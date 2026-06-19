<?php
session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();
$db = db();

function formatDatetimeForInput(?string $datetime): string {
    if (!$datetime) return '';
    return substr(str_replace(' ', 'T', $datetime), 0, 16);
}

function getTimeRemaining(?string $offerEndsAt): array {
    if (!$offerEndsAt) {
        return ['display' => 'لا يوجد عرض', 'remaining_hours' => 0, 'class' => 'text-muted'];
    }
    $endTime = new DateTime($offerEndsAt);
    $now     = new DateTime();
    if ($now >= $endTime) {
        return ['display' => '❌ انتهى العرض', 'remaining_hours' => 0, 'class' => 'text-danger'];
    }
    $interval        = $now->diff($endTime);
    $remaining_hours = ($interval->d * 24) + $interval->h;
    if ($remaining_hours > 24) {
        $days    = intdiv($remaining_hours, 24);
        $display = "⏳ $days أيام المتبقي";
        $class   = 'text-warning';
    } else {
        $display = "⏰ $remaining_hours ساعات المتبقي";
        $class   = $remaining_hours < 6 ? 'text-danger' : 'text-warning';
    }
    return ['display' => $display, 'remaining_hours' => $remaining_hours, 'class' => $class];
}

// ✅ من متغيرات البيئة فقط — لا تضع البيانات في الكود
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME'));
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY'));
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET'));
define('CLOUDINARY_FOLDER',     'vestia/products');

