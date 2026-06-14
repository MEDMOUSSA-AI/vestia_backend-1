<?php
// ============================================================
// ☁️ BACKEND API: ProductController.php (المحدّث)
// ============================================================

class ProductController {

    public static function index(): void {
        $db     = getDB();
        $user   = null;
        try { $user = getAuthUser(); } catch (\Throwable $e) {}
        $userId = $user ? (int)$user['id'] : null;

        // ✅ التحقق من قيم المدخلات
        $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
        $search     = isset($_GET['search']) ? mb_substr(trim($_GET['search']), 0, 100) : null;
        $lang       = $_GET['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar', 'fr'])) $lang = 'en';
        $page       = max(1, (int)($_GET['page']  ?? 1));
        $limit      = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset     = ($page - 1) * $limit;

        $where  = ['p.is_active = 1'];
        $params = [];

        if ($categoryId) {
            $where[]  = 'p.category_id = ?';
            $params[] = $categoryId;
        }

        if ($search) {
            $where[]  = 'p.name ILIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);

        // ✅ إصلاح ثغرة SQL Injection — استخدام parameter بدلاً من إدراج $userId مباشرة
        $savedJoin = $userId
            ? 'LEFT JOIN saved_items sp ON sp.product_id = p.id AND sp.user_id = ?'
            : 'LEFT JOIN saved_items sp ON FALSE';

        if ($userId) $params[] = $userId;

        $sql = "SELECT p.id, p.name, p.name_ar, p.name_fr, 
                       p.description, p.description_ar, p.description_fr,
                       p.price, p.old_price,
                       p.image_url, p.sizes, p.stock_count, p.offer_ends_at, p.created_at,
                       c.id AS category_id, c.name AS category_name,
                       c.name_ar AS category_name_ar, c.name_fr AS category_name_fr,
                       c.slug AS category_slug,
                       COALESCE(AVG(r.rating), 0) AS avg_rating,
                       COUNT(r.id) AS review_count,
                       CASE WHEN sp.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_saved
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN reviews    r ON r.product_id = p.id
                {$savedJoin}
                WHERE {$whereSQL}
                GROUP BY p.id, p.name, p.name_ar, p.name_fr, 
                         p.description, p.description_ar, p.description_fr,
                         p.price, p.old_price, p.image_url, p.sizes, 
                         p.stock_count, p.offer_ends_at, p.created_at,
                         c.id, c.name, c.name_ar, c.name_fr, c.slug,
                         sp.id
                ORDER BY p.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        $countSql  = "SELECT COUNT(*) FROM products p WHERE {$whereSQL}";
        $countParams = array_slice($params, 0, $userId ? count($params) - 1 : count($params));
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        jsonSuccess([
            'products'    => array_map(fn($p) => self::format($p, $lang), $products),
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ]);
    }

    public static function show(string $id): void {
        // ✅ التحقق من أن الـ ID رقم صحيح
        if (!ctype_digit($id)) jsonError('Invalid product ID', 422);

        $db   = getDB();
        $lang = $_GET['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar', 'fr'])) $lang = 'en';

        $user   = null;
        try { $user = getAuthUser(); } catch (\Throwable $e) {}
        $userId = $user ? (int)$user['id'] : null;

        // ✅ إصلاح ثغرة SQL Injection — استخدام parameter بدلاً من إدراج $userId مباشرة
        $savedJoin = $userId
            ? 'LEFT JOIN saved_items sp ON sp.product_id = p.id AND sp.user_id = ?'
            : 'LEFT JOIN saved_items sp ON FALSE';

        $params = $userId ? [$id, $userId] : [$id];

        $sql = "SELECT p.id, p.name, p.name_ar, p.name_fr, 
                       p.description, p.description_ar, p.description_fr,
                       p.price, p.old_price, p.image_url, p.sizes, 
                       p.stock_count, p.offer_ends_at, p.created_at, p.is_active,
                       c.id AS category_id, c.name AS category_name, 
                       c.name_ar AS category_name_ar, c.name_fr AS category_name_fr, 
                       c.slug AS category_slug,
                       COALESCE(AVG(r.rating), 0) AS avg_rating,
                       COUNT(r.id) AS review_count,
                       CASE WHEN sp.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_saved
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN reviews    r ON r.product_id = p.id
                {$savedJoin}
                WHERE p.id = ? AND p.is_active = 1
                GROUP BY p.id, p.name, p.name_ar, p.name_fr, 
                         p.description, p.description_ar, p.description_fr,
                         p.price, p.old_price, p.image_url, p.sizes, 
                         p.stock_count, p.offer_ends_at, p.created_at, p.is_active,
                         c.id, c.name, c.name_ar, c.name_fr, c.slug,
                         sp.id";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $product = $stmt->fetch();

        if (!$product) jsonError('Product not found', 404);

        jsonSuccess(['product' => self::format($product, $lang)]);
    }

    private static function format(array $p, string $lang = 'en'): array {
        $discount = null;
        if ($p['old_price'] && $p['old_price'] > $p['price']) {
            $discount = round((($p['old_price'] - $p['price']) / $p['old_price']) * 100);
        }

        $imageUrl = fixImageUrl($p['image_url']);

        $localizedName = match($lang) {
            'ar'    => $p['name_ar'] ?: $p['name'],
            'fr'    => $p['name_fr'] ?: $p['name'],
            default => $p['name'],
        };

        $localizedDescription = match($lang) {
            'ar'    => $p['description_ar'] ?: $p['description'],
            'fr'    => $p['description_fr'] ?: $p['description'],
            default => $p['description'],
        };

        $localizedCategory = isset($p['category_id']) && $p['category_id'] ? [
            'id'   => (int)$p['category_id'],
            'name' => match($lang) {
                'ar'    => $p['category_name_ar'] ?: $p['category_name'],
                'fr'    => $p['category_name_fr'] ?: $p['category_name'],
                default => $p['category_name'],
            },
            'slug' => $p['category_slug'],
        ] : null;

        return [
            'id'               => (int)$p['id'],
            'name'             => $localizedName,
            'description'      => $localizedDescription,
            'description_ar'   => $p['description_ar'],
            'description_fr'   => $p['description_fr'],
            'price'            => (float)$p['price'],
            'old_price'        => $p['old_price'] ? (float)$p['old_price'] : null,
            'discount_percent' => $discount,
            'image_url'        => $imageUrl,
            'sizes'            => self::parseSizes($p['sizes'] ?? 'S,M,L,XL,XXL'),
            'stock_count'      => (int)($p['stock_count'] ?? 0),
            'offer_ends_at'    => $p['offer_ends_at'],
            'category'         => $localizedCategory,
            'avg_rating'       => round((float)($p['avg_rating'] ?? 0), 1),
            'review_count'     => (int)($p['review_count'] ?? 0),
            'is_saved'         => (bool)($p['is_saved'] ?? false),
            'created_at'       => $p['created_at'],
        ];
    }

    private static function parseSizes(string $sizes): array {
        return array_filter(
            array_map('trim', explode(',', $sizes)),
            fn($s) => !empty($s)
        );
    }

    public static function search(): void {
        $db = getDB();
        $q    = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
        $lang = $_GET['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar', 'fr'])) $lang = 'en';

        if (mb_strlen($q) < 2) {
            jsonError('Query too short', 400);
        }

        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.name_ar, p.name_fr, p.price, p.image_url, p.stock_count
             FROM products p
             WHERE p.is_active = 1 AND (p.name ILIKE ? OR p.name_ar ILIKE ? OR p.name_fr ILIKE ?)
             LIMIT 10"
        );
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        $results = $stmt->fetchAll();

        jsonSuccess([
            'results' => array_map(fn($p) => [
                'id'    => (int)$p['id'],
                'name'  => match($lang) {
                    'ar'    => $p['name_ar'] ?: $p['name'],
                    'fr'    => $p['name_fr'] ?: $p['name'],
                    default => $p['name'],
                },
                'price' => (float)$p['price'],
                'image' => fixImageUrl($p['image_url']),
            ], $results),
        ]);
    }
}
