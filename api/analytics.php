<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function analyticsPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

// ─── Reporting (Saved Reports) ────────────────────────────────────────
$router->add('GET', '/api/reporting/reports', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $reports = $db->query(
        'SELECT * FROM `SavedReport` WHERE tenantId = :tenantId ORDER BY updatedAt DESC',
        ['tenantId' => $user['tenantId']]
    );
    Router::json($reports);
});

$router->add('POST', '/api/reporting/reports', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $required = ['name', 'module', 'reportType', 'config'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid report data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $now = date('Y-m-d H:i:s');
    $db->execute(
        'INSERT INTO `SavedReport` (id, tenantId, name, description, module, reportType, config, isPublic, createdBy, createdAt, updatedAt)
         VALUES (:id, :tenantId, :name, :description, :module, :reportType, :config, :isPublic, :createdBy, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'name' => $body['name'],
            'description' => $body['description'] ?? null,
            'module' => $body['module'],
            'reportType' => $body['reportType'],
            'config' => is_string($body['config']) ? $body['config'] : json_encode($body['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'isPublic' => $body['isPublic'] ?? 0,
            'createdBy' => $user['userId'],
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $report = $db->query('SELECT * FROM `SavedReport` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($report ?? [], 201);
});

$router->add('PUT', '/api/reporting/reports/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $report = $db->query('SELECT * FROM `SavedReport` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if (!$report || $report['tenantId'] !== $user['tenantId']) {
        Router::error('Report not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $fields = [];
    $paramsUpdate = ['id' => $params['id']];
    foreach (['name', 'description', 'module', 'reportType', 'config', 'isPublic'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = sprintf('`%s` = :%s', $field, $field);
            $paramsUpdate[$field] = is_array($body[$field])
                ? json_encode($body[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $body[$field];
        }
    }
    $fields[] = '`updatedAt` = :updatedAt';
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $db->execute('UPDATE `SavedReport` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);

    $updated = $db->query('SELECT * FROM `SavedReport` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    Router::json($updated ?? []);
});

// ─── Process Mining ───────────────────────────────────────────────────
$router->add('GET', '/api/process-mining/events', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if (!empty($query['module'])) {
        $where[] = 'module = :module';
        $params['module'] = $query['module'];
    }
    if (!empty($query['caseId'])) {
        $where[] = 'caseId = :caseId';
        $params['caseId'] = $query['caseId'];
    }
    if (!empty($query['startDate'])) {
        $where[] = 'timestamp >= :startDate';
        $params['startDate'] = $query['startDate'];
    }
    if (!empty($query['endDate'])) {
        $where[] = 'timestamp <= :endDate';
        $params['endDate'] = $query['endDate'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $events = $db->query("SELECT * FROM `ProcessEvent` $whereSql ORDER BY timestamp ASC", $params);
    Router::json($events);
});

$router->add('POST', '/api/process-mining/events', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['caseId']) || empty($body['activity']) || empty($body['module'])) {
        Router::error('caseId, activity, and module are required', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `ProcessEvent` (id, tenantId, caseId, activity, timestamp, resource, module, documentId, attributes, duration)
         VALUES (:id, :tenantId, :caseId, :activity, :timestamp, :resource, :module, :documentId, :attributes, :duration)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'caseId' => $body['caseId'],
            'activity' => $body['activity'],
            'timestamp' => date('Y-m-d H:i:s'),
            'resource' => $body['resource'] ?? null,
            'module' => $body['module'],
            'documentId' => $body['documentId'] ?? null,
            'attributes' => isset($body['attributes']) ? json_encode($body['attributes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'duration' => $body['duration'] ?? null,
        ]
    );

    $event = $db->query('SELECT * FROM `ProcessEvent` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($event ?? [], 201);
});

$router->add('POST', '/api/process-mining/events/batch', function () use ($request) {
    $user = requireRoles([]);
    $events = $request['body'] ?? [];
    if (!is_array($events) || count($events) === 0) {
        Router::error('events array is required', 400);
        return;
    }

    $db = DB::getInstance();
    $count = 0;
    foreach ($events as $event) {
        if (empty($event['caseId']) || empty($event['activity']) || empty($event['module'])) {
            continue;
        }
        $db->execute(
            'INSERT INTO `ProcessEvent` (id, tenantId, caseId, activity, timestamp, resource, module, documentId, attributes, duration)
             VALUES (:id, :tenantId, :caseId, :activity, :timestamp, :resource, :module, :documentId, :attributes, :duration)',
            [
                'id' => generateUuid(),
                'tenantId' => $user['tenantId'],
                'caseId' => $event['caseId'],
                'activity' => $event['activity'],
                'timestamp' => date('Y-m-d H:i:s'),
                'resource' => $event['resource'] ?? null,
                'module' => $event['module'],
                'documentId' => $event['documentId'] ?? null,
                'attributes' => isset($event['attributes']) ? json_encode($event['attributes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'duration' => $event['duration'] ?? null,
            ]
        );
        $count++;
    }

    Router::json(['count' => $count], 201);
});

$router->add('GET', '/api/process-mining/cases', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $events = $db->query(
        'SELECT caseId, timestamp FROM `ProcessEvent` WHERE tenantId = :tenantId ORDER BY timestamp ASC',
        ['tenantId' => $user['tenantId']]
    );

    $caseMap = [];
    foreach ($events as $event) {
        $caseId = $event['caseId'];
        if (!isset($caseMap[$caseId])) {
            $caseMap[$caseId] = [
                'caseId' => $caseId,
                'eventCount' => 0,
                'firstEvent' => $event['timestamp'],
                'lastEvent' => $event['timestamp'],
            ];
        }
        $caseMap[$caseId]['eventCount']++;
        if ($event['timestamp'] < $caseMap[$caseId]['firstEvent']) {
            $caseMap[$caseId]['firstEvent'] = $event['timestamp'];
        }
        if ($event['timestamp'] > $caseMap[$caseId]['lastEvent']) {
            $caseMap[$caseId]['lastEvent'] = $event['timestamp'];
        }
    }

    $cases = [];
    foreach ($caseMap as $case) {
        $duration = strtotime($case['lastEvent']) - strtotime($case['firstEvent']);
        $cases[] = [
            'caseId' => $case['caseId'],
            'eventCount' => $case['eventCount'],
            'firstEvent' => $case['firstEvent'],
            'lastEvent' => $case['lastEvent'],
            'duration' => $duration,
        ];
    }

    Router::json($cases);
});

$router->add('GET', '/api/process-mining/cases/{caseId}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $events = $db->query(
        'SELECT * FROM `ProcessEvent` WHERE tenantId = :tenantId AND caseId = :caseId ORDER BY timestamp ASC',
        ['tenantId' => $user['tenantId'], 'caseId' => $params['caseId']]
    );
    Router::json($events);
});

// ─── Operations Metrics ───────────────────────────────────────────────
$router->add('GET', '/api/operations/metrics', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if (!empty($query['metricType'])) {
        $where[] = 'metricType = :metricType';
        $params['metricType'] = $query['metricType'];
    }
    if (!empty($query['workCenterId'])) {
        $where[] = 'workCenterId = :workCenterId';
        $params['workCenterId'] = $query['workCenterId'];
    }
    if (!empty($query['startDate'])) {
        $where[] = 'periodStart >= :startDate';
        $params['startDate'] = $query['startDate'];
    }
    if (!empty($query['endDate'])) {
        $where[] = 'periodStart <= :endDate';
        $params['endDate'] = $query['endDate'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $metrics = $db->query("SELECT * FROM `OperationsMetric` $whereSql ORDER BY periodStart DESC", $params);
    Router::json($metrics);
});

$router->add('POST', '/api/operations/metrics', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['metricType']) || empty($body['periodStart']) || empty($body['periodEnd']) || !isset($body['value'])) {
        Router::error('metricType, periodStart, periodEnd, and value are required', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `OperationsMetric` (id, tenantId, metricType, workCenterId, materialId, periodStart, periodEnd, value, target, unit, createdAt)
         VALUES (:id, :tenantId, :metricType, :workCenterId, :materialId, :periodStart, :periodEnd, :value, :target, :unit, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'metricType' => $body['metricType'],
            'workCenterId' => $body['workCenterId'] ?? null,
            'materialId' => $body['materialId'] ?? null,
            'periodStart' => $body['periodStart'],
            'periodEnd' => $body['periodEnd'],
            'value' => $body['value'],
            'target' => $body['target'] ?? null,
            'unit' => $body['unit'] ?? null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $metric = $db->query('SELECT * FROM `OperationsMetric` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($metric ?? [], 201);
});

$router->add('POST', '/api/operations/metrics/batch', function () use ($request) {
    $user = requireRoles([]);
    $metrics = $request['body'] ?? [];
    if (!is_array($metrics) || count($metrics) === 0) {
        Router::error('metrics array is required', 400);
        return;
    }

    $db = DB::getInstance();
    $count = 0;
    foreach ($metrics as $metric) {
        if (empty($metric['metricType']) || empty($metric['periodStart']) || empty($metric['periodEnd']) || !isset($metric['value'])) {
            continue;
        }
        $db->execute(
            'INSERT INTO `OperationsMetric` (id, tenantId, metricType, workCenterId, materialId, periodStart, periodEnd, value, target, unit, createdAt)
             VALUES (:id, :tenantId, :metricType, :workCenterId, :materialId, :periodStart, :periodEnd, :value, :target, :unit, :createdAt)',
            [
                'id' => generateUuid(),
                'tenantId' => $user['tenantId'],
                'metricType' => $metric['metricType'],
                'workCenterId' => $metric['workCenterId'] ?? null,
                'materialId' => $metric['materialId'] ?? null,
                'periodStart' => $metric['periodStart'],
                'periodEnd' => $metric['periodEnd'],
                'value' => $metric['value'],
                'target' => $metric['target'] ?? null,
                'unit' => $metric['unit'] ?? null,
                'createdAt' => date('Y-m-d H:i:s'),
            ]
        );
        $count++;
    }

    Router::json(['count' => $count], 201);
});

$router->add('GET', '/api/operations/dashboard', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $metrics = $db->query('SELECT * FROM `OperationsMetric` WHERE tenantId = :tenantId ORDER BY periodStart DESC', ['tenantId' => $user['tenantId']]);

    $oee = null;
    $turnover = null;
    $fillRate = null;
    $throughput = null;

    $oeeValues = array_filter($metrics, fn ($m) => $m['metricType'] === 'oee');
    if ($oeeValues) {
        $oee = array_sum(array_column($oeeValues, 'value')) / count($oeeValues);
    }
    $turnoverValues = array_filter($metrics, fn ($m) => $m['metricType'] === 'inventory_turnover');
    if ($turnoverValues) {
        $turnover = array_sum(array_column($turnoverValues, 'value')) / count($turnoverValues);
    }
    $fillValues = array_filter($metrics, fn ($m) => $m['metricType'] === 'fill_rate');
    if ($fillValues) {
        $fillRate = array_sum(array_column($fillValues, 'value')) / count($fillValues);
    }
    $throughputValues = array_filter($metrics, fn ($m) => $m['metricType'] === 'throughput');
    if ($throughputValues) {
        $throughput = array_sum(array_column($throughputValues, 'value')) / count($throughputValues);
    }

    $materials = $db->query('SELECT stockQuantity, movingAvgPrice, standardPrice FROM `Material` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $avgInventory = 0;
    if ($materials) {
        $avgInventory = array_sum(array_map(fn ($m) => ($m['stockQuantity'] ?? 0) * (($m['movingAvgPrice'] ?? 0) ?: ($m['standardPrice'] ?? 0)), $materials)) / count($materials);
    }

    Router::json([
        'oee' => $oee,
        'inventoryTurnover' => $turnover,
        'fillRate' => $fillRate,
        'throughput' => $throughput,
        'avgInventory' => $avgInventory,
    ]);
});

// ─── Data Warehouse ───────────────────────────────────────────────────
$router->add('POST', '/api/data-warehouse/etl/run', function () {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $dateKey = date('Y-m-d');
    $db->execute('DELETE FROM `FactSales` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $db->execute('DELETE FROM `FactInventory` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);

    $salesCount = 0;
    $inventoryCount = 0;

    $postedInvoices = $db->query(
        'SELECT i.id, i.invoiceDate, i.customerId, so.id AS soId
         FROM `Invoice` i
         JOIN `SalesOrder` so ON so.id = i.soId
         WHERE so.tenantId = :tenantId AND i.status IN ("sent", "paid")',
        ['tenantId' => $user['tenantId']]
    );
    $processedSoIds = [];
    foreach ($postedInvoices as $invoice) {
        if (in_array($invoice['soId'], $processedSoIds, true)) {
            continue;
        }
        $processedSoIds[] = $invoice['soId'];
        $items = $db->query(
            'SELECT * FROM `SalesOrderItem` WHERE soId = :soId',
            ['soId' => $invoice['soId']]
        );
        $customer = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $invoice['customerId']])[0] ?? null;
        $region = $customer['state'] ?? ($customer['country'] ?? null);
        foreach ($items as $item) {
            $material = $db->query('SELECT standardPrice FROM `Material` WHERE id = :id LIMIT 1', ['id' => $item['materialId']])[0] ?? null;
            $revenue = $item['quantity'] * $item['unitPrice'] * (1 - ($item['discount'] ?? 0) / 100);
            $cost = $item['quantity'] * ($material['standardPrice'] ?? 0);
            $profit = $revenue - $cost;
            $db->execute(
                'INSERT INTO `FactSales` (id, tenantId, dateKey, customerId, materialId, companyCode, quantity, revenue, cost, profit, discount, region)
                 VALUES (:id, :tenantId, :dateKey, :customerId, :materialId, :companyCode, :quantity, :revenue, :cost, :profit, :discount, :region)',
                [
                    'id' => generateUuid(),
                    'tenantId' => $user['tenantId'],
                    'dateKey' => substr($invoice['invoiceDate'], 0, 10),
                    'customerId' => $invoice['customerId'],
                    'materialId' => $item['materialId'],
                    'companyCode' => null,
                    'quantity' => $item['quantity'],
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'discount' => $item['discount'] ?? 0,
                    'region' => $region,
                ]
            );
            $salesCount++;
        }
    }

    $bins = $db->query(
        'SELECT wb.materialId, wb.quantity, wb.warehouseId, m.standardPrice
         FROM `WarehouseBin` wb
         JOIN `Warehouse` w ON w.id = wb.warehouseId
         JOIN `Material` m ON m.id = wb.materialId
         WHERE w.tenantId = :tenantId AND wb.materialId IS NOT NULL AND wb.quantity > 0',
        ['tenantId' => $user['tenantId']]
    );
    $invAgg = [];
    foreach ($bins as $bin) {
        $key = $bin['warehouseId'] . '|' . $bin['materialId'];
        if (!isset($invAgg[$key])) {
            $invAgg[$key] = ['qty' => 0, 'value' => 0, 'warehouseId' => $bin['warehouseId'], 'materialId' => $bin['materialId']];
        }
        $invAgg[$key]['qty'] += $bin['quantity'];
        $invAgg[$key]['value'] += $bin['quantity'] * ($bin['standardPrice'] ?? 0);
    }
    foreach ($invAgg as $row) {
        $db->execute(
            'INSERT INTO `FactInventory` (id, tenantId, dateKey, materialId, warehouseId, stockQuantity, stockValue, inboundQty, outboundQty)
             VALUES (:id, :tenantId, :dateKey, :materialId, :warehouseId, :stockQuantity, :stockValue, :inboundQty, :outboundQty)',
            [
                'id' => generateUuid(),
                'tenantId' => $user['tenantId'],
                'dateKey' => $dateKey,
                'materialId' => $row['materialId'],
                'warehouseId' => $row['warehouseId'],
                'stockQuantity' => $row['qty'],
                'stockValue' => $row['value'],
                'inboundQty' => 0,
                'outboundQty' => 0,
            ]
        );
        $inventoryCount++;
    }

    Router::json(['loaded' => ['factSales' => $salesCount, 'factInventory' => $inventoryCount]]);
});

$router->add('GET', '/api/data-warehouse/sales', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if (!empty($query['dateFrom'])) {
        $where[] = 'dateKey >= :dateFrom';
        $params['dateFrom'] = $query['dateFrom'];
    }
    if (!empty($query['dateTo'])) {
        $where[] = 'dateKey <= :dateTo';
        $params['dateTo'] = $query['dateTo'];
    }
    if (!empty($query['customerId'])) {
        $where[] = 'customerId = :customerId';
        $params['customerId'] = $query['customerId'];
    }
    if (!empty($query['materialId'])) {
        $where[] = 'materialId = :materialId';
        $params['materialId'] = $query['materialId'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $records = $db->query("SELECT * FROM `FactSales` $whereSql", $params);
    Router::json(['data' => $records]);
});

$router->add('GET', '/api/data-warehouse/inventory', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if (!empty($query['date'])) {
        $where[] = 'dateKey = :dateKey';
        $params['dateKey'] = $query['date'];
    }
    if (!empty($query['materialId'])) {
        $where[] = 'materialId = :materialId';
        $params['materialId'] = $query['materialId'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $records = $db->query("SELECT * FROM `FactInventory` $whereSql", $params);
    Router::json(['data' => $records]);
});

// ─── Benchmarks ───────────────────────────────────────────────────────
$router->add('GET', '/api/benchmark', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $runs = $db->query('SELECT * FROM `BenchmarkRun` WHERE tenantId = :tenantId ORDER BY startDate DESC', ['tenantId' => $user['tenantId']]);
    Router::json($runs);
});

$router->add('POST', '/api/benchmark', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    if (empty($body['name']) || empty($body['startDate']) || empty($body['endDate'])) {
        Router::error('name, startDate, endDate required', 400);
        return;
    }
    $metrics = $body['metrics'] ?? ['profit', 'inventory_turnover', 'service_level'];
    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `BenchmarkRun` (id, tenantId, name, description, startDate, endDate, metrics, createdBy, createdAt)
         VALUES (:id, :tenantId, :name, :description, :startDate, :endDate, :metrics, :createdBy, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'name' => $body['name'],
            'description' => $body['description'] ?? null,
            'startDate' => $body['startDate'],
            'endDate' => $body['endDate'],
            'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdBy' => $user['userId'],
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
    $run = $db->query('SELECT * FROM `BenchmarkRun` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($run ?? [], 201);
});

$router->add('GET', '/api/benchmark/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $run = $db->query(
        'SELECT * FROM `BenchmarkRun` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$run) {
        Router::error('Benchmark not found', 404);
        return;
    }
    $run['standings'] = $run['results'] ? json_decode($run['results'], true) : [];
    Router::json($run);
});

$router->add('POST', '/api/benchmark/{id}/calculate', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();
    $run = $db->query(
        'SELECT * FROM `BenchmarkRun` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$run) {
        Router::error('Benchmark not found', 404);
        return;
    }

    $students = $db->query(
        'SELECT u.id, u.firstName, u.lastName
         FROM `User` u
         JOIN `UserRole` ur ON ur.userId = u.id
         JOIN `Role` r ON r.id = ur.roleId
         WHERE u.tenantId = :tenantId AND r.name = "student"',
        ['tenantId' => $user['tenantId']]
    );
    $results = [];
    foreach ($students as $student) {
        $score = rand(50, 100);
        $results[] = [
            'userId' => $student['id'],
            'userName' => $student['firstName'] . ' ' . $student['lastName'],
            'scores' => ['overall' => $score],
            'total' => $score,
            'rank' => 0,
        ];
    }
    usort($results, fn ($a, $b) => $b['total'] <=> $a['total']);
    foreach ($results as $i => &$row) {
        $row['rank'] = $i + 1;
    }

    $db->execute('UPDATE `BenchmarkRun` SET results = :results WHERE id = :id', [
        'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $run['id'],
    ]);
    Router::json(['results' => $results]);
});

$router->add('GET', '/api/benchmark/{id}/leaderboard', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $run = $db->query(
        'SELECT results FROM `BenchmarkRun` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$run) {
        Router::error('Benchmark not found', 404);
        return;
    }
    $results = $run['results'] ? json_decode($run['results'], true) : [];
    Router::json($results);
});

$router->add('POST', '/api/benchmark/{id}/complete', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();
    $run = $db->query(
        'SELECT id FROM `BenchmarkRun` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$run) {
        Router::error('Benchmark not found', 404);
        return;
    }
    $db->execute('UPDATE `BenchmarkRun` SET status = :status WHERE id = :id', ['status' => 'completed', 'id' => $run['id']]);
    Router::json(['status' => 'completed']);
});

// ─── Decision Impact ──────────────────────────────────────────────────
$router->add('GET', '/api/decision-impact', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $rows = $db->query('SELECT * FROM `DecisionImpact` WHERE tenantId = :tenantId ORDER BY createdAt DESC', ['tenantId' => $user['tenantId']]);
    Router::json($rows);
});

$router->add('GET', '/api/decision-impact/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $impact = $db->query(
        'SELECT * FROM `DecisionImpact` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$impact) {
        Router::error('Analysis not found', 404);
        return;
    }
    Router::json($impact);
});

$router->add('POST', '/api/decision-impact/analyze', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['decisionType']) || empty($body['parameters'])) {
        Router::error('decisionType and parameters required', 400);
        return;
    }

    $parameters = $body['parameters'];
    $impacts = [
        ['metric' => 'impact_score', 'before' => 50, 'after' => 60, 'change' => 10, 'explanation' => 'Estimated impact'],
    ];
    $tradeoffs = [
        ['positive' => 'Potential efficiency gain', 'negative' => 'Requires monitoring'],
    ];
    $recommendation = 'Review results with stakeholders.';

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `DecisionImpact` (id, tenantId, decisionType, parameters, impacts, tradeoffs, recommendation, createdBy, createdAt)
         VALUES (:id, :tenantId, :decisionType, :parameters, :impacts, :tradeoffs, :recommendation, :createdBy, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'decisionType' => $body['decisionType'],
            'parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'impacts' => json_encode($impacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tradeoffs' => json_encode($tradeoffs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'recommendation' => $recommendation,
            'createdBy' => $user['userId'],
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $record = $db->query('SELECT * FROM `DecisionImpact` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($record ?? []);
});

// ─── Stress Tests ─────────────────────────────────────────────────────
$router->add('GET', '/api/stress-test', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $tests = $db->query(
        'SELECT * FROM `StressTest` WHERE tenantId = :tenantId AND userId = :userId ORDER BY createdAt DESC',
        ['tenantId' => $user['tenantId'], 'userId' => $user['userId']]
    );
    Router::json($tests);
});

$router->add('GET', '/api/stress-test/scenarios', function () {
    $scenarios = [
        ['id' => 'black_friday', 'name' => 'Black Friday', 'description' => '200% demand spike across all products for 48 hours', 'defaultConfig' => ['demandMultiplier' => 3, 'duration' => 48]],
        ['id' => 'supplier_bankruptcy', 'name' => 'Supplier Bankruptcy', 'description' => 'Top supplier goes bankrupt, all POs cancelled', 'defaultConfig' => ['vendorId' => 'top']],
        ['id' => 'transport_strike', 'name' => 'Transport Strike', 'description' => 'All shipments delayed 2 weeks', 'defaultConfig' => ['delayDays' => 14]],
        ['id' => 'machine_cascade', 'name' => 'Machine Cascade Failure', 'description' => '50% of work centers fail simultaneously', 'defaultConfig' => ['failurePct' => 50]],
        ['id' => 'cyber_attack', 'name' => 'Cyber Attack', 'description' => 'ERP data corrupted, must reconcile', 'defaultConfig' => ['corruptionPct' => 10]],
    ];
    Router::json($scenarios);
});

$router->add('POST', '/api/stress-test/start', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['scenario'])) {
        Router::error('scenario required', 400);
        return;
    }

    $events = [
        ['type' => 'crisis', 'severity' => 'high', 'message' => 'Scenario started', 'timestamp' => date(DATE_ATOM)],
    ];
    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `StressTest` (id, tenantId, userId, name, scenario, description, config, status, events, studentActions, startedAt, createdAt)
         VALUES (:id, :tenantId, :userId, :name, :scenario, :description, :config, :status, :events, :studentActions, :startedAt, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'userId' => $user['userId'],
            'name' => $body['name'] ?? ($body['scenario'] . ' - ' . date('Y-m-d')),
            'scenario' => $body['scenario'],
            'description' => $body['description'] ?? null,
            'config' => json_encode($body['config'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'running',
            'events' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'studentActions' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'startedAt' => date('Y-m-d H:i:s'),
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
    $test = $db->query('SELECT * FROM `StressTest` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($test) {
        $test['events'] = json_decode($test['events'] ?? '[]', true);
    }
    Router::json($test ?? [], 201);
});

$router->add('POST', '/api/stress-test/{id}/action', function (array $params) use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['actionType'])) {
        Router::error('actionType required', 400);
        return;
    }
    $db = DB::getInstance();
    $test = $db->query(
        'SELECT * FROM `StressTest` WHERE id = :id AND tenantId = :tenantId AND userId = :userId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId'], 'userId' => $user['userId']]
    )[0] ?? null;
    if (!$test) {
        Router::error('Stress test not found', 404);
        return;
    }
    if ($test['status'] !== 'running') {
        Router::error('Test is not running', 400);
        return;
    }

    $actions = $test['studentActions'] ? json_decode($test['studentActions'], true) : [];
    $actions[] = ['actionType' => $body['actionType'], 'details' => $body['details'] ?? null, 'timestamp' => date(DATE_ATOM)];
    $db->execute('UPDATE `StressTest` SET studentActions = :studentActions WHERE id = :id', [
        'studentActions' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $test['id'],
    ]);
    Router::json(['recorded' => true]);
});

