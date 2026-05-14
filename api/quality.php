<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function qualityPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function qualityFilters(array $query, array $allowed): array
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

function qualityBuildUpdate(array $data, array $allowed): array
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

function qualityLoadMaterial(DB $db, ?string $materialId): ?array
{
    if (!$materialId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

function qualityLoadResults(DB $db, string $lotId): array
{
    return $db->query('SELECT * FROM `InspectionResult` WHERE inspectionLotId = :id', ['id' => $lotId]);
}

function qualityLoadNonConformances(DB $db, string $lotId): array
{
    return $db->query('SELECT * FROM `NonConformance` WHERE inspectionLotId = :id', ['id' => $lotId]);
}

// Inspection Lots
$router->add('GET', '/api/quality/inspection-lots', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = qualityPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = qualityFilters($query, ['status', 'origin']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = 'lotNumber LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `InspectionLot` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['material'] = qualityLoadMaterial($db, $row['materialId']);
        $row['results'] = qualityLoadResults($db, $row['id']);
        $row['nonConformances'] = qualityLoadNonConformances($db, $row['id']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `InspectionLot` $whereSql", $params);
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

$router->add('GET', '/api/quality/inspection-lots/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $lot = $rows[0] ?? null;
    if (!$lot || $lot['tenantId'] !== $user['tenantId']) {
        Router::error('inspection_lot not found', 404);
        return;
    }

    $lot['material'] = qualityLoadMaterial($db, $lot['materialId']);
    $lot['results'] = qualityLoadResults($db, $lot['id']);
    $lot['nonConformances'] = qualityLoadNonConformances($db, $lot['id']);

    Router::json($lot);
});

$router->add('POST', '/api/quality/inspection-lots', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['lotNumber', 'materialId', 'quantity', 'origin'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid inspection_lot data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `InspectionLot` WHERE tenantId = :tenantId AND lotNumber = :lotNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'lotNumber' => $body['lotNumber']]
    );
    if ($existing) {
        Router::error('inspection_lot already exists with that key', 409);
        return;
    }

    $lotId = generateUuid();
    $results = $body['results'] ?? [];
    $nonConformances = $body['nonConformances'] ?? [];
    $now = date('Y-m-d H:i:s');

    $db->transaction(function (DB $db) use ($body, $results, $nonConformances, $user, $lotId, $now) {
        $db->execute(
            'INSERT INTO `InspectionLot` (id, tenantId, lotNumber, materialId, quantity, origin, referenceDoc, status, inspectedQty, defectiveQty, inspectedBy, inspectedAt, createdAt)
             VALUES (:id, :tenantId, :lotNumber, :materialId, :quantity, :origin, :referenceDoc, :status, :inspectedQty, :defectiveQty, :inspectedBy, :inspectedAt, :createdAt)',
            [
                'id' => $lotId,
                'tenantId' => $user['tenantId'],
                'lotNumber' => $body['lotNumber'],
                'materialId' => $body['materialId'],
                'quantity' => $body['quantity'],
                'origin' => $body['origin'],
                'referenceDoc' => $body['referenceDoc'] ?? null,
                'status' => $body['status'] ?? 'created',
                'inspectedQty' => $body['inspectedQty'] ?? 0,
                'defectiveQty' => $body['defectiveQty'] ?? 0,
                'inspectedBy' => $body['inspectedBy'] ?? null,
                'inspectedAt' => $body['inspectedAt'] ?? null,
                'createdAt' => $now,
            ]
        );

        foreach ($results as $result) {
            $db->execute(
                'INSERT INTO `InspectionResult` (id, inspectionLotId, characteristic, specification, measuredValue, result, notes)
                 VALUES (:id, :inspectionLotId, :characteristic, :specification, :measuredValue, :result, :notes)',
                [
                    'id' => generateUuid(),
                    'inspectionLotId' => $lotId,
                    'characteristic' => $result['characteristic'] ?? '',
                    'specification' => $result['specification'] ?? null,
                    'measuredValue' => $result['measuredValue'] ?? null,
                    'result' => $result['result'] ?? 'pass',
                    'notes' => $result['notes'] ?? null,
                ]
            );
        }

        foreach ($nonConformances as $nc) {
            $db->execute(
                'INSERT INTO `NonConformance` (id, tenantId, ncNumber, inspectionLotId, description, severity, status, rootCause, correctiveAction, assignedTo, dueDate, closedAt, createdAt)
                 VALUES (:id, :tenantId, :ncNumber, :inspectionLotId, :description, :severity, :status, :rootCause, :correctiveAction, :assignedTo, :dueDate, :closedAt, :createdAt)',
                [
                    'id' => generateUuid(),
                    'tenantId' => $user['tenantId'],
                    'ncNumber' => $nc['ncNumber'] ?? generateUuid(),
                    'inspectionLotId' => $lotId,
                    'description' => $nc['description'] ?? '',
                    'severity' => $nc['severity'] ?? 'minor',
                    'status' => $nc['status'] ?? 'open',
                    'rootCause' => $nc['rootCause'] ?? null,
                    'correctiveAction' => $nc['correctiveAction'] ?? null,
                    'assignedTo' => $nc['assignedTo'] ?? null,
                    'dueDate' => $nc['dueDate'] ?? null,
                    'closedAt' => $nc['closedAt'] ?? null,
                    'createdAt' => $now,
                ]
            );
        }
    });

    $lot = $db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $lotId])[0] ?? null;
    if ($lot) {
        $lot['material'] = qualityLoadMaterial($db, $lot['materialId']);
        $lot['results'] = qualityLoadResults($db, $lot['id']);
        $lot['nonConformances'] = qualityLoadNonConformances($db, $lot['id']);
        logAudit($user, 'quality', 'inspection_lot', 'CREATE', $lotId, null, $lot);
        Router::json($lot, 201);
        return;
    }

    Router::error('Failed to create inspection_lot', 500);
});

