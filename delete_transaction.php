<?php
session_start();
include 'db.php';

if (isset($_SESSION['user_id']) && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $user_id = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'];

    if ($role == 'admin') {
        // ADMIN: Permanent Hard Delete (Crime prevention/System cleanup)
        $stmt = $conn->prepare("DELETE FROM transactions WHERE transaction_id=?");
        $stmt->bind_param("i", $id);
    } else {
        // USER: Soft Delete (Hide from view, but keep for Admin audit)
        $stmt = $conn->prepare("UPDATE transactions SET is_deleted_by_user = 1 WHERE transaction_id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $user_id);
    }

    if ($stmt->execute()) {
        header("Location: dashboard.php?msg=success");
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
} else {
    header("Location: login.php");
}
?>