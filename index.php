<?php
session_start();
require_once __DIR__ . '/core/db.php';
if (!function_exists('csrf_token')) { require_once __DIR__ . '/core/csrf.php'; }

$is_logged_in = isset($_SESSION['user_id']);
if ($is_logged_in && isset($_GET['auth'])) {
    $dest = (($_SESSION['role'] ?? '') === 'admin') ? 'pages/admin_dashboard.php' : 'pages/dashboard.php';
    header("Location: $dest");
    exit();
}

$login_error   = "";
$login_success = "";
$reg_error     = "";
$forgot_error   = "";
$reset_error   = "";
$reset_email   = "";
$active_auth_tab = $_GET['auth'] ?? ($_POST['active_tab'] ?? '');

// ── BRUTE FORCE HELPERS ────────────────────────────────────
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

function get_login_attempts(mysqli $conn, string $email): array {
    $stmt = $conn->prepare("SELECT attempt_count, locked_until FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: ['attempt_count' => 0, 'locked_until' => null];
}

function record_failed_attempt(mysqli $conn, string $email): void {
    // Administrator accounts are strictly exempt from brute-force lockout
    $chk = $conn->prepare("SELECT role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    $u = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($u && ($u['role'] ?? '') === 'admin') {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO login_attempts (email, attempt_count, last_attempt)
        VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            attempt_count = attempt_count + 1,
            last_attempt  = NOW(),
            locked_until  = IF(attempt_count + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
    ");
    $max  = MAX_LOGIN_ATTEMPTS;
    $lock = LOCKOUT_MINUTES;
    $stmt->bind_param("sii", $email, $max, $lock);
    $stmt->execute();
    $stmt->close();
}

function clear_login_attempts(mysqli $conn, string $email): void {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
}

// ── PROCESS AUTH ACTIONS ──────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['auth_action'])) {
    $is_ajax = (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

    // Validate CSRF
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'   => 'error',
                'message'  => 'Security token mismatch. Please try again.',
                'new_csrf' => csrf_token()
            ]);
            exit();
        }
        http_response_code(403);
        die('CSRF token mismatch. Please refresh and try again.');
    }
    // Regenerate token after successful verification
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $auth_action = $_POST['auth_action'];

    if ($auth_action === 'login') {
        $active_auth_tab = 'login';
        $login_input = trim($_POST['email'] ?? '');
        $password    = trim($_POST['password'] ?? '');

        if (empty($login_input)) {
            $login_error = "Please enter your email.";
        } elseif (empty($password)) {
            $login_error = "Password is required.";
        } else {
            $login_lc  = mb_strtolower($login_input);

            // Pre-check if this account belongs to an administrator
            $chk_admin = $conn->prepare("SELECT role FROM users WHERE LOWER(email) = ? LIMIT 1");
            $chk_admin->bind_param("s", $login_lc);
            $chk_admin->execute();
            $admin_row = $chk_admin->get_result()->fetch_assoc();
            $chk_admin->close();
            $is_admin_account = ($admin_row && ($admin_row['role'] ?? '') === 'admin');

            $attempts  = get_login_attempts($conn, $login_lc);
            $is_locked = $attempts['locked_until'] && strtotime($attempts['locked_until']) > time();

            // Administrator accounts are never locked out
            if (!$is_admin_account && $is_locked) {
                $mins  = ceil((strtotime($attempts['locked_until']) - time()) / 60);
                $login_error = "Too many failed attempts. Try again in {$mins} minute(s).";
            } else {
                $stmt = $conn->prepare("SELECT user_id, full_name, name, email, password, role, status FROM users WHERE LOWER(email) = ? LIMIT 1");
                $stmt->bind_param("s", $login_lc);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user && password_verify($password, $user['password'])) {
                    if ($user['status'] !== 'active' && ($user['role'] ?? '') !== 'admin') {
                        $login_error = "Your account has been suspended. Contact support.";
                    } else {
                        $_SESSION['user_id']       = (int)$user['user_id'];
                        $_SESSION['name']          = !empty($user['full_name']) ? $user['full_name'] : $user['name'];
                        $_SESSION['email']         = $user['email'];
                        $_SESSION['role']          = $user['role'] ?? 'user';
                        $_SESSION['last_activity'] = time();
                        $_SESSION['fingerprint']   = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                        session_regenerate_id(false);
                        session_write_close();
                        clear_login_attempts($conn, $login_lc);

                        $dest = (($user['role'] ?? '') === 'admin') ? 'pages/admin_dashboard.php' : 'pages/dashboard.php';
                        header("Location: $dest");
                        exit();
                    }
                } else {
                    if ($is_admin_account) {
                        // Admin accounts are exempt from lockout
                        $login_error = "Incorrect password for administrator account.";
                    } else {
                        record_failed_attempt($conn, $login_lc);
                        $remaining = MAX_LOGIN_ATTEMPTS - ($attempts['attempt_count'] + 1);
                        if ($remaining > 0) {
                            $login_error = "Wrong credentials or password. {$remaining} attempt(s) remaining.";
                        } else {
                            $login_error = "Account locked for " . LOCKOUT_MINUTES . " minutes due to too many failed attempts.";
                        }
                    }
                }
            }
        }
    } elseif ($auth_action === 'register') {
        $active_auth_tab = 'register';
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($full_name === '' || mb_strlen($full_name) < 2) {
            $reg_error = "Please enter your full name.";
        } elseif (mb_strlen($full_name) > 100) {
            $reg_error = "Full name must be under 100 characters.";
        } elseif (!preg_match("/^[a-zA-Z\s'-]+$/u", $full_name)) {
            $reg_error = "Full name can only contain letters, spaces, and hyphens.";
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $reg_error = "Please enter a valid email address.";
        } elseif (strlen($password) < 8) {
            $reg_error = "Password needs at least 8 characters.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $reg_error = "Password needs at least one number.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $reg_error = "Password needs at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $reg_error = "Password needs at least one lowercase letter.";
        } else {
            // Check email uniqueness
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1");
            $email_lc = mb_strtolower($email);
            $check_stmt->bind_param("s", $email_lc);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $check_stmt->close();
                $reg_error = "Email already registered. Please log in.";
            } else {
                $check_stmt->close();
                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $clean_name = strip_tags($full_name);

                $ins = $conn->prepare("
                    INSERT INTO users (full_name, name, email, password, role, status, is_verified, is_active)
                    VALUES (?, ?, ?, ?, 'user', 'active', 0, 1)
                ");
                $ins->bind_param("ssss", $clean_name, $clean_name, $email_lc, $hashed);

                    if ($ins->execute()) {
                        $new_user_id = $ins->insert_id;
                        $ins->close();

                        $_SESSION['user_id']       = (int)$new_user_id;
                        $_SESSION['name']          = $clean_name;
                        $_SESSION['email']         = $email_lc;
                        $_SESSION['role']          = 'user';
                        $_SESSION['last_activity'] = time();
                        $_SESSION['fingerprint']   = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                        header("Location: pages/dashboard.php");
                        exit();
                    } else {
                        $ins->close();
                        $reg_error = "System error during registration. Please try again.";
                    }
                }
            }
    } elseif ($auth_action === 'forgot_password') {
        $active_auth_tab = 'forgot';
        $forgot_input = trim($_POST['email'] ?? '');

        if (empty($forgot_input)) {
            $forgot_error = "Please enter your registered email address.";
        } else {
            $input_lc = mb_strtolower($forgot_input);
            $stmt = $conn->prepare("SELECT user_id, email, full_name, name, role FROM users WHERE LOWER(email) = ? LIMIT 1");
            $stmt->bind_param("s", $input_lc);
            $stmt->execute();
            $found_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($found_user) {
                // Email exists: transition to change password popup box
                $reset_email = $found_user['email'];
                $active_auth_tab = 'reset';
            } else {
                // Email does not exist: exact requirement "Invalid credentials"
                $forgot_error = "Invalid credentials";
            }
        }

        if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json; charset=utf-8');
            if (!empty($reset_email)) {
                echo json_encode([
                    'status'   => 'success',
                    'email'    => $reset_email,
                    'new_csrf' => csrf_token()
                ]);
            } else {
                echo json_encode([
                    'status'   => 'error',
                    'message'  => $forgot_error ?: 'Invalid credentials',
                    'new_csrf' => csrf_token()
                ]);
            }
            exit();
        }
    } elseif ($auth_action === 'change_password' || $auth_action === 'reset_password') {
        $active_auth_tab = 'reset';
        $reset_email = trim($_POST['email'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($reset_email)) {
            $reset_error = "Invalid credentials";
            $active_auth_tab = 'forgot';
        } elseif (empty($new_password)) {
            $reset_error = "Please enter a new password.";
        } elseif (strlen($new_password) < 8) {
            $reset_error = "Password needs at least 8 characters.";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $reset_error = "Password needs at least one number.";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $reset_error = "Password needs at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $reset_error = "Password needs at least one lowercase letter.";
        } elseif ($new_password !== $confirm_password) {
            $reset_error = "Passwords do not match.";
        } else {
            // Verify email exists in DB
            $email_lc = mb_strtolower($reset_email);
            $chk = $conn->prepare("SELECT user_id, email, role FROM users WHERE LOWER(email) = ? LIMIT 1");
            $chk->bind_param("s", $email_lc);
            $chk->execute();
            $target_user = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$target_user) {
                $reset_error = "Invalid credentials";
                $active_auth_tab = 'forgot';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE LOWER(email) = ?");
                $upd->bind_param("ss", $new_hash, $email_lc);
                if ($upd->execute()) {
                    $upd->close();

                    // Clear any lockout records for this email
                    clear_login_attempts($conn, $email_lc);

                    $login_success = "Password updated successfully. Please sign in with your new password.";
                    $active_auth_tab = 'login';
                } else {
                    $upd->close();
                    $reset_error = "Failed to update password. Please try again.";
                }
            }
        }

        if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json; charset=utf-8');
            if (!empty($reset_error)) {
                echo json_encode([
                    'status'   => 'error',
                    'message'  => $reset_error,
                    'new_csrf' => csrf_token()
                ]);
            } else {
                echo json_encode([
                    'status'   => 'success',
                    'message'  => $login_success,
                    'email'    => $reset_email,
                    'new_csrf' => csrf_token()
                ]);
            }
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fintrack — Personal Finance & Wealth Tracking for Kenya</title>
<meta name="description" content="Master your money with precision. Track daily cashflow, automate budget caps, and project long-term wealth in Kenyan Shillings.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* ─────────────────────────────────────────────────────────────
   DESIGN SYSTEM & DESIGN TOKENS
   - Warm stone background (#ECE6DD)
   - Cards, rows and inputs soft cream (#F4EFE7 / #FBF8F3)
   - Primary text navy (#0B1020), muted (#4A5170)
   - Accent orange (#F26A2E) for headline highlight & small highlights
   - Navy (#141B33) ONLY for preview card & simulator result card.
     (Navy is NOT a button color anywhere!)
   - Features band deep burnt orange (#B9501C)
   - Wealth simulator panel tan (#F3E1CF)
   - Footer darker stone (#E1D9CC)
   - Shared button system defined once via CSS custom properties
───────────────────────────────────────────────────────────── */
:root {
    /* ── Single Accent & Button Gradient Tokens (Easy to change in one place) ── */
    --accent: #F26A2E;
    --btn-primary-bg: linear-gradient(90deg, #FF7A3D 0%, #F0455A 100%);
    --btn-primary-shadow: 0 8px 20px rgba(240, 69, 90, 0.3);
    --btn-secondary-border: #B5AA99;
    
    /* ── Core Surfaces & Text ── */
    --page-bg: #ECE6DD;
    --cream: #F4EFE7;
    --cream-card: #FBF8F3;
    --cream-border: rgba(11, 16, 32, 0.08);
    
    --navy: #0B1020;         /* Primary text */
    --navy-card: #141B33;    /* Used ONLY for preview card & simulator result card */
    --muted: #4A5170;        /* Muted secondary body text (WCAG AA > 7:1) */
    
    --features-bg: #B9501C;  /* Deep burnt orange for features band */
    --tan-panel: #F3E1CF;    /* Tan panel for wealth simulator */
    --footer-bg: #E1D9CC;    /* Slightly darker stone for footer */
    
    --green-badge: #10B981;
    --green-badge-bg: rgba(16, 185, 129, 0.16);
    
    /* ── Typography ── */
    --font-head: 'Space Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    
    /* ── Radii ── */
    --radius-input: 12px;
    --radius-btn: 14px;
    --radius-card: 24px;
    --radius-lg: 28px;
    --radius-full: 9999px;
    
    /* ── Shadows ── */
    --shadow-soft: 0 4px 20px rgba(11, 16, 32, 0.05);
    --shadow-card: 0 12px 36px rgba(11, 16, 32, 0.08);
    --shadow-navy: 0 20px 48px rgba(11, 16, 32, 0.22);
}

*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    scroll-behavior: smooth;
}

body {
    background-color: var(--page-bg);
    color: var(--navy);
    font-family: var(--font-body);
    font-size: 16px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    min-height: 100vh;
}

/* Accessible focus ring */
a:focus-visible,
button:focus-visible,
input:focus-visible {
    outline: 3px solid #F0455A;
    outline-offset: 2px;
}

/* ─────────────────────────────────────────────────────────────
   SHARED BUTTON SYSTEM
   Defined once, used everywhere.
   PRIMARY: gradient, white bold text, 12-14px radius, 44px+ (52px modal), glow shadow.
   SECONDARY: transparent, 1px border #B5AA99, navy text.
   Navy is NOT a button color anywhere!
───────────────────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 44px;
    padding: 0 24px;
    border-radius: var(--radius-btn);
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.01em;
    text-decoration: none;
    cursor: pointer;
    transition: transform 150ms ease, filter 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
    border: 1px solid transparent;
}
.btn:hover {
    transform: translateY(-1px);
}
.btn:active {
    transform: translateY(0);
}
.btn:focus-visible {
    outline: 3px solid #F0455A;
    outline-offset: 2px;
}
.btn:disabled {
    opacity: 0.5;
    box-shadow: none !important;
    cursor: not-allowed;
    transform: none !important;
}

/* Shared PRIMARY button */
.btn-primary {
    background: var(--btn-primary-bg);
    color: #FFFFFF !important;
    border: none;
    box-shadow: var(--btn-primary-shadow);
    border-radius: var(--radius-btn);
}
.btn-primary:hover {
    filter: brightness(1.06);
    transform: translateY(-1px);
}
.btn-primary:focus-visible {
    outline: 3px solid #F0455A;
    outline-offset: 2px;
}
.btn-primary:disabled {
    opacity: 0.5;
    box-shadow: none !important;
}

/* Shared full-width PRIMARY button for modals (52px tall) */
.btn-primary-modal {
    width: 100%;
    min-height: 52px;
    font-size: 16px;
    border-radius: var(--radius-btn);
    margin-top: 10px;
}

/* Shared SECONDARY button */
.btn-secondary {
    background: transparent;
    color: var(--navy);
    border: 1px solid var(--btn-secondary-border);
    border-radius: var(--radius-btn);
}
.btn-secondary:hover {
    background: rgba(11, 16, 32, 0.05);
    border-color: #9E917F;
    transform: translateY(-1px);
}

/* ─────────────────────────────────────────────────────────────
   LOGO (Exact match with dashboard sidebar)
   40px rounded square (12px radius) with diagonal gradient
   from orange (#FF8A3D) at top-left to coral-pink (#F0455A) at bottom-right.
   White circled dollar sign with 2px stroke, rounded line caps.
   Wordmark: "Fintrack" in Space Grotesk bold 22px, navy.
───────────────────────────────────────────────────────────── */
.brand-link {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: var(--navy);
    flex-shrink: 0;
}
.brand-icon-tile {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, #FF8A3D 0%, #F0455A 100%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(240, 69, 90, 0.25);
}
.brand-icon-tile svg {
    width: 22px;
    height: 22px;
    color: #FFFFFF;
}
.brand-name {
    font-family: var(--font-head);
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.03em;
    color: var(--navy);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 1: NAVIGATION (Full-width bar with thin bottom border)
   Logo at FAR LEFT, Buttons at FAR RIGHT, Links in middle (hidden on mobile)
   NOT constrained to content width.
───────────────────────────────────────────────────────────── */
.site-nav {
    width: 100%;
    border-bottom: 1px solid rgba(11, 16, 32, 0.1);
    background: rgba(236, 230, 221, 0.94);
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.nav-inner {
    width: 100%;
    padding: 0 32px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.nav-links {
    display: flex;
    align-items: center;
    gap: 36px;
    list-style: none;
}
.nav-links a {
    color: var(--navy);
    text-decoration: none;
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 600;
    transition: color 0.15s ease;
}
.nav-links a:hover {
    color: var(--accent);
}
.nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

/* ─────────────────────────────────────────────────────────────
   SECTION 2: HERO
   Real photo background, stone overlay (65-80%) fading to solid stone at bottom
   Headline + One-line subheading. NO buttons in hero.
   Dashboard preview (navy card + cream card).
───────────────────────────────────────────────────────────── */
.hero-section {
    position: relative;
    background-image: url('assets/fintech_people.jpg');
    background-size: cover;
    background-position: center top;
    background-repeat: no-repeat;
    padding: 80px 24px 96px;
    overflow: hidden;
}
.hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(236, 230, 221, 0.72) 0%,
        rgba(236, 230, 221, 0.82) 55%,
        #ECE6DD 100%
    );
    pointer-events: none;
}
.hero-content {
    position: relative;
    z-index: 2;
    max-width: 1200px;
    margin: 0 auto;
}
.hero-header-block {
    text-align: center;
    max-width: 820px;
    margin: 0 auto 56px;
}
.hero-h1 {
    font-family: var(--font-head);
    font-size: clamp(38px, 5.2vw, 64px);
    font-weight: 700;
    line-height: 1.08;
    letter-spacing: -0.04em;
    color: var(--navy);
    margin-bottom: 18px;
}
.hero-h1 .accent-word {
    color: var(--accent);
}
.hero-sub {
    font-size: clamp(17px, 1.8vw, 20px);
    line-height: 1.5;
    color: var(--muted);
    margin: 0 auto;
    max-width: 680px;
}

/* ── Dashboard Preview Grid (Navy card + Cream card) ── */
.preview-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 24px;
    max-width: 1100px;
    margin: 0 auto;
}

/* Navy Card (#141B33) - ONLY used here & in simulator result */
.card-navy {
    background: var(--navy-card);
    border-radius: var(--radius-lg);
    padding: 32px;
    color: #FFFFFF;
    box-shadow: var(--shadow-navy);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.preview-navy-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
    gap: 16px;
}
.preview-label {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #A0AEC0;
    font-family: var(--font-head);
    font-weight: 600;
    margin-bottom: 4px;
}
.preview-val {
    font-family: var(--font-head);
    font-size: clamp(26px, 3.2vw, 36px);
    font-weight: 700;
    letter-spacing: -0.03em;
    color: #FFFFFF;
}
.badge-growth {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--green-badge-bg);
    color: #34D399;
    padding: 6px 12px;
    border-radius: var(--radius-full);
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    border: 1px solid rgba(52, 211, 153, 0.3);
    white-space: nowrap;
}
.chart-wrap {
    height: 180px;
    width: 100%;
    position: relative;
}

/* Cream Card (#FBF8F3 / #F4EFE7) for recent activity */
.card-cream {
    background: var(--cream-card);
    border-radius: var(--radius-lg);
    padding: 32px;
    border: 1px solid var(--cream-border);
    box-shadow: var(--shadow-card);
    display: flex;
    flex-direction: column;
}
.cream-card-title {
    font-family: var(--font-head);
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--navy);
    margin-bottom: 20px;
}
.activity-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1;
    justify-content: center;
}
.activity-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: var(--cream);
    border-radius: var(--radius-input);
    border: 1px solid var(--cream-border);
}
.activity-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.activity-icon-tile {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(11, 16, 32, 0.06);
    color: var(--navy);
}
.activity-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--navy);
}
.activity-amount {
    font-family: var(--font-head);
    font-size: 14px;
    font-weight: 700;
    letter-spacing: -0.01em;
}
.amt-positive {
    color: #059669;
}
.amt-negative {
    color: var(--navy);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 3: FEATURES (Deep burnt-orange band #B9501C)
   White headline, three cream cards each with a navy icon tile
───────────────────────────────────────────────────────────── */
.features-band {
    width: 100%;
    background-color: var(--features-bg);
    padding: 88px 24px;
}
.features-container {
    max-width: 1200px;
    margin: 0 auto;
}
.features-headline {
    text-align: center;
    font-family: var(--font-head);
    font-size: clamp(30px, 3.8vw, 46px);
    font-weight: 700;
    letter-spacing: -0.03em;
    color: #FFFFFF;
    margin-bottom: 56px;
}
.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 28px;
}
.feature-card {
    background: var(--cream-card);
    border-radius: var(--radius-btn);
    padding: 34px 28px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    display: flex;
    flex-direction: column;
}
.feature-icon-tile {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: var(--navy-card);
    color: #FFFFFF;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 22px;
}
.feature-h3 {
    font-family: var(--font-head);
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--navy);
    margin-bottom: 12px;
}
.feature-p {
    font-size: 15px;
    line-height: 1.55;
    color: var(--muted);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 4: WEALTH SIMULATOR (Tan panel #F3E1CF)
   Left: 2 live sliders. Right: Navy card (#141B33) compounding 7%
───────────────────────────────────────────────────────────── */
.sim-section {
    padding: 88px 24px 96px;
}
.sim-container {
    max-width: 1200px;
    margin: 0 auto;
}
.sim-panel {
    background-color: var(--tan-panel);
    border-radius: var(--radius-lg);
    padding: 56px;
    box-shadow: var(--shadow-card);
}
.sim-grid {
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 48px;
    align-items: center;
}
.sim-left-h2 {
    font-family: var(--font-head);
    font-size: clamp(26px, 3.2vw, 36px);
    font-weight: 700;
    letter-spacing: -0.03em;
    color: var(--navy);
    margin-bottom: 8px;
}
.sim-left-desc {
    color: var(--muted);
    font-size: 15px;
    margin-bottom: 36px;
}
.slider-group {
    margin-bottom: 28px;
}
.slider-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 10px;
}
.slider-label {
    font-family: var(--font-head);
    font-size: 14px;
    font-weight: 600;
    color: var(--navy);
}
.slider-val-badge {
    font-family: var(--font-head);
    font-size: 16px;
    font-weight: 700;
    color: var(--navy);
    background: rgba(11, 16, 32, 0.08);
    padding: 4px 12px;
    border-radius: var(--radius-full);
}

/* Sliders */
.calc-range {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    height: 8px;
    border-radius: 4px;
    background: rgba(11, 16, 32, 0.15);
    outline: none;
    cursor: pointer;
}
.calc-range::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--navy);
    border: 3px solid #FFFFFF;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}
.calc-range::-moz-range-thumb {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--navy);
    border: 3px solid #FFFFFF;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}

/* Simulator Result (Navy Card #141B33) */
.sim-navy-card {
    background: var(--navy-card);
    border-radius: var(--radius-btn);
    padding: 38px 32px;
    color: #FFFFFF;
    box-shadow: var(--shadow-navy);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.sim-card-top-lbl {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #A0AEC0;
    font-family: var(--font-head);
    font-weight: 600;
    margin-bottom: 6px;
}
.sim-total-display {
    font-family: var(--font-head);
    font-size: clamp(32px, 3.8vw, 44px);
    font-weight: 700;
    letter-spacing: -0.03em;
    color: #FFFFFF;
    margin-bottom: 26px;
}
.sim-breakdown-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    margin-bottom: 22px;
}
.sim-breakdown-sublbl {
    font-size: 12px;
    color: #A0AEC0;
    font-family: var(--font-head);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 4px;
}
.sim-breakdown-val {
    font-family: var(--font-head);
    font-size: 17px;
    font-weight: 700;
    color: #FFFFFF;
}
.sim-breakdown-val.green-txt {
    color: #34D399;
}
.sim-caption {
    font-size: 12px;
    line-height: 1.5;
    color: #A0AEC0;
    padding-top: 14px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 5: FOOTER (Darker stone #E1D9CC)
   Logo, short description, contact details, four social buttons, copyright
───────────────────────────────────────────────────────────── */
.site-footer {
    background-color: var(--footer-bg);
    border-top: 1px solid rgba(11, 16, 32, 0.08);
    padding: 64px 24px 40px;
    color: var(--navy);
}
.footer-container {
    max-width: 1200px;
    margin: 0 auto;
}
.footer-main-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr;
    gap: 48px;
    padding-bottom: 48px;
    border-bottom: 1px solid rgba(11, 16, 32, 0.1);
}
.footer-desc {
    font-size: 14px;
    line-height: 1.55;
    color: var(--muted);
    margin-top: 14px;
    max-width: 320px;
}
.footer-col-h {
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 16px;
    letter-spacing: -0.01em;
}
.footer-contacts-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 10px;
    font-size: 14px;
}
.footer-contacts-list a {
    color: var(--muted);
    text-decoration: none;
    transition: color 0.15s ease;
}
.footer-contacts-list a:hover {
    color: var(--navy);
}
.footer-contacts-list li {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--muted);
}

/* 4 Social Buttons */
.footer-social-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}
.social-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 0 14px;
    background: var(--cream);
    border: 1px solid var(--cream-border);
    border-radius: var(--radius-input);
    color: var(--navy);
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.15s ease, background-color 0.15s ease;
}
.social-btn:hover {
    background: #FFFFFF;
    transform: translateY(-1px);
}

.footer-bottom-bar {
    padding-top: 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    color: var(--muted);
    flex-wrap: wrap;
    gap: 16px;
}
.footer-legal-links {
    display: flex;
    gap: 20px;
}
.footer-legal-links a {
    color: var(--muted);
    text-decoration: none;
}
.footer-legal-links a:hover {
    color: var(--navy);
}

/* ─────────────────────────────────────────────────────────────
   AUTHENTICATION MODALS (Centered cream card #FBF8F3, 24px radius)
   Dimmed backdrop with page visible behind it.
───────────────────────────────────────────────────────────── */
.auth-overlay {
    position: fixed;
    inset: 0;
    background: rgba(11, 16, 32, 0.60);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.auth-overlay.open {
    display: flex;
}
.auth-card {
    background: var(--cream-card);
    border-radius: var(--radius-card);
    width: 100%;
    max-width: 440px;
    padding: 36px 30px;
    position: relative;
    box-shadow: 0 20px 48px rgba(11, 16, 32, 0.25);
    border: 1px solid var(--cream-border);
}
.auth-close {
    position: absolute;
    top: 20px;
    right: 20px;
    background: transparent;
    border: none;
    font-size: 20px;
    line-height: 1;
    color: var(--muted);
    cursor: pointer;
    padding: 6px;
    border-radius: 50%;
    transition: color 0.15s ease;
}
.auth-close:hover {
    color: var(--navy);
}
.auth-view {
    display: none;
}
.auth-view.active {
    display: block;
}
.auth-modal-title {
    font-family: var(--font-head);
    font-size: 24px;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: 4px;
    color: var(--navy);
}
.auth-modal-sub {
    font-size: 13.5px;
    color: var(--muted);
    margin-bottom: 22px;
}
.auth-field {
    margin-bottom: 16px;
    text-align: left;
}
.auth-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 6px;
    font-family: var(--font-body);
}
.auth-field input {
    width: 100%;
    min-height: 44px;
    padding: 0 14px;
    border-radius: var(--radius-input);
    border: 1px solid var(--btn-secondary-border);
    background: #FFFFFF;
    color: var(--navy);
    font-family: var(--font-body);
    font-size: 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.auth-field input:focus {
    outline: none;
    border-color: #F0455A;
    box-shadow: 0 0 0 3px rgba(240, 69, 90, 0.20);
}
.pwd-wrap {
    position: relative;
}
.pwd-toggle-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
}
.pwd-toggle-btn:hover {
    color: var(--navy);
}
.forgot-pwd-link {
    font-size: 12.5px;
    color: var(--navy);
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.forgot-pwd-link:hover {
    text-decoration: underline;
}
.auth-error {
    background: #FEF2F2;
    border: 1px solid #FCA5A5;
    color: #DC2626;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 16px;
}
.auth-toggle-msg {
    text-align: center;
    font-size: 13px;
    color: var(--muted);
    margin-top: 18px;
}
.auth-toggle-msg a {
    color: var(--navy);
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}
.strength-bar {
    height: 4px;
    background: rgba(11, 16, 32, 0.1);
    border-radius: 2px;
    margin-top: 6px;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    width: 0%;
    transition: width 0.3s ease, background 0.3s ease;
}
.strength-lbl {
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
}

/* ─────────────────────────────────────────────────────────────
   RESPONSIVE DESIGN (Phone width < 768px)
───────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .preview-grid {
        grid-template-columns: 1fr;
    }
    .features-grid {
        grid-template-columns: 1fr;
    }
    .sim-grid {
        grid-template-columns: 1fr;
    }
    .footer-main-grid {
        grid-template-columns: 1fr;
        gap: 36px;
    }
}

@media (max-width: 768px) {
    .nav-inner {
        padding: 0 16px;
        height: 64px;
    }
    .nav-links {
        display: none;
    }
    .hero-section {
        padding: 56px 16px 72px;
    }
    .hero-h1 {
        font-size: 34px;
    }
    .card-navy, .card-cream {
        padding: 22px 18px;
    }
    .features-band {
        padding: 60px 16px;
    }
    .sim-section {
        padding: 60px 16px 72px;
    }
    .sim-panel {
        padding: 28px 18px;
    }
    .auth-card {
        padding: 28px 20px;
        border-radius: 20px;
    }
    .footer-social-grid {
        grid-template-columns: 1fr;
    }
    .footer-bottom-bar {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
</head>

<body>

<!-- ───────────────────────────────────────────────────────────
     SECTION 1: NAVIGATION
     Full-width bar with thin bottom border.
     Logo at FAR LEFT, links in middle (hide on mobile).
     The ONLY two CTA buttons on the page sit at FAR RIGHT:
     "Sign in" (secondary) and "Get started" (primary).
─────────────────────────────────────────────────────────── -->
<header class="site-nav" role="banner">
    <div class="nav-inner">
        <!-- FAR LEFT: Brand (Gradient Tile + Space Grotesk Wordmark) -->
        <a href="index.php" class="brand-link" aria-label="Fintrack Home">
            <div class="brand-icon-tile">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <span class="brand-name">Fintrack</span>
        </a>

        <!-- MIDDLE: Anchor Links (hidden on phone) -->
        <ul class="nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#simulator">Simulator</a></li>
            <li><a href="#security">Security</a></li>
        </ul>

        <!-- FAR RIGHT: The only two CTA buttons on the whole page -->
        <div class="nav-actions">
            <?php if ($is_logged_in): ?>
                <a href="<?php echo (($_SESSION['role'] ?? '') === 'admin') ? 'pages/admin_dashboard.php' : 'pages/dashboard.php'; ?>" class="btn btn-primary">
                    Dashboard →
                </a>
            <?php else: ?>
                <button type="button" onclick="openAuthModal('login')" class="btn btn-secondary" aria-label="Sign in">
                    Sign in
                </button>
                <button type="button" onclick="openAuthModal('register')" class="btn btn-primary" aria-label="Get started">
                    Get started
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<main>
    <!-- ───────────────────────────────────────────────────────
         SECTION 2: HERO
         Real photo background with stone overlay (65-80%) fading to solid stone.
         Headline + one-line sub. NO buttons in hero.
         Dashboard preview (navy card + cream card).
    ─────────────────────────────────────────────────────── -->
    <section class="hero-section">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <div class="hero-header-block">
                <h1 class="hero-h1">
                    Master your money with <span class="accent-word">precision.</span>
                </h1>
                <p class="hero-sub">
                    The personal finance operating system built for Kenya to track cashflow, automate budget caps, and build long-term wealth in KES.
                </p>
            </div>

            <!-- Dashboard Preview (Navy Card + Cream Card) -->
            <div class="preview-grid">
                <!-- Navy Card: Net Worth + Chart -->
                <div class="card-navy">
                    <div class="preview-navy-top">
                        <div>
                            <div class="preview-label">Net worth</div>
                            <div class="preview-val">KES 4,556,000</div>
                        </div>
                        <div class="badge-growth">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                            </svg>
                            +12.4% this month
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="heroNetWorthChart"></canvas>
                    </div>
                </div>

                <!-- Cream Card: Recent Activity -->
                <div class="card-cream">
                    <div class="cream-card-title">Recent activity</div>
                    <ul class="activity-list">
                        <li class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon-tile">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <span class="activity-name">Web consulting</span>
                            </div>
                            <span class="activity-amount amt-positive">+KES 15,000</span>
                        </li>
                        <li class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon-tile">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                </div>
                                <span class="activity-name">Dining out</span>
                            </div>
                            <span class="activity-amount amt-negative">-KES 350</span>
                        </li>
                        <li class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon-tile">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                </div>
                                <span class="activity-name">Freelance work</span>
                            </div>
                            <span class="activity-amount amt-positive">+KES 1,000</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ───────────────────────────────────────────────────────
         SECTION 3: FEATURES
         Deep burnt-orange band (#B9501C) with white headline.
         Three cream cards with navy icon tiles.
    ─────────────────────────────────────────────────────── -->
    <section class="features-band" id="features">
        <div class="features-container">
            <h2 class="features-headline">Everything you need. Nothing you don't.</h2>
            <div class="features-grid">
                <!-- Card 1: Cashflow tracking -->
                <div class="feature-card">
                    <div class="feature-icon-tile">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="feature-h3">Cashflow tracking</h3>
                    <p class="feature-p">
                        Record daily income and expenses with automatic category allocation. View live net cashflow without complex spreadsheets.
                    </p>
                </div>

                <!-- Card 2: Smart budget caps -->
                <div class="feature-card">
                    <div class="feature-icon-tile">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <h3 class="feature-h3">Smart budget caps</h3>
                    <p class="feature-p">
                        Daily, monthly and yearly limits with alerts. Get notified before discretionary spending exceeds your financial rules.
                    </p>
                </div>

                <!-- Card 3: Bank-grade security -->
                <div class="feature-card" id="security">
                    <div class="feature-icon-tile">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <h3 class="feature-h3">Bank-grade security</h3>
                    <p class="feature-p">
                        Bcrypt hashing, CSRF protection, brute-force lockout, session fingerprinting, and strict parameterized queries.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ───────────────────────────────────────────────────────
         SECTION 4: WEALTH SIMULATOR
         Tan panel (#F3E1CF).
         Left: Monthly deposit & Time horizon sliders (live).
         Right: Navy card (#141B33) compounding at 7% per year.
    ─────────────────────────────────────────────────────── -->
    <section class="sim-section" id="simulator">
        <div class="sim-container">
            <div class="sim-panel">
                <div class="sim-grid">
                    <!-- Left: Sliders -->
                    <div>
                        <h2 class="sim-left-h2">Wealth simulator</h2>
                        <p class="sim-left-desc">See the impact of disciplined compounding in Kenyan Shillings.</p>

                        <div class="slider-group">
                            <div class="slider-head">
                                <span class="slider-label">Monthly deposit</span>
                                <span class="slider-val-badge" id="depositDisplay">KES 10,000</span>
                            </div>
                            <input type="range" class="calc-range" id="depositSlider" min="1000" max="100000" step="1000" value="10000" aria-label="Monthly deposit slider">
                        </div>

                        <div class="slider-group">
                            <div class="slider-head">
                                <span class="slider-label">Time horizon</span>
                                <span class="slider-val-badge" id="yearsDisplay">3 years</span>
                            </div>
                            <input type="range" class="calc-range" id="yearsSlider" min="1" max="10" step="1" value="3" aria-label="Time horizon slider">
                        </div>
                    </div>

                    <!-- Right: Navy Result Card (#141B33) -->
                    <div class="sim-navy-card">
                        <div class="sim-card-top-lbl">Projected balance</div>
                        <div class="sim-total-display" id="projectedTotal">KES 401,313</div>

                        <div class="sim-breakdown-row">
                            <div>
                                <div class="sim-breakdown-sublbl">Total deposited</div>
                                <div class="sim-breakdown-val" id="totalDeposited">KES 360,000</div>
                            </div>
                            <div>
                                <div class="sim-breakdown-sublbl">Growth earned</div>
                                <div class="sim-breakdown-val green-txt" id="growthEarned">+KES 41,313</div>
                            </div>
                        </div>

                        <div class="sim-caption">
                            Estimate based on steady monthly savings and 7% annual compound growth.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- ───────────────────────────────────────────────────────────
     SECTION 5: FOOTER (Darker stone #E1D9CC)
     Logo, short description, contact details, four social buttons, copyright
─────────────────────────────────────────────────────────── -->
<footer class="site-footer">
    <div class="footer-container">
        <div class="footer-main-grid">
            <!-- Brand & Description (Exact same logo as nav) -->
            <div>
                <a href="index.php" class="brand-link" aria-label="Fintrack Home">
                    <div class="brand-icon-tile">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <span class="brand-name">Fintrack</span>
                </a>
                <p class="footer-desc">Personal finance and wealth-tracking web app built for Kenyans.</p>
            </div>

            <!-- Contact Details -->
            <div>
                <h4 class="footer-col-h">Contact Details</h4>
                <ul class="footer-contacts-list">
                    <li>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <a href="mailto:support@fintrack.co.ke">support@fintrack.co.ke</a>
                    </li>
                    <li>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <a href="mailto:info@fintrack.co.ke">info@fintrack.co.ke</a>
                    </li>
                    <li>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span>+254 (0) 700 123 456</span>
                    </li>
                    <li>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span>Nairobi, Kenya</span>
                    </li>
                </ul>
            </div>

            <!-- Social Links (4 Required Buttons) -->
            <div>
                <h4 class="footer-col-h">Follow Fintrack</h4>
                <div class="footer-social-grid">
                    <a href="https://twitter.com" target="_blank" rel="noopener" class="social-btn" aria-label="X @Fintrack_KE">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        <span>@Fintrack_KE</span>
                    </a>
                    <a href="https://linkedin.com" target="_blank" rel="noopener" class="social-btn" aria-label="LinkedIn /company/fintrack">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                        <span>/company/fintrack</span>
                    </a>
                    <a href="https://github.com" target="_blank" rel="noopener" class="social-btn" aria-label="GitHub fintrack-finance">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                        <span>fintrack-finance</span>
                    </a>
                    <a href="https://instagram.com" target="_blank" rel="noopener" class="social-btn" aria-label="Instagram @fintrack.app">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                        <span>@fintrack.app</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="footer-bottom-bar">
            <div>
                © <?php echo date('Y'); ?> Fintrack. All rights reserved.
            </div>
            <div class="footer-legal-links">
                <a href="#privacy">Privacy Policy</a>
                <a href="#terms">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<!-- ───────────────────────────────────────────────────────────
     AUTHENTICATION MODALS (Centered cream card #FBF8F3, 24px radius)
─────────────────────────────────────────────────────────── -->
<?php $modal_open = (!empty($login_error) || !empty($login_success) || !empty($reg_error) || !empty($forgot_error) || !empty($reset_error) || $active_auth_tab === 'reset' || !empty($_GET['auth'])); ?>
<div class="auth-overlay <?php echo $modal_open ? 'open' : ''; ?>" id="authOverlay" onclick="if(event.target===this) closeAuthModal()">
    <div class="auth-card">
        <button class="auth-close" onclick="closeAuthModal()" aria-label="Close modal">✕</button>

        <!-- SIGN IN VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === '' || $active_auth_tab === 'login') ? 'active' : ''; ?>" id="viewLogin">
            <h2 class="auth-modal-title">Welcome back</h2>
            <p class="auth-modal-sub">Sign in to your Fintrack account</p>

            <?php if (!empty($login_error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($login_success)): ?>
                <div id="loginSuccessBox" class="auth-success" style="background:#ECFDF5;border:1px solid rgba(16,185,129,.3);color:#059669;padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.5;">
                    <?php echo htmlspecialchars($login_success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="login">
                <input type="hidden" name="active_tab" value="login">

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" name="email" required autocomplete="off" placeholder="name@example.com" value="<?php echo htmlspecialchars($login_input ?? ''); ?>">
                </div>

                <div class="auth-field">
                    <label>Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="loginPassword" name="password" required autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('loginPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <div style="text-align:right;margin-top:6px;">
                        <a onclick="switchAuthTab('forgot')" class="forgot-pwd-link">Forgot password?</a>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-primary-modal">Sign In to Dashboard →</button>
            </form>

            <div class="auth-toggle-msg">
                Don't have an account? <a onclick="switchAuthTab('register')">Register here</a>
            </div>
        </div>

        <!-- REGISTER VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === 'register') ? 'active' : ''; ?>" id="viewRegister">
            <h2 class="auth-modal-title">Create your account</h2>
            <p class="auth-modal-sub">Start your financial journey today</p>

            <?php if (!empty($reg_error)): ?>
                <div class="auth-error" id="regErrorBox"><?php echo htmlspecialchars($reg_error); ?></div>
            <?php else: ?>
                <div class="auth-error" id="regErrorBox" style="display:none;"></div>
            <?php endif; ?>

            <form id="regForm" method="POST" action="index.php" autocomplete="off" novalidate onsubmit="return validateRegForm(this)">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="register">
                <input type="hidden" name="active_tab" value="register">

                <div class="auth-field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required autocomplete="off" placeholder="Stephen Kim" maxlength="100">
                </div>

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" name="email" required autocomplete="off" placeholder="name@example.com">
                </div>

                <div class="auth-field">
                    <label>Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="regPassword" name="password" required autocomplete="new-password" placeholder="At least 8 characters" oninput="checkAuthStrength(this.value)">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('regPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="authStrengthFill"></div></div>
                    <div class="strength-lbl" id="authStrengthLbl">Enter a password</div>
                </div>

                <div class="auth-field">
                    <label>Confirm Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="regConfirmPassword" name="confirm_password" required autocomplete="new-password" placeholder="Repeat password">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('regConfirmPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-primary-modal">Create Account →</button>
            </form>

            <div class="auth-toggle-msg">
                Already have an account? <a onclick="switchAuthTab('login')">Sign in</a>
            </div>
        </div>

        <!-- FORGOT PASSWORD VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === 'forgot') ? 'active' : ''; ?>" id="viewForgot">
            <h2 class="auth-modal-title">Forgot Password</h2>
            <p class="auth-modal-sub">Enter your registered email address to verify your account.</p>

            <div class="auth-error" id="forgotErrorBox" style="<?php echo empty($forgot_error) ? 'display:none;' : ''; ?>">
                <?php echo htmlspecialchars($forgot_error ?? ''); ?>
            </div>

            <form id="forgotForm" method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="forgot_password">
                <input type="hidden" name="active_tab" value="forgot">

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" id="forgotEmailInput" name="email" required autocomplete="off" placeholder="name@example.com" value="<?php echo htmlspecialchars($forgot_input ?? ''); ?>">
                </div>

                <button type="submit" id="forgotSubmitBtn" class="btn btn-primary btn-primary-modal">Verify Email →</button>
            </form>

            <div class="auth-toggle-msg">
                Remembered your password? <a onclick="switchAuthTab('login')">Sign in</a>
            </div>
        </div>

        <!-- RESET PASSWORD VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === 'reset') ? 'active' : ''; ?>" id="viewReset">
            <h2 class="auth-modal-title">Set New Password</h2>
            <p class="auth-modal-sub">Reset password for <strong style="color:var(--navy);" id="resetEmailDisplay"><?php echo htmlspecialchars($reset_email); ?></strong></p>

            <div class="auth-error" id="resetErrorBox" style="<?php echo empty($reset_error) ? 'display:none;' : ''; ?>">
                <?php echo htmlspecialchars($reset_error ?? ''); ?>
            </div>

            <form id="resetForm" method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="reset_password">
                <input type="hidden" name="active_tab" value="reset">
                <input type="hidden" name="email" id="resetEmailHidden" value="<?php echo htmlspecialchars($reset_email); ?>">

                <div class="auth-field">
                    <label>New Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="resetPassword" name="new_password" required autocomplete="new-password" placeholder="At least 8 characters">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('resetPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="auth-field">
                    <label>Confirm New Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="resetConfirmPassword" name="confirm_password" required autocomplete="new-password" placeholder="Repeat new password">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('resetConfirmPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" id="resetSubmitBtn" class="btn btn-primary btn-primary-modal">Update Password →</button>
            </form>

            <div class="auth-toggle-msg">
                <a onclick="switchAuthTab('login')">← Back to Sign in</a>
            </div>
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────────
// AUTH MODAL LOGIC
// ─────────────────────────────────────────────────────────────
function openAuthModal(view) {
    const overlay = document.getElementById('authOverlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    switchAuthTab(view || 'login');
}

function closeAuthModal() {
    const overlay = document.getElementById('authOverlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
}

function switchAuthTab(tab) {
    document.querySelectorAll('.auth-view').forEach(v => v.classList.remove('active'));
    const target = {
        'login': 'viewLogin',
        'register': 'viewRegister',
        'forgot': 'viewForgot',
        'reset': 'viewReset'
    }[tab] || 'viewLogin';
    const el = document.getElementById(target);
    if (el) el.classList.add('active');
}

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    const eyeOpen = `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>`;
    const eyeClosed = `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858-5.908A8.962 8.962 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21M3 3l18 18"/></svg>`;
    btn.innerHTML = isPassword ? eyeClosed : eyeOpen;
}

function checkAuthStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const fill = document.getElementById('authStrengthFill');
    const lbl  = document.getElementById('authStrengthLbl');
    if (!fill || !lbl) return;

    const colors = ['#EF4444', '#F59E0B', '#3B82F6', '#10B981'];
    const labels = ['Too weak', 'Fair', 'Good', 'Strong'];
    const widths = ['25%', '50%', '75%', '100%'];

    if (val.length === 0) {
        fill.style.width = '0%';
        lbl.textContent = 'Enter a password';
        lbl.style.color = 'var(--muted)';
    } else {
        const idx = Math.max(0, score - 1);
        fill.style.width = widths[idx];
        fill.style.background = colors[idx];
        lbl.textContent = labels[idx];
        lbl.style.color = colors[idx];
    }
}

function validateRegForm(form) {
    const p1 = form.password ? form.password.value : '';
    const p2 = form.confirm_password ? form.confirm_password.value : '';
    const errBox = document.getElementById('regErrorBox');
    if (p1 && p2 && p1 !== p2) {
        if (errBox) {
            errBox.textContent = 'Passwords do not match.';
            errBox.style.display = 'block';
        }
        return false;
    }
    return true;
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeAuthModal();
});

// ─────────────────────────────────────────────────────────────
// HERO NET WORTH CHART (Rising orange area chart)
// ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('heroNetWorthChart');
    if (ctx) {
        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 180);
        gradient.addColorStop(0, 'rgba(242, 106, 46, 0.40)');
        gradient.addColorStop(1, 'rgba(242, 106, 46, 0.00)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'],
                datasets: [{
                    label: 'Net Worth (KES)',
                    data: [3650000, 3780000, 3910000, 4050000, 4200000, 4380000, 4556000],
                    borderColor: '#F26A2E',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#F26A2E',
                    pointBorderColor: '#141B33',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.35,
                    fill: true,
                    backgroundColor: gradient
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0B1020',
                        titleColor: '#FFFFFF',
                        bodyColor: '#A0AEC0',
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: (c) => ' KES ' + c.raw.toLocaleString()
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#718096', font: { size: 11, family: "'Inter', sans-serif" } },
                        border: { display: false }
                    },
                    y: {
                        display: false,
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────
    // WEALTH SIMULATOR LOGIC
    // Monthly compounding at 7% per year (r = 0.07 / 12)
    // ─────────────────────────────────────────────────────────
    const depositSlider  = document.getElementById('depositSlider');
    const yearsSlider    = document.getElementById('yearsSlider');
    const depositDisplay = document.getElementById('depositDisplay');
    const yearsDisplay   = document.getElementById('yearsDisplay');
    const projectedTotal = document.getElementById('projectedTotal');
    const totalDeposited = document.getElementById('totalDeposited');
    const growthEarned   = document.getElementById('growthEarned');

    function calculateWealth() {
        if (!depositSlider || !yearsSlider) return;
        const P = parseFloat(depositSlider.value);
        const y = parseInt(yearsSlider.value);
        const r = 0.07 / 12; // Monthly rate
        const n = y * 12;    // Total months

        // Future value of regular monthly deposits: FV = P * [((1 + r)^n - 1) / r] * (1 + r)
        const fv = P * ((Math.pow(1 + r, n) - 1) / r) * (1 + r);
        const totalDep = P * n;
        const growth = Math.max(0, fv - totalDep);

        depositDisplay.textContent = 'KES ' + P.toLocaleString();
        yearsDisplay.textContent   = y + (y === 1 ? ' year' : ' years');
        projectedTotal.textContent = 'KES ' + Math.round(fv).toLocaleString();
        totalDeposited.textContent = 'KES ' + Math.round(totalDep).toLocaleString();
        growthEarned.textContent   = '+KES ' + Math.round(growth).toLocaleString();
    }

    if (depositSlider && yearsSlider) {
        depositSlider.addEventListener('input', calculateWealth);
        yearsSlider.addEventListener('input', calculateWealth);
        calculateWealth(); // Initial run
    }

    // ─────────────────────────────────────────────────────────
    // FORGOT / RESET PASSWORD AJAX FLOW
    // ─────────────────────────────────────────────────────────
    const forgotForm = document.getElementById('forgotForm');
    if (forgotForm) {
        forgotForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('forgotSubmitBtn');
            const errBox = document.getElementById('forgotErrorBox');
            const emailInput = document.getElementById('forgotEmailInput');
            if (btn) btn.disabled = true;
            if (errBox) errBox.style.display = 'none';

            const formData = new FormData(this);
            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.new_csrf) {
                    document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = data.new_csrf);
                }
                if (data.status === 'success') {
                    switchAuthTab('reset');
                    const disp = document.getElementById('resetEmailDisplay');
                    const hid  = document.getElementById('resetEmailHidden');
                    if (disp) disp.textContent = data.email || emailInput.value;
                    if (hid)  hid.value = data.email || emailInput.value;
                } else {
                    if (errBox) {
                        errBox.textContent = data.message || "Email not registered in Fintrack system.";
                        errBox.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                if (errBox) {
                    errBox.textContent = "An error occurred. Please try again.";
                    errBox.style.display = 'block';
                }
            })
            .finally(() => {
                if (btn) btn.disabled = false;
            });
        });
    }

    const resetForm = document.getElementById('resetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('resetSubmitBtn');
            const errBox = document.getElementById('resetErrorBox');
            if (btn) btn.disabled = true;
            if (errBox) errBox.style.display = 'none';

            const formData = new FormData(this);
            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.new_csrf) {
                    document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = data.new_csrf);
                }
                if (data.status === 'success') {
                    switchAuthTab('login');
                    const succ = document.getElementById('loginSuccessBox');
                    if (succ) {
                        succ.textContent = data.message || "Password reset successful! Please sign in.";
                        succ.style.display = 'block';
                    }
                } else {
                    if (errBox) {
                        errBox.textContent = data.message || "Password reset failed.";
                        errBox.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                if (errBox) {
                    errBox.textContent = "An error occurred. Please try again.";
                    errBox.style.display = 'block';
                }
            })
            .finally(() => {
                if (btn) btn.disabled = false;
            });
        });
    }
});
</script>
</body>
</html>