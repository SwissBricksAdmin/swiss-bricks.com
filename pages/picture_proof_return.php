<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

\Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$stripeSession = \Stripe\Checkout\Session::retrieve($sessionId);
if ($stripeSession->payment_status !== 'paid') {
    header('Location: ' . BASE_URL . '/pages/checkout.php');
    exit;
}

$pending = $_SESSION['picture_proof_pending'] ?? null;
if (!$pending) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
unset($_SESSION['picture_proof_pending']);

$refNumber = generateProofNumber();

$stmt = $conn->prepare("INSERT INTO picture_proof_requests (reference_number, product_id, product_name, product_image, customer_email, customer_name, stripe_session_id, amount) VALUES (?,?,?,?,?,?,?,1.99)");
$stmt->bind_param('sisssss',
    $refNumber,
    $pending['product_id'],
    $pending['product_name'],
    $pending['product_image'],
    $pending['customer_email'],
    $pending['customer_name'],
    $sessionId
);
$stmt->execute();

// Send customer confirmation email
sendPictureProofConfirmationEmail(
    $pending['customer_email'],
    $pending['customer_name'],
    $pending['product_name'],
    $pending['product_image'] ?? '',
    $refNumber
);

$productName = htmlspecialchars($pending['product_name']);
require_once __DIR__ . '/../includes/header.php';
?>
<div style="max-width:540px;margin:80px auto;text-align:center;padding:0 20px;">
  <div style="width:64px;height:64px;background:rgba(34,197,94,0.12);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  </div>
  <h1 style="font-size:1.6rem;font-weight:800;margin-bottom:10px;">Picture Proof Requested!</h1>
  <p style="color:var(--text3);line-height:1.7;margin-bottom:8px;">Your payment of <strong style="color:var(--text);">CHF 1.99</strong> for Picture Proof of the <strong style="color:var(--text);"><?= $productName ?></strong> was received.</p>
  <p style="color:var(--text3);line-height:1.7;">We'll photograph the set and send the pictures to your email shortly.</p>
  <a href="<?= BASE_URL ?>/" class="btn btn-primary" style="margin-top:32px;display:inline-flex;">Back to Shop</a>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
