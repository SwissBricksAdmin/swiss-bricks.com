<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

\Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

$adminTitle = 'Pre-order Requests';
$msg = '';
$msgType = 'success';

// Process refund (single order or all expired)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['refund_order_id']) || isset($_POST['process_refunds']))) {
    $singleId = isset($_POST['refund_order_id']) ? (int)$_POST['refund_order_id'] : null;

    $where = $singleId
        ? "AND o.id = $singleId"
        : "AND o.created_at < NOW() - INTERVAL 30 DAY";

    $expiredResult = $conn->query("
        SELECT DISTINCT
            o.id, o.order_number, o.stripe_session_id, o.total_amount,
            u.first_name, u.last_name, u.email
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.is_preorder = 1
        JOIN users u ON u.id = o.user_id
        WHERE o.status NOT IN ('pending','cancelled')
          AND (o.payment_status IS NULL OR o.payment_status != 'refunded')
          $where
    ");

    $refunded = 0;
    $failed   = 0;

    $errors = [];

    while ($order = $expiredResult->fetch_assoc()) {
        $oid = (int)$order['id'];

        if (empty($order['stripe_session_id'])) {
            $errors[] = $order['order_number'] . ': no Stripe session (not placed via card checkout)';
            $failed++;
            continue;
        }

        try {
            $session = \Stripe\Checkout\Session::retrieve(
                $order['stripe_session_id'],
                ['expand' => ['payment_intent']]
            );

            if (empty($session->payment_intent)) {
                throw new \Exception('No payment intent found on session');
            }

            $pi = is_string($session->payment_intent)
                ? $session->payment_intent
                : $session->payment_intent->id;

            \Stripe\Refund::create(['payment_intent' => $pi]);

            $conn->query("UPDATE orders SET status='cancelled', payment_status='refunded' WHERE id=$oid");

            $itemsResult = $conn->query("
                SELECT oi.quantity, oi.price, p.name, p.image_url
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = $oid
            ");
            $emailItems = [];
            while ($row = $itemsResult->fetch_assoc()) $emailItems[] = $row;

            sendPreorderRefundEmail(
                $order['email'],
                $order['first_name'],
                $order['order_number'],
                (float)$order['total_amount'],
                $emailItems
            );

            $refunded++;
        } catch (\Exception $e) {
            $errors[] = $order['order_number'] . ': ' . $e->getMessage();
            $failed++;
        }
    }

    $msg = $refunded . ' refund' . ($refunded !== 1 ? 's' : '') . ' processed and customer' . ($refunded !== 1 ? 's' : '') . ' notified.';
    if ($failed) {
        $msg     .= ' ' . $failed . ' failed: ' . implode('; ', $errors);
        $msgType  = 'warning';
    }

    header('Location: ' . BASE_URL . '/admin/preorders.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit;
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

// Count expired orders awaiting refund
$expiredCount = (int)$conn->query("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id AND oi.is_preorder = 1
    WHERE o.created_at < NOW() - INTERVAL 30 DAY
      AND o.status NOT IN ('pending','cancelled')
      AND (o.payment_status IS NULL OR o.payment_status != 'refunded')
")->fetch_row()[0];

// Fetch all pre-orders (active + refunded) for display
$preorders = $conn->query("
    SELECT
        oi.id AS item_id,
        oi.quantity,
        oi.price,
        o.id AS order_id,
        o.order_number,
        o.created_at,
        UNIX_TIMESTAMP(o.created_at) AS created_ts,
        o.status AS order_status,
        o.payment_status,
        p.name AS product_name,
        p.slug AS product_slug,
        p.image_url,
        p.stock AS current_stock,
        u.first_name,
        u.last_name,
        u.email
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE oi.is_preorder = 1
      AND o.status != 'pending'
    ORDER BY o.created_at DESC
");

require_once __DIR__ . '/layout.php';
?>

<style>
.preorder-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
    transition: border-color 0.2s;
}
.preorder-card.urgent   { border-color: rgba(237,30,40,0.5); }
.preorder-card.expired  { border-color: var(--border); opacity: 0.65; }
.preorder-card.refunded  { border-color: var(--border); opacity: 0.5; }
.preorder-card.fulfilled { border-color: rgba(34,197,94,0.35); }
.preorder-inner {
    display: grid;
    grid-template-columns: 64px 1fr auto auto;
    gap: 16px;
    align-items: center;
    padding: 16px 20px;
}
.countdown-block {
    text-align: right;
    min-width: 180px;
}
.countdown-label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text3);
    margin-bottom: 4px;
}
.countdown-value {
    font-size: 1.5rem;
    font-weight: 800;
    font-family: var(--font-head);
    letter-spacing: -0.02em;
    color: var(--text);
    line-height: 1;
}
.countdown-value.urgent   { color: var(--accent); }
.countdown-value.expired  { color: var(--text3); font-size: 1rem; }
.countdown-value.refunded  { color: #22c55e; font-size: 0.9rem; }
.countdown-value.fulfilled { color: #22c55e; font-size: 0.9rem; }
.preorder-progress-bar {
    height: 3px;
    background: var(--border);
    position: relative;
}
.preorder-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), #f5686a);
    transition: width 1s linear;
}
.btn-refund {
    background: rgba(237,30,40,0.12);
    color: var(--accent);
    border: 1px solid rgba(237,30,40,0.3);
    white-space: nowrap;
    transition: background 0.18s, border-color 0.18s, color 0.18s;
}
.btn-refund:hover {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.btn-refund:active {
    background: #c4141c;
    border-color: #c4141c;
}
</style>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType === 'warning' ? 'warning' : 'success' ?>" style="margin-bottom:20px;">
    <?= htmlspecialchars($msg) ?>
  </div>
<?php endif; ?>

<?php if ($preorders && $preorders->num_rows === 0): ?>
  <div class="admin-card" style="padding:40px;text-align:center;color:var(--text3);">
    No pre-order requests yet.
  </div>
<?php else: ?>
  <div style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div style="color:var(--text3);font-size:0.88rem;"><?= $preorders->num_rows ?> pre-order<?= $preorders->num_rows !== 1 ? 's' : '' ?></div>
    <?php if ($expiredCount > 0): ?>
      <form method="POST" onsubmit="return confirm('This will issue Stripe refunds for <?= $expiredCount ?> expired pre-order<?= $expiredCount !== 1 ? 's' : '' ?> and notify customers. Continue?');">
        <input type="hidden" name="process_refunds" value="1">
        <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          Refund <?= $expiredCount ?> Expired Pre-order<?= $expiredCount !== 1 ? 's' : '' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php while ($po = $preorders->fetch_assoc()):
    $isRefunded  = ($po['payment_status'] === 'refunded');
    $isFulfilled = in_array($po['order_status'], ['shipped', 'delivered']);
    $isSettled   = $isRefunded || $isFulfilled;
    $createdTs   = (int)$po['created_ts'];
    $deadlineTs  = $createdTs + (30 * 24 * 3600);
    $nowTs       = time();
    $secsLeft    = $deadlineTs - $nowTs;
    $totalSecs   = 30 * 24 * 3600;
    $pct         = max(0, min(100, ($secsLeft / $totalSecs) * 100));
    $isExpired   = !$isSettled && $secsLeft <= 0;
    $isUrgent    = !$isExpired && !$isSettled && $secsLeft < 86400;
    $cardClass   = $isRefunded ? 'refunded' : ($isFulfilled ? 'fulfilled' : ($isExpired ? 'expired' : ($isUrgent ? 'urgent' : '')));
  ?>
  <div class="preorder-card <?= $cardClass ?>" <?= !$isRefunded ? 'data-deadline="' . $deadlineTs . '"' : '' ?>>
    <div class="preorder-inner">

      <!-- Product image -->
      <img src="<?= htmlspecialchars($po['image_url']) ?>" alt=""
        style="width:64px;height:64px;object-fit:contain;background:#fff;border-radius:8px;padding:4px;"
        onerror="this.src='https://placehold.co/64x64'">

      <!-- Info -->
      <div>
        <div style="font-weight:700;font-size:0.95rem;margin-bottom:2px;">
          <?= htmlspecialchars($po['product_name']) ?>
          <?php if ($isRefunded): ?>
            <span style="display:inline-block;margin-left:8px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.3);border-radius:4px;padding:1px 7px;font-size:0.68rem;font-weight:700;letter-spacing:0.05em;vertical-align:middle;">UNSUCCESSFUL / REFUNDED</span>
          <?php elseif ($isFulfilled): ?>
            <span style="display:inline-block;margin-left:8px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.3);border-radius:4px;padding:1px 7px;font-size:0.68rem;font-weight:700;letter-spacing:0.05em;vertical-align:middle;">FULFILLED</span>
          <?php elseif ($po['current_stock'] > 0): ?>
            <span style="display:inline-block;margin-left:8px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.3);border-radius:4px;padding:1px 7px;font-size:0.68rem;font-weight:700;letter-spacing:0.05em;vertical-align:middle;">RESTOCKED</span>
          <?php endif; ?>
        </div>
        <div style="font-size:0.83rem;color:var(--text3);margin-bottom:6px;">
          <?= htmlspecialchars($po['first_name'] . ' ' . $po['last_name']) ?>
          &nbsp;·&nbsp;
          <a href="mailto:<?= htmlspecialchars($po['email']) ?>" style="color:var(--text3);text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text3)'"><?= htmlspecialchars($po['email']) ?></a>
        </div>
        <div style="display:flex;gap:16px;font-size:0.78rem;color:var(--text3);">
          <span>Order <strong style="color:var(--text2);"><?= htmlspecialchars($po['order_number']) ?></strong></span>
          <span>Qty <strong style="color:var(--text2);"><?= $po['quantity'] ?></strong></span>
          <span><?= formatPrice($po['price'] * $po['quantity']) ?></span>
          <span>Ordered <?= date('d M Y', $createdTs) ?></span>
          <?php if (!$isSettled): ?>
            <span>Deadline <?= date('d M Y', $deadlineTs) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Actions -->
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <a href="<?= BASE_URL ?>/admin/orders.php?search=<?= urlencode($po['order_number']) ?>"
          class="btn btn-outline btn-xs" style="white-space:nowrap;">View Order</a>
        <?php if (!$isRefunded && !$isFulfilled): ?>
          <form method="POST" onsubmit="return confirm('Refund order <?= htmlspecialchars($po['order_number']) ?> (<?= formatPrice($po['price'] * $po['quantity']) ?>) and notify customer?');">
            <input type="hidden" name="refund_order_id" value="<?= (int)$po['order_id'] ?>">
            <button type="submit" class="btn btn-xs btn-refund">Refund</button>
          </form>
        <?php endif; ?>
      </div>

      <!-- Countdown / status -->
      <div class="countdown-block">
        <?php if ($isRefunded): ?>
          <div class="countdown-label">Status</div>
          <div class="countdown-value refunded">Refunded</div>
        <?php elseif ($isFulfilled): ?>
          <div class="countdown-label">Status</div>
          <div class="countdown-value fulfilled"><?= ucfirst($po['order_status']) ?></div>
        <?php elseif ($isExpired): ?>
          <div class="countdown-label">Status</div>
          <div class="countdown-value expired">Expired</div>
        <?php else: ?>
          <div class="countdown-label">Time remaining</div>
          <div class="countdown-value <?= $isUrgent ? 'urgent' : '' ?>" data-deadline="<?= $deadlineTs ?>">
            <?php if ($isUrgent): ?>
              <?= gmdate('H:i:s', $secsLeft) ?>
            <?php else:
              $daysLeft  = floor($secsLeft / 86400);
              $hoursLeft = floor(($secsLeft % 86400) / 3600);
            ?>
              <?= $daysLeft ?>d <?= $hoursLeft ?>h
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
    <!-- Progress bar (active orders only) -->
    <?php if (!$isExpired && !$isSettled): ?>
    <div class="preorder-progress-bar">
      <div class="preorder-progress-fill" style="width:<?= round($pct, 1) ?>%" data-pct-start="<?= round($pct, 4) ?>" data-deadline="<?= $deadlineTs ?>"></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endwhile; ?>
<?php endif; ?>

<script>
(function() {
  const TOTAL = 30 * 24 * 3600;

  function update() {
    const now = Math.floor(Date.now() / 1000);

    document.querySelectorAll('.countdown-value[data-deadline]').forEach(function(el) {
      const deadline = parseInt(el.dataset.deadline);
      const left = deadline - now;

      if (left <= 0) {
        el.textContent = 'Expired';
        el.className = 'countdown-value expired';
        el.closest('.preorder-card').classList.add('expired');
        el.closest('.preorder-card').classList.remove('urgent');
        return;
      }

      if (left < 86400) {
        const h = Math.floor(left / 3600);
        const m = Math.floor((left % 3600) / 60);
        const s = left % 60;
        el.textContent = h + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        el.className = 'countdown-value urgent';
        el.closest('.preorder-card').classList.add('urgent');
      } else {
        const d = Math.floor(left / 86400);
        const h = Math.floor((left % 86400) / 3600);
        el.textContent = d + 'd ' + h + 'h';
        el.className = 'countdown-value';
      }
    });

    document.querySelectorAll('.preorder-progress-fill[data-deadline]').forEach(function(bar) {
      const deadline = parseInt(bar.dataset.deadline);
      const left = deadline - now;
      if (left <= 0) { bar.style.width = '0%'; return; }
      const pct = Math.min(100, (left / TOTAL) * 100);
      bar.style.width = pct.toFixed(2) + '%';
    });
  }

  update();
  setInterval(update, 1000);
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
