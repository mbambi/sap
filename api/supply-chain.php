<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function supplyChainLoadNode(DB $db, string $nodeId): ?array
{
    $rows = $db->query('SELECT * FROM `SupplyChainNode` WHERE id = :id LIMIT 1', ['id' => $nodeId]);
    return $rows[0] ?? null;
}

function supplyChainLoadLink(DB $db, string $linkId): ?array
{
    $rows = $db->query('SELECT * FROM `SupplyChainLink` WHERE id = :id LIMIT 1', ['id' => $linkId]);
    return $rows[0] ?? null;
}

// Nodes
$router->add('GET', '/api/supply-chain/nodes', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $nodes = $db->query(
        'SELECT * FROM `SupplyChainNode` WHERE tenantId = :tenantId AND isActive = 1 ORDER BY name ASC',
        ['tenantId' => $user['tenantId']]
    );
    Router::json($nodes);
});

$router->add('POST', '/api/supply-chain/nodes', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['name']) || empty($body['type'])) {
        Router::error('name and type are required', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `SupplyChainNode` (id, tenantId, name, type, latitude, longitude, capacity, holdingCost, fixedCost, address, isActive, createdAt)
         VALUES (:id, :tenantId, :name, :type, :latitude, :longitude, :capacity, :holdingCost, :fixedCost, :address, :isActive, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'name' => $body['name'],
            'type' => $body['type'],
            'latitude' => $body['latitude'] ?? null,
            'longitude' => $body['longitude'] ?? null,
            'capacity' => $body['capacity'] ?? null,
            'holdingCost' => $body['holdingCost'] ?? null,
            'fixedCost' => $body['fixedCost'] ?? null,
            'address' => $body['address'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $node = supplyChainLoadNode($db, $id);
    Router::json($node ?? [], 201);
});

$router->add('PUT', '/api/supply-chain/nodes/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $existing = $db->query(
        'SELECT * FROM `SupplyChainNode` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    if (!$existing) {
        Router::error('Node not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $fields = [];
    $paramsUpdate = ['id' => $params['id']];
    foreach (['name', 'type', 'latitude', 'longitude', 'capacity', 'holdingCost', 'fixedCost', 'address', 'isActive'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = sprintf('`%s` = :%s', $field, $field);
            $paramsUpdate[$field] = $body[$field];
        }
    }
    if ($fields) {
        $db->execute('UPDATE `SupplyChainNode` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $node = supplyChainLoadNode($db, $params['id']);
    Router::json($node ?? []);
});

$router->add('DELETE', '/api/supply-chain/nodes/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $existing = $db->query(
        'SELECT id FROM `SupplyChainNode` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    if (!$existing) {
        Router::error('Node not found', 404);
        return;
    }

    $db->execute(
        'DELETE FROM `SupplyChainLink` WHERE fromNodeId = :id OR toNodeId = :id',
        ['id' => $params['id']]
    );
    $db->execute('DELETE FROM `SupplyChainNode` WHERE id = :id', ['id' => $params['id']]);
    Router::json(['message' => 'node deleted'], 204);
});

// Links
$router->add('GET', '/api/supply-chain/links', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $links = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE tenantId = :tenantId AND isActive = 1 ORDER BY fromNodeId ASC',
        ['tenantId' => $user['tenantId']]
    );
    foreach ($links as &$link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }
    Router::json($links);
});

$router->add('POST', '/api/supply-chain/links', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    if (empty($body['fromNodeId']) || empty($body['toNodeId'])) {
        Router::error('fromNodeId and toNodeId are required', 400);
        return;
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `SupplyChainLink` (id, tenantId, fromNodeId, toNodeId, transportMode, distance, costPerUnit, leadTimeDays, capacity, isActive)
         VALUES (:id, :tenantId, :fromNodeId, :toNodeId, :transportMode, :distance, :costPerUnit, :leadTimeDays, :capacity, :isActive)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'fromNodeId' => $body['fromNodeId'],
            'toNodeId' => $body['toNodeId'],
            'transportMode' => $body['transportMode'] ?? 'truck',
            'distance' => $body['distance'] ?? null,
            'costPerUnit' => $body['costPerUnit'] ?? 0,
            'leadTimeDays' => $body['leadTimeDays'] ?? 1,
            'capacity' => $body['capacity'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
        ]
    );

    $link = supplyChainLoadLink($db, $id);
    if ($link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }
    Router::json($link ?? [], 201);
});

