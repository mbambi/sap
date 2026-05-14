<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function controllingPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function controllingFilters(array $query, array $allowed): array
{
    $filters = [];
    foreach ($query as $key => $value) {
        if (in_array($key, ['page', 'limit', 'search', 'sort', 'order'], true)) {
            continue;
        }
        if ($value === '' || is_array($value)) {
            continue;
        }
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $filters[$key] = $value;
    }
    return $filters;
}

function controllingBuildUpdate(array $data, array $allowed): array
{
    $fields = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = sprintf('`%s` = :%s', $field, $field);
            $params[$field] = $data[$field];
        }
    }

    return [$fields, $params];
}

function controllingLoadCostCenter(DB $db, ?string $costCenterId): ?array
{
    if (!$costCenterId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $costCenterId]);
    return $rows[0] ?? null;
}

function controllingLoadCostChildren(DB $db, string $costCenterId): array
{
    return $db->query('SELECT * FROM `CostCenter` WHERE parentId = :id', ['id' => $costCenterId]);
}

// Cost Centers
$router->add('GET', '/api/controlling/cost-centers', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = controllingPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = controllingFilters($query, ['category', 'isActive', 'parentId']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(code LIKE :search OR name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `CostCenter` $whereSql ORDER BY code ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['parent'] = controllingLoadCostCenter($db, $row['parentId'] ?? null);
        $row['children'] = controllingLoadCostChildren($db, $row['id']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `CostCenter` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/controlling/cost-centers/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $center = $rows[0] ?? null;
    if (!$center || $center['tenantId'] !== $user['tenantId']) {
        Router::error('cost_center not found', 404);
        return;
    }

    $center['parent'] = controllingLoadCostCenter($db, $center['parentId'] ?? null);
    $center['children'] = controllingLoadCostChildren($db, $center['id']);
    Router::json($center);
});

$router->add('POST', '/api/controlling/cost-centers', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['code', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid cost_center data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `CostCenter` WHERE tenantId = :tenantId AND code = :code LIMIT 1',
        ['tenantId' => $user['tenantId'], 'code' => $body['code']]
    );
    if ($existing) {
        Router::error('cost_center already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `CostCenter` (id, tenantId, code, name, description, category, managerId, parentId, isActive, createdAt)
         VALUES (:id, :tenantId, :code, :name, :description, :category, :managerId, :parentId, :isActive, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'code' => $body['code'],
            'name' => $body['name'],
            'description' => $body['description'] ?? null,
            'category' => $body['category'] ?? null,
            'managerId' => $body['managerId'] ?? null,
            'parentId' => $body['parentId'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $center = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($center) {
        $center['parent'] = controllingLoadCostCenter($db, $center['parentId'] ?? null);
        $center['children'] = controllingLoadCostChildren($db, $center['id']);
        logAudit($user, 'controlling', 'cost_center', 'CREATE', $id, null, $center);
        Router::json($center, 201);
        return;
    }

    Router::error('Failed to create cost_center', 500);
});

$router->add('PUT', '/api/controlling/cost-centers/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $center = $rows[0] ?? null;
    if (!$center || $center['tenantId'] !== $user['tenantId']) {
        Router::error('cost_center not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['code', 'name', 'description', 'category', 'managerId', 'parentId', 'isActive'];
    [$fields, $paramsUpdate] = controllingBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `CostCenter` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['parent'] = controllingLoadCostCenter($db, $updated['parentId'] ?? null);
        $updated['children'] = controllingLoadCostChildren($db, $updated['id']);
        logAudit($user, 'controlling', 'cost_center', 'UPDATE', $params['id'], $center, $updated);
        Router::json($updated);
        return;
    }

    Router::error('cost_center not found', 404);
});

$router->add('DELETE', '/api/controlling/cost-centers/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CostCenter` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $center = $rows[0] ?? null;
    if (!$center || $center['tenantId'] !== $user['tenantId']) {
        Router::error('cost_center not found', 404);
        return;
    }

    $db->execute('DELETE FROM `CostCenter` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'controlling', 'cost_center', 'DELETE', $params['id'], $center, null);
    Router::json(['message' => 'cost_center deleted']);
});

// Internal Orders
$router->add('GET', '/api/controlling/internal-orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = controllingPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = controllingFilters($query, ['status', 'type']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(orderNumber LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `InternalOrder` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `InternalOrder` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/controlling/internal-orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InternalOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('internal_order not found', 404);
        return;
    }

    Router::json($order);
});

$router->add('POST', '/api/controlling/internal-orders', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['orderNumber', 'description', 'type'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid internal_order data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `InternalOrder` WHERE tenantId = :tenantId AND orderNumber = :orderNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'orderNumber' => $body['orderNumber']]
    );
    if ($existing) {
        Router::error('internal_order already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `InternalOrder` (id, tenantId, orderNumber, description, type, status, budget, actualCost, responsiblePerson, validFrom, validTo, createdAt)
         VALUES (:id, :tenantId, :orderNumber, :description, :type, :status, :budget, :actualCost, :responsiblePerson, :validFrom, :validTo, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'orderNumber' => $body['orderNumber'],
            'description' => $body['description'],
            'type' => $body['type'],
            'status' => $body['status'] ?? 'open',
            'budget' => $body['budget'] ?? 0,
            'actualCost' => $body['actualCost'] ?? 0,
            'responsiblePerson' => $body['responsiblePerson'] ?? null,
            'validFrom' => $body['validFrom'] ?? null,
            'validTo' => $body['validTo'] ?? null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $order = $db->query('SELECT * FROM `InternalOrder` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($order) {
        logAudit($user, 'controlling', 'internal_order', 'CREATE', $id, null, $order);
        Router::json($order, 201);
        return;
    }

    Router::error('Failed to create internal_order', 500);
});

$router->add('PUT', '/api/controlling/internal-orders/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InternalOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('internal_order not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['orderNumber', 'description', 'type', 'status', 'budget', 'actualCost', 'responsiblePerson', 'validFrom', 'validTo'];
    [$fields, $paramsUpdate] = controllingBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `InternalOrder` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `InternalOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'controlling', 'internal_order', 'UPDATE', $params['id'], $order, $updated);
        Router::json($updated);
        return;
    }

    Router::error('internal_order not found', 404);
});

$router->add('DELETE', '/api/controlling/internal-orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InternalOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('internal_order not found', 404);
        return;
    }

    $db->execute('DELETE FROM `InternalOrder` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'controlling', 'internal_order', 'DELETE', $params['id'], $order, null);
    Router::json(['message' => 'internal_order deleted']);
});
