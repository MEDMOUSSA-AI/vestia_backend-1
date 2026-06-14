<?php
session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name   = sanitize($_POST['name']    ?? '');
        $nameAr = sanitize($_POST['name_ar'] ?? '');
        $nameFr = sanitize($_POST['name_fr'] ?? '');

        // ✅ التحقق من الطول
        if (strlen($name) < 2 || strlen($name) > 100) {
            flash('error', 'Category name must be between 2 and 100 characters.');
            header('Location: /admin/categories.php'); exit;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        $slug = trim($slug, '-'); // ✅ إزالة الشرطات من البداية والنهاية

        $maxOrder = $db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories')->fetchColumn();
        try {
            $db->prepare('INSERT INTO categories (name, name_ar, name_fr, slug, sort_order) VALUES (?,?,?,?,?)')
               ->execute([$name, $nameAr ?: null, $nameFr ?: null, $slug, $maxOrder]);
            flash('success', 'Category added!');
        } catch (PDOException $e) {
            flash('error', 'Category already exists.');
        }
        header('Location: /admin/categories.php'); exit;
    }

    if ($action === 'edit') {
        $id     = (int)$_POST['id'];
        $name   = sanitize($_POST['name']    ?? '');
        $nameAr = sanitize($_POST['name_ar'] ?? '');
        $nameFr = sanitize($_POST['name_fr'] ?? '');

        // ✅ التحقق من الـ ID والطول
        if (!$id) { flash('error', 'Invalid category.'); header('Location: /admin/categories.php'); exit; }
        if (strlen($name) < 2 || strlen($name) > 100) {
            flash('error', 'Category name must be between 2 and 100 characters.');
            header('Location: /admin/categories.php'); exit;
        }

        $db->prepare('UPDATE categories SET name=?, name_ar=?, name_fr=? WHERE id=?')
           ->execute([$name, $nameAr ?: null, $nameFr ?: null, $id]);
        flash('success', 'Category updated!');
        header('Location: /admin/categories.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        // ✅ التحقق من الـ ID
        if (!$id) { flash('error', 'Invalid category.'); header('Location: /admin/categories.php'); exit; }

        $db->prepare("DELETE FROM categories WHERE id=? AND slug != 'all'")->execute([$id]);
        flash('success', 'Category deleted.');
        header('Location: /admin/categories.php'); exit;
    }
}

// Edit mode
$editCat = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit']; // ✅ تحويل لـ int مباشرة
    if ($editId) {
        $stmt = $db->prepare('SELECT * FROM categories WHERE id=?');
        $stmt->execute([$editId]);
        $editCat = $stmt->fetch();
    }
}

$categories = $db->query(
    'SELECT c.*, COUNT(p.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id=c.id AND p.is_active=1
     GROUP BY c.id, c.name, c.name_ar, c.name_fr, c.slug, c.sort_order
     ORDER BY c.sort_order'
)->fetchAll();

$pageTitle = 'Categories';
include __DIR__ . '/includes/header.php';
?>

<?php $succ=flash('success'); $err=flash('error'); ?>
<?php if($succ): ?><div class="alert alert-success" data-auto-dismiss><?= htmlspecialchars($succ) ?></div><?php endif; ?>
<?php if($err):  ?><div class="alert alert-danger"  data-auto-dismiss><?= htmlspecialchars($err)  ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header">
        <h5><i class="bi bi-<?= $editCat ? 'pencil' : 'plus-circle' ?> me-2"></i><?= $editCat ? 'Edit Category' : 'Add Category' ?></h5>
        <?php if ($editCat): ?>
          <a href="/admin/categories.php" class="btn btn-sm btn-outline-secondary ms-auto">Cancel</a>
        <?php endif; ?>
      </div>
      <div class="p-4">
        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="<?= $editCat ? 'edit' : 'add' ?>">
          <?php if ($editCat): ?>
            <input type="hidden" name="id" value="<?= (int)$editCat['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Category Name (EN) *</label>
            <input type="text" name="name" class="form-control" maxlength="100"
                   placeholder="e.g. Dresses"
                   value="<?= htmlspecialchars($editCat['name'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">الاسم بالعربية <span class="text-muted" style="font-size:12px">(اختياري)</span></label>
            <input type="text" name="name_ar" class="form-control" dir="rtl" maxlength="100"
                   placeholder="مثال: فساتين"
                   value="<?= htmlspecialchars($editCat['name_ar'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Nom en Français <span class="text-muted" style="font-size:12px">(optionnel)</span></label>
            <input type="text" name="name_fr" class="form-control" maxlength="100"
                   placeholder="ex: Robes"
                   value="<?= htmlspecialchars($editCat['name_fr'] ?? '') ?>">
          </div>

          <button type="submit" class="btn btn-dark w-100">
            <i class="bi bi-<?= $editCat ? 'check2' : 'plus-lg' ?> me-2"></i><?= $editCat ? 'Save Changes' : 'Add Category' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><h5><i class="bi bi-tags me-2"></i>All Categories</h5></div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Name (EN)</th>
              <th>عربي</th>
              <th>Français</th>
              <th>Slug</th>
              <th>Products</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($categories as $cat): ?>
            <tr>
              <td class="fw-600"><?= htmlspecialchars($cat['name']) ?></td>
              <td dir="rtl" style="font-size:13px"><?= htmlspecialchars($cat['name_ar'] ?? '—') ?></td>
              <td style="font-size:13px"><?= htmlspecialchars($cat['name_fr'] ?? '—') ?></td>
              <td><code style="font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px"><?= htmlspecialchars($cat['slug']) ?></code></td>
              <td><?= (int)$cat['product_count'] ?></td>
              <td>
                <?php if ($cat['slug'] !== 'all'): ?>
                <div class="d-flex gap-1">
                  <a href="/admin/categories.php?edit=<?= (int)$cat['id'] ?>" class="btn-icon">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                    <button type="submit" class="btn-icon danger"
                            data-confirm="Delete '<?= htmlspecialchars($cat['name']) ?>'? Products will be unassigned.">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
                <?php else: ?>
                  <span class="text-muted" style="font-size:12px">Default</span>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
