<?php
// ============================================================
// VESTIA API — Auth Controller
// ============================================================
class AuthController {
    public static function register(): void {
        $body  = getRequestBody();
        $name  = sanitize($body['name']  ?? '');
        $phone = trim($body['phone'] ?? '');
        $pass  = $body['password'] ?? '';

        // Validation
        $errors = [];
        if (strlen($name) < 2 || strlen($name) > 100) $errors['name'] = 'Name must be between 2 and 100 characters.';
        if (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) $errors['phone'] = 'Invalid phone number.';
        if (strlen($pass) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
            $errors['password'] = 'Password must contain letters and numbers.';
        }
        if (!empty($errors)) jsonError('Validation failed', 422, $errors);

        $db = getDB();
        $check = $db->prepare('SELECT id FROM users WHERE phone = ?');
        $check->execute([$phone]);
        if ($check->fetch()) jsonError('Phone number already registered', 409);

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare('INSERT INTO users (name, phone, password) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute([$name, $phone, $hash]);
        $userId = (int)$stmt->fetchColumn();

        $token  = generateToken();
        $expiry = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);
        $db->prepare('INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
           ->execute([$userId, $token, $expiry]);

        jsonSuccess([
            'token' => $token,
            'user'  => ['id' => $userId, 'name' => $name, 'phone' => $phone],
        ], 'Account created successfully', 201);
    }

    public static function login(): void {
        $body  = getRequestBody();
        $phone = trim($body['phone'] ?? '');
        $pass  = $body['password']   ?? '';

        if (!$phone || !$pass) jsonError('Phone and password are required', 422);

        // تأخير للحماية من Brute Force
        usleep(300000);

        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password'])) {
            jsonError('Invalid phone number or password', 401);
        }
        if (!$user['is_active']) jsonError('Account is suspended', 403);

        $token  = generateToken();
        $expiry = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);
        $db->prepare('INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
           ->execute([$user['id'], $token, $expiry]);

        jsonSuccess([
            'token' => $token,
            'user'  => [
                'id'     => $user['id'],
                'name'   => $user['name'],
                'phone'  => $user['phone'],
                'avatar' => $user['avatar'],
            ],
        ], 'Login successful');
    }

    public static function logout(): void {
        $user    = getAuthUser();
        $headers = getallheaders();
        $token   = trim(substr($headers['Authorization'] ?? $headers['authorization'] ?? '', 7));
        $db = getDB();
        $db->prepare('DELETE FROM auth_tokens WHERE token = ?')->execute([$token]);
        jsonSuccess([], 'Logged out successfully');
    }
}
