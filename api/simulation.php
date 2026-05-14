<?php

declare(strict_types=1);

use App\EventBus;
use App\DB;
use App\Router;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

static $activeSessions = [];

const SIMULATION_ROLES = [
    ['role' => 'procurement_manager', 'label' => 'Procurement Manager', 'permissions' => ['create_pr', 'create_po', 'manage_vendors']],
    ['role' => 'production_planner', 'label' => 'Production Planner', 'permissions' => ['create_production_order', 'manage_bom', 'run_mrp']],
    ['role' => 'warehouse_operator', 'label' => 'Warehouse Operator', 'permissions' => ['goods_receipt', 'goods_issue', 'stock_transfer']],
    ['role' => 'finance_controller', 'label' => 'Finance Controller', 'permissions' => ['post_invoice', 'execute_payment', 'journal_entry']],
    ['role' => 'sales_manager', 'label' => 'Sales Manager', 'permissions' => ['create_sales_order', 'manage_customers', 'create_delivery']],
    ['role' => 'quality_inspector', 'label' => 'Quality Inspector', 'permissions' => ['create_inspection', 'record_results', 'non_conformance']],
];

$router->add('GET', '/api/simulation/roles', function () {
    requireRoles([]);
    Router::json(SIMULATION_ROLES);
});

$router->add('POST', '/api/simulation/sessions', function () use (&$activeSessions, $request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    if (empty($body['name'])) {
        Router::error('name is required', 400);
        return;
    }

    $sessionId = 'sim_' . time() . '_' . bin2hex(random_bytes(3));
    $activeSessions[$sessionId] = [
        'id' => $sessionId,
        'tenantId' => $user['tenantId'],
        'name' => $body['name'],
        'createdBy' => $user['userId'],
        'startedAt' => date(DATE_ATOM),
        'players' => [],
        'events' => [],
        'status' => 'waiting',
        'scenario' => $body['scenario'] ?? null,
    ];

    Router::json([
        'sessionId' => $sessionId,
        'name' => $body['name'],
        'status' => 'waiting',
        'availableRoles' => SIMULATION_ROLES,
    ], 201);
});

$router->add('GET', '/api/simulation/sessions', function () use (&$activeSessions) {
    $user = requireRoles([]);
    $sessions = array_values(array_filter($activeSessions, fn ($s) => $s['tenantId'] === $user['tenantId']));
    $summary = array_map(function ($s) {
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'status' => $s['status'],
            'playerCount' => count($s['players']),
            'startedAt' => $s['startedAt'],
            'scenario' => $s['scenario'],
            'players' => array_map(fn ($p) => ['userName' => $p['userName'], 'role' => $p['role']], $s['players']),
        ];
    }, $sessions);
    Router::json($summary);
});

$router->add('POST', '/api/simulation/sessions/{id}/join', function (array $params) use (&$activeSessions, $request) {
    $user = requireRoles([]);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = &$activeSessions[$params['id']];
    if ($session['tenantId'] !== $user['tenantId']) {
        Router::error('Wrong tenant', 403);
        return;
    }

    $body = $request['body'] ?? [];
    $role = $body['role'] ?? null;
    $roleConfig = array_values(array_filter(SIMULATION_ROLES, fn ($r) => $r['role'] === $role));
    if (!$roleConfig) {
        Router::error('Invalid role', 400);
        return;
    }

    foreach ($session['players'] as $player) {
        if ($player['role'] === $role) {
            Router::error("Role \"{$role}\" is already taken", 409);
            return;
        }
        if ($player['userId'] === $user['userId']) {
            Router::error('You are already in this session', 409);
            return;
        }
    }

    $db = DB::getInstance();
    $userRow = $db->query('SELECT firstName, lastName FROM `User` WHERE id = :id LIMIT 1', ['id' => $user['userId']])[0] ?? null;
    $userName = $userRow ? $userRow['firstName'] . ' ' . $userRow['lastName'] : 'Unknown';

    $session['players'][] = [
        'userId' => $user['userId'],
        'userName' => $userName,
        'role' => $role,
        'joinedAt' => date(DATE_ATOM),
        'actions' => [],
    ];
    $session['events'][] = [
        'type' => 'player_joined',
        'userId' => $user['userId'],
        'timestamp' => date(DATE_ATOM),
        'description' => "{$userName} joined as {$role}",
    ];

    Router::json([
        'sessionId' => $session['id'],
        'role' => $role,
        'permissions' => $roleConfig[0]['permissions'] ?? [],
        'players' => array_map(fn ($p) => ['userName' => $p['userName'], 'role' => $p['role']], $session['players']),
    ]);
});

$router->add('POST', '/api/simulation/sessions/{id}/start', function (array $params) use (&$activeSessions) {
    $user = requireRoles(['admin', 'instructor']);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = &$activeSessions[$params['id']];
    if ($session['tenantId'] !== $user['tenantId']) {
        Router::error('Wrong tenant', 403);
        return;
    }
    if (count($session['players']) < 2) {
        Router::error('Need at least 2 players', 400);
        return;
    }
    $session['status'] = 'active';
    $session['events'][] = [
        'type' => 'simulation_started',
        'userId' => $user['userId'],
        'timestamp' => date(DATE_ATOM),
        'description' => 'Simulation started',
    ];
    Router::json(['status' => 'active', 'players' => count($session['players'])]);
});

