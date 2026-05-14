<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function warehousePagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function warehouseFilters(array $query, array $allowed): array
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

function warehouseBuildUpdate(array $data, array $allowed): array
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

function warehouseLoadPlant(DB $db, ?string $plantId): ?array
{
    if (!$plantId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $plantId]);
    return $rows[0] ?? null;
}

function warehouseLoadWarehouse(DB $db, ?string $warehouseId): ?array
{
    if (!$warehouseId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $warehouseId]);
    if (!$rows) {
        return null;
    }
    $warehouse = $rows[0];
    $warehouse['plant'] = warehouseLoadPlant($db, $warehouse['plantId']);
    return $warehouse;
}

function warehouseLoadMaterial(DB $db, ?string $materialId): ?array
{
    if (!$materialId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

// Warehouses
$router->add('GET', '/api/warehouse/warehouses', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = warehousePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = warehouseFilters($query, ['type', 'isActive']);

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
        "SELECT * FROM `Warehouse` $whereSql ORDER BY code ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['plant'] = warehouseLoadPlant($db, $row['plantId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Warehouse` $whereSql", $params);
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

$router->add('GET', '/api/warehouse/warehouses/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $warehouse = $rows[0] ?? null;
    if (!$warehouse || $warehouse['tenantId'] !== $user['tenantId']) {
        Router::error('warehouse not found', 404);
        return;
    }

    $warehouse['plant'] = warehouseLoadPlant($db, $warehouse['plantId']);
    Router::json($warehouse);
});

$router->add('POST', '/api/warehouse/warehouses', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['plantId', 'code', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid warehouse data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Warehouse` WHERE tenantId = :tenantId AND code = :code LIMIT 1',
        ['tenantId' => $user['tenantId'], 'code' => $body['code']]
    );
    if ($existing) {
        Router::error('warehouse already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Warehouse` (id, tenantId, plantId, code, name, type, isActive)
         VALUES (:id, :tenantId, :plantId, :code, :name, :type, :isActive)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'plantId' => $body['plantId'],
            'code' => $body['code'],
            'name' => $body['name'],
            'type' => $body['type'] ?? 'standard',
            'isActive' => $body['isActive'] ?? 1,
        ]
    );

    $warehouse = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($warehouse) {
        $warehouse['plant'] = warehouseLoadPlant($db, $warehouse['plantId']);
        logAudit($user, 'warehouse', 'warehouse', 'CREATE', $id, null, $warehouse);
        Router::json($warehouse, 201);
        return;
    }

    Router::error('Failed to create warehouse', 500);
});

$router->add('PUT', '/api/warehouse/warehouses/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $warehouse = $rows[0] ?? null;
    if (!$warehouse || $warehouse['tenantId'] !== $user['tenantId']) {
        Router::error('warehouse not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = warehouseBuildUpdate($body, ['plantId', 'code', 'name', 'type', 'isActive']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Warehouse` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['plant'] = warehouseLoadPlant($db, $updated['plantId']);
        logAudit($user, 'warehouse', 'warehouse', 'UPDATE', $params['id'], $warehouse, $updated);
        Router::json($updated);
        return;
    }

    Router::error('warehouse not found', 404);
});

$router->add('DELETE', '/api/warehouse/warehouses/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $warehouse = $rows[0] ?? null;
    if (!$warehouse || $warehouse['tenantId'] !== $user['tenantId']) {
        Router::error('warehouse not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Warehouse` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'warehouse', 'warehouse', 'DELETE', $params['id'], $warehouse, null);
    Router::json(['message' => 'warehouse deleted']);
});

// Warehouse Bins
$router->add('GET', '/api/warehouse/bins', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = warehousePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = warehouseFilters($query, ['warehouseId', 'binType', 'materialId']);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(binCode LIKE :search OR zone LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `WarehouseBin` $whereSql ORDER BY binCode ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['warehouse'] = warehouseLoadWarehouse($db, $row['warehouseId']);
        $row['material'] = warehouseLoadMaterial($db, $row['materialId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `WarehouseBin` $whereSql", $params);
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

$router->add('GET', '/api/warehouse/bins/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WarehouseBin` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bin = $rows[0] ?? null;
    if (!$bin) {
        Router::error('warehouse_bin not found', 404);
        return;
    }

    $bin['warehouse'] = warehouseLoadWarehouse($db, $bin['warehouseId']);
    $bin['material'] = warehouseLoadMaterial($db, $bin['materialId']);
    Router::json($bin);
});

$router->add('POST', '/api/warehouse/bins', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['warehouseId', 'binCode'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid warehouse_bin data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `WarehouseBin` WHERE warehouseId = :warehouseId AND binCode = :binCode LIMIT 1',
        ['warehouseId' => $body['warehouseId'], 'binCode' => $body['binCode']]
    );
    if ($existing) {
        Router::error('warehouse_bin already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `WarehouseBin` (id, warehouseId, binCode, zone, aisle, rack, level, materialId, quantity, maxCapacity, binType)
         VALUES (:id, :warehouseId, :binCode, :zone, :aisle, :rack, :level, :materialId, :quantity, :maxCapacity, :binType)',
        [
            'id' => $id,
            'warehouseId' => $body['warehouseId'],
            'binCode' => $body['binCode'],
            'zone' => $body['zone'] ?? null,
            'aisle' => $body['aisle'] ?? null,
            'rack' => $body['rack'] ?? null,
            'level' => $body['level'] ?? null,
            'materialId' => $body['materialId'] ?? null,
            'quantity' => $body['quantity'] ?? 0,
            'maxCapacity' => $body['maxCapacity'] ?? 1000,
            'binType' => $body['binType'] ?? 'storage',
        ]
    );

    $bin = $db->query('SELECT * FROM `WarehouseBin` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($bin) {
        $bin['warehouse'] = warehouseLoadWarehouse($db, $bin['warehouseId']);
        $bin['material'] = warehouseLoadMaterial($db, $bin['materialId']);
        logAudit($user, 'warehouse', 'warehouse_bin', 'CREATE', $id, null, $bin);
        Router::json($bin, 201);
        return;
    }

    Router::error('Failed to create warehouse_bin', 500);
});

$router->add('PUT', '/api/warehouse/bins/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WarehouseBin` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bin = $rows[0] ?? null;
    if (!$bin) {
        Router::error('warehouse_bin not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['warehouseId', 'binCode', 'zone', 'aisle', 'rack', 'level', 'materialId', 'quantity', 'maxCapacity', 'binType'];
    [$fields, $paramsUpdate] = warehouseBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `WarehouseBin` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `WarehouseBin` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['warehouse'] = warehouseLoadWarehouse($db, $updated['warehouseId']);
        $updated['material'] = warehouseLoadMaterial($db, $updated['materialId']);
        logAudit($user, 'warehouse', 'warehouse_bin', 'UPDATE', $params['id'], $bin, $updated);
        Router::json($updated);
        return;
    }

    Router::error('warehouse_bin not found', 404);
});

$router->add('DELETE', '/api/warehouse/bins/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WarehouseBin` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $bin = $rows[0] ?? null;
    if (!$bin) {
        Router::error('warehouse_bin not found', 404);
        return;
    }

    $db->execute('DELETE FROM `WarehouseBin` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'warehouse', 'warehouse_bin', 'DELETE', $params['id'], $bin, null);
    Router::json(['message' => 'warehouse_bin deleted']);
});
