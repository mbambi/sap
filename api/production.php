<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function productionPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function productionFilters(array $query, array $allowed): array
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

function productionBuildUpdate(array $data, array $allowed): array
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

function productionLoadMaterial(DB $db, ?string $materialId): ?array
{
    if (!$materialId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

function productionLoadComponents(DB $db, string $bomId): array
{
    $rows = $db->query('SELECT * FROM `BOMComponent` WHERE bomId = :bomId ORDER BY position ASC', ['bomId' => $bomId]);
    foreach ($rows as &$row) {
        $row['material'] = productionLoadMaterial($db, $row['materialId']);
    }
    return $rows;
}

function productionLoadRoutings(DB $db, string $bomId): array
{
    return $db->query('SELECT * FROM `Routing` WHERE bomId = :bomId ORDER BY stepNo ASC', ['bomId' => $bomId]);
}

// Bill of Materials
$router->add('GET', '/api/production/boms', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = productionPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = productionFilters($query, ['isActive']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(bomNumber LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `BillOfMaterial` $whereSql ORDER BY bomNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['material'] = productionLoadMaterial($db, $row['materialId']);
        $row['components'] = productionLoadComponents($db, $row['id']);
        $row['routings'] = productionLoadRoutings($db, $row['id']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `BillOfMaterial` $whereSql", $params);
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

$router->add('GET', '/api/production/boms/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `BillOfMaterial` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bom = $rows[0] ?? null;
    if (!$bom || $bom['tenantId'] !== $user['tenantId']) {
        Router::error('bom not found', 404);
        return;
    }

    $bom['material'] = productionLoadMaterial($db, $bom['materialId']);
    $bom['components'] = productionLoadComponents($db, $bom['id']);
    $bom['routings'] = productionLoadRoutings($db, $bom['id']);

    Router::json($bom);
});

$router->add('POST', '/api/production/boms', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['bomNumber', 'materialId'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid bom data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `BillOfMaterial` WHERE tenantId = :tenantId AND bomNumber = :bomNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'bomNumber' => $body['bomNumber']]
    );
    if ($existing) {
        Router::error('bom already exists with that key', 409);
        return;
    }

    $bomId = generateUuid();
    $components = $body['components'] ?? [];
    $routings = $body['routings'] ?? [];
    $now = date('Y-m-d H:i:s');

    $db->transaction(function (DB $db) use ($body, $components, $routings, $user, $bomId, $now) {
        $db->execute(
            'INSERT INTO `BillOfMaterial` (id, tenantId, bomNumber, materialId, description, version, isActive, validFrom, validTo, createdAt)
             VALUES (:id, :tenantId, :bomNumber, :materialId, :description, :version, :isActive, :validFrom, :validTo, :createdAt)',
            [
                'id' => $bomId,
                'tenantId' => $user['tenantId'],
                'bomNumber' => $body['bomNumber'],
                'materialId' => $body['materialId'],
                'description' => $body['description'] ?? null,
                'version' => $body['version'] ?? 1,
                'isActive' => $body['isActive'] ?? 1,
                'validFrom' => $body['validFrom'] ?? $now,
                'validTo' => $body['validTo'] ?? null,
                'createdAt' => $now,
            ]
        );

        foreach ($components as $component) {
            $db->execute(
                'INSERT INTO `BOMComponent` (id, bomId, materialId, quantity, unit, position, isPhantom, scrapRate)
                 VALUES (:id, :bomId, :materialId, :quantity, :unit, :position, :isPhantom, :scrapRate)',
                [
                    'id' => generateUuid(),
                    'bomId' => $bomId,
                    'materialId' => $component['materialId'],
                    'quantity' => $component['quantity'] ?? 0,
                    'unit' => $component['unit'] ?? 'EA',
                    'position' => $component['position'] ?? 1,
                    'isPhantom' => $component['isPhantom'] ?? 0,
                    'scrapRate' => $component['scrapRate'] ?? 0,
                ]
            );
        }

        foreach ($routings as $routing) {
            $db->execute(
                'INSERT INTO `Routing` (id, bomId, stepNo, workCenter, operation, description, setupTime, runTime, laborRate, machineRate)
                 VALUES (:id, :bomId, :stepNo, :workCenter, :operation, :description, :setupTime, :runTime, :laborRate, :machineRate)',
                [
                    'id' => generateUuid(),
                    'bomId' => $bomId,
                    'stepNo' => $routing['stepNo'] ?? 1,
                    'workCenter' => $routing['workCenter'] ?? '',
                    'operation' => $routing['operation'] ?? '',
                    'description' => $routing['description'] ?? null,
                    'setupTime' => $routing['setupTime'] ?? 0,
                    'runTime' => $routing['runTime'] ?? 0,
                    'laborRate' => $routing['laborRate'] ?? 0,
                    'machineRate' => $routing['machineRate'] ?? 0,
                ]
            );
        }
    });

    $bom = $db->query('SELECT * FROM `BillOfMaterial` WHERE id = :id LIMIT 1', ['id' => $bomId])[0] ?? null;
    if ($bom) {
        $bom['material'] = productionLoadMaterial($db, $bom['materialId']);
        $bom['components'] = productionLoadComponents($db, $bom['id']);
        $bom['routings'] = productionLoadRoutings($db, $bom['id']);
        logAudit($user, 'production', 'bom', 'CREATE', $bomId, null, $bom);
        Router::json($bom, 201);
        return;
    }

    Router::error('Failed to create bom', 500);
});

$router->add('PUT', '/api/production/boms/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `BillOfMaterial` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bom = $rows[0] ?? null;
    if (!$bom || $bom['tenantId'] !== $user['tenantId']) {
        Router::error('bom not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = productionBuildUpdate($body, ['bomNumber', 'materialId', 'description', 'version', 'isActive', 'validFrom', 'validTo']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `BillOfMaterial` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    if (isset($body['components'])) {
        $db->transaction(function (DB $db) use ($body, $bom) {
            $db->execute('DELETE FROM `BOMComponent` WHERE bomId = :bomId', ['bomId' => $bom['id']]);
            foreach ($body['components'] as $component) {
                $db->execute(
                    'INSERT INTO `BOMComponent` (id, bomId, materialId, quantity, unit, position, isPhantom, scrapRate)
                     VALUES (:id, :bomId, :materialId, :quantity, :unit, :position, :isPhantom, :scrapRate)',
                    [
                        'id' => generateUuid(),
                        'bomId' => $bom['id'],
                        'materialId' => $component['materialId'],
                        'quantity' => $component['quantity'] ?? 0,
                        'unit' => $component['unit'] ?? 'EA',
                        'position' => $component['position'] ?? 1,
                        'isPhantom' => $component['isPhantom'] ?? 0,
                        'scrapRate' => $component['scrapRate'] ?? 0,
                    ]
                );
            }
        });
    }

    if (isset($body['routings'])) {
        $db->transaction(function (DB $db) use ($body, $bom) {
            $db->execute('DELETE FROM `Routing` WHERE bomId = :bomId', ['bomId' => $bom['id']]);
            foreach ($body['routings'] as $routing) {
                $db->execute(
                    'INSERT INTO `Routing` (id, bomId, stepNo, workCenter, operation, description, setupTime, runTime, laborRate, machineRate)
                     VALUES (:id, :bomId, :stepNo, :workCenter, :operation, :description, :setupTime, :runTime, :laborRate, :machineRate)',
                    [
                        'id' => generateUuid(),
                        'bomId' => $bom['id'],
                        'stepNo' => $routing['stepNo'] ?? 1,
                        'workCenter' => $routing['workCenter'] ?? '',
                        'operation' => $routing['operation'] ?? '',
                        'description' => $routing['description'] ?? null,
                        'setupTime' => $routing['setupTime'] ?? 0,
                        'runTime' => $routing['runTime'] ?? 0,
                        'laborRate' => $routing['laborRate'] ?? 0,
                        'machineRate' => $routing['machineRate'] ?? 0,
                    ]
                );
            }
        });
    }

    $updated = $db->query('SELECT * FROM `BillOfMaterial` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['material'] = productionLoadMaterial($db, $updated['materialId']);
        $updated['components'] = productionLoadComponents($db, $updated['id']);
        $updated['routings'] = productionLoadRoutings($db, $updated['id']);
        logAudit($user, 'production', 'bom', 'UPDATE', $params['id'], $bom, $updated);
        Router::json($updated);
        return;
    }

    Router::error('bom not found', 404);
});

$router->add('DELETE', '/api/production/boms/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `BillOfMaterial` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bom = $rows[0] ?? null;
    if (!$bom || $bom['tenantId'] !== $user['tenantId']) {
        Router::error('bom not found', 404);
        return;
    }

    $db->execute('DELETE FROM `BillOfMaterial` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'production', 'bom', 'DELETE', $params['id'], $bom, null);
    Router::json(['message' => 'bom deleted']);
});

// Production Orders
$router->add('GET', '/api/production/orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = productionPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = productionFilters($query, ['status', 'priority']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = 'orderNumber LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `ProductionOrder` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `ProductionOrder` $whereSql", $params);
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

$router->add('GET', '/api/production/orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('production_order not found', 404);
        return;
    }

    Router::json($order);
});

$router->add('POST', '/api/production/orders', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['orderNumber', 'materialId', 'quantity', 'plannedStart', 'plannedEnd'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid production_order data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `ProductionOrder` WHERE tenantId = :tenantId AND orderNumber = :orderNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'orderNumber' => $body['orderNumber']]
    );
    if ($existing) {
        Router::error('production_order already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `ProductionOrder` (id, tenantId, orderNumber, materialId, quantity, unit, plannedStart, plannedEnd, actualStart, actualEnd, status, priority, yieldQty, scrapQty, notes, createdBy, createdAt, updatedAt)
         VALUES (:id, :tenantId, :orderNumber, :materialId, :quantity, :unit, :plannedStart, :plannedEnd, :actualStart, :actualEnd, :status, :priority, :yieldQty, :scrapQty, :notes, :createdBy, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'orderNumber' => $body['orderNumber'],
            'materialId' => $body['materialId'],
            'quantity' => $body['quantity'],
            'unit' => $body['unit'] ?? 'EA',
            'plannedStart' => $body['plannedStart'],
            'plannedEnd' => $body['plannedEnd'],
            'actualStart' => $body['actualStart'] ?? null,
            'actualEnd' => $body['actualEnd'] ?? null,
            'status' => $body['status'] ?? 'planned',
            'priority' => $body['priority'] ?? 5,
            'yieldQty' => $body['yieldQty'] ?? 0,
            'scrapQty' => $body['scrapQty'] ?? 0,
            'notes' => $body['notes'] ?? null,
            'createdBy' => $user['userId'],
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $order = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($order) {
        logAudit($user, 'production', 'production_order', 'CREATE', $id, null, $order);
        Router::json($order, 201);
        return;
    }

    Router::error('Failed to create production_order', 500);
});

$router->add('PUT', '/api/production/orders/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('production_order not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['orderNumber', 'materialId', 'quantity', 'unit', 'plannedStart', 'plannedEnd', 'actualStart', 'actualEnd', 'status', 'priority', 'yieldQty', 'scrapQty', 'notes'];
    [$fields, $paramsUpdate] = productionBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `ProductionOrder` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'production', 'production_order', 'UPDATE', $params['id'], $order, $updated);
        Router::json($updated);
        return;
    }

    Router::error('production_order not found', 404);
});

$router->add('DELETE', '/api/production/orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('production_order not found', 404);
        return;
    }

    $db->execute('DELETE FROM `ProductionOrder` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'production', 'production_order', 'DELETE', $params['id'], $order, null);
    Router::json(['message' => 'production_order deleted']);
});
