<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Products';

$msg   = $_GET['saved'] ?? '';
$error = '';

// Auto-clear expired deals
$conn->query("UPDATE products SET deal_price = NULL, deal_end = NULL WHERE deal_end IS NOT NULL AND deal_end <= NOW()");

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM products WHERE id=$id");
    $msg = 'Product deleted.';
}

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $id          = (int)($_POST['product_id'] ?? 0);
    $category_id = (int)$_POST['category_id'];
    $name        = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $slug        = $conn->real_escape_string(trim($_POST['slug'] ?? ''));
    $description = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $price       = (float)$_POST['price'];
    $old_price   = $_POST['old_price'] !== '' ? (float)$_POST['old_price'] : 'NULL';
    $badge       = in_array($_POST['badge'], ['NEW','HOT','SALE','']) ? $_POST['badge'] : '';
    $stock       = (int)$_POST['stock'];
    $featured    = isset($_POST['featured']) ? 1 : 0;
    $image_url   = $conn->real_escape_string(trim($_POST['image_url'] ?? ''));
    $deal_price  = $_POST['deal_price'] !== '' ? (float)$_POST['deal_price'] : 'NULL';
    $deal_hours  = (int)($_POST['deal_hours'] ?? 0);
    if ($deal_hours > 0) {
        $deal_end_ts = time() + ($deal_hours * 3600);
        $deal_end = "'" . date('Y-m-d H:i:s', $deal_end_ts) . "'";
    } else {
        $deal_end = 'NULL';
    }
    $weight_grams = $_POST['weight_grams'] !== '' ? (int)$_POST['weight_grams']   : 'NULL';
    $length_cm    = $_POST['length_cm']    !== '' ? (float)$_POST['length_cm']    : 'NULL';
    $width_cm     = $_POST['width_cm']     !== '' ? (float)$_POST['width_cm']     : 'NULL';
    $height_cm    = $_POST['height_cm']    !== '' ? (float)$_POST['height_cm']    : 'NULL';
    $release_year = $_POST['release_year'] !== '' ? (int)$_POST['release_year']   : 'NULL';

    if (!$name || !$slug || !$price) {
        $error = 'Name, slug, and price are required.';
    } else {
        $old_price_sql  = is_numeric($old_price) ? $old_price : 'NULL';
        $deal_price_sql = is_numeric($deal_price) ? $deal_price : 'NULL';
        $product_id = 0;
        if ($id) {
            $oldRow = $conn->query("SELECT stock, name, slug, image_url, price FROM products WHERE id=$id")->fetch_assoc();
            $conn->query("UPDATE products SET category_id=$category_id, name='$name', slug='$slug', description='$description', price=$price, old_price=$old_price_sql, badge='$badge', stock=$stock, featured=$featured, deal_price=$deal_price_sql, deal_end=$deal_end, weight_grams=$weight_grams, length_cm=$length_cm, width_cm=$width_cm, height_cm=$height_cm, release_year=$release_year WHERE id=$id");
            $product_id = $id;
            $notified = 0;
            if ($oldRow && (int)$oldRow['stock'] === 0 && $stock > 0) {
                $product = ['name' => $name, 'slug' => $slug, 'image_url' => $oldRow['image_url'], 'price' => $price];
                $wishers = $conn->query("SELECT u.email, u.first_name FROM wishlists w JOIN users u ON w.user_id=u.id WHERE w.product_id=$id");
                if ($wishers) while ($wisher = $wishers->fetch_assoc()) {
                    if (sendBackInStockEmail($wisher['email'], $wisher['first_name'] ?: 'there', $product)) $notified++;
                }
            }
            $msg = 'Product updated.' . ($notified > 0 ? " Back-in-stock email sent to $notified customer(s)." : '');
        } else {
            // Ensure unique slug for new products
            $baseSlug = $slug;
            $suffix   = 1;
            while ($conn->query("SELECT id FROM products WHERE slug='$slug' LIMIT 1")->num_rows > 0) {
                $slug = $baseSlug . '-' . $suffix++;
            }
            $conn->query("INSERT INTO products (category_id,name,slug,description,price,old_price,image_url,badge,stock,featured,deal_price,deal_end,weight_grams,length_cm,width_cm,height_cm,release_year) VALUES ($category_id,'$name','$slug','$description',$price,$old_price_sql,'','$badge',$stock,$featured,$deal_price_sql,$deal_end,$weight_grams,$length_cm,$width_cm,$height_cm,$release_year)");
            $product_id = $conn->insert_id;
            $msg = 'Product added.' . ($slug !== $baseSlug ? " Slug adjusted to \"$slug\" to avoid duplicates." : '');
        }

        // ── Process gallery image slots ──────────────────────────────
        ini_set('memory_limit', '512M');
        if ($product_id) {
            $uploadDir = __DIR__ . '/../uploads/products/' . $product_id . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $imgErrors = [];

            for ($slot = 1; $slot <= 10; $slot++) {
                // Delete slot?
                if (!empty($_POST['delete_slot'][$slot])) {
                    @unlink($uploadDir . 'slot_' . $slot . '.png');
                    $conn->query("DELETE FROM product_images WHERE product_id=$product_id AND sort_order=$slot");
                    continue;
                }
                // Upload new image?
                if (!empty($_FILES['product_images']['tmp_name'][$slot]) && $_FILES['product_images']['error'][$slot] === UPLOAD_ERR_OK) {
                    $tmp      = $_FILES['product_images']['tmp_name'][$slot];
                    $origName = $_FILES['product_images']['name'][$slot] ?? '';
                    $dest     = $uploadDir . 'slot_' . $slot . '.png';
                    $mime     = mime_content_type($tmp) ?: 'unknown';
                    $fileSize = round(filesize($tmp) / 1024 / 1024, 1);
                    if (convertImageToPng($tmp, $dest, $origName)) {
                        $rel    = 'uploads/products/' . $product_id . '/slot_' . $slot . '.png';
                        $relEsc = $conn->real_escape_string($rel);
                        $conn->query("INSERT INTO product_images (product_id, file_path, sort_order) VALUES ($product_id,'$relEsc',$slot)
                                      ON DUPLICATE KEY UPDATE file_path='$relEsc'");
                    } else {
                        $imgErrors[] = "Slot $slot failed ($origName, $mime, {$fileSize}MB)";
                    }
                }
            }
            if ($imgErrors) {
                $msg .= ' Image conversion failed for: ' . implode('; ', $imgErrors) . '.';
            }

            // Sync image_url to first gallery image
            $first = $conn->query("SELECT file_path FROM product_images WHERE product_id=$product_id ORDER BY sort_order ASC LIMIT 1")->fetch_assoc();
            if ($first) {
                $imgUrl = $conn->real_escape_string(BASE_URL . '/' . $first['file_path']);
                $conn->query("UPDATE products SET image_url='$imgUrl' WHERE id=$product_id");
            }

            // Redirect back to same page with success message
            $returnPage   = max(1, (int)($_POST['return_page'] ?? 1));
            $returnSearch = urlencode($_POST['return_search'] ?? '');
            $msgEnc = urlencode($msg);
            header("Location: " . BASE_URL . "/admin/products.php?page=$returnPage&search=$returnSearch&saved=$msgEnc");
            exit;
        }
    }
}

