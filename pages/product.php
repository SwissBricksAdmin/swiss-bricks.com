<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = sanitize($_GET['slug'] ?? '');
if (!$slug) { header('Location: ' . BASE_URL . '/pages/shop.php'); exit; }

$stmt = $conn->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON p.category_id=c.id WHERE p.slug=? LIMIT 1");
$stmt->bind_param('s', $slug);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) { header('Location: ' . BASE_URL . '/pages/shop.php'); exit; }

// Related products (same category, exclude current)
$pid      = $product['id'];
$catId    = $product['category_id'];
$related  = $conn->query("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id=c.id WHERE p.category_id=$catId AND p.id != $pid ORDER BY p.featured DESC LIMIT 4");

// Wishlist state for logged-in users
$wishlisted = false;
if (isLoggedIn()) {
    $wChk = $conn->prepare("SELECT id FROM wishlists WHERE user_id=? AND product_id=?");
    $wChk->bind_param('ii', $_SESSION['user_id'], $product['id']);
    $wChk->execute();
    $wishlisted = (bool)$wChk->get_result()->fetch_assoc();
}

// Handle review submission
$reviewMsg = '';
$reviewErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating     = (int)($_POST['rating'] ?? 0);
    $reviewText = trim($_POST['review_text'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $reviewErr = 'Please select a rating.';
    } elseif (!$reviewText) {
        $reviewErr = 'Please write a review.';
    } elseif (!$username || !$email) {
        $reviewErr = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reviewErr = 'Please enter a valid email address.';
    } else {
        $userId = isLoggedIn() ? (int)$_SESSION['user_id'] : null;
        $stmt2 = $conn->prepare("INSERT INTO reviews (product_id, user_id, username, email, rating, review_text) VALUES (?,?,?,?,?,?)");
        $stmt2->bind_param('iissis', $pid, $userId, $username, $email, $rating, $reviewText);
        $stmt2->execute();
        $reviewMsg = 'Thank you for your review!';
    }
}

// Fetch reviews for this product
$reviewsResult = $conn->query("SELECT * FROM reviews WHERE product_id=$pid ORDER BY created_at DESC");
$reviewCount   = $reviewsResult ? $reviewsResult->num_rows : 0;
$avgRating     = $conn->query("SELECT AVG(rating) FROM reviews WHERE product_id=$pid")->fetch_row()[0];

// Fetch gallery images
$galleryImgs = [];
$gStmt = $conn->prepare("SELECT file_path FROM product_images WHERE product_id=? ORDER BY sort_order ASC");
$gStmt->bind_param('i', $pid);
$gStmt->execute();
$gRes = $gStmt->get_result();
while ($gRow = $gRes->fetch_assoc()) {
    $galleryImgs[] = BASE_URL . '/' . $gRow['file_path'];
}
if (empty($galleryImgs) && !empty($product['image_url'])) {
    $galleryImgs[] = $product['image_url'];
}

