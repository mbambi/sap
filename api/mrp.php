<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function mrpPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function mrpLoadMaterial(DB $db, string $materialId): ?array
{
    $rows = $db->query('SELECT id, materialNumber, description, type FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

function mrpGenerateRunNumber(DB $db, string $tenantId): string
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $base = str_pad((string) (time() % 10000000), 7, '0', STR_PAD_LEFT);
        $runNumber = $attempt === 0 ? "MRP-{$base}" : "MRP-{$base}-{$attempt}";
        $exists = $db->query(
            'SELECT id FROM `MrpRun` WHERE tenantId = :tenantId AND runNumber = :runNumber LIMIT 1',
            ['tenantId' => $tenantId, 'runNumber' => $runNumber]
        );
        if (!$exists) {
            return $runNumber;
        }
    }

    return 'MRP-' . str_pad((string) time(), 7, '0', STR_PAD_LEFT);
}

function mrpRunEngine(DB $db, string $tenantId, string $userId, array $config): array
{
    $planningHorizonDays = (int) ($config['planningHorizonDays'] ?? 90);
    $includeForecast = (bool) ($config['includeForecast'] ?? true);
    $includeSafetyStock = (bool) ($config['includeSafetyStock'] ?? true);
    $lotSizingPolicy = (string) ($config['lotSizingPolicy'] ?? 'lot_for_lot');
    $fixedLotSize = isset($config['fixedLotSize']) ? (float) $config['fixedLotSize'] : null;

    $runNumber = mrpGenerateRunNumber($db, $tenantId);
    $runId = generateUuid();
    $today = new DateTimeImmutable('today');
    $todayStr = $today->format('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `MrpRun` (id, tenantId, runNumber, runDate, planningHorizonDays, status, parameters, createdBy, createdAt)
         VALUES (:id, :tenantId, :runNumber, :runDate, :planningHorizonDays, :status, :parameters, :createdBy, :createdAt)',
        [
            'id' => $runId,
            'tenantId' => $tenantId,
            'runNumber' => $runNumber,
            'runDate' => $todayStr,
            'planningHorizonDays' => $planningHorizonDays,
            'status' => 'running',
            'parameters' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdBy' => $userId,
            'createdAt' => $todayStr,
        ]
    );

    $materials = $db->query('SELECT * FROM `Material` WHERE tenantId = :tenantId AND isActive = 1', ['tenantId' => $tenantId]);

    $soRows = $db->query(
        'SELECT soi.materialId, SUM(soi.quantity) AS qty, SUM(soi.deliveredQty) AS delivered
         FROM `SalesOrderItem` soi
         JOIN `SalesOrder` so ON so.id = soi.soId
         WHERE so.tenantId = :tenantId AND so.status NOT IN ("cancelled", "completed")
         GROUP BY soi.materialId',
        ['tenantId' => $tenantId]
    );
    $soMap = [];
    foreach ($soRows as $row) {
        $soMap[$row['materialId']] = [
            'qty' => (float) ($row['qty'] ?? 0),
            'delivered' => (float) ($row['delivered'] ?? 0),
        ];
    }

    $poRows = $db->query(
        'SELECT poi.materialId, SUM(poi.quantity) AS qty, SUM(poi.receivedQty) AS received
         FROM `PurchaseOrderItem` poi
         JOIN `PurchaseOrder` po ON po.id = poi.poId
         WHERE po.tenantId = :tenantId AND po.status NOT IN ("cancelled", "closed")
         GROUP BY poi.materialId',
        ['tenantId' => $tenantId]
    );
    $poMap = [];
    foreach ($poRows as $row) {
        $poMap[$row['materialId']] = [
            'qty' => (float) ($row['qty'] ?? 0),
            'received' => (float) ($row['received'] ?? 0),
        ];
    }

    $horizonEnd = $today->modify('+' . $planningHorizonDays . ' days');
    $forecastMap = [];
    if ($includeForecast) {
        $forecastRows = $db->query(
            'SELECT materialId, SUM(forecastQty) AS forecast
             FROM `DemandForecast`
             WHERE tenantId = :tenantId AND periodEnd >= :startDate AND periodStart <= :endDate
             GROUP BY materialId',
            [
                'tenantId' => $tenantId,
                'startDate' => $today->format('Y-m-d'),
                'endDate' => $horizonEnd->format('Y-m-d'),
            ]
        );
        foreach ($forecastRows as $row) {
            $forecastMap[$row['materialId']] = (float) ($row['forecast'] ?? 0);
        }
    }

    $plannedOrders = [];
    foreach ($materials as $material) {
        $materialId = $material['id'];
        $so = $soMap[$materialId] ?? ['qty' => 0, 'delivered' => 0];
        $openSoQty = $so['qty'] - $so['delivered'];

        $forecastQty = $forecastMap[$materialId] ?? 0;
        $po = $poMap[$materialId] ?? ['qty' => 0, 'received' => 0];
        $openPoQty = $po['qty'] - $po['received'];

        $stock = (float) ($material['stockQuantity'] ?? 0);
        $safetyStock = $includeSafetyStock ? (float) ($material['safetyStock'] ?? 0) : 0;

        $totalDemand = $openSoQty + $forecastQty;
        $netReq = $totalDemand - $openPoQty - $stock + $safetyStock;

        if ($netReq <= 0) {
            continue;
        }

        $lotSize = (float) ($material['lotSize'] ?? 1);
        if ($lotSize <= 0) {
            $lotSize = 1;
        }
        if ($lotSizingPolicy === 'fixed' && $fixedLotSize && $fixedLotSize > 0) {
            $qty = ceil($netReq / $fixedLotSize) * $fixedLotSize;
        } else {
            $qty = ceil($netReq / $lotSize) * $lotSize;
        }

        $leadDays = (int) round((float) ($material['leadTimeDays'] ?? 0));
        $plannedDate = $today->modify('+' . $leadDays . ' days');

        $orderType = in_array($material['type'], ['raw', 'semi-finished', 'trading'], true) ? 'purchase' : 'production';

        $plannedOrders[] = [
            'id' => generateUuid(),
            'tenantId' => $tenantId,
            'mrpRunId' => $runId,
            'materialId' => $materialId,
            'orderType' => $orderType,
            'quantity' => $qty,
            'unit' => $material['baseUnit'] ?? 'EA',
            'plannedDate' => $plannedDate->format('Y-m-d H:i:s'),
            'dueDate' => $plannedDate->format('Y-m-d H:i:s'),
            'status' => 'planned',
            'createdAt' => $todayStr,
        ];
    }

    $db->transaction(function (DB $db) use ($plannedOrders) {
        foreach ($plannedOrders as $order) {
            $db->execute(
                'INSERT INTO `PlannedOrder` (id, tenantId, mrpRunId, materialId, orderType, quantity, unit, plannedDate, dueDate, status, createdAt)
                 VALUES (:id, :tenantId, :mrpRunId, :materialId, :orderType, :quantity, :unit, :plannedDate, :dueDate, :status, :createdAt)',
                $order
            );
        }
    });

    $summary = [
        'materialsProcessed' => count($materials),
        'plannedOrdersCreated' => count($plannedOrders),
        'runNumber' => $runNumber,
    ];

    $db->execute(
        'UPDATE `MrpRun` SET status = :status, results = :results WHERE id = :id',
        [
            'status' => 'completed',
            'results' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $runId,
        ]
    );

    return [
        'runId' => $runId,
        'runNumber' => $runNumber,
        'summary' => $summary,
    ];
}

