<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// Enforce admin-only access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../pages/dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/admin_dashboard.php");
    exit();
}

csrf_verify();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header("Location: ../pages/admin_dashboard.php?msg=invalid");
    exit();
}

$stmt = $conn->prepare("UPDATE transactions SET is_deleted_by_user = 0 WHERE transaction_id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: ../pages/admin_dashboard.php?msg=restored");
} else {
    header("Location: ../pages/admin_dashboard.php?msg=error");
}

$stmt->close();
exit();
