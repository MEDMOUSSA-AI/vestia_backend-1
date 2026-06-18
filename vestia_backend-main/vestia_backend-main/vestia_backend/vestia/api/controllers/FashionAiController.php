<?php
// ============================================================
// VESTIA — FashionAiController.php (v4 — FASHN.ai)
// ✅ FASHN.ai API (async job-based)
// ✅ نظام التجارب: 2 مجاناً + 3 بعد كل شراء
// ✅ Cloudinary لرفع صورة المستخدم (JSON upload)
// ✅ لا تدعم Shoes — تُرجع رسالة واضحة
// ============================================================

class FashionAiController {

    // ─── FASHN.ai endpoints ──────────────────────────────────
    private const FASHN_BASE_URL   = 'https://api.fashn.ai/v1';
    private const FASHN_RUN_URL    = self::FASHN_BASE_URL . '/run';
    private const FASHN_STATUS_URL = self::FASHN_BASE_URL . '/status/';

    // ─── Cloudinary ──────────────────────────────────────────
    private const CLOUDINARY_UPLOAD_URL = 'https://api.cloudinary.com/v1_1/%s/image/upload';

    // ─── حدود الحجم ──────────────────────────────────────────
    private const MAX_IMAGE_BODY = 15 * 1024 * 1024; // 15 MB

    // ─── Polling ─────────────────────────────────────────────
    private const POLL_INTERVAL = 4;  // ثوانٍ
    private const POLL_MAX      = 20; // أقصاه 80 ثانية

    // ─── فئات الملابس ────────────────────────────────────────
    private const CATEGORY_MAP = [
        2 => 'tops',     // Tshirts
        3 => 'bottoms',  // Jeans
        5 => 'tops',     // Jackets
    ];
    private const UNSUPPORTED_CATEGORIES = [1, 4]; // All, Shoes

    // ─── نظام التجارب ────────────────────────────────────────
    private const FREE_CREDITS         = 2;
    private const CREDITS_PER_PURCHASE = 3;

    // ══════════════════════════════════════════════════════════
    // SUGGEST
    // ══════════════════════════════════════════════════════════
    public static function suggest(): void {
        global $pdo;
        getAuthUser();

        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) jsonError('product_id مطلوب');

