<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$items = getCartItems($conn);
if (empty($items)) {
    header('Location: ' . BASE_URL . '/pages/cart.php');
    exit;
}
$total       = getCartTotal($conn);
$currentUser = isLoggedIn() ? getCurrentUser($conn) : null;
$errors      = [];

$shippingPriority = calculateShipping($items, 'priority');
$shippingEconomy  = calculateShipping($items, 'economy');
$selectedService  = $_POST['shipping_service'] ?? 'economy';
$shippingCost     = $selectedService === 'economy' ? $shippingEconomy['price'] : $shippingPriority['price'];
$voucher          = $_SESSION['voucher'] ?? null;
$voucherDiscount  = $voucher ? (float)$voucher['discount'] : 0;
$grandTotal       = max(0, $total + $shippingCost - $voucherDiscount);

$checkoutError = $_SESSION['checkout_error'] ?? null;
unset($_SESSION['checkout_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name     = sanitize($_POST['first_name']     ?? '');
    $last_name      = sanitize($_POST['last_name']      ?? '');
    $email          = sanitize($_POST['email']          ?? '');
    $phone          = sanitize($_POST['phone']          ?? '');
    $flat_room      = sanitize($_POST['flat_room']      ?? '');
    $street         = sanitize($_POST['street']         ?? '');
    $postal_code    = sanitize($_POST['postal_code']    ?? '');
    $city           = sanitize($_POST['city']           ?? '');
    $country        = sanitize($_POST['country']        ?? '');
    $payment_method  = sanitize($_POST['payment_method']  ?? '');
    $notes           = sanitize($_POST['notes']           ?? '');
    $shipping_service = in_array($_POST['shipping_service'] ?? '', ['priority','economy']) ? $_POST['shipping_service'] : 'priority';
    $shippingCost     = $shipping_service === 'economy' ? $shippingEconomy['price'] : $shippingPriority['price'];
    $voucher          = $_SESSION['voucher'] ?? null;
    $voucherDiscount  = $voucher ? (float)$voucher['discount'] : 0;
    $grandTotal       = max(0, $total + $shippingCost - $voucherDiscount);

    if (!$first_name) $errors[] = 'First name is required.';
    if (!$last_name)  $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (!$phone)      $errors[] = 'Phone number is required.';
    if (!$street)       $errors[] = 'Street address is required.';
    if (!$postal_code)  $errors[] = 'Post code is required.';
    if (!$city)         $errors[] = 'City is required.';
    if (!$country)      $errors[] = 'Country is required.';
    if (!$payment_method) $errors[] = 'Please select a payment method.';

    if (empty($errors)) {
        $addr_parts       = array_filter([$flat_room, $street, "$postal_code $city", $country]);
        $shipping_address = "$first_name $last_name\n" . implode(', ', $addr_parts) . "\nPhone: $phone";

        if ($payment_method === 'Credit Card') {
            // Save order details in session, then redirect to Stripe Checkout
            $_SESSION['pending_order'] = [
                'shipping_address' => $shipping_address,
                'notes'            => $notes,
                'email'            => $email,
                'first_name'       => $first_name,
                'shipping_service' => $shipping_service,
                'shipping_cost'    => $shippingCost,
                'voucher'          => $voucher,
            ];

            \Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

            $lineItems = [];
            foreach ($items as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency'     => 'chf',
                        'unit_amount'  => (int)round($item['price'] * 100),
                        'product_data' => ['name' => $item['name']],
                    ],
                    'quantity' => $item['qty'],
                ];
            }
            // Add shipping as a line item
            $isLetterOrder = !empty($shippingPriority['is_letter']);
            if ($isLetterOrder) {
                $serviceLabel = $shipping_service === 'economy' ? 'Swiss Post B-Mail Letter' : 'Swiss Post A-Mail Letter';
            } else {
                $serviceLabel = $shipping_service === 'economy' ? 'Swiss Post Economy (2–3 days)' : 'Swiss Post Priority (next day)';
            }
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'chf',
                    'unit_amount'  => (int)round($shippingCost * 100),
                    'product_data' => ['name' => $serviceLabel],
                ],
                'quantity' => 1,
            ];

            $protocol   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'];
            $baseUrl    = $protocol . '://' . $host . BASE_URL;

            $stripeParams = [
                'line_items'  => $lineItems,
                'mode'        => 'payment',
                'success_url' => $baseUrl . '/pages/stripe_return.php?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $baseUrl . '/pages/checkout.php',
            ];
            if ($voucher && $voucher['discount'] > 0) {
                $coupon = \Stripe\Coupon::create([
                    'amount_off' => (int)round($voucher['discount'] * 100),
                    'currency'   => 'chf',
                    'duration'   => 'once',
                    'name'       => 'Voucher ' . $voucher['code'],
                ]);
                $stripeParams['discounts'] = [['coupon' => $coupon->id]];
            }
            $stripeSession = \Stripe\Checkout\Session::create($stripeParams);

            header('Location: ' . $stripeSession->url);
            exit;
        }

        // Bank Transfer — create order directly
        $order_number    = generateOrderNumber();
        $user_id         = $currentUser ? $currentUser['id'] : null;
        $voucherCode     = $voucher ? $voucher['code'] : null;
        $voucherDiscount = $voucher ? (float)$voucher['discount'] : 0;

        $stmt = $conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, status, payment_method, shipping_address, notes, voucher_code, voucher_discount, shipping_method, shipping_cost) VALUES (?,?,?,'pending','Bank Transfer',?,?,?,?,?,?)");
        $stmt->bind_param('isdsssdsd', $user_id, $order_number, $grandTotal, $shipping_address, $notes, $voucherCode, $voucherDiscount, $shipping_service, $shippingCost);
        $stmt->execute();
        $order_id = $conn->insert_id;

        foreach ($items as $item) {
            $stmt2 = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?,?,?,?)");
            $stmt2->bind_param('iiid', $order_id, $item['id'], $item['qty'], $item['price']);
            $stmt2->execute();
        }

        if ($voucher) {
            $vc = $conn->real_escape_string($voucher['code']);
            $conn->query("UPDATE vouchers SET used=1, used_at=NOW(), used_by_order_id=$order_id WHERE code='$vc'");
            unset($_SESSION['voucher']);
        }

        $_SESSION['cart'] = [];
        $_SESSION['last_order_number']   = $order_number;
        $_SESSION['last_payment_method'] = 'Bank Transfer';

        sendOrderConfirmationEmail($email, $first_name, $order_number, $grandTotal, $items, 'Bank Transfer', $shipping_address, $shipping_service, $shippingCost);

        header('Location: ' . BASE_URL . '/pages/order_success.php');
        exit;
    }
}

