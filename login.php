<?php
// ── TEMP DEBUG — remove after fixing ──
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ──────────────────────────────────────
// ============================================================
// CRITICAL FIXES APPLIED:
// #1 — SQL injection: prepared statements replace raw queries
// #3 — Brute force: login attempts tracked, lockout after 5 fails
// #4 — Session security: regenerate ID on login, idle timeout,
//       browser fingerprinting (stable version)
// ============================================================

session_start();
require_once 'db.php';

// Already logged in — redirect (check BEFORE touching the session)
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// ── Session idle timeout ───────────────────────────────────
$session_lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1800);

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_lifetime
) {
    session_unset();
    session_destroy();
    session_start();
}

// ── BRUTE FORCE HELPERS ────────────────────────────────────
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

function get_login_attempts(mysqli $conn, string $email): array
{
    $stmt = $conn->prepare("
        SELECT attempt_count, locked_until
        FROM login_attempts
        WHERE email = ?
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: [
        'attempt_count' => 0,
        'locked_until' => null
    ];
}

function record_failed_attempt(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("
        INSERT INTO login_attempts (
            email,
            attempt_count,
            last_attempt
        )
        VALUES (?, 1, NOW())

        ON DUPLICATE KEY UPDATE
            attempt_count = attempt_count + 1,
            last_attempt = NOW(),
            locked_until = IF(
                attempt_count + 1 >= ?,
                DATE_ADD(NOW(), INTERVAL ? MINUTE),
                locked_until
            )
    ");

    $max = MAX_LOGIN_ATTEMPTS;
    $lock = LOCKOUT_MINUTES;

    $stmt->bind_param("sii", $email, $max, $lock);
    $stmt->execute();
}

function clear_login_attempts(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("
        DELETE FROM login_attempts
        WHERE email = ?
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();
}

// ── PROCESS LOGIN ──────────────────────────────────────────
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Sanitize inputs
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Invalid email address.";

    } elseif (empty($password)) {

        $error = "Password is required.";

    } else {

        // Check brute force lockout
        $attempts = get_login_attempts($conn, $email);

        $is_locked =
            $attempts['locked_until'] &&
            strtotime($attempts['locked_until']) > time();

        if ($is_locked) {

            $mins = ceil(
                (strtotime($attempts['locked_until']) - time()) / 60
            );

            $error = "Too many failed attempts. Try again in {$mins} minute(s).";

        } else {

            // Secure prepared statement
            $stmt = $conn->prepare("
                SELECT
                    user_id,
                    full_name,
                    password,
                    role,
                    status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $email);
            $stmt->execute();

            $user = $stmt->get_result()->fetch_assoc();

            if (
                $user &&
                password_verify($password, $user['password'])
            ) {

                // Account active?
                if ($user['status'] !== 'active') {

                    $error = "Your account has been suspended. Contact support.";

                } else {

                    // ── LOGIN SUCCESS ─────────────────────
                    // Write session data first, THEN regenerate ID
                    // (regenerate with delete=true before writing = session loss)
                    $_SESSION['user_id'] =
                        (int)$user['user_id'];

                    $_SESSION['name'] =
                        $user['full_name'];

                    $_SESSION['role'] =
                        $user['role'] ?? 'user';

                    // Refresh activity timer
                    $_SESSION['last_activity'] =
                        time();

                    // Stable fingerprint using browser only
                    $_SESSION['fingerprint'] = hash(
                        'sha256',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    );

                    // Regenerate AFTER writing data, keep old session briefly
                    session_regenerate_id(false);

                    // Force session write to disk before redirect
                    session_write_close();

                    // Clear failed login attempts
                    clear_login_attempts(
                        $conn,
                        $email
                    );

                    header("Location: dashboard.php");
                    exit();
                }

            } else {

                // Record failed login
                record_failed_attempt(
                    $conn,
                    $email
                );

                $remaining =
                    MAX_LOGIN_ATTEMPTS -
                    ($attempts['attempt_count'] + 1);

                if ($remaining > 0) {

                    $error =
                        "Wrong email or password. {$remaining} attempt(s) remaining.";

                } else {

                    $error =
                        "Account locked for " .
                        LOCKOUT_MINUTES .
                        " minutes due to too many failed attempts.";
                }
            }
        }
    }
}
?>