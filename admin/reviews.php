<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Reviews';
$msg = ''; $msgType = 'success';

// Release voucher manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_voucher'])) {
    $rid    = (int)$_POST['review_id'];
    $review = $conn->query("SELECT * FROM reviews WHERE id=$rid AND rewarded=0")->fetch_assoc();
    if ($review) {
        $voucherAmt   = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_amount'")->fetch_row()[0] ?? 5);
        $minSpend     = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_min_spend'")->fetch_row()[0] ?? 100);
        $expiryMonths = (int)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_expiry_months'")->fetch_row()[0] ?? 6);
        $expiresAt    = date('Y-m-d H:i:s', strtotime("+{$expiryMonths} months"));

        $userRow = $conn->query("SELECT id FROM users WHERE email='" . $conn->real_escape_string($review['email']) . "' LIMIT 1")->fetch_assoc();
        $userId  = $userRow ? (int)$userRow['id'] : 'NULL';

        do {
            $code   = generateVoucherCode();
            $exists = $conn->query("SELECT id FROM vouchers WHERE code='" . $conn->real_escape_string($code) . "'")->num_rows;
        } while ($exists);

        $safeCode    = $conn->real_escape_string($code);
        $safeEmail   = $conn->real_escape_string($review['email']);
        $safeExpires = $conn->real_escape_string($expiresAt);
        $conn->query("INSERT INTO vouchers (code, type, amount, min_spend, expires_at, assigned_user_id, assigned_email)
            VALUES ('$safeCode', 'cash', $voucherAmt, $minSpend, '$safeExpires', $userId, '$safeEmail')");
        $conn->query("UPDATE reviews SET rewarded=1 WHERE id=$rid");

        sendVoucherEmail($review['email'], $review['username'], $code, 'cash', $voucherAmt, $minSpend, $expiresAt);

        $msg = 'Voucher <strong>' . htmlspecialchars($code) . '</strong> released and emailed to ' . htmlspecialchars($review['email']) . '.';
    } else {
        $msg = 'Review not found or voucher already released.'; $msgType = 'error';
    }
    header('Location: ' . BASE_URL . '/admin/reviews.php?msg=' . urlencode(strip_tags($msg)) . '&msgtype=' . $msgType);
    exit;
}

// Toggle homepage feature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_featured'])) {
    $rid      = (int)$_POST['review_id'];
    $current  = (int)($conn->query("SELECT featured FROM reviews WHERE id=$rid")->fetch_row()[0] ?? 0);
    if ($current) {
        $conn->query("UPDATE reviews SET featured=0 WHERE id=$rid");
        $msg = 'Review removed from homepage.';
    } else {
        $featuredCount = (int)$conn->query("SELECT COUNT(*) FROM reviews WHERE featured=1")->fetch_row()[0];
        if ($featuredCount >= 3) {
            $msg = 'You already have 3 featured reviews. Unfeature one first.'; $msgType = 'error';
        } else {
            $conn->query("UPDATE reviews SET featured=1 WHERE id=$rid");
            $msg = 'Review featured on homepage.';
        }
    }
    header('Location: ' . BASE_URL . '/admin/reviews.php?msg=' . urlencode($msg) . '&msgtype=' . $msgType);
    exit;
}

// Delete review
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $conn->query("DELETE FROM reviews WHERE id=" . (int)$_GET['delete']);
    header('Location: ' . BASE_URL . '/admin/reviews.php?msg=Review+deleted.&msgtype=success');
    exit;
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['msgtype'] ?? 'success'; }