// Runs list
$router->add('GET', '/api/mrp/runs', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = mrpPagination($query);
    $status = $query['status'] ?? null;

    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `MrpRun` $whereSql ORDER BY runDate DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );
    $countRows = $db->query("SELECT COUNT(*) AS total FROM `MrpRun` $whereSql", $params);
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

// Run MRP
$router->add('POST', '/api/mrp/runs', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $db = DB::getInstance();
    try {
        $result = mrpRunEngine($db, $user['tenantId'], $user['userId'], [
            'planningHorizonDays' => $body['planningHorizonDays'] ?? 90,
        ]);

        $run = $db->query('SELECT * FROM `MrpRun` WHERE id = :id LIMIT 1', ['id' => $result['runId']])[0] ?? null;
        $plannedOrders = $db->query('SELECT * FROM `PlannedOrder` WHERE mrpRunId = :id', ['id' => $result['runId']]);
        if ($run) {
            $run['plannedOrders'] = $plannedOrders;
            Router::json($run, 201);
            return;
        }
    } catch (Throwable $exception) {
        Router::error('MRP run failed', 500);
        return;
    }

    Router::error('MRP run failed', 500);
});

// Run MRP advanced
$router->add('POST', '/api/mrp/runs/advanced', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $db = DB::getInstance();

    try {
        $config = [
            'planningHorizonDays' => $body['planningHorizonDays'] ?? 90,
            'lotSizingPolicy' => $body['lotSizingPolicy'] ?? 'lot_for_lot',
            'fixedLotSize' => $body['fixedLotSize'] ?? null,
            'includeForecast' => $body['includeForecast'] ?? true,
            'includeSafetyStock' => $body['includeSafetyStock'] ?? true,
        ];
        $result = mrpRunEngine($db, $user['tenantId'], $user['userId'], $config);
        $run = $db->query('SELECT * FROM `MrpRun` WHERE id = :id LIMIT 1', ['id' => $result['runId']])[0] ?? null;
        $plannedOrders = $db->query('SELECT * FROM `PlannedOrder` WHERE mrpRunId = :id', ['id' => $result['runId']]);
        if ($run) {
            $run['plannedOrders'] = $plannedOrders;
            Router::json([
                'run' => $run,
                'summary' => $result['summary'],
            ], 201);
            return;
        }
    } catch (Throwable $exception) {
        Router::error('MRP run failed', 500);
        return;
    }

    Router::error('MRP run failed', 500);
});

