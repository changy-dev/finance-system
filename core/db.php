<?php
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
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please check your system configuration.");
}

$conn->set_charset('utf8mb4');
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi');
$conn->query("SET time_zone = '+03:00'");

if (!$conn->query("
    CREATE TABLE IF NOT EXISTS `login_attempts` (
        `email` VARCHAR(100) NOT NULL,
        `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
        `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `locked_until` DATETIME DEFAULT NULL,
        PRIMARY KEY (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
")) {
    error_log("Failed to initialize login_attempts table: " . $conn->error);
    die("Could not initialize login security. Please check the database configuration.");
}
?>