// Filters
$search   = $conn->real_escape_string(trim($_GET['search'] ?? ''));
$rating   = (int)($_GET['rating'] ?? 0);
$pending  = isset($_GET['pending']);
$where    = [];
if ($search)  $where[] = "(r.username LIKE '%$search%' OR r.email LIKE '%$search%' OR r.review_text LIKE '%$search%' OR p.name LIKE '%$search%')";
if ($rating >= 1 && $rating <= 5) $where[] = "r.rating = $rating";
if ($pending) $where[] = "r.rewarded = 0";
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$reviews  = $conn->query("
    SELECT r.*, p.name AS product_name, p.slug AS product_slug,
           o.order_number
    FROM reviews r
    LEFT JOIN products p ON r.product_id = p.id
    LEFT JOIN orders o ON r.order_id = o.id
    $whereSql
    ORDER BY r.rewarded ASC, r.created_at DESC
");
$total        = $conn->query("SELECT COUNT(*) FROM reviews r LEFT JOIN products p ON r.product_id=p.id LEFT JOIN orders o ON r.order_id=o.id $whereSql")->fetch_row()[0];
$pendingCnt   = $conn->query("SELECT COUNT(*) FROM reviews WHERE rewarded=0")->fetch_row()[0];
$featuredCnt  = (int)$conn->query("SELECT COUNT(*) FROM reviews WHERE featured=1")->fetch_row()[0];
$avgRaw       = $conn->query("SELECT AVG(rating) FROM reviews")->fetch_row()[0];
$avgStar    = $avgRaw ? round((float)$avgRaw, 1) : null;

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:20px;"><?= $msg ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:28px;">
  <div class="admin-stat-card">
    <div class="stat-label">Total Reviews</div>
    <div class="stat-value"><?= $conn->query('SELECT COUNT(*) FROM reviews')->fetch_row()[0] ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="stat-label">Average Rating</div>
    <div class="stat-value"><?= $avgStar ? $avgStar . ' ★' : '—' ?></div>
  </div>
  <div class="admin-stat-card" style="<?= $pendingCnt ? 'border-color:#f59e0b44;' : '' ?>">
    <div class="stat-label">Pending Vouchers</div>
    <div class="stat-value" style="<?= $pendingCnt ? 'color:#f59e0b;' : '' ?>"><?= $pendingCnt ?></div>
  </div>
  <div class="admin-stat-card" style="border-color:<?= $featuredCnt > 0 ? '#a78bfa44' : 'var(--border)' ?>;">
    <div class="stat-label">Featured on Homepage</div>
    <div class="stat-value" style="color:<?= $featuredCnt > 0 ? '#a78bfa' : 'var(--text3)' ?>;"><?= $featuredCnt ?>/3</div>
  </div>
  <div class="admin-stat-card">
    <div class="stat-label">Showing</div>
    <div class="stat-value"><?= $total ?></div>
  </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:10px;margin-bottom:20px;align-items:center;flex-wrap:wrap;">
  <input type="text" name="search" class="form-control" placeholder="Search name, email, product, text…" value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:200px;max-width:300px;">
  <select name="rating" class="form-control" style="width:150px;">
    <option value="">All ratings</option>
    <?php for ($i = 5; $i >= 1; $i--): ?>
      <option value="<?= $i ?>" <?= $rating === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) . str_repeat('★', 5-$i) ?></option>
    <?php endfor; ?>
  </select>
  <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--text2);cursor:pointer;white-space:nowrap;">
    <input type="checkbox" name="pending" value="1" <?= $pending ? 'checked' : '' ?> style="accent-color:#f59e0b;">
    Pending only
  </label>
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <?php if ($search || $rating || $pending): ?>
    <a href="<?= BASE_URL ?>/admin/reviews.php" class="btn btn-sm" style="background:var(--surface2);color:var(--text2);">Clear</a>
  <?php endif; ?>
</form>

<!-- Reviews table -->
<?php if (!$reviews || $reviews->num_rows === 0): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--text3);">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px;opacity:0.4;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <p>No reviews found.</p>
  </div>
