<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function materialsPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function materialsFilters(array $query, array $allowed): array
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

function materialsBuildUpdate(array $data, array $allowed): array
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

function materialsLoadVendor(DB $db, ?string $vendorId): ?array
{
    if (!$vendorId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $vendorId]);
    return $rows[0] ?? null;
}

function materialsLoadMaterial(DB $db, ?string $materialId): ?array
{
    if (!$materialId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

function materialsLoadPurchaseOrderItems(DB $db, string $poId): array
{
    $rows = $db->query('SELECT * FROM `PurchaseOrderItem` WHERE poId = :poId ORDER BY lineNumber ASC', ['poId' => $poId]);
    foreach ($rows as &$row) {
        $row['material'] = materialsLoadMaterial($db, $row['materialId']);
    }
    return $rows;
}

function materialsHydrateVendor(array $row): ?array
{
    if (empty($row['vendorId'])) {
        return null;
    }

    return [
        'id' => $row['vendorId'],
        'name' => $row['vendor_name'] ?? null,
        'vendorNumber' => $row['vendor_number'] ?? null,
    ];
}

function materialsLoadGoodsReceipts(DB $db, string $poId): array
{
    $receipts = $db->query('SELECT * FROM `GoodsReceipt` WHERE poId = :poId ORDER BY createdAt DESC', ['poId' => $poId]);
    foreach ($receipts as &$receipt) {
        $receipt['items'] = $db->query('SELECT * FROM `GoodsReceiptItem` WHERE goodsReceiptId = :id', ['id' => $receipt['id']]);
    }
    return $receipts;
}

// Materials master
$router->add('GET', '/api/materials/items', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = materialsPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = materialsFilters($query, ['type', 'materialGroup', 'isActive']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(materialNumber LIKE :search OR description LIKE :search OR materialGroup LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT * FROM `Material` $whereSql ORDER BY materialNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Material` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

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

$router->add('GET', '/api/materials/items/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('material not found', 404);
        return;
    }

    Router::json($record);
});

$router->add('POST', '/api/materials/items', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['materialNumber', 'description', 'type'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid material data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Material` WHERE tenantId = :tenantId AND materialNumber = :materialNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'materialNumber' => $body['materialNumber']]
    );
    if ($existing) {
        Router::error('material already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `Material` (id, tenantId, materialNumber, description, type, baseUnit, materialGroup, weight, weightUnit, volume, volumeUnit, standardPrice, movingAvgPrice, lotSize, safetyStock, reorderPoint, leadTimeDays, stockQuantity, reservedQty, valuationClass, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :materialNumber, :description, :type, :baseUnit, :materialGroup, :weight, :weightUnit, :volume, :volumeUnit, :standardPrice, :movingAvgPrice, :lotSize, :safetyStock, :reorderPoint, :leadTimeDays, :stockQuantity, :reservedQty, :valuationClass, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'materialNumber' => $body['materialNumber'],
            'description' => $body['description'],
            'type' => $body['type'],
            'baseUnit' => $body['baseUnit'] ?? 'EA',
            'materialGroup' => $body['materialGroup'] ?? null,
            'weight' => $body['weight'] ?? null,
            'weightUnit' => $body['weightUnit'] ?? null,
            'volume' => $body['volume'] ?? null,
            'volumeUnit' => $body['volumeUnit'] ?? null,
            'standardPrice' => $body['standardPrice'] ?? 0,
            'movingAvgPrice' => $body['movingAvgPrice'] ?? 0,
            'lotSize' => $body['lotSize'] ?? 1,
            'safetyStock' => $body['safetyStock'] ?? 0,
            'reorderPoint' => $body['reorderPoint'] ?? 0,
            'leadTimeDays' => $body['leadTimeDays'] ?? 0,
            'stockQuantity' => $body['stockQuantity'] ?? 0,
            'reservedQty' => $body['reservedQty'] ?? 0,
            'valuationClass' => $body['valuationClass'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $record = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        logAudit($user, 'materials', 'material', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create material', 500);
});

$router->add('PUT', '/api/materials/items/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('material not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = [
        'materialNumber', 'description', 'type', 'baseUnit', 'materialGroup', 'weight', 'weightUnit', 'volume',
        'volumeUnit', 'standardPrice', 'movingAvgPrice', 'lotSize', 'safetyStock', 'reorderPoint', 'leadTimeDays',
        'stockQuantity', 'reservedQty', 'valuationClass', 'isActive',
    ];
    [$fields, $paramsUpdate] = materialsBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Material` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'materials', 'material', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('material not found', 404);
});

$router->add('DELETE', '/api/materials/items/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('material not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Material` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'materials', 'material', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'material deleted']);
});

// Plants
$router->add('GET', '/api/materials/plants', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = materialsPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = materialsFilters($query, ['isActive']);

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

    $data = $db->query(
        "SELECT * FROM `Plant` $whereSql ORDER BY code ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Plant` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

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

$router->add('GET', '/api/materials/plants/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('plant not found', 404);
        return;
    }

    Router::json($record);
});

$router->add('POST', '/api/materials/plants', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['code', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid plant data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Plant` WHERE tenantId = :tenantId AND code = :code LIMIT 1',
        ['tenantId' => $user['tenantId'], 'code' => $body['code']]
    );
    if ($existing) {
        Router::error('plant already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Plant` (id, tenantId, code, name, address, isActive)
         VALUES (:id, :tenantId, :code, :name, :address, :isActive)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'code' => $body['code'],
            'name' => $body['name'],
            'address' => $body['address'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
        ]
    );

    $record = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        logAudit($user, 'materials', 'plant', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create plant', 500);
});

$router->add('PUT', '/api/materials/plants/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('plant not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = materialsBuildUpdate($body, ['code', 'name', 'address', 'isActive']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Plant` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'materials', 'plant', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('plant not found', 404);
});

$router->add('DELETE', '/api/materials/plants/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('plant not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Plant` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'materials', 'plant', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'plant deleted']);
});

