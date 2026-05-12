<?php
// ============================================================
// CRITICAL FIX #1: Prepared statements — no SQL injection
// All inserts and selects use bind_param
// ============================================================
session_start();
require_once 'db.php';

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
    <title>Register | Royal Matrix</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --royal-purple: #7c3aed;
            --aqua-accent: #06b6d4;
            --neon-gold: #fbbf24;
        }

        body {
            font-family: 'Inter', sans-serif;
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

        .box {
            width: 90%;
            max-width: 360px;
            padding: 35px;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }

        h2 { font-size: 24px; margin-bottom: 5px; font-weight: 800; }
        p  { color: #94a3b8; margin-bottom: 25px; font-size: 13px; }

        .input-group { text-align: left; margin-bottom: 15px; }
        label { display: block; font-size: 11px; margin-bottom: 5px; color: #64748b; font-weight: 700; text-transform: uppercase; }

        input {
            width: 100%;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            color: white;
            outline: none;
            box-sizing: border-box;
            transition: 0.3s;
        }
        input:focus { border-color: var(--royal-purple); background: rgba(0, 0, 0, 0.4); }

        .strength-bar {
            height: 3px;
            border-radius: 99px;
            margin-top: 6px;
            background: rgba(255,255,255,0.1);
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            border-radius: 99px;
            width: 0%;
            transition: width .3s, background .3s;
        }
        .strength-label { font-size: 10px; color: #64748b; text-align: right; margin-top: 3px; }

        .status-msg { font-size: 12px; margin-top: 15px; font-weight: 600; }
        .error-msg  {
            color: #f87171;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.2);
            padding: 8px 10px;
            border-radius: 8px;
            text-align: left;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .success-msg {
            color: #4ade80;
            background: rgba(74, 222, 128, 0.1);
            border: 1px solid rgba(74, 222, 128, 0.2);
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--royal-purple), var(--aqua-accent));
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 13px;
            transition: 0.3s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(124, 58, 237, 0.3); }

        .link { margin-top: 20px; font-size: 13px; color: #64748b; }
        .link a { color: var(--neon-gold); text-decoration: none; font-weight: 700; }

        .requirements { font-size: 11px; color: #475569; margin-top: 6px; text-align: left; line-height: 1.6; }
        .req { display: flex; align-items: center; gap: 5px; }
        .req.met { color: #4ade80; }
        .req::before { content: "○"; }
        .req.met::before { content: "●"; }
    </style>
</head>
<body>
<div class="box">
    <h2>Join Now</h2>
    <p>Start your financial journey today.</p>

    <?php if ($error): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success-msg"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <br><a href="login.php" style="color:#4ade80;font-weight:700;">Go to Login →</a>
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
    const colors = ['#ef4444','#f59e0b','#3b82f6','#10b981'];
    const labels = ['Weak','Fair','Good','Strong'];
    fill.style.width  = (score * 25) + '%';
    fill.style.background = colors[score - 1] || '#ef4444';
    label.textContent = val.length ? (labels[score - 1] || 'Very weak') : 'Enter a password';
    label.style.color = colors[score - 1] || '#64748b';
}
</script>
</body>
</html>