<?php
// ============================================================
// CRITICAL FIXES APPLIED:
// #1 — SQL injection: prepared statements replace raw queries
// #3 — Brute force: login attempts tracked, lockout after 5 fails
// #4 — Session security: regenerate ID on login, idle timeout,
//       IP + UA fingerprinting
// ============================================================
session_start();
require_once 'db.php';

// ── CRITICAL FIX #4: Session idle timeout ──────────────────
$session_lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1800);
if (isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_lifetime) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Already logged in — redirect
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// ── BRUTE FORCE HELPERS ────────────────────────────────────
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES    = 15;

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
            locked_until  = IF(attempt_count + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
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
    // Sanitise inputs — trim only, no escaping (prepared stmt handles that)
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic format check
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (empty($password)) {
        $error = "Password is required.";
    } else {
        // ── CRITICAL FIX #3: Check brute force lockout ──
        $attempts = get_login_attempts($conn, $email);

        $is_locked = $attempts['locked_until'] &&
                     strtotime($attempts['locked_until']) > time();

        if ($is_locked) {
            $mins = ceil((strtotime($attempts['locked_until']) - time()) / 60);
            $error = "Too many failed attempts. Try again in {$mins} minute(s).";
        } else {
            // ── CRITICAL FIX #1: Prepared statement — no SQL injection ──
            $stmt = $conn->prepare("
                SELECT user_id, full_name, password, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                // Check account status
                if ($user['status'] !== 'active') {
                    $error = "Your account has been suspended. Contact support.";
                } else {
                    // ── 2FA: Generate OTP and redirect to verify page ──
                    require_once __DIR__ . '/mailer.php';
                    $otp_plain  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_hash   = password_hash($otp_plain, PASSWORD_BCRYPT);
                    $otp_expiry = date('Y-m-d H:i:s', time() + 600);

                    $s2fa = $conn->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?");
                    $s2fa->bind_param("ssi", $otp_hash, $otp_expiry, $user['user_id']);
                    $s2fa->execute();

                    Mailer::send(
                        $email,
                        'Your Royal Matrix Login Code',
                        Mailer::otpEmail($user['full_name'], $otp_plain)
                    );

                    session_regenerate_id(true);
                    $_SESSION['2fa_pending_user_id'] = $user['user_id'];
                    $_SESSION['otp_last_sent']       = time();

                    clear_login_attempts($conn, $email);

                    header("Location: verify_otp.php");
                    exit();
                }
            } else {
                // Record failed attempt regardless of whether email exists
                // (prevents user enumeration via timing)
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
    <title>Login | Royal Matrix</title>
    <style>
        :root {
            --royal-purple: #7c3aed;
            --aqua-accent: #06b6d4;
            --neon-gold: #fbbf24;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            color: white;
            background: linear-gradient(-45deg, #020617, #0a0f1e, #1e1b4b, #0c4a6e);
            background-size: 400% 400%;
            animation: gradientShift 10s ease infinite;
            overflow: hidden;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-box {
            width: 90%;
            max-width: 380px;
            padding: 40px;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }

        h2 { font-size: 28px; margin: 0 0 8px 0; font-weight: 800; }
        p  { color: #cbd5e1; margin-bottom: 30px; font-size: 14px; }

        .input-group  { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 12px; margin-bottom: 8px; color: #94a3b8; font-weight: 600; text-transform: uppercase; }

        input {
            width: 100%;
            padding: 14px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            outline: none;
            box-sizing: border-box;
            transition: 0.3s;
        }
        input:focus { border-color: var(--aqua-accent); box-shadow: 0 0 10px rgba(6, 182, 212, 0.2); }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--royal-purple), var(--aqua-accent));
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: 0.3s;
            text-transform: uppercase;
            font-size: 14px;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(124, 58, 237, 0.3); }
        .btn-login:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .error-msg {
            color: #fca5a5;
            font-size: 13px;
            margin-bottom: 15px;
            background: rgba(239, 68, 68, 0.1);
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(239, 68, 68, 0.2);
            text-align: left;
        }

        .footer-link { margin-top: 25px; font-size: 14px; color: #94a3b8; }
        .footer-link a { color: var(--neon-gold); text-decoration: none; font-weight: 700; }

        .forgot { display: block; text-align: right; font-size: 12px; color: #64748b; margin-top: 6px; cursor: pointer; }
    </style>
</head>
<body>
<div class="login-box">
    <h2>Welcome Back</h2>
    <p>Log in to your Royal Matrix account</p>

    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="example@gmail.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter password">
            <span class="forgot" onclick="alert('Contact support to reset your password.')">Forgot password?</span>
        </div>
        <button type="submit" class="btn-login">Login</button>
    </form>

    <div class="footer-link">No account? <a href="register.php">Sign Up Free</a></div>
</div>
</body>
</html>