$router->add('PUT', '/api/supply-chain/links/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $existing = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    if (!$existing) {
        Router::error('Link not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $fields = [];
    $paramsUpdate = ['id' => $params['id']];
    foreach (['fromNodeId', 'toNodeId', 'transportMode', 'distance', 'costPerUnit', 'leadTimeDays', 'capacity', 'isActive'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = sprintf('`%s` = :%s', $field, $field);
            $paramsUpdate[$field] = $body[$field];
        }
    }
    if ($fields) {
        $db->execute('UPDATE `SupplyChainLink` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $link = supplyChainLoadLink($db, $params['id']);
    if ($link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }
    Router::json($link ?? []);
});

$router->add('DELETE', '/api/supply-chain/links/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $existing = $db->query(
        'SELECT id FROM `SupplyChainLink` WHERE id = :id AND tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    if (!$existing) {
        Router::error('Link not found', 404);
        return;
    }

    $db->execute('DELETE FROM `SupplyChainLink` WHERE id = :id', ['id' => $params['id']]);
    Router::json(['message' => 'link deleted'], 204);
});

// Network
$router->add('GET', '/api/supply-chain/network', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $nodes = $db->query(
        'SELECT * FROM `SupplyChainNode` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    $links = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    foreach ($links as &$link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }

    Router::json(['nodes' => $nodes, 'links' => $links]);
});

// Optimize (greedy)
$router->add('POST', '/api/supply-chain/optimize', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $demand = $body['demand'] ?? null;
    $supply = $body['supply'] ?? null;

    if (!is_array($demand) || !is_array($supply)) {
        Router::error('demand and supply objects are required', 400);
        return;
    }

    $db = DB::getInstance();
    $links = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    foreach ($links as &$link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }

    $routes = [];
    $remainingSupply = $supply;
    $remainingDemand = $demand;

    $customerNodes = array_keys($demand);
    $supplierNodes = array_keys($supply);

    foreach ($customerNodes as $customerId) {
        $need = $remainingDemand[$customerId] ?? 0;
        if ($need <= 0) {
            continue;
        }

        $incomingLinks = array_filter($links, function ($link) use ($customerId, $supplierNodes) {
            return $link['toNodeId'] === $customerId && in_array($link['fromNodeId'], $supplierNodes, true);
        });
        usort($incomingLinks, function ($a, $b) {
            return ($a['costPerUnit'] ?? 0) <=> ($b['costPerUnit'] ?? 0);
        });

        foreach ($incomingLinks as $link) {
            if ($need <= 0) {
                break;
            }
            $available = $remainingSupply[$link['fromNodeId']] ?? 0;
            if ($available <= 0) {
                continue;
            }
            $capacity = $link['capacity'] ?? INF;
            $qty = min($need, $available, $capacity);
            if ($qty <= 0) {
                continue;
            }

            $costPerUnit = $link['costPerUnit'] ?? 0;
            $routes[] = [
                'from' => $link['fromNode']['name'] ?? '',
                'to' => $link['toNode']['name'] ?? '',
                'quantity' => $qty,
                'cost' => $costPerUnit,
                'totalCost' => $costPerUnit * $qty,
            ];

            $remainingSupply[$link['fromNodeId']] = ($remainingSupply[$link['fromNodeId']] ?? 0) - $qty;
            $remainingDemand[$customerId] = ($remainingDemand[$customerId] ?? 0) - $qty;
            $need -= $qty;
        }
    }

    $totalCost = array_reduce($routes, fn ($sum, $r) => $sum + ($r['totalCost'] ?? 0), 0);
    Router::json(['routes' => $routes, 'totalCost' => $totalCost]);
});

