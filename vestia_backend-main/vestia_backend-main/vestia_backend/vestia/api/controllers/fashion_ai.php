<?php
// ============================================================
// VESTIA — fashion_ai.php
// يتحكم في: 1) تجربة الثوب  2) اقتراح الإطلالة الكاملة
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// ── التحقق من التوكن ──────────────────────────────────────
$headers = getallheaders();
$token   = $headers['Authorization'] ?? '';
$token   = str_replace('Bearer ', '', $token);

if (empty($token)) jsonError('Unauthorized', 401);

$stmt = $pdo->prepare("SELECT user_id FROM auth_tokens WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$auth = $stmt->fetch();
if (!$auth) jsonError('Unauthorized', 401);
$userId = $auth['user_id'];

// ── قراءة الطلب ──────────────────────────────────────────
$action = $_GET['action'] ?? '';

// ============================================================
// ACTION 1: جلب اقتراح الإطلالة (بدون صورة)
// GET /fashion_ai.php?action=suggest&product_id=X
// ============================================================
if ($action === 'suggest' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) jsonError('product_id مطلوب');

    // جلب فئة المنتج الحالي
    $stmt = $pdo->prepare("SELECT category_id FROM products WHERE id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) jsonError('المنتج غير موجود', 404);

    $categoryId = $product['category_id'];

    // جلب الفئات المرتبطة
    $stmt = $pdo->prepare("
        SELECT paired_category_id FROM category_pairs WHERE category_id = ?
    ");
    $stmt->execute([$categoryId]);
    $pairedCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($pairedCategories)) {
        jsonSuccess(['suggestions' => []], 'لا توجد اقتراحات لهذه الفئة');
    }

    // جلب 4 منتجات عشوائية من الفئات المرتبطة
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
    }

    jsonSuccess(['suggestions' => $suggestions]);
}

// ============================================================
// ACTION 2: تجربة ثوب واحد
// POST /fashion_ai.php?action=tryon
// Body: { product_id, user_image_base64 }
// ============================================================
if ($action === 'tryon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body        = getRequestBody();
    $productId   = (int)($body['product_id'] ?? 0);
    $userImage   = $body['user_image_base64'] ?? '';

    if (!$productId || empty($userImage)) {
        jsonError('product_id و user_image_base64 مطلوبان');
    }

    // جلب صورة المنتج
    $stmt = $pdo->prepare("SELECT image_url FROM products WHERE id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) jsonError('المنتج غير موجود', 404);

    $garmentUrl = fixImageUrl($product['image_url']);

    // استدعاء PixelAPI
    $resultUrl = callTryOnAPI($userImage, $garmentUrl);
    if (!$resultUrl) jsonError('فشل في معالجة الصورة، حاول مجدداً', 500);

    jsonSuccess(['result_image_url' => $resultUrl]);
}

// ============================================================
// ACTION 3: تجربة إطلالة كاملة (ثوبان معاً)
// POST /fashion_ai.php?action=outfit
// Body: { product_id, suggested_product_id, user_image_base64 }
// ============================================================
if ($action === 'outfit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body              = getRequestBody();
    $productId         = (int)($body['product_id'] ?? 0);
    $suggestedId       = (int)($body['suggested_product_id'] ?? 0);
    $userImage         = $body['user_image_base64'] ?? '';

    if (!$productId || !$suggestedId || empty($userImage)) {
        jsonError('product_id و suggested_product_id و user_image_base64 مطلوبان');
    }

    // جلب صور المنتجين
    $stmt = $pdo->prepare("
        SELECT id, image_url, category_id FROM products
        WHERE id IN (?, ?) AND is_active = 1
    ");
    $stmt->execute([$productId, $suggestedId]);
    $products = $stmt->fetchAll();
    if (count($products) < 2) jsonError('أحد المنتجين غير موجود', 404);

    // ترتيب: الجزء العلوي أولاً ثم السفلي
    // Tshirts(2), Jackets(5) = upper | Jeans(3), Shoes(4) = lower
    $upperCategories = [2, 5];
    usort($products, function($a, $b) use ($upperCategories) {
        $aIsUpper = in_array($a['category_id'], $upperCategories) ? 0 : 1;
        $bIsUpper = in_array($b['category_id'], $upperCategories) ? 0 : 1;
        return $aIsUpper - $bIsUpper;
    });

    $firstGarment  = fixImageUrl($products[0]['image_url']);
    $secondGarment = fixImageUrl($products[1]['image_url']);

    // الطلب الأول: المستخدم + الثوب الأول
    $intermediateImage = callTryOnAPI($userImage, $firstGarment);
    if (!$intermediateImage) jsonError('فشل في معالجة الثوب الأول', 500);

    // الطلب الثاني: الصورة الوسيطة + الثوب الثاني
    // تحويل URL إلى base64
    $intermediateBase64 = imageUrlToBase64($intermediateImage);
    $finalImage = callTryOnAPI($intermediateBase64, $secondGarment);
    if (!$finalImage) jsonError('فشل في معالجة الثوب الثاني', 500);

    jsonSuccess(['result_image_url' => $finalImage]);
}

jsonError('action غير صالح', 400);

// ============================================================
// HELPER: استدعاء PixelAPI للـ Virtual Try-On
// ============================================================
function callTryOnAPI(string $userImageBase64, string $garmentUrl): ?string {
    $apiKey = getenv('PIXEL_API_KEY'); // متغير بيئة في Render

    $payload = json_encode([
        'model_image' => $userImageBase64,  // base64
        'cloth_image' => $garmentUrl,       // URL
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

// ============================================================
// HELPER: تحويل URL صورة إلى Base64
// ============================================================
function imageUrlToBase64(string $url): string {
    $imageData = file_get_contents($url);
    $mimeType  = 'image/jpeg';
    return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
}
