<footer class="site-footer">
  <div class="footer-inner">

    <div class="footer-brand">
      <img src="<?= BASE_URL ?>/assets/logo.png" alt="SwissBricks" class="footer-logo">
      <p>Switzerland's #1 retailer of rare, retired, and current LEGO sets. Every set sealed in original packaging with picture proof upon request.</p>
    </div>

    <div class="footer-col">
      <h4>Explore</h4>
      <a href="<?= BASE_URL ?>/pages/shop.php">All Sets</a>
      <a href="<?= BASE_URL ?>/#deal-of-the-day" class="footer-deal-link">Sale</a>
    </div>

    <div class="footer-col">
      <h4>Account</h4>
      <a href="<?= BASE_URL ?>/pages/myaccount.php">My Account</a>
      <a href="<?= BASE_URL ?>/pages/myaccount.php?tab=orders">Order History</a>
      <a href="<?= BASE_URL ?>/pages/cart.php">Shopping Cart</a>
      <a href="<?= BASE_URL ?>/pages/login.php">Sign In</a>
      <a href="<?= BASE_URL ?>/pages/register.php">Create Account</a>
    </div>

    <div class="footer-col">
      <h4>Contact</h4>
      <p style="display:flex;align-items:center;gap:8px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Zürich, Switzerland</p>
      <a href="mailto:info@swiss-bricks.com" style="display:flex;align-items:center;gap:8px;color:var(--text2);transition:color 0.2s;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text2)'"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> info@swiss-bricks.com</a>
    </div>

  </div>
  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> SwissBricks. All rights reserved. Not affiliated with the LEGO Group.</p>
  </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= file_exists(__DIR__.'/../assets/js/main.js') ? filemtime(__DIR__.'/../assets/js/main.js') : 1 ?>"></script>
<?php if (function_exists('isLoggedIn')) require_once __DIR__ . '/proof_popup.php'; ?>
</body>
</html>
