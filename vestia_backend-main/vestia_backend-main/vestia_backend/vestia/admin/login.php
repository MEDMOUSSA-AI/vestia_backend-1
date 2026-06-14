<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once __DIR__ . '/includes/db.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php'); exit;
}

// Rate Limiting — منع Brute Force
$ip       = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')[0];
$rlFile   = sys_get_temp_dir() . '/admin_rl_' . md5($ip) . '.json';
$now      = time();
$attempts = [];

if (file_exists($rlFile)) {
    $attempts = json_decode(file_get_contents($rlFile), true) ?? [];
}
$attempts = array_filter($attempts, fn($t) => $t > $now - 900); // نافذة 15 دقيقة

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (count($attempts) >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } else {
            usleep(500000); // تأخير 0.5 ثانية لإبطاء Brute Force

            $stmt = db()->prepare('SELECT * FROM admins WHERE email = ?');
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($pass, $admin['password'])) {
                // تسجيل دخول ناجح — مسح المحاولات الفاشلة
                @unlink($rlFile);
                session_regenerate_id(true);
                $_SESSION['admin_id']   = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                header('Location: /admin/dashboard.php'); exit;
            }

            // تسجيل محاولة فاشلة
            $attempts[] = $now;
            file_put_contents($rlFile, json_encode(array_values($attempts)));
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — VESTIA Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { font-family: 'Inter', sans-serif; background: #111; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-wrap { width: 100%; max-width: 400px; padding: 16px; }
.login-card { background: #fff; border-radius: 20px; padding: 44px 40px; box-shadow: 0 24px 80px rgba(0,0,0,0.3); }
.brand { text-align: center; margin-bottom: 36px; }
.brand .mark { width: 52px; height: 52px; background: #111; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.brand .mark svg { width: 28px; height: 28px; }
.brand h1 { font-size: 16px; font-weight: 800; letter-spacing: 5px; color: #111; margin: 0; }
.brand p { font-size: 10px; letter-spacing: 4px; color: #9ca3af; margin: 3px 0 0; font-weight: 500; }
.form-label { font-size: 13px; font-weight: 600; color: #374151; }
.form-control { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; font-size: 14px; }
.form-control:focus { border-color: #111; box-shadow: 0 0 0 3px rgba(0,0,0,0.08); }
.btn-login { background: #111; color: #fff; border: none; border-radius: 12px; padding: 13px; font-size: 15px; font-weight: 700; width: 100%; }
.btn-login:hover { background: #333; color: #fff; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 20px; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="brand">
      <div class="mark">
        <svg viewBox="0 0 24 24" fill="white"><path d="M8 0h8v8h8v8h-8v8H8v-8H0V8h8z"/></svg>
      </div>
      <h1>VESTIA</h1>
      <p>COUTURE ADMIN</p>
    </div>
    <?php if ($error): ?>
      <div class="alert-error"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="admin@vestia.com" required autofocus autocomplete="username">
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn-login">Login</button>
    </form>
    <p class="text-center mt-4 mb-0" style="font-size:12px;color:#9ca3af">VESTIA Admin v1.0</p>
  </div>
</div>
</body>
</html>
