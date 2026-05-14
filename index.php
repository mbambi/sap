<?php

declare(strict_types=1);

use App\Router;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$origin = getenv('CORS_ORIGIN') ?: '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawBody = file_get_contents('php://input');
$body = $rawBody ? json_decode($rawBody, true) : [];
if (!is_array($body)) {
    $body = [];
}

$request = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
    'query' => $_GET,
    'body' => $body,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
];

$router = new Router();

$router->add('GET', '/api/health', function () {
    Router::json(['status' => 'ok', 'timestamp' => date(DATE_ATOM)]);
});

$handlerFiles = [
    __DIR__ . '/api/auth.php',
    __DIR__ . '/api/finance.php',
    __DIR__ . '/api/materials.php',
    __DIR__ . '/api/sales.php',
    __DIR__ . '/api/production.php',
    __DIR__ . '/api/warehouse.php',
    __DIR__ . '/api/quality.php',
    __DIR__ . '/api/maintenance.php',
    __DIR__ . '/api/hr.php',
    __DIR__ . '/api/controlling.php',
    __DIR__ . '/api/mrp.php',
    __DIR__ . '/api/supply-chain.php',
    __DIR__ . '/api/analytics.php',
    __DIR__ . '/api/learning.php',
    __DIR__ . '/api/gamification.php',
    __DIR__ . '/api/simulation.php',
    __DIR__ . '/api/admin.php',
];

foreach ($handlerFiles as $file) {
    if (file_exists($file)) {
        require $file;
    }
}

try {
    $router->dispatch($request['method'], $request['path']);
} catch (Throwable $exception) {
    Router::error('Internal server error', 500);
}