$pageTitle = $product['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <!-- Breadcrumb -->
  <nav class="breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a> ›
    <a href="<?= BASE_URL ?>/pages/shop.php">Shop</a> ›
    <a href="<?= BASE_URL ?>/pages/shop.php?cat=<?= $product['category_slug'] ?>"><?= htmlspecialchars($product['category_name']) ?></a> ›
    <span><?= htmlspecialchars($product['name']) ?></span>
  </nav>

  <!-- Product detail -->
  <div class="product-detail-layout" style="margin-bottom:80px;">
    <div class="product-detail-image" style="position:relative;overflow:visible;background:transparent;border:none;box-shadow:none;aspect-ratio:unset;">
      <?php $totalImgs = count($galleryImgs); ?>
      <!-- Main image -->
      <div id="gallery-main" style="position:relative;border-radius:var(--radius-lg);overflow:hidden;aspect-ratio:4/3;background:#ffffff;border:1px solid var(--accent);box-shadow:0 0 18px rgba(237,30,40,0.45),0 0 6px rgba(237,30,40,0.25);">
        <?php foreach ($galleryImgs as $i => $src): ?>
          <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($product['name']) ?>"
               id="gallery-img-<?= $i ?>"
               style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;transform:scale(0.95);transition:opacity 0.25s ease;<?= $i === 0 ? 'opacity:1;' : 'opacity:0;pointer-events:none;' ?>">
        <?php endforeach; ?>

        <?php if ($totalImgs > 1): ?>
          <!-- Prev arrow -->
          <button onclick="galleryNav(-1)" aria-label="Previous image"
                  style="position:absolute;left:10px;top:50%;transform:translateY(-50%);z-index:3;background:rgba(0,0,0,0.55);border:none;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;transition:background 0.15s;"
                  onmouseover="this.style.background='rgba(237,30,40,0.8)'" onmouseout="this.style.background='rgba(0,0,0,0.55)'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <!-- Next arrow -->
          <button onclick="galleryNav(1)" aria-label="Next image"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);z-index:3;background:rgba(0,0,0,0.55);border:none;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;transition:background 0.15s;"
                  onmouseover="this.style.background='rgba(237,30,40,0.8)'" onmouseout="this.style.background='rgba(0,0,0,0.55)'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        <?php endif; ?>
      </div>

      <?php if ($totalImgs > 1): ?>
        <!-- Thumbnail strip -->
        <div style="display:flex;gap:8px;margin-top:12px;justify-content:center;flex-wrap:wrap;">
          <?php foreach ($galleryImgs as $i => $src): ?>
            <button onclick="galleryGoTo(<?= $i ?>)" id="gallery-thumb-<?= $i ?>"
                    style="width:60px;height:60px;padding:0;border-radius:8px;overflow:hidden;background:#fff;border:2px solid <?= $i === 0 ? 'var(--accent)' : 'var(--border)' ?>;cursor:pointer;transition:border-color 0.15s;flex-shrink:0;"
                    onmouseover="this.style.borderColor='var(--accent)'" onmouseout="if(galleryIndex!==<?= $i ?>)this.style.borderColor='var(--border)'">
              <img src="<?= htmlspecialchars($src) ?>" alt="" style="width:100%;height:100%;object-fit:contain;">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <script>
      var galleryIndex = 0;
      var galleryTotal = <?= $totalImgs ?>;
      function galleryGoTo(n) {
        document.getElementById('gallery-img-' + galleryIndex).style.opacity = '0';
        document.getElementById('gallery-img-' + galleryIndex).style.pointerEvents = 'none';
        document.getElementById('gallery-thumb-' + galleryIndex) && (document.getElementById('gallery-thumb-' + galleryIndex).style.borderColor = 'var(--border)');
        galleryIndex = (n + galleryTotal) % galleryTotal;
        document.getElementById('gallery-img-' + galleryIndex).style.opacity = '1';
        document.getElementById('gallery-img-' + galleryIndex).style.pointerEvents = '';
        document.getElementById('gallery-thumb-' + galleryIndex) && (document.getElementById('gallery-thumb-' + galleryIndex).style.borderColor = 'var(--accent)');
      }
      function galleryNav(dir) { galleryGoTo(galleryIndex + dir); }
      </script>
    </div>

    <div class="product-detail-info">
      <?php if ($product['badge']): ?>
        <div class="product-detail-badge">
          <span class="product-badge badge-<?= $product['badge'] ?>"><?= $product['badge'] ?></span>
        </div>
      <?php endif; ?>

      <div class="product-category" style="margin-bottom:8px;"><?= htmlspecialchars($product['category_name']) ?></div>
      <h1 class="product-detail-name"><?= htmlspecialchars($product['name']) ?></h1>

      <div class="product-rating" style="margin:12px 0;">
        <span class="product-stars" style="font-size:1rem;">★★★★★</span>
      </div>

      <?php
        $hasDeal   = !empty($product['deal_price']) && (empty($product['deal_end']) || strtotime($product['deal_end']) > time());
        $showPrice = $hasDeal ? $product['deal_price'] : $product['price'];
        $crossPrice = $hasDeal ? $product['price'] : $product['old_price'];
      ?>
      <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;">
        <span class="product-detail-price"><?= formatPrice($showPrice) ?></span>
        <?php if ($crossPrice): ?>
          <span class="product-detail-old"><?= formatPrice($crossPrice) ?></span>
        <?php endif; ?>
        <span style="font-size:0.75rem;color:var(--text2);white-space:nowrap;">Shipping calculated at checkout</span>
      </div>

      <?php if ($product['stock'] > 0): ?>
        <div class="stock-badge" style="margin-bottom:20px;margin-top:20px;">In stock (<?= $product['stock'] ?> available)</div>

        <div class="qty-row">
          <div class="qty-controls">
            <button class="qty-btn" onclick="changeQty(-1)" type="button">−</button>
            <input class="qty-input" type="number" id="productQty" data-id="<?= $product['id'] ?>" value="1" min="1" max="99">
            <button class="qty-btn" onclick="changeQty(1)" type="button">+</button>
          </div>
          <button class="btn btn-primary add-to-cart-btn" data-id="<?= $product['id'] ?>" style="flex:1;padding-top:0;padding-bottom:0;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Add to Cart
          </button>
        </div>

        <div style="display:flex;gap:10px;align-items:stretch;">
          <a href="<?= BASE_URL ?>/pages/cart.php" class="btn btn-outline" style="flex:1;text-align:center;">View Cart</a>
          <button id="wishlist-btn"
            data-id="<?= $product['id'] ?>"
            data-wishlisted="<?= $wishlisted ? '1' : '0' ?>"
            data-login-url="<?= BASE_URL ?>/pages/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
            style="display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;border:none;cursor:pointer;padding:10px 20px;border-radius:var(--radius);font-size:0.88rem;font-weight:600;box-shadow:var(--shadow-red);transition:box-shadow var(--transition),transform var(--transition);white-space:nowrap;"
            onmouseover="this.style.background='#f5686a';this.style.boxShadow='0 12px 40px rgba(237,30,40,0.35)';this.style.transform='translateY(-1px)'"
            onmouseout="this.style.background='#ED1E28';this.style.boxShadow='var(--shadow-red)';this.style.transform='translateY(0)'">
            <svg id="wishlist-icon" width="16" height="16" viewBox="0 0 24 24"
              fill="<?= $wishlisted ? '#fff' : 'none' ?>"
              stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <span id="wishlist-label"><?php
              if ($wishlisted) echo 'Saved to Wishlist';
              elseif ($product['stock'] == 0) echo 'Notify me when back in stock';
              else echo 'Add to Wishlist';
            ?></span>
          </button>
        </div>
        <a href="<?= BASE_URL ?>/pages/picture_proof_checkout.php?product_id=<?= $product['id'] ?>"
           style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:11px 16px;background:var(--surface2);border:1px solid transparent;border-radius:var(--radius);color:#fff;font-size:0.84rem;font-weight:600;text-decoration:none;transition:border-color 0.18s,color 0.18s,box-shadow 0.18s;"
           onmouseover="this.style.borderColor='var(--accent)';this.style.color='#ED1E28';this.style.boxShadow='0 0 0 1px rgba(237,30,40,0.2),0 4px 20px rgba(237,30,40,0.15)'"
           onmouseout="this.style.borderColor='transparent';this.style.color='#fff';this.style.boxShadow='none'">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Request Picture Proof — CHF 1.99
        </a>
        <p style="margin:8px 0 0;font-size:0.78rem;color:var(--text2);line-height:1.6;">Want to have the certainty of the stock of the product and the condition of the box? You can request picture proof at a small surcharge, to cover the time needed to provide this for you at our storage facility.</p>
      <?php else: ?>
        <div style="display:flex;gap:10px;align-items:stretch;margin-top:20px;">
          <button id="preorder-btn"
            onclick="document.getElementById('preorder-modal').style.display='flex'"
            style="display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;border:none;cursor:pointer;padding:12px 24px;border-radius:var(--radius);font-size:0.95rem;font-weight:700;box-shadow:var(--shadow-red);transition:box-shadow var(--transition),transform var(--transition);"
            onmouseover="this.style.background='#f5686a';this.style.boxShadow='0 12px 40px rgba(237,30,40,0.35)';this.style.transform='translateY(-1px)'"
            onmouseout="this.style.background='#ED1E28';this.style.boxShadow='var(--shadow-red)';this.style.transform='translateY(0)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Request &amp; Pre-order
          </button>
          <button id="wishlist-btn"
            data-id="<?= $product['id'] ?>"
            data-wishlisted="<?= $wishlisted ? '1' : '0' ?>"
            data-login-url="<?= BASE_URL ?>/pages/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
            style="display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;border:none;cursor:pointer;padding:10px 20px;border-radius:var(--radius);font-size:0.88rem;font-weight:600;box-shadow:var(--shadow-red);transition:box-shadow var(--transition),transform var(--transition);white-space:nowrap;"
            onmouseover="this.style.background='#f5686a';this.style.boxShadow='0 12px 40px rgba(237,30,40,0.35)';this.style.transform='translateY(-1px)'"
            onmouseout="this.style.background='#ED1E28';this.style.boxShadow='var(--shadow-red)';this.style.transform='translateY(0)'">
            <svg id="wishlist-icon" width="16" height="16" viewBox="0 0 24 24"
              fill="<?= $wishlisted ? '#fff' : 'none' ?>"
              stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <span id="wishlist-label"><?php
              if ($wishlisted) echo 'Saved to Wishlist';
              else echo 'Notify me when back in stock';
            ?></span>
          </button>
        </div>
        <a href="<?= BASE_URL ?>/pages/picture_proof_checkout.php?product_id=<?= $product['id'] ?>"
           style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:11px 16px;background:var(--surface2);border:1px solid transparent;border-radius:var(--radius);color:#fff;font-size:0.84rem;font-weight:600;text-decoration:none;transition:border-color 0.18s,color 0.18s,box-shadow 0.18s;"
           onmouseover="this.style.borderColor='var(--accent)';this.style.color='#ED1E28';this.style.boxShadow='0 0 0 1px rgba(237,30,40,0.2),0 4px 20px rgba(237,30,40,0.15)'"
           onmouseout="this.style.borderColor='transparent';this.style.color='#fff';this.style.boxShadow='none'">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Request Picture Proof — CHF 1.99
        </a>
      <?php endif; ?>

      <!-- Product meta + trust badges -->
      <div style="margin-top:28px;padding-top:24px;border-top:1px solid var(--border);display:flex;align-items:flex-end;gap:24px;flex-wrap:wrap;">
        <div style="flex-shrink:0;">
          <div style="font-size:0.75rem;color:var(--text3);font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:4px;">Category</div>
          <div style="font-size:0.9rem;"><?= htmlspecialchars($product['category_name']) ?></div>
        </div>
        <?php if (!empty($product['release_year'])): ?>
        <div style="flex-shrink:0;">
          <div style="font-size:0.75rem;color:var(--text3);font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:4px;">Released</div>
          <div style="font-size:0.9rem;"><?= (int)$product['release_year'] ?></div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:var(--text3);background:var(--surface2);padding:6px 12px;border-radius:8px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> Sealed original</span>
          <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:var(--text3);background:var(--surface2);padding:6px 12px;border-radius:8px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> Picture proof</span>
          <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:var(--text3);background:var(--surface2);padding:6px 12px;border-radius:8px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Swiss Post</span>
        </div>
      </div>

      <!-- Description -->
      <div class="product-detail-desc" style="margin-top:28px;padding-top:24px;border-top:1px solid var(--border);">
        <p><?= nl2br(htmlspecialchars($product['description'])) ?></p>
      </div>
    </div>
  </div>

  <!-- Reviews -->
  <div style="margin-bottom:60px;padding-top:48px;border-top:1px solid var(--border);">
    <div style="display:flex;align-items:baseline;gap:16px;margin-bottom:32px;flex-wrap:wrap;">
      <h2 style="margin:0;font-size:1.5rem;">Customer Reviews</h2>
      <?php if ($reviewCount > 0): ?>
        <span style="font-size:1.1rem;"><span style="color:#f59e0b;"><?= str_repeat('★', (int)round($avgRating)) ?></span><span style="color:#3a3a4a;"><?= str_repeat('★', 5 - (int)round($avgRating)) ?></span></span>
        <span style="color:var(--text3);font-size:0.85rem;"><?= number_format((float)$avgRating, 1) ?> · <?= $reviewCount ?> review<?= $reviewCount !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start;">

      <!-- Existing reviews list -->
      <div>
        <?php if ($reviewCount === 0): ?>
          <p style="color:var(--text3);font-size:0.9rem;">No reviews yet — be the first!</p>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:20px;">
            <?php $reviewsResult->data_seek(0); while ($rev = $reviewsResult->fetch_assoc()): ?>
              <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:12px;flex-wrap:wrap;">
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:34px;height:34px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;color:#fff;flex-shrink:0;">
                      <?= strtoupper(substr($rev['username'], 0, 1)) ?>
                    </div>
                    <div>
                      <div style="font-weight:600;font-size:0.88rem;"><?= htmlspecialchars($rev['username']) ?></div>
                      <div style="font-size:0.72rem;color:var(--text3);"><?= date('d M Y', strtotime($rev['created_at'])) ?></div>
                    </div>
                  </div>
                  <span style="font-size:0.95rem;letter-spacing:1px;"><span style="color:#f59e0b;"><?= str_repeat('★', (int)$rev['rating']) ?></span><span style="color:#3a3a4a;"><?= str_repeat('★', 5 - (int)$rev['rating']) ?></span></span>
                </div>
                <p style="margin:0;font-size:0.88rem;color:var(--text2);line-height:1.6;"><?= nl2br(htmlspecialchars($rev['review_text'])) ?></p>
              </div>
            <?php endwhile; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Write a review form -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;">
        <h3 style="margin:0 0 20px;font-size:1rem;">Write a Review</h3>
        <?php if ($reviewMsg): ?>
          <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:12px 16px;color:#10b981;font-size:0.88rem;margin-bottom:16px;"><?= htmlspecialchars($reviewMsg) ?></div>
        <?php elseif ($reviewErr): ?>
          <div style="background:rgba(237,30,40,0.1);border:1px solid rgba(237,30,40,0.3);border-radius:8px;padding:12px 16px;color:var(--accent);font-size:0.88rem;margin-bottom:16px;"><?= htmlspecialchars($reviewErr) ?></div>
        <?php endif; ?>
        <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
          <!-- Star rating picker -->
          <div>
            <label style="font-size:0.8rem;color:var(--text3);font-weight:600;display:block;margin-bottom:6px;">Rating *</label>
            <div style="display:flex;gap:4px;" id="star-picker">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <label style="cursor:pointer;font-size:1.6rem;color:var(--text3);line-height:1;transition:color 0.1s;" id="star-label-<?= $i ?>">
                  <input type="radio" name="rating" value="<?= $i ?>" style="display:none;"
                    onchange="updateStars(<?= $i ?>)" <?= (int)($_POST['rating'] ?? 0) === $i ? 'checked' : '' ?>>★
                </label>
              <?php endfor; ?>
            </div>
          </div>
          <div>
            <label style="font-size:0.8rem;color:var(--text3);font-weight:600;display:block;margin-bottom:6px;">Name *</label>
            <input type="text" name="username" class="form-input" placeholder="Your name"
              value="<?= htmlspecialchars(isLoggedIn() ? (getCurrentUser($conn)['first_name'] . ' ' . getCurrentUser($conn)['last_name']) : ($_POST['username'] ?? '')) ?>"
              style="width:100%;box-sizing:border-box;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text1);font-size:0.88rem;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;color:var(--text3);font-weight:600;display:block;margin-bottom:6px;">Email *</label>
            <input type="email" name="email" class="form-input" placeholder="your@email.com"
              value="<?= htmlspecialchars(isLoggedIn() ? getCurrentUser($conn)['email'] : ($_POST['email'] ?? '')) ?>"
              style="width:100%;box-sizing:border-box;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text1);font-size:0.88rem;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;color:var(--text3);font-weight:600;display:block;margin-bottom:6px;">Review *</label>
            <textarea name="review_text" placeholder="Share your experience with this product…" rows="4"
              style="width:100%;box-sizing:border-box;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text1);font-size:0.88rem;outline:none;resize:vertical;font-family:inherit;"><?= htmlspecialchars($_POST['review_text'] ?? '') ?></textarea>
          </div>
          <button type="submit" name="submit_review"
            style="background:var(--accent);color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:0.9rem;font-weight:700;cursor:pointer;transition:background 0.15s;align-self:flex-start;"
            onmouseover="this.style.background='#f5686a'" onmouseout="this.style.background='var(--accent)'">
            Submit Review
          </button>
        </form>
        <script>
        function updateStars(n) {
          for (var i = 1; i <= 5; i++) {
            document.getElementById('star-label-' + i).style.color = i <= n ? '#f59e0b' : 'var(--text3)';
          }
        }
        // Init colour if rating was pre-selected (after failed submit)
        (function(){ var checked = document.querySelector('#star-picker input:checked'); if (checked) updateStars(+checked.value); })();
        // Hover preview
        document.querySelectorAll('#star-picker label').forEach(function(lbl, idx) {
          lbl.addEventListener('mouseover', function() { updateStars(idx + 1); });
          lbl.addEventListener('mouseout', function() {
            var checked = document.querySelector('#star-picker input:checked');
            updateStars(checked ? +checked.value : 0);
          });
        });
        </script>
      </div>

    </div>
  </div>

  <!-- Related products -->
  <?php if ($related && $related->num_rows > 0): ?>
  <div style="margin-bottom:80px;">
    <div class="section-header">
      <h2>More from <?= htmlspecialchars($product['category_name']) ?></h2>
      <a href="<?= BASE_URL ?>/pages/shop.php?cat=<?= $product['category_slug'] ?>" class="view-all">View all →</a>
    </div>
    <div class="products-grid" style="grid-template-columns:repeat(4,1fr);">
      <?php while ($p = $related->fetch_assoc()): ?>
        <div class="product-card">
          <div class="product-card-image">
            <?php if ($p['badge']): ?>
              <span class="product-badge badge-<?= $p['badge'] ?>"><?= $p['badge'] ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>">
              <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
            </a>
          </div>
          <div class="product-card-body">
            <div class="product-name">
              <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name']) ?></a>
            </div>
            <div class="product-price-row">
              <span class="product-price"><?= formatPrice($p['price']) ?></span>
              <button class="add-to-cart-btn" data-id="<?= $p['id'] ?>">+ Cart</button>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function changeQty(delta) {
  const input = document.getElementById('productQty');
  if (!input) return;
  let v = parseInt(input.value) + delta;
  input.value = Math.max(1, Math.min(99, v));
}
</script>

