<?php
// ============================================================
// verify_otp.php — 2FA Email OTP verification (COMPLETE REWRITE)
// ============================================================
session_start();
require_once 'db.php';
require_once 'mailer.php';
require_once 'audit.php';

// Must come from login flow
if (empty($_SESSION['2fa_pending_user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['2fa_pending_user_id'];
$error   = '';
$success = '';

// ── RESEND OTP ──────────────────────────────────────────────
if (isset($_GET['resend'])) {
    $last = $_SESSION['otp_last_sent'] ?? 0;
    if ((time() - $last) < 60) {
        $error = 'Please wait before requesting another code.';
    } else {
        $otp_plain  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash   = password_hash($otp_plain, PASSWORD_BCRYPT);
        $otp_expiry = date('Y-m-d H:i:s', time() + 600);

        $stmt = $conn->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE user_id=?");
        $stmt->bind_param("ssi", $otp_hash, $otp_expiry, $user_id);
        $stmt->execute();

        $u = $conn->prepare("SELECT email, full_name FROM users WHERE user_id=?");
        $u->bind_param("i", $user_id);
        $u->execute();
        $urow = $u->get_result()->fetch_assoc();

        $html = Mailer::otpEmail($urow['full_name'], $otp_plain);
        Mailer::send($urow['email'], 'Your Royal Matrix Login Code', $html);

        $_SESSION['otp_last_sent'] = time();
        $success = 'A new code was sent to your email.';
    }
}

// ── VERIFY OTP ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_input = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $otp_input)) {
        $error = 'Enter the 6-digit code from your email.';
    } else {
        $stmt = $conn->prepare("SELECT otp_code, otp_expiry, full_name, email FROM users WHERE user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || !$row['otp_code']) {
            $error = 'No verification code found. Please log in again.';
        } elseif (strtotime($row['otp_expiry']) < time()) {
            $error = 'This code has expired. <a href="verify_otp.php?resend=1">Send a new code</a>.';
        } elseif (!password_verify($otp_input, $row['otp_code'])) {
            $_SESSION['otp_fails'] = ($_SESSION['otp_fails'] ?? 0) + 1;
            if ($_SESSION['otp_fails'] >= 5) {
                session_unset(); session_destroy();
                header('Location: login.php?otp_lockout=1');
                exit();
            }
            $remaining = 5 - $_SESSION['otp_fails'];
            $error = "Incorrect code. {$remaining} attempt(s) remaining.";
        } else {
            // SUCCESS
            $stmt2 = $conn->prepare("UPDATE users SET otp_code=NULL, otp_expiry=NULL WHERE user_id=?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();

            session_regenerate_id(true);
            $_SESSION['user_id']       = $user_id;
            $_SESSION['name']          = $row['full_name'];
            $_SESSION['last_activity'] = time();
            $_SESSION['fingerprint']   = hash('sha256',
                ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['otp_fails'], $_SESSION['otp_last_sent']);

            Audit::log($conn, $user_id, 'auth.2fa_verified', 'user', $user_id, null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

            header('Location: dashboard.php');
            exit();
        }
    }
}

// Mask email
$u2 = $conn->prepare("SELECT email FROM users WHERE user_id=?");
$u2->bind_param("i", $user_id);
$u2->execute();
$masked_email = '';
if ($urow2 = $u2->get_result()->fetch_assoc()) {
    [$local, $domain] = explode('@', $urow2['email']);
    $masked_email = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
}

$can_resend = (time() - ($_SESSION['otp_last_sent'] ?? 0)) >= 60;
$resend_wait = max(0, 60 - (time() - ($_SESSION['otp_last_sent'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login | Royal Matrix</title>
    <style>
        :root{--purple:#7c3aed;--cyan:#06b6d4;--gold:#fbbf24}
        body{font-family:'Inter',-apple-system,sans-serif;height:100vh;display:flex;
             align-items:center;justify-content:center;margin:0;color:#fff;
             background:linear-gradient(-45deg,#020617,#0a0f1e,#1e1b4b,#0c4a6e);
             background-size:400% 400%;animation:gs 10s ease infinite}
        @keyframes gs{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
        .box{width:90%;max-width:420px;padding:44px 40px;background:rgba(255,255,255,.04);
             backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.1);border-radius:24px;
             text-align:center;box-shadow:0 25px 60px rgba(0,0,0,.5)}
        .icon{font-size:42px;margin-bottom:12px}
        h2{font-size:26px;font-weight:800;margin:0 0 8px}
        .sub{color:#94a3b8;font-size:14px;margin-bottom:28px;line-height:1.6}
        .sub strong{color:#e2e8f0}
        .otp-row{display:flex;gap:10px;justify-content:center;margin-bottom:22px}
        .otp-row input{width:50px;height:58px;text-align:center;font-size:22px;font-weight:700;
            background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:12px;
            color:#fff;outline:none;transition:.25s;-moz-appearance:textfield}
        .otp-row input::-webkit-outer-spin-button,.otp-row input::-webkit-inner-spin-button{-webkit-appearance:none}
        .otp-row input:focus{border-color:var(--cyan);box-shadow:0 0 14px rgba(6,182,212,.3)}
        .otp-row input.filled{border-color:var(--purple)}
        .btn{width:100%;padding:15px;background:linear-gradient(135deg,var(--purple),var(--cyan));
             border:none;border-radius:12px;color:#fff;font-weight:700;font-size:15px;
             cursor:pointer;letter-spacing:.5px;transition:.25s;margin-top:4px}
        .btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(124,58,237,.4)}
        .error{color:#fca5a5;font-size:13px;margin-bottom:14px;padding:10px 14px;
               background:rgba(239,68,68,.1);border-radius:8px;border:1px solid rgba(239,68,68,.2);text-align:left}
        .error a{color:#fbbf24}
        .success{color:#86efac;font-size:13px;margin-bottom:14px;padding:10px 14px;
                 background:rgba(34,197,94,.1);border-radius:8px;border:1px solid rgba(34,197,94,.2)}
        .resend{margin-top:20px;font-size:13px;color:#64748b}
        .resend a{color:var(--gold);font-weight:700;text-decoration:none}
        .back{display:block;margin-top:14px;font-size:13px;color:#475569;text-decoration:none}
        .back:hover{color:#94a3b8}
        .timer{color:#475569}
    </style>
</head>
<body>
<div class="box">
    <div class="icon">🔐</div>
    <h2>Two-Factor Authentication</h2>
    <p class="sub">We sent a 6-digit code to<br><strong><?= htmlspecialchars($masked_email) ?></strong><br>
    Code expires in 10 minutes.</p>

    <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST" id="otpForm" autocomplete="off">
        <div class="otp-row">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <input type="number" id="d<?= $i ?>" min="0" max="9" inputmode="numeric">
            <?php endfor; ?>
        </div>
        <input type="hidden" name="otp" id="otpHidden">
        <button type="submit" class="btn" id="submitBtn">Verify &amp; Continue →</button>
    </form>

    <div class="resend" id="resendArea">
        <?php if ($can_resend): ?>
            Didn't get the code? <a href="verify_otp.php?resend=1">Resend Code</a>
        <?php else: ?>
            <span class="timer">Resend available in <b id="countdown"><?= $resend_wait ?></b>s</span>
        <?php endif; ?>
    </div>
    <a href="login.php" class="back">← Back to login</a>
</div>

<script>
const digits = [...document.querySelectorAll('.otp-row input')];

digits.forEach((inp, i) => {
    inp.addEventListener('input', e => {
        const v = String(e.target.value).replace(/\D/g,'').slice(-1);
        e.target.value = v;
        if (v) { e.target.classList.add('filled'); if (i < 5) digits[i+1].focus(); }
        else { e.target.classList.remove('filled'); }
    });
    inp.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !e.target.value && i > 0) {
            digits[i-1].value = '';
            digits[i-1].classList.remove('filled');
            digits[i-1].focus();
        }
    });
    inp.addEventListener('paste', e => {
        e.preventDefault();
        const paste = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
        paste.split('').slice(0,6).forEach((ch,j) => {
            if (digits[j]) { digits[j].value = ch; digits[j].classList.add('filled'); }
        });
        digits[Math.min(paste.length, 5)].focus();
    });
});

document.getElementById('otpForm').addEventListener('submit', e => {
    const code = digits.map(d => d.value).join('');
    document.getElementById('otpHidden').value = code;
    if (code.length < 6) { e.preventDefault(); }
});

digits[0].focus();

// Countdown timer
<?php if (!$can_resend): ?>
let secs = <?= $resend_wait ?>;
const cd = document.getElementById('countdown');
if (cd) {
    const t = setInterval(() => {
        cd.textContent = --secs;
        if (secs <= 0) {
            clearInterval(t);
            document.getElementById('resendArea').innerHTML =
                'Didn\'t get the code? <a href="verify_otp.php?resend=1" style="color:#fbbf24;font-weight:700;text-decoration:none;">Resend Code</a>';
        }
    }, 1000);
}
<?php endif; ?>
</script>
</body>
</html>
