<?php
// ============================================================
// VESTIA API — Auth Middleware
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

function getAuthUser(): array {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError('Unauthorized — Missing token', 401);
    }

    $token = trim(substr($authHeader, 7));

    // ✅ التحقق من صيغة الـ token (يجب أن يكون 64 حرفاً hex فقط)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        jsonError('Unauthorized — Invalid token format', 401);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.phone, u.avatar, u.is_active
         FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW()'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('Unauthorized — Invalid or expired token', 401);
    }

    if (!$user['is_active']) {
        jsonError('Account is suspended', 403);
    }

    return $user;
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}