<script>
(function() {
  const btn   = document.getElementById('wishlist-btn');
  if (!btn) return;
  const icon  = document.getElementById('wishlist-icon');
  const label = document.getElementById('wishlist-label');
  const isOutOfStock = <?= $product['stock'] == 0 ? 'true' : 'false' ?>;

  btn.addEventListener('click', function() {
    const loggedIn = <?= isLoggedIn() ? 'true' : 'false' ?>;
    if (!loggedIn) {
      window.location.href = btn.dataset.loginUrl;
      return;
    }
    const productId = btn.dataset.id;
    const wasWishlisted = btn.dataset.wishlisted === '1';

    fetch('<?= BASE_URL ?>/pages/wishlist_toggle.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'product_id=' + productId
    })
    .then(r => r.json())
    .then(data => {
      if (data.wishlisted) {
        btn.dataset.wishlisted = '1';
        icon.setAttribute('fill', '#fff');
        label.textContent = 'Saved to Wishlist';
        btn.style.transform = 'scale(1.08)';
        setTimeout(() => btn.style.transform = '', 200);
      } else {
        btn.dataset.wishlisted = '0';
        icon.setAttribute('fill', 'none');
        label.textContent = isOutOfStock ? 'Notify me when back in stock' : 'Add to Wishlist';
      }
    });
  });
})();
</script>