function uploadToCloudinary(string $fileTmpPath, string $originalName): string|false {
    $timestamp    = time();
    $folder       = CLOUDINARY_FOLDER;
    $publicId     = $folder . '/' . pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid();
    $paramsToSign = "folder={$folder}&public_id={$publicId}&timestamp={$timestamp}";
    $signature    = sha1($paramsToSign . CLOUDINARY_API_SECRET);
    $uploadUrl    = 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/image/upload';
    $postFields   = [
        'file'      => new CURLFile($fileTmpPath),
        'api_key'   => CLOUDINARY_API_KEY,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'folder'    => $folder,
        'public_id' => $publicId,
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $uploadUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return false;
    $data = json_decode($response, true);
    return $data['secure_url'] ?? false;
}

// ── قائمة الألوان المتاحة (نفس قائمة fashion_ai) ──────────
$colorOptions = [
    'black'  => '#111827',
    'white'  => '#f9fafb',
    'navy'   => '#1e3a5f',
    'gray'   => '#9ca3af',
    'beige'  => '#d4b896',
    'brown'  => '#7c5c3e',
    'camel'  => '#c19a6b',
    'green'  => '#16a34a',
    'pink'   => '#ec4899',
    'red'    => '#dc2626',
    'blue'   => '#3b82f6',
    'yellow' => '#eab308',
    'orange' => '#f97316',
    'purple' => '#8b5cf6',
    'striped'=> null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name   = sanitize($_POST['name']           ?? '');
        $nameAr = sanitize($_POST['name_ar']        ?? '');
        $nameFr = sanitize($_POST['name_fr']        ?? '');
        $desc   = sanitize($_POST['description']    ?? '');
        $descAr = sanitize($_POST['description_ar'] ?? '');
        $descFr = sanitize($_POST['description_fr'] ?? '');
        $catId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $price  = (float)($_POST['price'] ?? 0);
        $color  = strtolower(trim(sanitize($_POST['color'] ?? ''))) ?: null;

        // ✅ التحقق من الطول والسعر
        if (strlen($name) > 200) {
            flash('error', 'اسم المنتج طويل جداً.');
            header('Location: /admin/products.php'); exit;
        }
        if ($price <= 0 || $price > 9999999) {
            flash('error', 'السعر غير صحيح.');
            header('Location: /admin/products.php'); exit;
        }

        $oldPrice = isset($_POST['old_price']) && $_POST['old_price'] !== ''
            ? (float)$_POST['old_price'] : null;
        if ($oldPrice !== null && ($oldPrice <= 0 || $oldPrice > 9999999)) $oldPrice = null;

        $sizes       = mb_substr(sanitize($_POST['sizes'] ?? 'S,M,L,XL,XXL'), 0, 100);
        $stockCount  = max(0, min(99999, (int)($_POST['stock_count'] ?? 0)));
        $offerEndsAt = isset($_POST['offer_ends_at']) && $_POST['offer_ends_at'] !== ''
            ? sanitize($_POST['offer_ends_at']) : null;

        // ✅ التحقق من صيغة التاريخ
        if ($offerEndsAt && !strtotime($offerEndsAt)) $offerEndsAt = null;

        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $imageUrl = sanitize($_POST['current_image_url'] ?? '');

        if (!empty($_FILES['image_file']['name'])) {
            $file    = $_FILES['image_file'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $maxSize = 5 * 1024 * 1024;

            // ✅ التحقق من نوع الملف الفعلي وليس فقط المُعلن عنه من المتصفح
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowed, true)) {
                flash('error', 'نوع الصورة غير مدعوم. المسموح: JPG, PNG, WEBP, GIF.');
            } elseif ($file['size'] > $maxSize) {
                flash('error', 'حجم الصورة كبير جداً. الحد الأقصى 5 MB.');
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                flash('error', 'فشل في رفع الصورة. حاول مرة أخرى.');
            } else {
                $cloudUrl = uploadToCloudinary($file['tmp_name'], $file['name']);
                if ($cloudUrl) {
                    $imageUrl = $cloudUrl;
                } else {
                    flash('error', 'فشل الرفع إلى Cloudinary. تحقق من بيانات الاعتماد.');
                }
            }
        }

        if (!$name || !$price) {
            flash('error', 'الاسم والسعر مطلوبان.');
        } else {
            if ($action === 'add') {
                $db->prepare(
                    'INSERT INTO products
                    (category_id, name, name_ar, name_fr, description, description_ar, description_fr,
                     price, old_price, image_url, sizes, stock_count, offer_ends_at, is_active, color)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $catId, $name, $nameAr ?: null, $nameFr ?: null,
                    $desc,  $descAr ?: null, $descFr ?: null,
                    $price, $oldPrice, $imageUrl, $sizes,
                    $stockCount, $offerEndsAt, $isActive, $color,
                ]);
                flash('success', 'تمت إضافة المنتج بنجاح! ✅');
            } else {
                $id = (int)$_POST['id'];
                if (!$id) {
                    flash('error', 'منتج غير صالح.');
                    header('Location: /admin/products.php'); exit;
                }
                $db->prepare(
                    'UPDATE products
                    SET category_id=?, name=?, name_ar=?, name_fr=?,
                        description=?, description_ar=?, description_fr=?,
                        price=?, old_price=?, image_url=?, sizes=?,
                        stock_count=?, offer_ends_at=?, is_active=?, color=?
                    WHERE id=?'
                )->execute([
                    $catId, $name, $nameAr ?: null, $nameFr ?: null,
                    $desc,  $descAr ?: null, $descFr ?: null,
                    $price, $oldPrice, $imageUrl, $sizes,
                    $stockCount, $offerEndsAt, $isActive, $color, $id,
                ]);
                flash('success', 'تم تحديث المنتج! ✅');
            }
            header('Location: /admin/products.php'); exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id) {
            $db->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
            flash('success', 'تم إخفاء المنتج.');
        }
        header('Location: /admin/products.php'); exit;
    }
}

// Edit mode
$editProduct = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId) {
        $stmt = $db->prepare('SELECT * FROM products WHERE id=?');
        $stmt->execute([$editId]);
        $editProduct = $stmt->fetch();
    }
}

