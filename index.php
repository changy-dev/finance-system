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
    csrf_verify();
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
    } elseif ($auth_action === 'change_password') {
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
<title>Fintrack — Personal Finance Management & Wealth Tracker</title>
<meta name="description" content="Take total control of your money with Fintrack. Track transactions, automate budget limits, monitor cash flows, and visualize analytics in real-time.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    /* ── Dashboard Matched Palette ── */
    --page:       #F7F6F3;
    --surface:    #FFFFFF;
    --surface-2:  #F2F0EC;
    --surface-3:  #EAE8E2;
    --ink:        #1E1B4B;
    --line:       rgba(30,27,75,.1);
    --line-2:     rgba(30,27,75,.05);

    /* ── Primary Accent: Tangerine → Rose ── */
    --accent:      #FF6B35;
    --accent-2:    #E8356D;
    --accent-grad: linear-gradient(135deg, #FF6B35, #E8356D);
    --accent-glow: 0 4px 20px rgba(255,107,53,.3);

    /* ── Secondary Accents ── */
    --cyan:        #4F46E5;
    --cyan-dim:    rgba(79,70,229,.1);
    --cyan-glow:   0 4px 20px rgba(79,70,229,.18);
    --coral:       #E8356D;
    --coral-dim:   rgba(232,53,109,.1);
    --violet:      #7C3AED;

    /* ── Semantic ── */
    --green:       #059669;
    --green-dim:   rgba(5,150,105,.1);
    --red:         #E11D48;
    --red-dim:     rgba(225,29,72,.1);
    --amber:       #D97706;
    --amber-dim:   rgba(217,119,6,.1);

    /* ── Typography ── */
    --font:        'Plus Jakarta Sans', sans-serif;
    --font-head:   'Space Grotesk', sans-serif;
    --font-mono:   'Space Mono', monospace;
    --text-1:      #0F0F28;
    --text-2:      rgba(15,15,40,.55);
    --text-3:      rgba(15,15,40,.35);

    /* ── Sizing & Shadow ── */
    --radius-lg: 16px;
    --radius-xl: 22px;
    --shadow:    0 1px 8px rgba(15,15,40,.08);
    --shadow-md: 0 4px 24px rgba(15,15,40,.12);
    --shadow-lg: 0 12px 48px rgba(15,15,40,.15);
}

body {
    font-family: var(--font);
    background: var(--page);
    color: var(--text-1);
    font-size: 14px;
    line-height: 1.6;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

/* Subtle dashboard mesh background */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(255,107,53,.07) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(79,70,229,.05) 0%, transparent 55%),
        radial-gradient(ellipse 50% 60% at 50% 50%, rgba(232,53,109,.04) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

body > * { position: relative; z-index: 1; }

/* ── NAVBAR ── */
.navbar {
    height: 70px;
    padding: 0 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(90deg, #1E1B4B 0%, #16134A 100%);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 4px 20px rgba(30,27,75,.15);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}

.brand-icon {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--accent-grad);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(255,107,53,.4);
}

.brand-icon svg { width: 20px; height: 20px; color: #fff; }

.brand-title {
    font-family: var(--font-head);
    font-size: 20px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.3px;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 32px;
    list-style: none;
}

.nav-links a {
    color: rgba(255,255,255,.7);
    text-decoration: none;
    font-weight: 500;
    font-size: 13.5px;
    transition: color .2s;
}

.nav-links a:hover { color: #fff; }

.nav-actions { display: flex; align-items: center; gap: 12px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    font-family: var(--font);
    cursor: pointer;
    text-decoration: none;
    transition: all .2s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}

.btn-ghost {
    background: rgba(255,255,255,.1);
    color: #fff;
    border: 1px solid rgba(255,255,255,.15);
}
.btn-ghost:hover {
    background: rgba(255,255,255,.2);
    border-color: rgba(255,255,255,.3);
}

.btn-primary {
    background: var(--accent-grad);
    color: #fff;
    box-shadow: var(--accent-glow);
    border: none;
}
.btn-primary:hover {
    filter: brightness(1.08);
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(255,107,53,.4);
}

.btn-lg { padding: 13px 26px; font-size: 14.5px; border-radius: 12px; }

/* ── HERO SECTION ── */
.hero {
    padding: 80px 40px 60px;
    max-width: 1200px;
    margin: 0 auto;
    text-align: center;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    background: var(--accent-lt);
    border: 1px solid rgba(255,107,53,.25);
    color: var(--accent);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 20px;
}

.hero-title {
    font-family: var(--font-head);
    font-size: 52px;
    font-weight: 800;
    line-height: 1.18;
    letter-spacing: -1.2px;
    max-width: 900px;
    margin: 0 auto 18px;
    color: var(--text-1);
}

.hero-title span {
    background: var(--accent-grad);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-sub {
    font-size: 17px;
    color: var(--text-2);
    max-width: 660px;
    margin: 0 auto 34px;
    font-weight: 400;
}

.hero-buttons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-bottom: 50px;
}

/* ── DEMO DASHBOARD GRAPHIC ── */
.dashboard-preview {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    padding: 26px;
    max-width: 1040px;
    margin: 0 auto;
    text-align: left;
    position: relative;
    overflow: hidden;
}

.preview-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
}

.preview-card {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 20px;
}

.preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.preview-title { font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: .5px; }

.stat-val { font-family: var(--font-mono); font-size: 26px; font-weight: 700; color: var(--text-1); margin-bottom: 4px; }
.stat-tag { font-size: 12px; color: var(--green); font-weight: 700; }

.chart-box { height: 180px; width: 100%; position: relative; }

.tx-list { display: flex; flex-direction: column; gap: 10px; }

.tx-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background: var(--surface);
    border-radius: 10px;
    border: 1px solid var(--line);
}

.tx-left { display: flex; align-items: center; gap: 10px; }
.tx-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(79,70,229,.1); color: var(--cyan); display: flex; align-items: center; justify-content: center; font-size: 14px; }
.tx-name { font-size: 13px; font-weight: 600; color: var(--text-1); }
.tx-date { font-size: 11px; color: var(--text-3); }
.tx-amt { font-family: var(--font-mono); font-weight: 700; font-size: 13px; }
.tx-amt.income { color: var(--green); }
.tx-amt.expense { color: var(--red); }

/* ── FEATURES GRID ── */
.features-section {
    padding: 80px 40px;
    max-width: 1200px;
    margin: 0 auto;
}

.section-hd {
    text-align: center;
    margin-bottom: 50px;
}

.section-badge {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.section-title {
    font-family: var(--font-head);
    font-size: 34px;
    font-weight: 700;
    color: var(--text-1);
    letter-spacing: -.5px;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.feature-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    padding: 26px 22px;
    box-shadow: var(--shadow);
    transition: transform .25s, border-color .25s, box-shadow .25s;
}

.feature-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255,107,53,.3);
    box-shadow: var(--shadow-md);
}

.feature-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    color: var(--accent);
}

