<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function salesPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function salesLoadCustomer(DB $db, ?string $customerId): ?array
{
    if (!$customerId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $customerId]);
    return $rows[0] ?? null;
}

function salesLoadMaterial(DB $db, ?string $materialId): ?array
{
    if (!$materialId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Material` WHERE id = :id LIMIT 1', ['id' => $materialId]);
    return $rows[0] ?? null;
}

function salesLoadOrderItems(DB $db, string $soId): array
{
    $rows = $db->query('SELECT * FROM `SalesOrderItem` WHERE soId = :soId ORDER BY lineNumber ASC', ['soId' => $soId]);
    foreach ($rows as &$row) {
        $row['material'] = salesLoadMaterial($db, $row['materialId']);
    }
    return $rows;
}

function salesLoadDeliveries(DB $db, string $soId): array
{
    $deliveries = $db->query('SELECT * FROM `Delivery` WHERE soId = :soId ORDER BY createdAt DESC', ['soId' => $soId]);
    foreach ($deliveries as &$delivery) {
        $delivery['items'] = $db->query('SELECT * FROM `DeliveryItem` WHERE deliveryId = :id', ['id' => $delivery['id']]);
    }
    return $deliveries;
}

function salesLoadInvoices(DB $db, string $soId): array
{
    $invoices = $db->query('SELECT * FROM `Invoice` WHERE soId = :soId ORDER BY createdAt DESC', ['soId' => $soId]);
    foreach ($invoices as &$invoice) {
        $invoice['items'] = $db->query('SELECT * FROM `InvoiceItem` WHERE invoiceId = :id', ['id' => $invoice['id']]);
    }
    return $invoices;
}

// Sales Orders
$router->add('GET', '/api/sales/orders', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = salesPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $status = $query['status'] ?? null;

    $where = ['so.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($status) {
        $where[] = 'so.status = :status';
        $params['status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(so.soNumber LIKE :search OR c.name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT so.* FROM `SalesOrder` so LEFT JOIN `Customer` c ON so.customerId = c.id $whereSql ORDER BY so.createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['customer'] = salesLoadCustomer($db, $row['customerId']);
        $row['items'] = salesLoadOrderItems($db, $row['id']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `SalesOrder` so LEFT JOIN `Customer` c ON so.customerId = c.id $whereSql", $params);
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

$router->add('GET', '/api/sales/orders/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $so = $rows[0] ?? null;
    if (!$so || $so['tenantId'] !== $user['tenantId']) {
        Router::error('SO not found', 404);
        return;
    }

    $so['customer'] = salesLoadCustomer($db, $so['customerId']);
    $so['items'] = salesLoadOrderItems($db, $so['id']);
    $so['deliveries'] = salesLoadDeliveries($db, $so['id']);
    $so['invoices'] = salesLoadInvoices($db, $so['id']);

    Router::json($so);
});

$router->add('POST', '/api/sales/orders', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $items = $body['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        Router::error('At least one item required', 400);
        return;
    }
    if (empty($body['customerId'])) {
        Router::error('customerId is required', 400);
        return;
    }

    $db = DB::getInstance();
    $customer = $db->query('SELECT id FROM `Customer` WHERE id = :id AND tenantId = :tenantId LIMIT 1', [
        'id' => $body['customerId'],
        'tenantId' => $user['tenantId'],
    ]);
    if (!$customer) {
        Router::error('Customer not found', 400);
        return;
    }

    foreach ($items as $item) {
        if (empty($item['materialId'])) {
            Router::error('materialId is required for each item', 400);
            return;
        }
        if (!isset($item['quantity']) || (float) $item['quantity'] <= 0) {
            Router::error('quantity must be > 0', 400);
            return;
        }
        if (!isset($item['unitPrice']) || (float) $item['unitPrice'] < 0) {
            Router::error('unitPrice must be >= 0', 400);
            return;
        }
        $material = $db->query('SELECT id FROM `Material` WHERE id = :id AND tenantId = :tenantId LIMIT 1', [
            'id' => $item['materialId'],
            'tenantId' => $user['tenantId'],
        ]);
        if (!$material) {
            Router::error(sprintf('Material not found: %s', $item['materialId']), 400);
            return;
        }
    }

    $so = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $countRows = $db->query('SELECT COUNT(*) AS total FROM `SalesOrder` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
        $count = (int) ($countRows[0]['total'] ?? 0);
        $soNumber = sprintf('SO-%s', str_pad((string) ($count + 1 + $attempt), 7, '0', STR_PAD_LEFT));

        $exists = $db->query('SELECT id FROM `SalesOrder` WHERE tenantId = :tenantId AND soNumber = :soNumber LIMIT 1', [
            'tenantId' => $user['tenantId'],
            'soNumber' => $soNumber,
        ]);
        if ($exists) {
            continue;
        }

        $totalAmount = array_reduce($items, function ($sum, $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unitPrice'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            return $sum + ($qty * $price * (1 - $discount / 100));
        }, 0.0);

        $soId = generateUuid();
        $now = date('Y-m-d H:i:s');

        $db->transaction(function (DB $db) use ($body, $items, $user, $soId, $soNumber, $totalAmount, $now) {
            $db->execute(
                'INSERT INTO `SalesOrder` (id, tenantId, soNumber, customerId, orderDate, requestedDate, status, totalAmount, currency, paymentTerms, notes, createdBy, createdAt, updatedAt)
                 VALUES (:id, :tenantId, :soNumber, :customerId, :orderDate, :requestedDate, :status, :totalAmount, :currency, :paymentTerms, :notes, :createdBy, :createdAt, :updatedAt)',
                [
                    'id' => $soId,
                    'tenantId' => $user['tenantId'],
                    'soNumber' => $soNumber,
                    'customerId' => $body['customerId'],
                    'orderDate' => $body['orderDate'] ?? $now,
                    'requestedDate' => $body['requestedDate'] ?? null,
                    'status' => $body['status'] ?? 'draft',
                    'totalAmount' => $totalAmount,
                    'currency' => $body['currency'] ?? 'USD',
                    'paymentTerms' => $body['paymentTerms'] ?? 'NET30',
                    'notes' => $body['notes'] ?? null,
                    'createdBy' => $user['userId'],
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            foreach ($items as $index => $item) {
                $qty = (float) ($item['quantity'] ?? 0);
                $price = (float) ($item['unitPrice'] ?? 0);
                $discount = (float) ($item['discount'] ?? 0);
                $db->execute(
                    'INSERT INTO `SalesOrderItem` (id, soId, lineNumber, materialId, quantity, unit, unitPrice, discount, totalPrice, deliveredQty)
                     VALUES (:id, :soId, :lineNumber, :materialId, :quantity, :unit, :unitPrice, :discount, :totalPrice, :deliveredQty)',
                    [
                        'id' => generateUuid(),
                        'soId' => $soId,
                        'lineNumber' => $index + 1,
                        'materialId' => $item['materialId'],
                        'quantity' => $item['quantity'] ?? 0,
                        'unit' => $item['unit'] ?? 'EA',
                        'unitPrice' => $item['unitPrice'] ?? 0,
                        'discount' => $item['discount'] ?? 0,
                        'totalPrice' => $qty * $price * (1 - $discount / 100),
                        'deliveredQty' => $item['deliveredQty'] ?? 0,
                    ]
                );
            }
        });

        $so = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $soId])[0] ?? null;
        if ($so) {
            $so['customer'] = salesLoadCustomer($db, $so['customerId']);
            $so['items'] = salesLoadOrderItems($db, $so['id']);
            logAudit($user, 'sales', 'sales_order', 'CREATE', $soId, null, $so);
        }
        break;
    }

    if (!$so) {
        Router::error('Sales order number collision. Please retry.', 409);
        return;
    }

    Router::json($so, 201);
});

$router->add('POST', '/api/sales/orders/{id}/confirm', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $so = $rows[0] ?? null;
    if (!$so || $so['tenantId'] !== $user['tenantId']) {
        Router::error('SO not found', 404);
        return;
    }
    if ($so['status'] !== 'draft') {
        Router::error('Only draft orders can be confirmed', 400);
        return;
    }

    $db->execute('UPDATE `SalesOrder` SET status = :status, updatedAt = :updatedAt WHERE id = :id', [
        'status' => 'confirmed',
        'updatedAt' => date('Y-m-d H:i:s'),
        'id' => $so['id'],
    ]);

    $updated = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $so['id']])[0] ?? null;
    if ($updated) {
        $updated['customer'] = salesLoadCustomer($db, $updated['customerId']);
        $updated['items'] = salesLoadOrderItems($db, $updated['id']);
        logAudit($user, 'sales', 'sales_order', 'UPDATE', $updated['id'], $so, $updated);
        Router::json($updated);
        return;
    }

    Router::error('SO not found', 404);
});

$router->add('POST', '/api/sales/orders/{id}/deliver', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $so = $rows[0] ?? null;
    if (!$so || $so['tenantId'] !== $user['tenantId']) {
        Router::error('SO not found', 404);
        return;
    }
    if (!in_array($so['status'], ['confirmed', 'processing'], true)) {
        Router::error('Order must be confirmed', 400);
        return;
    }

    $items = $db->query('SELECT * FROM `SalesOrderItem` WHERE soId = :soId', ['soId' => $so['id']]);
    $pendingItems = [];
    foreach ($items as $item) {
        $pendingQty = ($item['quantity'] ?? 0) - ($item['deliveredQty'] ?? 0);
        if ($pendingQty > 0) {
            $pendingItems[] = [
                'itemId' => $item['id'],
                'materialId' => $item['materialId'],
                'quantity' => $pendingQty,
            ];
        }
    }

    if (!$pendingItems) {
        Router::error('No pending quantity to deliver', 400);
        return;
    }

    $countRows = $db->query('SELECT COUNT(*) AS total FROM `Delivery` WHERE soId = :soId', ['soId' => $so['id']]);
    $count = (int) ($countRows[0]['total'] ?? 0);
    $deliveryNumber = sprintf('DL-%s-%d', $so['soNumber'], $count + 1);

    $deliveryId = generateUuid();
    $now = date('Y-m-d H:i:s');

    try {
        $db->transaction(function (DB $db) use ($pendingItems, $so, $deliveryId, $deliveryNumber, $user, $now) {
            $db->execute(
                'INSERT INTO `Delivery` (id, deliveryNumber, soId, customerId, deliveryDate, status, trackingNumber, carrier, notes, createdBy, createdAt)
                 VALUES (:id, :deliveryNumber, :soId, :customerId, :deliveryDate, :status, :trackingNumber, :carrier, :notes, :createdBy, :createdAt)',
                [
                    'id' => $deliveryId,
                    'deliveryNumber' => $deliveryNumber,
                    'soId' => $so['id'],
                    'customerId' => $so['customerId'],
                    'deliveryDate' => $now,
                    'status' => 'planned',
                    'trackingNumber' => null,
                    'carrier' => null,
                    'notes' => null,
                    'createdBy' => $user['userId'],
                    'createdAt' => $now,
                ]
            );

            foreach ($pendingItems as $item) {
                $materialRows = $db->query('SELECT id, stockQuantity FROM `Material` WHERE id = :id LIMIT 1', ['id' => $item['materialId']]);
                $material = $materialRows[0] ?? null;
                if (!$material) {
                    throw new RuntimeException(sprintf('Material not found: %s', $item['materialId']));
                }
                if ($material['stockQuantity'] < $item['quantity']) {
                    throw new RuntimeException(sprintf('Insufficient stock for material: %s', $item['materialId']));
                }

                $db->execute(
                    'UPDATE `Material` SET stockQuantity = stockQuantity - :qty, updatedAt = :updatedAt WHERE id = :id',
                    [
                        'qty' => $item['quantity'],
                        'updatedAt' => $now,
                        'id' => $item['materialId'],
                    ]
                );

                $db->execute(
                    'INSERT INTO `DeliveryItem` (id, deliveryId, materialId, quantity, batchNumber)
                     VALUES (:id, :deliveryId, :materialId, :quantity, :batchNumber)',
                    [
                        'id' => generateUuid(),
                        'deliveryId' => $deliveryId,
                        'materialId' => $item['materialId'],
                        'quantity' => $item['quantity'],
                        'batchNumber' => null,
                    ]
                );

                $db->execute(
                    'UPDATE `SalesOrderItem` SET deliveredQty = deliveredQty + :qty WHERE id = :id',
                    [
                        'qty' => $item['quantity'],
                        'id' => $item['itemId'],
                    ]
                );
            }

            $db->execute('UPDATE `SalesOrder` SET status = :status, updatedAt = :updatedAt WHERE id = :id', [
                'status' => 'processing',
                'updatedAt' => $now,
                'id' => $so['id'],
            ]);
        });
    } catch (RuntimeException $exception) {
        Router::error($exception->getMessage(), 400);
        return;
    }

    $delivery = $db->query('SELECT * FROM `Delivery` WHERE id = :id LIMIT 1', ['id' => $deliveryId])[0] ?? null;
    if ($delivery) {
        $delivery['items'] = $db->query('SELECT * FROM `DeliveryItem` WHERE deliveryId = :id', ['id' => $deliveryId]);
        logAudit($user, 'sales', 'sales_order', 'CREATE', $so['id'], $so, $delivery);
        Router::json($delivery, 201);
        return;
    }

    Router::error('Failed to create delivery', 500);
});

$router->add('POST', '/api/sales/orders/{id}/invoice', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $so = $rows[0] ?? null;
    if (!$so || $so['tenantId'] !== $user['tenantId']) {
        Router::error('SO not found', 404);
        return;
    }

    $items = $db->query('SELECT * FROM `SalesOrderItem` WHERE soId = :soId', ['soId' => $so['id']]);

    $invoice = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $countRows = $db->query('SELECT COUNT(*) AS total FROM `Invoice` WHERE soId = :soId', ['soId' => $so['id']]);
        $count = (int) ($countRows[0]['total'] ?? 0);
        $invoiceNumber = sprintf('INV-%s-%d', $so['soNumber'], $count + 1 + $attempt);

        $exists = $db->query('SELECT id FROM `Invoice` WHERE invoiceNumber = :invoiceNumber LIMIT 1', ['invoiceNumber' => $invoiceNumber]);
        if ($exists) {
            continue;
        }

        $taxRate = 0.1;
        $subtotal = (float) $so['totalAmount'];
        $taxAmount = $subtotal * $taxRate;
        $totalAmount = $subtotal + $taxAmount;

        $dueDate = (new DateTimeImmutable())->modify('+30 days');
        $now = date('Y-m-d H:i:s');

        $invoiceId = generateUuid();
        $db->transaction(function (DB $db) use ($invoiceId, $invoiceNumber, $so, $items, $user, $subtotal, $taxAmount, $totalAmount, $dueDate, $now) {
            $db->execute(
                'INSERT INTO `Invoice` (id, invoiceNumber, soId, customerId, invoiceDate, dueDate, status, subtotal, taxAmount, totalAmount, paidAmount, currency, notes, createdBy, createdAt)
                 VALUES (:id, :invoiceNumber, :soId, :customerId, :invoiceDate, :dueDate, :status, :subtotal, :taxAmount, :totalAmount, :paidAmount, :currency, :notes, :createdBy, :createdAt)',
                [
                    'id' => $invoiceId,
                    'invoiceNumber' => $invoiceNumber,
                    'soId' => $so['id'],
                    'customerId' => $so['customerId'],
                    'invoiceDate' => $now,
                    'dueDate' => $dueDate->format('Y-m-d H:i:s'),
                    'status' => 'draft',
                    'subtotal' => $subtotal,
                    'taxAmount' => $taxAmount,
                    'totalAmount' => $totalAmount,
                    'paidAmount' => 0,
                    'currency' => $so['currency'] ?? 'USD',
                    'notes' => null,
                    'createdBy' => $user['userId'],
                    'createdAt' => $now,
                ]
            );

            foreach ($items as $item) {
                $db->execute(
                    'INSERT INTO `InvoiceItem` (id, invoiceId, description, quantity, unitPrice, totalPrice)
                     VALUES (:id, :invoiceId, :description, :quantity, :unitPrice, :totalPrice)',
                    [
                        'id' => generateUuid(),
                        'invoiceId' => $invoiceId,
                        'description' => sprintf('%s x %s', $item['materialId'], $item['quantity']),
                        'quantity' => $item['quantity'],
                        'unitPrice' => $item['unitPrice'],
                        'totalPrice' => $item['totalPrice'],
                    ]
                );
            }
        });

        $invoice = $db->query('SELECT * FROM `Invoice` WHERE id = :id LIMIT 1', ['id' => $invoiceId])[0] ?? null;
        if ($invoice) {
            $invoice['items'] = $db->query('SELECT * FROM `InvoiceItem` WHERE invoiceId = :id', ['id' => $invoiceId]);
            $invoice['customer'] = salesLoadCustomer($db, $invoice['customerId']);
        }
        break;
    }

    if (!$invoice) {
        Router::error('Invoice number collision. Please retry.', 409);
        return;
    }

    logAudit($user, 'sales', 'sales_order', 'CREATE', $so['id'], $so, $invoice);
    Router::json($invoice, 201);
});

// Deliveries list
$router->add('GET', '/api/sales/deliveries', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = salesPagination($query);

    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT d.* FROM `Delivery` d JOIN `SalesOrder` so ON so.id = d.soId WHERE so.tenantId = :tenantId ORDER BY d.createdAt DESC LIMIT :limit OFFSET :offset',
        ['tenantId' => $user['tenantId'], 'limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['customer'] = salesLoadCustomer($db, $row['customerId']);
        $row['salesOrder'] = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $row['soId']])[0] ?? null;
        $row['items'] = $db->query('SELECT * FROM `DeliveryItem` WHERE deliveryId = :id', ['id' => $row['id']]);
    }

    $countRows = $db->query(
        'SELECT COUNT(*) AS total FROM `Delivery` d JOIN `SalesOrder` so ON so.id = d.soId WHERE so.tenantId = :tenantId',
        ['tenantId' => $user['tenantId']]
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

// Invoices list
$router->add('GET', '/api/sales/invoices', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = salesPagination($query);

    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT i.* FROM `Invoice` i JOIN `SalesOrder` so ON so.id = i.soId WHERE so.tenantId = :tenantId ORDER BY i.createdAt DESC LIMIT :limit OFFSET :offset',
        ['tenantId' => $user['tenantId'], 'limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['customer'] = salesLoadCustomer($db, $row['customerId']);
        $row['salesOrder'] = $db->query('SELECT * FROM `SalesOrder` WHERE id = :id LIMIT 1', ['id' => $row['soId']])[0] ?? null;
        $row['items'] = $db->query('SELECT * FROM `InvoiceItem` WHERE invoiceId = :id', ['id' => $row['id']]);
    }

    $countRows = $db->query(
        'SELECT COUNT(*) AS total FROM `Invoice` i JOIN `SalesOrder` so ON so.id = i.soId WHERE so.tenantId = :tenantId',
        ['tenantId' => $user['tenantId']]
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
