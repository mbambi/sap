<?php

declare(strict_types=1);

namespace App;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authConfig(): array
{
    $secret = trim((string) (getenv('JWT_SECRET') ?: ''));
    if ($secret === '' || $secret === 'change-me' || strlen($secret) < 32) {
        throw new \RuntimeException('JWT_SECRET must be configured with a secure value (min 32 chars)');
    }

    $expiryRaw = trim((string) (getenv('JWT_EXPIRY') ?: '8h'));
    $expirySeconds = parseExpiryToSeconds($expiryRaw);
    if ($expirySeconds < 300 || $expirySeconds > 604800) {
        throw new \RuntimeException('JWT_EXPIRY must be between 5 minutes and 7 days');
    }

    return ['secret' => $secret, 'expirySeconds' => $expirySeconds];
}

function parseExpiryToSeconds(string $expiry): int
{
    if ($expiry === '') {
        return 8 * 3600;
    }
    if (ctype_digit($expiry)) {
        return (int) $expiry;
    }
    if (!preg_match('/^\s*(\d+)\s*([smhd])\s*$/i', $expiry, $matches)) {
        return 8 * 3600;
    }

    $value = (int) $matches[1];
    $unit = strtolower($matches[2]);
    $multiplier = match ($unit) {
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        default => 3600,
    };

    return $value * $multiplier;
}

function normalizeEmail(?string $email): string
{
    return strtolower(trim((string) $email));
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 10
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function verifyToken(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        Router::error('Authentication required', 401);
        exit;
    }

    $token = substr($header, 7);
    $config = authConfig();

    try {
        $decoded = JWT::decode($token, new Key($config['secret'], 'HS256'));
        return (array) $decoded;
    } catch (\Throwable $exception) {
        Router::error('Invalid or expired token', 401);
        exit;
    }
}

function requireRoles(array $roles): array
{
    $payload = verifyToken();

    if ($roles === []) {
        return $payload;
    }

    $userRoles = $payload['roles'] ?? [];
    $hasRole = false;
    foreach ($roles as $role) {
        if (in_array($role, $userRoles, true)) {
            $hasRole = true;
            break;
        }
    }

    if (!$hasRole) {
        Router::error('Insufficient permissions', 403);
        exit;
    }

    return $payload;
}

function generateToken(array $payload): string
{
    $config = authConfig();

    $issuedAt = time();
    $expiresAt = $issuedAt + $config['expirySeconds'];

    $payload['iat'] = $issuedAt;
    $payload['exp'] = $expiresAt;

    return JWT::encode($payload, $config['secret'], 'HS256');
}

function logAudit(
    array $user,
    string $module,
    string $resource,
    string $action,
    ?string $resourceId,
    mixed $oldValue,
    mixed $newValue
): void {
    $db = DB::getInstance();

    $db->execute(
        'INSERT INTO `AuditLog` (id, tenantId, userId, action, module, resource, resourceId, oldValue, newValue, ipAddress, createdAt)
         VALUES (:id, :tenantId, :userId, :action, :module, :resource, :resourceId, :oldValue, :newValue, :ipAddress, :createdAt)',
        [
            'id' => generateUuid(),
            'tenantId' => $user['tenantId'] ?? null,
            'userId' => $user['userId'] ?? null,
            'action' => $action,
            'module' => $module,
            'resource' => $resource,
            'resourceId' => $resourceId,
            'oldValue' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'newValue' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
}

function generateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