.feature-icon svg { width: 22px; height: 22px; }

.feature-h { font-size: 16px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; }
.feature-p { font-size: 13px; color: var(--text-2); line-height: 1.5; }

/* ── CALCULATOR / PROJECTION SECTION ── */
.calc-section {
    padding: 50px 40px 80px;
    max-width: 1040px;
    margin: 0 auto;
}

.calc-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    padding: 38px;
    box-shadow: var(--shadow-lg);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    align-items: center;
}

.calc-title { font-family: var(--font-head); font-size: 26px; font-weight: 700; color: var(--text-1); margin-bottom: 8px; }
.calc-desc { font-size: 13.5px; color: var(--text-2); margin-bottom: 22px; }

.calc-input-group { margin-bottom: 18px; }
.calc-label { display: block; font-size: 11.5px; font-weight: 700; color: var(--text-2); text-transform: uppercase; margin-bottom: 8px; }
.calc-range { width: 100%; accent-color: var(--accent); }
.calc-val-display { font-family: var(--font-mono); font-size: 17px; font-weight: 700; color: var(--accent); margin-top: 4px; }

.calc-result-box {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 26px;
    text-align: center;
}

.calc-result-lbl { font-size: 11.5px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.calc-result-val { font-family: var(--font-mono); font-size: 36px; font-weight: 800; color: var(--green); letter-spacing: -1px; margin-bottom: 10px; }

/* ── INTEGRATED AUTH MODAL ── */
.auth-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,15,40,.6);
    backdrop-filter: blur(8px);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    transition: opacity .25s ease;
}
.auth-overlay.open {
    display: flex;
    opacity: 1;
}