<?php if ($product['stock'] == 0): ?>
<!-- Pre-order modal -->
<div id="preorder-modal" style="display:none;position:fixed;inset:0;z-index:9000;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,0.75);backdrop-filter:blur(6px);">
  <div style="background:var(--surface);border:1px solid rgba(237,30,40,0.2);border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,0.6),0 0 40px rgba(237,30,40,0.12);overflow:hidden;position:relative;">

    <!-- Close -->
    <button onclick="document.getElementById('preorder-modal').style.display='none'"
      style="position:absolute;top:14px;right:14px;background:var(--surface2);border:none;color:var(--text2);width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:background var(--transition);"
      onmouseover="this.style.background='rgba(237,30,40,0.2)';this.style.color='#fff'"
      onmouseout="this.style.background='var(--surface2)';this.style.color='var(--text2)'">✕</button>

    <!-- Product header -->
    <div style="display:flex;align-items:center;gap:16px;padding:24px 24px 20px;border-bottom:1px solid var(--border);">
      <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>"
        style="width:72px;height:72px;object-fit:contain;background:#fff;border-radius:10px;flex-shrink:0;padding:6px;">
      <div>
        <div style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--accent);margin-bottom:4px;">Pre-order Request</div>
        <div style="font-weight:700;font-size:1rem;line-height:1.3;"><?= htmlspecialchars($product['name']) ?></div>
        <div style="font-size:0.9rem;color:var(--text3);margin-top:2px;"><?= formatPrice($product['price']) ?></div>
      </div>
    </div>

    <!-- Body text -->
    <div style="padding:20px 24px;font-size:0.9rem;line-height:1.7;color:var(--text2);">
      <p style="margin:0 0 12px;">Our sets are often retired and rare to find, therefore stock can run out. By requesting and pre-ordering we will make your product a priority.</p>
      <p style="margin:0 0 12px;">A <strong style="color:var(--text);">30 day period</strong> will initiate where we will maximise our efforts to re-stock. By pre-ordering you avoid missing out and you will be the first to receive the product upon re-stock!</p>
      <p style="margin:0 0 12px;">Often we are only able to re-stock a handful of sets and you can miss out if not pre-ordering.</p>
      <p style="margin:0;color:var(--text3);font-size:0.84rem;">If we are unable to restock within this period you will <strong style="color:var(--text2);">automatically be refunded</strong>. Thank you for shopping with us 🙂</p>
    </div>

    <!-- Qty + confirm -->
    <div style="padding:0 24px 24px;display:flex;align-items:center;gap:12px;">
      <div class="qty-controls" style="flex-shrink:0;">
        <button class="qty-btn" onclick="changePreorderQty(-1)" type="button">−</button>
        <input class="qty-input" type="number" id="preorderQty" value="1" min="1" max="99">
        <button class="qty-btn" onclick="changePreorderQty(1)" type="button">+</button>
      </div>
      <button id="confirm-preorder-btn"
        data-id="<?= $product['id'] ?>"
        style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--accent);color:#fff;border:none;cursor:pointer;padding:12px 20px;border-radius:var(--radius);font-size:0.95rem;font-weight:700;box-shadow:var(--shadow-red);transition:box-shadow var(--transition),transform var(--transition);"
        onmouseover="this.style.background='#f5686a';this.style.boxShadow='0 12px 40px rgba(237,30,40,0.35)'"
        onmouseout="this.style.background='#ED1E28';this.style.boxShadow='var(--shadow-red)'">
        Confirm Pre-order
      </button>
    </div>
  </div>
</div>
<script>
function changePreorderQty(delta) {
  const input = document.getElementById('preorderQty');
  if (!input) return;
  input.value = Math.max(1, Math.min(99, parseInt(input.value) + delta));
}
document.getElementById('preorder-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
document.getElementById('confirm-preorder-btn').addEventListener('click', function() {
  const btn = this;
  const productId = btn.dataset.id;
  const qty = parseInt(document.getElementById('preorderQty').value) || 1;
  btn.textContent = 'Adding…';
  btn.disabled = true;
  fetch('<?= BASE_URL ?>/pages/cart_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=preorder_add&product_id=' + productId + '&qty=' + qty
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.querySelector('.cart-badge') && (document.querySelector('.cart-badge').textContent = data.cart_count, document.querySelector('.cart-badge').style.display = 'flex');
      document.getElementById('preorder-modal').style.display = 'none';
      window.location.href = '<?= BASE_URL ?>/pages/cart.php';
    } else {
      btn.textContent = 'Confirm Pre-order';
      btn.disabled = false;
    }
  })
  .catch(() => { btn.textContent = 'Confirm Pre-order'; btn.disabled = false; });
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