// Purchase Orders
$router->add('GET', '/api/materials/purchase-orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = materialsPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $status = $query['status'] ?? null;

    $where = ['po.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($status) {
        $where[] = 'po.status = :status';
        $params['status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(po.poNumber LIKE :search OR v.name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT po.*,
                v.name AS vendor_name,
                v.vendorNumber AS vendor_number,
                COUNT(poi.id) AS itemCount
         FROM `PurchaseOrder` po
         LEFT JOIN `Vendor` v ON po.vendorId = v.id
         LEFT JOIN `PurchaseOrderItem` poi ON poi.poId = po.id
         $whereSql
         GROUP BY po.id
         ORDER BY po.createdAt DESC
         LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $rows = array_map(function ($row) {
        $row['vendor'] = materialsHydrateVendor($row);
        $row['itemCount'] = (int) ($row['itemCount'] ?? 0);
        unset($row['vendor_name'], $row['vendor_number']);
        return $row;
    }, $rows);

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `PurchaseOrder` po LEFT JOIN `Vendor` v ON po.vendorId = v.id $whereSql", $params);
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

$router->add('GET', '/api/materials/purchase-orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $po = $rows[0] ?? null;
    if (!$po || $po['tenantId'] !== $user['tenantId']) {
        Router::error('PO not found', 404);
        return;
    }

    $po['vendor'] = materialsLoadVendor($db, $po['vendorId']);
    $po['items'] = materialsLoadPurchaseOrderItems($db, $po['id']);
    $po['goodsReceipts'] = materialsLoadGoodsReceipts($db, $po['id']);

    Router::json($po);
});

$router->add('POST', '/api/materials/purchase-orders', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $items = $body['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        Router::error('At least one line item required', 400);
        return;
    }
    if (empty($body['vendorId'])) {
        Router::error('vendorId is required', 400);
        return;
    }

    $db = DB::getInstance();
    $countRows = $db->query('SELECT COUNT(*) AS total FROM `PurchaseOrder` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $count = (int) ($countRows[0]['total'] ?? 0);
    $poNumber = sprintf('PO-%s', str_pad((string) ($count + 1), 7, '0', STR_PAD_LEFT));

    $totalAmount = array_reduce($items, fn ($sum, $item) => $sum + ((float) ($item['quantity'] ?? 0) * (float) ($item['unitPrice'] ?? 0)), 0.0);

    $poId = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->transaction(function (DB $db) use ($body, $items, $user, $poId, $poNumber, $totalAmount, $now) {
        $db->execute(
            'INSERT INTO `PurchaseOrder` (id, tenantId, poNumber, vendorId, orderDate, deliveryDate, status, totalAmount, currency, paymentTerms, notes, approvedBy, approvedAt, createdBy, createdAt, updatedAt)
             VALUES (:id, :tenantId, :poNumber, :vendorId, :orderDate, :deliveryDate, :status, :totalAmount, :currency, :paymentTerms, :notes, :approvedBy, :approvedAt, :createdBy, :createdAt, :updatedAt)',
            [
                'id' => $poId,
                'tenantId' => $user['tenantId'],
                'poNumber' => $poNumber,
                'vendorId' => $body['vendorId'],
                'orderDate' => $body['orderDate'] ?? $now,
                'deliveryDate' => $body['deliveryDate'] ?? null,
                'status' => $body['status'] ?? 'draft',
                'totalAmount' => $totalAmount,
                'currency' => $body['currency'] ?? 'USD',
                'paymentTerms' => $body['paymentTerms'] ?? 'NET30',
                'notes' => $body['notes'] ?? null,
                'approvedBy' => $body['approvedBy'] ?? null,
                'approvedAt' => $body['approvedAt'] ?? null,
                'createdBy' => $user['userId'],
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
        );

        foreach ($items as $index => $item) {
            $db->execute(
                'INSERT INTO `PurchaseOrderItem` (id, poId, lineNumber, materialId, quantity, unit, unitPrice, totalPrice, deliveryDate, receivedQty)
                 VALUES (:id, :poId, :lineNumber, :materialId, :quantity, :unit, :unitPrice, :totalPrice, :deliveryDate, :receivedQty)',
                [
                    'id' => generateUuid(),
                    'poId' => $poId,
                    'lineNumber' => $index + 1,
                    'materialId' => $item['materialId'],
                    'quantity' => $item['quantity'] ?? 0,
                    'unit' => $item['unit'] ?? 'EA',
                    'unitPrice' => $item['unitPrice'] ?? 0,
                    'totalPrice' => ($item['quantity'] ?? 0) * ($item['unitPrice'] ?? 0),
                    'deliveryDate' => $item['deliveryDate'] ?? null,
                    'receivedQty' => $item['receivedQty'] ?? 0,
                ]
            );
        }
    });

    $po = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $poId])[0] ?? null;
    if ($po) {
        $po['vendor'] = materialsLoadVendor($db, $po['vendorId']);
        $po['items'] = materialsLoadPurchaseOrderItems($db, $po['id']);
        logAudit($user, 'materials', 'purchase_order', 'CREATE', $poId, null, $po);
        Router::json($po, 201);
        return;
    }

    Router::error('Failed to create PO', 500);
});

$router->add('POST', '/api/materials/purchase-orders/{id}/approve', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $po = $rows[0] ?? null;
    if (!$po || $po['tenantId'] !== $user['tenantId']) {
        Router::error('PO not found', 404);
        return;
    }
    if ($po['status'] !== 'draft') {
        Router::error('Only draft POs can be approved', 400);
        return;
    }

    $db->execute(
        'UPDATE `PurchaseOrder` SET status = :status, approvedBy = :approvedBy, approvedAt = :approvedAt, updatedAt = :updatedAt WHERE id = :id',
        [
            'status' => 'approved',
            'approvedBy' => $user['userId'],
            'approvedAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
            'id' => $po['id'],
        ]
    );

    $updated = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $po['id']])[0] ?? null;
    if ($updated) {
        $updated['vendor'] = materialsLoadVendor($db, $updated['vendorId']);
        $updated['items'] = materialsLoadPurchaseOrderItems($db, $updated['id']);
        logAudit($user, 'materials', 'purchase_order', 'UPDATE', $updated['id'], $po, $updated);
        Router::json($updated);
        return;
    }

    Router::error('PO not found', 404);
});

