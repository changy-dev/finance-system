<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $dest = (($_SESSION['role'] ?? '') === 'admin') ? '../pages/admin_dashboard.php' : '../pages/dashboard.php';
    header("Location: $dest");
} else {
    header("Location: ../index.php?auth=register");
}
exit();