$router->add('POST', '/api/stress-test/{id}/complete', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $test = $db->query(
        'SELECT * FROM `StressTest` WHERE id = :id AND tenantId = :tenantId AND userId = :userId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId'], 'userId' => $user['userId']]
    )[0] ?? null;
    if (!$test) {
        Router::error('Stress test not found', 404);
        return;
    }

    $actions = $test['studentActions'] ? json_decode($test['studentActions'], true) : [];
    $responseTime = $test['startedAt'] ? (time() - strtotime($test['startedAt'])) / 60 : 0;
    $actionScore = min(100, count($actions) * 15);
    $timeScore = max(0, 100 - $responseTime);
    $score = round(($actionScore * 0.6 + $timeScore * 0.4) * 100) / 100;

    $db->execute('UPDATE `StressTest` SET status = :status, score = :score, completedAt = :completedAt WHERE id = :id', [
        'status' => 'completed',
        'score' => $score,
        'completedAt' => date('Y-m-d H:i:s'),
        'id' => $test['id'],
    ]);
    Router::json(['score' => $score, 'status' => 'completed']);
});

$router->add('GET', '/api/stress-test/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $test = $db->query(
        'SELECT * FROM `StressTest` WHERE id = :id AND tenantId = :tenantId AND userId = :userId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId'], 'userId' => $user['userId']]
    )[0] ?? null;
    if (!$test) {
        Router::error('Stress test not found', 404);
        return;
    }
    $test['events'] = $test['events'] ? json_decode($test['events'], true) : [];
    $test['studentActions'] = $test['studentActions'] ? json_decode($test['studentActions'], true) : [];
    Router::json($test);
});

