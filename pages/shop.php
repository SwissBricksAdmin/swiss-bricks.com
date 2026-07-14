<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$search     = sanitize($_GET['search'] ?? '');
$cat        = sanitize($_GET['cat']    ?? '');
$sort       = sanitize($_GET['sort']   ?? 'newest');
$badge      = sanitize($_GET['badge']  ?? '');
$minPrice   = max(0,  min(10000, (int)($_GET['min_price'] ?? 0)));
$maxPrice   = max(10, min(10000, (int)($_GET['max_price'] ?? 10000)));
$yearFilter = array_filter(array_map('intval', (array)($_GET['year'] ?? [])));

// Build WHERE clause
$conditions = ['1=1'];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(p.name LIKE ? OR p.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}
if ($cat !== '') {
    $conditions[] = 'c.slug = ?';
    $params[] = $cat;
    $types   .= 's';
}
if ($badge !== '' && in_array($badge, ['NEW','HOT','SALE'])) {
    if ($badge === 'SALE') {
        $conditions[] = "(p.badge = 'SALE' OR (p.deal_price IS NOT NULL AND (p.deal_end IS NULL OR p.deal_end > NOW())))";
    } else {
        $conditions[] = 'p.badge = ?';
        $params[] = $badge;
        $types   .= 's';
    }
}
if ($minPrice > 0) {
    $conditions[] = 'p.price >= ?';
    $params[] = $minPrice;
    $types   .= 'd';
}
if ($maxPrice < 10000) {
    $conditions[] = 'p.price <= ?';
    $params[] = $maxPrice;
    $types   .= 'd';
}
if (!empty($yearFilter)) {
    $placeholders = implode(',', array_fill(0, count($yearFilter), '?'));
    $conditions[] = "p.release_year IN ($placeholders)";
    foreach ($yearFilter as $y) { $params[] = $y; $types .= 'i'; }
}

$where = implode(' AND ', $conditions);

// Available release years for filter
$availableYears = [];
$yrResult = $conn->query("SELECT DISTINCT release_year FROM products WHERE release_year IS NOT NULL ORDER BY release_year DESC");
while ($yr = $yrResult->fetch_row()) $availableYears[] = (int)$yr[0];

// Sort
$orderMap = [
    'newest'     => 'p.created_at DESC',
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'popular'    => 'p.featured DESC, p.id DESC',
];
$orderBy = $orderMap[$sort] ?? 'p.created_at DESC';

// Pagination
$page          = max(1, (int)($_GET['page'] ?? 1));
$perInStock    = 12;
$perPreOrder   = 3;
$offsetInStock = ($page - 1) * $perInStock;
$offsetPreOrder= ($page - 1) * $perPreOrder;

// Helper to run a count or data query with optional params
function shopQuery($conn, string $sql, string $types, array $params) {
    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
    return $conn->query($sql);
}

$baseSelect = "SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON p.category_id=c.id WHERE $where";

// In-stock counts + data
$totalInStock  = (int)shopQuery($conn, "SELECT COUNT(*) FROM products p JOIN categories c ON p.category_id=c.id WHERE $where AND p.stock > 0", $types, $params)->fetch_row()[0];
$totalPreOrder = (int)shopQuery($conn, "SELECT COUNT(*) FROM products p JOIN categories c ON p.category_id=c.id WHERE $where AND p.stock = 0", $types, $params)->fetch_row()[0];
$totalCount    = $totalInStock + $totalPreOrder;
$totalPages    = max(1, max(ceil($totalInStock / $perInStock), $totalPreOrder > 0 ? ceil($totalPreOrder / $perPreOrder) : 1));

$inStock  = shopQuery($conn, "$baseSelect AND p.stock > 0 ORDER BY $orderBy LIMIT $perInStock  OFFSET $offsetInStock",  $types, $params)->fetch_all(MYSQLI_ASSOC);
$preOrder = shopQuery($conn, "$baseSelect AND p.stock = 0 ORDER BY $orderBy LIMIT $perPreOrder OFFSET $offsetPreOrder", $types, $params)->fetch_all(MYSQLI_ASSOC);

// Sidebar: all categories with counts
$cats = $conn->query("SELECT c.*, COUNT(p.id) AS cnt FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id ORDER BY c.name");

