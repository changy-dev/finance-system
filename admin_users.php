<?php
session_start();
include 'db.php';
require_once 'csrf.php';

// ── ADMIN ROLE CHECK (re-verified from DB, not just session) ──────────────
// WHY: $_SESSION['role'] can be tampered with or stale. We re-fetch the role
// directly from the database on every admin page load so even if someone
// manipulates their session cookie, they cannot gain admin access.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$authUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$authUser || $authUser['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}
// ─────────────────────────────────────────────────────────────────────────

// 2. Handle Adding a New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    csrf_verify(); // WHY: prevents cross-site request forgery on this sensitive admin action

    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $role     = in_array($_POST['role'], ['user', 'admin']) ? $_POST['role'] : 'user';

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, 'active')");
    $stmt->bind_param("ssss", $name, $email, $password, $role);
    if ($stmt->execute()) {
        $msg = "New user added successfully!";
    } else {
        $msg = "Error: " . $stmt->error;
    }
    $stmt->close();
}

// 3. Handle Admin Actions (Warn, Freeze, Unfreeze)
// WHY: These actions come via GET links — we verify the admin role above,
// but we also make sure the target user is not an admin themselves.
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id              = (int) $_GET['id'];
    $allowed_actions = ['warn', 'freeze', 'unfreeze'];
    $action          = $_GET['action'];

    if (in_array($action, $allowed_actions)) {
        // Prevent acting on another admin account
        $chk = $conn->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
        $chk->bind_param("i", $id);
        $chk->execute();
        $target = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($target && $target['role'] !== 'admin') {
            if ($action === 'warn') {
                $stmt = $conn->prepare("UPDATE users SET warnings = warnings + 1 WHERE user_id = ?");
            } elseif ($action === 'freeze') {
                $stmt = $conn->prepare("UPDATE users SET status = 'frozen' WHERE user_id = ?");
            } elseif ($action === 'unfreeze') {
                $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE user_id = ?");
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
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
        <?php if (isset($msg)) echo "<p style='color:green'>" . htmlspecialchars($msg) . "</p>"; ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="text"     name="name"     placeholder="Full Name"      required>
            <input type="email"    name="email"    placeholder="Email Address"  required>
            <input type="password" name="password" placeholder="Password"       required>
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
            <?php while ($row = $users->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td><span class="badge"><?php echo strtoupper($row['role']); ?></span></td>
                <td><?php echo (int)$row['warnings']; ?>/3</td>
                <td><span class="badge <?php echo htmlspecialchars($row['status']); ?>"><?php echo strtoupper($row['status']); ?></span></td>
                <td>
                    <?php if ($row['role'] !== 'admin'): ?>
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