<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Settings';
$msg        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $keys = ['active_currency', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'review_voucher_amount', 'review_voucher_min_spend', 'review_voucher_expiry_months'];
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            $val  = $conn->real_escape_string(trim($_POST[$key]));
            $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
        }
    }
    $msg = 'Settings saved.';
}

// Fetch current settings
$settingsRows = $conn->query("SELECT setting_key, setting_value FROM settings");
$settings     = [];
while ($row = $settingsRows->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>

<form method="POST">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

    <!-- Currency -->
    <div class="admin-card">
      <div class="admin-card-header"><div class="admin-card-title">💱 Currency</div></div>
      <div style="padding:24px;">
        <div class="form-group">
          <label>Active Currency</label>
          <select name="active_currency" class="form-control">
            <?php foreach (['CHF' => 'Swiss Franc (CHF)'] as $code => $label): ?>
              <option value="<?= $code ?>" <?= ($settings['active_currency'] ?? 'CHF') === $code ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p style="font-size:0.8rem;color:var(--text3);">All prices in the database are stored in CHF. Currently only CHF is supported.</p>
      </div>
    </div>

    <!-- Stripe -->
    <div class="admin-card">
      <div class="admin-card-header"><div class="admin-card-title">💳 Stripe Payment</div></div>
      <div style="padding:24px;">
        <div class="form-group">
          <label>Publishable Key <span style="color:var(--text3);font-weight:400;">(pk_test_…)</span></label>
          <input type="text" name="stripe_publishable_key" class="form-control"
                 placeholder="pk_test_…"
                 value="<?= htmlspecialchars($settings['stripe_publishable_key'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Secret Key <span style="color:var(--text3);font-weight:400;">(sk_test_…)</span></label>
          <input type="text" name="stripe_secret_key" class="form-control"
                 placeholder="sk_test_…"
                 value="<?= htmlspecialchars($settings['stripe_secret_key'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Webhook Secret <span style="color:var(--text3);font-weight:400;">(optional)</span></label>
          <input type="text" name="stripe_webhook_secret" class="form-control"
                 placeholder="whsec_…"
                 value="<?= htmlspecialchars($settings['stripe_webhook_secret'] ?? '') ?>">
        </div>
        <div style="font-size:0.78rem;color:var(--text3);padding:10px;background:var(--surface2);border-radius:8px;">
          Get your keys from <strong>dashboard.stripe.com</strong> → Developers → API keys.<br>
          Enable Test Mode to use test keys.
        </div>
      </div>
    </div>

  </div>

  <!-- Review Voucher -->
  <div class="admin-card" style="margin-top:0;">
    <div class="admin-card-header"><div class="admin-card-title">⭐ Review Voucher</div></div>
    <div style="padding:24px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">
      <div class="form-group" style="margin:0;">
        <label>Voucher Amount (CHF)</label>
        <input type="number" step="0.01" min="0" name="review_voucher_amount" class="form-control"
               value="<?= htmlspecialchars($settings['review_voucher_amount'] ?? '5.00') ?>">
        <small style="color:var(--text3);">Amount credited when a customer leaves a review.</small>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Minimum Order Spend (CHF)</label>
        <input type="number" step="0.01" min="0" name="review_voucher_min_spend" class="form-control"
               value="<?= htmlspecialchars($settings['review_voucher_min_spend'] ?? '100.00') ?>">
        <small style="color:var(--text3);">Voucher is valid on orders above this amount.</small>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Voucher Expiry Period</label>
        <select name="review_voucher_expiry_months" class="form-control">
          <?php foreach ([1, 3, 6, 9, 12] as $m): ?>
            <option value="<?= $m ?>" <?= (int)($settings['review_voucher_expiry_months'] ?? 6) === $m ? 'selected' : '' ?>>
              <?= $m ?> month<?= $m > 1 ? 's' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--text3);">How long after issue the voucher remains valid.</small>
      </div>
    </div>
    <div style="padding:0 24px 20px;font-size:0.8rem;color:var(--text3);">When an order is marked <strong>Delivered</strong>, the customer receives an email with a personal review link. After leaving a review, they get a voucher code for the amount above.</div>
  </div>

  <!-- Stripe test cards reference -->
  <div class="admin-card" style="margin-top:0;">
    <div class="admin-card-header"><div class="admin-card-title">🧪 Stripe Test Cards</div></div>
    <table class="admin-table">
      <thead><tr><th>Card Type</th><th>Number</th><th>Expiry</th><th>CVC</th></tr></thead>
      <tbody>
        <tr><td>Visa (success)</td><td style="font-family:monospace;">4242 4242 4242 4242</td><td>12/25</td><td>123</td></tr>
        <tr><td>Mastercard</td><td style="font-family:monospace;">5555 5555 5555 4444</td><td>12/25</td><td>123</td></tr>
        <tr><td>Amex</td><td style="font-family:monospace;">3782 8224 6310 005</td><td>12/25</td><td>1234</td></tr>
        <tr><td style="color:var(--accent);">Declined</td><td style="font-family:monospace;">4000 0000 0000 0002</td><td>12/25</td><td>123</td></tr>
      </tbody>
    </table>
  </div>

  <div style="margin-top:8px;">
    <button type="submit" name="save_settings" class="btn btn-primary" style="padding:12px 28px;">Save All Settings</button>
  </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
