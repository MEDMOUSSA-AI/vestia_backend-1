<?php
session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();
$db = db();

// Delete review
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int)$_POST['id'];
    if ($id) {
        $db->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);
        flash('success', 'Review deleted.');
    }
    header('Location: /admin/reviews.php'); exit;
}

// ✅ التحقق من قيمة rating
$ratingFilt = (int)($_GET['rating'] ?? 0);
if ($ratingFilt < 1 || $ratingFilt > 5) $ratingFilt = 0;

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];
if ($ratingFilt) { $where[] = 'r.rating=?'; $params[] = $ratingFilt; }
$whereSQL = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM reviews r WHERE $whereSQL");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));
$page  = min($page, $pages);

$stmt = $db->prepare(
    "SELECT r.*, u.name AS user_name, u.phone, p.name AS product_name, p.image_url
     FROM reviews r
     JOIN users u ON u.id=r.user_id
     JOIN products p ON p.id=r.product_id
     WHERE $whereSQL
     ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Stats
$avgRating  = $db->query('SELECT COALESCE(ROUND(AVG(rating)::numeric,2),0) FROM reviews')->fetchColumn();
$totalCount = (int)$db->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
$dist       = $db->query('SELECT rating, COUNT(*) AS cnt FROM reviews GROUP BY rating ORDER BY rating DESC')->fetchAll();
$distMap    = array_column($dist, 'cnt', 'rating');

$pageTitle = 'Reviews';
include __DIR__ . '/includes/header.php';
?>

<?php $succ=flash('success'); if($succ): ?>
<div class="alert alert-success" data-auto-dismiss><?= htmlspecialchars($succ) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fefce8;color:#d97706"><i class="bi bi-star-fill"></i></div>
      <div>
        <div class="stat-label">Average Rating</div>
        <div class="stat-value"><?= number_format((float)$avgRating, 2) ?> <span style="font-size:16px;color:#9ca3af">/ 5</span></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-chat-square-text-fill"></i></div>
      <div>
        <div class="stat-label">Total Reviews</div>
        <div class="stat-value"><?= number_format($totalCount) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card p-3">
      <?php for($s=5;$s>=1;$s--):
        $cnt = (int)($distMap[$s] ?? 0);
        $pct = $totalCount > 0 ? round($cnt / $totalCount * 100) : 0;
      ?>
      <div class="d-flex align-items-center gap-2 mb-1">
        <span style="font-size:11px;width:8px;color:#111;font-weight:600"><?= $s ?></span>
        <i class="bi bi-star-fill" style="font-size:10px;color:#d97706"></i>
        <div style="flex:1;height:6px;background:#f3f4f6;border-radius:4px">
          <div style="height:6px;width:<?= (int)$pct ?>%;background:#111;border-radius:4px"></div>
        </div>
        <span style="font-size:11px;color:#9ca3af;min-width:22px;text-align:right"><?= $cnt ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header justify-content-between flex-wrap gap-2">
    <h5><i class="bi bi-star me-2"></i>All Reviews <span class="text-muted fw-normal" style="font-size:13px">(<?= (int)$total ?>)</span></h5>
    <form class="d-flex gap-2" method="GET">
      <select name="rating" class="form-select form-select-sm" style="width:140px">
        <option value="">All Ratings</option>
        <?php for($r=5;$r>=1;$r--): ?>
          <option value="<?= $r ?>" <?= $ratingFilt===$r?'selected':'' ?>><?= $r ?> Star<?= $r>1?'s':'' ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-sm btn-dark">Filter</button>
      <?php if($ratingFilt): ?>
        <a href="/admin/reviews.php" class="btn btn-sm btn-outline-secondary">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th>Customer</th><th>Rating</th><th>Review</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($reviews as $rev): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if($rev['image_url']): ?>
                <img src="<?= htmlspecialchars($rev['image_url']) ?>" class="product-thumb" alt="">
              <?php endif; ?>
              <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($rev['product_name']) ?></span>
            </div>
          </td>
          <td>
            <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($rev['user_name']) ?></div>
            <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($rev['phone']) ?></div>
          </td>
          <td>
            <div class="d-flex gap-1">
              <?php for($i=1;$i<=5;$i++): ?>
                <i class="bi bi-star<?= $i<=(int)$rev['rating']?'-fill':'' ?>"
                   style="color:<?= $i<=(int)$rev['rating']?'#d97706':'#e5e7eb' ?>;font-size:13px"></i>
              <?php endfor; ?>
            </div>
          </td>
          <td style="max-width:280px">
            <div style="font-size:13px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= $rev['text'] ? htmlspecialchars($rev['text']) : '<span class="text-muted">No text</span>' ?>
            </div>
          </td>
          <td style="font-size:12px;color:#9ca3af"><?= htmlspecialchars(timeAgo($rev['created_at'])) ?></td>
          <td>
            <form method="POST" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf() ?>">
              <input type="hidden" name="id" value="<?= (int)$rev['id'] ?>">
              <button type="submit" class="btn-icon danger" data-confirm="Delete this review?">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($reviews)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No reviews yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="p-3 d-flex justify-content-center">
    <nav><ul class="pagination mb-0">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <li class="page-item <?= $i==$page?'active':'' ?>">
          <a class="page-link" href="?page=<?= (int)$i ?>&rating=<?= (int)$ratingFilt ?>"><?= (int)$i ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
