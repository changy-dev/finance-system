<?php
session_start();
include 'db.php';
require_once 'csrf.php';

// ── AUTH CHECK ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ── ONLY ACCEPT POST ──────────────────────────────────────────────────────
// WHY: The original used GET (?id=X) to delete transactions — this is dangerous
// because any image tag or link on any website can trigger a GET request.
// Deletes must always be POST requests protected by CSRF.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

// ── CSRF CHECK ────────────────────────────────────────────────────────────
// WHY: Without this, a malicious website could silently delete a user's
// transactions by tricking them into clicking a link while logged in.
csrf_verify();

$user_id = (int) $_SESSION['user_id'];
$id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header("Location: dashboard.php?msg=invalid");
    exit();
}

// ── RE-VERIFY ROLE FROM DATABASE ──────────────────────────────────────────
// WHY: Never trust $_SESSION['role'] alone — it can be stale or tampered with.
// We fetch the current role from the DB on every sensitive action.
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
    // USER: Soft delete — hides from view but keeps record for admin audit
    // WHY: We also check user_id here so a regular user cannot soft-delete
    // another user's transaction by guessing a transaction_id.
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