$router->add('POST', '/api/materials/purchase-orders/{id}/goods-receipt', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `PurchaseOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $po = $rows[0] ?? null;
    if (!$po || $po['tenantId'] !== $user['tenantId']) {
        Router::error('PO not found', 404);
        return;
    }
    if (!in_array($po['status'], ['approved', 'ordered'], true)) {
        Router::error('PO must be approved or ordered', 400);
        return;
    }

    $body = $request['body'] ?? [];
    $items = $body['items'] ?? [];

    $poItems = $db->query('SELECT * FROM `PurchaseOrderItem` WHERE poId = :poId', ['poId' => $po['id']]);
    $grCountRows = $db->query('SELECT COUNT(*) AS total FROM `GoodsReceipt` WHERE poId = :poId', ['poId' => $po['id']]);
    $grCount = (int) ($grCountRows[0]['total'] ?? 0);
    $grNumber = sprintf('GR-%s-%d', $po['poNumber'], $grCount + 1);

    $receiptId = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->transaction(function (DB $db) use ($items, $poItems, $po, $user, $receiptId, $grNumber, $now) {
        $db->execute(
            'INSERT INTO `GoodsReceipt` (id, poId, grNumber, receiptDate, notes, createdBy, createdAt)
             VALUES (:id, :poId, :grNumber, :receiptDate, :notes, :createdBy, :createdAt)',
            [
                'id' => $receiptId,
                'poId' => $po['id'],
                'grNumber' => $grNumber,
                'receiptDate' => $now,
                'notes' => null,
                'createdBy' => $user['userId'],
                'createdAt' => $now,
            ]
        );

        $itemList = $items ?: $poItems;
        foreach ($itemList as $item) {
            $db->execute(
                'INSERT INTO `GoodsReceiptItem` (id, goodsReceiptId, materialId, quantity, batchNumber, storageLocation)
                 VALUES (:id, :goodsReceiptId, :materialId, :quantity, :batchNumber, :storageLocation)',
                [
                    'id' => generateUuid(),
                    'goodsReceiptId' => $receiptId,
                    'materialId' => $item['materialId'],
                    'quantity' => $item['quantity'] ?? 0,
                    'batchNumber' => $item['batchNumber'] ?? null,
                    'storageLocation' => $item['storageLocation'] ?? null,
                ]
            );

            $db->execute(
                'UPDATE `Material` SET stockQuantity = stockQuantity + :qty, updatedAt = :updatedAt WHERE id = :id',
                [
                    'qty' => $item['quantity'] ?? 0,
                    'updatedAt' => $now,
                    'id' => $item['materialId'],
                ]
            );

            $db->execute(
                'INSERT INTO `InventoryMovement` (id, materialId, movementType, quantity, unit, fromLocation, toLocation, reference, reason, createdBy, createdAt)
                 VALUES (:id, :materialId, :movementType, :quantity, :unit, :fromLocation, :toLocation, :reference, :reason, :createdBy, :createdAt)',
                [
                    'id' => generateUuid(),
                    'materialId' => $item['materialId'],
                    'movementType' => 'receipt',
                    'quantity' => $item['quantity'] ?? 0,
                    'unit' => $item['unit'] ?? 'EA',
                    'fromLocation' => null,
                    'toLocation' => $item['storageLocation'] ?? null,
                    'reference' => $grNumber,
                    'reason' => null,
                    'createdBy' => $user['userId'],
                    'createdAt' => $now,
                ]
            );
        }
    });

    $allReceived = true;
    foreach ($poItems as $poItem) {
        $receivedForItem = null;
        foreach ($items as $item) {
            if (($item['materialId'] ?? null) === $poItem['materialId']) {
                $receivedForItem = $item;
                break;
            }
        }
        $receivedQty = ($poItem['receivedQty'] ?? 0) + ($receivedForItem['quantity'] ?? $poItem['quantity']);
        if ($receivedQty < ($poItem['quantity'] ?? 0)) {
            $allReceived = false;
            break;
        }
    }

    $newStatus = $allReceived ? 'received' : 'ordered';
    $db->execute('UPDATE `PurchaseOrder` SET status = :status, updatedAt = :updatedAt WHERE id = :id', [
        'status' => $newStatus,
        'updatedAt' => date('Y-m-d H:i:s'),
        'id' => $po['id'],
    ]);

    $receipt = $db->query('SELECT * FROM `GoodsReceipt` WHERE id = :id LIMIT 1', ['id' => $receiptId])[0] ?? null;
    if ($receipt) {
        $receipt['items'] = $db->query('SELECT * FROM `GoodsReceiptItem` WHERE goodsReceiptId = :id', ['id' => $receiptId]);
        logAudit($user, 'materials', 'purchase_order', 'CREATE', $po['id'], $po, $receipt);
        Router::json($receipt, 201);
        return;
    }

    Router::error('Failed to create goods receipt', 500);
});

// Inventory movements
$router->add('GET', '/api/materials/inventory-movements', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = materialsPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = materialsFilters($query, ['movementType']);

    $where = ['m.tenantId = :tenantId'];
    $params = [];
    $params['tenantId'] = $user['tenantId'];

    if ($search !== '') {
        $where[] = '(reference LIKE :search OR movementType LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('im.`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT im.*,
                m.materialNumber AS material_materialNumber,
                m.description AS material_description
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         $whereSql
         ORDER BY im.createdAt DESC
         LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $data = array_map(function ($row) {
        $row['material'] = [
            'id' => $row['materialId'],
            'materialNumber' => $row['material_materialNumber'] ?? null,
            'description' => $row['material_description'] ?? null,
        ];
        unset($row['material_materialNumber'], $row['material_description']);
        return $row;
    }, $data);

    $countRows = $db->query(
        "SELECT COUNT(*) AS total
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         $whereSql",
        $params
    );
    $total = (int) ($countRows[0]['total'] ?? 0);

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

$router->add('GET', '/api/materials/inventory-movements/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT im.*, m.materialNumber AS material_materialNumber, m.description AS material_description
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         WHERE im.id = :id AND m.tenantId = :tenantId
         LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    $record = $rows[0] ?? null;

    if (!$record) {
        Router::error('inventory_movement not found', 404);
        return;
    }

    $record['material'] = [
        'id' => $record['materialId'],
        'materialNumber' => $record['material_materialNumber'] ?? null,
        'description' => $record['material_description'] ?? null,
    ];
    unset($record['material_materialNumber'], $record['material_description']);
    Router::json($record);
});