// Run detail
$router->add('GET', '/api/mrp/runs/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $run = $db->query('SELECT * FROM `MrpRun` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if (!$run || $run['tenantId'] !== $user['tenantId']) {
        Router::error('MRP run not found', 404);
        return;
    }
    $run['plannedOrders'] = $db->query('SELECT * FROM `PlannedOrder` WHERE mrpRunId = :id', ['id' => $params['id']]);
    Router::json($run);
});

// Forecasts
$router->add('GET', '/api/mrp/forecasts', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = mrpPagination($query);
    $materialId = $query['materialId'] ?? null;

    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if ($materialId) {
        $where[] = 'materialId = :materialId';
        $params['materialId'] = $materialId;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `DemandForecast` $whereSql ORDER BY periodStart DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['material'] = mrpLoadMaterial($db, $row['materialId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `DemandForecast` $whereSql", $params);
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

$router->add('POST', '/api/mrp/forecasts', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $db = DB::getInstance();

    if (!empty($body['autoGenerate'])) {
        $materialId = $body['materialId'] ?? null;
        if (!$materialId) {
            Router::error('materialId required for auto-generate', 400);
            return;
        }

        $material = $db->query(
            'SELECT id FROM `Material` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
            ['id' => $materialId, 'tenantId' => $user['tenantId']]
        );
        if (!$material) {
            Router::error('Material not found', 404);
            return;
        }

        $periods = (int) ($body['periods'] ?? 6);
        $horizonDays = (int) ($body['horizonDays'] ?? 90);

        $historical = $db->query(
            'SELECT forecastQty FROM `DemandForecast` WHERE tenantId = :tenantId AND materialId = :materialId ORDER BY periodStart DESC LIMIT :limit',
            ['tenantId' => $user['tenantId'], 'materialId' => $materialId, 'limit' => $periods]
        );
        $salesHistory = $db->query(
            'SELECT soi.quantity FROM `SalesOrderItem` soi JOIN `SalesOrder` so ON so.id = soi.soId
             WHERE so.tenantId = :tenantId AND so.status = "completed" AND soi.materialId = :materialId',
            ['tenantId' => $user['tenantId'], 'materialId' => $materialId]
        );
        $quantities = [];
        foreach ($historical as $row) {
            if ($row['forecastQty'] > 0) {
                $quantities[] = (float) $row['forecastQty'];
            }
        }
        foreach ($salesHistory as $row) {
            if ($row['quantity'] > 0) {
                $quantities[] = (float) $row['quantity'];
            }
        }

        $slice = array_slice($quantities, 0, $periods);
        $movingAvg = $slice ? array_sum($slice) / max(1, count($slice)) : 0;

        $start = new DateTimeImmutable('today');
        $forecastRows = [];
        $periodCount = (int) ceil($horizonDays / 30);
        for ($i = 0; $i < $periodCount; $i++) {
            $periodStart = $start->modify('+' . $i . ' month');
            $periodEnd = $periodStart->modify('+1 month')->modify('-1 day');
            $forecastRows[] = [
                'id' => generateUuid(),
                'tenantId' => $user['tenantId'],
                'materialId' => $materialId,
                'periodStart' => $periodStart->format('Y-m-d H:i:s'),
                'periodEnd' => $periodEnd->format('Y-m-d H:i:s'),
                'forecastQty' => round($movingAvg * 10) / 10,
                'method' => 'moving_avg',
                'confidence' => count($quantities) >= $periods ? 0.8 : 0.5,
                'createdBy' => $user['userId'],
                'createdAt' => date('Y-m-d H:i:s'),
            ];
        }

        $db->transaction(function (DB $db) use ($forecastRows) {
            foreach ($forecastRows as $row) {
                $db->execute(
                    'INSERT INTO `DemandForecast` (id, tenantId, materialId, periodStart, periodEnd, forecastQty, method, confidence, createdBy, createdAt)
                     VALUES (:id, :tenantId, :materialId, :periodStart, :periodEnd, :forecastQty, :method, :confidence, :createdBy, :createdAt)',
                    $row
                );
            }
        });

        Router::json(['data' => $forecastRows, 'method' => 'moving_avg'], 201);
        return;
    }

    if (empty($body['materialId']) || empty($body['periodStart']) || empty($body['periodEnd']) || !isset($body['forecastQty'])) {
        Router::error('materialId, periodStart, periodEnd, and forecastQty are required', 400);
        return;
    }

    $material = $db->query(
        'SELECT id FROM `Material` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $body['materialId'], 'tenantId' => $user['tenantId']]
    );
    if (!$material) {
        Router::error('Material not found', 404);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `DemandForecast` (id, tenantId, materialId, periodStart, periodEnd, forecastQty, method, confidence, createdBy, createdAt)
         VALUES (:id, :tenantId, :materialId, :periodStart, :periodEnd, :forecastQty, :method, :confidence, :createdBy, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'materialId' => $body['materialId'],
            'periodStart' => $body['periodStart'],
            'periodEnd' => $body['periodEnd'],
            'forecastQty' => $body['forecastQty'],
            'method' => $body['method'] ?? 'manual',
            'confidence' => $body['confidence'] ?? null,
            'createdBy' => $user['userId'],
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $forecast = $db->query('SELECT * FROM `DemandForecast` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($forecast ?? [], 201);
});

// Planned orders
$router->add('GET', '/api/mrp/planned-orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = mrpPagination($query);
    $status = $query['status'] ?? null;
    $mrpRunId = $query['mrpRunId'] ?? null;

    $db = DB::getInstance();
    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];
    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    if ($mrpRunId) {
        $where[] = 'mrpRunId = :mrpRunId';
        $params['mrpRunId'] = $mrpRunId;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `PlannedOrder` $whereSql ORDER BY plannedDate ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['material'] = mrpLoadMaterial($db, $row['materialId']);
        if (!empty($row['mrpRunId'])) {
            $row['mrpRun'] = $db->query('SELECT * FROM `MrpRun` WHERE id = :id LIMIT 1', ['id' => $row['mrpRunId']])[0] ?? null;
        }
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `PlannedOrder` $whereSql", $params);
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

$router->add('POST', '/api/mrp/planned-orders/{id}/firm', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $order = $db->query('SELECT * FROM `PlannedOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('Planned order not found', 404);
        return;
    }
    if ($order['status'] !== 'planned') {
        Router::error(sprintf('Cannot firm order with status %s', $order['status']), 400);
        return;
    }

    $db->execute('UPDATE `PlannedOrder` SET status = :status WHERE id = :id', ['status' => 'firmed', 'id' => $order['id']]);
    $updated = $db->query('SELECT * FROM `PlannedOrder` WHERE id = :id LIMIT 1', ['id' => $order['id']])[0] ?? null;
    Router::json($updated ?? []);
});

$router->add('POST', '/api/mrp/planned-orders/{id}/convert', function (array $params) use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $db = DB::getInstance();

    $order = $db->query('SELECT * FROM `PlannedOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if (!$order || $order['tenantId'] !== $user['tenantId']) {
        Router::error('Planned order not found', 404);
        return;
    }
    if ($order['status'] === 'converted') {
        Router::error('Order already converted', 400);
        return;
    }

    $material = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $order['materialId']])[0] ?? null;
    if (!$material || $material['tenantId'] !== $user['tenantId']) {
        Router::error('Material not found', 404);
        return;
    }

    $unitPrice = ($material['movingAvgPrice'] ?? 0) > 0 ? $material['movingAvgPrice'] : $material['standardPrice'];

    if ($order['orderType'] === 'purchase') {
        $vendorId = $body['vendorId'] ?? null;
        if (!$vendorId) {
            Router::error('vendorId required to convert to purchase order', 400);
            return;
        }

        $vendor = $db->query(
            'SELECT id FROM `Vendor` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
            ['id' => $vendorId, 'tenantId' => $user['tenantId']]
        );
        if (!$vendor) {
            Router::error('Vendor not found', 404);
            return;
        }

        $countRows = $db->query('SELECT COUNT(*) AS total FROM `PurchaseOrder` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
        $count = (int) ($countRows[0]['total'] ?? 0);
        $poNumber = sprintf('PO-%s', str_pad((string) ($count + 1), 7, '0', STR_PAD_LEFT));
        $totalPrice = $order['quantity'] * $unitPrice;

        $poId = generateUuid();
        $now = date('Y-m-d H:i:s');
        $db->transaction(function (DB $db) use ($poId, $poNumber, $vendorId, $order, $user, $unitPrice, $totalPrice, $now) {
            $db->execute(
                'INSERT INTO `PurchaseOrder` (id, tenantId, poNumber, vendorId, orderDate, deliveryDate, status, totalAmount, currency, createdBy, createdAt, updatedAt)
                 VALUES (:id, :tenantId, :poNumber, :vendorId, :orderDate, :deliveryDate, :status, :totalAmount, :currency, :createdBy, :createdAt, :updatedAt)',
                [
                    'id' => $poId,
                    'tenantId' => $user['tenantId'],
                    'poNumber' => $poNumber,
                    'vendorId' => $vendorId,
                    'orderDate' => $now,
                    'deliveryDate' => $order['dueDate'],
                    'status' => 'draft',
                    'totalAmount' => $totalPrice,
                    'currency' => 'USD',
                    'createdBy' => $user['userId'],
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $db->execute(
                'INSERT INTO `PurchaseOrderItem` (id, poId, lineNumber, materialId, quantity, unit, unitPrice, totalPrice, deliveryDate, receivedQty)
                 VALUES (:id, :poId, :lineNumber, :materialId, :quantity, :unit, :unitPrice, :totalPrice, :deliveryDate, :receivedQty)',
                [
                    'id' => generateUuid(),
                    'poId' => $poId,
                    'lineNumber' => 1,
                    'materialId' => $order['materialId'],
                    'quantity' => (int) round($order['quantity']),
                    'unit' => $order['unit'],
                    'unitPrice' => $unitPrice,
                    'totalPrice' => $totalPrice,
                    'deliveryDate' => $order['dueDate'],
                    'receivedQty' => 0,
                ]
            );
        });

        $db->execute(
            'UPDATE `PlannedOrder` SET status = :status, convertedTo = :convertedTo, vendorId = :vendorId WHERE id = :id',
            ['status' => 'converted', 'convertedTo' => $poId, 'vendorId' => $vendorId, 'id' => $order['id']]
        );

        $plannedOrder = $db->query('SELECT * FROM `PlannedOrder` WHERE id = :id LIMIT 1', ['id' => $order['id']])[0] ?? null;
        $purchaseOrder = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $poId])[0] ?? null;
        Router::json(['plannedOrder' => $plannedOrder, 'purchaseOrder' => $purchaseOrder], 201);
        return;
    }

    $countRows = $db->query('SELECT COUNT(*) AS total FROM `ProductionOrder` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $count = (int) ($countRows[0]['total'] ?? 0);
    $orderNumber = sprintf('PROD-%s', str_pad((string) ($count + 1), 7, '0', STR_PAD_LEFT));

    $prodId = generateUuid();
    $db->execute(
        'INSERT INTO `ProductionOrder` (id, tenantId, orderNumber, materialId, quantity, unit, plannedStart, plannedEnd, status, createdBy, createdAt, updatedAt)
         VALUES (:id, :tenantId, :orderNumber, :materialId, :quantity, :unit, :plannedStart, :plannedEnd, :status, :createdBy, :createdAt, :updatedAt)',
        [
            'id' => $prodId,
            'tenantId' => $user['tenantId'],
            'orderNumber' => $orderNumber,
            'materialId' => $order['materialId'],
            'quantity' => (int) round($order['quantity']),
            'unit' => $order['unit'],
            'plannedStart' => $order['plannedDate'],
            'plannedEnd' => $order['dueDate'],
            'status' => 'planned',
            'createdBy' => $user['userId'],
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );

    $db->execute(
        'UPDATE `PlannedOrder` SET status = :status, convertedTo = :convertedTo WHERE id = :id',
        ['status' => 'converted', 'convertedTo' => $prodId, 'id' => $order['id']]
    );

    $plannedOrder = $db->query('SELECT * FROM `PlannedOrder` WHERE id = :id LIMIT 1', ['id' => $order['id']])[0] ?? null;
    $productionOrder = $db->query('SELECT * FROM `ProductionOrder` WHERE id = :id LIMIT 1', ['id' => $prodId])[0] ?? null;
    Router::json(['plannedOrder' => $plannedOrder, 'productionOrder' => $productionOrder], 201);
});
