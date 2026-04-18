<?php
session_start();
include 'db.php';

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id=?");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body{
    margin:0;
    font-family:Inter;
    background:#020617;
    color:#e2e8f0;
}

.app{display:flex;}

.sidebar{
    width:250px;
    padding:25px;
    background:#020617;
}

.sidebar a{
    display:block;
    padding:12px;
    color:#94a3b8;
    text-decoration:none;
    border-radius:10px;
}

.sidebar a.active{
    background:linear-gradient(135deg,#6366f1,#3b82f6);
    color:white;
}

.main{flex:1;padding:40px;}

.grid{
    display:grid;
    gap:20px;
}

@media(min-width:900px){
    .grid{grid-template-columns:1fr 1fr;}
}

.card{
    background:#0f172a;
    padding:20px;
    border-radius:12px;
}

input, select{
    width:100%;
    padding:10px;
    margin:10px 0;
    border-radius:8px;
    border:none;
}

.btn{
    background:linear-gradient(135deg,#6366f1,#3b82f6);
    border:none;
    padding:10px;
    color:white;
    border-radius:8px;
    cursor:pointer;
}

.avatar{
    width:90px;
    height:90px;
    border-radius:50%;
    margin-bottom:10px;
}
</style>

</head>

<body>

<div class="app">

<div class="sidebar">
<h2>Finance</h2>
<a href="dashboard.php">Dashboard</a>
<a href="transactions.php">Transactions</a>
<a href="settings.php" class="active">Settings</a>
</div>

<div class="main">

<h2>Settings</h2>

<div class="grid">

<!-- PROFILE -->
<div class="card">
<h3>Profile</h3>

<form action="update_settings.php" method="POST" enctype="multipart/form-data">

<img src="uploads/<?php echo $user['profile_pic']; ?>" class="avatar">

<input type="file" name="profile_pic">

<input type="text" name="full_name" value="<?php echo $user['full_name']; ?>">

<input type="email" name="email" value="<?php echo $user['email']; ?>">

<input type="text" name="phone" value="<?php echo $user['phone']; ?>">

<button class="btn">Save Changes</button>

</form>
</div>

<!-- PREFERENCES -->
<div class="card">
<h3>Preferences</h3>

<label>Theme</label>
<select>
<option>Dark</option>
<option>Light</option>
</select>

<label>Language</label>
<select>
<option>🌍 English</option>
<option>🇰🇪 Swahili</option>
</select>

</div>

</div>

</div>
</div>

</body>
</html>