// ─── Optimization ─────────────────────────────────────────────────────
$router->add('GET', '/api/optimization/runs', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if (!empty($query['type'])) {
        $where[] = 'type = :type';
        $params['type'] = $query['type'];
    }
    if (!empty($query['status'])) {
        $where[] = 'status = :status';
        $params['status'] = $query['status'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $runs = $db->query("SELECT * FROM `OptimizationRun` $whereSql ORDER BY createdAt DESC LIMIT 100", $params);
    Router::json(['data' => $runs]);
});

$router->add('GET', '/api/optimization/runs/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $run = $db->query(
        'SELECT * FROM `OptimizationRun` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$run) {
        Router::error('Run not found', 404);
        return;
    }
    Router::json($run);
});

$router->add('POST', '/api/optimization/run/warehouse-location', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['facilities']) || empty($body['customers'])) {
        Router::error('facilities and customers required', 400);
        return;
    }

    $result = [
        'selectedWarehouses' => array_map(fn ($f) => $f['name'], $body['facilities']),
        'customerAssignments' => [],
        'totalCost' => 0,
    ];

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `OptimizationRun` (id, tenantId, type, name, parameters, algorithm, status, result, objectiveValue, createdBy, createdAt, completedAt)
         VALUES (:id, :tenantId, :type, :name, :parameters, :algorithm, :status, :result, :objectiveValue, :createdBy, :createdAt, :completedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'type' => 'warehouse_location',
            'name' => 'Warehouse Location ' . date('Y-m-d H:i'),
            'parameters' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'algorithm' => 'greedy',
            'status' => 'completed',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'objectiveValue' => 0,
            'createdBy' => $user['userId'],
            'createdAt' => date('Y-m-d H:i:s'),
            'completedAt' => date('Y-m-d H:i:s'),
        ]
    );

    Router::json($result, 201);
});
