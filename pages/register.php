<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/myaccount.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name']  ?? '');
    $email      = sanitize($_POST['email']      ?? '');
    $password   = $_POST['password']  ?? '';
    $password2  = $_POST['password2'] ?? '';

    if (!$first_name) $errors[] = 'First name is required.';
    if (!$last_name)  $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password2) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id, is_verified FROM users WHERE email=? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing && $existing['is_verified']) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        if (!empty($existing) && !$existing['is_verified']) {
            // Overwrite the stale unverified record
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, password=? WHERE id=?");
            $stmt->bind_param('sssi', $first_name, $last_name, $hashed, $existing['id']);
            $stmt->execute();
            $userId = $existing['id'];
        } else {
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, is_verified) VALUES (?,?,?,?,'member',0)");
            $stmt->bind_param('ssss', $first_name, $last_name, $email, $hashed);
            $stmt->execute();
            $userId = $conn->insert_id;
        }
        sendVerificationEmail($conn, $userId, $email, $first_name, 'new');
        $_SESSION['verify_sent_email'] = $email;
        header('Location: ' . BASE_URL . '/pages/register.php?sent=1');
        exit;
    }
}

$sent  = false;
$email = '';
if (isset($_GET['sent']) && !empty($_SESSION['verify_sent_email'])) {
    $sent  = true;
    $email = $_SESSION['verify_sent_email'];
    unset($_SESSION['verify_sent_email']);
}

$pageTitle = 'Create Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — SwissBricks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">
      <a href="<?= BASE_URL ?>/"><img src="<?= BASE_URL ?>/assets/logo.png" alt="SwissBricks"></a>
    </div>

    <?php if (!empty($sent)): ?>
      <div style="text-align:center;">
        <div style="font-size:2.5rem;margin:8px 0;">✓</div>
        <h1 class="auth-title" style="font-size:1.4rem;">Check your inbox</h1>
        <p class="auth-subtitle">We've sent a verification link to <strong><?= htmlspecialchars($email) ?></strong>. Click it to activate your account. Please check your spam or junk folder.</p>
        <a href="<?= BASE_URL ?>/pages/login.php" class="btn btn-outline btn-full" style="margin-top:24px;">Back to Log In</a>
      </div>
    <?php else: ?>
      <h1 class="auth-title">Create Account</h1>
      <p class="auth-subtitle">Join SwissBricks and start shopping</p>

      <?php if ($errors): ?>
        <div class="alert alert-error"><?= implode('<br>', $errors) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-grid-2">
          <div class="form-group">
            <label>First Name *</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Last Name *</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Password * <span style="color:var(--text3);font-weight:400;">(min. 6 characters)</span></label>
          <input type="password" name="password" required>
        </div>
        <div class="form-group">
          <label>Confirm Password *</label>
          <input type="password" name="password2" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem;padding:14px;margin-top:8px;">
          Create Account
        </button>
      </form>

      <p class="auth-link">Already have an account? <a href="<?= BASE_URL ?>/pages/login.php">Log in →</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
