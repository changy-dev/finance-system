<?php
// ============================================================
// CRITICAL FIXES APPLIED:
// #1 — SQL injection: prepared statements replace raw queries
// #3 — Brute force: login attempts tracked, lockout after 5 fails
// #4 — Session security: regenerate ID on login, idle timeout,
//       browser fingerprinting (stable version)
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('../core/db.php');

// Already logged in — redirect
if (isset($_SESSION['user_id'])) {
    header("Location: ../pages/dashboard.php");
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    return $row ?: ['attempt_count' => 0, 'locked_until' => null];
}

function record_failed_attempt(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("
        INSERT INTO login_attempts (email, attempt_count, last_attempt)
        VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            attempt_count = attempt_count + 1,
            last_attempt  = NOW(),
            locked_until  = IF(
                attempt_count + 1 >= ?,
                DATE_ADD(NOW(), INTERVAL ? MINUTE),
                locked_until
            )
    ");
    $max  = MAX_LOGIN_ATTEMPTS;
    $lock = LOCKOUT_MINUTES;
    $stmt->bind_param("sii", $email, $max, $lock);
    $stmt->execute();
}

function clear_login_attempts(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

// ── PROCESS LOGIN ──────────────────────────────────────────
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";

    } elseif (empty($password)) {
        $error = "Password is required.";

    } else {
        $attempts  = get_login_attempts($conn, $email);
        $is_locked = $attempts['locked_until'] &&
                     strtotime($attempts['locked_until']) > time();

        if ($is_locked) {
            $mins  = ceil((strtotime($attempts['locked_until']) - time()) / 60);
            $error = "Too many failed attempts. Try again in {$mins} minute(s).";

        } else {
            $stmt = $conn->prepare("
                SELECT user_id, full_name, password, role, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {

                if ($user['status'] !== 'active') {
                    $error = "Your account has been suspended. Contact support.";

                } else {
                    // Write session data THEN regenerate ID
                    $_SESSION['user_id']      = (int)$user['user_id'];
                    $_SESSION['name']         = $user['full_name'];
                    $_SESSION['role']         = $user['role'] ?? 'user';
                    $_SESSION['last_activity'] = time();
                    $_SESSION['fingerprint']  = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                    session_regenerate_id(false);
                    session_write_close();

                    clear_login_attempts($conn, $email);

                    header("Location: ../pages/dashboard.php");
                    exit();
                }

            } else {
                record_failed_attempt($conn, $email);

                $remaining = MAX_LOGIN_ATTEMPTS - ($attempts['attempt_count'] + 1);

                if ($remaining > 0) {
                    $error = "Wrong email or password. {$remaining} attempt(s) remaining.";
                } else {
                    $error = "Account locked for " . LOCKOUT_MINUTES . " minutes due to too many failed attempts.";
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
    <title>Login — Fintrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --page:       #F7F6F3;
            --surface:    #FFFFFF;
            --surface-2:  #F2F0EC;
            --surface-3:  #EAE8E2;
            --ink:        #1E1B4B;
            --line:       rgba(30,27,75,.1);
            --line-2:     rgba(30,27,75,.05);

            --accent:      #FF6B35;
            --accent-2:    #E8356D;
            --accent-grad: linear-gradient(135deg,#FF6B35,#E8356D);
            --accent-lt:   rgba(255,107,53,.12);

            --cyan:        #4F46E5;
            --cyan-dim:    rgba(79,70,229,.1);
            --violet:      #7C3AED;

            --green:       #059669;
            --green-dim:   rgba(5,150,105,.1);
            --red:         #E11D48;
            --red-dim:     rgba(225,29,72,.1);
            --amber:       #D97706;
            --amber-dim:   rgba(217,119,6,.1);

            --font:        'Plus Jakarta Sans', sans-serif;
            --font-head:   'Space Grotesk', sans-serif;
            --font-mono:   'Space Mono', monospace;
            --text-1:      #0F0F28;
            --text-2:      rgba(15,15,40,.55);
            --text-3:      rgba(15,15,40,.35);

            --r:    10px;
            --r-lg: 16px;
            --r-xl: 22px;
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
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            position: relative;
            overflow-x: hidden;
        }

        /* Light mesh background */
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

        .login-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-lg);
            padding: 2.75rem 2.25rem;
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            animation: cardIn .45s cubic-bezier(.4,0,.2,1);
        }

        @keyframes cardIn { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .login-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.75rem;
        }

        .login-logo-icon {
            width: 38px; height: 38px; border-radius: 11px;
            background: var(--accent-grad);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(255,107,53,.4);
            flex-shrink: 0;
        }

        .login-logo-icon svg { width: 20px; height: 20px; color: #fff; }
        .login-logo-name { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text-1); letter-spacing: -.3px; }
        .login-logo-tag { font-size: 11px; color: var(--text-3); margin-top: 1px; }

        h1 {
            font-family: var(--font-head);
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            letter-spacing: -.3px;
            color: var(--text-1);
        }

        .subtitle {
            color: var(--text-2);
            font-size: 0.875rem;
            margin-bottom: 1.75rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-2);
            margin-bottom: 6px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            border-radius: var(--r);
            font-size: 0.9375rem;
            color: var(--text-1);
            background: var(--surface-2);
            font-family: var(--font);
            transition: border-color 0.18s, box-shadow 0.18s, background .18s;
            outline: none;
        }

        input[type="email"]:hover,
        input[type="password"]:hover {
            border-color: rgba(30,27,75,.2);
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,107,53,.1);
            background: var(--surface);
        }

        .error-box {
            background: var(--red-dim);
            border: 1px solid rgba(225,29,72,.25);
            color: var(--red);
            border-radius: var(--r);
            padding: 0.7rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .success-box {
            background: var(--green-dim);
            border: 1px solid rgba(5,150,105,.25);
            color: var(--green);
            border-radius: var(--r);
            padding: 0.7rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
        }

        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: var(--accent-grad);
            color: #fff;
            border: none;
            border-radius: var(--r);
            font-size: 0.9375rem;
            font-weight: 700;
            font-family: var(--font);
            cursor: pointer;
            transition: all 0.18s;
            margin-top: 0.5rem;
            box-shadow: 0 4px 16px rgba(255,107,53,.3);
            letter-spacing: -.01em;
        }

        button[type="submit"]:hover {
            filter: brightness(1.07);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(255,107,53,.4);
        }

        button[type="submit"]:active {
            transform: translateY(0);
        }

        .footer-link {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.875rem;
            color: var(--text-2);
        }

        .footer-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 700;
            transition: opacity .15s;
        }
        .footer-link a:hover { opacity: .75; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                          d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <div class="login-logo-name">Fintrack</div>
                <div class="login-logo-tag">Personal Finance</div>
            </div>
        </div>

        <h1>Welcome back</h1>
        <p class="subtitle">Sign in to your Fintrack account</p>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" autocomplete="off" novalidate>
            <div class="form-group">
                <label for="email">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value=""
                    required
                    autocomplete="off"
                >
                <div class="field-error" id="emailError" style="display:none;color:var(--red);font-size:12px;margin-top:5px;"></div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                >
                <div class="field-error" id="passwordError" style="display:none;color:var(--red);font-size:12px;margin-top:5px;"></div>
            </div>

            <button type="submit">Sign in</button>
        </form>

        <p class="footer-link">
            Don't have an account? <a href="../auth/register.php">Register</a>
        </p>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function (e) {
            const email    = document.getElementById('email');
            const password = document.getElementById('password');
            const emailErr = document.getElementById('emailError');
            const passErr  = document.getElementById('passwordError');
            let valid = true;

            emailErr.style.display = 'none';
            passErr.style.display  = 'none';

            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email.value.trim())) {
                emailErr.textContent = 'Please enter a valid email address.';
                emailErr.style.display = 'block';
                valid = false;
            }
            if (!password.value) {
                passErr.textContent = 'Password is required.';
                passErr.style.display = 'block';
                valid = false;
            }
            if (!valid) e.preventDefault();
        });
    </script>
</body>
</html>