$pageTitle = 'Shop';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <!-- Breadcrumb -->
  <nav class="breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a> ›
    <a href="<?= BASE_URL ?>/pages/shop.php">Shop</a>
    <?php if ($cat): ?> › <span><?= htmlspecialchars($cat) ?></span><?php endif; ?>
  </nav>

  <div class="shop-layout">

    <!-- ── Sidebar ── -->
    <aside class="shop-sidebar">
      <div class="sidebar-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Filter Sets
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">Price Range</div>
        <div class="price-range-wrap">
          <div class="price-range-labels">
            <span id="minPriceLabel">CHF <?= number_format($minPrice) ?></span>
            <span id="maxPriceLabel"><?= $maxPrice >= 10000 ? 'CHF 1,000+' : 'CHF ' . number_format($maxPrice) ?></span>
          </div>
          <div class="dual-range-wrap">
            <div class="dual-range-bg"></div>
            <div class="dual-range-fill" id="dualRangeFill"></div>
            <input type="range" id="minRange" min="0" max="1000" step="10" value="<?= min($minPrice, 1000) ?>">
            <input type="range" id="maxRange" min="0" max="1000" step="10" value="<?= $maxPrice >= 10000 ? 1000 : min($maxPrice, 1000) ?>">
          </div>
        </div>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">Badge</div>
        <div class="badge-filter-grid">
          <a href="?<?= http_build_query(array_merge($_GET, ['badge'=>'','page'=>1])) ?>"
             class="badge-filter-btn <?= $badge==='' ? 'active' : '' ?>">All</a>
          <a href="?<?= http_build_query(array_merge($_GET, ['badge'=>'NEW','page'=>1])) ?>"
             class="badge-filter-btn <?= $badge==='NEW' ? 'active' : '' ?>">NEW</a>
          <a href="?<?= http_build_query(array_merge($_GET, ['badge'=>'HOT','page'=>1])) ?>"
             class="badge-filter-btn <?= $badge==='HOT' ? 'active' : '' ?>">HOT</a>
          <a href="?<?= http_build_query(array_merge($_GET, ['badge'=>'SALE','page'=>1])) ?>"
             class="badge-filter-btn <?= $badge==='SALE' ? 'active' : '' ?>">SALE</a>
        </div>
      </div>

      <?php if (!empty($availableYears)): ?>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Release Year</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($availableYears as $yr): ?>
            <?php
              $isChecked = in_array($yr, $yearFilter);
              $newYears  = $isChecked
                ? array_values(array_diff($yearFilter, [$yr]))
                : array_values(array_merge($yearFilter, [$yr]));
              $queryArr  = array_merge($_GET, ['year' => $newYears, 'page' => 1]);
              if (empty($newYears)) unset($queryArr['year']);
              $href = '?' . http_build_query($queryArr);
            ?>
            <a href="<?= $href ?>" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text2);font-size:0.85rem;font-weight:<?= $isChecked ? '700' : '400' ?>;transition:color 0.15s;"
               onmouseover="this.style.color='var(--text1)'" onmouseout="this.style.color='<?= $isChecked ? 'var(--text1)' : 'var(--text2)' ?>'">
              <span style="width:16px;height:16px;border-radius:4px;border:2px solid <?= $isChecked ? 'var(--accent)' : 'var(--border)' ?>;background:<?= $isChecked ? 'var(--accent)' : 'transparent' ?>;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                <?php if ($isChecked): ?><svg width="9" height="9" viewBox="0 0 12 12" fill="none"><polyline points="2,6 5,9 10,3" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?>
              </span>
              <?= $yr ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-outline btn-full btn-sm" style="margin-top:8px;">Clear All</a>
    </aside>

    <!-- ── Main content ── -->
    <main>
      <div class="shop-main-header">
        <div class="results-count">
          <strong><?= $totalCount ?></strong> set<?= $totalCount !== 1 ? 's' : '' ?> found
        </div>
        <?php
          $sortLabels = ['newest'=>'Newest First','popular'=>'Most Popular','price_asc'=>'Price: Low → High','price_desc'=>'Price: High → Low'];
        ?>
        <div class="sort-dropdown" id="sortDropdown">
          <button class="btn btn-primary sort-btn" type="button" onclick="this.closest('.sort-dropdown').classList.toggle('open')">
            <?= $sortLabels[$sort] ?? 'Newest First' ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="sort-menu">
            <?php foreach ($sortLabels as $val => $label):
              $url = '?' . http_build_query(array_merge($_GET, ['sort'=>$val])); ?>
              <a href="<?= $url ?>" class="sort-option <?= $sort===$val ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if ($totalCount === 0): ?>
        <div class="cart-empty" style="padding:80px 24px;text-align:center;">
          <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--text3);margin:0 auto 24px;">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
          </svg>
          <h3 style="margin-bottom:8px;">No sets found</h3>
          <p>Try adjusting your filters or clearing your search.</p>
          <a href="<?= BASE_URL ?>/pages/shop.php" class="btn btn-primary" style="margin-top:24px;">Clear Filters</a>
        </div>
      <?php else: ?>

        <?php
        // Reusable card renderer
        function renderProductCard($p) { ?>
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
              <div class="product-category"><?= htmlspecialchars($p['category_name']) ?></div>
              <div class="product-name">
                <a href="<?= BASE_URL ?>/pages/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name']) ?></a>
              </div>
              <div class="product-rating">
                <span class="product-stars">★★★★★</span>
              </div>
              <div class="product-price-row">
                <div>
                  <span class="product-price"><?= formatPrice($p['price']) ?></span>
                  <?php if ($p['old_price']): ?>
                    <span class="product-old-price"><?= formatPrice($p['old_price']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($p['stock'] > 0): ?>
                  <button class="add-to-cart-btn" data-id="<?= $p['id'] ?>">+ Cart</button>
                <?php else: ?>
                  <span style="font-size:0.8rem;color:var(--text3);">Pre-order</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php }
        ?>

        <!-- ── In Stock ── -->
        <?php if (!empty($inStock)): ?>
          <div style="margin-bottom:32px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
              <h2 style="font-size:1.1rem;font-weight:800;color:var(--text);margin:0;">In Stock</h2>
              <span style="font-size:0.72rem;font-weight:700;color:#22c55e;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);border-radius:20px;padding:3px 10px;"><?= $totalInStock ?> set<?= $totalInStock !== 1 ? 's' : '' ?></span>
            </div>
            <div class="products-grid">
              <?php foreach ($inStock as $p): renderProductCard($p); endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- ── Request & Pre-Order ── -->
        <?php if (!empty($preOrder)): ?>
          <div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;<?= !empty($inStock) ? 'margin-top:16px;padding-top:32px;border-top:1px solid var(--border);' : '' ?>">
              <h2 style="font-size:1.1rem;font-weight:800;color:var(--text);margin:0;">Request &amp; Pre-Order</h2>
              <span style="font-size:0.72rem;font-weight:700;color:#f59e0b;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);border-radius:20px;padding:3px 10px;"><?= $totalPreOrder ?> set<?= $totalPreOrder !== 1 ? 's' : '' ?></span>
            </div>
            <div class="products-grid">
              <?php foreach ($preOrder as $p): renderProductCard($p); endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- ── Pagination ── -->
        <?php if ($totalPages > 1): ?>
          <?php
            $pagerBase = array_diff_key($_GET, ['page' => '']);
            function shopPageUrl(array $base, int $p): string {
                return '?' . http_build_query(array_merge($base, ['page' => $p]));
            }
          ?>
          <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:40px;flex-wrap:wrap;">
            <?php if ($page > 1): ?>
              <a href="<?= shopPageUrl($pagerBase, $page - 1) ?>"
                 style="padding:8px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text2);text-decoration:none;font-size:0.85rem;transition:border-color 0.15s,color 0.15s;"
                 onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'">← Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <?php if ($i === $page): ?>
                <span style="padding:8px 14px;border-radius:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;font-size:0.85rem;font-weight:700;"><?= $i ?></span>
              <?php else: ?>
                <a href="<?= shopPageUrl($pagerBase, $i) ?>"
                   style="padding:8px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text2);text-decoration:none;font-size:0.85rem;transition:border-color 0.15s,color 0.15s;"
                   onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= shopPageUrl($pagerBase, $page + 1) ?>"
                 style="padding:8px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text2);text-decoration:none;font-size:0.85rem;transition:border-color 0.15s,color 0.15s;"
                 onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'">Next →</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </main>

  </div>
</div>

<script>
document.addEventListener('click', function(e) {
  const dd = document.getElementById('sortDropdown');
  if (dd && !dd.contains(e.target)) dd.classList.remove('open');
});

(function () {
  const minR = document.getElementById('minRange');
  const maxR = document.getElementById('maxRange');
  const minL = document.getElementById('minPriceLabel');
  const maxL = document.getElementById('maxPriceLabel');
  const fill = document.getElementById('dualRangeFill');
  if (!minR || !maxR) return;

  function update() {
    let lo = parseInt(minR.value), hi = parseInt(maxR.value);
    if (lo > hi - 10) { if (this === minR) minR.value = lo = hi - 10; else maxR.value = hi = lo + 10; }
    fill.style.left  = (lo / 1000 * 100) + '%';
    fill.style.width = ((hi - lo) / 1000 * 100) + '%';
    minL.textContent = 'CHF ' + lo.toLocaleString();
    maxL.textContent = hi >= 1000 ? 'CHF 1,000+' : 'CHF ' + hi.toLocaleString();
  }

  function apply() {
    const lo = parseInt(minR.value), hi = parseInt(maxR.value);
    const url = new URL(window.location.href);
    lo > 0   ? url.searchParams.set('min_price', lo) : url.searchParams.delete('min_price');
    hi < 1000 ? url.searchParams.set('max_price', hi) : url.searchParams.delete('max_price');
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
  }

  minR.addEventListener('input',  update.bind(minR));
  maxR.addEventListener('input',  update.bind(maxR));
  minR.addEventListener('change', apply);
  maxR.addEventListener('change', apply);
  update.call(null);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
