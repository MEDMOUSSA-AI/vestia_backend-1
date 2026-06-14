<?php
// ============================================================
// 🛠️ ADMIN PANEL: products.php (جاهز للاستخدام الفوري)
// ============================================================

session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();
$db = db();

// ✅ Helper function لتنسيق datetime للـ input
function formatDatetimeForInput(?string $datetime): string {
    if (!$datetime) return '';
    return substr(str_replace(' ', 'T', $datetime), 0, 16);
}

// ✅ Helper function لحساب المدة المتبقية
function getTimeRemaining(?string $offerEndsAt): array {
    if (!$offerEndsAt) {
        return ['display' => 'لا يوجد عرض', 'remaining_hours' => 0, 'class' => 'text-muted'];
    }
    
    $endTime = new DateTime($offerEndsAt);
    $now = new DateTime();
    
    if ($now >= $endTime) {
        return ['display' => '❌ انتهى العرض', 'remaining_hours' => 0, 'class' => 'text-danger'];
    }
    
    $interval = $now->diff($endTime);
    $remaining_hours = ($interval->d * 24) + $interval->h;
    
    if ($remaining_hours > 24) {
        $days = intdiv($remaining_hours, 24);
        $display = "⏳ $days أيام المتبقي";
        $class = 'text-warning';
    } else {
        $display = "⏰ $remaining_hours ساعات المتبقي";
        $class = $remaining_hours < 6 ? 'text-danger' : 'text-warning';
    }
    
    return ['display' => $display, 'remaining_hours' => $remaining_hours, 'class' => $class];
}

// ── Cloudinary Configuration ──
define('CLOUDINARY_CLOUD_NAME', 'dyaiu7env');
define('CLOUDINARY_API_KEY',    '368529122995758');
define('CLOUDINARY_API_SECRET', 'I-Udh8Hr06mSqWhkbhQeyTk1O5s');
define('CLOUDINARY_FOLDER',     'vestia/products');

function uploadToCloudinary(string $fileTmpPath, string $originalName): string|false {
    $timestamp  = time();
    $folder     = CLOUDINARY_FOLDER;
    $publicId   = $folder . '/' . pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid();
    $paramsToSign = "folder={$folder}&public_id={$publicId}&timestamp={$timestamp}";
    $signature    = sha1($paramsToSign . CLOUDINARY_API_SECRET);
    $uploadUrl = 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/image/upload';
    $postFields = [
        'file'       => new CURLFile($fileTmpPath),
        'api_key'    => CLOUDINARY_API_KEY,
        'timestamp'  => $timestamp,
        'signature'  => $signature,
        'folder'     => $folder,
        'public_id'  => $publicId,
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $uploadUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return false;
    $data = json_decode($response, true);
    return $data['secure_url'] ?? false;
}

// ── Handle actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        // ✅ جميع الحقول المتعددة اللغات
        $name     = sanitize($_POST['name']    ?? '');
        $nameAr   = sanitize($_POST['name_ar'] ?? '');
        $nameFr   = sanitize($_POST['name_fr'] ?? '');
        
        // ✅ الأوصاف المتعددة اللغات
        $desc     = sanitize($_POST['description']    ?? '');
        $descAr   = sanitize($_POST['description_ar'] ?? '');
        $descFr   = sanitize($_POST['description_fr'] ?? '');
        
        $catId    = (int)($_POST['category_id'] ?? 0) ?: null;
        $price    = (float)($_POST['price']     ?? 0);
        $oldPrice = $_POST['old_price'] !== '' ? (float)$_POST['old_price'] : null;
        $sizes    = sanitize($_POST['sizes']    ?? 'S,M,L,XL,XXL');
        
        // ✅ المخزون
        $stockCount = (int)($_POST['stock_count'] ?? 0);
        
        // ✅ نهاية العرض
        $offerEndsAt = $_POST['offer_ends_at'] !== '' ? sanitize($_POST['offer_ends_at']) : null;
        
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // الصورة
        $imageUrl = sanitize($_POST['current_image_url'] ?? '');

        if (!empty($_FILES['image_file']['name'])) {
            $file    = $_FILES['image_file'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $maxSize = 5 * 1024 * 1024;

            if (!in_array($file['type'], $allowed)) {
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
                     price, old_price, image_url, sizes, stock_count, offer_ends_at, is_active) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $catId, $name, $nameAr ?: null, $nameFr ?: null, 
                    $desc, $descAr ?: null, $descFr ?: null,
                    $price, $oldPrice, $imageUrl, $sizes, 
                    $stockCount, $offerEndsAt ?: null, $isActive
                ]);
                flash('success', 'تمت إضافة المنتج بنجاح! ✅');
            } else {
                $id = (int)$_POST['id'];
                $db->prepare(
                    'UPDATE products 
                    SET category_id=?, name=?, name_ar=?, name_fr=?, 
                        description=?, description_ar=?, description_fr=?,
                        price=?, old_price=?, image_url=?, sizes=?, 
                        stock_count=?, offer_ends_at=?, is_active=? 
                    WHERE id=?'
                )->execute([
                    $catId, $name, $nameAr ?: null, $nameFr ?: null, 
                    $desc, $descAr ?: null, $descFr ?: null,
                    $price, $oldPrice, $imageUrl, $sizes, 
                    $stockCount, $offerEndsAt ?: null, $isActive, $id
                ]);
                flash('success', 'تم تحديث المنتج! ✅');
            }
            header('Location: https://vestia-backend-2.onrender.com/admin/products.php'); exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
        flash('success', 'تم إخفاء المنتج.');
        header('Location: https://vestia-backend-2.onrender.com/admin/products.php'); exit;
    }
}