        $stmt = $pdo->prepare("
            SELECT category_id FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        $pairedCategories = self::getPairedCategories($pdo, $product['category_id']);
        $pairedCategories = array_values(array_filter(
            $pairedCategories,
            fn($id) => !in_array($id, self::UNSUPPORTED_CATEGORIES)
        ));

        if (empty($pairedCategories)) {
            jsonSuccess(['suggestions' => []], 'لا توجد اقتراحات لهذه الفئة');
        }

        $placeholders = implode(',', array_fill(0, count($pairedCategories), '?'));
        $stmt = $pdo->prepare("
            SELECT id, name, name_ar, name_fr, price, image_url, category_id
            FROM products
            WHERE category_id IN ($placeholders)
              AND is_active = 1
              AND id != ?
            ORDER BY RANDOM()
            LIMIT 4
        ");
        $stmt->execute([...$pairedCategories, $productId]);
        $suggestions = $stmt->fetchAll();

        foreach ($suggestions as &$s) {
            $s['image_url'] = fixImageUrl($s['image_url']);
            $s['price']     = (float)$s['price'];
        }

        jsonSuccess(['suggestions' => $suggestions]);
    }

    // ══════════════════════════════════════════════════════════
    // TRYON — تجربة ثوب واحد
    // ══════════════════════════════════════════════════════════
    public static function tryon(): void {
        global $pdo;
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

        $stmt = $pdo->prepare("
            SELECT image_url, category_id FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        if (in_array($product['category_id'], self::UNSUPPORTED_CATEGORIES)) {
            jsonError('التجربة الافتراضية غير متاحة لهذه الفئة حالياً', 422);
        }

        // ✅ خصم كريديت قبل الإرسال
        self::checkAndDeductCredit($pdo, $user['id'], $productId);

        $personUrl = self::uploadToCloudinary($userImage);
        if (!$personUrl) {
            self::refundCredit($pdo, $user['id'], $productId);
            jsonError('تعذّر رفع الصورة، حاول مجدداً', 500);
        }

        $garmentUrl = fixImageUrl($product['image_url']);
        $category   = self::CATEGORY_MAP[$product['category_id']] ?? 'tops';

        $resultUrl = self::submitAndPoll($personUrl, $garmentUrl, $category);
        if (!$resultUrl) {
            self::refundCredit($pdo, $user['id'], $productId);
            jsonError('فشل في معالجة الصورة، حاول مجدداً', 500);
        }

        self::updateTryonLog($pdo, $user['id'], $productId, $resultUrl);

        jsonSuccess(['result_image_url' => $resultUrl]);
    }

    // ══════════════════════════════════════════════════════════
    // OUTFIT — إطلالة كاملة
    // ══════════════════════════════════════════════════════════
    public static function outfit(): void {
        global $pdo;
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

        $stmt = $pdo->prepare("
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

        self::checkAndDeductCredit($pdo, $user['id'], $productId);

        // ✅ tops أولاً
        usort($products, fn($a, $b) =>
            (self::CATEGORY_MAP[$a['category_id']] === 'tops' ? 0 : 1) -
            (self::CATEGORY_MAP[$b['category_id']] === 'tops' ? 0 : 1)
        );

        $personUrl = self::uploadToCloudinary($userImage);
        if (!$personUrl) {
            self::refundCredit($pdo, $user['id'], $productId);
            jsonError('تعذّر رفع الصورة، حاول مجدداً', 500);
        }

        $cat1         = self::CATEGORY_MAP[$products[0]['category_id']] ?? 'tops';
        $intermediate = self::submitAndPoll(
            $personUrl,
            fixImageUrl($products[0]['image_url']),
            $cat1
        );
        if (!$intermediate) {
            self::refundCredit($pdo, $user['id'], $productId);
            jsonError('فشل في معالجة الثوب الأول', 500);
        }

        $cat2  = self::CATEGORY_MAP[$products[1]['category_id']] ?? 'bottoms';
        $final = self::submitAndPoll(
            $intermediate,
            fixImageUrl($products[1]['image_url']),
            $cat2
        );
        if (!$final) {
            self::refundCredit($pdo, $user['id'], $productId);
            jsonError('فشل في معالجة الثوب الثاني', 500);
        }

        self::updateTryonLog($pdo, $user['id'], $productId, $final);

        jsonSuccess(['result_image_url' => $final]);
    }

    // ══════════════════════════════════════════════════════════
    // GET CREDITS
    // ══════════════════════════════════════════════════════════
    public static function getCredits(): void {
        global $pdo;
        $user = getAuthUser();

        $stmt = $pdo->prepare("
            SELECT tryon_credits, tryon_total_used FROM users WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        $data = $stmt->fetch();

        jsonSuccess([
            'credits_remaining' => (int)($data['tryon_credits']   ?? 0),
            'total_used'        => (int)($data['tryon_total_used'] ?? 0),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // ADD CREDITS AFTER PURCHASE — يُستدعى من OrderController
    // ══════════════════════════════════════════════════════════
    public static function addCreditsAfterPurchase(int $userId): void {
        global $pdo;
        $pdo->prepare("
            UPDATE users
            SET tryon_credits  = tryon_credits + ?,
                tryon_reset_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([self::CREDITS_PER_PURCHASE, $userId]);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════

    private static function checkAndDeductCredit(PDO $pdo, int $userId, int $productId): void {
        $stmt = $pdo->prepare("
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

        $pdo->prepare("
            INSERT INTO tryon_logs (user_id, product_id, credits_before, credits_after)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $userId,
            $productId,
            $result['tryon_credits'] + 1,
            $result['tryon_credits'],
        ]);
    }

    private static function refundCredit(PDO $pdo, int $userId, int $productId): void {
        $pdo->prepare("
            UPDATE users
            SET tryon_credits    = tryon_credits + 1,
                tryon_total_used = GREATEST(tryon_total_used - 1, 0)
            WHERE id = ?
        ")->execute([$userId]);

        $pdo->prepare("
            UPDATE tryon_logs SET result_url = 'REFUNDED'
            WHERE user_id = ? AND product_id = ? AND result_url IS NULL
            ORDER BY created_at DESC LIMIT 1
        ")->execute([$userId, $productId]);
    }

    private static function updateTryonLog(
        PDO $pdo, int $userId, int $productId, string $url
    ): void {
        $pdo->prepare("
            UPDATE tryon_logs SET result_url = ?
            WHERE user_id = ? AND product_id = ? AND result_url IS NULL
            ORDER BY created_at DESC LIMIT 1
        ")->execute([$url, $userId, $productId]);
    }

    private static function submitAndPoll(
        string $personUrl,
        string $garmentUrl,
        string $category
    ): ?string {
        $apiKey = getenv('FASHN_API_KEY');
        if (!$apiKey) return null;

        $payload = json_encode([
            'model_image'   => $personUrl,
            'garment_image' => $garmentUrl,
            'category'      => $category,
            'mode'          => 'balanced',
        ]);

        // ─── إرسال الطلب ────────────────────────────────────
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

        if ($httpCode !== 200) return null;

        $data   = json_decode($response, true);
        $predId = $data['id'] ?? null;
        if (!$predId) return null;

        // ─── Polling ─────────────────────────────────────────
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

            if ($status === 'completed') return $data['output'][0] ?? null;
            if ($status === 'failed')    return null;
        }

        return null;
    }

    // ✅ التعديل الرئيسي: رفع base64 عبر JSON بدلاً من multipart
    private static function uploadToCloudinary(string $base64Image): ?string {
        $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $apiKey    = getenv('CLOUDINARY_API_KEY');
        $apiSecret = getenv('CLOUDINARY_API_SECRET');

        if (!$cloudName || !$apiKey || !$apiSecret) return null;

        $timestamp = time();
        $paramsToSign = "folder=vestia_tryon&timestamp={$timestamp}";
        $signature = sha1($paramsToSign . $apiSecret);

        $payload = json_encode([
            'file'      => $base64Image,   // data:image/jpeg;base64,....
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => 'vestia_tryon',
        ]);

        $ch = curl_init(sprintf(self::CLOUDINARY_UPLOAD_URL, $cloudName));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,   // رفع الصور قد يستغرق أطول
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return null;

        $data = json_decode($response, true);
        return $data['secure_url'] ?? null;
    }

    private static function getPairedCategories(PDO $pdo, int $categoryId): array {
        $stmt = $pdo->prepare("
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
