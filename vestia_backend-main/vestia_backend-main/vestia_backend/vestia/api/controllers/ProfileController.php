<?php
// ============================================================
// VESTIA API — Profile Controller
// ============================================================
class ProfileController {

    public static function show(): void {
        $user = getAuthUser();
        $db   = getDB();

        $stmt = $db->prepare('SELECT id, name, phone, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();

        if (!$profile) jsonError('User not found', 404);

        jsonSuccess(['user' => $profile]);
    }

    public static function update(): void {
        $user = getAuthUser();
        $body = getRequestBody();
        $db   = getDB();

        $name  = sanitize(trim($body['name']  ?? ''));
        $phone = trim($body['phone'] ?? '');
        $pass  = $body['password']   ?? '';

        // ── Validation ──
        $errors = [];

        // ✅ حد أقصى لطول الاسم
        if ($name && strlen($name) > 100) {
            $errors['name'] = 'Name is too long';
        }

        if ($phone && !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
            $errors['phone'] = 'Invalid phone number';
        }

        // ✅ تحديث شروط كلمة المرور لتتطابق مع التسجيل (8 أحرف + أرقام وحروف)
        if ($pass) {
            if (strlen($pass) < 8) {
                $errors['password'] = 'Password must be at least 8 characters';
            } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
                $errors['password'] = 'Password must contain letters and numbers';
            }
        }

        if (!empty($errors)) jsonError('Validation failed', 422, $errors);

        $fields = [];
        $params = [];

        if ($name) {
            $fields[] = 'name = ?';
            $params[] = $name;
        }

        if ($phone) {
            $dup = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
            $dup->execute([$phone, $user['id']]);
            if ($dup->fetch()) jsonError('Phone number already in use', 409);

            $fields[] = 'phone = ?';
            $params[] = $phone;
        }

        if ($pass) {
            $fields[] = 'password = ?';
            $params[] = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (empty($fields)) jsonError('Nothing to update', 422);

        $params[] = $user['id'];
        $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);

        jsonSuccess([], 'Profile updated successfully');
    }
}
