<?php
session_start();
include 'db.php';

if (isset($_POST['register'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Generate a random 6-digit OTP
    $otp = rand(100000, 999999);

    // Insert user as inactive (is_active = 0)
    $sql = "INSERT INTO users (name, email, phone, password, otp_code, is_active) 
            VALUES ('$name', '$email', '$phone', '$pass', '$otp', 0)";

    if ($conn->query($sql)) {
        $_SESSION['temp_email'] = $email;
        // In a real system, you would use mail() or an SMS API here.
        // For now, we will redirect to the verify page.
        header("Location: verify_otp.php");
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Join Royal Finance</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #7c3aed 0%, #db2777 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .reg-card { background: white; padding: 40px; border-radius: 30px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); width: 100%; max-width: 400px; }
        input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 12px; box-sizing: border-box; }
        .checkbox-group { display: flex; align-items: flex-start; gap: 10px; font-size: 0.85em; margin: 15px 0; color: #64748b; }
        .robot-check { background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .btn-reg { background: #7c3aed; color: white; border: none; width: 100%; padding: 15px; border-radius: 15px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .btn-reg:hover { transform: scale(1.02); background: #6d28d9; }
    </style>
</head>
<body>

<div class="reg-card">
    <h2 style="text-align:center; margin-bottom:10px;">Create Account</h2>
    <p style="text-align:center; color:#64748b; font-size:0.9em; margin-bottom:25px;">Secure your future with Royal Finance</p>
    
    <form method="POST">
        <input type="text" name="name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="text" name="phone" placeholder="Phone Number" required>
        <input type="password" name="password" placeholder="Create Password" required>

        <div class="robot-check">
            <input type="checkbox" style="width:20px; margin:0;" required>
            <span style="font-weight:600; font-size:0.9em;">I am not a robot</span>
            <i class="fas fa-redo-alt" style="margin-left:auto; color:#cbd5e1;"></i>
        </div>

        <div class="checkbox-group">
            <input type="checkbox" style="width:20px; margin:0;" required>
            <span>I agree to the <a href="#" style="color:#7c3aed;">Terms of Service</a> and Privacy Policy of Royal Finance.</span>
        </div>

        <button type="submit" name="register" class="btn-reg">Get Verification Code</button>
    </form>
</div>

</body>
</html>