$router->add('POST', '/api/simulation/sessions/{id}/action', function (array $params) use (&$activeSessions, $request) {
    $user = requireRoles([]);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = &$activeSessions[$params['id']];
    if ($session['status'] !== 'active') {
        Router::error('Session not active', 400);
        return;
    }

    $playerIndex = null;
    foreach ($session['players'] as $index => $player) {
        if ($player['userId'] === $user['userId']) {
            $playerIndex = $index;
            break;
        }
    }
    if ($playerIndex === null) {
        Router::error('You are not in this session', 403);
        return;
    }

    $body = $request['body'] ?? [];
    $action = $body['action'] ?? null;
    if (!$action) {
        Router::error('action is required', 400);
        return;
    }

    $roleConfig = array_values(array_filter(SIMULATION_ROLES, fn ($r) => $r['role'] === $session['players'][$playerIndex]['role']));
    if (!$roleConfig || !in_array($action, $roleConfig[0]['permissions'], true)) {
        Router::error("Action \"{$action}\" not permitted for role", 403);
        return;
    }

    $details = $body['details'] ?? '';
    $documentId = $body['documentId'] ?? null;

    $session['players'][$playerIndex]['actions'][] = [
        'action' => $action,
        'timestamp' => date(DATE_ATOM),
        'details' => $details,
    ];
    $session['events'][] = [
        'type' => 'action_performed',
        'userId' => $user['userId'],
        'timestamp' => date(DATE_ATOM),
        'description' => $session['players'][$playerIndex]['userName'] . ' (' . $session['players'][$playerIndex]['role'] . '): ' . $action . ' — ' . $details,
    ];

    $eventTypeMap = [
        'create_po' => 'PurchaseOrderCreated',
        'goods_receipt' => 'GoodsReceived',
        'goods_issue' => 'GoodsIssued',
        'create_sales_order' => 'SalesOrderCreated',
        'create_delivery' => 'DeliveryCreated',
        'post_invoice' => 'InvoicePosted',
        'execute_payment' => 'PaymentExecuted',
        'create_production_order' => 'ProductionOrderCreated',
    ];
    if (isset($eventTypeMap[$action])) {
        EventBus::getInstance()->publish($eventTypeMap[$action], [
            'tenantId' => $session['tenantId'],
            'userId' => $user['userId'],
            'module' => $session['players'][$playerIndex]['role'],
            'documentId' => $documentId,
            'correlationId' => $session['id'],
            'payload' => ['simulationSession' => $session['id'], 'action' => $action, 'details' => $details],
        ]);
    }

    Router::json([
        'action' => $action,
        'timestamp' => date(DATE_ATOM),
        'sessionEvents' => array_slice($session['events'], -10),
    ]);
});

$router->add('GET', '/api/simulation/sessions/{id}', function (array $params) use (&$activeSessions) {
    $user = requireRoles([]);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = $activeSessions[$params['id']];
    if ($session['tenantId'] !== $user['tenantId']) {
        Router::error('Wrong tenant', 403);
        return;
    }
    Router::json([
        'id' => $session['id'],
        'name' => $session['name'],
        'status' => $session['status'],
        'startedAt' => $session['startedAt'],
        'scenario' => $session['scenario'],
        'players' => array_map(function ($p) {
            return [
                'userName' => $p['userName'],
                'role' => $p['role'],
                'joinedAt' => $p['joinedAt'],
                'actionCount' => count($p['actions']),
                'lastAction' => $p['actions'] ? $p['actions'][count($p['actions']) - 1] : null,
            ];
        }, $session['players']),
        'recentEvents' => array_slice($session['events'], -20),
        'totalEvents' => count($session['events']),
    ]);
});

$router->add('GET', '/api/simulation/sessions/{id}/feed', function (array $params) use (&$activeSessions, $request) {
    $user = requireRoles([]);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = $activeSessions[$params['id']];
    if ($session['tenantId'] !== $user['tenantId']) {
        Router::error('Wrong tenant', 403);
        return;
    }

    $query = $request['query'] ?? [];
    $since = !empty($query['since']) ? strtotime($query['since']) : 0;
    $events = array_values(array_filter($session['events'], fn ($e) => strtotime($e['timestamp']) > $since));

    Router::json([
        'events' => $events,
        'playerStatuses' => array_map(function ($p) {
            return [
                'userName' => $p['userName'],
                'role' => $p['role'],
                'lastAction' => $p['actions'] ? $p['actions'][count($p['actions']) - 1] : null,
            ];
        }, $session['players']),
    ]);
});

$router->add('POST', '/api/simulation/sessions/{id}/end', function (array $params) use (&$activeSessions) {
    $user = requireRoles(['admin', 'instructor']);
    if (!isset($activeSessions[$params['id']])) {
        Router::error('Session not found', 404);
        return;
    }
    $session = &$activeSessions[$params['id']];
    if ($session['tenantId'] !== $user['tenantId']) {
        Router::error('Wrong tenant', 403);
        return;
    }
    $session['status'] = 'completed';
    $playerStats = array_map(fn ($p) => [
        'userName' => $p['userName'],
        'role' => $p['role'],
        'totalActions' => count($p['actions']),
        'actions' => $p['actions'],
    ], $session['players']);

    Router::json([
        'status' => 'completed',
        'duration' => time() - strtotime($session['startedAt']),
        'totalEvents' => count($session['events']),
        'playerStats' => $playerStats,
    ]);
});
