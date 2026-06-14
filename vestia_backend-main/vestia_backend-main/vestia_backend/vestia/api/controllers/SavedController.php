<?php
// ============================================================
// VESTIA API — Saved (Wishlist) Controller
// ============================================================
class SavedController {

    public static function index(): void {
        $user = getAuthUser();
        $db   = getDB();

        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.name_ar, p.name_fr,
                    p.price, p.old_price, p.image_url,
                    COALESCE(AVG(r.rating), 0) AS avg_rating
             FROM saved_items s
             JOIN products p ON p.id = s.product_id
             LEFT JOIN reviews r ON r.product_id = p.id
             WHERE s.user_id = ? AND p.is_active = 1
             GROUP BY p.id, p.name, p.name_ar, p.name_fr,
                      p.price, p.old_price, p.image_url, s.created_at
             ORDER BY s.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $items = $stmt->fetchAll();

        // ✅ استخدام fixImageUrl() بدلاً من تكرار الكود
        $items = array_map(function($item) {
            $item['image_url'] = fixImageUrl($item['image_url']);
            $item['name_ar']   = $item['name_ar'] ?: $item['name'];
            $item['name_fr']   = $item['name_fr'] ?: $item['name'];
            return $item;
        }, $items);

        jsonSuccess(['saved' => $items]);
    }

    public static function toggle(): void {
        $user      = getAuthUser();
        $body      = getRequestBody();
        $productId = (int)($body['product_id'] ?? 0);

        if (!$productId) jsonError('product_id is required', 422);

        $db = getDB();

        $check = $db->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
        $check->execute([$productId]);
        if (!$check->fetch()) jsonError('Product not found', 404);

        $existing = $db->prepare('SELECT id FROM saved_items WHERE user_id = ? AND product_id = ?');
        $existing->execute([$user['id'], $productId]);

        if ($existing->fetch()) {
            $db->prepare('DELETE FROM saved_items WHERE user_id = ? AND product_id = ?')
               ->execute([$user['id'], $productId]);
            jsonSuccess(['saved' => false], 'Removed from saved');
        } else {
            $db->prepare('INSERT INTO saved_items (user_id, product_id) VALUES (?, ?)')
               ->execute([$user['id'], $productId]);
            jsonSuccess(['saved' => true], 'Added to saved');
        }
    }
}
