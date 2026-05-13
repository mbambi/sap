<?php

declare(strict_types=1);

namespace App;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function verifyToken(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        Router::error('Authentication required', 401);
        exit;
    }

    $token = substr($header, 7);
    $secret = getenv('JWT_SECRET') ?: '';

    try {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
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
    $secret = getenv('JWT_SECRET') ?: '';
    $expiry = getenv('JWT_EXPIRY') ?: '8h';

    $issuedAt = time();
    $expiresAt = is_numeric($expiry)
        ? $issuedAt + (int) $expiry
        : (int) (strtotime('+' . $expiry, $issuedAt) ?: ($issuedAt + 8 * 3600));

    $payload['iat'] = $issuedAt;
    $payload['exp'] = $expiresAt;

    return JWT::encode($payload, $secret, 'HS256');
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
