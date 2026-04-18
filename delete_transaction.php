<?php
session_start();
include 'db.php';

if (isset($_SESSION['user_id']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];

    if ($role == 'admin') {
        // ADMIN: Permanent Hard Delete (Crime prevention/System cleanup)
        $sql = "DELETE FROM transactions WHERE transaction_id='$id'";
    } else {
        // USER: Soft Delete (Hide from view, but keep for Admin audit)
        $sql = "UPDATE transactions SET is_deleted_by_user = 1 
                WHERE transaction_id='$id' AND user_id='$user_id'";
    }

    if ($conn->query($sql)) {
        header("Location: dashboard.php?msg=success");
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    header("Location: login.php");
}
?>