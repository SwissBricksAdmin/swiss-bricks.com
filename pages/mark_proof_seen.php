<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

$id   = (int)($_POST['proof_id'] ?? 0);
$user = getCurrentUser($conn);

if ($id && $user) {
    $email = $conn->real_escape_string($user['email']);
    $conn->query("UPDATE picture_proof_requests SET popup_shown=1 WHERE id=$id AND customer_email='$email'");
}

echo 'ok';
