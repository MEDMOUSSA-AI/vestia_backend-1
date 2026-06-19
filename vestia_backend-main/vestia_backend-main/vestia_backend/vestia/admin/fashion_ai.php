<?php

session_start();
require_once __DIR__ . '/includes/db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
adminCheck();
$db = db();

// ══════════════════════════════════════════════════════════
// POST ACTIONS
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── ربط منتجَين ──────────────────────────────────────
    if ($action === 'add_pairing') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $pairedId  = (int)($_POST['paired_id']  ?? 0);
        $score     = min(1.0, max(0.1, (float)($_POST['score'] ?? 0.9)));

        if (!$productId || !$pairedId) {
            flash('error', 'يجب اختيار منتجَين.');
        } elseif ($productId === $pairedId) {
            flash('error', 'لا يمكن ربط المنتج بنفسه.');
        } else {
            // ربط أساسي
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

            flash('success', 'تم ربط المنتجَين بنجاح ✅');
        }
        header('Location: /admin/fashion_ai.php'); exit;
    }

    // ── إلغاء ربط ────────────────────────────────────────
    if ($action === 'remove_pairing') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $pairedId  = (int)($_POST['paired_id']  ?? 0);

        if ($productId && $pairedId) {
            $db->prepare("
                UPDATE product_pairings SET is_active = false
                WHERE (product_id = ? AND paired_id = ?)
                   OR (product_id = ? AND paired_id = ?)
            ")->execute([$productId, $pairedId, $pairedId, $productId]);
            flash('success', 'تم إلغاء الربط.');
        }
        header('Location: /admin/fashion_ai.php'); exit;
    }

    // ── تحديث لون منتج ───────────────────────────────────
    if ($action === 'update_color') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $color     = strtolower(trim(sanitize($_POST['color'] ?? '')));

        if ($productId && $color) {
            $db->prepare("UPDATE products SET color = ? WHERE id = ?")
               ->execute([$color, $productId]);
            flash('success', 'تم تحديث اللون ✅');
        }
        header('Location: /admin/fashion_ai.php'); exit;
    }

    // ── منح كريدت لمستخدم ────────────────────────────────
    if ($action === 'grant_credits') {
        $userId  = (int)($_POST['user_id']  ?? 0);
        $credits = max(1, min(50, (int)($_POST['credits'] ?? 3)));

        if ($userId) {
            $db->prepare("
                UPDATE users
                SET tryon_credits  = tryon_credits + ?,
                    tryon_reset_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$credits, $userId]);
            flash('success', "تم منح $credits كريدت للمستخدم #$userId ✅");
        }
        header('Location: /admin/fashion_ai.php'); exit;
    }
}

// ══════════════════════════════════════════════════════════
// DATA
// ══════════════════════════════════════════════════════════

