<?php
session_start();
include 'db.php';

if (isset($_POST['login'])) {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];

    $result = $conn->query("SELECT * FROM users WHERE email='$email' AND password='$password'");

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($user['status'] == 'frozen') {
            $error = "Account Frozen. Contact Admin.";
        } else {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            header("Location: dashboard.php");
            exit();
        }
    } else {
        $error = "Invalid credentials.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 320px; text-align: center; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; background: #2d3748; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Login</h2>
        <?php if(isset($_GET['msg'])) echo "<p style='color:green'>Registration successful! Please login.</p>"; ?>
        <?php if(isset($error)) echo "<p style='color:red'>$error</p>"; ?>
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" id="loginPass" placeholder="Password" required>
            
            <div style="text-align:left; font-size:0.8em; margin-bottom:10px;">
                <input type="checkbox" style="width:auto;" onclick="myFunction()"> Show Password
            </div>

            <button type="submit" name="login" class="btn">Login</button>
        </form>
        <p style="font-size: 0.9em; margin-top: 15px;">New here? <a href="register.php">Register</a></p>
    </div>

    <script>
    function myFunction() {
        var x = document.getElementById("loginPass");
        x.type = x.type === "password" ? "text" : "password";
    }
    </script>
</body>
</html>