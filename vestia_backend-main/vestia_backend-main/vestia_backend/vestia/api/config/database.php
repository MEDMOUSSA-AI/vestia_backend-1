<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', getenv('DB_HOST') ?: 'dpg-d84grr3tqb8s73fcadn0-a.oregon-postgres.render.com');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'my_fashion_db_dpp9');
define('DB_USER', getenv('DB_USER') ?: 'my_fashion_db_dpp9_user');
define('DB_PASS', getenv('DB_PASS') ?: 'BI7j11oBO6Mvw5vFXxzFOtTnBReLcNgn');
define('TOKEN_EXPIRY', 30 * 24 * 60 * 60);
define('SHIPPING_FEE', 80.00);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
        }
    }
    return $pdo;
}
