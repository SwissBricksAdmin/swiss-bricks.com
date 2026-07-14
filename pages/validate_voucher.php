<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? 'apply';

if ($action === 'remove') {
    unset($_SESSION['voucher']);
    echo json_encode(['ok' => true]);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Please log in to use a voucher.']);
    exit;
}

$code     = strtoupper(trim($_POST['code'] ?? ''));
$subtotal = (float)($_POST['subtotal'] ?? 0);

if (!$code) {
    echo json_encode(['ok' => false, 'msg' => 'Please enter a voucher code.']);
    exit;
}

$user      = getCurrentUser($conn);
$userEmail = $user['email'];

$stmt = $conn->prepare("SELECT * FROM vouchers WHERE code = ? AND used = 0 LIMIT 1");
$stmt->bind_param('s', $code);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();

if (!$v) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid or already used voucher code.']);
    exit;
}

if ($v['assigned_email'] && strtolower($v['assigned_email']) !== strtolower($userEmail)) {
    echo json_encode(['ok' => false, 'msg' => 'This voucher is not assigned to your account.']);
    exit;
}

if ($subtotal < $v['min_spend']) {
    echo json_encode(['ok' => false, 'msg' => 'Minimum spend of CHF ' . number_format($v['min_spend'], 2) . ' required.']);
    exit;
}

$discount = $v['type'] === 'cash'
    ? min((float)$v['amount'], $subtotal)
    : round($subtotal * (float)$v['amount'] / 100, 2);

$_SESSION['voucher'] = [
    'id'       => (int)$v['id'],
    'code'     => $code,
    'type'     => $v['type'],
    'amount'   => (float)$v['amount'],
    'discount' => $discount,
];

echo json_encode([
    'ok'       => true,
    'discount' => $discount,
    'msg'      => 'Voucher applied — you save CHF ' . number_format($discount, 2) . '!',
]);
