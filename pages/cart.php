<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Server-side remove fallback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_id'])) {
    removeFromCart((int)$_POST['remove_id']);
    header('Location: ' . BASE_URL . '/pages/cart.php');
    exit;
}

$items     = getCartItems($conn);
$total     = getCartTotal($conn);
$itemCount = getCartCount();
$pageTitle = 'Shopping Cart';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <nav class="breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a> ›
    <span>Shopping Cart</span>
  </nav>

  <h1 style="margin-bottom:32px;">
    Shopping Cart
    <span style="font-size:1rem;color:var(--text3);font-weight:400;font-family:var(--font-body);" class="cart-item-count">
      — <?= $itemCount ?> <?= $itemCount === 1 ? 'item' : 'items' ?>
    </span>
  </h1>

  <?php if (empty($items)): ?>
    <div class="cart-empty">
      <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
      </svg>
      <h2 style="margin-bottom:12px;">Your cart is empty</h2>
      <p>Looks like you haven't added any LEGO sets yet.</p>
      <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary" style="margin-top:24px;">Start Shopping</a>
    </div>
  <?php else: ?>
    <div class="cart-layout">

      <!-- Cart table -->
      <div>
        <table class="cart-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Price</th>
              <th>Quantity</th>
              <th>Subtotal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="cart-product-info">
                    <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="cart-thumb">
                    <div>
                      <div class="cart-product-name">
                        <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $item['slug'] ?>"><?= htmlspecialchars($item['name']) ?></a>
                      </div>
                      <?php if (!empty($item['is_preorder'])): ?>
                        <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.3);border-radius:4px;padding:2px 7px;font-size:0.7rem;font-weight:700;letter-spacing:0.05em;margin-bottom:2px;">PRE-ORDER</div>
                      <?php endif; ?>
                      <div class="cart-product-cat"><?= htmlspecialchars($item['category_name']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:700;"><?= formatPrice($item['price']) ?></td>
                <td>
                  <div class="cart-qty-controls">
                    <button class="cart-qty-btn" data-id="<?= $item['id'] ?>" data-action="minus">−</button>
                    <input class="cart-qty-input" type="number" data-id="<?= $item['id'] ?>"
                           value="<?= $item['qty'] ?>" min="1" max="99">
                    <button class="cart-qty-btn" data-id="<?= $item['id'] ?>" data-action="plus">+</button>
                  </div>
                </td>
                <td>
                  <span class="cart-subtotal" data-id="<?= $item['id'] ?>" style="font-weight:700;">
                    <?= formatPrice($item['subtotal']) ?>
                  </span>
                </td>
                <td>
                  <form class="cart-remove-form" method="POST">
                    <input type="hidden" name="remove_id" value="<?= $item['id'] ?>">
                    <button type="submit" class="cart-remove-btn" title="Remove">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                      </svg>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:24px;">
          <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-outline">← Continue Shopping</a>
        </div>
      </div>

      <!-- Order summary -->
      <div class="order-summary-card">
        <div class="order-summary-title">Order Summary</div>

        <div class="summary-row">
          <span class="label">Subtotal</span>
          <span><?= formatPrice($total) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Shipping</span>
          <span style="color:var(--text3);font-size:0.85rem;">Calculated at checkout</span>
        </div>


        <div class="summary-total">
          <span class="label">Total</span>
          <span class="value cart-total-value"><?= formatPrice($total) ?></span>
        </div>

        <div class="coupon-row">
          <input type="text" placeholder="Coupon code">
          <button class="btn btn-outline btn-sm">Apply</button>
        </div>

        <a href="<?= BASE_URL ?>/pages/checkout.php" class="btn btn-primary btn-full" style="font-size:1rem;padding:14px;">
          Proceed to Checkout →
        </a>

        <div class="payment-icons-row">
          <span class="payment-icon-chip">TWINT</span>
          <span class="payment-icon-chip">PayPal</span>
          <span class="payment-icon-chip">VISA</span>
          <span class="payment-icon-chip">MC</span>
        </div>
        <div class="secure-badge"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/></svg> Secure Checkout Guaranteed</div>
      </div>

    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
