<?php
// Load .env if not already loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php') && !isset($_ENV['DB_USER'])) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

define('DB_HOST',   $_ENV['DB_HOST']   ?? 'localhost');
define('DB_USER',   $_ENV['DB_USER']   ?? 'root');
define('DB_PASS',   $_ENV['DB_PASS']   ?? '');
define('DB_NAME',   $_ENV['DB_NAME']   ?? 'swissbricks');
define('DB_PORT',   (int)($_ENV['DB_PORT'] ?? 3306));
define('SITE_NAME', 'SwissBricks');
define('BASE_URL',  $_ENV['BASE_URL']  ?? '');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    die('<div style="font-family:sans-serif;padding:40px;background:#1a1a2e;color:#ff6b6b;">
        <h2>Database connection failed</h2>
        <p>Please ensure MySQL is running and the database <strong>' . DB_NAME . '</strong> exists.</p>
        <p><small>' . htmlspecialchars($conn->connect_error) . '</small></p>
    </div>');
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+00:00'");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
