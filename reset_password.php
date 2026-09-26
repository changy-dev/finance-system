<?php
session_start();
require_once __DIR__ . '/core/db.php';
if (!function_exists('csrf_token')) { require_once __DIR__ . '/core/csrf.php'; }

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error_message = '';
$success_message = '';
$is_token_valid = false;
$email = '';

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $reset_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($reset_row) {
        if (strtotime($reset_row['expires_at']) >= time()) {
            $is_token_valid = true;
            $email = $reset_row['email'];
        } else {
            $error_message = "This password reset token has expired. Please request a new one.";
        }
    } else {
        $error_message = "Invalid or expired password reset link.";
    }
} else {
    $error_message = "No reset token provided. Please request a password reset from the sign-in page.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_token_valid) {
    csrf_verify();
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 8) {
        $error_message = "Password needs at least 8 characters.";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error_message = "Password needs at least one number.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error_message = "Password needs at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $error_message = "Password needs at least one lowercase letter.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $up_stmt = $conn->prepare("UPDATE users SET password = ? WHERE LOWER(email) = ?");
        $email_lc = mb_strtolower($email);
        $up_stmt->bind_param("ss", $hashed_password, $email_lc);

        if ($up_stmt->execute()) {
            $up_stmt->close();
            
            // Delete used token
            $del_stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
            $del_stmt->bind_param("s", $token);
            $del_stmt->execute();
            $del_stmt->close();

            $success_message = "Your password has been successfully updated! You can now sign in with your new password.";
            $is_token_valid = false; // Prevents re-submitting
        } else {
            $up_stmt->close();
            $error_message = "Failed to update password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Your Password — Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --page:       #F7F6F3;
    --surface:    #FFFFFF;
    --surface-2:  #F2F0EC;
    --surface-3:  #EAE8E2;
    --text-1:      #0F0F28;
    --text-2:      rgba(15,15,40,.55);
    --text-3:      rgba(15,15,40,.35);
    --accent:      #FF6B35;
    --accent-2:    #E8356D;
    --accent-grad: linear-gradient(135deg, #FF6B35, #E8356D);
    --accent-glow: 0 4px 20px rgba(255,107,53,.3);
    --green:       #059669;
    --green-dim:   rgba(5,150,105,.1);
    --red:         #E11D48;
    --red-dim:     rgba(225,29,72,.1);
    --line:        rgba(30,27,75,.1);
    --radius-xl:   22px;
    --shadow-lg:   0 12px 48px rgba(15,15,40,.15);
    --font:        'Plus Jakarta Sans', sans-serif;
    --font-head:   'Space Grotesk', sans-serif;
}

body {
    font-family: var(--font);
    background: var(--page);
    color: var(--text-1);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
    overflow-x: hidden;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(255,107,53,.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(79,70,229,.06) 0%, transparent 55%);
    pointer-events: none;
}

.reset-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-xl);
    width: 100%;
    max-width: 440px;
    padding: 36px 30px;
    box-shadow: var(--shadow-lg);
    position: relative;
    z-index: 1;
}

.brand-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    text-decoration: none;
}

.brand-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;
    background: var(--accent-grad);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(255,107,53,.4);
}

.brand-icon svg { width: 22px; height: 22px; color: #fff; }

.brand-title {
    font-family: var(--font-head);
    font-size: 22px;
    font-weight: 700;
    color: var(--text-1);
}

.card-title {
    font-family: var(--font-head);
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-1);
}

.card-sub {
    font-size: 13.5px;
    color: var(--text-2);
    margin-bottom: 22px;
    line-height: 1.5;
}

.alert-box {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13.5px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.alert-error {
    background: var(--red-dim);
    border: 1px solid rgba(225,29,72,.25);
    color: var(--red);
}

.alert-success {
    background: var(--green-dim);
    border: 1px solid rgba(5,150,105,.25);
    color: var(--green);
}

.form-field { margin-bottom: 18px; text-align: left; }
.form-field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-2); margin-bottom: 6px; }
.form-field input { width: 100%; padding: 11px 14px; border: 1.5px solid var(--line); border-radius: 10px; background: var(--surface-2); color: var(--text-1); font-size: 14px; font-family: var(--font); outline: none; transition: border-color .15s, box-shadow .15s, background .15s; }
.form-field input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(255,107,53,.1); background: var(--surface); }

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

.strength-bar { height: 4px; border-radius: 99px; margin-top: 6px; background: var(--surface-3); overflow: hidden; }
.strength-fill { height: 100%; width: 0%; transition: width .3s, background .3s; }
.strength-lbl { font-size: 10px; color: var(--text-3); text-align: right; margin-top: 3px; }

.submit-btn {
    width: 100%;
    padding: 13px;
    border-radius: 10px;
    border: none;
    background: var(--accent-grad);
    color: #fff;
    font-size: 14.5px;
    font-weight: 700;
    font-family: var(--font);
    cursor: pointer;
    box-shadow: var(--accent-glow);
    margin-top: 6px;
    transition: all .18s;
}
.submit-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }

.back-link {
    display: inline-block;
    text-align: center;
    width: 100%;
    margin-top: 20px;
    font-size: 13.5px;
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
}
.back-link:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="reset-card">
    <a href="index.php" class="brand-header">
        <div class="brand-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <span class="brand-title">Fintrack</span>
    </a>

    <h1 class="card-title">Set New Password</h1>
    <p class="card-sub">Please enter a new password for account <?php echo !empty($email) ? '<b>' . htmlspecialchars($email) . '</b>' : ''; ?>.</p>

    <?php if (!empty($error_message)): ?>
        <div class="alert-box alert-error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert-box alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <a href="index.php?auth=login" class="submit-btn" style="text-align:center;text-decoration:none;display:block;">Proceed to Sign In →</a>
    <?php elseif ($is_token_valid): ?>
        <form method="POST" action="reset_password.php" autocomplete="off" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <div class="form-field">
                <label>New Password</label>
                <div class="pwd-wrap">
                    <input type="password" id="newPass" name="new_password" required autocomplete="new-password" placeholder="At least 8 characters" oninput="checkStrength(this.value)">
                    <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('newPass', this)" title="Show/Hide Password">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-lbl" id="strengthLbl">Enter a password</div>
            </div>

            <div class="form-field">
                <label>Confirm New Password</label>
                <div class="pwd-wrap">
                    <input type="password" id="confirmPass" name="confirm_password" required autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('confirmPass', this)" title="Show/Hide Password">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn">Reset & Update Password →</button>
        </form>
    <?php endif; ?>

    <a href="index.php?auth=login" class="back-link">← Return to Sign In</a>
</div>

<script>
function checkStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const fill = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLbl');
    const colors = ['#E11D48','#D97706','#4F46E5','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];
    fill.style.width = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#E11D48';
    label.textContent = val.length ? (labels[score - 1] || 'Weak') : 'Enter a password';
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
</script>
</body>
</html>
