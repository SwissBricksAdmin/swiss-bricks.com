<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Vouchers';
$msg = '';
$msgType = 'success';

// Handle create voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    $type      = in_array($_POST['type'] ?? '', ['cash', 'percentage']) ? $_POST['type'] : 'cash';
    $amount    = max(0.01, (float)($_POST['amount'] ?? 0));
    $minSpend  = max(0, (float)($_POST['min_spend'] ?? 0));
    $userId    = (int)($_POST['user_id'] ?? 0);

    if ($type === 'percentage' && $amount > 100) $amount = 100;

    // Fetch assigned user email
    $assignedEmail = '';
    $assignedName  = '';
    if ($userId) {
        $uRow = $conn->query("SELECT email, first_name, last_name FROM users WHERE id=$userId")->fetch_assoc();
        if ($uRow) {
            $assignedEmail = $uRow['email'];
            $assignedName  = trim($uRow['first_name'] . ' ' . $uRow['last_name']);
        }
    }

    $expiryMonths = (int)($_POST['expiry_months'] ?? 6);
    $expiresAt    = date('Y-m-d H:i:s', strtotime("+{$expiryMonths} months"));

    if (!$assignedEmail) {
        $msg = 'Please select a valid user.';
        $msgType = 'error';
    } elseif ($amount <= 0) {
        $msg = 'Please enter a valid discount amount.';
        $msgType = 'error';
    } else {
        // Generate unique code
        do {
            $code = generateVoucherCode();
            $exists = $conn->query("SELECT id FROM vouchers WHERE code='" . $conn->real_escape_string($code) . "'")->num_rows;
        } while ($exists);

        $stmt = $conn->prepare("INSERT INTO vouchers (code, type, amount, min_spend, expires_at, assigned_user_id, assigned_email) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('ssdisds', $code, $type, $amount, $minSpend, $expiresAt, $userId, $assignedEmail);
        $stmt->execute();

        if ($conn->affected_rows) {
            $ok = sendVoucherEmail($assignedEmail, $assignedName, $code, $type, $amount, $minSpend);
            $msg = 'Voucher <strong>' . htmlspecialchars($code) . '</strong> created and ' . ($ok ? 'emailed to ' . htmlspecialchars($assignedEmail) : 'saved (email failed — check SMTP)') . '.';
        } else {
            $msg = 'Failed to save voucher: ' . $conn->error;
            $msgType = 'error';
        }
    }

    header('Location: ' . BASE_URL . '/admin/vouchers.php?msg=' . urlencode(strip_tags($msg)) . '&type=' . $msgType);
    exit;
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['type'] ?? 'success'; }

$users    = $conn->query("SELECT id, first_name, last_name, email FROM users ORDER BY first_name, last_name");
$vouchers = $conn->query("SELECT v.*, u.first_name, u.last_name FROM vouchers v LEFT JOIN users u ON v.assigned_user_id=u.id ORDER BY v.created_at DESC");

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:20px;"><?= $msg ?></div>
<?php endif; ?>

<!-- Create Voucher -->
<div class="admin-card" style="margin-bottom:28px;">
  <div class="admin-card-header">
    <div class="admin-card-title">Create Voucher</div>
  </div>
  <div style="padding:24px;">
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;align-items:end;">
      <input type="hidden" name="create_voucher" value="1">

      <div class="form-group" style="margin:0;">
        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Discount Type</label>
        <select name="type" class="form-control" style="width:100%;" onchange="updateAmountLabel(this)">
          <option value="cash">Cash (CHF)</option>
          <option value="percentage">Percentage (%)</option>
        </select>
      </div>

      <div class="form-group" style="margin:0;">
        <label id="amount-label" style="display:block;font-size:0.8rem;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Amount (CHF)</label>
        <input type="number" name="amount" class="form-control" min="0.01" step="0.01" placeholder="e.g. 10.00" required style="width:100%;">
      </div>

      <div class="form-group" style="margin:0;">
        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Minimum Spend (CHF)</label>
        <input type="number" name="min_spend" class="form-control" min="0" step="0.01" placeholder="0 = no minimum" value="0" style="width:100%;">
      </div>

      <div class="form-group" style="margin:0;">
        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Expiry Period</label>
        <select name="expiry_months" class="form-control" style="width:100%;">
          <?php foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 9 => '9 months', 12 => '12 months'] as $val => $label): ?>
            <option value="<?= $val ?>" <?= $val === 6 ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin:0;">
        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Assign to User</label>
        <select name="user_id" class="form-control" required style="width:100%;">
          <option value="">— Select user —</option>
          <?php while ($u = $users->fetch_assoc()): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
          <?php endwhile; ?>
        </select>
      </div>

      <div style="align-self:end;">
        <button type="submit" class="btn btn-primary" style="width:100%;padding:10px 0;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Generate &amp; Send
        </button>
      </div>
    </form>
  </div>
