<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

$router->add('GET', '/api/admin/tenants', function () {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    if (!in_array('admin', $user['roles'] ?? [], true)) {
        $tenant = $db->query('SELECT * FROM `Tenant` WHERE id = :id LIMIT 1', ['id' => $user['tenantId']])[0] ?? null;
        Router::json($tenant ? [$tenant] : []);
        return;
    }

    $tenants = $db->query('SELECT * FROM `Tenant` ORDER BY name ASC');
    Router::json($tenants);
});

$router->add('POST', '/api/admin/tenants', function () use ($request) {
    $user = requireRoles(['admin']);
    $body = $request['body'] ?? [];
    if (empty($body['name']) || empty($body['slug'])) {
        Router::error('name and slug are required', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Tenant` (id, name, slug, university, description, isActive, createdAt, updatedAt)
         VALUES (:id, :name, :slug, :university, :description, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'name' => $body['name'],
            'slug' => $body['slug'],
            'university' => $body['university'] ?? null,
            'description' => $body['description'] ?? null,
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );

    foreach (['admin', 'instructor', 'student', 'auditor'] as $roleName) {
        $db->execute(
            'INSERT INTO `Role` (id, tenantId, name, isSystem, createdAt) VALUES (:id, :tenantId, :name, :isSystem, :createdAt)',
            [
                'id' => generateUuid(),
                'tenantId' => $id,
                'name' => $roleName,
                'isSystem' => 1,
                'createdAt' => date('Y-m-d H:i:s'),
            ]
        );
    }

    $tenant = $db->query('SELECT * FROM `Tenant` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($tenant ?? [], 201);
});

$router->add('GET', '/api/admin/users', function () {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();
    $users = $db->query(
        'SELECT * FROM `User` WHERE tenantId = :tenantId ORDER BY lastName ASC',
        ['tenantId' => $user['tenantId']]
    );
    $userRoles = $db->query(
        'SELECT ur.userId, r.name FROM `UserRole` ur JOIN `Role` r ON r.id = ur.roleId WHERE r.tenantId = :tenantId',
        ['tenantId' => $user['tenantId']]
    );
    $roleMap = [];
    foreach ($userRoles as $row) {
        $roleMap[$row['userId']][] = $row['name'];
    }

    $safe = array_map(function ($u) use ($roleMap) {
        unset($u['passwordHash']);
        $u['roles'] = $roleMap[$u['id']] ?? [];
        return $u;
    }, $users);

    Router::json($safe);
});

$router->add('POST', '/api/admin/users', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    if (empty($body['email']) || empty($body['firstName']) || empty($body['lastName'])) {
        Router::error('email, firstName, lastName required', 400);
        return;
    }
    if (empty($body['password']) || strlen((string) $body['password']) < 6) {
        Router::error('password is required and must be at least 6 characters', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $passwordHash = password_hash((string) $body['password'], PASSWORD_BCRYPT);
    $db->execute(
        'INSERT INTO `User` (id, tenantId, email, passwordHash, firstName, lastName, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :email, :passwordHash, :firstName, :lastName, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'email' => $body['email'],
            'passwordHash' => $passwordHash,
            'firstName' => $body['firstName'],
            'lastName' => $body['lastName'],
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );

    if (!empty($body['roleNames']) && is_array($body['roleNames'])) {
        foreach ($body['roleNames'] as $roleName) {
            $role = $db->query(
                'SELECT id FROM `Role` WHERE tenantId = :tenantId AND name = :name LIMIT 1',
                ['tenantId' => $user['tenantId'], 'name' => $roleName]
            )[0] ?? null;
            if ($role) {
                $db->execute(
                    'INSERT INTO `UserRole` (id, userId, roleId) VALUES (:id, :userId, :roleId)',
                    [
                        'id' => generateUuid(),
                        'userId' => $id,
                        'roleId' => $role['id'],
                    ]
                );
            }
        }
    }

    Router::json(['id' => $id, 'email' => $body['email']], 201);
});

$router->add('PUT', '/api/admin/users/{id}/roles', function (array $params) use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    $db = DB::getInstance();
    $target = $db->query('SELECT * FROM `User` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if (!$target || $target['tenantId'] !== $user['tenantId']) {
        Router::error('User not found', 404);
        return;
    }

    $db->execute('DELETE FROM `UserRole` WHERE userId = :userId', ['userId' => $target['id']]);
    foreach ($body['roles'] ?? [] as $roleName) {
        $role = $db->query(
            'SELECT id FROM `Role` WHERE tenantId = :tenantId AND name = :name LIMIT 1',
            ['tenantId' => $user['tenantId'], 'name' => $roleName]
        )[0] ?? null;
        if ($role) {
            $db->execute(
                'INSERT INTO `UserRole` (id, userId, roleId) VALUES (:id, :userId, :roleId)',
                [
                    'id' => generateUuid(),
                    'userId' => $target['id'],
                    'roleId' => $role['id'],
                ]
            );
        }
    }

    Router::json(['message' => 'Roles updated']);
});

$router->add('GET', '/api/admin/roles', function () {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();
    $roles = $db->query('SELECT * FROM `Role` WHERE tenantId = :tenantId ORDER BY name ASC', ['tenantId' => $user['tenantId']]);
    $permissions = $db->query(
        'SELECT * FROM `RolePermission` WHERE roleId IN (SELECT id FROM `Role` WHERE tenantId = :tenantId)',
        ['tenantId' => $user['tenantId']]
    );
    $permMap = [];
    foreach ($permissions as $perm) {
        $permMap[$perm['roleId']][] = $perm;
    }
    $roles = array_map(function ($role) use ($permMap) {
        $role['permissions'] = $permMap[$role['id']] ?? [];
        return $role;
    }, $roles);
    Router::json($roles);
});

$router->add('GET', '/api/admin/audit-log', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $query = $request['query'] ?? [];
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $db = DB::getInstance();
    $where = ['a.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if (!empty($query['module'])) {
        $where[] = 'a.module = :module';
        $params['module'] = $query['module'];
    }
    if (!empty($query['userId'])) {
        $where[] = 'a.userId = :userId';
        $params['userId'] = $query['userId'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT a.*, u.firstName, u.lastName, u.email FROM `AuditLog` a LEFT JOIN `User` u ON u.id = a.userId $whereSql ORDER BY a.createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );
    $countRows = $db->query("SELECT COUNT(*) AS total FROM `AuditLog` a $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    $data = array_map(function ($row) {
        $row['user'] = $row['userId'] ? ['firstName' => $row['firstName'], 'lastName' => $row['lastName'], 'email' => $row['email']] : null;
        unset($row['firstName'], $row['lastName'], $row['email']);
        return $row;
    }, $data);

    Router::json([
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});
