<?php
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// ── AUTH CHECK ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}


csrf_verify();

$user_id = (int) $_SESSION['user_id'];
$id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header("Location: dashboard.php?msg=invalid");
    exit();
}


$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$authUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$authUser) {
    header("Location: login.php");
    exit();
}

$role = $authUser['role'];

if ($role === 'admin') {
    // ADMIN: Hard delete — permanent removal for system cleanup
    $stmt = $conn->prepare("DELETE FROM transactions WHERE transaction_id = ?");
    $stmt->bind_param("i", $id);
} else {
    
    $stmt = $conn->prepare("UPDATE transactions SET is_deleted_by_user = 1 WHERE transaction_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
}

if ($stmt->execute()) {
    header("Location: dashboard.php?msg=success");
} else {
    header("Location: dashboard.php?msg=error");
}

$stmt->close();
exit();