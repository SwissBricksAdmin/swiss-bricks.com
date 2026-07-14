<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user    = getCurrentUser($conn);
$success = '';
$error   = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name']  ?? '');
    $email      = sanitize($_POST['email']      ?? '');
    $phone      = sanitize($_POST['phone']      ?? '');
    $flat_room   = sanitize($_POST['flat_room']   ?? '');
    $street      = sanitize($_POST['street']      ?? '');
    $postal_code = sanitize($_POST['postal_code'] ?? '');
    $city        = sanitize($_POST['city']        ?? '');
    $country     = sanitize($_POST['country']     ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($email !== $user['email']) {
        $chk = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=? LIMIT 1");
        $chk->bind_param('si', $email, $user['id']);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'That email address is already in use by another account.';
        }
    }

    if (!$error) {
        $emailChanged = ($email !== $user['email']);
        $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, flat_room=?, street=?, postal_code=?, city=?, country=? WHERE id=?");
        $stmt->bind_param('ssssssssi', $first_name, $last_name, $phone, $flat_room, $street, $postal_code, $city, $country, $user['id']);
        $stmt->execute();
        if ($emailChanged) {
            $conn->execute_query("UPDATE users SET pending_email=? WHERE id=?", [$email, $user['id']]);
            sendVerificationEmail($conn, $user['id'], $email, $first_name, 'email');
            $success = 'Profile updated. A verification link has been sent to ' . htmlspecialchars($email) . ' — click it to confirm your new email address.';
        } else {
            $success = 'Profile updated successfully.';
        }
        $user = getCurrentUser($conn);
    }
}

// Handle account deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    session_destroy();
    header('Location: ' . BASE_URL . '/');
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current   = $_POST['current_password']  ?? '';
    $new_pass  = $_POST['new_password']      ?? '';
    $confirm   = $_POST['confirm_password']  ?? '';

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new_pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hashed, $user['id']);
        $stmt->execute();
        $success = 'Password updated successfully.';
    }
}

// Fetch orders
$orders = $conn->query("SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) AS item_count FROM orders o WHERE o.user_id={$user['id']} ORDER BY o.created_at DESC");
// Pre-fetch all order items for this user's orders
$orderItems = [];
$itemsResult = $conn->query("SELECT oi.order_id, oi.quantity, oi.price, p.name FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id IN (SELECT id FROM orders WHERE user_id={$user['id']})");
if ($itemsResult) while ($row = $itemsResult->fetch_assoc()) $orderItems[$row['order_id']][] = $row;

// Picture proof requests
$proofRequests = [];
$prRes = $conn->query("SELECT * FROM picture_proof_requests WHERE customer_email='" . $conn->real_escape_string($user['email']) . "' ORDER BY created_at DESC");
if ($prRes) while ($row = $prRes->fetch_assoc()) $proofRequests[] = $row;

// Wishlist items
$wishlistItems = [];
$wRes = $conn->query("SELECT p.*, c.name AS category_name FROM wishlists w JOIN products p ON w.product_id=p.id JOIN categories c ON p.category_id=c.id WHERE w.user_id={$user['id']} ORDER BY w.created_at DESC");
if ($wRes) while ($row = $wRes->fetch_assoc()) $wishlistItems[] = $row;

$initials  = strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1));
$pageTitle = 'My Account';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <nav class="breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a> › <span>My Account</span>
  </nav>

  <div class="account-layout">

    <!-- Sidebar -->
    <aside class="account-sidebar">
      <div class="account-sidebar-top">
        <div class="account-avatar"><?= $initials ?></div>
        <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
        <span style="font-size:0.8rem;color:var(--text3);"><?= htmlspecialchars($user['email']) ?></span>
      </div>
      <nav class="account-nav">