// Fetch for edit
$editing = null;
$editingImages = []; // slot => file_path
if (isset($_GET['edit'])) {
    $editing = $conn->query("SELECT * FROM products WHERE id=".(int)$_GET['edit'])->fetch_assoc();
    if ($editing) {
        $imgRows = $conn->query("SELECT sort_order, file_path FROM product_images WHERE product_id=" . (int)$_GET['edit'] . " ORDER BY sort_order ASC");
        while ($ir = $imgRows->fetch_assoc()) $editingImages[(int)$ir['sort_order']] = $ir['file_path'];
    }
}

$cats     = $conn->query("SELECT * FROM categories ORDER BY name");
$perPage  = 12;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$search   = $conn->real_escape_string($_GET['search'] ?? '');
$where    = $search ? "WHERE p.name LIKE '%$search%'" : '';
$total    = $conn->query("SELECT COUNT(*) FROM products p $where")->fetch_row()[0];
$pages    = max(1, ceil($total / $perPage));

$sortCol  = in_array($_GET['sort_col'] ?? '', ['name','price','release_year']) ? $_GET['sort_col'] : '';
$sortDir  = in_array($_GET['sort_dir'] ?? '', ['asc','desc']) ? $_GET['sort_dir'] : '';
$orderBy  = match(true) {
    $sortCol === 'name'         && $sortDir === 'asc'  => 'p.name ASC',
    $sortCol === 'name'         && $sortDir === 'desc' => 'p.name DESC',
    $sortCol === 'price'        && $sortDir === 'asc'  => 'p.price ASC',
    $sortCol === 'price'        && $sortDir === 'desc' => 'p.price DESC',
    $sortCol === 'release_year' && $sortDir === 'asc'  => 'p.release_year ASC',
    $sortCol === 'release_year' && $sortDir === 'desc' => 'p.release_year DESC',
    default                                            => 'p.id DESC',
};