.auth-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    width: 100%;
    max-width: 420px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 32px 28px;
    box-shadow: var(--shadow-lg);
    position: relative;
    transform: translateY(20px) scale(.92);
    transition: transform .3s cubic-bezier(.34, 1.56, .64, 1);
}
.auth-overlay.open .auth-card {
    transform: translateY(0) scale(1);
}

.auth-close {
    position: absolute;
    top: 18px;
    right: 18px;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    color: var(--text-2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
}
.auth-close:hover { background: var(--surface-3); color: var(--text-1); }

.auth-switcher {
    display: flex;
    background: var(--surface-2);
    padding: 3px;
    border-radius: 10px;
    margin-bottom: 22px;
    border: 1px solid var(--line);
}

.auth-tab-btn {
    flex: 1;
    padding: 9px;
    border: none;
    background: transparent;
    color: var(--text-2);
    font-size: 13px;
    font-weight: 700;
    font-family: var(--font);
    cursor: pointer;
    border-radius: 8px;
    transition: all .2s;
}

.auth-tab-btn.active {
    background: var(--surface);
    color: var(--accent);
    box-shadow: var(--shadow);
}

.auth-view { display: none; }
.auth-view.active { display: block; }

.auth-field { margin-bottom: 16px; text-align: left; }
.auth-field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-2); margin-bottom: 6px; }
.auth-field input { width: 100%; padding: 10px 13px; border: 1.5px solid var(--line); border-radius: 10px; background: var(--surface-2); color: var(--text-1); font-size: 14px; font-family: var(--font); outline: none; transition: border-color .15s, box-shadow .15s, background .15s; }
.auth-field input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(255,107,53,.1); background: var(--surface); }

.pwd-wrap { position: relative; display: flex; align-items: center; }
.pwd-wrap input { padding-right: 40px; }
.pwd-toggle-btn {
    position: absolute;
    right: 10px;
    background: transparent;
    border: none;
    color: var(--text-2);
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: color .15s;
}
.pwd-toggle-btn:hover { color: var(--text-1); }

.forgot-link {
    font-size: 12px;
    color: var(--accent);
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    display: inline-block;
    transition: color .2s, transform .2s;
}
.forgot-link:hover { color: var(--accent-2); text-decoration: underline; }
.forgot-link.shake-accent {
    animation: forgotShake 0.6s cubic-bezier(.36,.07,.19,.97) both 0.25s;
    color: var(--red);
    font-weight: 700;
}

@keyframes forgotShake {
    0%, 100% { transform: translateX(0); }
    15%, 45%, 75% { transform: translateX(-6px); }
    30%, 60%, 90% { transform: translateX(6px); }
}

.auth-submit-btn {
    width: 100%;
    padding: 12px;
    border-radius: 10px;
    border: none;
    background: var(--accent-grad);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    font-family: var(--font);
    cursor: pointer;
    box-shadow: var(--accent-glow);
    margin-top: 6px;
    transition: all .18s;
}
.auth-submit-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }

.auth-toggle-msg {
    text-align: center;
    font-size: 13px;
    color: var(--text-2);
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--line);
}

.auth-toggle-msg a {
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    margin-left: 4px;
}
.auth-toggle-msg a:hover { text-decoration: underline; color: var(--accent-2); }

.auth-error {
    background: var(--red-dim);
    border: 1px solid rgba(225,29,72,.25);
    color: var(--red);
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 16px;
}

