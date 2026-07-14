<?php
ob_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

ob_end_clean();
header('Content-Type: application/json');

$items = getCartItems($conn);
if (empty($items)) {
    echo json_encode(['error' => 'Cart is empty — please add items before paying']);
    exit;
}

$total = getCartTotal($conn);
$amountCents = (int)round($total * 100);

\Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

$intent = \Stripe\PaymentIntent::create([
    'amount'   => $amountCents,
    'currency' => 'chf',
    'automatic_payment_methods' => ['enabled' => true],
]);

echo json_encode(['client_secret' => $intent->client_secret]);
