<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$msg = "";

// Handle Updates
if (isset($_POST['update_account'])) {
    $new_email = $conn->real_escape_string($_POST['email']);
    $new_phone = $conn->real_escape_string($_POST['phone']);

    $update = $conn->query("UPDATE users SET email='$new_email', phone='$new_phone' WHERE user_id='$user_id'");
    
    if ($update) {
        $msg = "<p style='color:green;'>System Updated Successfully!</p>";
    } else {
        $msg = "<p style='color:red;'>Update failed. Data collision detected.</p>";
    }
}

$user = $conn->query("SELECT * FROM users WHERE user_id='$user_id'")->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Account Matrix | Modify</title>
    <style>
        body { font-family: sans-serif; background: #0f172a; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: rgba(255,255,255,0.03); padding: 40px; border-radius: 25px; width: 100%; max-width: 400px; border: 1px solid rgba(255,255,255,0.1); }
        input { width: 100%; padding: 15px; margin: 15px 0; border-radius: 10px; border: none; background: rgba(0,0,0,0.3); color: white; box-sizing: border-box; }
        button { width: 100%; padding: 15px; background: #7c3aed; color: white; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

<div class="card">
    <h2>Account Modification</h2>
    <?php echo $msg; ?>
    <form method="POST">
        <label>Primary Email</label>
        <input type="email" name="email" value="<?php echo $user['email']; ?>" required>
        
        <label>Contact Number</label>
        <input type="text" name="phone" value="<?php echo $user['phone']; ?>" required>
        
        <button type="submit" name="update_account">Commit Changes</button>
    </form>
    <br>
    <a href="dashboard.php" style="color: #94a3b8; text-decoration: none;">Back to Matrix</a>
</div>

</body>
</html>