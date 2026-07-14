<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Orders';

// Save tracking number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tracking'])) {
    $oid      = (int)$_POST['order_id'];
    $tracking = trim($conn->real_escape_string($_POST['tracking_number'] ?? ''));
    $conn->query("UPDATE orders SET tracking_number='$tracking' WHERE id=$oid");
    header('Location: ' . BASE_URL . '/admin/orders.php?updated=1');
    exit;
}

// Update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid    = (int)$_POST['order_id'];
    $status = $conn->real_escape_string($_POST['status']);
    $allowed = ['pending','paid','processing','shipped','delivered','cancelled'];
    if (in_array($status, $allowed)) {
        $conn->query("UPDATE orders SET status='$status' WHERE id=$oid");
        $orow = $conn->query("SELECT o.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS customer, u.email FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=$oid")->fetch_assoc();

        if ($orow && !empty($orow['email'])) {
            if ($status === 'shipped') {
                sendOrderShippedEmail($orow['email'], trim($orow['customer']), $orow['order_number'], trim($orow['tracking_number'] ?? ''));
            } elseif ($status === 'cancelled') {
                sendOrderCancelledEmail($orow['email'], trim($orow['customer']), $orow['order_number']);
            } elseif ($status === 'delivered' && !$orow['review_invite_sent']) {
                $voucherAmt   = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_amount'")->fetch_row()[0] ?? 5);
                $minSpend     = (float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_min_spend'")->fetch_row()[0] ?? 100);
                $expiryMonths = (int)($conn->query("SELECT setting_value FROM settings WHERE setting_key='review_voucher_expiry_months'")->fetch_row()[0] ?? 6);
                $token        = generateReviewToken();
                $safeToken    = $conn->real_escape_string($token);
                $conn->query("UPDATE orders SET review_token='$safeToken', review_invite_sent=1 WHERE id=$oid");
                sendReviewInviteEmail($orow['email'], trim($orow['customer']), $orow['order_number'], $token, $voucherAmt, $minSpend, $expiryMonths);
            }
        }
    }
    header('Location: ' . BASE_URL . '/admin/orders.php?updated=1');
    exit;
}

$perPage   = 15;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;
$search    = $conn->real_escape_string($_GET['search'] ?? '');
$where     = $search ? "WHERE o.order_number LIKE '%$search%' OR CONCAT(u.first_name,' ',u.last_name) LIKE '%$search%'" : '';
$total     = $conn->query("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id=u.id $where")->fetch_row()[0];
$pages     = max(1, ceil($total / $perPage));
$orders    = $conn->query("SELECT o.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS customer FROM orders o LEFT JOIN users u ON o.user_id=u.id $where ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset");

require_once __DIR__ . '/layout.php';
?>

<?php if (isset($_GET['updated'])): ?>
  <div class="alert alert-success">Order status updated.</div>
<?php endif; ?>