// Edit mode
$editProduct = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editProduct = $stmt->fetch();
}

// Filters
$search  = trim($_GET['search']   ?? '');
$catFilt = (int)($_GET['category'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 15;
$offset  = ($page - 1) * $limit;

$where  = ['p.is_active=1'];
$params = [];
if ($search)  { $where[] = 'p.name ILIKE ?'; $params[] = "%$search%"; }
if ($catFilt) { $where[] = 'p.category_id=?'; $params[] = $catFilt; }
$whereSQL = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM products p WHERE $whereSQL");
$total->execute($params); $total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));

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
.stock-high { background: #dcfce7; color: #166534; }
.stock-low { background: #fef08a; color: #b45309; }
.stock-empty { background: #fee2e2; color: #991b1b; }
</style>

<?php $succ=flash('success'); $err=flash('error'); ?>
<?php if($succ): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($succ) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err):  ?><div class="alert alert-danger alert-dismissible fade show"  role="alert"><?= htmlspecialchars($err)  ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-4">

  <!-- Product Form -->
  <div class="col-xl-4 col-lg-5">
    <div class="card">
      <div class="card-header">
        <h5><i class="bi bi-bag-plus me-2"></i><?= $editProduct ? 'تعديل منتج' : 'إضافة منتج جديد' ?></h5>
        <?php if ($editProduct): ?>
          <a href="https://vestia-backend-2.onrender.com/admin/products.php" class="btn btn-sm btn-outline-secondary ms-auto">إلغاء</a>
        <?php endif; ?>
      </div>
      <div class="p-4">
        <form method="POST" enctype="multipart/form-data" id="productForm">
          <input type="hidden" name="_csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="<?= $editProduct ? 'edit' : 'add' ?>">
          <input type="hidden" name="current_image_url" id="currentImageUrl" value="<?= htmlspecialchars($editProduct['image_url'] ?? '') ?>">
          <?php if ($editProduct): ?><input type="hidden" name="id" value="<?= $editProduct['id'] ?>"><?php endif; ?>

          <!-- ✅ أسماء متعددة اللغات -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#3b82f6"></i>اسم المنتج (إنجليزي) *</label>
            <input type="text" name="name" class="form-control" placeholder="مثال: Summer Dress"
                   value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#ec4899"></i>الاسم بالعربية</label>
            <input type="text" name="name_ar" class="form-control" dir="rtl"
                   placeholder="مثال: فستان صيفي"
                   value="<?= htmlspecialchars($editProduct['name_ar'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label"><i class="bi bi-tag me-1" style="color:#8b5cf6"></i>الاسم بالفرنسية</label>
            <input type="text" name="name_fr" class="form-control"
                   placeholder="مثال: Robe d'été"
                   value="<?= htmlspecialchars($editProduct['name_fr'] ?? '') ?>">
          </div>

          <!-- ✅ الأوصاف -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-file-text me-1" style="color:#6366f1"></i>الوصف (إنجليزي)</label>
            <textarea name="description" class="form-control" rows="2" placeholder="وصف المنتج بالإنجليزية" required><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
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

          <!-- ✅ الفئة -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-folder me-1" style="color:#f59e0b"></i>الفئة</label>
            <select name="category_id" class="form-select">
              <option value="">— بدون فئة —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($editProduct['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- ✅ الأسعار -->
          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label"><i class="bi bi-cash-coin me-1" style="color:#10b981"></i>السعر *</label>
              <input type="number" name="price" class="form-control" step="0.01"
                     value="<?= $editProduct['price'] ?? '' ?>" required>
            </div>
            <div class="col">
              <label class="form-label"><i class="bi bi-percent me-1" style="color:#ef4444"></i>السعر القديم</label>
              <input type="number" name="old_price" class="form-control" step="0.01"
                     value="<?= $editProduct['old_price'] ?? '' ?>" placeholder="اختياري">
            </div>
          </div>

          <!-- ✅ الصورة -->
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

          <!-- ✅ المقاسات -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-rulers me-1" style="color:#a78bfa"></i>المقاسات</label>
            <input type="text" name="sizes" class="form-control"
                   value="<?= htmlspecialchars($editProduct['sizes'] ?? 'S,M,L,XL,XXL') ?>"
                   placeholder="مفصول بفواصل: S,M,L,XL,XXL">
          </div>

          <!-- ✅ المخزون (سهل الاستخدام) -->
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-box-seam me-1" style="color:#14b8a6"></i>عدد القطع المتبقية</label>
            <div class="input-group input-group-lg">
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(-5)">-5</button>
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(-1)">-1</button>
              <input type="number" name="stock_count" class="form-control text-center fw-bold" 
                     id="stockCount" min="0" value="<?= $editProduct['stock_count'] ?? 0 ?>"
                     style="font-size: 18px; letter-spacing: 2px">
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(1)">+1</button>
              <button class="btn btn-outline-secondary" type="button" onclick="adjustStock(5)">+5</button>
            </div>
            <small class="text-muted d-block mt-2">
              💡 استخدم الأزرار للتعديل السريع أو اكتب الرقم مباشرة
            </small>
          </div>

          <!-- ✅ نهاية العرض (سهل جداً) -->
          <div class="mb-3">
            <label class="form-label d-flex align-items-center gap-2">
              <i class="bi bi-hourglass-split" style="color:#f59e0b"></i>تاريخ انتهاء العرض
              <span class="badge bg-warning">اختياري</span>
            </label>
            <div class="input-group">
              <input type="datetime-local" name="offer_ends_at" 
                     class="form-control" id="offerEndsAt"
                     value="<?= formatDatetimeForInput($editProduct['offer_ends_at'] ?? null) ?>"
                     onchange="updateOfferStatus()">
              <button class="btn btn-outline-secondary" type="button" id="quickSetOffer" 
                      style="border-left: none; border-right: none" title="تعيين سريع">
                ⚡
              </button>
            </div>
            <small class="text-muted d-block mt-2">
              ⚡ <strong>الخيارات السريعة:</strong>
              <br>
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

          <!-- ✅ حالة المنتج -->
          <div class="form-check mb-4 p-2 rounded" style="background: #f0f9ff; border-left: 4px solid #06b6d4">
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
        <h5><i class="bi bi-bag me-2"></i>المنتجات <span class="text-muted fw-normal" style="font-size:13px">(<?= $total ?>)</span></h5>
        <form class="d-flex gap-2" method="GET">
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="بحث..." value="<?= htmlspecialchars($search) ?>" style="width:160px">
          <select name="category" class="form-select form-select-sm" style="width:130px">
            <option value="">جميع الفئات</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= $catFilt == $cat['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-dark">تصفية</button>
          <?php if ($search || $catFilt): ?>
            <a href="https://vestia-backend-2.onrender.com/admin/products.php" class="btn btn-sm btn-outline-secondary">إعادة تعيين</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead class="table-light">
            <tr>
              <th>المنتج</th><th>العربية</th><th>الفئة</th><th>السعر</th><th>المخزون</th><th>العرض</th><th>الحالة</th><th>الإجراءات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($products as $p): 
            $timeStatus = getTimeRemaining($p['offer_ends_at']);
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
                    <div style="font-size:11px;color:#9ca3af">#<?= $p['id'] ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:12px">
                <?php if ($p['name_ar'] ?? null): ?>
                  <div dir="rtl"><?= htmlspecialchars($p['name_ar']) ?></div>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:13px"><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
              <td>
                <div class="fw-700"><?= formatPrice($p['price']) ?></div>
                <?php if ($p['old_price']): ?>
                  <div style="font-size:11px;color:#9ca3af;text-decoration:line-through"><?= formatPrice($p['old_price']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="stock-indicator <?php 
                  if ($p['stock_count'] > 10) echo 'stock-high';
                  elseif ($p['stock_count'] > 0) echo 'stock-low';
                  else echo 'stock-empty';
                ?>">
                  <?php if ($p['stock_count'] > 0): ?>
                    ✓ <?= $p['stock_count'] ?>
                  <?php else: ?>
                    ✗ منتهي
                  <?php endif; ?>
                </span>
              </td>
              <td style="font-size:12px">
                <span class="<?= $timeStatus['class'] ?>">
                  <?= $timeStatus['display'] ?>
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
                  <a href="https://vestia-backend-2.onrender.com/admin/products.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="bi bi-pencil"></i></a>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف"
                            onclick="return confirm('هل تريد حذف هذا المنتج؟')"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($products)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">لا توجد منتجات</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
      <div class="p-3 d-flex justify-content-center">
        <nav><ul class="pagination mb-0">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $catFilt ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul></nav>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// ✅ إدارة الصور
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
  document.getElementById('uploadPlaceholder').style.display = 'none';
  document.getElementById('uploadingIndicator').style.display = 'none';
  document.getElementById('uploadPreview').style.display = 'block';
}

function removeImage(e) {
  e.stopPropagation();
  document.getElementById('imageFileInput').value = '';
  document.getElementById('currentImageUrl').value = '';
  document.getElementById('previewImg').src = '';
  document.getElementById('uploadPreview').style.display = 'none';
  document.getElementById('uploadingIndicator').style.display = 'none';
  document.getElementById('uploadPlaceholder').style.display = 'flex';
}

function triggerPicker(e) {
  e.stopPropagation();
  document.getElementById('imageFileInput').click();
}

// ✅ إدارة المخزون (بسيط جداً)
function adjustStock(amount) {
  const input = document.getElementById('stockCount');
  const newValue = Math.max(0, parseInt(input.value) + amount);
  input.value = newValue;
  input.focus();
}

// ✅ إدارة العرض (سهل جداً)
function setOfferQuick(hours) {
  const now = new Date();
  now.setHours(now.getHours() + hours);
  const iso = now.toISOString().slice(0, 16);
  document.getElementById('offerEndsAt').value = iso;
  updateOfferStatus();
}

function clearOffer() {
  document.getElementById('offerEndsAt').value = '';
  document.getElementById('offerStatus').style.display = 'none';
}

function updateOfferStatus() {
  const input = document.getElementById('offerEndsAt');
  const statusDiv = document.getElementById('offerStatus');
  const statusText = document.getElementById('offerStatusText');
  
  if (!input.value) {
    statusDiv.style.display = 'none';
    return;
  }
  
  const endTime = new Date(input.value);
  const now = new Date();
  
  if (now >= endTime) {
    statusText.textContent = '❌ التاريخ قد مضى!';
    statusText.style.color = '#dc2626';
  } else {
    const diff = endTime - now;
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const days = Math.floor(hours / 24);
    
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

// ✅ Submit handler
document.getElementById('productForm').addEventListener('submit', function () {
  const hasNewFile = document.getElementById('imageFileInput').files.length > 0;
  if (hasNewFile) {
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadPlaceholder').style.display = 'none';
    document.getElementById('uploadingIndicator').style.display = 'flex';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = 
      '<span class="spinner-border spinner-border-sm me-2"></span>جارٍ الحفظ...';
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
