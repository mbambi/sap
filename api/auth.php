<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateToken;
use function App\requireRoles;
use function App\generateUuid;

/** @var Router $router */
/** @var array $request */

$router->add('POST', '/api/auth/login', function () use ($request) {
    $body = $request['body'] ?? [];
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    $tenantSlug = $body['tenantSlug'] ?? null;

    if (!$email || !$password || !$tenantSlug) {
        Router::error('Invalid credentials', 400);
        return;
    }

    $db = DB::getInstance();
    $tenantRows = $db->query('SELECT * FROM `Tenant` WHERE slug = :slug LIMIT 1', ['slug' => $tenantSlug]);
    $tenant = $tenantRows[0] ?? null;
    if (!$tenant || !(bool) $tenant['isActive']) {
        Router::error('Invalid tenant or credentials', 401);
        return;
    }

    $userRows = $db->query(
        'SELECT * FROM `User` WHERE tenantId = :tenantId AND email = :email LIMIT 1',
        ['tenantId' => $tenant['id'], 'email' => $email]
    );
    $user = $userRows[0] ?? null;
    if (!$user || !(bool) $user['isActive']) {
        Router::error('Invalid credentials', 401);
        return;
    }

    if (!password_verify($password, $user['passwordHash'])) {
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

    $token = generateToken($payload);

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
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    $firstName = $body['firstName'] ?? null;
    $lastName = $body['lastName'] ?? null;
    $tenantSlug = $body['tenantSlug'] ?? null;

    if (!$email || !$password || !$firstName || !$lastName || !$tenantSlug) {
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
