<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Home';

// Total product count for hero stat
$productCount        = $conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$productCountDisplay = $productCount >= 10 ? (floor($productCount / 5) * 5) . '+' : 'Vast';
$productCountLabel   = $productCount >= 10 ? 'Vast Product Range' : 'Product Range';

// Most expensive product for hero image
$heroProduct = $conn->query("SELECT * FROM products ORDER BY price DESC LIMIT 1")->fetch_assoc();

// Featured products (6)
$featured = $conn->query("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id=c.id WHERE p.featured=1 LIMIT 6");

// New arrivals (4 newest)
$newArrivals = $conn->query("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id=c.id ORDER BY p.created_at DESC LIMIT 4");

// Auto-clear any expired deals
$conn->query("UPDATE products SET deal_price = NULL, deal_end = NULL WHERE deal_end IS NOT NULL AND deal_end <= NOW()");

// Current deal — active admin-set deal first, fallback to HOT featured product
$dealResult = $conn->query("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id=c.id WHERE p.deal_price IS NOT NULL AND (p.deal_end IS NULL OR p.deal_end > NOW()) ORDER BY p.deal_end ASC LIMIT 1");
$deal       = ($dealResult && $dealResult->num_rows > 0) ? $dealResult->fetch_assoc() : null;

// Categories with count
$cats = $conn->query("SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id ORDER BY c.name");

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Hero ── -->
<section class="hero">
  <div class="hero-inner">
    <div class="hero-content">
      <h1 class="hero-title">
        The <span>#1 Retailer</span> of<br>
        Rare and Retired<br>
        Lego Sets in Switzerland
      </h1>
      <p class="hero-desc">Every set sealed in original packaging for prices that cannot be found anywhere else. From rare sets that are hard to find to new releases — SwissBricks has it all.</p>
      <div class="hero-ctas">
        <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary">Shop All Sets</a>
        <a href="#deal-of-the-day" class="btn btn-outline">Explore Deals →</a>
      </div>
      <div class="hero-stats">
        <div>
          <div class="hero-stat-value"><?= $productCountDisplay ?></div>
          <div class="hero-stat-label"><?= $productCountLabel ?></div>
        </div>
        <div>
          <div class="hero-stat-value">Countless</div>
          <div class="hero-stat-label">Happy Customers</div>
        </div>
        <div>
          <div class="hero-stat-value">5★</div>
          <div class="hero-stat-label">Top Rating</div>
        </div>
      </div>
    </div>
    <div class="hero-image-wrap">
      <img src="<?= htmlspecialchars($heroProduct['image_url'] ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=800&q=80') ?>" alt="<?= htmlspecialchars($heroProduct['name'] ?? 'LEGO') ?>" class="hero-image">
      <div class="hero-badge">
        <?= htmlspecialchars($heroProduct['name'] ?? 'Rare Retired Sets') ?><br>
        <small>Always Sealed in Original Packaging</small>
      </div>
      <div class="hero-dot-pattern"></div>
    </div>
  </div>
</section>

<!-- ── Feature strip ── -->
<div class="feature-strip">
  <div class="container">
    <div class="feature-item">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div>
        <div class="feature-title">Quick Support</div>
        <div class="feature-sub">Same-day response</div>
      </div>
    </div>
    <div class="feature-item">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/></svg>
      </div>
      <div>
        <div class="feature-title">Secure Payment</div>
        <div class="feature-sub">TWINT, PayPal, Card</div>
      </div>
    </div>
    <div class="feature-item">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div>
        <div class="feature-title">Fast Delivery</div>
        <div class="feature-sub">Swiss Post 1–3 days</div>
      </div>
    </div>
    <div class="feature-item">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      </div>
      <div>
        <div class="feature-title">Always Sealed</div>
        <div class="feature-sub">100% original packaging</div>
      </div>
    </div>
    <div class="feature-item">
      <div class="feature-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      </div>
      <div>
        <div class="feature-title">Picture Proof</div>
        <div class="feature-sub">Upon request</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Featured products ── -->
<section class="section">
  <div class="container">
    <div class="section-header">
      <h2>Featured Sets</h2>
      <a href="<?= BASE_URL ?>/pages/shop.php" class="view-all">View all →</a>
    </div>
    <div class="products-grid">
      <?php if ($featured): while ($p = $featured->fetch_assoc()): ?>
        <div class="product-card reveal">
          <div class="product-card-image">
            <?php if ($p['badge']): ?>
              <span class="product-badge badge-<?= $p['badge'] ?>"><?= $p['badge'] ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>">
              <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
            </a>
          </div>
          <div class="product-card-body">
            <div class="product-category"><?= htmlspecialchars($p['category_name']) ?></div>
            <div class="product-name">
              <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name']) ?></a>
            </div>
            <div class="product-rating">
              <span class="product-stars">★★★★★</span>
            </div>
            <?php
              $hasDeal    = !empty($p['deal_price']) && (empty($p['deal_end']) || strtotime($p['deal_end']) > time());
              $showPrice  = $hasDeal ? $p['deal_price'] : $p['price'];
              $crossPrice = $hasDeal ? $p['price'] : $p['old_price'];
            ?>
            <div class="product-price-row">
              <div>
                <span class="product-price"><?= formatPrice($showPrice) ?></span>
                <?php if ($crossPrice): ?>
                  <span class="product-old-price"><?= formatPrice($crossPrice) ?></span>
                <?php endif; ?>
              </div>
              <button class="add-to-cart-btn" data-id="<?= $p['id'] ?>">+ Cart</button>
            </div>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>
  </div>
</section>