.strength-bar { height: 4px; border-radius: 99px; margin-top: 6px; background: var(--surface-3); overflow: hidden; }
.strength-fill { height: 100%; width: 0%; transition: width .3s, background .3s; }
.strength-lbl { font-size: 10px; color: var(--text-3); text-align: right; margin-top: 3px; }

/* ── FOOTER ── */
.footer {
    border-top: 1px solid var(--line);
    padding: 36px 40px;
    text-align: center;
    color: var(--text-3);
    font-size: 13px;
}

@media (max-width: 900px) {
    .navbar { padding: 0 20px; }
    .hero { padding: 50px 20px; }
    .hero-title { font-size: 38px; }
    .navbar { padding: 0 16px; }
    .nav-links { display: none !important; }
    .hero { padding: 50px 16px; }
    .hero-title { font-size: clamp(30px, 7vw, 42px); }
    .features-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: 1fr; }
    .calc-card { grid-template-columns: 1fr; gap: 20px; padding: 24px; }
    .calc-card { grid-template-columns: 1fr; gap: 20px; padding: 20px; }
}

@media (max-width: 600px) {
    .navbar .btn-ghost { display: none; }
    .features-grid { grid-template-columns: 1fr; }
    .hero-buttons { flex-direction: column; }
    .btn { width: 100%; justify-content: center; }
    .hero-buttons { flex-direction: column; width: 100%; }
    .hero-buttons .btn { width: 100%; justify-content: center; }
    .calc-card { padding: 16px; }
    .auth-card { padding: 24px 18px; border-radius: var(--radius-lg); }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<header class="navbar">
    <a href="index.php" class="brand">
        <div class="brand-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <span class="brand-title">Fintrack</span>
    </a>

    <nav class="nav-links">
        <a href="#features">Features</a>
        <a href="#calculator">Projection</a>
        <a href="#security">Security</a>
    </nav>

    <div class="nav-actions">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary">Go to Dashboard →</a>
        <?php else: ?>
            <button onclick="openAuthModal('login')" class="btn btn-ghost">Sign In</button>
            <button onclick="openAuthModal('register')" class="btn btn-primary">Get Started Free</button>
        <?php endif; ?>
    </div>
</header>

<!-- HERO -->
<section class="hero">
    <h1 class="hero-title">Master Your Money with <span>Precision & Intelligence.</span></h1>
    <p class="hero-sub">Track daily expenses, set category budget limits, monitor cash flows, and visualize financial trends in one high-performance dashboard.</p>
    
    <div class="hero-buttons">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary btn-lg">Open Your Dashboard →</a>
        <?php else: ?>
            <button onclick="openAuthModal('register')" class="btn btn-primary btn-lg">Create Free Account →</button>
            <button onclick="openAuthModal('login')" class="btn btn-ghost btn-lg" style="color:var(--text-1);border-color:var(--line);">Sign In to Account</button>
        <?php endif; ?>
    </div>

    <!-- DASHBOARD PREVIEW DEMO -->
    <div class="dashboard-preview">
        <div class="preview-grid">
            <div class="preview-card">
                <div class="preview-header">
                    <div>
                        <div class="preview-title">Total Net Worth Overview</div>
                        <div class="stat-val">KES 4,556,000.00</div>
                    </div>
                    <div class="stat-tag">+12.4% this month</div>
                </div>
                <div class="chart-box">
                    <canvas id="previewChart"></canvas>
                </div>
            </div>
            
            <div class="preview-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                    <div class="preview-title" style="margin-bottom:14px;">Recent Activity</div>
                    <div class="tx-list">
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:#ecfdf5;display:flex;align-items:center;justify-content:center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="7 17 17 7"/><polyline points="7 7 17 7 17 17"/></svg>
                                </div>
                                <div>
                                    <div class="tx-name">Web Consulting</div>
                                    <div class="tx-date">Today</div>
                                </div>
                            </div>
                            <div class="tx-amt income">+KES 15,000</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:#fff1f2;display:flex;align-items:center;justify-content:center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#E11D48" stroke-width="2.5"><polyline points="7 7 17 17"/><polyline points="17 7 17 17 7 17"/></svg>
                                </div>
                                <div>
                                    <div class="tx-name">Dining Out</div>
                                    <div class="tx-date">Yesterday</div>
                                </div>
                            </div>
                            <div class="tx-amt expense">-KES 350</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:#ecfdf5;display:flex;align-items:center;justify-content:center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="7 17 17 7"/><polyline points="7 7 17 7 17 17"/></svg>
                                </div>
                                <div>
                                    <div class="tx-name">Freelance Consulting</div>
                                    <div class="tx-date">3 days ago</div>
                                </div>
                            </div>
                            <div class="tx-amt income">+KES 1,000</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="features-section" id="features">
    <div class="section-hd">
        <div class="section-badge">Designed for Financial Success</div>
        <h2 class="section-title">Everything you need to build wealth</h2>
    </div>

    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
            </div>
            <div class="feature-h">Real-Time Analytics</div>
            <div class="feature-p">Gain instant clarity on cashflow, spending breakdown, and recurring monthly commitments.</div>
        </div>

        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div class="feature-h">Smart Budget Caps</div>
            <div class="feature-p">Define category limits with visual progress tracking and automated spending alerts.</div>
        </div>

        <div class="feature-card">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            </div>
            <div class="feature-h">Cashflow Tracking</div>
            <div class="feature-p">Monitor all income inflows and expense outflows with clean category tagging and transaction history.</div>
        </div>

        <div class="feature-card" id="security">
            <div class="feature-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div class="feature-h">Bank-Grade Protection</div>
            <div class="feature-p">Bcrypt password hashing, brute-force lockout, CSRF defense, and session security built-in.</div>
        </div>
    </div>
</section>

<!-- CALCULATOR SECTION -->
<section class="calc-section" id="calculator">
    <div class="calc-card">
        <div>
            <div class="section-badge">Wealth Growth Simulator</div>
            <h2 class="calc-title">Simulate your future savings</h2>
            <p class="calc-desc">Adjust monthly contributions to see how fast your wealth accumulates over time.</p>

            <div class="calc-input-group">
                <label class="calc-label">Monthly Deposit (KES)</label>
                <input type="range" class="calc-range" id="monthlyInput" min="1000" max="100000" step="1000" value="10000" oninput="calculateSavings()">
                <div class="calc-val-display" id="monthlyVal">KES 10,000 / mo</div>
            </div>

            <div class="calc-input-group">
                <label class="calc-label">Time Horizon (Years)</label>
                <input type="range" class="calc-range" id="yearsInput" min="1" max="10" step="1" value="3" oninput="calculateSavings()">
                <div class="calc-val-display" id="yearsVal">3 Years</div>
            </div>
        </div>

        <div class="calc-result-box">
            <div class="calc-result-lbl">Projected Savings Balance</div>
            <div class="calc-result-val" id="totalResult">KES 400,000.00</div>
            <p style="font-size:12px;color:var(--text-3);">Based on steady savings + estimated 7% compound growth.</p>
        </div>
    </div>
</section>

<!-- AUTH MODAL -->
<?php $modal_open = (!empty($login_error) || !empty($login_success) || !empty($reg_error) || !empty($forgot_error) || !empty($reset_error) || $active_auth_tab === 'reset' || !empty($_GET['auth'])); ?>
<div class="auth-overlay <?php echo $modal_open ? 'open' : ''; ?>" id="authOverlay" onclick="if(event.target===this) closeAuthModal()">
    <div class="auth-card">
        <button class="auth-close" onclick="closeAuthModal()" aria-label="Close modal">✕</button>

        <!-- LOGIN VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === '' || $active_auth_tab === 'login') ? 'active' : ''; ?>" id="viewLogin">
            <h2 style="font-family:var(--font-head);font-size:22px;font-weight:700;margin-bottom:4px;color:var(--text-1);">Welcome back</h2>
            <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">Sign in to your Fintrack account</p>

            <?php if (!empty($login_error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($login_success)): ?>
                <div id="loginSuccessBox" class="auth-success" style="background:var(--green-dim);border:1px solid rgba(5,150,105,.25);color:var(--green);padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.5;">
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
                        <a id="forgotPassLink" onclick="switchAuthTab('forgot')" class="forgot-link <?php echo !empty($login_error) ? 'shake-accent' : ''; ?>">Forgot password?</a>
                    </div>
                </div>

                <button type="submit" class="auth-submit-btn">Sign In to Dashboard →</button>
            </form>

            <div class="auth-toggle-msg">
                Don't have an account? <a onclick="switchAuthTab('register')">Register here</a>
            </div>
        </div>

        <!-- REGISTER VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab === 'register') ? 'active' : ''; ?>" id="viewRegister">
            <h2 style="font-family:var(--font-head);font-size:22px;font-weight:700;margin-bottom:4px;color:var(--text-1);">Create an account</h2>
            <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">Start your financial journey today</p>

            <?php if (!empty($reg_error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($reg_error); ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="register">
                <input type="hidden" name="active_tab" value="register">

                <div class="auth-field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required autocomplete="off" placeholder="Stephen Musyoki" maxlength="100">
                </div>

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" name="email" required autocomplete="off" placeholder="example@gmail.com">
                </div>

                <div class="auth-field">
                    <label>Create Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="regPassword" name="password" required autocomplete="new-password" placeholder="At least 8 characters" oninput="checkAuthStrength(this.value)">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('regPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="authStrengthFill"></div></div>
                    <div class="strength-lbl" id="authStrengthLbl">Enter a password</div>
                </div>

                <button type="submit" class="auth-submit-btn">Create Free Account →</button>
            </form>

            <div class="auth-toggle-msg">
                Already have an account? <a onclick="switchAuthTab('login')">Sign In here</a>
            </div>
        </div>

        <!-- FORGOT PASSWORD VIEW (Step 1: Verify Email) -->
        <div class="auth-view <?php echo ($active_auth_tab === 'forgot') ? 'active' : ''; ?>" id="viewForgot">
            <h2 style="font-family:var(--font-head);font-size:22px;font-weight:700;margin-bottom:4px;color:var(--text-1);">Forgot Password</h2>
            <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">Enter your registered email address to verify your account.</p>

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

                <button type="submit" id="forgotSubmitBtn" class="auth-submit-btn">Verify Email →</button>
            </form>

            <div class="auth-toggle-msg">
                Remembered your password? <a onclick="switchAuthTab('login')">Sign In here</a>
            </div>
        </div>

        <!-- CHANGE PASSWORD VIEW (Step 2: Set New Password) -->
        <div class="auth-view <?php echo ($active_auth_tab === 'reset') ? 'active' : ''; ?>" id="viewReset">
            <h2 style="font-family:var(--font-head);font-size:22px;font-weight:700;margin-bottom:4px;color:var(--text-1);">Change Password</h2>
            <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">
                Enter a new password for <strong id="resetEmailDisplay" style="color:var(--text-1);"><?php echo htmlspecialchars($reset_email ?? ''); ?></strong>
            </p>

            <div class="auth-error" id="resetErrorBox" style="<?php echo empty($reset_error) ? 'display:none;' : ''; ?>">
                <?php echo htmlspecialchars($reset_error ?? ''); ?>
            </div>

            <form id="resetForm" method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="change_password">
                <input type="hidden" name="active_tab" value="reset">
                <input type="hidden" name="email" id="resetEmailInput" value="<?php echo htmlspecialchars($reset_email ?? ''); ?>">

                <div class="auth-field">
                    <label>New Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="resetNewPassword" name="new_password" required autocomplete="new-password" placeholder="At least 8 characters" oninput="checkResetStrength(this.value)">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('resetNewPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="resetStrengthFill"></div></div>
                    <div class="strength-lbl" id="resetStrengthLbl">Enter a new password</div>
                </div>

                <div class="auth-field">
                    <label>Confirm New Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="resetConfirmPassword" name="confirm_password" required autocomplete="new-password" placeholder="Re-type your password">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('resetConfirmPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" id="resetSubmitBtn" class="auth-submit-btn">Change Password →</button>
            </form>

            <div class="auth-toggle-msg">
                Changed your mind? <a onclick="switchAuthTab('login')">Sign In here</a>
            </div>
        </div>
    </div>
</div>

<!-- FOOTER -->
<footer class="footer">
    <p>© <?php echo date('Y'); ?> Fintrack Personal Finance. All rights reserved.</p>
</footer>

<script>
// Chart Preview
const ctx = document.getElementById('previewChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Expense Trend',
            data: [12000, 15000, 19000, 22000, 18000, 25000],
            borderColor: '#FF6B35',
            borderWidth: 3,
            backgroundColor: 'rgba(255, 107, 53, 0.08)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#FF6B35'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(30,27,75,0.05)' }, ticks: { color: 'rgba(15,15,40,0.5)' } },
            y: { grid: { color: 'rgba(30,27,75,0.05)' }, ticks: { color: 'rgba(15,15,40,0.5)', callback: v => 'KES ' + (v/1000).toFixed(0) + 'k' } }
        }
    }
});

// Interactive Calculator
function calculateSavings() {
    const monthly = parseFloat(document.getElementById('monthlyInput').value);
    const years = parseInt(document.getElementById('yearsInput').value);
    document.getElementById('monthlyVal').textContent = 'KES ' + monthly.toLocaleString() + ' / mo';
    document.getElementById('yearsVal').textContent = years + (years === 1 ? ' Year' : ' Years');

    const months = years * 12;
    const rate = 0.07 / 12;
    let total = 0;
    for (let i = 0; i < months; i++) {
        total = (total + monthly) * (1 + rate);
    }

    document.getElementById('totalResult').textContent = 'KES ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Clean URL query on load so refresh never opens modal automatically
if (window.history.replaceState && window.location.search.includes('auth=')) {
    const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
    window.history.replaceState({path: cleanUrl}, '', cleanUrl);
}

// Modal & Auth Tab Switcher
function openAuthModal(tab = 'login') {
    switchAuthTab(tab);
    document.getElementById('authOverlay').classList.add('open');
}

function closeAuthModal() {
    document.getElementById('authOverlay').classList.remove('open');
}

function switchAuthTab(tab) {
    const viewLogin = document.getElementById('viewLogin');
    const viewRegister = document.getElementById('viewRegister');
    const viewForgot = document.getElementById('viewForgot');
    const viewReset = document.getElementById('viewReset');
    if (viewLogin) viewLogin.classList.toggle('active', tab === 'login');
    if (viewRegister) viewRegister.classList.toggle('active', tab === 'register');
    if (viewForgot) viewForgot.classList.toggle('active', tab === 'forgot');
    if (viewReset) viewReset.classList.toggle('active', tab === 'reset');
}

function checkAuthStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const fill = document.getElementById('authStrengthFill');
    const label = document.getElementById('authStrengthLbl');
    const colors = ['#E11D48','#D97706','#4F46E5','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];
    fill.style.width = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#E11D48';
    label.textContent = val.length ? (labels[score - 1] || 'Weak') : 'Enter a password';
    label.style.color = colors[score - 1] || 'var(--text-3)';
}

function checkResetStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const fill = document.getElementById('resetStrengthFill');
    const label = document.getElementById('resetStrengthLbl');
    if (!fill || !label) return;
    const colors = ['#E11D48','#D97706','#4F46E5','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];
    fill.style.width = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#E11D48';
    label.textContent = val.length ? (labels[score - 1] || 'Weak') : 'Enter a new password';
    label.style.color = colors[score - 1] || 'var(--text-3)';
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

// Handle Forgot Password submission
const forgotForm = document.getElementById('forgotForm');
if (forgotForm) {
    forgotForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const emailInput = document.getElementById('forgotEmailInput');
        const errBox = document.getElementById('forgotErrorBox');
        if (errBox) errBox.style.display = 'none';

        const email = emailInput ? emailInput.value.trim() : '';
        if (!email) {
            if (errBox) {
                errBox.textContent = 'Please enter your registered email address.';
                errBox.style.display = 'block';
            }
            return;
        }

        const submitBtn = document.getElementById('forgotSubmitBtn');
        const origText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Checking...';
        }

        const formData = new FormData(forgotForm);
        formData.append('ajax', '1');

        fetch('index.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
            if (data.new_csrf) {
                document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = data.new_csrf);
            }
            if (data.status === 'success') {
                const resetInput = document.getElementById('resetEmailInput');
                const resetDisplay = document.getElementById('resetEmailDisplay');
                if (resetInput) resetInput.value = data.email;
                if (resetDisplay) resetDisplay.textContent = data.email;
                const resetErr = document.getElementById('resetErrorBox');
                if (resetErr) resetErr.style.display = 'none';
                switchAuthTab('reset');
            } else {
                if (errBox) {
                    errBox.textContent = data.message || 'Invalid credentials';
                    errBox.style.display = 'block';
                }
            }
        })
        .catch(err => {
            forgotForm.submit();
        });
    });
}

