<?php
session_start();
include 'db.php';

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $check = $conn->query("SELECT email FROM users WHERE email='$email'");
    if ($check->num_rows > 0) {
        $error = "Email already exists.";
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (full_name, email, password) VALUES ('$full_name', '$email', '$hashed')";
        if ($conn->query($sql)) {
            $success = "Account created! You can login now.";
        } else {
            $error = "Error creating account.";
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
    <style>
        /* Shared CSS from Login Page */
        :root { --royal-purple: #7c3aed; --aqua-accent: #06b6d4; --neon-gold: #fbbf24; }
        body {
            font-family: 'Inter', sans-serif;
            height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; color: white;
            background: linear-gradient(-45deg, #020617, #0a0f1e, #1e1b4b, #0c4a6e);
            background-size: 400% 400%; animation: gradientShift 10s ease infinite; overflow: hidden;
        }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .box { width: 100%; max-width: 380px; padding: 40px; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; text-align: center; }
        h2 { font-size: 28px; margin-bottom: 8px; }
        p { color: #cbd5e1; margin-bottom: 30px; font-size: 14px; }
        .input-group { text-align: left; margin-bottom: 15px; }
        label { display: block; font-size: 11px; margin-bottom: 5px; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
        input { width: 100%; padding: 12px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 10px; color: white; outline: none; box-sizing: border-box; }
        .btn { width: 100%; padding: 16px; background: linear-gradient(135deg, var(--royal-purple), var(--aqua-accent)); border: none; border-radius: 12px; color: white; font-weight: 700; cursor: pointer; margin-top: 15px; }
        .error { color: #fca5a5; font-size: 13px; margin-bottom: 10px; }
        .success { color: #86efac; font-size: 13px; margin-bottom: 10px; }
        .link { margin-top: 25px; font-size: 14px; color: #94a3b8; }
        .link a { color: var(--neon-gold); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Join Us</h2>
        <p>Create your finance account</p>
        <?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>
        <form method="POST">
            <div class="input-group">
                <label>Full Name</label>
                <input type="text" name="full_name" required placeholder="Your Name">
            </div>
            <div class="input-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="Email">
            </div>
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Create Password">
            </div>
            <button type="submit" class="btn">SIGN UP</button>
        </form>
        <div class="link">Have an account? <a href="login.php">Log In</a></div>
    </div>
</body>
</html>