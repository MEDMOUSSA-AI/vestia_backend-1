<?php
// ============================================================
// VESTIA API — Response Helpers
// ============================================================
function jsonSuccess($data = [], string $message = 'Success', int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jsonError(string $message = 'Error', int $code = 400, array $errors = []): void {
    http_response_code($code);
    $res = ['success' => false, 'message' => $message];
    if (!empty($errors)) $res['errors'] = $errors;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}
// ✅ [تعديل] إضافة $maxBytes لدعم رفع صور تجربة الثوب (افتراضي 64 KB)
function getRequestBody(int $maxBytes = 65536): array {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        jsonError('Request body too large', 413);
    }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function sanitize($value): string {
    return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
}
function fixImageUrl(?string $imageUrl): ?string {
    if (!$imageUrl) return null;
    if (strpos($imageUrl, 'http') === 0) return $imageUrl;
    if (strpos($imageUrl, '/') === 0) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        return "{$protocol}://{$_SERVER['HTTP_HOST']}{$imageUrl}";
    }
    return $imageUrl;
}
