<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$product = $conn->query("SELECT id, name, image_url FROM products WHERE id = $product_id")->fetch_assoc();
if (!$product) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$user = getCurrentUser($conn);

\Stripe\Stripe::setApiKey(trim($_ENV['STRIPE_SECRET_KEY']));

$session = \Stripe\Checkout\Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price_data' => [
            'currency'     => 'chf',
            'unit_amount'  => 199,
            'product_data' => [
                'name'        => 'Picture Proof — ' . $product['name'],
                'description' => 'Photos of the box condition sent to your email',
            ],
        ],
        'quantity' => 1,
    ]],
    'mode'        => 'payment',
    'success_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/pages/picture_proof_return.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/pages/product.php?id=' . $product_id,
    'customer_email' => $user['email'],
]);

$_SESSION['picture_proof_pending'] = [
    'product_id'   => $product['id'],
    'product_name' => $product['name'],
    'product_image'=> $product['image_url'] ?? '',
    'customer_email' => $user['email'],
    'customer_name'  => trim($user['first_name'] . ' ' . $user['last_name']),
];

header('Location: ' . $session->url);
exit;
