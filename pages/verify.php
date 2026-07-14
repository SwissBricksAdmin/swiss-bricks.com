<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = sanitize($_GET['token'] ?? '');
$mode  = sanitize($_GET['mode']  ?? 'new'); // 'new' = account activation, 'email' = email change
$msg   = '';
$type  = 'error';

if ($token) {
    $stmt = $conn->prepare("SELECT id, email, pending_email, verify_token, verify_expiry FROM users WHERE verify_token=? LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        $msg = 'This verification link is invalid.';
    } elseif (strtotime($user['verify_expiry']) < time()) {
        $msg = 'This verification link has expired. Please request a new one.';
    } elseif ($mode === 'email' && $user['pending_email']) {
        $conn->execute_query("UPDATE users SET email=?, pending_email=NULL, verify_token=NULL, verify_expiry=NULL WHERE id=?", [$user['pending_email'], $user['id']]);
        $msg  = 'Your email address has been updated successfully.';
        $type = 'success';
    } else {
        $conn->execute_query("UPDATE users SET is_verified=1, verify_token=NULL, verify_expiry=NULL WHERE id=?", [$user['id']]);
        $msg  = 'Your account has been verified. You can now log in.';
        $type = 'success';
    }
}

$pageTitle = 'Verify Email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — SwissBricks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card" style="text-align:center;">
    <div class="auth-logo">
      <a href="<?= BASE_URL ?>/"><img src="<?= BASE_URL ?>/assets/logo.png" alt="SwissBricks"></a>
    </div>
    <?php if ($type === 'success'): ?>
      <div style="font-size:2.5rem;margin:16px 0;">✓</div>
      <h1 class="auth-title" style="font-size:1.4rem;">Email Verified</h1>
      <p class="auth-subtitle"><?= $msg ?></p>
      <a href="<?= BASE_URL ?>/pages/login.php" class="btn btn-primary btn-full" style="margin-top:24px;">Log In</a>
    <?php else: ?>
      <h1 class="auth-title" style="font-size:1.4rem;">Verification Failed</h1>
      <div class="alert alert-error" style="margin-top:16px;"><?= $msg ?></div>
      <a href="<?= BASE_URL ?>/" class="btn btn-outline btn-full" style="margin-top:16px;">Back to Home</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
