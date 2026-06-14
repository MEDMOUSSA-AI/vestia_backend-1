<?php
// ============================================================
// VESTIA API — Review Controller
// ============================================================
class ReviewController {

    public static function index(string $productId): void {
        // ✅ التحقق من أن الـ ID رقم صحيح
        if (!ctype_digit($productId)) jsonError('Invalid product ID', 422);

        $db     = getDB();
        $page   = max(1, (int)($_GET['page']  ?? 1));
        $limit  = min(20, (int)($_GET['limit'] ?? 10));
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare(
            "SELECT r.id, r.rating, r.text, r.created_at,
                    u.name AS reviewer_name
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ?
             ORDER BY r.created_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$productId]);
        $reviews = $stmt->fetchAll();

        $stats = $db->prepare(
            'SELECT COALESCE(AVG(rating)::numeric, 0) AS avg, COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN rating=5 THEN 1 ELSE 0 END), 0) AS s5,
                    COALESCE(SUM(CASE WHEN rating=4 THEN 1 ELSE 0 END), 0) AS s4,
                    COALESCE(SUM(CASE WHEN rating=3 THEN 1 ELSE 0 END), 0) AS s3,
                    COALESCE(SUM(CASE WHEN rating=2 THEN 1 ELSE 0 END), 0) AS s2,
                    COALESCE(SUM(CASE WHEN rating=1 THEN 1 ELSE 0 END), 0) AS s1
             FROM reviews WHERE product_id = ?'
        );
        $stats->execute([$productId]);
        $s = $stats->fetch();

        jsonSuccess([
            'reviews'      => $reviews,
            'avg_rating'   => round((float)$s['avg'], 1),
            'total'        => (int)$s['total'],
            'distribution' => [5=>(int)$s['s5'],4=>(int)$s['s4'],3=>(int)$s['s3'],2=>(int)$s['s2'],1=>(int)$s['s1']],
        ]);
    }

    public static function store(string $productId): void {
        // ✅ التحقق من أن الـ ID رقم صحيح
        if (!ctype_digit($productId)) jsonError('Invalid product ID', 422);

        $user    = getAuthUser();
        $body    = getRequestBody();
        $rating  = (int)($body['rating'] ?? 0);

        // ✅ تحديد حد أقصى لطول نص التقييم
        $text    = mb_substr(sanitize($body['text'] ?? ''), 0, 1000);

        $orderId = isset($body['order_id']) ? (int)$body['order_id'] : null;

        if ($rating < 1 || $rating > 5) jsonError('Rating must be between 1 and 5', 422);

        $db = getDB();

        $check = $db->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
        $check->execute([$productId]);
        if (!$check->fetch()) jsonError('Product not found', 404);

        $dup = $db->prepare('SELECT id FROM reviews WHERE user_id = ? AND product_id = ?');
        $dup->execute([$user['id'], $productId]);

        if ($dup->fetch()) {
            $db->prepare('UPDATE reviews SET rating=?, text=?, order_id=? WHERE user_id=? AND product_id=?')
               ->execute([$rating, $text, $orderId, $user['id'], $productId]);
            jsonSuccess([], 'Review updated');
        }

        $db->prepare(
            'INSERT INTO reviews (user_id, product_id, order_id, rating, text) VALUES (?,?,?,?,?)'
        )->execute([$user['id'], $productId, $orderId, $rating, $text]);

        jsonSuccess([], 'Review submitted', 201);
    }
}
