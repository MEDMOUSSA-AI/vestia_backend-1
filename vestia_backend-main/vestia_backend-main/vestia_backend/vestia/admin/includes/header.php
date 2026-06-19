<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?? 'Admin' ?> — VESTIA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
  --sidebar-w: 240px;
  --brand:     #111111;
  --accent:    #111111;
  --muted:     #6b7280;
  --border:    #e5e7eb;
  --bg:        #f9fafb;
  --white:     #ffffff;
  --success:   #16a34a;
  --warning:   #d97706;
  --danger:    #dc2626;
  --info:      #2563eb;
}
* { box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: #111; margin: 0; }

/* Sidebar */
.sidebar {
  position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
  background: var(--brand); color: #fff; display: flex; flex-direction: column;
  z-index: 100; overflow-y: auto;
}
.sidebar-brand {
  padding: 24px 20px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sidebar-brand .logo-mark {
  width: 32px; height: 32px; background: #fff; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  margin-right: 10px;
}
.sidebar-brand .logo-mark svg { width: 18px; height: 18px; }
.sidebar-brand span { font-weight: 800; font-size: 15px; letter-spacing: 3px; }
.sidebar-brand small { display: block; font-size: 9px; letter-spacing: 4px; color: rgba(255,255,255,0.4); margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 16px 0; }
.nav-label { font-size: 10px; font-weight: 700; letter-spacing: 2px; color: rgba(255,255,255,0.35); padding: 8px 20px 4px; text-transform: uppercase; }
.sidebar-nav a {
  display: flex; align-items: center; gap: 12px; padding: 11px 20px;
  color: rgba(255,255,255,0.7); text-decoration: none; font-size: 14px; font-weight: 500;
  border-left: 3px solid transparent; transition: all .15s;
}
.sidebar-nav a:hover { color: #fff; background: rgba(255,255,255,0.07); }
.sidebar-nav a.active { color: #fff; background: rgba(255,255,255,0.1); border-left-color: #fff; }
.sidebar-nav a i { font-size: 17px; width: 20px; text-align: center; }

/* AI nav item — توهج بنفسجي خفيف */
.sidebar-nav a.nav-ai {
  color: rgba(167,139,250,0.85);
}
.sidebar-nav a.nav-ai:hover {
  color: #c4b5fd;
  background: rgba(139,92,246,0.12);
}
.sidebar-nav a.nav-ai.active {
  color: #c4b5fd;
  background: rgba(139,92,246,0.18);
  border-left-color: #8b5cf6;
}

.sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); font-size: 13px; color: rgba(255,255,255,0.5); }

/* Main */
.main { margin-left: var(--sidebar-w); min-height: 100vh; }
.topbar {
  height: 60px; background: var(--white); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 28px; gap: 16px;
  position: sticky; top: 0; z-index: 50;
}
.topbar-title { font-size: 17px; font-weight: 700; color: #111; flex: 1; }
.topbar-admin { font-size: 13px; color: var(--muted); }
.content { padding: 28px; }

/* Cards */
.stat-card {
  background: var(--white); border: 1px solid var(--border); border-radius: 14px;
  padding: 22px 24px; display: flex; align-items: center; gap: 18px;
}
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
.stat-value { font-size: 26px; font-weight: 800; color: #111; line-height: 1; margin-top: 4px; }
.stat-delta { font-size: 12px; color: var(--success); margin-top: 4px; }

/* Table */
.card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.card-header { padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
.card-header h5 { margin: 0; font-size: 15px; font-weight: 700; }
.table { margin: 0; }
.table thead th { background: #f8f9fa; font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); padding: 12px 16px; white-space: nowrap; }
.table tbody td { padding: 13px 16px; vertical-align: middle; font-size: 14px; border-bottom: 1px solid #f3f4f6; }
.table tbody tr:last-child td { border-bottom: none; }
.table tbody tr:hover td { background: #fafafa; }

/* Badges */
.badge-status { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.bs-packing   { background: #fef3c7; color: #92400e; }
.bs-picked    { background: #dbeafe; color: #1e40af; }
.bs-transit   { background: #ede9fe; color: #5b21b6; }
.bs-completed { background: #dcfce7; color: #166534; }
.bs-cancelled { background: #fee2e2; color: #991b1b; }

/* Buttons */
.btn-dark { background: #111; border-color: #111; }
.btn-dark:hover { background: #333; border-color: #333; }
.btn-sm { font-size: 12px; padding: 5px 12px; }
.btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 14px; border: 1px solid var(--border); background: var(--white); color: #555; text-decoration: none; }
.btn-icon:hover { background: #f3f4f6; color: #111; }
.btn-icon.danger { border-color: #fecaca; color: var(--danger); }
.btn-icon.danger:hover { background: #fee2e2; }

/* Form */
.form-label { font-size: 13px; font-weight: 600; color: #374151; }
.form-control, .form-select { font-size: 14px; border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; }
.form-control:focus, .form-select:focus { border-color: #111; box-shadow: 0 0 0 3px rgba(0,0,0,0.06); }

/* Product image thumb */
.product-thumb { width: 42px; height: 48px; object-fit: cover; border-radius: 8px; background: #f3f4f6; }
.product-thumb-placeholder { width: 42px; height: 48px; background: #f3f4f6; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #ccc; font-size: 18px; }

/* Alert */
.alert { border-radius: 10px; font-size: 14px; }
.alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
.alert-danger  { background: #fef2f2; border-color: #fecaca; color: #991b1b; }

/* Pagination */
.pagination .page-link { border-radius: 8px; margin: 0 2px; font-size: 13px; color: #111; border-color: var(--border); }
.pagination .page-item.active .page-link { background: #111; border-color: #111; }

/* fw helpers */
.fw-500 { font-weight: 500; }
.fw-600 { font-weight: 600; }
.fw-700 { font-weight: 700; }
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-brand">
    <div class="d-flex align-items-center">
      <div class="logo-mark">
        <svg viewBox="0 0 24 24" fill="#111"><path d="M8 0h8v8h8v8h-8v8H8v-8H0V8h8z"/></svg>
      </div>
      <div>
        <span>VESTIA</span>
        <small>COUTURE ADMIN</small>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a href="/admin/dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
      <i class="bi bi-grid-1x2-fill"></i> Dashboard
    </a>

    <div class="nav-label mt-2">Catalog</div>
    <a href="/admin/products.php" class="<?= str_contains($_SERVER['PHP_SELF'], 'product') ? 'active' : '' ?>">
      <i class="bi bi-bag-fill"></i> Products
    </a>
    <a href="/admin/categories.php" class="<?= str_contains($_SERVER['PHP_SELF'], 'categor') ? 'active' : '' ?>">
      <i class="bi bi-tags-fill"></i> Categories
    </a>

    <div class="nav-label mt-2">Sales</div>
    <a href="/admin/orders.php" class="<?= str_contains($_SERVER['PHP_SELF'], 'order') ? 'active' : '' ?>">
      <i class="bi bi-box-seam-fill"></i> Orders
    </a>
    <a href="/admin/reviews.php" class="<?= str_contains($_SERVER['PHP_SELF'], 'review') ? 'active' : '' ?>">
      <i class="bi bi-star-fill"></i> Reviews
    </a>

    <div class="nav-label mt-2">Users</div>
    <a href="/admin/users.php" class="<?= str_contains($_SERVER['PHP_SELF'], 'user') ? 'active' : '' ?>">
      <i class="bi bi-people-fill"></i> Customers
    </a>

    <div class="nav-label mt-2">AI Features</div>
    <a href="/admin/fashion_ai.php"
       class="nav-ai <?= str_contains($_SERVER['PHP_SELF'], 'fashion_ai') ? 'active' : '' ?>">
      <i class="bi bi-magic"></i> Fashion AI
    </a>

  </nav>

  <div class="sidebar-footer">
    <div class="fw-600 text-white mb-1" style="font-size:13px"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
    <a href="/admin/logout.php" class="text-danger text-decoration-none" style="font-size:12px"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?= $pageTitle ?? '' ?></div>
    <div class="topbar-admin"><i class="bi bi-circle-fill text-success me-1" style="font-size:8px"></i>Admin Panel</div>
  </div>
  <div class="content">
