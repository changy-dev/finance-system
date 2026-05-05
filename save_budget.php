<?php
session_start();
include 'db.php';
require_once 'csrf.php';

header('Content-Type: application/json');

// ── AUTH CHECK ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// ── ONLY ACCEPT POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// ── CSRF CHECK ────────────────────────────────────────────────────────────
// WHY: Budget amounts are financial data. Without CSRF protection, a malicious
// site could silently reset all your budget limits to 0 while you're logged in.
csrf_verify_json();

$user_id = (int) $_SESSION['user_id'];

// ── VALIDATE INPUT ────────────────────────────────────────────────────────
$raw = $_POST['budgets'] ?? '';
if (empty($raw)) {
    echo json_encode(['success' => false, 'message' => 'No budget data received']);
    exit;
}

$budgets = json_decode($raw, true);
if (!is_array($budgets)) {
    echo json_encode(['success' => false, 'message' => 'Invalid budget data format']);
    exit;
}

// ── ALLOWED CATEGORIES ────────────────────────────────────────────────────
$allowed = ['Food', 'Transport', 'Utilities', 'Entertainment', 'Shopping', 'Health', 'Other'];

// ── UPSERT EACH BUDGET CATEGORY ───────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO budgets (user_id, category, amount_limit)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE amount_limit = VALUES(amount_limit)"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

$saved  = 0;
$errors = [];

foreach ($budgets as $category => $amount) {
    if (!in_array($category, $allowed, true)) continue;

    $amount = max(0, (float) $amount);

    $stmt->bind_param('isd', $user_id, $category, $amount);
    if ($stmt->execute()) {
        $saved++;
    } else {
        $errors[] = $category;
    }
}

$stmt->close();

if (count($errors) === 0) {
    echo json_encode([
        'success' => true,
        'message' => "$saved budget(s) saved successfully",
        'saved'   => $saved,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Some categories failed to save: ' . implode(', ', $errors),
        'saved'   => $saved,
    ]);
}