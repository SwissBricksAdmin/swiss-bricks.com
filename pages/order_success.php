<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$orderNumber   = $_SESSION['last_order_number']   ?? null;
$paymentMethod = $_SESSION['last_payment_method'] ?? '';
if (!$orderNumber) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
unset($_SESSION['last_order_number'], $_SESSION['last_payment_method']);

$pageTitle = 'Order Confirmed';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <div class="success-page">
    <div class="success-card">
      <div class="success-icon">✓</div>
      <h1 style="margin-bottom:12px;">Order Confirmed!</h1>
      <p style="margin-bottom:8px;">Thank you for shopping with SwissBricks. Your order has been received and we'll be in touch shortly.</p>
      <div class="success-order-num"><?= htmlspecialchars($orderNumber) ?></div>
      <p style="font-size:0.85rem;color:var(--text3);margin-bottom:32px;">Keep this order number for your records. You'll receive a confirmation by email.</p>

      <div style="display:grid;gap:12px;margin-bottom:32px;text-align:left;background:var(--surface2);border-radius:12px;padding:20px;">
        <div style="display:flex;gap:10px;align-items:center;font-size:0.9rem;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          <span>Your set will be shipped in its original sealed packaging.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;font-size:0.9rem;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          <span>Picture Proof of Set Condition can be requested as surcharge.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;font-size:0.9rem;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          <span>Delivery via Swiss Post within 1–3 business days.</span>
        </div>
      </div>

      <?php if ($paymentMethod === 'Bank Transfer'): ?>
      <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:20px;margin-bottom:24px;text-align:left;">
        <div style="font-weight:700;margin-bottom:12px;font-size:0.95rem;">Bank Transfer Details</div>
        <div style="font-size:0.85rem;color:var(--text2);line-height:2;">
          <div>Please transfer <strong style="color:var(--text);"><?= formatPrice($conn->query("SELECT total_amount FROM orders WHERE order_number='" . $conn->real_escape_string($orderNumber) . "'")->fetch_row()[0] ?? 0) ?></strong> to:</div>
          <div><span style="color:var(--text3);">Account holder:</span> Jasper Luca Weening</div>
          <div><span style="color:var(--text3);">IBAN:</span> <strong style="color:var(--text);font-family:monospace;">CH68 0020 4204 3038 7040 A</strong></div>
          <div><span style="color:var(--text3);">Reference:</span> <strong style="color:var(--accent);font-family:monospace;"><?= htmlspecialchars($orderNumber) ?></strong></div>
        </div>
        <p style="font-size:0.78rem;color:var(--text3);margin-top:12px;">Your order will be processed once payment is received. Please include the reference number.</p>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
        <?php if (isLoggedIn()): ?>
          <a href="<?= BASE_URL ?>/pages/myaccount.php?tab=orders" class="btn btn-primary">View My Orders</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-outline">Continue Shopping</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
