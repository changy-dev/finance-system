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
        $error = "Email already registered.";
    } 
    // SPECIFIC ERRORS (What is missing)
    elseif (strlen($password) < 8) {
        $error = "Password needs 8+ characters.";
    } 
    elseif (!preg_match("/[0-9]/", $password)) {
        $error = "Password needs at least one number.";
    }
    elseif (!preg_match("/[A-Z]/", $password) && !preg_match("/[a-z]/", $password)) {
        $error = "Password needs at least one letter.";
    }
    else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (full_name, email, password) VALUES ('$full_name', '$email', '$hashed')";
        if ($conn->query($sql)) {
            $success = "Account ready! You can log in.";
        } else {
            $error = "System error. Try again.";
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
        :root { --royal-purple: #7c3aed; --aqua-accent: #06b6d4; --neon-gold: #fbbf24; }
        
        body {
            font-family: 'Inter', sans-serif;
            height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; color: white;
            background: linear-gradient(-45deg, #020617, #0a0f1e, #1e1b4b, #0c4a6e);
            background-size: 400% 400%; animation: gradientShift 10s ease infinite; overflow: hidden;
        }

        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .box {
            width: 90%; max-width: 360px; padding: 35px; background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; text-align: center;
        }

        h2 { font-size: 24px; margin-bottom: 5px; font-weight: 800; }
        p { color: #94a3b8; margin-bottom: 25px; font-size: 13px; }

        .input-group { text-align: left; margin-bottom: 15px; }
        label { display: block; font-size: 11px; margin-bottom: 5px; color: #64748b; font-weight: 700; text-transform: uppercase; }
        
        input { 
            width: 100%; padding: 12px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.08); 
            border-radius: 10px; color: white; outline: none; box-sizing: border-box; transition: 0.3s; 
        }
        input:focus { border-color: var(--royal-purple); background: rgba(0, 0, 0, 0.4); }

        /* THE MINIMAL MESSAGE STYLE */
        .status-msg {
            font-size: 12px;
            margin-top: 15px;
            height: 15px; /* Keeps space so box doesn't jump */
            font-weight: 600;
        }
        .error { color: #f87171; text-shadow: 0 0 8px rgba(248, 113, 113, 0.3); }
        .success { color: #4ade80; text-shadow: 0 0 8px rgba(74, 222, 128, 0.3); }

        .btn { 
            width: 100%; padding: 15px; background: linear-gradient(135deg, var(--royal-purple), var(--aqua-accent)); 
            border: none; border-radius: 10px; color: white; font-weight: 700; cursor: pointer; 
            margin-top: 5px; text-transform: uppercase; letter-spacing: 1px;
        }

        .link { margin-top: 20px; font-size: 13px; color: #64748b; }
        .link a { color: var(--neon-gold); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Join Now</h2>
        <p>Start your financial journey today.</p>

        <form method="POST">
            <div class="input-group">
                <label>Full Name</label>
                <input type="text" name="full_name" required placeholder="Full Name">
            </div>
            <div class="input-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="example@gmail.com">
            </div>
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Create Password">
            </div>

            <button type="submit" class="btn">Create Account</button>

            <div class="status-msg">
                <?php if($error): ?>
                    <span class="error"><i class="fas fa-info-circle"></i> <?php echo $error; ?></span>
                <?php endif; ?>
                <?php if($success): ?>
                    <span class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></span>
                <?php endif; ?>
            </div>
        </form>

        <div class="link">Member? <a href="login.php">Log In</a></div>
    </div>
</body>
</html>