// Filters
$search  = mb_substr(trim($_GET['search'] ?? ''), 0, 100);
$catFilt = (int)($_GET['category'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 15;
$offset  = ($page - 1) * $limit;

$where  = ['p.is_active=1'];
$params = [];
if ($search)  { $where[] = 'p.name ILIKE ?'; $params[] = "%$search%"; }
if ($catFilt) { $where[] = 'p.category_id=?'; $params[] = $catFilt; }
$whereSQL = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $whereSQL");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));
$page  = min($page, $pages);

$stmt = $db->prepare(
    "SELECT p.*, c.name AS cat_name FROM products p
     LEFT JOIN categories c ON c.id=p.category_id
     WHERE $whereSQL ORDER BY p.id DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM categories WHERE slug != 'all' ORDER BY sort_order")->fetchAll();

$pageTitle = 'Products';
include __DIR__ . '/includes/header.php';
?>

<style>
.img-upload-area { position: relative; border: 2px dashed #d1d5db; border-radius: 12px; overflow: hidden; background: #f9fafb; transition: border-color .2s, background .2s; cursor: pointer; min-height: 120px; }
.img-upload-area:hover { border-color: #111827; background: #f3f4f6; }
.img-upload-area input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
.img-upload-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px 16px; gap: 6px; pointer-events: none; user-select: none; }
.img-upload-placeholder .icon { font-size: 34px; color: #9ca3af; }
.img-upload-placeholder .label { font-size: 13px; font-weight: 600; color: #374151; }
.img-upload-placeholder .hint { font-size: 11.5px; color: #9ca3af; text-align: center; }
.img-upload-uploading { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px 16px; gap: 8px; pointer-events: none; }
.img-upload-uploading .spinner { width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top-color: #111827; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.img-upload-uploading span { font-size: 12px; color: #6b7280; }
.img-upload-preview { display: none; position: relative; }
.img-upload-preview img { width: 100%; max-height: 200px; object-fit: cover; display: block; }
.img-upload-preview .remove-btn { position: absolute; top: 8px; right: 8px; background: rgba(220,38,38,.8); color: #fff; border: none; border-radius: 50%; width: 30px; height: 30px; font-size: 15px; cursor: pointer; z-index: 3; display: flex; align-items: center; justify-content: center; pointer-events: all; }
.cloudinary-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; color: #0ea5e9; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 20px; padding: 2px 8px; margin-top: 4px; }
.stock-indicator { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.stock-high  { background: #dcfce7; color: #166534; }
.stock-low   { background: #fef08a; color: #b45309; }
.stock-empty { background: #fee2e2; color: #991b1b; }

/* ── لوحة الألوان ── */
.color-dot {
    display: inline-block;
    width: 14px; height: 14px; border-radius: 50%;
    border: 1.5px solid #d1d5db; vertical-align: middle;
    margin-right: 4px;
}
.color-pick-btn {
    padding: 6px 12px; border: 2px solid #e5e7eb; border-radius: 8px;
    font-size: 12px; font-weight: 600; background: #fff; cursor: pointer;
    transition: all .15s;
}
.color-pick-btn:hover { border-color: #9ca3af; }
.color-pick-btn.selected { border-color: #111827; background: #f3f4f6; }
</style>

<?php $succ=flash('success'); $err=flash('error'); ?>
<?php if($succ): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($succ) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err):  ?><div class="alert alert-danger  alert-dismissible fade show" role="alert"><?= htmlspecialchars($err)  ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-4">

  <!-- Product Form -->
  <div class="col-xl-4 col-lg-5">
    <div class="card">
      <div class="card-header">
        <h5><i class="bi bi-bag-plus me-2"></i><?= $editProduct ? 'تعديل منتج' : 'إضافة منتج جديد' ?></h5>
        <?php if ($editProduct): ?>
          <a href="/admin/products.php" class="btn btn-sm btn-outline-secondary ms-auto">إلغاء</a>
        <?php endif; ?>
      </div>
      <div class="p-4">
        <form method="POST" enctype="multipart/form-data" id="productForm">
          <input type="hidden" name="_csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="<?= $editProduct ? 'edit' : 'add' ?>">
          <input type="hidden" name="current_image_url" id="currentImageUrl" value="<?= htmlspecialchars($editProduct['image_url'] ?? '') ?>">
          <?php if ($editProduct): ?>
            <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#3b82f6"></i>اسم المنتج (إنجليزي) *</label>
            <input type="text" name="name" class="form-control" maxlength="200"
                   placeholder="مثال: Summer Dress"
                   value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#ec4899"></i>الاسم بالعربية</label>
            <input type="text" name="name_ar" class="form-control" dir="rtl" maxlength="200"
                   placeholder="مثال: فستان صيفي"
                   value="<?= htmlspecialchars($editProduct['name_ar'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#8b5cf6"></i>الاسم بالفرنسية</label>
            <input type="text" name="name_fr" class="form-control" maxlength="200"
                   placeholder="مثال: Robe d'été"
                   value="<?= htmlspecialchars($editProduct['name_fr'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-file-text me-1" style="color:#6366f1"></i>الوصف (إنجليزي)</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="وصف المنتج بالإنجليزية" required><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-file-text me-1" style="color:#ec4899"></i>الوصف بالعربية</label>
            <textarea name="description_ar" class="form-control" rows="2" dir="rtl"
                      placeholder="وصف المنتج بالعربية"><?= htmlspecialchars($editProduct['description_ar'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-file-text me-1" style="color:#8b5cf6"></i>الوصف بالفرنسية</label>
            <textarea name="description_fr" class="form-control" rows="2"
                      placeholder="Description du produit en français"><?= htmlspecialchars($editProduct['description_fr'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-folder me-1" style="color:#f59e0b"></i>الفئة</label>
            <select name="category_id" class="form-select">
              <option value="">— بدون فئة —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"
                  <?= ($editProduct['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label"><i class="bi bi-cash-coin me-1" style="color:#10b981"></i>السعر *</label>
              <input type="number" name="price" class="form-control" step="0.01" min="0.01" max="9999999"
                     value="<?= htmlspecialchars($editProduct['price'] ?? '') ?>" required>
            </div>
            <div class="col">
              <label class="form-label"><i class="bi bi-percent me-1" style="color:#ef4444"></i>السعر القديم</label>
              <input type="number" name="old_price" class="form-control" step="0.01" min="0" max="9999999"
                     value="<?= htmlspecialchars($editProduct['old_price'] ?? '') ?>" placeholder="اختياري">
            </div>
          </div>

          <!-- ── اختيار اللون ── -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-palette me-1" style="color:#8b5cf6"></i>اللون</label>
            <div class="row g-2 mb-2" id="colorPalette">
              <?php foreach ($colorOptions as $cname => $chex): ?>
                <div class="col-auto">
                  <button type="button"
                          class="color-pick-btn <?= ($editProduct['color'] ?? '') === $cname ? 'selected' : '' ?>"
                          onclick="pickColor('<?= $cname ?>', this)"
                          title="<?= $cname ?>">
                    <?php if ($chex): ?>
                      <span class="color-dot" style="background:<?= $chex ?>;<?= $cname === 'white' ? 'border-color:#d1d5db' : '' ?>"></span>
                    <?php else: ?>
                      〰
                    <?php endif; ?>
                    <?= $cname ?>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
            <input type="text" name="color" id="colorInput" class="form-control"
                   value="<?= htmlspecialchars($editProduct['color'] ?? '') ?>"
                   placeholder="أو اكتب اللون يدوياً: black, white, navy...">
          </div>

          <div class="mb-3">
            <label class="form-label d-flex align-items-center gap-2">
              <i class="bi bi-image" style="color:#06b6d4"></i>صورة المنتج
              <span class="cloudinary-badge"><i class="bi bi-cloud-arrow-up-fill"></i> Cloudinary CDN</span>
            </label>
            <div class="img-upload-area" id="uploadArea">
              <input type="file" name="image_file" id="imageFileInput" accept="image/*" onchange="handleImageSelect(this)">
              <div class="img-upload-placeholder" id="uploadPlaceholder">
                <span class="icon"><i class="bi bi-cloud-upload"></i></span>
                <span class="label">اضغط لاختيار صورة</span>
                <span class="hint">JPG · PNG · WEBP · GIF — حتى 5 MB</span>
              </div>
              <div class="img-upload-uploading" id="uploadingIndicator" style="display:none">
                <div class="spinner"></div>
                <span>جارٍ الرفع إلى Cloudinary...</span>
              </div>
              <div class="img-upload-preview" id="uploadPreview">
                <img id="previewImg" src="" alt="Preview">
                <button type="button" class="remove-btn" onclick="removeImage(event)" title="حذف"><i class="bi bi-x"></i></button>
                <button type="button" class="change-btn" onclick="triggerPicker(event)"><i class="bi bi-arrow-repeat"></i> تغيير</button>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-rulers me-1" style="color:#a78bfa"></i>المقاسات</label>
            <input type="text" name="sizes" class="form-control" maxlength="100"
                   value="<?= htmlspecialchars($editProduct['sizes'] ?? 'S,M,L,XL,XXL') ?>"
                   placeholder="مفصول بفواصل: S,M,L,XL,XXL">
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-box-seam me-1" style="color:#14b8a6"></i>عدد القطع المتبقية</label>
            <div class="input-group input-group-lg">
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(-5)">-5</button>
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(-1)">-1</button>
              <input type="number" name="stock_count" class="form-control text-center fw-bold"
                     id="stockCount" min="0" max="99999"
                     value="<?= (int)($editProduct['stock_count'] ?? 0) ?>"
                     style="font-size:18px;letter-spacing:2px">
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(1)">+1</button>
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(5)">+5</button>
            </div>
            <small class="text-muted d-block mt-2">💡 استخدم الأزرار للتعديل السريع أو اكتب الرقم مباشرة</small>
          </div>

          <div class="mb-3">
            <label class="form-label d-flex align-items-center gap-2">
              <i class="bi bi-hourglass-split" style="color:#f59e0b"></i>تاريخ انتهاء العرض
              <span class="badge bg-warning">اختياري</span>
            </label>
            <div class="input-group">
              <input type="datetime-local" name="offer_ends_at" class="form-control" id="offerEndsAt"
                     value="<?= htmlspecialchars(formatDatetimeForInput($editProduct['offer_ends_at'] ?? null)) ?>"
                     onchange="updateOfferStatus()">
              <button class="btn btn-outline-secondary" type="button" id="quickSetOffer"
                      style="border-left:none;border-right:none" title="تعيين سريع">⚡</button>
            </div>
            <small class="text-muted d-block mt-2">
              ⚡ <strong>الخيارات السريعة:</strong><br>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="setOfferQuick(1)">+1 ساعة</button>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="setOfferQuick(3)">+3 ساعات</button>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="setOfferQuick(6)">+6 ساعات</button>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="setOfferQuick(24)">+1 يوم</button>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="setOfferQuick(72)">+3 أيام</button>
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 m-0" onclick="clearOffer()">حذف العرض</button>
            </small>
            <div id="offerStatus" class="mt-2" style="display:none">
              <small id="offerStatusText" class="d-block fw-bold"></small>
            </div>
          </div>

          <div class="form-check mb-4 p-2 rounded" style="background:#f0f9ff;border-left:4px solid #06b6d4">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                   <?= ($editProduct['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label fw-500" for="isActive">
              <i class="bi bi-eye me-1"></i>نشط (مرئي في التطبيق)
            </label>
          </div>

          <button type="submit" class="btn btn-lg btn-dark w-100" id="submitBtn">
            <i class="bi bi-<?= $editProduct ? 'check2' : 'plus-lg' ?> me-2"></i><?= $editProduct ? 'حفظ التغييرات' : 'إضافة المنتج' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Products Table -->
  <div class="col-xl-8 col-lg-7">
    <div class="card">
      <div class="card-header justify-content-between flex-wrap gap-2">
        <h5><i class="bi bi-bag me-2"></i>المنتجات <span class="text-muted fw-normal" style="font-size:13px">(<?= (int)$total ?>)</span></h5>
        <form class="d-flex gap-2" method="GET">
          <input type="text" name="search" class="form-control form-control-sm" maxlength="100"
                 placeholder="بحث..." value="<?= htmlspecialchars($search) ?>" style="width:160px">
          <select name="category" class="form-select form-select-sm" style="width:130px">
            <option value="">جميع الفئات</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= $catFilt == $cat['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-dark">تصفية</button>
          <?php if ($search || $catFilt): ?>
            <a href="/admin/products.php" class="btn btn-sm btn-outline-secondary">إعادة تعيين</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead class="table-light">
            <tr>
              <th>المنتج</th><th>العربية</th><th>الفئة</th><th>اللون</th><th>السعر</th><th>المخزون</th><th>العرض</th><th>الحالة</th><th>الإجراءات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($products as $p):
            $timeStatus = getTimeRemaining($p['offer_ends_at']);
            $pColorHex  = $colorOptions[$p['color'] ?? ''] ?? null;
          ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($p['image_url']): ?>
                    <?php
                      $thumbUrl = $p['image_url'];
                      if (str_contains($thumbUrl, 'cloudinary.com')) {
                          $thumbUrl = str_replace('/upload/', '/upload/w_80,h_80,c_fill,q_auto,f_auto/', $thumbUrl);
                      }
                    ?>
                    <img src="<?= htmlspecialchars($thumbUrl) ?>" class="product-thumb" alt="">
                  <?php else: ?>
                    <div class="product-thumb-placeholder"><i class="bi bi-image"></i></div>
                  <?php endif; ?>
                  <div>
                    <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($p['name']) ?></div>
                    <div style="font-size:11px;color:#9ca3af">#<?= (int)$p['id'] ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:12px">
                <?php if (!empty($p['name_ar'])): ?>
                  <div dir="rtl"><?= htmlspecialchars($p['name_ar']) ?></div>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:13px"><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
              <td style="font-size:12px">
                <?php if (!empty($p['color'])): ?>
                  <span style="display:inline-flex;align-items:center;gap:5px">
                    <?php if ($pColorHex): ?>
                      <span class="color-dot" style="background:<?= $pColorHex ?>"></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($p['color']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-700"><?= formatPrice((float)$p['price']) ?></div>
                <?php if ($p['old_price']): ?>
                  <div style="font-size:11px;color:#9ca3af;text-decoration:line-through"><?= formatPrice((float)$p['old_price']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="stock-indicator <?php
                  if ((int)$p['stock_count'] > 10)     echo 'stock-high';
                  elseif ((int)$p['stock_count'] > 0)  echo 'stock-low';
                  else                                  echo 'stock-empty';
                ?>">
                  <?php if ((int)$p['stock_count'] > 0): ?>
                    ✓ <?= (int)$p['stock_count'] ?>
                  <?php else: ?>
                    ✗ منتهي
                  <?php endif; ?>
                </span>
              </td>
              <td style="font-size:12px">
                <span class="<?= htmlspecialchars($timeStatus['class']) ?>">
                  <?= htmlspecialchars($timeStatus['display']) ?>
                </span>
              </td>
              <td>
                <span style="font-size:11px;padding:3px 8px;border-radius:20px;font-weight:600;
                             background:<?= $p['is_active'] ? '#dcfce7' : '#f3f4f6' ?>;
                             color:<?= $p['is_active'] ? '#166534' : '#6b7280' ?>">
                  <?= $p['is_active'] ? '🟢 نشط' : '⚫ معطل' ?>
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <a href="/admin/products.php?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف"
                            data-confirm="هل تريد حذف هذا المنتج؟">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($products)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">لا توجد منتجات</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
      <div class="p-3 d-flex justify-content-center">
        <nav><ul class="pagination mb-0">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= (int)$i ?>&search=<?= urlencode($search) ?>&category=<?= (int)$catFilt ?>">
                <?= (int)$i ?>
              </a>
            </li>
          <?php endfor; ?>
        </ul></nav>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const existing = document.getElementById('currentImageUrl').value;
  if (existing) showPreview(existing);
});

function handleImageSelect(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => showPreview(e.target.result);
  reader.readAsDataURL(input.files[0]);
}

function showPreview(src) {
  document.getElementById('previewImg').src = src;
  document.getElementById('uploadPlaceholder').style.display   = 'none';
  document.getElementById('uploadingIndicator').style.display  = 'none';
  document.getElementById('uploadPreview').style.display       = 'block';
}

function removeImage(e) {
  e.stopPropagation();
  document.getElementById('imageFileInput').value    = '';
  document.getElementById('currentImageUrl').value  = '';
  document.getElementById('previewImg').src          = '';
  document.getElementById('uploadPreview').style.display      = 'none';
  document.getElementById('uploadingIndicator').style.display = 'none';
  document.getElementById('uploadPlaceholder').style.display  = 'flex';
}

function triggerPicker(e) {
  e.stopPropagation();
  document.getElementById('imageFileInput').click();
}

function adjustStock(amount) {
  const input    = document.getElementById('stockCount');
  const newValue = Math.max(0, parseInt(input.value || 0) + amount);
  input.value    = newValue;
  input.focus();
}

function setOfferQuick(hours) {
  const now = new Date();
  now.setHours(now.getHours() + hours);
  document.getElementById('offerEndsAt').value = now.toISOString().slice(0, 16);
  updateOfferStatus();
}

function clearOffer() {
  document.getElementById('offerEndsAt').value        = '';
  document.getElementById('offerStatus').style.display = 'none';
}

function updateOfferStatus() {
  const input      = document.getElementById('offerEndsAt');
  const statusDiv  = document.getElementById('offerStatus');
  const statusText = document.getElementById('offerStatusText');
  if (!input.value) { statusDiv.style.display = 'none'; return; }
  const endTime = new Date(input.value);
  const now     = new Date();
  if (now >= endTime) {
    statusText.textContent = '❌ التاريخ قد مضى!';
    statusText.style.color = '#dc2626';
  } else {
    const diff  = endTime - now;
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const days  = Math.floor(hours / 24);
    if (days > 0) {
      statusText.textContent = `✅ العرض سينتهي بعد ${days} يوم و${hours % 24} ساعات`;
      statusText.style.color = '#059669';
    } else {
      statusText.textContent = `⏰ العرض سينتهي بعد ${hours} ساعات`;
      statusText.style.color = '#f59e0b';
    }
  }
  statusDiv.style.display = 'block';
}

function pickColor(name, btn) {
  document.getElementById('colorInput').value = name;
  document.querySelectorAll('.color-pick-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
}

document.getElementById('productForm').addEventListener('submit', function () {
  const hasNewFile = document.getElementById('imageFileInput').files.length > 0;
  if (hasNewFile) {
    document.getElementById('uploadPreview').style.display      = 'none';
    document.getElementById('uploadPlaceholder').style.display  = 'none';
    document.getElementById('uploadingIndicator').style.display = 'flex';
    document.getElementById('submitBtn').disabled               = true;
    document.getElementById('submitBtn').innerHTML =
      '<span class="spinner-border spinner-border-sm me-2"></span>جارٍ الحفظ...';
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
