<?php
if (!isset($conn)) { require_once __DIR__ . '/db.php'; }
require_once __DIR__ . '/functions.php';
cleanCart($conn);
$cartCount   = getCartCount();
$currentUser = isLoggedIn() ? getCurrentUser($conn) : null;
$categories  = $conn->query("SELECT * FROM categories ORDER BY name");
$pageTitle   = $pageTitle ?? SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — SwissBricks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=Lilita+One&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>">
</head>
<body>

<header class="site-header" id="site-header">
  <div class="header-inner">

    <!-- Logo -->
    <a href="<?= BASE_URL ?>/" class="logo-link">
      <img src="<?= BASE_URL ?>/assets/logo.png" alt="SwissBricks" class="logo-img">
    </a>

    <!-- Search -->
    <form class="header-search" action="<?= BASE_URL ?>/pages/shop.php" method="GET">
      <input type="text" name="search" placeholder="Search LEGO Sets..."
             value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" autocomplete="off">
      <button type="submit" aria-label="Search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        </svg>
      </button>
    </form>

    <!-- Nav actions -->
    <nav class="header-actions">
      <a href="<?= BASE_URL ?>/pages/shop.php" class="nav-link">Shop</a>

      <?php if ($currentUser): ?>
        <div class="user-menu-wrap">
          <button class="user-menu-btn" id="userMenuBtn">
              <span class="user-initials-sm"><?= strtoupper(substr($currentUser['first_name'],0,1).substr($currentUser['last_name'],0,1)) ?></span>
            <span><?= htmlspecialchars($currentUser['first_name']) ?></span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="user-dropdown" id="userDropdown">
            <a href="<?= BASE_URL ?>/pages/myaccount.php">My Account</a>
            <a href="<?= BASE_URL ?>/pages/myaccount.php?tab=orders">My Orders</a>
            <?php if (isAdmin()): ?>
              <a href="<?= BASE_URL ?>/admin/">Admin Panel</a>
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <a href="<?= BASE_URL ?>/pages/logout.php" class="logout-link">Sign Out</a>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/pages/login.php" class="nav-link">Sign In</a>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/pages/cart.php" class="cart-btn" aria-label="Shopping cart">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <span class="cart-badge" style="<?= $cartCount == 0 ? 'display:none' : '' ?>"><?= $cartCount ?></span>
      </a>

      <!-- Hamburger -->
      <button class="hamburger" id="hamburger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </nav>

  </div>

  <!-- Mobile nav -->
  <div class="mobile-nav" id="mobileNav">
    <a href="<?= BASE_URL ?>/">Home</a>
    <a href="<?= BASE_URL ?>/pages/shop.php">All Sets</a>
    <?php if ($categories): $categories->data_seek(0); while ($cat = $categories->fetch_assoc()): ?>
      <a href="<?= BASE_URL ?>/pages/shop.php?cat=<?= $cat['slug'] ?>"><?= $cat['icon'] ?> <?= htmlspecialchars($cat['name']) ?></a>
    <?php endwhile; endif; ?>
    <hr>
    <?php if ($currentUser): ?>
      <a href="<?= BASE_URL ?>/pages/myaccount.php">My Account</a>
      <a href="<?= BASE_URL ?>/pages/logout.php">Sign Out</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/pages/login.php">Sign In</a>
      <a href="<?= BASE_URL ?>/pages/register.php">Register</a>
    <?php endif; ?>
  </div>
</header>
