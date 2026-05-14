<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function maintenancePagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function maintenanceFilters(array $query, array $allowed): array
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

function maintenanceBuildUpdate(array $data, array $allowed): array
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

function maintenanceLoadPlant(DB $db, ?string $plantId): ?array
{
    if (!$plantId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $plantId]);
    return $rows[0] ?? null;
}

function maintenanceLoadEquipment(DB $db, ?string $equipmentId): ?array
{
    if (!$equipmentId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $equipmentId]);
    if (!$rows) {
        return null;
    }
    $equipment = $rows[0];
    $equipment['plant'] = maintenanceLoadPlant($db, $equipment['plantId']);
    return $equipment;
}

// Equipment
$router->add('GET', '/api/maintenance/equipment', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = maintenancePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = maintenanceFilters($query, ['category', 'status', 'criticality']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(equipmentNumber LIKE :search OR description LIKE :search OR manufacturer LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `Equipment` $whereSql ORDER BY equipmentNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['plant'] = maintenanceLoadPlant($db, $row['plantId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Equipment` $whereSql", $params);
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

$router->add('GET', '/api/maintenance/equipment/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $equipment = $rows[0] ?? null;
    if (!$equipment || $equipment['tenantId'] !== $user['tenantId']) {
        Router::error('equipment not found', 404);
        return;
    }

    $equipment['plant'] = maintenanceLoadPlant($db, $equipment['plantId']);
    Router::json($equipment);
});

$router->add('POST', '/api/maintenance/equipment', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['equipmentNumber', 'description', 'category', 'plantId'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid equipment data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Equipment` WHERE tenantId = :tenantId AND equipmentNumber = :equipmentNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'equipmentNumber' => $body['equipmentNumber']]
    );
    if ($existing) {
        Router::error('equipment already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');
    $db->execute(
        'INSERT INTO `Equipment` (id, tenantId, equipmentNumber, description, category, manufacturer, model, serialNumber, plantId, location, installDate, warrantyEnd, status, criticality, costCenterId, createdAt, updatedAt)
         VALUES (:id, :tenantId, :equipmentNumber, :description, :category, :manufacturer, :model, :serialNumber, :plantId, :location, :installDate, :warrantyEnd, :status, :criticality, :costCenterId, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'equipmentNumber' => $body['equipmentNumber'],
            'description' => $body['description'],
            'category' => $body['category'],
            'manufacturer' => $body['manufacturer'] ?? null,
            'model' => $body['model'] ?? null,
            'serialNumber' => $body['serialNumber'] ?? null,
            'plantId' => $body['plantId'],
            'location' => $body['location'] ?? null,
            'installDate' => $body['installDate'] ?? null,
            'warrantyEnd' => $body['warrantyEnd'] ?? null,
            'status' => $body['status'] ?? 'active',
            'criticality' => $body['criticality'] ?? 'medium',
            'costCenterId' => $body['costCenterId'] ?? null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $equipment = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($equipment) {
        $equipment['plant'] = maintenanceLoadPlant($db, $equipment['plantId']);
        logAudit($user, 'maintenance', 'equipment', 'CREATE', $id, null, $equipment);
        Router::json($equipment, 201);
        return;
    }

    Router::error('Failed to create equipment', 500);
});

$router->add('PUT', '/api/maintenance/equipment/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $equipment = $rows[0] ?? null;
    if (!$equipment || $equipment['tenantId'] !== $user['tenantId']) {
        Router::error('equipment not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['equipmentNumber', 'description', 'category', 'manufacturer', 'model', 'serialNumber', 'plantId', 'location', 'installDate', 'warrantyEnd', 'status', 'criticality', 'costCenterId'];
    [$fields, $paramsUpdate] = maintenanceBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Equipment` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['plant'] = maintenanceLoadPlant($db, $updated['plantId']);
        logAudit($user, 'maintenance', 'equipment', 'UPDATE', $params['id'], $equipment, $updated);
        Router::json($updated);
        return;
    }

    Router::error('equipment not found', 404);
});

$router->add('DELETE', '/api/maintenance/equipment/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Equipment` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $equipment = $rows[0] ?? null;
    if (!$equipment || $equipment['tenantId'] !== $user['tenantId']) {
        Router::error('equipment not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Equipment` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'maintenance', 'equipment', 'DELETE', $params['id'], $equipment, null);
    Router::json(['message' => 'equipment deleted']);
});

// Work Orders
$router->add('GET', '/api/maintenance/work-orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = maintenancePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = maintenanceFilters($query, ['status', 'priority', 'type']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(woNumber LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `WorkOrder` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['equipment'] = maintenanceLoadEquipment($db, $row['equipmentId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `WorkOrder` $whereSql", $params);
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

$router->add('GET', '/api/maintenance/work-orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WorkOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('work_order not found', 404);
        return;
    }

    $order['equipment'] = maintenanceLoadEquipment($db, $order['equipmentId']);
    Router::json($order);
});

$router->add('POST', '/api/maintenance/work-orders', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['woNumber', 'equipmentId', 'type', 'description'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid work_order data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `WorkOrder` WHERE tenantId = :tenantId AND woNumber = :woNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'woNumber' => $body['woNumber']]
    );
    if ($existing) {
        Router::error('work_order already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');
    $db->execute(
        'INSERT INTO `WorkOrder` (id, tenantId, woNumber, equipmentId, type, priority, description, status, plannedStart, plannedEnd, actualStart, actualEnd, estimatedHours, actualHours, estimatedCost, actualCost, assignedTo, notes, createdBy, createdAt, updatedAt)
         VALUES (:id, :tenantId, :woNumber, :equipmentId, :type, :priority, :description, :status, :plannedStart, :plannedEnd, :actualStart, :actualEnd, :estimatedHours, :actualHours, :estimatedCost, :actualCost, :assignedTo, :notes, :createdBy, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'woNumber' => $body['woNumber'],
            'equipmentId' => $body['equipmentId'],
            'type' => $body['type'],
            'priority' => $body['priority'] ?? 'medium',
            'description' => $body['description'],
            'status' => $body['status'] ?? 'open',
            'plannedStart' => $body['plannedStart'] ?? null,
            'plannedEnd' => $body['plannedEnd'] ?? null,
            'actualStart' => $body['actualStart'] ?? null,
            'actualEnd' => $body['actualEnd'] ?? null,
            'estimatedHours' => $body['estimatedHours'] ?? null,
            'actualHours' => $body['actualHours'] ?? null,
            'estimatedCost' => $body['estimatedCost'] ?? null,
            'actualCost' => $body['actualCost'] ?? null,
            'assignedTo' => $body['assignedTo'] ?? null,
            'notes' => $body['notes'] ?? null,
            'createdBy' => $user['userId'],
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $order = $db->query('SELECT * FROM `WorkOrder` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($order) {
        $order['equipment'] = maintenanceLoadEquipment($db, $order['equipmentId']);
        logAudit($user, 'maintenance', 'work_order', 'CREATE', $id, null, $order);
        Router::json($order, 201);
        return;
    }

    Router::error('Failed to create work_order', 500);
});

$router->add('PUT', '/api/maintenance/work-orders/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WorkOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('work_order not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['woNumber', 'equipmentId', 'type', 'priority', 'description', 'status', 'plannedStart', 'plannedEnd', 'actualStart', 'actualEnd', 'estimatedHours', 'actualHours', 'estimatedCost', 'actualCost', 'assignedTo', 'notes'];
    [$fields, $paramsUpdate] = maintenanceBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `WorkOrder` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `WorkOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['equipment'] = maintenanceLoadEquipment($db, $updated['equipmentId']);
        logAudit($user, 'maintenance', 'work_order', 'UPDATE', $params['id'], $order, $updated);
        Router::json($updated);
        return;
    }

    Router::error('work_order not found', 404);
});

$router->add('DELETE', '/api/maintenance/work-orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `WorkOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $order = $rows[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('work_order not found', 404);
        return;
    }

    $db->execute('DELETE FROM `WorkOrder` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'maintenance', 'work_order', 'DELETE', $params['id'], $order, null);
    Router::json(['message' => 'work_order deleted']);
});

// Maintenance Plans
$router->add('GET', '/api/maintenance/maintenance-plans', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = maintenancePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = maintenanceFilters($query, ['type', 'isActive']);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = 'name LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `MaintenancePlan` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['equipment'] = maintenanceLoadEquipment($db, $row['equipmentId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `MaintenancePlan` $whereSql", $params);
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

$router->add('GET', '/api/maintenance/maintenance-plans/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `MaintenancePlan` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $plan = $rows[0] ?? null;
    if (!$plan) {
        Router::error('maintenance_plan not found', 404);
        return;
    }

    $plan['equipment'] = maintenanceLoadEquipment($db, $plan['equipmentId']);
    Router::json($plan);
});

$router->add('POST', '/api/maintenance/maintenance-plans', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['equipmentId', 'name', 'type'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid maintenance_plan data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `MaintenancePlan` (id, equipmentId, name, type, intervalDays, intervalHours, lastExecuted, nextDue, isActive, createdAt)
         VALUES (:id, :equipmentId, :name, :type, :intervalDays, :intervalHours, :lastExecuted, :nextDue, :isActive, :createdAt)',
        [
            'id' => $id,
            'equipmentId' => $body['equipmentId'],
            'name' => $body['name'],
            'type' => $body['type'],
            'intervalDays' => $body['intervalDays'] ?? null,
            'intervalHours' => $body['intervalHours'] ?? null,
            'lastExecuted' => $body['lastExecuted'] ?? null,
            'nextDue' => $body['nextDue'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $plan = $db->query('SELECT * FROM `MaintenancePlan` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($plan) {
        $plan['equipment'] = maintenanceLoadEquipment($db, $plan['equipmentId']);
        logAudit($user, 'maintenance', 'maintenance_plan', 'CREATE', $id, null, $plan);
        Router::json($plan, 201);
        return;
    }

    Router::error('Failed to create maintenance_plan', 500);
});

$router->add('PUT', '/api/maintenance/maintenance-plans/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `MaintenancePlan` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $plan = $rows[0] ?? null;
    if (!$plan) {
        Router::error('maintenance_plan not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['equipmentId', 'name', 'type', 'intervalDays', 'intervalHours', 'lastExecuted', 'nextDue', 'isActive'];
    [$fields, $paramsUpdate] = maintenanceBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `MaintenancePlan` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `MaintenancePlan` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['equipment'] = maintenanceLoadEquipment($db, $updated['equipmentId']);
        logAudit($user, 'maintenance', 'maintenance_plan', 'UPDATE', $params['id'], $plan, $updated);
        Router::json($updated);
        return;
    }

    Router::error('maintenance_plan not found', 404);
});

$router->add('DELETE', '/api/maintenance/maintenance-plans/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `MaintenancePlan` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $plan = $rows[0] ?? null;
    if (!$plan) {
        Router::error('maintenance_plan not found', 404);
        return;
    }

    $db->execute('DELETE FROM `MaintenancePlan` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'maintenance', 'maintenance_plan', 'DELETE', $params['id'], $plan, null);
    Router::json(['message' => 'maintenance_plan deleted']);
});
