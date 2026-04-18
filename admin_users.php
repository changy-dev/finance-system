<?php
session_start();
include 'db.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: dashboard.php");
    exit();
}

// 2. Handle Adding a New User
if (isset($_POST['add_user'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password']; // In a real app, use password_hash()
    $role = $_POST['role'];

    $sql = "INSERT INTO users (name, email, password, role, status) VALUES ('$name', '$email', '$password', '$role', 'active')";
    if ($conn->query($sql)) {
        $msg = "New user added successfully!";
    } else {
        $msg = "Error: " . $conn->error;
    }
}

// 3. Handle Admin Actions (Warn, Freeze, etc.)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    if ($_GET['action'] == 'warn') {
        $conn->query("UPDATE users SET warnings = warnings + 1 WHERE user_id='$id'");
    } elseif ($_GET['action'] == 'freeze') {
        $conn->query("UPDATE users SET status='frozen' WHERE user_id='$id'");
    } elseif ($_GET['action'] == 'unfreeze') {
        $conn->query("UPDATE users SET status='active' WHERE user_id='$id'");
    }
    header("Location: admin_users.php");
    exit();
}

$users = $conn->query("SELECT * FROM users ORDER BY role ASC, name ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - User Management</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 40px; background: #f0f2f5; }
        .container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .form-section { background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #e2e8f0; }
        input, select { padding: 10px; margin-right: 10px; border: 1px solid #cbd5e0; border-radius: 5px; }
        .btn-add { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #edf2f7; }
        th { background: #2d3748; color: white; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .active { background: #c6f6d5; color: #22543d; }
        .frozen { background: #fed7d7; color: #822727; }
    </style>
</head>
<body>

<div class="container">
    <h2>⚙️ User Management</h2>

    <div class="form-section">
        <h3>Add New User</h3>
        <?php if(isset($msg)) echo "<p style='color:green'>$msg</p>"; ?>
        <form method="POST">
            <input type="text" name="name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Password" required>
            <select name="role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
            <button type="submit" name="add_user" class="btn-add">+ Create User</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Warnings</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $users->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td><span class="badge"><?php echo strtoupper($row['role']); ?></span></td>
                <td><?php echo $row['warnings']; ?>/3</td>
                <td><span class="badge <?php echo $row['status']; ?>"><?php echo strtoupper($row['status']); ?></span></td>
                <td>
                    <?php if($row['role'] != 'admin'): ?>
                        <a href="admin_users.php?action=warn&id=<?php echo $row['user_id']; ?>">⚠️ Warn</a> | 
                        <a href="admin_users.php?action=freeze&id=<?php echo $row['user_id']; ?>">❄️ Freeze</a> |
                        <a href="admin_users.php?action=unfreeze&id=<?php echo $row['user_id']; ?>">🔓 Unfreeze</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <br>
    <a href="dashboard.php">← Back to Dashboard</a>
</div>

</body>
</html>