<!-- ── Current Deal ── -->
<?php if (!$deal): ?>
<section class="section deal-section" id="deal-of-the-day" style="text-align:center;">
  <div class="container">
    <div class="deal-info-label" style="margin-bottom:12px;">Deal of the Day</div>
    <h2 style="font-size:2rem;opacity:0.5;">No Deals Right Now!</h2>
    <p style="color:var(--text3);margin-top:8px;">Check back soon for exclusive limited-time offers.</p>
  </div>
</section>
<?php else: ?>
<section class="section deal-section" id="deal-of-the-day">
  <div class="container">
    <div class="deal-inner">
      <div class="deal-info">
        <div class="deal-info-label">Deal of the Day</div>
        <h2 class="deal-title"><?= htmlspecialchars($deal['name']) ?></h2>
        <p style="color:var(--text2);margin-bottom:16px;"><?= htmlspecialchars(substr($deal['description'],0,120)) ?>…</p>
        <?php
          $showPrice    = !empty($deal['deal_price']) ? $deal['deal_price'] : $deal['price'];
          $crossedPrice = !empty($deal['deal_price']) ? $deal['price'] : $deal['old_price'];
        ?>
        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:16px;">
          <span class="deal-price-main"><?= formatPrice($showPrice) ?></span>
          <?php if ($crossedPrice): ?>
            <span class="deal-price-old"><?= formatPrice($crossedPrice) ?></span>
          <?php endif; ?>
        </div>
        <div class="deal-countdown" id="countdown" data-target="">
          <div class="deal-countdown-unit">
            <div class="deal-countdown-num" id="cd-hours">00</div>
            <div class="deal-countdown-label">Hours</div>
          </div>
          <div class="deal-countdown-unit">
            <div class="deal-countdown-num" id="cd-mins">00</div>
            <div class="deal-countdown-label">Mins</div>
          </div>
          <div class="deal-countdown-unit">
            <div class="deal-countdown-num" id="cd-secs">00</div>
            <div class="deal-countdown-label">Secs</div>
          </div>
        </div>
        <div class="deal-ctas">
          <button class="btn btn-primary add-to-cart-btn" data-id="<?= $deal['id'] ?>">Add to Cart</button>
          <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-outline">View Shop</a>
        </div>
      </div>

      <div class="deal-image-col">
        <div class="deal-image">
          <img src="<?= htmlspecialchars($deal['image_url']) ?>" alt="<?= htmlspecialchars($deal['name']) ?>">
        </div>
        <div class="deal-meta">
          <div class="deal-meta-item">
            <div class="deal-meta-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </div>
            <div>
              <div class="deal-meta-title">Original Sealed Box</div>
              <div class="deal-meta-sub">Factory sealed, picture proof available</div>
            </div>
          </div>
          <div class="deal-meta-item">
            <div class="deal-meta-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            </div>
            <div>
              <div class="deal-meta-title">Swiss Post Delivery</div>
              <div class="deal-meta-sub">1–3 business days across Switzerland</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── New Arrivals ── -->
<section class="section">
  <div class="container">
    <div class="section-header">
      <h2>New Arrivals</h2>
      <a href="<?= BASE_URL ?>/pages/shop.php?sort=newest" class="view-all">View all →</a>
    </div>
    <div class="products-grid" style="grid-template-columns:repeat(4,1fr)">
      <?php if ($newArrivals): while ($p = $newArrivals->fetch_assoc()): ?>
        <div class="product-card reveal">
          <div class="product-card-image">
            <?php if ($p['badge']): ?>
              <span class="product-badge badge-<?= $p['badge'] ?>"><?= $p['badge'] ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>">
              <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
            </a>
          </div>
          <div class="product-card-body">
            <div class="product-category"><?= htmlspecialchars($p['category_name']) ?></div>
            <div class="product-name">
              <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name']) ?></a>
            </div>
            <?php
              $hasDeal    = !empty($p['deal_price']) && (empty($p['deal_end']) || strtotime($p['deal_end']) > time());
              $showPrice  = $hasDeal ? $p['deal_price'] : $p['price'];
              $crossPrice = $hasDeal ? $p['price'] : $p['old_price'];
            ?>
            <div class="product-price-row">
              <div>
                <span class="product-price"><?= formatPrice($showPrice) ?></span>
                <?php if ($crossPrice): ?>
                  <span class="product-old-price"><?= formatPrice($crossPrice) ?></span>
                <?php endif; ?>
              </div>
              <button class="add-to-cart-btn" data-id="<?= $p['id'] ?>">+ Cart</button>
            </div>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>
  </div>
</section>

<!-- ── Testimonials ── -->
<?php
$featuredReviews = $conn->query("SELECT * FROM reviews WHERE featured=1 ORDER BY created_at DESC LIMIT 3");
if ($featuredReviews && $featuredReviews->num_rows > 0):
?>
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="section-header">
      <h2>What Customers Say</h2>
    </div>
    <div class="testimonials-grid">
      <?php while ($rev = $featuredReviews->fetch_assoc()):
        $words    = preg_split('/\s+/', trim($rev['username']));
        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : substr($words[0], 1, 1)));
        $stars    = str_repeat('★', (int)$rev['rating']) . str_repeat('★', 5 - (int)$rev['rating']);
      ?>
      <div class="testimonial-card reveal">
        <div class="testimonial-stars"><?= $stars ?></div>
        <p class="testimonial-text">"<?= htmlspecialchars($rev['review_text']) ?>"</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar"><?= $initials ?></div>
          <div>
            <div class="testimonial-name"><?= htmlspecialchars($rev['username']) ?></div>
          </div>
        </div>
      </div>
      <?php endwhile; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php
if ($deal && !empty($deal['deal_end'])) {
    $target = strtotime($deal['deal_end']) * 1000;
    echo "<script>var el=document.getElementById('countdown');if(el)el.dataset.target='{$target}';</script>";
}
require_once __DIR__ . '/includes/footer.php';
?>