<?php else: ?>
  <div class="admin-card">
    <div style="overflow-x:auto;">
      <table class="admin-table">
        <thead><tr>
          <th>Status</th>
          <th>Product / Order</th>
          <th>Rating</th>
          <th style="min-width:220px;">Review</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php while ($r = $reviews->fetch_assoc()):
            $tpUrl = 'https://www.trustpilot.com/review/swiss-bricks.com';
        ?>
          <tr style="<?= !$r['rewarded'] ? 'background:rgba(245,158,11,0.04);' : '' ?>">

            <!-- Status -->
            <td style="white-space:nowrap;">
              <?php if ($r['rewarded']): ?>
                <span style="background:#22c55e22;color:#22c55e;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;">Voucher Sent</span>
              <?php else: ?>
                <span style="background:#f59e0b22;color:#f59e0b;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;">Pending</span>
              <?php endif; ?>
              <?php if ($r['featured']): ?>
                <div style="margin-top:4px;"><span style="background:#a78bfa22;color:#a78bfa;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;">★ Featured</span></div>
              <?php endif; ?>
            </td>

            <!-- Product / Order -->
            <td style="font-size:0.82rem;">
              <?php if ($r['product_name']): ?>
                <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= htmlspecialchars($r['product_slug']) ?>" target="_blank"
                   style="color:var(--text1);font-weight:600;text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text1)'">
                  <?= htmlspecialchars($r['product_name']) ?>
                </a>
              <?php else: ?>
                <span style="color:var(--text3);">Website review</span>
              <?php endif; ?>
              <?php if ($r['order_number']): ?>
                <div style="font-size:0.75rem;color:var(--text3);margin-top:2px;"><?= htmlspecialchars($r['order_number']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Rating -->
            <td style="white-space:nowrap;">
              <span style="color:#f59e0b;"><?= str_repeat('★', (int)$r['rating']) ?></span><span style="color:#3a3a4a;"><?= str_repeat('★', 5-(int)$r['rating']) ?></span>
              <span style="font-size:0.72rem;color:var(--text3);margin-left:3px;"><?= $r['rating'] ?>/5</span>
            </td>

            <!-- Review text -->
            <td>
              <p style="margin:0;font-size:0.83rem;color:var(--text2);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;max-width:280px;">
                <?= htmlspecialchars($r['review_text']) ?>
              </p>
            </td>

            <!-- Customer -->
            <td style="font-size:0.82rem;white-space:nowrap;">
              <div style="font-weight:600;"><?= htmlspecialchars($r['username']) ?></div>
              <div style="color:var(--text3);font-size:0.75rem;"><?= htmlspecialchars($r['email']) ?></div>
            </td>

            <!-- Date -->
            <td style="font-size:0.78rem;color:var(--text3);white-space:nowrap;">
              <?= date('d M Y', strtotime($r['created_at'])) ?><br>
              <span style="font-size:0.72rem;"><?= date('H:i', strtotime($r['created_at'])) ?></span>
            </td>

            <!-- Actions -->
            <td style="white-space:nowrap;">
              <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">

                <?php if (!$r['rewarded']): ?>
                  <form method="POST" onsubmit="return confirm('Release voucher to <?= htmlspecialchars(addslashes($r['username'])) ?>?')">
                    <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                    <button type="submit" name="release_voucher"
                      style="background:#22c55e;color:#fff;border:none;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                      Release Voucher
                    </button>
                  </form>
                <?php endif; ?>

                <form method="POST">
                  <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                  <?php if ($r['featured']): ?>
                    <button type="submit" name="toggle_featured"
                      style="background:#a78bfa22;color:#a78bfa;border:1px solid #a78bfa44;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                      ★ Unfeature
                    </button>
                  <?php elseif ($featuredCnt < 3): ?>
                    <button type="submit" name="toggle_featured"
                      style="background:#1e1e2e;color:#a78bfa;border:1px solid #a78bfa44;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                      ☆ Feature
                    </button>
                  <?php else: ?>
                    <span style="color:var(--text3);font-size:0.75rem;font-style:italic;">3/3 featured</span>
                  <?php endif; ?>
                </form>

                <a href="<?= $tpUrl ?>" target="_blank"
                   style="background:#00B67A;color:#fff;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                  Check Trustpilot
                </a>

                <a href="<?= BASE_URL ?>/admin/reviews.php?delete=<?= $r['id'] ?>"
                   onclick="return confirm('Delete this review?')"
                   style="color:var(--accent);font-size:0.75rem;text-decoration:none;font-weight:600;">Delete</a>
              </div>
            </td>

          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
