<?php
session_start();
session_unset();
session_destroy();

// ✅ مسح الكوكي من المتصفح
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header('Location: /admin/login.php');
exit;
