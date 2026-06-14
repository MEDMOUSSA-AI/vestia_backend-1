<?php
session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();
$db = db();

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id     = (int)$_POST['id'];
    $action = $_POST['action'] ?? '';
    if ($id && $action === 'toggle') {
        $cur = $db->prepare('SELECT is_active FROM users WHERE id=?');
        $cur->execute([$id]);
        $curVal = $cur->fetchColumn();
        if ($curVal !== false) {
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$curVal ? 0 : 1, $id]);
            flash('success', 'Customer status updated.');
        }
    }
    header('Location: /admin/users.php'); exit;
}

$search = mb_substr(trim($_GET['search'] ?? ''), 0, 100);
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(name ILIKE ? OR phone ILIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$whereSQL = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereSQL");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));
$page  = min($page, $pages);

$stmt = $db->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM orders WHERE user_id=u.id) AS order_count,
            (SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id=u.id AND status='Completed') AS total_spent
     FROM users u WHERE $whereSQL ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Customers';
include __DIR__ . '/includes/header.php';
?>

<?php $succ=flash('success'); if($succ): ?>
<div class="alert alert-success" data-auto-dismiss><?= htmlspecialchars($succ) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header justify-content-between flex-wrap gap-2">
    <h5><i class="bi bi-people me-2"></i>Customers <span class="text-muted fw-normal" style="font-size:13px">(<?= (int)$total ?>)</span></h5>
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="search" class="form-control form-control-sm" maxlength="100"
             placeholder="Name or phone..." value="<?= htmlspecialchars($search) ?>" style="width:240px">
      <button class="btn btn-sm btn-dark">Search</button>
      <?php if($search): ?>
        <a href="/admin/users.php" class="btn btn-sm btn-outline-secondary">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Customer</th><th>Orders</th><th>Total Spent</th><th>Joined</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div style="width:36px;height:36px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#555;flex-shrink:0">
                <?= htmlspecialchars(strtoupper(substr($u['name'], 0, 1))) ?>
              </div>
              <div>
                <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($u['name']) ?></div>
                <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($u['phone']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:13px"><?= (int)$u['order_count'] ?> order<?= (int)$u['order_count']!=1?'s':'' ?></td>
          <td class="fw-600" style="font-size:13px"><?= formatPrice((float)$u['total_spent']) ?></td>
          <td style="font-size:12px;color:#9ca3af"><?= htmlspecialchars(date('M j, Y', strtotime($u['created_at']))) ?></td>
          <td>
            <span style="font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;
                         background:<?= $u['is_active']?'#dcfce7':'#fee2e2' ?>;
                         color:<?= $u['is_active']?'#166534':'#991b1b' ?>">
              <?= $u['is_active'] ? 'Active' : 'Suspended' ?>
            </span>
          </td>
          <td>
            <form method="POST" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button type="submit"
                      class="btn btn-sm <?= $u['is_active']?'btn-outline-danger':'btn-outline-success' ?>"
                      data-confirm="<?= $u['is_active']?'Suspend this customer?':'Re-activate this customer?' ?>">
                <?= $u['is_active'] ? 'Suspend' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($users)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No customers found</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="p-3 d-flex justify-content-center">
    <nav><ul class="pagination mb-0">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <li class="page-item <?= $i==$page?'active':'' ?>">
          <a class="page-link" href="?page=<?= (int)$i ?>&search=<?= urlencode($search) ?>"><?= (int)$i ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
