<?php
// ── TEMP DEBUG — remove after fixing ──
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ──────────────────────────────────────
// ============================================================
// CRITICAL FIX #2: No more hardcoded credentials
// Loads from .env file — root/empty password removed
// ============================================================

// Load .env file
$env_path = __DIR__ . '/../.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'finance_db';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("DB ERROR: " . $conn->connect_error); // TEMP DEBUG — revert after fixing
}

$conn->set_charset('utf8mb4');

// Auto-initialize password_resets table if not present
$conn->query("
    CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(255) NOT NULL,
        `token` VARCHAR(64) NOT NULL,
        `created_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL,
        INDEX (`email`),
        INDEX (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
?>