// Layout
$router->add('PUT', '/api/supply-chain/layout', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $positions = $body['positions'] ?? null;
    if (!is_array($positions)) {
        Router::error('positions array required', 400);
        return;
    }

    $db = DB::getInstance();
    foreach ($positions as $pos) {
        if (!isset($pos['nodeId'])) {
            continue;
        }
        $db->execute(
            'UPDATE `SupplyChainNode` SET latitude = :latitude, longitude = :longitude WHERE id = :id AND tenantId = :tenantId',
            [
                'latitude' => $pos['y'] ?? null,
                'longitude' => $pos['x'] ?? null,
                'id' => $pos['nodeId'],
                'tenantId' => $user['tenantId'],
            ]
        );
    }

    Router::json(['updated' => count($positions)]);
});

// Editor state
$router->add('GET', '/api/supply-chain/editor', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $nodes = $db->query(
        'SELECT * FROM `SupplyChainNode` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    $links = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    foreach ($links as &$link) {
        $link['fromNode'] = supplyChainLoadNode($db, $link['fromNodeId']);
        $link['toNode'] = supplyChainLoadNode($db, $link['toNodeId']);
    }

    $nodeTemplates = [
        ['type' => 'supplier', 'label' => 'Supplier', 'color' => '#3B82F6', 'icon' => 'truck'],
        ['type' => 'factory', 'label' => 'Factory', 'color' => '#F59E0B', 'icon' => 'factory'],
        ['type' => 'warehouse', 'label' => 'Warehouse', 'color' => '#10B981', 'icon' => 'warehouse'],
        ['type' => 'distribution_center', 'label' => 'Distribution Center', 'color' => '#8B5CF6', 'icon' => 'building'],
        ['type' => 'retail', 'label' => 'Retail Store', 'color' => '#EC4899', 'icon' => 'store'],
        ['type' => 'customer', 'label' => 'Customer', 'color' => '#6366F1', 'icon' => 'users'],
    ];
    $transportModes = [
        ['id' => 'truck', 'label' => 'Truck', 'avgSpeed' => 60, 'costPerKm' => 1.5],
        ['id' => 'rail', 'label' => 'Rail', 'avgSpeed' => 80, 'costPerKm' => 0.8],
        ['id' => 'ship', 'label' => 'Ship', 'avgSpeed' => 30, 'costPerKm' => 0.3],
        ['id' => 'air', 'label' => 'Air', 'avgSpeed' => 800, 'costPerKm' => 5.0],
    ];

    $nodesWithPos = array_map(function ($node) {
        $node['x'] = $node['longitude'] ?? (rand(0, 800));
        $node['y'] = $node['latitude'] ?? (rand(0, 600));
        return $node;
    }, $nodes);

    Router::json([
        'nodes' => $nodesWithPos,
        'links' => $links,
        'nodeTemplates' => $nodeTemplates,
        'transportModes' => $transportModes,
    ]);
});

