<?php
session_start();
include 'db.php';

$error = "";
if (isset($_POST['verify'])) {
    $email = $_SESSION['temp_email'];
    $user_otp = $_POST['otp'];

    $res = $conn->query("SELECT * FROM users WHERE email='$email' AND otp_code='$user_otp'");
    
    if ($res->num_rows > 0) {
        // Activate the user
        $conn->query("UPDATE users SET is_active=1, is_verified=1 WHERE email='$email'");
        unset($_SESSION['temp_email']);
        header("Location: login.php?msg=Verified Successfully!");
    } else {
        $error = "Invalid OTP Code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Identity</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; max-width: 350px; }
        .otp-input { width: 100%; font-size: 2em; text-align: center; letter-spacing: 10px; border: 2px solid #7c3aed; border-radius: 12px; padding: 10px; margin: 20px 0; }
        .btn { background: #7c3aed; color: white; border: none; width: 100%; padding: 15px; border-radius: 12px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

<div class="card">
    <h2 style="margin:0;">Confirm it's you</h2>
    <p style="color:#64748b;">We've sent a 6-digit code to your email.</p>
    
    <?php if($error) echo "<p style='color:red;'>$error</p>"; ?>

    <form method="POST">
        <input type="text" name="otp" class="otp-input" maxlength="6" placeholder="000000" required>
        <button type="submit" name="verify" class="btn">Verify Account</button>
    </form>
</div>

</body>
</html>