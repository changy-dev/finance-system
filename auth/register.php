<?php
// ============================================================
// CRITICAL FIX #1: Prepared statements — no SQL injection
// All inserts and selects use bind_param
// ============================================================
session_start();
require_once '../core/db.php';

// Already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error   = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']       ?? '';

    // ── Validate inputs ────────────────────────────────────
    if (empty($full_name) || strlen($full_name) < 2) {
        $error = "Please enter your full name.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password needs at least 8 characters.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password needs at least one number.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password needs at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password needs at least one lowercase letter.";
    } else {
        // ── CRITICAL FIX #1: Prepared statement for duplicate check ──
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "Email already registered. Please log in.";
        } else {
            // Hash password with bcrypt (cost factor 12 for security)
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Sanitise name for DB storage — strip any HTML
            $clean_name = strip_tags($full_name);

            // ── CRITICAL FIX #1: Prepared statement for INSERT ──
            $ins = $conn->prepare("
                INSERT INTO users (full_name, email, password, role, status, is_verified, is_active)
                VALUES (?, ?, ?, 'user', 'active', 0, 1)
            ");
            $ins->bind_param("sss", $clean_name, $email, $hashed);

            if ($ins->execute()) {
                $success = "Account created! You can now log in.";
            } else {
                error_log("Registration failed: " . $conn->error);
                $error = "System error. Please try again.";
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
    <title>Register | Fintrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
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

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: var(--font);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 24px 16px;
            color: var(--text-1);
            background: var(--page);
            font-size: 14px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
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

        .box {
            width: 100%;
            max-width: 400px;
            padding: 2.5rem 2.25rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-xl);
            text-align: center;
            box-shadow: var(--shadow-lg);
            position: relative;
            z-index: 1;
            animation: cardIn .45s cubic-bezier(.4,0,.2,1);
        }

        @keyframes cardIn { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }

        /* Logo lockup */
        .reg-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        .reg-logo-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--accent-grad);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(255,107,53,.4);
            flex-shrink: 0;
        }
        .reg-logo-icon svg { width: 18px; height: 18px; color: #fff; }
        .reg-logo-name {
            font-family: var(--font-head);
            font-size: 19px; font-weight: 700; color: var(--text-1); letter-spacing: -.2px;
        }

        h2 {
            font-family: var(--font-head);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: -.3px;
            color: var(--text-1);
        }

        p { color: var(--text-2); margin-bottom: 1.5rem; font-size: 13px; }

        .input-group { text-align: left; margin-bottom: 14px; }

        label {
            display: block;
            font-size: 10.5px;
            margin-bottom: 6px;
            color: var(--text-2);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        input {
            width: 100%;
            padding: 10px 13px;
            background: var(--surface-2);
            border: 1.5px solid var(--line);
            border-radius: var(--r);
            color: var(--text-1);
            font-family: var(--font);
            font-size: 14px;
            outline: none;
            transition: border-color .18s, box-shadow .18s, background .18s;
        }
        input:hover  { border-color: rgba(30,27,75,.2); }
        input:focus  {
            border-color: var(--accent);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(255,107,53,.1);
        }

        .strength-bar {
            height: 3px;
            border-radius: 99px;
            margin-top: 7px;
            background: var(--surface-3);
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            border-radius: 99px;
            width: 0%;
            transition: width .3s, background .3s;
        }
        .strength-label { font-size: 10px; color: var(--text-3); text-align: right; margin-top: 3px; }

        .status-msg { font-size: 12px; margin-top: 14px; font-weight: 600; }

        .error-msg {
            color: var(--red);
            background: var(--red-dim);
            border: 1px solid rgba(225,29,72,.25);
            padding: 9px 12px;
            border-radius: 9px;
            text-align: left;
            font-size: 13px;
            margin-bottom: 14px;
        }
        .success-msg {
            color: var(--green);
            background: var(--green-dim);
            border: 1px solid rgba(5,150,105,.25);
            padding: 9px 12px;
            border-radius: 9px;
            font-size: 13px;
            margin-bottom: 14px;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: var(--accent-grad);
            border: none;
            border-radius: var(--r);
            color: #fff;
            font-family: var(--font);
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            letter-spacing: -.01em;
            font-size: 14px;
            transition: all .18s;
            box-shadow: 0 4px 16px rgba(255,107,53,.3);
        }
        .btn:hover {
            filter: brightness(1.07);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(255,107,53,.4);
        }
        .btn:active { transform: translateY(0); }

        .link { margin-top: 18px; font-size: 13px; color: var(--text-2); }
        .link a { color: var(--accent); text-decoration: none; font-weight: 700; transition: opacity .15s; }
        .link a:hover { opacity: .75; }

        .requirements { font-size: 11px; color: var(--text-3); margin-top: 7px; text-align: left; line-height: 1.7; }
        .req { display: flex; align-items: center; gap: 6px; transition: color .2s; }
        .req.met { color: var(--green); }
        .req::before { content: "○"; font-size: 9px; }
        .req.met::before { content: "●"; }
    </style>
</head>
<body>
<div class="box">
    <div class="reg-logo">
        <div class="reg-logo-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                      d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="reg-logo-name">Fintrack</div>
    </div>

    <h2>Join Now</h2>
    <p>Start your financial journey today.</p>

    <?php if ($error): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success-msg"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <br><a href="login.php" style="color:var(--green);font-weight:700;">Go to Login →</a>
        </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" id="regForm">
        <div class="input-group">
            <label>Full Name</label>
            <input type="text" name="full_name" required placeholder="John Doe" maxlength="100"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="example@gmail.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="input-group">
            <label>Create Password</label>
            <input type="password" name="password" id="pwdInput" required placeholder="At least 8 characters" oninput="checkStrength(this.value)">
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-label" id="strengthLabel">Enter a password</div>
            <div class="requirements" id="reqs">
                <div class="req" id="req-len">8+ characters</div>
                <div class="req" id="req-num">At least one number</div>
                <div class="req" id="req-upper">At least one uppercase</div>
                <div class="req" id="req-lower">At least one lowercase</div>
            </div>
        </div>

        <button type="submit" class="btn">Create Account</button>
    </form>
    <?php endif; ?>

    <div class="link">Already a member? <a href="login.php">Log In</a></div>
</div>

<script>
function checkStrength(val) {
    const checks = {
        'req-len':   val.length >= 8,
        'req-num':   /[0-9]/.test(val),
        'req-upper': /[A-Z]/.test(val),
        'req-lower': /[a-z]/.test(val),
    };
    let score = Object.values(checks).filter(Boolean).length;
    Object.entries(checks).forEach(([id, met]) => {
        document.getElementById(id)?.classList.toggle('met', met);
    });
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    const colors = ['#E11D48','#D97706','#4F46E5','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];
    fill.style.width  = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#E11D48';
    label.textContent = val.length ? (labels[score - 1] || 'Very weak') : 'Enter a password';
    label.style.color = colors[score - 1] || '#94a3b8';
}
</script>
</body>
</html>