<?php
// ============================================================
// VESTIA API — Main Router  (api/index.php)
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);

// ── CORS ──
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Bootstrap ──
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/auth.php';

// ── Controllers ──
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/CategoryController.php';
require_once __DIR__ . '/controllers/CartController.php';
require_once __DIR__ . '/controllers/SavedController.php';
require_once __DIR__ . '/controllers/OrderController.php';
require_once __DIR__ . '/controllers/ReviewController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/FashionAiController.php'; // ← جديد

// ── Route Parsing ──
$method    = $_SERVER['REQUEST_METHOD'];
$uri       = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$path      = '/' . trim(substr($uri, strlen($scriptDir)), '/');
$segments  = array_values(array_filter(explode('/', trim($path, '/'))));
$resource  = $segments[0] ?? '';
$id        = $segments[1] ?? null;
$sub       = $segments[2] ?? null;

// ── Rate Limiting ──
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = explode(',', $ip)[0];
$rateLimitFile = sys_get_temp_dir() . '/rl_' . md5($ip) . '.json';
$now      = time();
$requests = [];
if (file_exists($rateLimitFile)) {
    $requests = json_decode(file_get_contents($rateLimitFile), true) ?? [];
}
$requests = array_filter($requests, fn($t) => $t > $now - 60);
if (count($requests) >= 100) {
    http_response_code(429);
    die(json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']));
}
$requests[] = $now;
file_put_contents($rateLimitFile, json_encode(array_values($requests)));

// ── Route Table ──
match(true) {
    // ── Health ──
    $resource === 'health' && $method === 'GET' => jsonSuccess(['status' => 'ok']),

    // ── Auth ──
    $resource === 'register' && $method === 'POST' => AuthController::register(),
    $resource === 'login'    && $method === 'POST' => AuthController::login(),
    $resource === 'logout'   && $method === 'POST' => AuthController::logout(),

    // ── Categories ──
    $resource === 'categories' && $method === 'GET' => CategoryController::index(),

    // ── Products ──
    $resource === 'products' && $method === 'GET' && $id === null                  => ProductController::index(),
    $resource === 'products' && $method === 'GET' && $id !== null && $sub === null => ProductController::show($id),

    // ── Reviews ──
    $resource === 'products' && $id !== null && $sub === 'reviews' && $method === 'GET'  => ReviewController::index($id),
    $resource === 'products' && $id !== null && $sub === 'reviews' && $method === 'POST' => ReviewController::store($id),

    // ── Saved ──
    $resource === 'saved' && $method === 'GET'  => SavedController::index(),
    $resource === 'saved' && $method === 'POST' => SavedController::toggle(),

    // ── Cart ──
    $resource === 'cart' && $method === 'GET'    => CartController::index(),
    $resource === 'cart' && $method === 'POST'   => CartController::add(),
    $resource === 'cart' && $method === 'PUT'    => CartController::update($id),
    $resource === 'cart' && $method === 'DELETE' => CartController::remove($id),

    // ── Orders ──
    $resource === 'orders' && $method === 'GET'  && $id === null => OrderController::index(),
    $resource === 'orders' && $method === 'GET'  && $id !== null => OrderController::show($id),
    $resource === 'orders' && $method === 'POST'                 => OrderController::store(),

    // ── Profile ──
    $resource === 'profile' && $method === 'GET' => ProfileController::show(),
    $resource === 'profile' && $method === 'PUT' => ProfileController::update(),

    // ── Fashion AI ── (جديد)
    $resource === 'fashion' && $method === 'GET'  && $id === 'suggest' => FashionAiController::suggest(),
    $resource === 'fashion' && $method === 'POST' && $id === 'tryon'   => FashionAiController::tryon(),
    $resource === 'fashion' && $method === 'POST' && $id === 'outfit'  => FashionAiController::outfit(),
    $resource === 'fashion' && $method === 'GET'  && $id === 'credits' => FashionAiController::getCredits(), // ← أضف هذا

    // ── Fashion AI — Admin ──
    $resource === 'admin' && $id === 'fashion' && $sub === 'pairings' && $method === 'POST'   => FashionAiController::addPairing(),
    $resource === 'admin' && $id === 'fashion' && $sub === 'pairings' && $method === 'DELETE' => FashionAiController::removePairing(),

    // ── 404 ──
    default => jsonError('Endpoint not found', 404),
};
