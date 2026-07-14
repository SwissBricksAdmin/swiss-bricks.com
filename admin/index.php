<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Dashboard';
require_once __DIR__ . '/layout.php';

// Stats
$totalOrders    = $conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];
$totalRevenue   = $conn->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status!='cancelled'")->fetch_row()[0];
$totalProducts  = $conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$totalUsers     = $conn->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetch_row()[0];
$pendingOrders  = $conn->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetch_row()[0];
$recentOrders   = $conn->query("SELECT o.*, CONCAT(u.first_name,' ',u.last_name) AS customer FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 8");
$topProducts    = $conn->query("SELECT p.name, p.price, p.stock, p.badge, SUM(oi.quantity) AS total_sold FROM order_items oi JOIN products p ON oi.product_id=p.id GROUP BY p.id ORDER BY total_sold DESC LIMIT 5");
?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-card-top">
      <div>
        <div class="stat-value"><?= formatPrice($totalRevenue) ?></div>
        <div class="stat-label">Total Revenue</div>
      </div>
      <div class="stat-icon" style="background:rgba(237,30,40,0.12);">💰</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div>
        <div class="stat-value"><?= $totalOrders ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-icon" style="background:rgba(59,130,246,0.12);">📦</div>
    </div>
    <?php if ($pendingOrders > 0): ?>
      <div style="font-size:0.78rem;color:#f59e0b;margin-top:8px;"><?= $pendingOrders ?> pending</div>
    <?php endif; ?>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div>
        <div class="stat-value"><?= $totalProducts ?></div>
        <div class="stat-label">Products Listed</div>
      </div>
      <div class="stat-icon" style="background:rgba(16,185,129,0.12);">🧱</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div>
        <div class="stat-value"><?= $totalUsers ?></div>
        <div class="stat-label">Registered Members</div>
      </div>
      <div class="stat-icon" style="background:rgba(139,92,246,0.12);">👥</div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

  <!-- Recent orders -->
  <div class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-title">Recent Orders</div>
      <a href="<?= BASE_URL ?>/admin/orders.php" class="btn btn-outline btn-sm">View All</a>
    </div>
    <table class="admin-table">
      <thead><tr>
        <th>Order #</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th>
      </tr></thead>
      <tbody>
      <?php while ($o = $recentOrders->fetch_assoc()): ?>
        <tr>
          <td style="font-weight:700;color:var(--accent);"><?= htmlspecialchars($o['order_number']) ?></td>
          <td><?= htmlspecialchars($o['customer'] ?: 'Guest') ?></td>
          <td style="font-weight:700;"><?= formatPrice($o['total_amount']) ?></td>
          <td><?= getStatusBadge($o['status']) ?></td>
          <td style="color:var(--text3);"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- Top products -->
  <div class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-title">Top Sellers</div>
    </div>
    <table class="admin-table">
      <thead><tr><th>Product</th><th>Sold</th></tr></thead>
      <tbody>
      <?php while ($p = $topProducts->fetch_assoc()): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:0.82rem;"><?= htmlspecialchars(substr($p['name'],0,32)) ?></div>
            <div style="color:var(--text3);font-size:0.75rem;"><?= formatPrice($p['price']) ?></div>
          </td>
          <td style="font-weight:700;"><?= $p['total_sold'] ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
