<?php

declare(strict_types=1);

/**
 * Front controller - all HTTP requests enter here.
 */
use function header;
use function json_decode;
use function json_encode;
use function ob_end_clean;
use function ob_start;
use function http_response_code;

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Shortener.php';
require_once __DIR__ . '/../src/Router.php';

header('Content-Type: application/json');

$store = new Store();
$router = new Router();

// Define routes
$routes = [
    // POST /api/shorten - Create a short URL
    ['POST', '/api/shorten', fn() => handleShorten($store)],

    // GET /{code} - Redirect to original URL
    ['GET', '/{code}', fn($params) => handleRedirect($store, $params['code'])],

    // GET /api/stats - Get total stats
    ['GET', '/api/stats', fn() => handleStats($store)],

    // GET /api/{code} - Get link details
    ['GET', '/api/{code}', fn($params) => handleGetLink($store, $params['code'])],
];

// Route the request
$result = $router->route($routes);

if (!$result['matched']) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

try {
    $result['handler']($result['params'] ?? []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle POST /api/shorten
 */
function handleShorten(Store $store): void
{
    // Get request body
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        return;
    }

    $data = json_decode($rawInput, true);
    if ($data === null || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }

    if (!isset($data['url'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Missing or invalid URL']);
        return;
    }

    $url = Shortener::validateAndNormalize($data['url']);
    if ($url === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Missing or invalid URL']);
        return;
    }

    // Generate short code
    $code = Shortener::generateCode();
    while ($store->exists($code)) {
        $code = Shortener::generateCode();
    }

    // Store link
    try {
        $link = $store->add($code, [
            'url' => $url,
            'clicks' => 0,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save link']);
        return;
    }

    http_response_code(201);
    $shortUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/' . $code;
    echo json_encode([
        'short_url' => $shortUrl,
        'code' => $code,
        'original_url' => $url,
        'created_at' => $link['created_at'],
    ]);
}

/**
 * Handle GET /{code} - Redirect to original URL
 */
function handleRedirect(Store $store, string $code): void
{
    $link = $store->get($code);
    if ($link === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Short code not found']);
        return;
    }

    try {
        $store->incrementClicks($code);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to increment clicks']);
        return;
    }

    header('Location: ' . $link['url']);
    exit;
}

/**
 * Handle GET /api/{code} - Get link details
 */
function handleGetLink(Store $store, string $code): void
{
    $link = $store->get($code);
    if ($link === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Short code not found']);
        return;
    }

    echo json_encode([
        'code' => $code,
        'original_url' => $link['url'],
        'clicks' => $link['clicks'],
        'created_at' => $link['created_at'],
    ]);
}

/**
 * Handle GET /api/stats - Get total stats
 */
function handleStats(Store $store): void
{
    echo json_encode([
        'total_links' => $store->getTotalLinks(),
        'total_clicks' => $store->getTotalClicks(),
    ]);
}
