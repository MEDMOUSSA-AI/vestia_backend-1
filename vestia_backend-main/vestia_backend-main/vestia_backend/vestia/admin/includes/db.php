<?php
// ============================================================
// VESTIA ADMIN — Database Connection
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=5432;dbname=' . DB_NAME . ';sslmode=require';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            http_response_code(500);
            die('Service unavailable.');
        }
    }
    return $pdo;
}

function adminCheck(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    if (empty($_SESSION['admin_id'])) {
        header('Location: /vestia_backend/vestia/admin/login.php');
        exit;
    }
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

function flash(string $key, string $msg = ''): string {
    if ($msg) {
        $_SESSION['flash_' . $key] = $msg;
        return '';
    }
    $val = $_SESSION['flash_' . $key] ?? '';
    unset($_SESSION['flash_' . $key]);
    return $val;
}

function formatPrice(float $v): string {
    return 'MRU ' . number_format($v, 0, '.', ',');
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return (int)($diff/60)   . 'm ago';
    if ($diff < 86400)  return (int)($diff/3600)  . 'h ago';
    if ($diff < 604800) return (int)($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function sanitize(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
