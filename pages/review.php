<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

// Validate token
$order = null;
if ($token) {
    $safeToken = $conn->real_escape_string($token);
    $order = $conn->query("SELECT o.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS customer, u.email FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.review_token='$safeToken' AND o.status='delivered'")->fetch_assoc();
}

if (!$order) {
    $error = 'This review link is invalid or has already been used.';
}

// Check if already reviewed via this token
if ($order) {
    $existingReview = $conn->query("SELECT id FROM reviews WHERE order_id=" . (int)$order['id'])->fetch_assoc();
    if ($existingReview) {
        $done = true;
    }
}

// Handle the order's first product for association
$productId = null;
if ($order) {
    $firstItem = $conn->query("SELECT product_id FROM order_items WHERE order_id=" . (int)$order['id'] . " LIMIT 1")->fetch_assoc();
    if ($firstItem) $productId = (int)$firstItem['product_id'];
}

// Process submission
$submitted = false;
if (!$error && !$done && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating = (int)($_POST['rating'] ?? 0);
    $text   = trim($_POST['review_text'] ?? '');
    $name   = trim($_POST['reviewer_name'] ?? '');
    $email  = trim($_POST['reviewer_email'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a star rating.';
    } elseif (strlen($text) < 10) {
        $error = 'Please write at least 10 characters in your review.';
    } elseif (empty($name)) {
        $error = 'Please enter your name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $safeText   = $conn->real_escape_string($text);
        $safeName   = $conn->real_escape_string($name);
        $safeEmail  = $conn->real_escape_string($email);
        $pid        = $productId ? (int)$productId : 'NULL';
        $oid        = (int)$order['id'];
        $safeToken2 = $conn->real_escape_string($token);

        // Insert review — rewarded=0, voucher released manually by admin
        $conn->query("INSERT INTO reviews (product_id, order_id, review_token, rating, review_text, username, email, rewarded, created_at)
            VALUES ($pid, $oid, '$safeToken2', $rating, '$safeText', '$safeName', '$safeEmail', 0, NOW())");

        // Invalidate token so link cannot be reused
        $conn->query("UPDATE orders SET review_token=NULL WHERE review_token='$safeToken2'");

        // Notify admin
        sendAdminReviewNotification($name, $email, $order['order_number'], $rating, $text);

        $submitted = true;
    }
}

$pageTitle = 'Leave a Review — SwissBricks';
require_once __DIR__ . '/../includes/header.php';
?>

<main style="min-height:70vh;padding:60px 0;">
<div style="max-width:540px;margin:0 auto;padding:0 20px;">

<?php if ($submitted): ?>
  <div style="text-align:center;padding:48px 32px;background:var(--surface);border-radius:20px;border:1px solid #2a2a3a;">
    <div style="font-size:3rem;margin-bottom:16px;">⭐</div>
    <h1 style="font-size:1.6rem;font-weight:800;margin:0 0 12px;color:#fff;">Thank you for your review!</h1>
    <p style="color:var(--text2);line-height:1.7;margin:0 0 24px;">Don't forget to also leave a review on Trustpilot, to receive your voucher!</p>
    <a href="<?= BASE_URL ?>/" class="btn btn-primary" style="padding:12px 28px;">Continue Shopping</a>
  </div>

<?php elseif ($done): ?>
  <div style="text-align:center;padding:48px 32px;background:var(--surface);border-radius:20px;border:1px solid #2a2a3a;">
    <h1 style="font-size:1.4rem;font-weight:800;margin:0 0 12px;color:#fff;">Review already submitted</h1>
    <p style="color:var(--text2);line-height:1.7;margin:0 0 24px;">A review has already been left for this order. Your voucher will be sent once verified.</p>
    <a href="<?= BASE_URL ?>/" class="btn btn-primary" style="padding:12px 28px;">Back to Shop</a>
  </div>

<?php elseif ($error && !$order): ?>
  <div style="text-align:center;padding:48px 32px;background:var(--surface);border-radius:20px;border:1px solid #2a2a3a;">
    <h1 style="font-size:1.4rem;font-weight:800;margin:0 0 12px;color:#fff;">Invalid Link</h1>
    <p style="color:var(--text2);line-height:1.7;margin:0;"><?= htmlspecialchars($error) ?></p>
  </div>

<?php else: ?>
  <?php
    $voucherAmt2 = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_amount'")->fetch_row()[0] ?? 5);
    $minSpend2   = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_min_spend'")->fetch_row()[0] ?? 100);
  ?>
  <div style="background:var(--surface);border-radius:20px;border:1px solid #2a2a3a;overflow:hidden;">
    <div style="background:#ED1E28;padding:24px 32px;">
      <div style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.7);margin-bottom:4px;">Order <?= htmlspecialchars($order['order_number']) ?></div>
      <h1 style="font-size:1.4rem;font-weight:800;color:#fff;margin:0;">Leave a Review</h1>
    </div>
    <div style="padding:28px 32px;">
      <p style="color:var(--text2);line-height:1.7;margin:0 0 20px;">Leave a review and receive a <strong style="color:#ED1E28;">CHF <?= number_format($voucherAmt2, 2) ?> gift voucher</strong> off your next order over CHF <?= number_format($minSpend2, 2) ?>. Your voucher will be sent once we have verified your review.</p>

      <?php if ($error): ?>
        <div style="background:#ED1E2822;border:1px solid #ED1E28;color:#ED1E28;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:0.9rem;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div style="margin-bottom:20px;">
          <label style="font-size:0.85rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);display:block;margin-bottom:10px;">Your Rating</label>
          <div id="stars" style="display:flex;gap:6px;cursor:pointer;">
            <?php for ($i=1;$i<=5;$i++): ?>
            <span data-val="<?= $i ?>" onclick="setStar(<?= $i ?>)" style="font-size:2rem;color:#3a3a4a;transition:color 0.15s;">★</span>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="rating" id="rating_input" value="<?= (int)($_POST['rating'] ?? 0) ?>">
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label>Your Review</label>
          <textarea name="review_text" class="form-control" rows="4" placeholder="Tell us what you thought about your order…" style="resize:vertical;"><?= htmlspecialchars($_POST['review_text'] ?? '') ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
          <div class="form-group" style="margin:0;">
            <label>Your Name</label>
            <input type="text" name="reviewer_name" class="form-control" value="<?= htmlspecialchars($_POST['reviewer_name'] ?? trim($order['customer'])) ?>" required>
          </div>
          <div class="form-group" style="margin:0;">
            <label>Email</label>
            <input type="email" name="reviewer_email" class="form-control" value="<?= htmlspecialchars($_POST['reviewer_email'] ?? $order['email']) ?>" required>
          </div>
        </div>

        <button type="submit" name="submit_review" class="btn btn-primary" style="width:100%;padding:14px;font-size:1rem;">Submit Review</button>
      </form>
    </div>
  </div>
<?php endif; ?>

</div>
</main>

<script>
const ratingInput = document.getElementById('rating_input');
const stars = document.querySelectorAll('#stars span');
let current = parseInt(ratingInput?.value || '0');
function setStar(val) { current = val; ratingInput.value = val; updateStarDisplay(val); }
function updateStarDisplay(val) { stars.forEach(s => { s.style.color = parseInt(s.dataset.val) <= val ? '#f59e0b' : '#3a3a4a'; }); }
stars.forEach(s => {
  s.addEventListener('mouseenter', () => updateStarDisplay(parseInt(s.dataset.val)));
  s.addEventListener('mouseleave', () => updateStarDisplay(current));
});
if (current > 0) updateStarDisplay(current);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
