<?php
session_start();
include 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $result = $conn->query("SELECT * FROM users WHERE email='$email'");
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name'] = $user['full_name'];
            header("Location: dashboard.php");
        } else {
            $error = "Wrong email or password.";
        }
    } else {
        $error = "Account not found.";
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
            /* Animated Shifting Background */
            background: linear-gradient(-45deg, #020617, #0a0f1e, #1e1b4b, #0c4a6e);
            background-size: 400% 400%;
            animation: gradientShift 10s ease infinite;
            overflow: hidden;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
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
        p { color: #cbd5e1; margin-bottom: 30px; font-size: 14px; }

        .input-group { text-align: left; margin-bottom: 20px; }
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
        }

        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(124, 58, 237, 0.3); }

        .error-msg { color: #fca5a5; font-size: 13px; margin-bottom: 15px; background: rgba(239, 68, 68, 0.1); padding: 8px; border-radius: 8px; }
        .footer-link { margin-top: 25px; font-size: 14px; color: #94a3b8; }
        .footer-link a { color: var(--neon-gold); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Welcome</h2>
        <p>Log in to your account</p>
        <?php if($error): ?><div class="error-msg"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <div class="input-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="example@gmail.com">
            </div>
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Enter password">
            </div>
            <button type="submit" class="btn-login">Login</button>
        </form>
        <div class="footer-link">No account? <a href="register.php">Sign Up Free</a></div>
    </div>
</body>
</html>