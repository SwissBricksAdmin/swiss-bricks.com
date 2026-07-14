<?php
ob_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'login_required']);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$user_id    = (int)$_SESSION['user_id'];

if (!$product_id) {
    echo json_encode(['error' => 'invalid']);
    exit;
}

$check = $conn->prepare("SELECT id FROM wishlists WHERE user_id=? AND product_id=?");
$check->bind_param('ii', $user_id, $product_id);
$check->execute();
$exists = $check->get_result()->fetch_assoc();

if ($exists) {
    $stmt = $conn->prepare("DELETE FROM wishlists WHERE user_id=? AND product_id=?");
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    echo json_encode(['wishlisted' => false]);
} else {
    $stmt = $conn->prepare("INSERT INTO wishlists (user_id, product_id) VALUES (?,?)");
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    echo json_encode(['wishlisted' => true]);
}
