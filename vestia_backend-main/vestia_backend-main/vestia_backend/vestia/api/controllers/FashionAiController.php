<?php
// ============================================================
// VESTIA — FashionAiController.php
// ============================================================

class FashionAiController {

    private const FASHN_BASE_URL   = 'https://api.fashn.ai/v1';
    private const FASHN_RUN_URL    = self::FASHN_BASE_URL . '/run';
    private const FASHN_STATUS_URL = self::FASHN_BASE_URL . '/status/';
    private const FASHN_MODEL_NAME = 'tryon-v1.6';

    private const CLOUDINARY_UPLOAD_URL = 'https://api.cloudinary.com/v1_1/%s/image/upload';

    private const MAX_IMAGE_BODY = 15 * 1024 * 1024;
    private const POLL_INTERVAL  = 4;
    private const POLL_MAX       = 20;

    private const CATEGORY_MAP = [
        2 => 'tops',      // Tshirts
        3 => 'bottoms',   // Jeans
        5 => 'tops',      // Jackets
        6 => 'tops',      // Dresses
    ];
    private const UNSUPPORTED_CATEGORIES = [1, 4]; // All, Shoes

    private const FREE_CREDITS         = 2;
    private const CREDITS_PER_PURCHASE = 3;

    // ══════════════════════════════════════════════════════════
    // INIT CREDITS — منح 2 محاولة عند إنشاء الحساب
    // استدعِها في register.php بعد INSERT للمستخدم الجديد:
    //   FashionAiController::initCredits($userId);
    // ══════════════════════════════════════════════════════════
    public static function initCredits(int $userId): void {
        $db = getDB();
        $db->prepare("
            UPDATE users
            SET tryon_credits    = ?,
                tryon_total_used = 0
            WHERE id = ? AND tryon_credits IS NULL
        ")->execute([self::FREE_CREDITS, $userId]);

        // إذا كان العمود NULL قبل التحديث لم تؤثر الاستعلام — نتأكد بـ fallback
        $db->prepare("
            UPDATE users
            SET tryon_credits    = COALESCE(tryon_credits, ?),
                tryon_total_used = COALESCE(tryon_total_used, 0)
            WHERE id = ?
        ")->execute([self::FREE_CREDITS, $userId]);
    }

    // ══════════════════════════════════════════════════════════
    // SUGGEST — أولوية: Admin Pairings → AI بالألوان
    // GET /fashion/suggest?product_id=X
    // ══════════════════════════════════════════════════════════
    public static function suggest(): void {
        $db = getDB();
        getAuthUser();

        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) jsonError('product_id مطلوب');

        $stmt = $db->prepare("
            SELECT category_id, color FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        // 1) ابحث في التطابقات اليدوية من الأدمن
        $stmt = $db->prepare("
            SELECT p.id, p.name, p.name_ar, p.name_fr,
                   p.price, p.image_url, p.category_id
            FROM product_pairings pp
            JOIN products p ON p.id = pp.paired_id
            WHERE pp.product_id = ?
              AND pp.is_active  = true
              AND p.is_active   = 1
            ORDER BY pp.score DESC
            LIMIT 6
        ");
        $stmt->execute([$productId]);
        $suggestions = $stmt->fetchAll();

        // 2) إن لم توجد — AI بناءً على اللون والفئة
        if (empty($suggestions)) {
            $suggestions = self::getAiSuggestions($db, $productId, $product);
        }

        foreach ($suggestions as &$s) {
            $s['image_url'] = fixImageUrl($s['image_url']);
            $s['price']     = (float)$s['price'];
        }
        unset($s);

        jsonSuccess(['suggestions' => array_values($suggestions)]);
    }

    // ══════════════════════════════════════════════════════════
    // TRYON — ثوب واحد
    // POST /fashion/tryon
    // ══════════════════════════════════════════════════════════
    public static function tryon(): void {
        $db   = getDB();
        $user = getAuthUser();

        $body      = getRequestBody(self::MAX_IMAGE_BODY);
        $productId = (int)($body['product_id'] ?? 0);
        $userImage = $body['user_image_base64'] ?? '';

        if (!$productId || empty($userImage)) {
            jsonError('product_id و user_image_base64 مطلوبان');
        }

        if (!self::isValidBase64Image($userImage)) {
            jsonError('صيغة الصورة غير صالحة');
        }

        $stmt = $db->prepare("
            SELECT image_url, category_id FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        if (in_array($product['category_id'], self::UNSUPPORTED_CATEGORIES)) {
            jsonError('التجربة الافتراضية غير متاحة لهذه الفئة حالياً', 422);
        }

        self::checkAndDeductCredit($db, $user['id'], $productId);

        $personUrl = self::uploadToCloudinary($userImage);
        if (!$personUrl) {
            self::refundCredit($db, $user['id'], $productId);
            jsonError('تعذّر رفع الصورة، حاول مجدداً', 500);
        }

        $garmentUrl = fixImageUrl($product['image_url']);
        $category   = self::CATEGORY_MAP[$product['category_id']] ?? 'tops';

        $errorInfo = null;
        $resultUrl = self::submitAndPoll($personUrl, $garmentUrl, $category, $errorInfo);
        if (!$resultUrl) {
            self::refundCredit($db, $user['id'], $productId);
            self::handleTryonFailure($errorInfo);
        }

        self::updateTryonLog($db, $user['id'], $productId, $resultUrl);

        jsonSuccess(['result_image_url' => $resultUrl]);
    }

    // ══════════════════════════════════════════════════════════
    // OUTFIT — ثوبان معاً
    // POST /fashion/outfit
    // ══════════════════════════════════════════════════════════
    public static function outfit(): void {
        $db   = getDB();
        $user = getAuthUser();

        $body        = getRequestBody(self::MAX_IMAGE_BODY);
        $productId   = (int)($body['product_id'] ?? 0);
        $suggestedId = (int)($body['suggested_product_id'] ?? 0);
        $userImage   = $body['user_image_base64'] ?? '';

        if (!$productId || !$suggestedId || empty($userImage)) {
            jsonError('product_id و suggested_product_id و user_image_base64 مطلوبان');
        }

        if (!self::isValidBase64Image($userImage)) {
            jsonError('صيغة الصورة غير صالحة');
        }

        $stmt = $db->prepare("
            SELECT id, image_url, category_id FROM products
            WHERE id IN (?, ?) AND is_active = 1
        ");
        $stmt->execute([$productId, $suggestedId]);
        $products = $stmt->fetchAll();
        if (count($products) < 2) jsonError('أحد المنتجين غير موجود', 404);

        foreach ($products as $p) {
            if (in_array($p['category_id'], self::UNSUPPORTED_CATEGORIES)) {
                jsonError('التجربة الافتراضية غير متاحة لأحد المنتجين', 422);
            }
        }

        self::checkAndDeductCredit($db, $user['id'], $productId);

        // tops أولاً ثم bottoms
        usort($products, fn($a, $b) =>
            (self::CATEGORY_MAP[$a['category_id']] === 'tops' ? 0 : 1) -
            (self::CATEGORY_MAP[$b['category_id']] === 'tops' ? 0 : 1)
        );

        $personUrl = self::uploadToCloudinary($userImage);
        if (!$personUrl) {
            self::refundCredit($db, $user['id'], $productId);
            jsonError('تعذّر رفع الصورة، حاول مجدداً', 500);
        }

        $cat1         = self::CATEGORY_MAP[$products[0]['category_id']] ?? 'tops';
        $errorInfo    = null;
        $intermediate = self::submitAndPoll(
            $personUrl,
            fixImageUrl($products[0]['image_url']),
            $cat1,
            $errorInfo
        );
        if (!$intermediate) {
            self::refundCredit($db, $user['id'], $productId);
            self::handleTryonFailure($errorInfo);
        }

        $cat2      = self::CATEGORY_MAP[$products[1]['category_id']] ?? 'bottoms';
        $errorInfo = null;
        $final     = self::submitAndPoll(
            $intermediate,
            fixImageUrl($products[1]['image_url']),
            $cat2,
            $errorInfo
        );
        if (!$final) {
            self::refundCredit($db, $user['id'], $productId);
            self::handleTryonFailure($errorInfo);
        }

        self::updateTryonLog($db, $user['id'], $productId, $final);

        jsonSuccess(['result_image_url' => $final]);
    }

    // ══════════════════════════════════════════════════════════
    // GET CREDITS
    // GET /fashion/credits
    // ✅ مدمج من tryon_credits.php — key مصحَّح إلى `remaining`
    //    ليتطابق مع ما يتوقعه Flutter: data['remaining']
    // ══════════════════════════════════════════════════════════
    public static function getCredits(): void {
        $db   = getDB();
        $user = getAuthUser();

        $stmt = $db->prepare("
            SELECT tryon_credits, tryon_total_used FROM users WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        $data = $stmt->fetch();

        // إذا كان الحساب قديماً ولم يُعطَ رصيد بعد → امنحه افتراضياً
        if ($data && $data['tryon_credits'] === null) {
            self::initCredits($user['id']);
            $data['tryon_credits']   = self::FREE_CREDITS;
            $data['tryon_total_used'] = 0;
        }

        jsonSuccess([
            'remaining'  => (int)($data['tryon_credits']    ?? 0),
            'total_used' => (int)($data['tryon_total_used']  ?? 0),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // ADD CREDITS AFTER PURCHASE — +3 بعد كل عملية شراء مكتملة
    // استدعِها من orders.php عند تحديث الحالة إلى Completed:
    //   FashionAiController::addCreditsAfterPurchase($userId);
    // ══════════════════════════════════════════════════════════
    public static function addCreditsAfterPurchase(int $userId): void {
        $db = getDB();
        $db->prepare("
            UPDATE users
            SET tryon_credits  = tryon_credits + ?,
                tryon_reset_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([self::CREDITS_PER_PURCHASE, $userId]);
    }

    // ══════════════════════════════════════════════════════════
    // ADD PAIRING — Admin يربط منتجَين يدوياً
    // POST /admin/fashion/pairings
    // ══════════════════════════════════════════════════════════
    public static function addPairing(): void {
        $db   = getDB();
        $body = getRequestBody();

        $productId = (int)($body['product_id'] ?? 0);
        $pairedId  = (int)($body['paired_id']  ?? 0);
        $score     = min(1.0, max(0.0, (float)($body['score'] ?? 0.9)));

        if (!$productId || !$pairedId) jsonError('product_id و paired_id مطلوبان');
        if ($productId === $pairedId)  jsonError('لا يمكن ربط المنتج بنفسه');

        $db->prepare("
            INSERT INTO product_pairings (product_id, paired_id, score, source)
            VALUES (?, ?, ?, 'admin')
            ON CONFLICT (product_id, paired_id)
            DO UPDATE SET score = EXCLUDED.score, is_active = true
        ")->execute([$productId, $pairedId, $score]);

        // ربط عكسي تلقائي
        $db->prepare("
            INSERT INTO product_pairings (product_id, paired_id, score, source)
            VALUES (?, ?, ?, 'admin')
            ON CONFLICT (product_id, paired_id)
            DO UPDATE SET score = EXCLUDED.score, is_active = true
        ")->execute([$pairedId, $productId, round($score * 0.9, 2)]);

        jsonSuccess(['message' => 'تم ربط المنتجَين بنجاح']);
    }

    // ══════════════════════════════════════════════════════════
    // REMOVE PAIRING — Admin يلغي ربط منتجَين
    // DELETE /admin/fashion/pairings
    // ══════════════════════════════════════════════════════════
    public static function removePairing(): void {
        $db   = getDB();
        $body = getRequestBody();

        $productId = (int)($body['product_id'] ?? 0);
        $pairedId  = (int)($body['paired_id']  ?? 0);

        if (!$productId || !$pairedId) jsonError('product_id و paired_id مطلوبان');

        $db->prepare("
            UPDATE product_pairings SET is_active = false
            WHERE (product_id = ? AND paired_id = ?)
               OR (product_id = ? AND paired_id = ?)
        ")->execute([$productId, $pairedId, $pairedId, $productId]);

        jsonSuccess(['message' => 'تم إلغاء الربط']);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — AI اقتراح بناءً على اللون والفئة
    // ══════════════════════════════════════════════════════════
    private static function getAiSuggestions(PDO $db, int $productId, array $product): array {
        $pairedCategories = self::getPairedCategories($db, $product['category_id']);
        $pairedCategories = array_values(array_filter(
            $pairedCategories,
            fn($id) => !in_array($id, self::UNSUPPORTED_CATEGORIES)
        ));

        if (empty($pairedCategories)) return [];

        $placeholders = implode(',', array_fill(0, count($pairedCategories), '?'));
        $color = $product['color'] ?? '';

        $stmt = $db->prepare("
            SELECT p.id, p.name, p.name_ar, p.name_fr,
                   p.price, p.image_url, p.category_id,
                   CASE
                       WHEN ? != '' AND EXISTS (
                           SELECT 1 FROM color_rules cr
                           WHERE cr.base_color = ?
                             AND cr.complementary_color = p.color
                       ) THEN 1.0
                       WHEN p.color IS NOT NULL AND p.color != ? THEN 0.5
                       ELSE 0.2
                   END AS score
            FROM products p
            WHERE p.category_id IN ($placeholders)
              AND p.is_active = 1
              AND p.id != ?
            ORDER BY score DESC, RANDOM()
            LIMIT 4
        ");

        $params = array_merge([$color, $color, $color], $pairedCategories, [$productId]);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════

    private static function checkAndDeductCredit(PDO $db, int $userId, int $productId): void {
        $stmt = $db->prepare("
            UPDATE users
            SET tryon_credits    = tryon_credits - 1,
                tryon_total_used = tryon_total_used + 1
            WHERE id = ? AND tryon_credits > 0
            RETURNING tryon_credits, tryon_total_used
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();

        if (!$result) {
            jsonError('انتهت تجاربك المجانية، اشترِ منتجاً للحصول على 3 تجارب جديدة', 403);
        }

        $db->prepare("
            INSERT INTO tryon_logs (user_id, product_id, credits_before, credits_after)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $userId,
            $productId,
            $result['tryon_credits'] + 1,
            $result['tryon_credits'],
        ]);
    }

    private static function refundCredit(PDO $db, int $userId, int $productId): void {
        $db->prepare("
            UPDATE users
            SET tryon_credits    = tryon_credits + 1,
                tryon_total_used = GREATEST(tryon_total_used - 1, 0)
            WHERE id = ?
        ")->execute([$userId]);

        $db->prepare("
            UPDATE tryon_logs SET result_url = 'REFUNDED'
            WHERE id = (
                SELECT id FROM tryon_logs
                WHERE user_id = ? AND product_id = ? AND result_url IS NULL
                ORDER BY created_at DESC LIMIT 1
            )
        ")->execute([$userId, $productId]);
    }

    private static function updateTryonLog(PDO $db, int $userId, int $productId, string $url): void {
        $db->prepare("
            UPDATE tryon_logs SET result_url = ?
            WHERE id = (
                SELECT id FROM tryon_logs
                WHERE user_id = ? AND product_id = ? AND result_url IS NULL
                ORDER BY created_at DESC LIMIT 1
            )
        ")->execute([$url, $userId, $productId]);
    }

    private static function handleTryonFailure(?array $errorInfo): void {
        $name = $errorInfo['name'] ?? '';

        if ($name === 'PoseError') {
            jsonError(
                'تعذّر التعرف على وضعية جسمك في الصورة، يرجى استخدام صورة واضحة كاملة الطول وأنت واقف بشكل مستقيم مع إضاءة جيدة',
                422
            );
        }

        jsonError('فشل في معالجة الصورة، حاول مجدداً', 500);
    }

    private static function submitAndPoll(
        string $personUrl,
        string $garmentUrl,
        string $category,
        ?array &$errorOut = null
    ): ?string {
        $apiKey = getenv('FASHN_API_KEY');
        if (!$apiKey) {
            error_log('[VESTIA:FASHN] FASHN_API_KEY MISSING');
            return null;
        }

        $payload = json_encode([
            'model_name' => self::FASHN_MODEL_NAME,
            'inputs'     => [
                'model_image'   => $personUrl,
                'garment_image' => $garmentUrl,
                'category'      => $category,
                'mode'          => 'balanced',
            ],
        ]);

        $ch = curl_init(self::FASHN_RUN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log('[VESTIA:FASHN:run] HTTP=' . $httpCode . ' response=' . substr($response, 0, 400));

        if ($httpCode !== 200) return null;

        $data   = json_decode($response, true);
        $predId = $data['id'] ?? null;
        if (!$predId) return null;

        for ($i = 0; $i < self::POLL_MAX; $i++) {
            sleep(self::POLL_INTERVAL);

            $ch = curl_init(self::FASHN_STATUS_URL . $predId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) continue;

            $data   = json_decode($response, true);
            $status = $data['status'] ?? '';

            error_log('[VESTIA:FASHN:poll] i=' . $i . ' status=' . $status . ' response=' . substr($response, 0, 300));

            if ($status === 'completed') return $data['output'][0] ?? null;
            if ($status === 'failed') {
                $errorOut = $data['error'] ?? null;
                return null;
            }
        }

        return null;
    }

    private static function uploadToCloudinary(string $base64Image): ?string {
        $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $apiKey    = getenv('CLOUDINARY_API_KEY');
        $apiSecret = getenv('CLOUDINARY_API_SECRET');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            error_log('[VESTIA:Cloudinary] MISSING ENV — cloud=' . ($cloudName ? 'ok' : 'MISSING') . ' key=' . ($apiKey ? 'ok' : 'MISSING') . ' secret=' . ($apiSecret ? 'ok' : 'MISSING'));
            return null;
        }

        $timestamp    = time();
        $paramsToSign = "folder=vestia_tryon&timestamp={$timestamp}";
        $signature    = sha1($paramsToSign . $apiSecret);

        $payload = [
            'file'      => $base64Image,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => 'vestia_tryon',
        ];

        $ch = curl_init(sprintf(self::CLOUDINARY_UPLOAD_URL, $cloudName));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log('[VESTIA:Cloudinary] HTTP=' . $httpCode . ' response=' . substr($response, 0, 300));

        if ($httpCode !== 200) return null;

        $data = json_decode($response, true);
        return $data['secure_url'] ?? null;
    }

    private static function getPairedCategories(PDO $db, int $categoryId): array {
        $stmt = $db->prepare("
            SELECT paired_category_id FROM category_pairs WHERE category_id = ?
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function isValidBase64Image(string $input): bool {
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $input)) {
            return false;
        }
        $base64Part = substr($input, strpos($input, ',') + 1);
        return base64_decode($base64Part, true) !== false;
    }
}