$router->add('PUT', '/api/quality/inspection-lots/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $lot = $rows[0] ?? null;
    if (!$lot || $lot['tenantId'] !== $user['tenantId']) {
        Router::error('inspection_lot not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['lotNumber', 'materialId', 'quantity', 'origin', 'referenceDoc', 'status', 'inspectedQty', 'defectiveQty', 'inspectedBy', 'inspectedAt'];
    [$fields, $paramsUpdate] = qualityBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `InspectionLot` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    if (isset($body['results'])) {
        $db->transaction(function (DB $db) use ($body, $lot) {
            $db->execute('DELETE FROM `InspectionResult` WHERE inspectionLotId = :id', ['id' => $lot['id']]);
            foreach ($body['results'] as $result) {
                $db->execute(
                    'INSERT INTO `InspectionResult` (id, inspectionLotId, characteristic, specification, measuredValue, result, notes)
                     VALUES (:id, :inspectionLotId, :characteristic, :specification, :measuredValue, :result, :notes)',
                    [
                        'id' => generateUuid(),
                        'inspectionLotId' => $lot['id'],
                        'characteristic' => $result['characteristic'] ?? '',
                        'specification' => $result['specification'] ?? null,
                        'measuredValue' => $result['measuredValue'] ?? null,
                        'result' => $result['result'] ?? 'pass',
                        'notes' => $result['notes'] ?? null,
                    ]
                );
            }
        });
    }

    if (isset($body['nonConformances'])) {
        $db->transaction(function (DB $db) use ($body, $lot, $user) {
            $db->execute('DELETE FROM `NonConformance` WHERE inspectionLotId = :id', ['id' => $lot['id']]);
            foreach ($body['nonConformances'] as $nc) {
                $db->execute(
                    'INSERT INTO `NonConformance` (id, tenantId, ncNumber, inspectionLotId, description, severity, status, rootCause, correctiveAction, assignedTo, dueDate, closedAt, createdAt)
                     VALUES (:id, :tenantId, :ncNumber, :inspectionLotId, :description, :severity, :status, :rootCause, :correctiveAction, :assignedTo, :dueDate, :closedAt, :createdAt)',
                    [
                        'id' => generateUuid(),
                        'tenantId' => $user['tenantId'],
                        'ncNumber' => $nc['ncNumber'] ?? generateUuid(),
                        'inspectionLotId' => $lot['id'],
                        'description' => $nc['description'] ?? '',
                        'severity' => $nc['severity'] ?? 'minor',
                        'status' => $nc['status'] ?? 'open',
                        'rootCause' => $nc['rootCause'] ?? null,
                        'correctiveAction' => $nc['correctiveAction'] ?? null,
                        'assignedTo' => $nc['assignedTo'] ?? null,
                        'dueDate' => $nc['dueDate'] ?? null,
                        'closedAt' => $nc['closedAt'] ?? null,
                        'createdAt' => date('Y-m-d H:i:s'),
                    ]
                );
            }
        });
    }

    $updated = $db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['material'] = qualityLoadMaterial($db, $updated['materialId']);
        $updated['results'] = qualityLoadResults($db, $updated['id']);
        $updated['nonConformances'] = qualityLoadNonConformances($db, $updated['id']);
        logAudit($user, 'quality', 'inspection_lot', 'UPDATE', $params['id'], $lot, $updated);
        Router::json($updated);
        return;
    }

    Router::error('inspection_lot not found', 404);
});

