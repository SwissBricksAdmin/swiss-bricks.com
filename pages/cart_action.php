<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$action     = sanitize($_POST['action'] ?? '');
$product_id = (int)($_POST['product_id'] ?? 0);
$qty        = (int)($_POST['qty']        ?? 1);

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product']);
    exit;
}

switch ($action) {
    case 'add':
        $stmt = $conn->prepare("SELECT id, price FROM products WHERE id=? AND stock>0 LIMIT 1");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not available']);
            exit;
        }
        addToCart($product_id, $qty);
        break;

    case 'preorder_add':
        $stmt = $conn->prepare("SELECT id FROM products WHERE id=? AND stock=0 LIMIT 1");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Not eligible for pre-order']);
            exit;
        }
        addToCart($product_id, $qty);
        if (!isset($_SESSION['preorder_items'])) $_SESSION['preorder_items'] = [];
        $_SESSION['preorder_items'][$product_id] = true;
        break;

    case 'update':
        updateCartQty($product_id, $qty);
        break;

    case 'remove':
        removeFromCart($product_id);
        unset($_SESSION['preorder_items'][$product_id]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}

$cartCount = getCartCount();
$total     = getCartTotal($conn);

// Calculate subtotal for this item if updating
$subtotal = 0;
if (isset($_SESSION['cart'][$product_id])) {
    $stmt = $conn->prepare("SELECT price FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $subtotal = $row['price'] * ($_SESSION['cart'][$product_id] ?? 0);
    }
}

echo json_encode([
    'success'           => true,
    'cart_count'        => $cartCount,
    'formatted_total'   => formatPricePlain($total),
    'formatted_subtotal'=> formatPricePlain($subtotal),
]);
