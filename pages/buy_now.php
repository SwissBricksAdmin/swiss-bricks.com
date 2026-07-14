<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) {
    header('Location: ' . BASE_URL . '/pages/shop.php');
    exit;
}

$result = $conn->query("SELECT id FROM products WHERE id = $product_id LIMIT 1");
if (!$result || $result->num_rows === 0) {
    header('Location: ' . BASE_URL . '/pages/shop.php');
    exit;
}

addToCart($product_id, 1);

header('Location: ' . BASE_URL . '/pages/checkout.php');
exit;