// Handle Reset Password submission
const resetForm = document.getElementById('resetForm');
if (resetForm) {
    resetForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const errBox = document.getElementById('resetErrorBox');
        if (errBox) errBox.style.display = 'none';

        const newPwd = document.getElementById('resetNewPassword').value.trim();
        const confirmPwd = document.getElementById('resetConfirmPassword').value.trim();

        if (newPwd.length < 8) {
            if (errBox) {
                errBox.textContent = "Password needs at least 8 characters.";
                errBox.style.display = 'block';
            }
            return;
        }
        if (!/[0-9]/.test(newPwd)) {
            if (errBox) {
                errBox.textContent = "Password needs at least one number.";
                errBox.style.display = 'block';
            }
            return;
        }
        if (!/[A-Z]/.test(newPwd)) {
            if (errBox) {
                errBox.textContent = "Password needs at least one uppercase letter.";
                errBox.style.display = 'block';
            }
            return;
        }
        if (!/[a-z]/.test(newPwd)) {
            if (errBox) {
                errBox.textContent = "Password needs at least one lowercase letter.";
                errBox.style.display = 'block';
            }
            return;
        }
        if (newPwd !== confirmPwd) {
            if (errBox) {
                errBox.textContent = "Passwords do not match.";
                errBox.style.display = 'block';
            }
            return;
        }

        const submitBtn = document.getElementById('resetSubmitBtn');
        const origText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Updating...';
        }

        const formData = new FormData(resetForm);
        formData.append('ajax', '1');

        fetch('index.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
            if (data.new_csrf) {
                document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = data.new_csrf);
            }
            if (data.status === 'success') {
                let loginSuccess = document.getElementById('loginSuccessBox');
                if (!loginSuccess) {
                    loginSuccess = document.createElement('div');
                    loginSuccess.id = 'loginSuccessBox';
                    loginSuccess.className = 'auth-success';
                    loginSuccess.style.cssText = 'background:var(--green-dim);border:1px solid rgba(5,150,105,.25);color:var(--green);padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.5;';
                    const loginForm = document.querySelector('#viewLogin form');
                    if (loginForm) loginForm.parentNode.insertBefore(loginSuccess, loginForm);
                }
                loginSuccess.textContent = data.message || "Password updated successfully. Please sign in with your new password.";
                loginSuccess.style.display = 'block';

                const loginEmailInput = document.querySelector('#viewLogin input[name="email"]');
                if (loginEmailInput && data.email) {
                    loginEmailInput.value = data.email;
                }
                switchAuthTab('login');
            } else {
                if (errBox) {
                    errBox.textContent = data.message || "Failed to update password.";
                    errBox.style.display = 'block';
                }
            }
        })
        .catch(err => {
            resetForm.submit();
        });
    });
}
</script>
</body>
</html>
