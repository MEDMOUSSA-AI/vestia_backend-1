<?php
// ============================================================
// VESTIA — FashionAiController.php
// الخدمة 1: تجربة الثوب الافتراضية (tryon)
// الخدمة 2: اقتراح الإطلالة الكاملة (outfit)
// الخدمة 3: جلب اقتراحات الإطلالة (suggest)
// ============================================================

class FashionAiController {

    // ✅ حد أقصى 15 MB لاستيعاب صور Base64 (أكبر بـ 33% من الملف الأصلي)
    private const MAX_IMAGE_BODY = 15 * 1024 * 1024;

    // ──────────────────────────────────────────────────────
    public static function suggest(): void {
        global $pdo;
        getAuthUser(); // ✅ التحقق من التوكن

        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) jsonError('product_id مطلوب');

        $stmt = $pdo->prepare("
            SELECT category_id FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        $stmt = $pdo->prepare("
            SELECT paired_category_id 
            FROM category_pairs 
            WHERE category_id = ?
        ");
        $stmt->execute([$product['category_id']]);
        $pairedCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

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

    // ──────────────────────────────────────────────────────
    public static function tryon(): void {
        global $pdo;
        getAuthUser(); // ✅ التحقق من التوكن

        // ✅ 15 MB لاستيعاب صورة Base64
        $body      = getRequestBody(self::MAX_IMAGE_BODY);
        $productId = (int)($body['product_id'] ?? 0);
        $userImage = $body['user_image_base64'] ?? '';

        if (!$productId || empty($userImage)) {
            jsonError('product_id و user_image_base64 مطلوبان');
        }

        // ✅ التحقق من صيغة Base64
        if (!self::isValidBase64Image($userImage)) {
            jsonError('صيغة الصورة غير صالحة');
        }

        $stmt = $pdo->prepare("
            SELECT image_url FROM products WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) jsonError('المنتج غير موجود', 404);

        $resultUrl = self::callPixelAPI($userImage, fixImageUrl($product['image_url']));
        if (!$resultUrl) jsonError('فشل في معالجة الصورة، حاول مجدداً', 500);

        jsonSuccess(['result_image_url' => $resultUrl]);
    }

    // ──────────────────────────────────────────────────────
    public static function outfit(): void {
        global $pdo;
        getAuthUser(); // ✅ التحقق من التوكن

        // ✅ 15 MB لاستيعاب صورة Base64
        $body        = getRequestBody(self::MAX_IMAGE_BODY);
        $productId   = (int)($body['product_id'] ?? 0);
        $suggestedId = (int)($body['suggested_product_id'] ?? 0);
        $userImage   = $body['user_image_base64'] ?? '';

        if (!$productId || !$suggestedId || empty($userImage)) {
            jsonError('product_id و suggested_product_id و user_image_base64 مطلوبان');
        }

        // ✅ التحقق من صيغة Base64
        if (!self::isValidBase64Image($userImage)) {
            jsonError('صيغة الصورة غير صالحة');
        }

        $stmt = $pdo->prepare("
            SELECT id, image_url, category_id 
            FROM products
            WHERE id IN (?, ?) AND is_active = 1
        ");
        $stmt->execute([$productId, $suggestedId]);
        $products = $stmt->fetchAll();
        if (count($products) < 2) jsonError('أحد المنتجين غير موجود', 404);

        // ✅ الجزء العلوي أولاً: Tshirts(2), Jackets(5)
        $upperCategories = [2, 5];
        usort($products, fn($a, $b) =>
            (in_array($a['category_id'], $upperCategories) ? 0 : 1) -
            (in_array($b['category_id'], $upperCategories) ? 0 : 1)
        );

        // الطلب الأول: المستخدم + الثوب الأول
        $intermediate = self::callPixelAPI(
            $userImage,
            fixImageUrl($products[0]['image_url'])
        );
        if (!$intermediate) jsonError('فشل في معالجة الثوب الأول', 500);

        // الطلب الثاني: الصورة الوسيطة + الثوب الثاني
        $final = self::callPixelAPI(
            self::urlToBase64($intermediate),
            fixImageUrl($products[1]['image_url'])
        );
        if (!$final) jsonError('فشل في معالجة الثوب الثاني', 500);

        jsonSuccess(['result_image_url' => $final]);
    }

    // ══════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════

    private static function callPixelAPI(string $userImageBase64, string $garmentUrl): ?string {
        $apiKey = getenv('PIXEL_API_KEY');
        if (!$apiKey) return null;

        $payload = json_encode([
            'model_image' => $userImageBase64,
            'cloth_image' => $garmentUrl,
            'category'    => 'auto',
        ]);

        $ch = curl_init('https://api.pixelapi.io/v1/try-on');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return null;

        $data = json_decode($response, true);
        return $data['output_url'] ?? null;
    }

    private static function urlToBase64(string $url): string {
        $imageData = @file_get_contents($url);
        if (!$imageData) return '';
        return 'data:image/jpeg;base64,' . base64_encode($imageData);
    }

    // ✅ التحقق من أن الصورة base64 صالحة وبالنوع المسموح
    private static function isValidBase64Image(string $input): bool {
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $input)) {
            return false;
        }
        $base64Part = substr($input, strpos($input, ',') + 1);
        return base64_decode($base64Part, true) !== false;
    }
}