<a href="#" class="account-tab-link" data-tab="orders"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> My Orders</a>
        <a href="#" class="account-tab-link" data-tab="profile"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Profile</a>
        <a href="#" class="account-tab-link" data-tab="wishlist"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> Wishlist</a>
        <a href="#" class="account-tab-link" data-tab="pictureproof" style="display:flex;align-items:center;gap:8px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Picture Proof
          <?php if (count(array_filter($proofRequests, fn($r) => $r['status'] === 'waiting')) > 0): ?>
            <span style="background:rgba(245,158,11,0.2);color:#f59e0b;border-radius:20px;padding:1px 7px;font-size:0.68rem;font-weight:800;"><?= count(array_filter($proofRequests, fn($r) => $r['status'] === 'waiting')) ?></span>
          <?php endif; ?>
        </a>
        <a href="#" class="account-tab-link" data-tab="password"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/></svg> Change Password</a>
        <a href="<?= BASE_URL ?>/pages/logout.php" class="logout-nav"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign Out</a>
      </nav>
    </aside>

    <!-- Content area -->
    <div class="account-content">
      <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

      <!-- Orders tab -->
      <div class="account-tab-content" id="tab-orders" style="display:none;">
        <h2 style="margin-bottom:24px;">My Orders</h2>
        <?php if ($orders && $orders->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="orders-table">
              <thead><tr>
                <th>Order #</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th>Payment</th>
              </tr></thead>
              <tbody>
              <?php while ($o = $orders->fetch_assoc()):
                $oid = $o['id'];
                $status = $o['status'];
                // Timeline: 0=pending/paid, 1=processing, 2=shipped, 3=delivered, -1=cancelled
                $step = match($status) {
                    'pending','paid' => 0,
                    'processing'     => 1,
                    'shipped'        => 2,
                    'delivered'      => 3,
                    default          => -1
                };
                $steps = ['Order Received','Processing','Shipped','Delivered'];
              ?>
                <tr class="order-row-clickable" onclick="toggleMyOrder('my-order-<?= $oid ?>', this)" style="cursor:pointer;">
                  <td style="font-weight:700;color:var(--accent);">
                    <span class="order-num-btn" style="display:inline-flex;align-items:center;gap:6px;">
                      <svg class="my-order-chevron" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
                      <?= htmlspecialchars($o['order_number']) ?>
                    </span>
                  </td>
                  <td><?= date('d M Y H:i', strtotime($o['created_at'])) ?></td>
                  <td><?= $o['item_count'] ?></td>
                  <td style="font-weight:700;"><?= formatPrice($o['total_amount']) ?></td>
                  <td><?= getStatusBadge($o['status']) ?></td>
                  <td style="color:var(--text3);"><?= htmlspecialchars($o['payment_method']) ?></td>
                </tr>
                <tr id="my-order-<?= $oid ?>" style="display:none;">
                  <td colspan="6" style="padding:0;border-top:none;">
                    <div style="background:var(--surface2);border-left:3px solid var(--accent);padding:24px 28px;">

                      <?php if ($step >= 0):
                        $totalSteps = count($steps) - 1;
                        $progressPct = $totalSteps > 0 ? round($step / $totalSteps * 100) : 0;
                      ?>
                      <!-- Timeline -->
                      <div style="margin-bottom:44px;padding:0 24px;">
                        <!-- Each flex item is exactly 18px wide so space-between aligns line endpoints to dot centres -->
                        <div style="display:flex;justify-content:space-between;position:relative;">
                          <!-- Full background line: left=9px (centre of first dot), right=9px (centre of last dot) -->
                          <div style="position:absolute;top:9px;left:9px;right:9px;height:2px;background:var(--border2);z-index:0;"></div>
                          <?php if ($progressPct > 0): ?>
                          <div style="position:absolute;top:9px;left:9px;width:calc((100% - 18px) * <?= $progressPct ?> / 100);height:2px;background:#22c55e;z-index:0;"></div>
                          <?php endif; ?>
                          <?php foreach ($steps as $i => $label):
                            $done    = $i < $step;
                            $current = $i === $step;
                            $dotBg    = $done ? '#22c55e' : ($current ? '#ED1E28' : 'var(--surface)');
                            $dotBorder= ($done || $current) ? 'none' : '2px solid var(--border2)';
                            $labelCol = $done ? '#22c55e' : ($current ? '#ffffff' : 'var(--text3)');
                            $fontW    = $current ? '700' : '400';
                          ?>
                          <div style="width:18px;flex-shrink:0;position:relative;z-index:1;">
                            <div style="width:18px;height:18px;border-radius:50%;background:<?= $dotBg ?>;border:<?= $dotBorder ?>;display:flex;align-items:center;justify-content:center;">
                              <?php if ($done): ?>
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                              <?php elseif ($current): ?>
                                <div style="width:6px;height:6px;border-radius:50%;background:#fff;"></div>
                              <?php endif; ?>
                            </div>
                            <div style="position:absolute;top:26px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:0.7rem;color:<?= $labelCol ?>;font-weight:<?= $fontW ?>;"><?= $label ?></div>
                          </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <?php else: ?>
                      <div style="display:inline-block;background:rgba(239,68,68,0.12);color:#f87171;padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:600;margin-bottom:20px;">Order Cancelled</div>
                      <?php endif; ?>

                      <!-- Items -->
                      <?php if (!empty($orderItems[$oid])): ?>
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin-bottom:8px;">Items</div>
                        <?php foreach ($orderItems[$oid] as $item): ?>
                          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.87rem;">
                            <span style="color:var(--text2);"><?= htmlspecialchars($item['name']) ?> <span style="color:var(--text3);">&times; <?= $item['quantity'] ?></span></span>
                            <span style="font-weight:600;"><?= formatPrice($item['price'] * $item['quantity']) ?></span>
                          </div>
                        <?php endforeach; ?>
                        <div style="display:flex;justify-content:space-between;padding:10px 0 0;font-weight:700;font-size:0.9rem;">
                          <span>Total</span>
                          <span style="color:var(--accent);"><?= formatPrice($o['total_amount']) ?></span>
                        </div>
                      <?php endif; ?>

                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:48px;">
            <p style="color:var(--text3);margin-bottom:20px;">You haven't placed any orders yet.</p>
            <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary">Shop Now</a>
          </div>
        <?php endif; ?>
        <style>
        .order-num-btn { transition: text-shadow var(--transition); }
        .order-row-clickable:hover .order-num-btn { text-shadow: 0 0 12px rgba(237,30,40,0.6), 0 0 28px rgba(237,30,40,0.25); }
        </style>
        <script>
        function toggleMyOrder(id, row) {
          const detail = document.getElementById(id);
          const icon   = row.querySelector('.my-order-chevron');
          const open   = detail.style.display === 'none' || detail.style.display === '';
          detail.style.display = open ? 'table-row' : 'none';
          icon.style.transform = open ? 'rotate(180deg)' : 'rotate(0)';
        }
        </script>
      </div>

      <!-- Profile tab -->
      <div class="account-tab-content" id="tab-profile" style="display:none;">
        <h2 style="margin-bottom:24px;">Edit Profile</h2>
        <form id="profile-form" method="POST">
          <div class="form-grid-2">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Flat / Room</label>
            <input type="text" name="flat_room" value="<?= htmlspecialchars($user['flat_room'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Street and House Number</label>
            <input type="text" name="street" value="<?= htmlspecialchars($user['street'] ?? '') ?>">
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label>PLZ (Post Code)</label>
              <input type="text" name="postal_code" value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>City</label>
              <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Country</label>
            <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>">
          </div>
        </form>
        <?php if (!in_array($user['role'], ['admin', 'super_admin'])): ?>
        <form id="delete-form" method="POST" onsubmit="return confirm('Are you sure you want to delete your account? This cannot be undone.');"></form>
        <?php endif; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:28px;">
          <button type="submit" form="profile-form" name="update_profile" class="btn btn-primary">Save Changes</button>
          <?php if (!in_array($user['role'], ['admin', 'super_admin'])): ?>
          <button type="submit" form="delete-form" name="delete_account" style="background:none;border:none;color:var(--accent);font-size:0.82rem;cursor:pointer;padding:0;opacity:0.7;transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">Delete Profile</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Password tab -->
      <div class="account-tab-content" id="tab-password" style="display:none;">
        <h2 style="margin-bottom:24px;">Change Password</h2>
        <form method="POST" style="max-width:400px;">
          <div class="form-group">
            <label>Current Password *</label>
            <input type="password" name="current_password" required>
          </div>
          <div class="form-group">
            <label>New Password * <span style="color:var(--text3);font-weight:400;">(min. 6 characters)</span></label>
            <input type="password" name="new_password" required>
          </div>
          <div class="form-group">
            <label>Confirm New Password *</label>
            <input type="password" name="confirm_password" required>
          </div>
          <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
        </form>
      </div>

      <!-- Wishlist tab -->
      <div class="account-tab-content" id="tab-wishlist" style="display:none;">
        <h2 style="margin-bottom:24px;">My Wishlist</h2>
        <?php if (empty($wishlistItems)): ?>
          <div style="text-align:center;padding:48px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            <p style="color:var(--text3);margin-bottom:20px;">Your wishlist is empty.</p>
            <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary">Browse Products</a>
          </div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;">
            <?php foreach ($wishlistItems as $p): ?>
              <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;display:flex;flex-direction:column;">
                <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>" style="display:block;background:#fff;aspect-ratio:4/3;overflow:hidden;">
                  <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width:100%;height:100%;object-fit:contain;transform:scale(0.9);">
                </a>
                <div style="padding:14px;flex:1;display:flex;flex-direction:column;gap:6px;">
                  <div style="font-size:0.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:0.06em;"><?= htmlspecialchars($p['category_name']) ?></div>
                  <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>" style="color:var(--text);font-weight:600;font-size:0.9rem;line-height:1.3;text-decoration:none;"><?= htmlspecialchars($p['name']) ?></a>
                  <?php
                    $hasDeal = !empty($p['deal_price']) && (empty($p['deal_end']) || strtotime($p['deal_end']) > time());
                    $wPrice  = $hasDeal ? $p['deal_price'] : $p['price'];
                  ?>
                  <div style="font-weight:700;color:var(--accent);"><?= formatPrice($wPrice) ?></div>
                  <?php if ($p['stock'] == 0): ?>
                    <div style="font-size:0.75rem;color:var(--text3);">Out of stock — you'll be notified</div>
                  <?php endif; ?>
                  <div style="margin-top:auto;padding-top:10px;display:flex;gap:8px;">
                    <?php if ($p['stock'] > 0): ?>
                      <button class="add-to-cart-btn btn btn-primary" data-id="<?= $p['id'] ?>" style="flex:1;font-size:0.8rem;padding:8px;">+ Cart</button>
                    <?php endif; ?>
                    <button class="wishlist-remove-btn" data-id="<?= $p['id'] ?>" title="Remove from wishlist"
                      style="background:none;border:1px solid var(--border2);border-radius:var(--radius);padding:8px;cursor:pointer;color:var(--accent);display:flex;align-items:center;justify-content:center;transition:background 0.15s;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="#ED1E28" stroke="#ED1E28" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <script>
          document.querySelectorAll('.wishlist-remove-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
              const id = btn.dataset.id;
              fetch('<?= BASE_URL ?>/pages/wishlist_toggle.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'product_id=' + id
              }).then(() => {
                btn.closest('div[style*="background:var(--surface)"]').remove();
              });
            });
          });
          </script>
        <?php endif; ?>
      </div>

      <!-- Picture Proof tab -->
      <div class="account-tab-content" id="tab-pictureproof" style="display:none;">
        <h2 style="margin-bottom:24px;">Picture Proof Requests</h2>
        <?php if (empty($proofRequests)): ?>
          <div style="text-align:center;padding:48px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <p style="color:var(--text3);margin-bottom:20px;">You haven't requested any picture proof yet.</p>
            <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary">Browse Products</a>
          </div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:16px;">
          <?php foreach ($proofRequests as $pr):
            $isSent   = $pr['status'] === 'sent';
            $proofId  = (int)$pr['id'];
            // Load uploaded photos from DB
            $photos    = $conn->query("SELECT file_path FROM picture_proof_photos WHERE request_id=$proofId ORDER BY id ASC");
            $photoUrls = [];
            if ($isSent && $photos) {
                while ($ph = $photos->fetch_assoc()) {
                    $ext  = strtolower(pathinfo($ph['file_path'], PATHINFO_EXTENSION));
                    $photoUrls[] = [
                        'url'  => BASE_URL . '/' . $ph['file_path'],
                        'heic' => in_array($ext, ['heic', 'heif']),
                    ];
                }
            }
          ?>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
              <!-- Card header -->
              <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;<?= $isSent && !empty($photoUrls) ? 'cursor:pointer;' : '' ?>"
                   <?= $isSent && !empty($photoUrls) ? 'onclick="toggleProof(\'proof-' . $proofId . '\', this)"' : '' ?>>
                <?php if (!empty($pr['product_image'])): ?>
                  <img src="<?= htmlspecialchars($pr['product_image']) ?>" alt="" style="width:52px;height:52px;object-fit:contain;background:#fff;border-radius:8px;flex-shrink:0;">
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:700;font-size:0.95rem;color:var(--text);margin-bottom:2px;"><?= htmlspecialchars($pr['product_name']) ?></div>
                  <div style="font-size:0.8rem;color:var(--accent);font-weight:700;letter-spacing:0.03em;"><?= htmlspecialchars($pr['reference_number'] ?? '') ?></div>
                  <div style="font-size:0.78rem;color:var(--text3);margin-top:2px;"><?= date('d M Y', strtotime($pr['created_at'])) ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                  <?php if ($isSent): ?>
                    <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#22c55e;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);border-radius:20px;padding:3px 10px;">SENT</span>
                    <?php if (!empty($photoUrls)): ?>
                      <svg class="proof-chevron-<?= $proofId ?>" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;"><polyline points="6 9 12 15 18 9"/></svg>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#f59e0b;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);border-radius:20px;padding:3px 10px;">WAITING</span>
                  <?php endif; ?>
                </div>
              </div>
              <!-- Expandable photo grid -->
              <?php if ($isSent && !empty($photoUrls)): ?>
                <div id="proof-<?= $proofId ?>" style="display:none;border-top:1px solid var(--border);padding:20px;">
                  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;">
                    <?php foreach ($photoUrls as $photo): ?>
                      <?php if ($photo['heic']): ?>
                        <a href="<?= htmlspecialchars($photo['url']) ?>" target="_blank" download
                           style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border-radius:8px;aspect-ratio:1;background:var(--surface2);border:1px solid var(--border);text-decoration:none;color:var(--text3);font-size:0.72rem;transition:border-color 0.15s;"
                           onmouseover="this.style.borderColor='#6b6b80'" onmouseout="this.style.borderColor='var(--border)'">
                          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                          Download HEIC
                        </a>
                      <?php else: ?>
                        <div style="position:relative;border-radius:8px;overflow:hidden;background:var(--surface2);aspect-ratio:1;border:1px solid var(--border);">
                          <a href="<?= htmlspecialchars($photo['url']) ?>" target="_blank" style="display:block;width:100%;height:100%;">
                            <img src="<?= htmlspecialchars($photo['url']) ?>" alt="Picture Proof" style="width:100%;height:100%;object-fit:cover;transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                          </a>
                          <a href="<?= htmlspecialchars($photo['url']) ?>" download target="_blank" title="Download"
                             style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,0.65);border-radius:6px;padding:5px;display:flex;align-items:center;justify-content:center;color:#fff;backdrop-filter:blur(4px);transition:background 0.15s;"
                             onmouseover="this.style.background='rgba(237,30,40,0.85)'" onmouseout="this.style.background='rgba(0,0,0,0.65)'">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                          </a>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php elseif ($isSent): ?>
                <div style="border-top:1px solid var(--border);padding:14px 20px;font-size:0.82rem;color:var(--text3);">Photos have been sent to your email.</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
          <script>
          function toggleProof(id, header) {
            const panel   = document.getElementById(id);
            const proofId = id.replace('proof-', '');
            const chevron = document.querySelector('.proof-chevron-' + proofId);
            const open    = panel.style.display === 'none' || panel.style.display === '';
            panel.style.display  = open ? 'block' : 'none';
            if (chevron) chevron.style.transform = open ? 'rotate(180deg)' : 'rotate(0)';
          }
          </script>
        <?php endif; ?>
      </div>

    </div><!-- end account-content -->
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