$pageTitle = 'Checkout';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <nav class="breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a> ›
    <a href="<?= BASE_URL ?>/pages/cart.php">Cart</a> ›
    <span>Checkout</span>
  </nav>

  <h1 style="margin-bottom:32px;">Checkout</h1>

  <?php if ($checkoutError): ?>
    <div class="alert alert-error"><?= htmlspecialchars($checkoutError) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <strong>Please fix these errors:</strong><br>
      <?= implode('<br>', $errors) ?>
    </div>
  <?php endif; ?>

  <form method="POST" id="checkout-form">
    <div class="checkout-layout">

      <!-- Left column -->
      <div>

        <!-- Section 1: Contact -->
        <div class="checkout-step">
          <div class="step-header">
            <div class="step-num">1</div>
            <div class="step-title">Contact Information</div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label>First Name *</label>
              <input type="text" name="first_name" value="<?= htmlspecialchars($currentUser['first_name'] ?? ($_POST['first_name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
              <label>Last Name *</label>
              <input type="text" name="last_name" value="<?= htmlspecialchars($currentUser['last_name'] ?? ($_POST['last_name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
              <label>Email Address *</label>
              <input type="email" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? ($_POST['email'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
              <label>Phone Number *</label>
              <input type="tel" name="phone" placeholder="+41 79 000 00 00" value="<?= htmlspecialchars($currentUser['phone'] ?? ($_POST['phone'] ?? '')) ?>" required>
            </div>
          </div>
        </div>

        <!-- Section 2: Shipping -->
        <div class="checkout-step">
          <div class="step-header">
            <div class="step-num">2</div>
            <div class="step-title">Shipping Address</div>
          </div>
          <div class="form-group">
            <label>Flat / Room</label>
            <input type="text" name="flat_room" placeholder="Apt 4B" value="<?= htmlspecialchars($currentUser['flat_room'] ?? ($_POST['flat_room'] ?? '')) ?>">
          </div>
          <div class="form-group">
            <label>Street and House Number *</label>
            <input type="text" name="street" placeholder="Bahnhofstrasse 1" value="<?= htmlspecialchars($currentUser['street'] ?? ($_POST['street'] ?? '')) ?>" required>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label>PLZ (Post Code) *</label>
              <input type="text" name="postal_code" placeholder="8001" value="<?= htmlspecialchars($currentUser['postal_code'] ?? ($_POST['postal_code'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
              <label>City *</label>
              <input type="text" name="city" placeholder="Zürich" value="<?= htmlspecialchars($currentUser['city'] ?? ($_POST['city'] ?? '')) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Country *</label>
            <input type="text" name="country" placeholder="Switzerland" value="<?= htmlspecialchars($currentUser['country'] ?? ($_POST['country'] ?? '')) ?>" required>
          </div>
          <div class="form-group">
            <label>Order Notes (optional)</label>
            <textarea name="notes" rows="3" placeholder="Any special instructions…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
          </div>
        </div>

        <!-- Section 3: Payment -->
        <div class="checkout-step">
          <div class="step-header">
            <div class="step-num">3</div>
            <div class="step-title">Payment Method</div>
          </div>

          <label class="payment-method-card" id="pm-card-credit">
            <input type="radio" name="payment_method" value="Credit Card" id="pm-credit">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            <div>
              <div style="font-weight:700;">Card, PayPal or TWINT</div>
              <div style="font-size:0.8rem;color:var(--text3);">Visa, Mastercard, PayPal, TWINT &mdash; secured by Stripe</div>
            </div>
          </label>

          <label class="payment-method-card" id="pm-card-bank">
            <input type="radio" name="payment_method" value="Bank Transfer" id="pm-bank">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18" x2="6" y2="11"/><line x1="10" y1="18" x2="10" y2="11"/><line x1="14" y1="18" x2="14" y2="11"/><line x1="18" y1="18" x2="18" y2="11"/><polygon points="12 2 2 7 22 7"/></svg>
            <div>
              <div style="font-weight:700;">Bank Transfer</div>
              <div style="font-size:0.8rem;color:var(--text3);">Pay by Swiss bank transfer (IBAN)</div>
            </div>
          </label>

        </div>

        <!-- Section 4: Shipping Method -->
        <div class="checkout-step">
          <div class="step-header">
            <div class="step-num">4</div>
            <div class="step-title">Shipping Method</div>
          </div>

          <?php if ($shippingPriority['missing_data']): ?>
            <div style="padding:12px 16px;background:rgba(237,30,40,0.08);border:1px solid rgba(237,30,40,0.2);border-radius:8px;font-size:0.85rem;color:var(--text2);">
              Some products are missing weight/dimension data. Shipping cost will be confirmed after ordering.
            </div>
          <?php endif; ?>

          <?php
            $isLetter = !empty($shippingPriority['is_letter']);
            if ($isLetter) {
                $priorityLabel = 'A-Mail Letter — Next working day';
                $economyLabel  = 'B-Mail Letter — 2–3 working days';
                $priorityIcon  = '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>';
                $economyIcon   = '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>';
            } else {
                $priorityLabel = 'Swiss Post Priority — Next working day';
                $economyLabel  = 'Swiss Post Economy — 2–3 working days';
                $priorityIcon  = '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>';
                $economyIcon   = '<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>';
            }
          ?>

          <label class="payment-method-card" style="cursor:pointer;">
            <input type="radio" name="shipping_service" value="economy" id="ship-economy"
              <?= $selectedService === 'economy' ? 'checked' : '' ?> onchange="updateShippingTotal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $economyIcon ?></svg>
            <div style="flex:1;">
              <div style="font-weight:700;"><?= $economyLabel ?></div>
              <div style="font-size:0.8rem;color:var(--text3);"><?= $shippingEconomy['tier'] ?> · <?= $shippingEconomy['weight_kg'] ?> kg · <?= $shippingEconomy['dimensions'] ?></div>
            </div>
            <div style="font-weight:700;color:var(--accent);white-space:nowrap;">CHF <?= number_format($shippingEconomy['price'], 2) ?></div>
          </label>

          <label class="payment-method-card" style="cursor:pointer;margin-top:8px;">
            <input type="radio" name="shipping_service" value="priority" id="ship-priority"
              <?= $selectedService === 'priority' ? 'checked' : '' ?> onchange="updateShippingTotal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $priorityIcon ?></svg>
            <div style="flex:1;">
              <div style="font-weight:700;"><?= $priorityLabel ?></div>
              <div style="font-size:0.8rem;color:var(--text3);"><?= $shippingPriority['tier'] ?> · <?= $shippingPriority['weight_kg'] ?> kg · <?= $shippingPriority['dimensions'] ?></div>
            </div>
            <div style="font-weight:700;color:var(--accent);white-space:nowrap;">CHF <?= number_format($shippingPriority['price'], 2) ?></div>
          </label>
        </div>

      </div><!-- end left column -->

      <!-- Right column: order summary -->
      <div class="order-summary-card" style="height:fit-content;">
        <div class="step-header" style="margin-bottom:20px;">
          <div class="step-num check">✓</div>
          <div class="step-title">Order Review</div>
        </div>

        <?php foreach ($items as $item): ?>
          <div class="order-review-item">
            <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="order-review-thumb">
            <div>
              <div class="order-review-name"><?= htmlspecialchars($item['name']) ?></div>
              <div class="order-review-qty">Qty: <?= $item['qty'] ?></div>
            </div>
            <div class="order-review-subtotal"><?= formatPrice($item['subtotal']) ?></div>
          </div>
        <?php endforeach; ?>

        <!-- Voucher input -->
        <div style="margin-top:16px;margin-bottom:4px;">
          <?php if ($voucher): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);border-radius:8px;padding:10px 14px;">
              <div>
                <span style="font-family:monospace;font-weight:800;color:#22c55e;font-size:0.88rem;letter-spacing:0.06em;"><?= htmlspecialchars($voucher['code']) ?></span>
                <span style="font-size:0.78rem;color:var(--text3);margin-left:8px;">−CHF <?= number_format($voucher['discount'], 2) ?></span>
              </div>
              <button type="button" onclick="removeVoucher()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:0.78rem;padding:0;transition:color 0.15s;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text3)'">Remove</button>
            </div>
          <?php else: ?>
            <div style="display:flex;gap:8px;">
              <input type="text" id="voucher-input" placeholder="Voucher code" style="flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:0.85rem;outline:none;transition:border-color 0.15s;" onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'" onkeydown="if(event.key==='Enter'){event.preventDefault();applyVoucher();}">
              <button type="button" onclick="applyVoucher()" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 14px;color:var(--text2);font-size:0.84rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:border-color 0.15s,color 0.15s;" onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'">Apply</button>
            </div>
            <div id="voucher-msg" style="font-size:0.78rem;margin-top:5px;"></div>
          <?php endif; ?>
        </div>

        <div class="summary-row" style="margin-top:12px;">
          <span class="label">Subtotal</span>
          <span><?= formatPrice($total) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Shipping</span>
          <span id="summary-shipping" style="font-weight:600;">CHF <?= number_format($shippingCost, 2) ?></span>
        </div>
        <?php if ($voucher): ?>
          <div class="summary-row" id="voucher-row">
            <span class="label" style="color:#22c55e;">Voucher</span>
            <span style="color:#22c55e;font-weight:700;" id="summary-voucher">−CHF <?= number_format($voucherDiscount, 2) ?></span>
          </div>
        <?php else: ?>
          <div class="summary-row" id="voucher-row" style="display:none;">
            <span class="label" style="color:#22c55e;">Voucher</span>
            <span style="color:#22c55e;font-weight:700;" id="summary-voucher"></span>
          </div>
        <?php endif; ?>
        <div class="summary-total">
          <span class="label">Total</span>
          <div style="text-align:right;">
            <span class="value" id="summary-grand-total">CHF <?= number_format($grandTotal, 2) ?></span>
            <div style="font-size:0.72rem;color:var(--text3);margin-top:2px;">non-refundable</div>
          </div>
        </div>

        <button type="submit" id="place-order-btn" class="btn btn-primary btn-full" style="font-size:1rem;padding:15px;margin-top:8px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/></svg>
          <span id="place-order-label">Place Order — CHF <?= number_format($grandTotal, 2) ?></span>
        </button>
        <div class="secure-badge">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/></svg>
          Your information is secure &amp; encrypted
        </div>
      </div>

    </div>
  </form>
</div>

<script>
var subtotal        = <?= $total ?>;
var shipping        = { priority: <?= $shippingPriority['price'] ?>, economy: <?= $shippingEconomy['price'] ?> };
var voucherDiscount = <?= $voucherDiscount ?>;

function getShippingCost() {
  var sel = document.querySelector('input[name="shipping_service"]:checked');
  return sel ? shipping[sel.value] : shipping.economy;
}

function updateTotals() {
  var cost  = getShippingCost();
  var grand = Math.max(0, subtotal + cost - voucherDiscount);
  document.getElementById('summary-shipping').textContent    = 'CHF ' + cost.toFixed(2);
  document.getElementById('summary-grand-total').textContent = 'CHF ' + grand.toFixed(2);
  document.getElementById('place-order-label').textContent   = 'Place Order — CHF ' + grand.toFixed(2);
}

function updateShippingTotal() { updateTotals(); }

function applyVoucher() {
  var code = (document.getElementById('voucher-input')?.value || '').trim();
  if (!code) return;
  var fd = new FormData();
  fd.append('code', code);
  fd.append('subtotal', subtotal);
  fetch('<?= BASE_URL ?>/pages/validate_voucher.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      var msg = document.getElementById('voucher-msg');
      if (data.ok) {
        voucherDiscount = data.discount;
        msg.style.color = '#22c55e';
        msg.textContent = data.msg;
        document.getElementById('voucher-row').style.display = '';
        document.getElementById('summary-voucher').textContent = '−CHF ' + data.discount.toFixed(2);
        updateTotals();
      } else {
        msg.style.color = 'var(--accent)';
        msg.textContent = data.msg;
      }
    });
}

function removeVoucher() {
  fetch('<?= BASE_URL ?>/pages/validate_voucher.php', {
    method: 'POST',
    body: new URLSearchParams({ action: 'remove' })
  }).then(() => { window.location.reload(); });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
