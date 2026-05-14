<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateToken;
use function App\requireRoles;
use function App\generateUuid;
use function App\isStrongPassword;
use function App\normalizeEmail;

/** @var Router $router */
/** @var array $request */

function authRateStorePath(): string
{
    return sys_get_temp_dir() . '/sap_auth_rate_limit.json';
}

function authReadRateStore(): array
{
    $path = authRateStorePath();
    if (!file_exists($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function authWriteRateStore(array $data): void
{
    file_put_contents(authRateStorePath(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function authRateKey(string $tenantSlug, string $email, string $ip): string
{
    return hash('sha256', strtolower($tenantSlug . '|' . $email . '|' . $ip));
}

function authIsLocked(string $tenantSlug, string $email, string $ip): bool
{
    $data = authReadRateStore();
    $key = authRateKey($tenantSlug, $email, $ip);
    $entry = $data[$key] ?? null;
    if (!is_array($entry)) {
        return false;
    }

    $now = time();
    if (($entry['lockUntil'] ?? 0) > $now) {
        return true;
    }

    if (($entry['lastAttemptAt'] ?? 0) < ($now - 3600)) {
        unset($data[$key]);
        authWriteRateStore($data);
    }

    return false;
}

function authRecordFailure(string $tenantSlug, string $email, string $ip): void
{
    $data = authReadRateStore();
    $key = authRateKey($tenantSlug, $email, $ip);
    $now = time();
    $entry = $data[$key] ?? ['attempts' => 0, 'lockUntil' => 0, 'lastAttemptAt' => 0];

    if (($entry['lastAttemptAt'] ?? 0) < ($now - 900)) {
        $entry['attempts'] = 0;
    }

    $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
    $entry['lastAttemptAt'] = $now;
    if ($entry['attempts'] >= 5) {
        $entry['lockUntil'] = $now + 900;
    }

    $data[$key] = $entry;
    authWriteRateStore($data);
}

function authResetFailures(string $tenantSlug, string $email, string $ip): void
{
    $data = authReadRateStore();
    $key = authRateKey($tenantSlug, $email, $ip);
    if (isset($data[$key])) {
        unset($data[$key]);
        authWriteRateStore($data);
    }
}

$router->add('POST', '/api/auth/login', function () use ($request) {
    $body = $request['body'] ?? [];
    $email = normalizeEmail($body['email'] ?? null);
    $password = (string) ($body['password'] ?? '');
    $tenantSlug = trim((string) ($body['tenantSlug'] ?? ''));
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($email === '' || $password === '' || $tenantSlug === '') {
        Router::error('Invalid credentials', 400);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9-]+$/i', $tenantSlug)) {
        Router::error('Invalid credentials', 400);
        return;
    }
    if (authIsLocked($tenantSlug, $email, $ip)) {
        Router::error('Account temporarily locked due to failed login attempts', 429);
        return;
    }

    $db = DB::getInstance();
    $tenantRows = $db->query('SELECT * FROM `Tenant` WHERE slug = :slug LIMIT 1', ['slug' => $tenantSlug]);
    $tenant = $tenantRows[0] ?? null;
    if (!$tenant || !(bool) $tenant['isActive']) {
        authRecordFailure($tenantSlug, $email, $ip);
        Router::error('Invalid tenant or credentials', 401);
        return;
    }

    $userRows = $db->query(
        'SELECT * FROM `User` WHERE tenantId = :tenantId AND email = :email LIMIT 1',
        ['tenantId' => $tenant['id'], 'email' => $email]
    );
    $user = $userRows[0] ?? null;
    if (!$user || !(bool) $user['isActive']) {
        authRecordFailure($tenantSlug, $email, $ip);
        Router::error('Invalid credentials', 401);
        return;
    }

    if (!password_verify($password, $user['passwordHash'])) {
        authRecordFailure($tenantSlug, $email, $ip);
        Router::error('Invalid credentials', 401);
        return;
    }

    $roleRows = $db->query(
        'SELECT r.name FROM `UserRole` ur JOIN `Role` r ON ur.roleId = r.id WHERE ur.userId = :userId',
        ['userId' => $user['id']]
    );
    $roles = array_map(fn ($row) => $row['name'], $roleRows);

    $payload = [
        'userId' => $user['id'],
        'tenantId' => $tenant['id'],
        'email' => $user['email'],
        'roles' => $roles,
    ];

    try {
        $token = generateToken($payload);
    } catch (\Throwable $exception) {
        error_log('Token generation failed: ' . $exception->getMessage());
        Router::error('Authentication service unavailable', 500);
        return;
    }
    authResetFailures($tenantSlug, $email, $ip);

    $db->execute(
        'UPDATE `User` SET lastLogin = :lastLogin, updatedAt = :updatedAt WHERE id = :id',
        [
            'lastLogin' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
            'id' => $user['id'],
        ]
    );

    Router::json([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'firstName' => $user['firstName'],
            'lastName' => $user['lastName'],
            'roles' => $roles,
            'tenantId' => $tenant['id'],
            'tenantName' => $tenant['name'],
        ],
    ]);
});

$router->add('POST', '/api/auth/register', function () use ($request) {
    $body = $request['body'] ?? [];
    $email = normalizeEmail($body['email'] ?? null);
    $password = (string) ($body['password'] ?? '');
    $firstName = trim((string) ($body['firstName'] ?? ''));
    $lastName = trim((string) ($body['lastName'] ?? ''));
    $tenantSlug = trim((string) ($body['tenantSlug'] ?? ''));

    if ($email === '' || $password === '' || $firstName === '' || $lastName === '' || $tenantSlug === '') {
        Router::error('Invalid registration data', 400);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9-]+$/i', $tenantSlug)) {
        Router::error('Invalid registration data', 400);
        return;
    }
    if (!isStrongPassword($password)) {
        Router::error('Password must be at least 10 chars and include uppercase, lowercase, number, and symbol', 400);
        return;
    }
    if (strlen($firstName) > 80 || strlen($lastName) > 80) {
        Router::error('Invalid registration data', 400);
        return;
    }

    $db = DB::getInstance();
    $tenantRows = $db->query('SELECT * FROM `Tenant` WHERE slug = :slug LIMIT 1', ['slug' => $tenantSlug]);
    $tenant = $tenantRows[0] ?? null;
    if (!$tenant || !(bool) $tenant['isActive']) {
        Router::error('Invalid tenant', 400);
        return;
    }

    $existing = $db->query(
        'SELECT id FROM `User` WHERE tenantId = :tenantId AND email = :email LIMIT 1',
        ['tenantId' => $tenant['id'], 'email' => $email]
    );
    if ($existing) {
        Router::error('Email already registered in this tenant', 409);
        return;
    }

    $roleRows = $db->query(
        'SELECT * FROM `Role` WHERE tenantId = :tenantId AND name = :name LIMIT 1',
        ['tenantId' => $tenant['id'], 'name' => 'student']
    );
    $studentRole = $roleRows[0] ?? null;

    if (!$studentRole) {
        $roleId = generateUuid();
        $db->execute(
            'INSERT INTO `Role` (id, tenantId, name, isSystem, createdAt) VALUES (:id, :tenantId, :name, :isSystem, :createdAt)',
            [
                'id' => $roleId,
                'tenantId' => $tenant['id'],
                'name' => 'student',
                'isSystem' => 1,
                'createdAt' => date('Y-m-d H:i:s'),
            ]
        );
        $studentRole = ['id' => $roleId];
    }

    $userId = generateUuid();
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $db->execute(
        'INSERT INTO `User` (id, tenantId, email, passwordHash, firstName, lastName, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :email, :passwordHash, :firstName, :lastName, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $userId,
            'tenantId' => $tenant['id'],
            'email' => $email,
            'passwordHash' => $passwordHash,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );

    $db->execute(
        'INSERT INTO `UserRole` (id, userId, roleId) VALUES (:id, :userId, :roleId)',
        [
            'id' => generateUuid(),
            'userId' => $userId,
            'roleId' => $studentRole['id'],
        ]
    );

    Router::json([
        'message' => 'Registration successful',
        'userId' => $userId,
    ], 201);
});

$router->add('GET', '/api/auth/me', function () {
    $payload = requireRoles([]);
    $db = DB::getInstance();

    $userRows = $db->query('SELECT * FROM `User` WHERE id = :id LIMIT 1', ['id' => $payload['userId'] ?? '']);
    $user = $userRows[0] ?? null;
    if (!$user) {
        Router::error('User not found', 404);
        return;
    }

    $tenantRows = $db->query('SELECT * FROM `Tenant` WHERE id = :id LIMIT 1', ['id' => $user['tenantId']]);
    $tenant = $tenantRows[0] ?? null;

    $roleRows = $db->query(
        'SELECT r.id, r.name FROM `UserRole` ur JOIN `Role` r ON ur.roleId = r.id WHERE ur.userId = :userId',
        ['userId' => $user['id']]
    );
    $roles = array_map(fn ($row) => $row['name'], $roleRows);

    $permissionRows = $db->query(
        'SELECT rp.module, rp.action, rp.resource
         FROM `RolePermission` rp
         JOIN `UserRole` ur ON rp.roleId = ur.roleId
         WHERE ur.userId = :userId',
        ['userId' => $user['id']]
    );

    Router::json([
        'id' => $user['id'],
        'email' => $user['email'],
        'firstName' => $user['firstName'],
        'lastName' => $user['lastName'],
        'roles' => $roles,
        'permissions' => $permissionRows,
        'tenantId' => $user['tenantId'],
        'tenantName' => $tenant['name'] ?? null,
    ]);
});

$router->add('GET', '/api/auth/tenants', function () {
    $db = DB::getInstance();
    $tenants = $db->query(
        'SELECT slug, name, university, description FROM `Tenant` WHERE isActive = 1 ORDER BY name ASC'
    );

    Router::json($tenants);
});
