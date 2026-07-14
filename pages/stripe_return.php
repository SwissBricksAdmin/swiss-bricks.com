<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

\Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    header('Location: ' . BASE_URL . '/pages/checkout.php');
    exit;
}

$checkoutSession = \Stripe\Checkout\Session::retrieve($sessionId);

if ($checkoutSession->payment_status !== 'paid') {
    $_SESSION['checkout_error'] = 'Payment was not completed. Please try again.';
    header('Location: ' . BASE_URL . '/pages/checkout.php');
    exit;
}

// Retrieve order details saved in session before redirect
$pending = $_SESSION['pending_order'] ?? null;
if (!$pending) {
    header('Location: ' . BASE_URL . '/pages/checkout.php');
    exit;
}
unset($_SESSION['pending_order']);

$items = getCartItems($conn);
if (empty($items)) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
$total = getCartTotal($conn);

$order_number    = generateOrderNumber();
$user_id         = isLoggedIn() ? getCurrentUser($conn)['id'] : null;
$shipping_method = $pending['shipping_service'] ?? 'priority';
$shipping_cost   = (float)($pending['shipping_cost'] ?? 0);
$voucher         = $pending['voucher'] ?? null;
$voucher_code    = $voucher ? $voucher['code'] : null;
$voucher_discount = $voucher ? (float)$voucher['discount'] : 0;
$grand_total     = max(0, $total + $shipping_cost - $voucher_discount);

$stmt = $conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, status, payment_method, shipping_address, notes, voucher_code, voucher_discount, stripe_session_id, shipping_method, shipping_cost) VALUES (?,?,?,'paid','Credit Card',?,?,?,?,?,?,?)");
$stmt->bind_param('isdsssdssd', $user_id, $order_number, $grand_total, $pending['shipping_address'], $pending['notes'], $voucher_code, $voucher_discount, $sessionId, $shipping_method, $shipping_cost);
$stmt->execute();
$order_id = $conn->insert_id;

foreach ($items as $item) {
    $isPreorder = (!empty($_SESSION['preorder_items'][$item['id']])) ? 1 : 0;
    $stmt2 = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, is_preorder) VALUES (?,?,?,?,?)");
    $stmt2->bind_param('iiidi', $order_id, $item['id'], $item['qty'], $item['price'], $isPreorder);
    $stmt2->execute();
}

if ($voucher) {
    $vc = $conn->real_escape_string($voucher['code']);
    $conn->query("UPDATE vouchers SET used=1, used_at=NOW(), used_by_order_id=$order_id WHERE code='$vc'");
    unset($_SESSION['voucher']);
}

$_SESSION['cart'] = [];
unset($_SESSION['preorder_items']);
$_SESSION['last_order_number']   = $order_number;
$_SESSION['last_payment_method'] = 'Credit Card';

// Send confirmation email
$customerEmail = $checkoutSession->customer_details->email ?? $pending['email'] ?? '';
$customerName  = $pending['first_name'] ?? 'Customer';
if ($customerEmail) {
    sendOrderConfirmationEmail($customerEmail, $customerName, $order_number, $grand_total, $items, 'Credit Card', $pending['shipping_address'], $shipping_method, $shipping_cost);
}

header('Location: ' . BASE_URL . '/pages/order_success.php');
exit;