// كل المنتجات النشطة
$products = $db->query("
    SELECT id, name, name_ar, category_id, image_url, color
    FROM products
    WHERE is_active = 1
    ORDER BY category_id, name
")->fetchAll();

// الفئات
$categories = $db->query("SELECT id, name FROM categories ORDER BY id")->fetchAll();
$catMap = array_column($categories, 'name', 'id');

// الربط الحالي
$pairings = $db->query("
    SELECT pp.product_id, pp.paired_id, pp.score, pp.source, pp.created_at,
           p1.name AS product_name, p1.image_url AS product_image,
           p2.name AS paired_name,  p2.image_url AS paired_image,
           c1.name AS product_cat,  c2.name AS paired_cat
    FROM product_pairings pp
    JOIN products p1 ON p1.id = pp.product_id
    JOIN products p2 ON p2.id = pp.paired_id
    LEFT JOIN categories c1 ON c1.id = p1.category_id
    LEFT JOIN categories c2 ON c2.id = p2.category_id
    WHERE pp.is_active = true
      AND pp.product_id < pp.paired_id
    ORDER BY pp.score DESC, pp.created_at DESC
")->fetchAll();

// إحصاءات Try-On
$stats = $db->query("
    SELECT
        COUNT(*) AS total_tryons,
        COUNT(DISTINCT user_id) AS unique_users,
        SUM(CASE WHEN result_url IS NOT NULL AND result_url != 'REFUNDED' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN result_url = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded
    FROM tryon_logs
")->fetch();

// أكثر المنتجات تجربةً
$topProducts = $db->query("
    SELECT p.name, p.image_url, COUNT(*) AS tries
    FROM tryon_logs tl
    JOIN products p ON p.id = tl.product_id
    GROUP BY p.id, p.name, p.image_url
    ORDER BY tries DESC
    LIMIT 5
")->fetchAll();

// المستخدمون مع الكريدت
$users = $db->query("
    SELECT id, name, email, tryon_credits, tryon_total_used
    FROM users
    ORDER BY tryon_total_used DESC
    LIMIT 30
")->fetchAll();

// الألوان المتاحة في color_rules
$colorRules = $db->query("
    SELECT DISTINCT base_color FROM color_rules ORDER BY base_color
")->fetchAll(PDO::FETCH_COLUMN);

// منتجات بدون لون
$noColorProducts = $db->query("
    SELECT id, name, category_id FROM products
    WHERE is_active = 1 AND (color IS NULL OR color = '')
    ORDER BY category_id, name
    LIMIT 20
")->fetchAll();

$pageTitle = 'Fashion AI';
include __DIR__ . '/includes/header.php';
?>

<style>
/* ── بطاقات الإحصاءات ── */
.ai-stat-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px 24px;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 16px;
}
.ai-stat-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.ai-stat-value { font-size: 26px; font-weight: 700; line-height: 1.1; }
.ai-stat-label { font-size: 12px; color: #6b7280; margin-top: 2px; }

/* ── بطاقات الربط ── */
.pairing-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: box-shadow .15s;
}
.pairing-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
.pairing-thumb {
    width: 52px; height: 52px;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
    flex-shrink: 0;
}
.pairing-thumb-placeholder {
    width: 52px; height: 52px;
    border-radius: 8px;
    background: #f3f4f6;
    display: flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 20px; flex-shrink: 0;
}
.score-badge {
    padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.score-high   { background: #dcfce7; color: #166534; }
.score-medium { background: #fef9c3; color: #854d0e; }
.score-low    { background: #fee2e2; color: #991b1b; }

.source-admin { background: #dbeafe; color: #1d4ed8; }
.source-ai    { background: #f3e8ff; color: #7c3aed; }

/* ── تاب ── */
.ai-tab-btn {
    padding: 8px 20px; border-radius: 8px; border: 1.5px solid #e5e7eb;
    background: #fff; cursor: pointer; font-size: 13px; font-weight: 600;
    color: #6b7280; transition: all .15s;
}
.ai-tab-btn.active {
    background: #111827; color: #fff; border-color: #111827;
}

/* ── color dot ── */
.color-dot {
    display: inline-block;
    width: 14px; height: 14px; border-radius: 50%;
    border: 1.5px solid #d1d5db; vertical-align: middle;
    margin-right: 4px;
}
</style>

<?php $succ = flash('success'); $err = flash('error'); ?>
<?php if ($succ): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($succ) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- ══ Header ══ -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div>
        <h4 class="mb-0 fw-700"><i class="bi bi-magic me-2" style="color:#8b5cf6"></i>Fashion AI</h4>
        <small class="text-muted">إدارة التجربة الافتراضية وربط المنتجات</small>
    </div>
    <div class="d-flex gap-2">
        <button class="ai-tab-btn active" onclick="switchTab('pairings', this)">
            <i class="bi bi-link-45deg me-1"></i>ربط المنتجات
        </button>
        <button class="ai-tab-btn" onclick="switchTab('colors', this)">
            <i class="bi bi-palette me-1"></i>الألوان
        </button>
        <button class="ai-tab-btn" onclick="switchTab('credits', this)">
            <i class="bi bi-coin me-1"></i>الكريديت
        </button>
        <button class="ai-tab-btn" onclick="switchTab('stats', this)">
            <i class="bi bi-bar-chart me-1"></i>الإحصاءات
        </button>
    </div>
</div>

<!-- ══ إحصاءات سريعة ══ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="ai-stat-card">
            <div class="ai-stat-icon" style="background:#ede9fe">🪄</div>
            <div>
                <div class="ai-stat-value"><?= (int)($stats['total_tryons'] ?? 0) ?></div>
                <div class="ai-stat-label">إجمالي التجارب</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ai-stat-card">
            <div class="ai-stat-icon" style="background:#dcfce7">✅</div>
            <div>
                <div class="ai-stat-value"><?= (int)($stats['successful'] ?? 0) ?></div>
                <div class="ai-stat-label">تجارب ناجحة</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ai-stat-card">
            <div class="ai-stat-icon" style="background:#dbeafe">👤</div>
            <div>
                <div class="ai-stat-value"><?= (int)($stats['unique_users'] ?? 0) ?></div>
                <div class="ai-stat-label">مستخدمون فريدون</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ai-stat-card">
            <div class="ai-stat-icon" style="background:#fef9c3">🔗</div>
            <div>
                <div class="ai-stat-value"><?= count($pairings) ?></div>
                <div class="ai-stat-label">روابط نشطة</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 1: ربط المنتجات
══════════════════════════════════════════════════════════ -->
<div id="tab-pairings">
<div class="row g-4">

    <!-- نموذج الربط -->
    <div class="col-xl-4 col-lg-5">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-link-45deg me-2"></i>ربط منتجَين</h5>
            </div>
            <div class="p-4">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="add_pairing">

                    <div class="mb-3">
                        <label class="form-label fw-600">
                            <i class="bi bi-bag me-1" style="color:#3b82f6"></i>المنتج الأول
                        </label>
                        <select name="product_id" class="form-select" id="product1Select" onchange="updatePreview()" required>
                            <option value="">— اختر منتجاً —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                        data-img="<?= htmlspecialchars($p['image_url'] ?? '') ?>"
                                        data-cat="<?= htmlspecialchars($catMap[$p['category_id']] ?? '') ?>"
                                        data-color="<?= htmlspecialchars($p['color'] ?? '') ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($p['name_ar']): ?>(<?= htmlspecialchars($p['name_ar']) ?>)<?php endif; ?>
                                    — <?= htmlspecialchars($catMap[$p['category_id']] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- معاينة المنتج الأول -->
                        <div id="preview1" class="mt-2 d-none">
                            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f9fafb;border:1px solid #e5e7eb">
                                <img id="preview1Img" src="" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover">
                                <div>
                                    <div id="preview1Name" style="font-size:12px;font-weight:600"></div>
                                    <div id="preview1Cat" style="font-size:11px;color:#6b7280"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-600">
                            <i class="bi bi-bag-heart me-1" style="color:#ec4899"></i>المنتج المكمّل
                        </label>
                        <select name="paired_id" class="form-select" id="product2Select" onchange="updatePreview()" required>
                            <option value="">— اختر منتجاً —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                        data-img="<?= htmlspecialchars($p['image_url'] ?? '') ?>"
                                        data-cat="<?= htmlspecialchars($catMap[$p['category_id']] ?? '') ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($p['name_ar']): ?>(<?= htmlspecialchars($p['name_ar']) ?>)<?php endif; ?>
                                    — <?= htmlspecialchars($catMap[$p['category_id']] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- معاينة المنتج الثاني -->
                        <div id="preview2" class="mt-2 d-none">
                            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f9fafb;border:1px solid #e5e7eb">
                                <img id="preview2Img" src="" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover">
                                <div>
                                    <div id="preview2Name" style="font-size:12px;font-weight:600"></div>
                                    <div id="preview2Cat" style="font-size:11px;color:#6b7280"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-600">
                            <i class="bi bi-star me-1" style="color:#f59e0b"></i>درجة التطابق
                            <span class="text-muted fw-400" style="font-size:12px">(0.1 – 1.0)</span>
                        </label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="range" name="score" id="scoreRange"
                                   min="0.1" max="1.0" step="0.05" value="0.9"
                                   class="form-range flex-grow-1"
                                   oninput="document.getElementById('scoreVal').textContent = parseFloat(this.value).toFixed(2)">
                            <span id="scoreVal" class="fw-700" style="min-width:36px;color:#111827">0.90</span>
                        </div>
                        <div class="d-flex justify-content-between mt-1" style="font-size:10px;color:#9ca3af">
                            <span>ضعيف</span><span>متوسط</span><span>ممتاز</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg w-100">
                        <i class="bi bi-link-45deg me-2"></i>ربط المنتجَين
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- قائمة الروابط -->
    <div class="col-xl-8 col-lg-7">
        <div class="card">
            <div class="card-header justify-content-between">
                <h5><i class="bi bi-grid-3x3-gap me-2"></i>الروابط الحالية <span class="text-muted fw-normal" style="font-size:13px">(<?= count($pairings) ?>)</span></h5>
                <input type="text" id="pairingSearch" class="form-control form-control-sm" style="width:180px" placeholder="بحث..." oninput="filterPairings(this.value)">
            </div>
            <div class="p-3" id="pairingsList">
                <?php if (empty($pairings)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-link-45deg" style="font-size:40px;opacity:.3"></i>
                        <p class="mt-3">لا توجد روابط بعد — أضف أول ربط من النموذج</p>
                    </div>
                <?php else: ?>
                    <div class="row g-2" id="pairingsGrid">
                    <?php foreach ($pairings as $pair):
                        $thumb1 = $pair['product_image'] ?? '';
                        $thumb2 = $pair['paired_image']  ?? '';
                        if ($thumb1 && str_contains($thumb1, 'cloudinary.com'))
                            $thumb1 = str_replace('/upload/', '/upload/w_80,h_80,c_fill,q_auto,f_auto/', $thumb1);
                        if ($thumb2 && str_contains($thumb2, 'cloudinary.com'))
                            $thumb2 = str_replace('/upload/', '/upload/w_80,h_80,c_fill,q_auto,f_auto/', $thumb2);
                        $score = (float)$pair['score'];
                        $scoreClass = $score >= 0.8 ? 'score-high' : ($score >= 0.5 ? 'score-medium' : 'score-low');
                    ?>
                    <div class="col-12 pairing-row" data-names="<?= strtolower(htmlspecialchars($pair['product_name'] . ' ' . $pair['paired_name'])) ?>">
                        <div class="pairing-card">
                            <!-- صورة 1 -->
                            <?php if ($thumb1): ?>
                                <img src="<?= htmlspecialchars($thumb1) ?>" class="pairing-thumb" alt="">
                            <?php else: ?>
                                <div class="pairing-thumb-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>

                            <div style="flex:1;min-width:0">
                                <div class="fw-600" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?= htmlspecialchars($pair['product_name']) ?>
                                </div>
                                <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($pair['product_cat'] ?? '') ?></div>
                            </div>

                            <!-- أيقونة الربط -->
                            <div class="text-center px-1">
                                <i class="bi bi-arrow-left-right" style="color:#d1d5db;font-size:16px"></i>
                            </div>

                            <!-- صورة 2 -->
                            <?php if ($thumb2): ?>
                                <img src="<?= htmlspecialchars($thumb2) ?>" class="pairing-thumb" alt="">
                            <?php else: ?>
                                <div class="pairing-thumb-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>

                            <div style="flex:1;min-width:0">
                                <div class="fw-600" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?= htmlspecialchars($pair['paired_name']) ?>
                                </div>
                                <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($pair['paired_cat'] ?? '') ?></div>
                            </div>

                            <!-- التفاصيل -->
                            <div class="d-flex flex-column align-items-end gap-1 ms-auto" style="flex-shrink:0">
                                <span class="score-badge <?= $scoreClass ?>"><?= number_format($score, 2) ?></span>
                                <span class="score-badge <?= $pair['source'] === 'admin' ? 'source-admin' : 'source-ai' ?>" style="font-size:10px">
                                    <?= $pair['source'] === 'admin' ? '👤 أدمن' : '🤖 AI' ?>
                                </span>
                            </div>

                            <!-- زر الحذف -->
                            <form method="POST" class="ms-2">
                                <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                                <input type="hidden" name="action" value="remove_pairing">
                                <input type="hidden" name="product_id" value="<?= (int)$pair['product_id'] ?>">
                                <input type="hidden" name="paired_id"  value="<?= (int)$pair['paired_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="إلغاء ربط هذين المنتجَين؟" title="إلغاء الربط">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 2: الألوان
══════════════════════════════════════════════════════════ -->
<div id="tab-colors" style="display:none">
<div class="row g-4">

    <!-- تحديث لون منتج -->
    <div class="col-xl-4 col-lg-5">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-palette me-2"></i>تحديث لون منتج</h5>
            </div>
            <div class="p-4">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="update_color">

                    <div class="mb-3">
                        <label class="form-label fw-600">المنتج</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">— اختر منتجاً —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($p['color']): ?>
                                        [<?= htmlspecialchars($p['color']) ?>]
                                    <?php else: ?>
                                        [بدون لون]
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-600">اللون</label>
                        <div class="row g-2 mb-2" id="colorPalette">
                            <?php
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
                            foreach ($colorOptions as $name => $hex): ?>
                                <div class="col-auto">
                                    <button type="button"
                                            class="btn btn-sm color-pick-btn"
                                            onclick="pickColor('<?= $name ?>')"
                                            title="<?= $name ?>"
                                            style="padding:6px 12px;border:2px solid #e5e7eb;border-radius:8px;font-size:12px;font-weight:600">
                                        <?php if ($hex): ?>
                                            <span class="color-dot" style="background:<?= $hex ?>;<?= $name === 'white' ? 'border-color:#d1d5db' : '' ?>"></span>
                                        <?php else: ?>
                                            〰
                                        <?php endif; ?>
                                        <?= $name ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" name="color" id="colorInput" class="form-control"
                               placeholder="أو اكتب اللون يدوياً: black, white, navy..."
                               required>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg w-100">
                        <i class="bi bi-palette me-2"></i>حفظ اللون
                    </button>
                </form>
            </div>
        </div>

        <!-- منتجات بدون لون -->
        <?php if (!empty($noColorProducts)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="bi bi-exclamation-triangle me-2" style="color:#f59e0b"></i>منتجات بدون لون</h5>
                <span class="badge bg-warning text-dark ms-auto"><?= count($noColorProducts) ?></span>
            </div>
            <div class="p-3">
                <?php foreach ($noColorProducts as $p): ?>
                    <div class="d-flex align-items-center justify-content-between py-1 border-bottom" style="font-size:12px">
                        <span><?= htmlspecialchars($p['name']) ?></span>
                        <span class="text-muted"><?= htmlspecialchars($catMap[$p['category_id']] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- جدول الألوان الحالية -->
    <div class="col-xl-8 col-lg-7">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-table me-2"></i>ألوان المنتجات الحالية</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr><th>المنتج</th><th>الفئة</th><th>اللون</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $p):
                        $hex = [
                            'black'=>'#111', 'white'=>'#f9f9f9', 'navy'=>'#1e3a5f',
                            'gray'=>'#9ca3af', 'beige'=>'#d4b896', 'brown'=>'#7c5c3e',
                            'camel'=>'#c19a6b', 'green'=>'#16a34a', 'pink'=>'#ec4899',
                            'red'=>'#dc2626', 'blue'=>'#3b82f6', 'yellow'=>'#eab308',
                            'orange'=>'#f97316', 'purple'=>'#8b5cf6',
                        ][$p['color'] ?? ''] ?? '#e5e7eb';
                    ?>
                        <tr>
                            <td style="font-size:13px">
                                <div class="fw-600"><?= htmlspecialchars($p['name']) ?></div>
                                <div style="font-size:11px;color:#9ca3af">#<?= (int)$p['id'] ?></div>
                            </td>
                            <td style="font-size:12px;color:#6b7280">
                                <?= htmlspecialchars($catMap[$p['category_id']] ?? '—') ?>
                            </td>
                            <td>
                                <?php if ($p['color']): ?>
                                    <span style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:20px;background:#f9fafb;border:1px solid #e5e7eb;font-size:12px;font-weight:600">
                                        <span style="width:12px;height:12px;border-radius:50%;background:<?= $hex ?>;border:1px solid #d1d5db;display:inline-block"></span>
                                        <?= htmlspecialchars($p['color']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- قواعد تناسق الألوان -->
        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="bi bi-arrow-left-right me-2"></i>قواعد تناسق الألوان (color_rules)</h5>
            </div>
            <div class="p-3">
                <?php
                $rules = $db->query("SELECT base_color, STRING_AGG(complementary_color, ', ' ORDER BY complementary_color) AS complements FROM color_rules GROUP BY base_color ORDER BY base_color")->fetchAll();
                $hexMap = ['black'=>'#111','white'=>'#f9f9f9','navy'=>'#1e3a5f','gray'=>'#9ca3af','beige'=>'#d4b896','brown'=>'#7c5c3e','camel'=>'#c19a6b','green'=>'#16a34a','pink'=>'#ec4899','red'=>'#dc2626','blue'=>'#3b82f6','yellow'=>'#eab308','orange'=>'#f97316','purple'=>'#8b5cf6'];
                foreach ($rules as $r): ?>
                    <div class="d-flex align-items-center gap-2 py-1 border-bottom">
                        <span style="width:14px;height:14px;border-radius:50%;background:<?= $hexMap[$r['base_color']] ?? '#e5e7eb' ?>;border:1px solid #d1d5db;flex-shrink:0"></span>
                        <span class="fw-600" style="width:70px;font-size:13px"><?= htmlspecialchars($r['base_color']) ?></span>
                        <span style="color:#9ca3af;font-size:12px">→</span>
                        <span style="font-size:12px;color:#374151"><?= htmlspecialchars($r['complements']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 3: الكريديت
══════════════════════════════════════════════════════════ -->
<div id="tab-credits" style="display:none">
<div class="row g-4">

    <div class="col-xl-4 col-lg-5">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-coin me-2"></i>منح كريديت لمستخدم</h5>
            </div>
            <div class="p-4">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="grant_credits">

                    <div class="mb-3">
                        <label class="form-label fw-600">المستخدم</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">— اختر مستخدماً —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= htmlspecialchars($u['name']) ?>
                                    (<?= htmlspecialchars($u['email']) ?>)
                                    — رصيد: <?= (int)$u['tryon_credits'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-600">عدد الكريديت</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="range" name="credits" id="creditsRange"
                                   min="1" max="20" step="1" value="3"
                                   class="form-range flex-grow-1"
                                   oninput="document.getElementById('creditsVal').textContent = this.value">
                            <span id="creditsVal" class="fw-700" style="min-width:28px;color:#111827">3</span>
                        </div>
                        <div class="d-flex justify-content-between mt-1" style="font-size:10px;color:#9ca3af">
                            <span>1</span><span>10</span><span>20</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg w-100">
                        <i class="bi bi-gift me-2"></i>منح الكريديت
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8 col-lg-7">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-people me-2"></i>رصيد المستخدمين</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr><th>#</th><th>المستخدم</th><th>الرصيد المتبقي</th><th>إجمالي الاستخدام</th><th>الحالة</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td style="font-size:12px;color:#9ca3af"><?= (int)$u['id'] ?></td>
                            <td>
                                <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($u['name']) ?></div>
                                <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($u['email']) ?></div>
                            </td>
                            <td>
                                <?php $c = (int)$u['tryon_credits']; ?>
                                <span style="font-size:15px;font-weight:700;color:<?= $c > 0 ? '#059669' : '#dc2626' ?>">
                                    <?= $c ?>
                                </span>
                                <span style="font-size:11px;color:#9ca3af"> كريديت</span>
                            </td>
                            <td style="font-size:13px"><?= (int)$u['tryon_total_used'] ?> تجربة</td>
                            <td>
                                <?php if ($u['tryon_credits'] > 0): ?>
                                    <span style="font-size:11px;padding:3px 8px;border-radius:20px;background:#dcfce7;color:#166534;font-weight:600">🟢 نشط</span>
                                <?php else: ?>
                                    <span style="font-size:11px;padding:3px 8px;border-radius:20px;background:#fee2e2;color:#991b1b;font-weight:600">🔴 نفد الرصيد</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 4: الإحصاءات
══════════════════════════════════════════════════════════ -->
<div id="tab-stats" style="display:none">
<div class="row g-4">

    <!-- أكثر المنتجات تجربةً -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-trophy me-2" style="color:#f59e0b"></i>أكثر المنتجات تجربةً</h5>
            </div>
            <div class="p-3">
                <?php if (empty($topProducts)): ?>
                    <p class="text-muted text-center py-4">لا توجد بيانات بعد</p>
                <?php else: ?>
                    <?php foreach ($topProducts as $i => $tp):
                        $thumb = $tp['image_url'] ?? '';
                        if ($thumb && str_contains($thumb, 'cloudinary.com'))
                            $thumb = str_replace('/upload/', '/upload/w_80,h_80,c_fill,q_auto,f_auto/', $thumb);
                        $medals = ['🥇', '🥈', '🥉'];
                    ?>
                        <div class="d-flex align-items-center gap-3 py-2 <?= $i < count($topProducts)-1 ? 'border-bottom' : '' ?>">
                            <span style="font-size:20px"><?= $medals[$i] ?? ($i+1) ?></span>
                            <?php if ($thumb): ?>
                                <img src="<?= htmlspecialchars($thumb) ?>" style="width:40px;height:40px;border-radius:8px;object-fit:cover" alt="">
                            <?php else: ?>
                                <div style="width:40px;height:40px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center">
                                    <i class="bi bi-image" style="color:#9ca3af"></i>
                                </div>
                            <?php endif; ?>
                            <div style="flex:1">
                                <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($tp['name']) ?></div>
                            </div>
                            <div class="fw-700" style="color:#111827"><?= (int)$tp['tries'] ?> <span style="font-size:11px;color:#9ca3af;font-weight:400">تجربة</span></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ملخص -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-graph-up me-2"></i>ملخص الأداء</h5>
            </div>
            <div class="p-4">
                <?php
                $total      = (int)($stats['total_tryons'] ?? 0);
                $successful = (int)($stats['successful']   ?? 0);
                $refunded   = (int)($stats['refunded']     ?? 0);
                $rate       = $total > 0 ? round($successful / $total * 100) : 0;
                ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-size:13px">معدل النجاح</span>
                        <span class="fw-700"><?= $rate ?>%</span>
                    </div>
                    <div class="progress" style="height:10px;border-radius:99px">
                        <div class="progress-bar bg-success" style="width:<?= $rate ?>%;border-radius:99px"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded text-center" style="background:#f9fafb;border:1px solid #e5e7eb">
                            <div style="font-size:22px;font-weight:700;color:#059669"><?= $successful ?></div>
                            <div style="font-size:12px;color:#6b7280">ناجحة</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded text-center" style="background:#f9fafb;border:1px solid #e5e7eb">
                            <div style="font-size:22px;font-weight:700;color:#dc2626"><?= $refunded ?></div>
                            <div style="font-size:12px;color:#6b7280">مُستردة</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded text-center" style="background:#f9fafb;border:1px solid #e5e7eb">
                            <div style="font-size:22px;font-weight:700"><?= count($pairings) ?></div>
                            <div style="font-size:12px;color:#6b7280">روابط نشطة</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded text-center" style="background:#f9fafb;border:1px solid #e5e7eb">
                            <div style="font-size:22px;font-weight:700"><?= count($noColorProducts) ?></div>
                            <div style="font-size:12px;color:#6b7280">بدون لون</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<script>
// ── التبديل بين التابات ──────────────────────────────────
function switchTab(tab, btn) {
    ['pairings','colors','credits','stats'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === tab ? '' : 'none';
    });
    document.querySelectorAll('.ai-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── معاينة المنتج في نموذج الربط ───────────────────────
function updatePreview() {
    updateSinglePreview('product1Select', 'preview1', 'preview1Img', 'preview1Name', 'preview1Cat');
    updateSinglePreview('product2Select', 'preview2', 'preview2Img', 'preview2Name', 'preview2Cat');
}

function updateSinglePreview(selectId, previewId, imgId, nameId, catId) {
    const sel = document.getElementById(selectId);
    const opt = sel.options[sel.selectedIndex];
    const preview = document.getElementById(previewId);
    if (!opt || !opt.value) { preview.classList.add('d-none'); return; }
    const img = opt.getAttribute('data-img') || '';
    const cat = opt.getAttribute('data-cat') || '';
    document.getElementById(imgId).src = img || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44"><rect fill="%23f3f4f6" width="44" height="44"/></svg>';
    document.getElementById(nameId).textContent = opt.text.split('—')[0].trim();
    document.getElementById(catId).textContent  = cat;
    preview.classList.remove('d-none');
}

// ── فلترة الروابط ────────────────────────────────────────
function filterPairings(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.pairing-row').forEach(row => {
        row.style.display = row.dataset.names.includes(q) ? '' : 'none';
    });
}

// ── اختيار اللون من اللوحة ──────────────────────────────
function pickColor(name) {
    document.getElementById('colorInput').value = name;
    document.querySelectorAll('.color-pick-btn').forEach(b => {
        b.style.borderColor = '#e5e7eb';
        b.style.background  = '';
    });
    event.currentTarget.style.borderColor = '#111827';
    event.currentTarget.style.background  = '#f3f4f6';
}

// ── تأكيد الحذف ──────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm(btn.dataset.confirm)) e.preventDefault();
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