<div class="admin-card">
  <div class="admin-card-header">
    <div class="admin-card-title">All Orders (<?= $total ?>)</div>
    <form class="admin-search" method="GET">
      <input type="text" name="search" placeholder="Search order # or customer…" value="<?= htmlspecialchars($search) ?>">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
  </div>
  <table class="admin-table">
    <thead><tr>
      <th>Order #</th><th>Customer</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php while ($o = $orders->fetch_assoc()):
        $items_q = $conn->query("SELECT oi.quantity AS qty, oi.price, oi.is_preorder, p.name, p.weight_grams, p.length_cm, p.width_cm, p.height_cm FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=" . (int)$o['id']);
        $items_arr = [];
        $hasPreorder = false;
        while ($item = $items_q->fetch_assoc()) {
            if ($item['is_preorder']) $hasPreorder = true;
            $items_arr[] = $item;
        }
        // Recalculate shipping config from product dimensions
        $storedMethod = $o['shipping_method'] ?: 'economy';
        $shipCalc = empty($items_arr) ? null : calculateShipping($items_arr, $storedMethod);
        $rowId = 'order-detail-' . $o['id'];
    ?>
      <tr class="order-main-row" onclick="toggleOrderDetail('<?= $rowId ?>', this)" style="cursor:pointer;">
        <td style="font-weight:700;color:var(--accent);">
          <span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <svg class="expand-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
            <?= htmlspecialchars($o['order_number']) ?>
            <?php if ($hasPreorder): ?>
              <span style="background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.35);border-radius:4px;padding:1px 6px;font-size:0.65rem;font-weight:800;letter-spacing:0.06em;">PRE-ORDER</span>
            <?php endif; ?>
          </span>
        </td>
        <td><?= htmlspecialchars(trim($o['customer']) ?: 'Guest') ?></td>
        <td style="font-weight:700;"><?= formatPrice($o['total_amount']) ?></td>
        <td style="color:var(--text3);"><?= htmlspecialchars($o['payment_method']) ?></td>
        <td><?= getStatusBadge($o['status']) ?></td>
        <td style="color:var(--text3);"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
        <td onclick="event.stopPropagation()">
          <form method="POST" action="<?= BASE_URL ?>/admin/orders.php" style="display:flex;gap:6px;align-items:center;" onclick="event.stopPropagation()">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <input type="hidden" name="update_status" value="1">
            <select name="status" class="status-select" onclick="event.stopPropagation()">
              <?php foreach (['processing','shipped','delivered','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-xs" onclick="event.stopPropagation()">Save</button>
          </form>
        </td>
      </tr>
      <tr id="<?= $rowId ?>" style="display:none;">
        <td colspan="7" style="padding:0;border-top:none;">
          <div style="background:var(--surface2);border-left:3px solid var(--accent);padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:24px;">

            <!-- Left column: address + parcel config -->
            <div>
              <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin-bottom:8px;">Shipping Address</div>
              <div style="font-size:0.88rem;line-height:1.8;color:var(--text2);"><?= nl2br(htmlspecialchars($o['shipping_address'] ?? '—')) ?></div>
              <?php if (!empty($o['notes'])): ?>
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin:14px 0 6px;">Notes</div>
                <div style="font-size:0.85rem;color:var(--text2);"><?= htmlspecialchars($o['notes']) ?></div>
              <?php endif; ?>

              <!-- Tracking number -->
              <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin:16px 0 8px;">Swiss Post Tracking</div>
              <form method="POST" action="<?= BASE_URL ?>/admin/orders.php" style="display:flex;gap:8px;align-items:center;" onclick="event.stopPropagation()">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="save_tracking" value="1">
                <input type="text" name="tracking_number" value="<?= htmlspecialchars($o['tracking_number'] ?? '') ?>"
                  placeholder="e.g. 990023452290003452"
                  class="form-control" style="flex:1;font-family:monospace;font-size:0.85rem;padding:7px 10px;">
                <button type="submit" class="btn btn-outline btn-xs" onclick="event.stopPropagation()" style="white-space:nowrap;">Save</button>
              </form>
              <?php if (!empty($o['tracking_number'])): ?>
                <a href="https://track.post.ch/?formattedParcelCodes=<?= urlencode($o['tracking_number']) ?>" target="_blank"
                   style="font-size:0.75rem;color:var(--accent);text-decoration:none;display:inline-block;margin-top:5px;">
                  Track on Swiss Post →
                </a>
              <?php endif; ?>

              <?php if ($shipCalc): ?>
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin:16px 0 8px;">Parcel Configuration</div>
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;">

                  <?php if (!empty($shipCalc['missing_data'])): ?>
                    <div style="padding:8px 14px;background:rgba(237,30,40,0.08);font-size:0.75rem;color:var(--accent);border-bottom:1px solid var(--border);">
                      ⚠ Some products are missing weight/dimension data — estimate only
                    </div>
                  <?php endif; ?>

                  <?php
                    $isLetter = !empty($shipCalc['is_letter']);
                    if ($isLetter) {
                        $svcName  = $storedMethod === 'economy' ? 'B-Mail Letter' : 'A-Mail Letter';
                        $svcTime  = $storedMethod === 'economy' ? '2–3 working days' : 'Next working day';
                        $svcIcon  = '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>';
                        $svcColor = '#22c55e';
                    } else {
                        $svcName  = $storedMethod === 'economy' ? 'Swiss Post Economy' : 'Swiss Post Priority';
                        $svcTime  = $storedMethod === 'economy' ? '2–3 working days' : 'Next working day';
                        $svcIcon  = $storedMethod === 'economy'
                            ? '<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'
                            : '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>';
                        $svcColor = 'var(--accent)';
                    }
                    $weightG = round($shipCalc['weight_kg'] * 1000);
                    $weightDisplay = $weightG >= 1000
                        ? round($shipCalc['weight_kg'], 3) . ' kg'
                        : $weightG . ' g';
                  ?>

                  <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $svcColor ?>" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $svcIcon ?></svg>
                    <div style="flex:1;">
                      <div style="font-weight:700;font-size:0.88rem;color:var(--text);"><?= $svcName ?></div>
                      <div style="font-size:0.75rem;color:var(--text3);"><?= $svcTime ?></div>
                    </div>
                    <?php if ($shipCalc['is_bulky'] ?? false): ?>
                      <span style="font-size:0.65rem;font-weight:800;background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.3);border-radius:4px;padding:2px 7px;letter-spacing:0.05em;">BULKY</span>
                    <?php endif; ?>
                  </div>

                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
                    <div style="padding:10px 14px;border-right:1px solid var(--border);">
                      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:var(--text3);margin-bottom:3px;">Total Weight</div>
                      <div style="font-weight:700;font-size:0.92rem;color:var(--text);"><?= $weightDisplay ?></div>
                    </div>
                    <div style="padding:10px 14px;">
                      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:var(--text3);margin-bottom:3px;">Packed Size</div>
                      <div style="font-weight:700;font-size:0.92rem;color:var(--text);"><?= $shipCalc['dimensions'] ?></div>
                    </div>
                    <div style="padding:10px 14px;border-top:1px solid var(--border);border-right:1px solid var(--border);">
                      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:var(--text3);margin-bottom:3px;">Tier</div>
                      <div style="font-weight:700;font-size:0.88rem;color:var(--text);"><?= htmlspecialchars($shipCalc['tier']) ?></div>
                    </div>
                    <div style="padding:10px 14px;border-top:1px solid var(--border);">
                      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:var(--text3);margin-bottom:3px;">Shipping Charged</div>
                      <div style="font-weight:700;font-size:0.88rem;color:var(--accent);">CHF <?= number_format((float)($o['shipping_cost'] ?? $shipCalc['price']), 2) ?></div>
                    </div>
                  </div>

                </div>
              <?php endif; ?>
            </div>

            <!-- Right column: items + totals -->
            <div>
              <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);margin-bottom:8px;">Order Items</div>
              <?php
                $itemsSubtotal = 0;
                foreach ($items_arr as $item):
                    $lineTotal = $item['price'] * $item['qty'];
                    $itemsSubtotal += $lineTotal;
              ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.88rem;">
                  <span style="color:var(--text2);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?= htmlspecialchars($item['name']) ?> <span style="color:var(--text3);">&times; <?= $item['qty'] ?></span>
                    <?php if ($item['is_preorder']): ?>
                      <span style="background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.35);border-radius:4px;padding:1px 6px;font-size:0.65rem;font-weight:800;letter-spacing:0.06em;">PRE-ORDER</span>
                    <?php endif; ?>
                    <?php if (!empty($item['weight_grams'])): ?>
                      <span style="color:var(--text3);font-size:0.75rem;"><?= $item['weight_grams'] * $item['qty'] ?>g</span>
                    <?php endif; ?>
                  </span>
                  <span style="color:var(--text);font-weight:600;white-space:nowrap;"><?= formatPrice($lineTotal) ?></span>
                </div>
              <?php endforeach; ?>
              <?php $shCost = (float)($o['shipping_cost'] ?? 0); ?>
              <?php if ($shCost > 0): ?>
                <div style="display:flex;justify-content:space-between;padding:8px 0 0;font-size:0.85rem;color:var(--text3);">
                  <span>Subtotal</span><span><?= formatPrice($itemsSubtotal) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:0.85rem;color:var(--text3);">
                  <span>Shipping</span><span>CHF <?= number_format($shCost, 2) ?></span>
                </div>
              <?php endif; ?>
              <div style="display:flex;justify-content:space-between;padding:10px 0 0;border-top:1px solid var(--border);margin-top:4px;font-weight:700;font-size:0.9rem;">
                <span>Total</span>
                <span style="color:var(--accent);"><?= formatPrice($o['total_amount']) ?></span>
              </div>
            </div>

          </div>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div style="padding:16px 20px;">
      <div class="pagination">
        <?php for ($i=1; $i<=$pages; $i++): ?>
          <?php if ($i===$page): ?>
            <span class="current"><?= $i ?></span>
          <?php else: ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<style>
.order-main-row:hover td { background: var(--surface2); }
.order-main-row.expanded td { background: var(--surface2); }
</style>
<script>
function toggleOrderDetail(id, row) {
  const detail = document.getElementById(id);
  const icon   = row.querySelector('.expand-icon');
  const open   = detail.style.display === 'none' || detail.style.display === '';
  detail.style.display = open ? 'table-row' : 'none';
  icon.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
  row.classList.toggle('expanded', open);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