$router->add('DELETE', '/api/quality/inspection-lots/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $lot = $rows[0] ?? null;
    if (!$lot || $lot['tenantId'] !== $user['tenantId']) {
        Router::error('inspection_lot not found', 404);
        return;
    }

    $db->execute('DELETE FROM `InspectionLot` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'quality', 'inspection_lot', 'DELETE', $params['id'], $lot, null);
    Router::json(['message' => 'inspection_lot deleted']);
});

// Non-Conformances
$router->add('GET', '/api/quality/non-conformances', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = qualityPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = qualityFilters($query, ['status', 'severity']);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(ncNumber LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `NonConformance` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['inspectionLot'] = $row['inspectionLotId']
            ? ($db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $row['inspectionLotId']])[0] ?? null)
            : null;
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `NonConformance` $whereSql", $params);
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

$router->add('GET', '/api/quality/non-conformances/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `NonConformance` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $nc = $rows[0] ?? null;
    if (!$nc) {
        Router::error('non_conformance not found', 404);
        return;
    }

    $nc['inspectionLot'] = $nc['inspectionLotId']
        ? ($db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $nc['inspectionLotId']])[0] ?? null)
        : null;

    Router::json($nc);
});

$router->add('POST', '/api/quality/non-conformances', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['ncNumber', 'description'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid non_conformance data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `NonConformance` (id, tenantId, ncNumber, inspectionLotId, description, severity, status, rootCause, correctiveAction, assignedTo, dueDate, closedAt, createdAt)
         VALUES (:id, :tenantId, :ncNumber, :inspectionLotId, :description, :severity, :status, :rootCause, :correctiveAction, :assignedTo, :dueDate, :closedAt, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'ncNumber' => $body['ncNumber'],
            'inspectionLotId' => $body['inspectionLotId'] ?? null,
            'description' => $body['description'],
            'severity' => $body['severity'] ?? 'minor',
            'status' => $body['status'] ?? 'open',
            'rootCause' => $body['rootCause'] ?? null,
            'correctiveAction' => $body['correctiveAction'] ?? null,
            'assignedTo' => $body['assignedTo'] ?? null,
            'dueDate' => $body['dueDate'] ?? null,
            'closedAt' => $body['closedAt'] ?? null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $nc = $db->query('SELECT * FROM `NonConformance` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($nc) {
        $nc['inspectionLot'] = $nc['inspectionLotId']
            ? ($db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $nc['inspectionLotId']])[0] ?? null)
            : null;
        logAudit($user, 'quality', 'non_conformance', 'CREATE', $id, null, $nc);
        Router::json($nc, 201);
        return;
    }

    Router::error('Failed to create non_conformance', 500);
});

$router->add('PUT', '/api/quality/non-conformances/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `NonConformance` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $nc = $rows[0] ?? null;
    if (!$nc) {
        Router::error('non_conformance not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['ncNumber', 'inspectionLotId', 'description', 'severity', 'status', 'rootCause', 'correctiveAction', 'assignedTo', 'dueDate', 'closedAt'];
    [$fields, $paramsUpdate] = qualityBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `NonConformance` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `NonConformance` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['inspectionLot'] = $updated['inspectionLotId']
            ? ($db->query('SELECT * FROM `InspectionLot` WHERE id = :id LIMIT 1', ['id' => $updated['inspectionLotId']])[0] ?? null)
            : null;
        logAudit($user, 'quality', 'non_conformance', 'UPDATE', $params['id'], $nc, $updated);
        Router::json($updated);
        return;
    }

    Router::error('non_conformance not found', 404);
});

$router->add('DELETE', '/api/quality/non-conformances/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `NonConformance` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $nc = $rows[0] ?? null;
    if (!$nc) {
        Router::error('non_conformance not found', 404);
        return;
    }

    $db->execute('DELETE FROM `NonConformance` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'quality', 'non_conformance', 'DELETE', $params['id'], $nc, null);
    Router::json(['message' => 'non_conformance deleted']);
});