$router->add('POST', '/api/materials/inventory-movements', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['materialId', 'movementType', 'quantity'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid inventory_movement data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $material = $db->query(
        'SELECT id FROM `Material` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $body['materialId'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if (!$material) {
        Router::error('material not found', 404);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `InventoryMovement` (id, materialId, movementType, quantity, unit, fromLocation, toLocation, reference, reason, createdBy, createdAt)
         VALUES (:id, :materialId, :movementType, :quantity, :unit, :fromLocation, :toLocation, :reference, :reason, :createdBy, :createdAt)',
        [
            'id' => $id,
            'materialId' => $body['materialId'],
            'movementType' => $body['movementType'],
            'quantity' => $body['quantity'],
            'unit' => $body['unit'] ?? 'EA',
            'fromLocation' => $body['fromLocation'] ?? null,
            'toLocation' => $body['toLocation'] ?? null,
            'reference' => $body['reference'] ?? null,
            'reason' => $body['reason'] ?? null,
            'createdBy' => $user['userId'],
            'createdAt' => $now,
        ]
    );

    $record = $db->query('SELECT * FROM `InventoryMovement` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        $record['material'] = materialsLoadMaterial($db, $record['materialId']);
        logAudit($user, 'materials', 'inventory_movement', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create inventory_movement', 500);
});

$router->add('PUT', '/api/materials/inventory-movements/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT im.*
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         WHERE im.id = :id AND m.tenantId = :tenantId
         LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    $record = $rows[0] ?? null;
    if (!$record) {
        Router::error('inventory_movement not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['materialId', 'movementType', 'quantity', 'unit', 'fromLocation', 'toLocation', 'reference', 'reason'];
    [$fields, $paramsUpdate] = materialsBuildUpdate($body, $allowed);
    if (isset($body['materialId'])) {
        $material = $db->query(
            'SELECT id FROM `Material` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
            ['id' => $body['materialId'], 'tenantId' => $user['tenantId']]
        )[0] ?? null;
        if (!$material) {
            Router::error('material not found', 404);
            return;
        }
    }
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `InventoryMovement` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query(
        'SELECT im.*, m.materialNumber AS material_materialNumber, m.description AS material_description
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         WHERE im.id = :id AND m.tenantId = :tenantId
         LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    if ($updated) {
        $updated['material'] = [
            'id' => $updated['materialId'],
            'materialNumber' => $updated['material_materialNumber'] ?? null,
            'description' => $updated['material_description'] ?? null,
        ];
        unset($updated['material_materialNumber'], $updated['material_description']);
        logAudit($user, 'materials', 'inventory_movement', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('inventory_movement not found', 404);
});

$router->add('DELETE', '/api/materials/inventory-movements/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT im.*
         FROM `InventoryMovement` im
         JOIN `Material` m ON m.id = im.materialId
         WHERE im.id = :id AND m.tenantId = :tenantId
         LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    $record = $rows[0] ?? null;
    if (!$record) {
        Router::error('inventory_movement not found', 404);
        return;
    }

    $db->execute('DELETE FROM `InventoryMovement` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'materials', 'inventory_movement', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'inventory_movement deleted']);
});

// Goods receipts
$router->add('GET', '/api/materials/goods-receipts', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = materialsPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $where = ['po.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(gr.grNumber LIKE :search OR po.poNumber LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $rows = $db->query(
        "SELECT gr.*,
                po.poNumber,
                COUNT(gri.id) AS itemCount
         FROM `GoodsReceipt` gr
         JOIN `PurchaseOrder` po ON po.id = gr.poId
         LEFT JOIN `GoodsReceiptItem` gri ON gri.goodsReceiptId = gr.id
         $whereSql
         GROUP BY gr.id
         ORDER BY gr.createdAt DESC
         LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $rows = array_map(function ($row) {
        $row['itemCount'] = (int) ($row['itemCount'] ?? 0);
        return $row;
    }, $rows);

    $countRows = $db->query(
        "SELECT COUNT(*) AS total
         FROM `GoodsReceipt` gr
         JOIN `PurchaseOrder` po ON po.id = gr.poId
         $whereSql",
        $params
    );
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
