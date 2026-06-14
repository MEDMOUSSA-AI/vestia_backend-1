<?php
session_start();
require_once __DIR__ . '/includes/db.php';
adminCheck();

$db = db();

// Stats
$totalProducts = $db->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
$totalUsers    = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalOrders   = $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$revenue       = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Completed'")->fetchColumn();
$pendingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status IN ('Packing','Picked','In Transit')")->fetchColumn();
$avgRating     = $db->query('SELECT COALESCE(ROUND(AVG(rating)::numeric,1),0) FROM reviews')->fetchColumn();

// Recent orders
$recentOrders = $db->query(
    "SELECT o.id, o.status, o.total, o.created_at, u.name AS user_name, u.phone
     FROM orders o JOIN users u ON u.id=o.user_id
     ORDER BY o.created_at DESC LIMIT 8"
)->fetchAll();

// Recent users
$recentUsers = $db->query(
    'SELECT id, name, phone, created_at FROM users ORDER BY created_at DESC LIMIT 5'
)->fetchAll();

// Orders by status
$statusCounts = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status"
)->fetchAll();
$statusMap = array_column($statusCounts, 'cnt', 'status');

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<?php $succ = flash('success'); if ($succ): ?>
<div class="alert alert-success" data-auto-dismiss><?= htmlspecialchars($succ) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="bi bi-currency-dollar"></i></div>
      <div>
        <div class="stat-label">Revenue</div>
        <div class="stat-value"><?= formatPrice((float)$revenue) ?></div>
        <div class="stat-delta"><i class="bi bi-arrow-up-short"></i>Completed orders</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-box-seam-fill"></i></div>
      <div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= number_format((int)$totalOrders) ?></div>
        <div class="stat-delta" style="color:#d97706"><i class="bi bi-clock"></i> <?= (int)$pendingOrders ?> pending</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#faf5ff;color:#7c3aed"><i class="bi bi-people-fill"></i></div>
      <div>
        <div class="stat-label">Customers</div>
        <div class="stat-value"><?= number_format((int)$totalUsers) ?></div>
        <div class="stat-delta">Registered accounts</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fefce8;color:#d97706"><i class="bi bi-star-fill"></i></div>
      <div>
        <div class="stat-label">Avg Rating</div>
        <div class="stat-value"><?= number_format((float)$avgRating, 1) ?></div>
        <div class="stat-delta"><?= (int)$totalProducts ?> active products</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header justify-content-between">
        <h5><i class="bi bi-box-seam me-2"></i>Recent Orders</h5>
        <a href="/admin/orders.php" class="btn btn-sm btn-outline-secondary">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr>
            <th>#ID</th><th>Customer</th><th>Status</th><th>Total</th><th>Date</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <?php $sc = ['Packing'=>'bs-packing','Picked'=>'bs-picked','In Transit'=>'bs-transit','Completed'=>'bs-completed','Cancelled'=>'bs-cancelled'][$o['status']] ?? ''; ?>
            <tr>
              <td class="fw-600">#<?= (int)$o['id'] ?></td>
              <td>
                <div class="fw-600" style="font-size:13px"><?= htmlspecialchars($o['user_name']) ?></div>
                <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($o['phone']) ?></div>
              </td>
              <td><span class="badge-status <?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($o['status']) ?></span></td>
              <td class="fw-700"><?= formatPrice((float)$o['total']) ?></td>
              <td style="font-size:12px;color:#9ca3af"><?= htmlspecialchars(timeAgo($o['created_at'])) ?></td>
              <td><a href="/admin/orders.php?id=<?= (int)$o['id'] ?>" class="btn-icon"><i class="bi bi-eye"></i></a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($recentOrders)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No orders yet</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card mb-4">
      <div class="card-header"><h5><i class="bi bi-pie-chart me-2"></i>Order Status</h5></div>
      <div class="p-3">
        <?php
        $statuses = ['Packing'=>['bs-packing','Packing'],'Picked'=>['bs-picked','Picked'],'In Transit'=>['bs-transit','In Transit'],'Completed'=>['bs-completed','Completed'],'Cancelled'=>['bs-cancelled','Cancelled']];
        foreach ($statuses as $key => [$cls, $label]):
          $cnt = (int)($statusMap[$key] ?? 0);
          $pct = $totalOrders > 0 ? round($cnt / $totalOrders * 100) : 0;
        ?>
        <div class="d-flex align-items-center mb-2">
          <span class="badge-status <?= $cls ?> me-2" style="min-width:80px;text-align:center"><?= htmlspecialchars($label) ?></span>
          <div class="flex-1 me-2" style="flex:1">
            <div style="height:6px;background:#f3f4f6;border-radius:4px">
              <div style="height:6px;width:<?= (int)$pct ?>%;background:#111;border-radius:4px"></div>
            </div>
          </div>
          <span style="font-size:13px;font-weight:600;min-width:20px;text-align:right"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header justify-content-between">
        <h5><i class="bi bi-person-plus me-2"></i>New Customers</h5>
        <a href="/admin/users.php" class="btn btn-sm btn-outline-secondary">View all</a>
      </div>
      <div class="p-2">
        <?php foreach ($recentUsers as $u): ?>
        <div class="d-flex align-items-center p-2 rounded" style="gap:12px">
          <div style="width:36px;height:36px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#555">
            <?= htmlspecialchars(strtoupper(substr($u['name'], 0, 1))) ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['name']) ?></div>
            <div style="font-size:11px;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['phone']) ?></div>
          </div>
          <div style="font-size:11px;color:#9ca3af;flex-shrink:0"><?= htmlspecialchars(timeAgo($u['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recentUsers)): ?>
          <p class="text-center text-muted py-3 mb-0" style="font-size:13px">No customers yet</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
