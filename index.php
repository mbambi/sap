<?php

declare(strict_types=1);

use App\Router;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

function buildAllowedOrigins(string $rawOrigins): array
{
    $origins = array_filter(array_map('trim', explode(',', $rawOrigins)));
    return $origins === [] ? [] : array_values(array_unique($origins));
}

function isOriginAllowed(?string $requestOrigin, array $allowedOrigins): bool
{
    if ($requestOrigin === null || $requestOrigin === '') {
        return true;
    }
    if (in_array('*', $allowedOrigins, true)) {
        return true;
    }
    return in_array($requestOrigin, $allowedOrigins, true);
}

$requestId = bin2hex(random_bytes(8));
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigins = buildAllowedOrigins((string) (getenv('CORS_ORIGIN') ?: 'http://localhost:5173,http://localhost:4173'));

if (!isOriginAllowed($requestOrigin, $allowedOrigins)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Origin not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$corsOrigin = $requestOrigin ?: ($allowedOrigins[0] ?? '');
if ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'');
if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header('Cache-Control: no-store');
header('X-Request-Id: ' . $requestId);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody !== '' && $rawBody !== false) {
    $body = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        Router::json(['error' => 'Invalid JSON payload', 'requestId' => $requestId], 400);
        exit;
    }
} else {
    $body = [];
}
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
    error_log(sprintf('[%s] Unhandled exception: %s', $requestId, $exception->getMessage()));
    Router::json(['error' => 'Internal server error', 'requestId' => $requestId], 500);
}