$productsResult = $conn->query("SELECT p.*, c.name AS cat_name, COALESCE(wc.wishlist_count,0) AS wishlist_count FROM products p JOIN categories c ON p.category_id=c.id LEFT JOIN (SELECT product_id, COUNT(*) AS wishlist_count FROM wishlists GROUP BY product_id) wc ON wc.product_id=p.id $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$products = [];
$productIds = [];
while ($row = $productsResult->fetch_assoc()) { $products[] = $row; $productIds[] = $row['id']; }

// Pre-fetch wishlist users for all products on this page
$wishlistUsers = [];
if ($productIds) {
    $idList = implode(',', $productIds);
    $wUsersResult = $conn->query("SELECT w.product_id, u.first_name, u.last_name, u.email FROM wishlists w JOIN users u ON w.user_id=u.id WHERE w.product_id IN ($idList)");
    if ($wUsersResult) while ($wu = $wUsersResult->fetch_assoc()) $wishlistUsers[$wu['product_id']][] = $wu;
}

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

  <!-- Product list -->
  <div class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-title">All Products (<?= $total ?>)</div>
      <div style="display:flex;gap:8px;">
        <form class="admin-search" method="GET">
          <input type="text" name="search" placeholder="Search name…" value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Go</button>
        </form>
        <a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-outline btn-sm">+ New</a>
      </div>
    </div>
    <?php
    // Helper: build sort link for a column header
    function sortLink(string $col, string $label, string $currentCol, string $currentDir, int $page, string $search): string {
        if ($currentCol === $col) {
            if ($currentDir === 'asc')  { $nextDir = 'desc'; $icon = ' ↑'; }
            elseif ($currentDir === 'desc') { $nextDir = ''; $col2 = ''; $icon = ' ↓'; }
            else { $nextDir = 'asc'; $icon = ''; }
        } else {
            $nextDir = 'asc'; $icon = '';
        }
        $col2 = $col2 ?? $col;
        $qs = http_build_query(['page' => $page, 'search' => $search, 'sort_col' => $col2, 'sort_dir' => $nextDir]);
        $active = $currentCol === $col && $currentDir !== '';
        $style = $active ? 'color:var(--accent);cursor:pointer;user-select:none;white-space:nowrap;' : 'cursor:pointer;user-select:none;white-space:nowrap;';
        return "<th><a href=\"?$qs\" style=\"$style text-decoration:none;color:inherit;\">$label$icon</a></th>";
    }
    ?>
    <table class="admin-table">
      <thead><tr>
        <th>Image</th>
        <?= sortLink('name', 'Name', $sortCol, $sortDir, $page, $search) ?>
        <th>Category</th>
        <?= sortLink('price', 'Price', $sortCol, $sortDir, $page, $search) ?>
        <th>Stock</th><th>Wishlist</th><th>Badge</th><th>Featured</th>
        <?= sortLink('release_year', 'Year', $sortCol, $sortDir, $page, $search) ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p):
        $wCount = (int)$p['wishlist_count'];
        $wUsers = $wishlistUsers[$p['id']] ?? [];
      ?>
        <tr>
          <td><img src="<?= htmlspecialchars($p['image_url']) ?>" alt="" onerror="this.src='https://placehold.co/48x48'"></td>
          <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
          <td style="color:var(--text3);"><?= htmlspecialchars($p['cat_name']) ?></td>
          <td style="font-weight:700;"><?= formatPrice($p['price']) ?></td>
          <td><?= $p['stock'] ?></td>
          <td>
            <?php if ($wCount > 0): ?>
              <button type="button"
                onclick="toggleWishlistRow(<?= $p['id'] ?>)"
                style="display:inline-flex;align-items:center;gap:5px;background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.3);border-radius:6px;padding:3px 9px;font-size:0.78rem;font-weight:700;cursor:pointer;transition:background 0.15s,box-shadow 0.15s;"
                onmouseover="this.style.background='rgba(237,30,40,0.22)';this.style.boxShadow='0 0 10px rgba(237,30,40,0.25)'"
                onmouseout="this.style.background='rgba(237,30,40,0.12)';this.style.boxShadow='none'">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <?= $wCount ?>
              </button>
            <?php else: ?>
              <span style="color:var(--text3);font-size:0.82rem;">—</span>
            <?php endif; ?>
          </td>
          <td><?php if ($p['badge']): ?><span class="badge badge-<?= $p['badge'] ?>"><?= $p['badge'] ?></span><?php endif; ?></td>
          <td><?= $p['featured'] ? '⭐' : '—' ?></td>
          <td style="color:var(--text3);font-size:0.85rem;"><?= $p['release_year'] ? (int)$p['release_year'] : '—' ?></td>
          <td>
            <div style="display:flex;gap:6px;">
              <a href="?edit=<?= $p['id'] ?>&page=<?= $page ?>&search=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>" class="btn btn-outline btn-xs">Edit</a>
              <a href="?delete=<?= $p['id'] ?>" class="btn btn-danger btn-xs"
                 data-confirm="Delete '<?= htmlspecialchars($p['name']) ?>'?">Del</a>
            </div>
          </td>
        </tr>
        <?php if ($wCount > 0): ?>
        <tr id="wishlist-row-<?= $p['id'] ?>" style="display:none;">
          <td colspan="9" style="padding:0;">
            <div style="background:rgba(237,30,40,0.06);border-top:1px solid rgba(237,30,40,0.15);border-bottom:1px solid rgba(237,30,40,0.15);padding:12px 20px;">
              <div style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--accent);margin-bottom:8px;">
                Wishlisted by <?= $wCount ?> customer<?= $wCount !== 1 ? 's' : '' ?>
              </div>
              <div style="display:flex;flex-direction:column;gap:4px;">
                <?php foreach ($wUsers as $wu): ?>
                <div style="display:flex;align-items:center;gap:12px;font-size:0.84rem;">
                  <span style="font-weight:600;color:var(--text1);min-width:120px;"><?= htmlspecialchars(trim($wu['first_name'] . ' ' . $wu['last_name'])) ?></span>
                  <a href="mailto:<?= htmlspecialchars($wu['email']) ?>" style="color:var(--text3);text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text3)'"><?= htmlspecialchars($wu['email']) ?></a>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($pages > 1): ?>
      <div style="padding:16px 20px;">
        <div class="pagination">
          <?php for ($i=1; $i<=$pages; $i++): ?>
            <?php if ($i===$page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Add / Edit form -->
  <div class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-title"><?= $editing ? 'Edit Product' : 'Add Product' ?></div>
    </div>
    <div style="padding:20px;">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="product_id" value="<?= $editing['id'] ?? '' ?>">
        <input type="hidden" name="return_page" value="<?= $page ?>">
        <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">

        <div class="form-group">
          <label>Name *</label>
          <input id="productName" type="text" name="name" class="form-control"
                 value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Slug *</label>
          <input id="productSlug" type="text" name="slug" class="form-control"
                 value="<?= htmlspecialchars($editing['slug'] ?? '') ?>" required>
        </div>
        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label>Category *</label>
            <select name="category_id" class="form-control">
              <?php $cats->data_seek(0); while ($c = $cats->fetch_assoc()): ?>
                <option value="<?= $c['id'] ?>" <?= ($editing['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Badge</label>
            <select name="badge" class="form-control">
              <?php foreach (['','NEW','HOT','SALE'] as $b): ?>
                <option value="<?= $b ?>" <?= ($editing['badge'] ?? '') === $b ? 'selected' : '' ?>><?= $b ?: 'None' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label>Price (CHF) *</label>
            <input type="number" name="price" step="0.01" class="form-control" value="<?= $editing['price'] ?? '' ?>" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Old Price</label>
            <input type="number" name="old_price" step="0.01" class="form-control" value="<?= $editing['old_price'] ?? '' ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="margin-bottom:0;">
            <label>Stock</label>
            <input type="number" name="stock" class="form-control" value="<?= $editing['stock'] ?? 100 ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Release Year</label>
            <input type="number" name="release_year" class="form-control" placeholder="e.g. 2023" min="1990" max="<?= date('Y') + 1 ?>" value="<?= $editing['release_year'] ?? '' ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;display:flex;flex-direction:row;align-items:center;gap:10px;padding-top:28px;">
            <input type="checkbox" name="featured" id="featuredCheck" <?= ($editing['featured'] ?? 0) ? 'checked' : '' ?>>
            <label for="featuredCheck" style="margin-bottom:0;font-size:0.85rem;">Featured</label>
          </div>
        </div>
        <!-- Gallery image slots -->
        <div class="form-group">
          <label>Product Images <span style="font-weight:400;color:var(--text3);font-size:0.8rem;">(up to 10 · converted to PNG · slot 1 = main image)</span></label>
          <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:6px;">
            <?php for ($slot = 1; $slot <= 10; $slot++):
              $existingPath = $editingImages[$slot] ?? null;
              $existingUrl  = $existingPath ? BASE_URL . '/' . $existingPath . '?v=' . time() : null;
            ?>
              <div style="position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--surface2);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;cursor:pointer;transition:border-color 0.15s;"
                   id="slot-wrap-<?= $slot ?>"
                   onclick="document.getElementById('slot-input-<?= $slot ?>').click()"
                   onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">

                <?php if ($existingUrl): ?>
                  <!-- Existing image -->
                  <img src="<?= htmlspecialchars($existingUrl) ?>" id="slot-preview-<?= $slot ?>"
                       style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#fff;">
                  <!-- Download button -->
                  <a href="<?= htmlspecialchars(BASE_URL . '/' . $existingPath) ?>" download
                     onclick="event.stopPropagation()"
                     style="position:absolute;top:5px;left:5px;background:rgba(0,0,0,0.7);border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;color:#fff;z-index:2;text-decoration:none;"
                     title="Download image">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  </a>
                  <!-- Delete button -->
                  <button type="button" onclick="event.stopPropagation();deleteSlot(<?= $slot ?>)"
                          style="position:absolute;top:5px;right:5px;background:rgba(0,0,0,0.7);border:none;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:14px;line-height:1;z-index:2;">×</button>
                  <input type="hidden" name="delete_slot[<?= $slot ?>]" id="delete-slot-<?= $slot ?>" value="">
                <?php else: ?>
                  <!-- Empty slot -->
                  <div id="slot-preview-<?= $slot ?>" style="display:contents;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span style="font-size:0.72rem;color:var(--text3);font-weight:600;"><?= $slot ?></span>
                  </div>
                  <input type="hidden" name="delete_slot[<?= $slot ?>]" id="delete-slot-<?= $slot ?>" value="">
                <?php endif; ?>

                <input type="file" id="slot-input-<?= $slot ?>" name="product_images[<?= $slot ?>]"
                       accept="image/*" style="display:none;" onchange="previewSlot(<?= $slot ?>, this)">
              </div>
            <?php endfor; ?>
          </div>
          <?php if (!empty($editing['image_url']) && empty($editingImages)): ?>
            <p style="margin-top:8px;font-size:0.78rem;color:var(--text3);">Current: <a href="<?= htmlspecialchars($editing['image_url']) ?>" target="_blank" style="color:var(--accent);">external image URL</a> — upload above to replace with local images.</p>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
        </div>

        <!-- Shipping -->
        <div style="border-top:1px solid var(--border);margin:20px 0 16px;padding-top:20px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <span style="font-family:var(--font-head);font-weight:700;font-size:0.95rem;">Shipping — Swiss Post</span>
            <span style="font-size:0.75rem;color:var(--text3);">(product dimensions in original box)</span>
          </div>
          <div class="form-row">
            <div class="form-group" style="margin-bottom:0;">
              <label>Weight (grams)</label>
              <input type="number" name="weight_grams" min="0" class="form-control"
                     placeholder="e.g. 1850"
                     value="<?= $editing['weight_grams'] ?? '' ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label>Length (cm)</label>
              <input type="number" name="length_cm" min="0" step="0.1" class="form-control"
                     placeholder="e.g. 47.8"
                     value="<?= $editing['length_cm'] ?? '' ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label>Width (cm)</label>
              <input type="number" name="width_cm" min="0" step="0.1" class="form-control"
                     placeholder="e.g. 37.6"
                     value="<?= $editing['width_cm'] ?? '' ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label>Height (cm)</label>
              <input type="number" name="height_cm" min="0" step="0.1" class="form-control"
                     placeholder="e.g. 11.0"
                     value="<?= $editing['height_cm'] ?? '' ?>">
            </div>
          </div>
          <?php if (!empty($editing['weight_grams'])): ?>
            <?php
              $previewPriority = calculateShipping([$editing + ['qty'=>1]], 'priority');
              $previewEconomy  = calculateShipping([$editing + ['qty'=>1]], 'economy');
            ?>
            <div style="margin-top:10px;padding:10px 14px;background:var(--surface2);border-radius:8px;font-size:0.8rem;color:var(--text3);display:flex;gap:20px;flex-wrap:wrap;">
              <span>📦 <?= $previewPriority['weight_kg'] ?> kg &nbsp;·&nbsp; <?= $previewPriority['dimensions'] ?> (packed)</span>
              <span style="color:var(--text2);">Priority <strong style="color:var(--accent);">CHF <?= number_format($previewPriority['price'],2) ?></strong></span>
              <span style="color:var(--text2);">Economy <strong style="color:var(--accent);">CHF <?= number_format($previewEconomy['price'],2) ?></strong></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Deal of the Day -->
        <div style="border-top:1px solid var(--border);margin:20px 0 16px;padding-top:20px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <span style="font-size:1rem;">🔥</span>
            <span style="font-family:var(--font-head);font-weight:700;font-size:0.95rem;">Deal of the Day</span>
            <span style="font-size:0.75rem;color:var(--text3);">(leave blank to remove)</span>
          </div>
          <div class="form-row">
            <div class="form-group" style="margin-bottom:0;">
              <label>Deal Price (CHF)</label>
              <input type="number" name="deal_price" step="0.01" class="form-control"
                     placeholder="e.g. 749.99"
                     value="<?= !empty($editing['deal_price']) ? $editing['deal_price'] : '' ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label>Countdown (hours)</label>
              <input type="number" name="deal_hours" min="0" step="1" class="form-control"
                     placeholder="e.g. 24"
                     value="">
            </div>
          </div>
          <?php
            if (!empty($editing['deal_end'])) {
              $now     = time();
              $endTime = strtotime($editing['deal_end']);
              if ($endTime > $now) {
                $diff = $endTime - $now;
                $h = floor($diff/3600); $m = floor(($diff%3600)/60);
                echo "<div style='font-size:0.78rem;color:#10b981;margin-top:8px;'>✓ Active — {$h}h {$m}m remaining. Enter new hours above to reset.</div>";
              } else {
                echo "<div style='font-size:0.78rem;color:var(--accent);margin-top:8px;'>✗ Deal has expired. Enter hours above to restart.</div>";
              }
            }
          ?>
        </div>

        <button type="submit" name="save_product" class="btn btn-primary"><?= $editing ? 'Update Product' : 'Add Product' ?></button>
        <?php if ($editing): ?>
          <a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-outline" style="margin-left:8px;">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<script>
function toggleWishlistRow(productId) {
  var row = document.getElementById('wishlist-row-' + productId);
  if (!row) return;
  row.style.display = row.style.display === 'none' ? '' : 'none';
}

function previewSlot(slot, input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var wrap = document.getElementById('slot-wrap-' + slot);
    var prev = document.getElementById('slot-preview-' + slot);
    if (prev && prev.tagName === 'IMG') {
      // Existing server image — update src in place
      prev.src = e.target.result;
    } else {
      // Empty slot — hide placeholder, insert new preview img
      if (prev) prev.style.display = 'none';
      var img = document.createElement('img');
      img.id = 'slot-new-img-' + slot;
      img.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#fff;pointer-events:none;';
      img.src = e.target.result;
      wrap.insertBefore(img, wrap.firstChild);
      if (!document.getElementById('slot-clear-btn-' + slot)) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'slot-clear-btn-' + slot;
        btn.innerHTML = '&times;';
        btn.style.cssText = 'position:absolute;top:5px;right:5px;background:rgba(0,0,0,0.7);border:none;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:14px;line-height:1;z-index:2;';
        btn.onclick = function(ev) { ev.stopPropagation(); clearSlot(slot); };
        wrap.appendChild(btn);
      }
    }
  };
  reader.readAsDataURL(input.files[0]);
}

function clearSlot(slot) {
  document.getElementById('slot-input-' + slot).value = '';
  var img = document.getElementById('slot-new-img-' + slot);
  if (img) img.remove();
  var btn = document.getElementById('slot-clear-btn-' + slot);
  if (btn) btn.remove();
  var prev = document.getElementById('slot-preview-' + slot);
  if (prev) prev.style.display = '';
}

function deleteSlot(slot) {
  document.getElementById('delete-slot-' + slot).value = '1';
  var wrap = document.getElementById('slot-wrap-' + slot);
  var prev = document.getElementById('slot-preview-' + slot);
  if (prev) prev.style.display = 'none';
  var phpBtn = wrap.querySelector('button:not([id])');
  if (phpBtn) phpBtn.style.display = 'none';
  if (!document.getElementById('slot-placeholder-' + slot)) {
    var ph = document.createElement('div');
    ph.id = 'slot-placeholder-' + slot;
    ph.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:6px;pointer-events:none;';
    ph.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>'
                 + '<span style="font-size:0.72rem;color:var(--text3);font-weight:600;">' + slot + '</span>';
    wrap.insertBefore(ph, wrap.firstChild);
  }
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
