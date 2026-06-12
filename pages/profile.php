<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = "";

// 1. Handle Profile Update
if (isset($_POST['update_profile'])) {
    $phone = $conn->real_escape_string($_POST['phone']);
    
    // Handle Image Upload
    if (!empty($_FILES['image']['name'])) {
        $img_name = time() . '_' . $_FILES['image']['name'];
        $target = "uploads/" . $img_name;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
            $conn->query("UPDATE users SET profile_pic='$img_name' WHERE user_id='$user_id'");
        }
    }

    $conn->query("UPDATE users SET phone='$phone', is_verified=1 WHERE user_id='$user_id'");
    $msg = "Profile Verified & Updated!";
}

// 2. Fetch current user data
$res = $conn->query("SELECT * FROM users WHERE user_id='$user_id'");
$user = $res->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Profile</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f3ff 0%, #fdf2f8 100%); margin: 0; padding: 40px; }
        .card { max-width: 500px; margin: auto; background: white; padding: 40px; border-radius: 30px; box-shadow: 0 20px 50px rgba(124, 58, 237, 0.1); text-align: center; }
        .profile-preview { width: 120px; height: 120px; border-radius: 50%; border: 4px solid #fbbf24; object-fit: cover; margin-bottom: 20px; }
        input[type="text"], input[type="file"] { width: 100%; padding: 12px; margin: 15px 0; border: 1px solid #ddd; border-radius: 12px; }
        .btn-save { background: linear-gradient(to right, #7c3aed, #db2777); color: white; padding: 15px 30px; border: none; border-radius: 15px; font-weight: bold; cursor: pointer; width: 100%; }
        .status { color: #10b981; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="card">
    <h2 style="font-weight:900; color:#7c3aed;">Verification Center</h2>
    
    <?php if($msg) echo "<p class='status'>$msg</p>"; ?>

    <form method="POST" enctype="multipart/form-data">
        <img src="<?php echo ($user['profile_pic'] != 'default.png') ? 'uploads/'.$user['profile_pic'] : 'https://ui-avatars.com/api/?name='.$user['name']; ?>" class="profile-preview">
        
        <p style="font-size: 0.9em; color: #64748b;">Upload Profile Picture</p>
        <input type="file" name="image" accept="image/*">

        <input type="text" name="phone" placeholder="Phone Number (e.g. 0712...)" value="<?php echo $user['phone']; ?>" required>
        
        <p style="font-size: 0.8em; color: #94a3b8; margin-bottom: 20px;">
            <i class="fas fa-shield-alt"></i> Verifying your phone secures your financial data.
        </p>

        <button type="submit" name="update_profile" class="btn-save">Verify & Save</button>
    </form>
    
    <br>
    <a href="dashboard.php" style="text-decoration:none; color:#7c3aed; font-weight:bold;">← Back to Dashboard</a>
</div>

</body>
</html>