// Simulate
$router->add('POST', '/api/supply-chain/simulate', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $periods = (int) ($body['periods'] ?? 12);
    $demandMean = (float) ($body['demandMean'] ?? 100);
    $demandStdDev = (float) ($body['demandStdDev'] ?? 20);
    $disruptions = $body['disruptions'] ?? [];

    $db = DB::getInstance();
    $nodes = $db->query(
        'SELECT * FROM `SupplyChainNode` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );
    $links = $db->query(
        'SELECT * FROM `SupplyChainLink` WHERE tenantId = :tenantId AND isActive = 1',
        ['tenantId' => $user['tenantId']]
    );

    if (!$nodes) {
        Router::error('No supply chain nodes to simulate', 400);
        return;
    }

    $suppliers = array_filter($nodes, fn ($n) => $n['type'] === 'supplier');
    $factories = array_filter($nodes, fn ($n) => $n['type'] === 'factory');
    $warehouses = array_filter($nodes, fn ($n) => in_array($n['type'], ['warehouse', 'distribution_center'], true));
    $customers = array_filter($nodes, fn ($n) => in_array($n['type'], ['customer', 'retail'], true));

    $nodeInventory = [];
    foreach ($nodes as $node) {
        $nodeInventory[$node['id']] = $node['capacity'] ? $node['capacity'] * 0.5 : 500;
    }

    $timeline = [];
    $totalCost = 0;
    $totalStockouts = 0;
    $totalDelivered = 0;

    for ($period = 1; $period <= $periods; $period++) {
        $periodEvents = [];
        $periodCost = 0;

        $activeDisruptions = array_filter($disruptions, fn ($d) => ($d['period'] ?? null) === $period);
        foreach ($activeDisruptions as $d) {
            $nodeId = $d['nodeId'] ?? null;
            if (!$nodeId) {
                continue;
            }
            $node = array_values(array_filter($nodes, fn ($n) => $n['id'] === $nodeId))[0] ?? null;
            $name = $node['name'] ?? 'Node';
            if (($d['type'] ?? '') === 'shutdown') {
                $nodeInventory[$nodeId] = 0;
                $periodEvents[] = "Disruption: {$name} shut down";
            } elseif (($d['type'] ?? '') === 'delay') {
                $periodEvents[] = "Disruption: {$name} delayed";
            } elseif (($d['type'] ?? '') === 'capacity_reduction') {
                $severity = (float) ($d['severity'] ?? 0);
                $nodeInventory[$nodeId] *= (1 - $severity / 100);
                $periodEvents[] = "Disruption: {$name} capacity reduced {$severity}%";
            }
        }

        foreach ($suppliers as $sup) {
            $production = ($sup['capacity'] ?? 200) * (0.8 + (rand(0, 40) / 100));
            $nodeInventory[$sup['id']] = ($nodeInventory[$sup['id']] ?? 0) + $production;
        }

        foreach ($links as $link) {
            $available = $nodeInventory[$link['fromNodeId']] ?? 0;
            $capacity = $link['capacity'] ?? 500;
            $flow = min($available * 0.6, $capacity);
            if ($flow > 0) {
                $nodeInventory[$link['fromNodeId']] -= $flow;
                $nodeInventory[$link['toNodeId']] = ($nodeInventory[$link['toNodeId']] ?? 0) + $flow;
                $periodCost += $flow * ($link['costPerUnit'] ?? 1);
            }
        }

        $periodStockouts = 0;
        $periodDelivered = 0;
        foreach ($customers as $cust) {
            $demand = max(0, $demandMean + ((rand(0, 100) / 100) - 0.5) * 2 * $demandStdDev);
            $available = $nodeInventory[$cust['id']] ?? 0;
            $fulfilled = min($demand, $available);
            $nodeInventory[$cust['id']] = max(0, $available - $demand);
            $periodDelivered += $fulfilled;
            if ($fulfilled < $demand) {
                $periodStockouts++;
            }
        }

        foreach ($nodes as $node) {
            $periodCost += ($nodeInventory[$node['id']] ?? 0) * ($node['holdingCost'] ?? 0.5);
        }

        $totalCost += $periodCost;
        $totalStockouts += $periodStockouts;
        $totalDelivered += $periodDelivered;

        $timeline[] = [
            'period' => $period,
            'cost' => round($periodCost),
            'stockouts' => $periodStockouts,
            'delivered' => round($periodDelivered),
            'events' => $periodEvents,
            'inventory' => $nodeInventory,
        ];
    }

    $serviceLevel = count($customers) > 0
        ? round(((($periods * count($customers)) - $totalStockouts) / ($periods * count($customers))) * 10000) / 100
        : 100;

    Router::json([
        'summary' => [
            'totalCost' => round($totalCost),
            'totalDelivered' => round($totalDelivered),
            'totalStockouts' => $totalStockouts,
            'serviceLevel' => $serviceLevel,
            'avgCostPerPeriod' => round($totalCost / max(1, $periods)),
        ],
        'timeline' => $timeline,
        'network' => [
            'nodes' => count($nodes),
            'links' => count($links),
            'suppliers' => count($suppliers),
            'factories' => count($factories),
            'warehouses' => count($warehouses),
            'customers' => count($customers),
        ],
    ]);
});
