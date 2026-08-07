<?php
session_start();
require_once __DIR__ . '/core/db.php';
if (!function_exists('csrf_token')) { require_once __DIR__ . '/core/csrf.php'; }

$is_logged_in = isset($_SESSION['user_id']);
if ($is_logged_in && isset($_GET['auth'])) {
    header("Location: pages/dashboard.php");
    exit();
}

$login_error = "";
$reg_error   = "";
$active_auth_tab = $_GET['auth'] ?? ($_POST['active_tab'] ?? '');

// ── BRUTE FORCE HELPERS ────────────────────────────────────
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

function get_login_attempts(mysqli $conn, string $email): array {
    $stmt = $conn->prepare("SELECT attempt_count, locked_until FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: ['attempt_count' => 0, 'locked_until' => null];
}

function record_failed_attempt(mysqli $conn, string $email): void {
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
}

function clear_login_attempts(mysqli $conn, string $email): void {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

// ── PROCESS AUTH ACTIONS ──────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['auth_action'])) {
    csrf_verify();
    $auth_action = $_POST['auth_action'];

    if ($auth_action === 'login') {
        $active_auth_tab = 'login';
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login_error = "Invalid email address.";
        } elseif (empty($password)) {
            $login_error = "Password is required.";
        } else {
            $attempts  = get_login_attempts($conn, $email);
            $is_locked = $attempts['locked_until'] && strtotime($attempts['locked_until']) > time();

            if ($is_locked) {
                $mins  = ceil((strtotime($attempts['locked_until']) - time()) / 60);
                $login_error = "Too many failed attempts. Try again in {$mins} minute(s).";
            } else {
                $stmt = $conn->prepare("SELECT user_id, full_name, name, password, role, status FROM users WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();

                if ($user && password_verify($password, $user['password'])) {
                    if ($user['status'] !== 'active') {
                        $login_error = "Your account has been suspended. Contact support.";
                    } else {
                        $_SESSION['user_id']       = (int)$user['user_id'];
                        $_SESSION['name']          = !empty($user['full_name']) ? $user['full_name'] : $user['name'];
                        $_SESSION['email']         = $email;
                        $_SESSION['role']          = $user['role'] ?? 'user';
                        $_SESSION['last_activity'] = time();
                        $_SESSION['fingerprint']   = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                        session_regenerate_id(false);
                        session_write_close();
                        clear_login_attempts($conn, $email);

                        header("Location: pages/dashboard.php");
                        exit();
                    }
                } else {
                    record_failed_attempt($conn, $email);
                    $remaining = MAX_LOGIN_ATTEMPTS - ($attempts['attempt_count'] + 1);
                    if ($remaining > 0) {
                        $login_error = "Wrong email or password. {$remaining} attempt(s) remaining.";
                    } else {
                        $login_error = "Account locked for " . LOCKOUT_MINUTES . " minutes due to too many failed attempts.";
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
            $reg_error = "Name must be under 100 characters.";
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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fintrack — Personal Finance Management & Wealth Tracker</title>
<meta name="description" content="Take total control of your money with Fintrack. Track transactions, automate budget limits, monitor savings goals, and visualize analytics in real-time.">
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

/* ── CTA BANNER ── */
.cta-banner {
    background: linear-gradient(135deg, #1E1B4B, #16134A);
    border-radius: var(--radius-xl);
    padding: 56px 40px;
    max-width: 1040px;
    margin: 0 auto 80px;
    text-align: center;
    box-shadow: var(--shadow-lg);
}

.cta-h { font-family: var(--font-head); font-size: 32px; font-weight: 800; color: #fff; margin-bottom: 10px; }
.cta-p { font-size: 15px; color: rgba(255,255,255,.7); max-width: 520px; margin: 0 auto 26px; }

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
    margin-top: 18px;
}

.auth-toggle-msg a {
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.auth-toggle-msg a:hover { text-decoration: underline; }

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
    .features-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: 1fr; }
    .calc-card { grid-template-columns: 1fr; gap: 20px; padding: 24px; }
}

@media (max-width: 600px) {
    .features-grid { grid-template-columns: 1fr; }
    .hero-buttons { flex-direction: column; }
    .btn { width: 100%; justify-content: center; }
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
    <p class="hero-sub">Track daily expenses, set budget limits, automate savings goals, and visualize net worth progress in one high-performance dashboard.</p>
    
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
                                <div class="tx-icon">💼</div>
                                <div>
                                    <div class="tx-name">Web Consulting</div>
                                    <div class="tx-date">Today</div>
                                </div>
                            </div>
                            <div class="tx-amt income">+KES 15,000</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:var(--red-dim);color:var(--red);">☕</div>
                                <div>
                                    <div class="tx-name">Dining Out</div>
                                    <div class="tx-date">Yesterday</div>
                                </div>
                            </div>
                            <div class="tx-amt expense">-KES 350</div>
                        </div>
                        <div class="tx-item">
                            <div class="tx-left">
                                <div class="tx-icon" style="background:var(--cyan-dim);color:var(--cyan);">🏠</div>
                                <div>
                                    <div class="tx-name">Savings Goal</div>
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
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9c0 4-4 4-4 4H7v5H3V9a6 6 0 0 1 12 0h2z"/><circle cx="21" cy="14" r="3"/></svg>
            </div>
            <div class="feature-h">Savings Milestones</div>
            <div class="feature-p">Set custom targets for home decor, emergency funds, and vacation plans with easy deposits.</div>
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

<!-- CTA BANNER -->
<section class="cta-banner">
    <h2 class="cta-h">Ready to transform your finances?</h2>
    <p class="cta-p">Join Fintrack today and experience complete financial clarity and control.</p>
    <div style="display:flex;justify-content:center;gap:16px;">
        <?php if ($is_logged_in): ?>
            <a href="pages/dashboard.php" class="btn btn-primary btn-lg">Go to Dashboard →</a>
        <?php else: ?>
            <button onclick="openAuthModal('register')" class="btn btn-primary btn-lg">Create Free Account →</button>
            <button onclick="openAuthModal('login')" class="btn btn-ghost btn-lg">Sign In</button>
        <?php endif; ?>
    </div>
</section>

<!-- AUTH MODAL -->
<?php $modal_open = (!empty($login_error) || !empty($reg_error) || !empty($_GET['auth'])); ?>
<div class="auth-overlay <?php echo $modal_open ? 'open' : ''; ?>" id="authOverlay" onclick="if(event.target===this) closeAuthModal()">
    <div class="auth-card">
        <button class="auth-close" onclick="closeAuthModal()" aria-label="Close modal">✕</button>

        <div class="auth-switcher">
            <button class="auth-tab-btn <?php echo ($active_auth_tab !== 'register') ? 'active' : ''; ?>" id="tabBtnLogin" onclick="switchAuthTab('login')">Sign In</button>
            <button class="auth-tab-btn <?php echo ($active_auth_tab === 'register') ? 'active' : ''; ?>" id="tabBtnRegister" onclick="switchAuthTab('register')">Register</button>
        </div>

        <!-- LOGIN VIEW -->
        <div class="auth-view <?php echo ($active_auth_tab !== 'register') ? 'active' : ''; ?>" id="viewLogin">
            <h2 style="font-family:var(--font-head);font-size:22px;font-weight:700;margin-bottom:4px;color:var(--text-1);">Welcome back</h2>
            <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">Sign in to your Fintrack account</p>

            <?php if (!empty($login_error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <form method="POST" action="index.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="auth_action" value="login">
                <input type="hidden" name="active_tab" value="login">

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" name="email" required autocomplete="off" placeholder="name@example.com">
                </div>

                <div class="auth-field">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="••••••••">
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
                    <input type="text" name="full_name" required autocomplete="off" placeholder="John Doe" maxlength="100">
                </div>

                <div class="auth-field">
                    <label>Email address</label>
                    <input type="email" name="email" required autocomplete="off" placeholder="example@gmail.com">
                </div>

                <div class="auth-field">
                    <label>Create Password</label>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="At least 8 characters" oninput="checkAuthStrength(this.value)">
                    <div class="strength-bar"><div class="strength-fill" id="authStrengthFill"></div></div>
                    <div class="strength-lbl" id="authStrengthLbl">Enter a password</div>
                </div>

                <button type="submit" class="auth-submit-btn">Create Free Account →</button>
            </form>

            <div class="auth-toggle-msg">
                Already have an account? <a onclick="switchAuthTab('login')">Sign In here</a>
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

// Modal & Auth Tab Switcher
function openAuthModal(tab = 'login') {
    switchAuthTab(tab);
    document.getElementById('authOverlay').classList.add('open');
}

function closeAuthModal() {
    document.getElementById('authOverlay').classList.remove('open');
}

function switchAuthTab(tab) {
    const isReg = tab === 'register';
    document.getElementById('viewLogin').classList.toggle('active', !isReg);
    document.getElementById('viewRegister').classList.toggle('active', isReg);
    document.getElementById('tabBtnLogin').classList.toggle('active', !isReg);
    document.getElementById('tabBtnRegister').classList.toggle('active', isReg);
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
</script>
</body>
</html>