</div>

<!-- All Vouchers -->
<div class="admin-card">
  <div class="admin-card-header">
    <div class="admin-card-title">All Vouchers</div>
  </div>

  <?php if ($vouchers->num_rows === 0): ?>
    <div style="padding:40px;text-align:center;color:var(--text3);font-size:0.9rem;">No vouchers created yet.</div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);">
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Code</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Discount</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Min Spend</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Assigned To</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Status</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Expires</th>
            <th style="padding:12px 20px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text3);">Created</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($v = $vouchers->fetch_assoc()): ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:14px 20px;">
              <span style="font-family:monospace;font-size:0.92rem;font-weight:800;color:var(--accent);letter-spacing:0.06em;"><?= htmlspecialchars($v['code']) ?></span>
            </td>
            <td style="padding:14px 20px;font-weight:700;color:var(--text);">
              <?= $v['type'] === 'cash' ? 'CHF ' . number_format($v['amount'], 2) : number_format($v['amount'], 0) . '%' ?>
            </td>
            <td style="padding:14px 20px;color:var(--text2);">
              <?= $v['min_spend'] > 0 ? 'CHF ' . number_format($v['min_spend'], 2) : '<span style="color:var(--text3);">None</span>' ?>
            </td>
            <td style="padding:14px 20px;">
              <div style="font-weight:600;color:var(--text);"><?= htmlspecialchars(trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''))) ?></div>
              <div style="font-size:0.78rem;color:var(--text3);"><?= htmlspecialchars($v['assigned_email'] ?? '') ?></div>
            </td>
            <td style="padding:14px 20px;">
              <?php
                $isExpired = !$v['used'] && $v['expires_at'] && strtotime($v['expires_at']) < time();
              ?>
              <?php if ($v['used']): ?>
                <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#22c55e;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);border-radius:20px;padding:3px 10px;">Used</span>
                <?php if ($v['used_at']): ?>
                  <div style="font-size:0.75rem;color:var(--text3);margin-top:3px;"><?= date('d M Y', strtotime($v['used_at'])) ?></div>
                <?php endif; ?>
              <?php elseif ($isExpired): ?>
                <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#ef4444;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.25);border-radius:20px;padding:3px 10px;">Expired</span>
              <?php else: ?>
                <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#f59e0b;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);border-radius:20px;padding:3px 10px;">Active</span>
              <?php endif; ?>
            </td>
            <td style="padding:14px 20px;font-size:0.82rem;">
              <?php if ($v['expires_at']): ?>
                <?php $expired = strtotime($v['expires_at']) < time(); ?>
                <span style="color:<?= $expired ? '#ef4444' : 'var(--text2)' ?>;">
                  <?= date('d M Y', strtotime($v['expires_at'])) ?>
                </span>
                <?php if ($expired): ?>
                  <div style="font-size:0.72rem;color:#ef4444;font-weight:700;">Expired</div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text3);">—</span>
              <?php endif; ?>
            </td>
            <td style="padding:14px 20px;color:var(--text3);font-size:0.82rem;"><?= date('d M Y', strtotime($v['created_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function updateAmountLabel(sel) {
  document.getElementById('amount-label').textContent = sel.value === 'percentage' ? 'Amount (%)' : 'Amount (CHF)';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
