<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

csrf_verify_json();

$user_id = (int) $_SESSION['user_id'];
$id      = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
    exit;
}

$type        = trim($_POST['type']        ?? '');
$category    = trim($_POST['category']    ?? '');
$amount      = floatval($_POST['amount']  ?? 0);
$description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
$created_at  = trim($_POST['created_at']  ?? '');

$allowed_types = ['expense', 'income'];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid type']);
    exit;
}

$allowed_categories = [
    // Expense — Food & Dining
    'Groceries', 'Household Supplies', 'Eating Out',
    // Expense — Transport & Auto
    'Fuel & Charging', 'Vehicle Maintenance', 'Commuting',
    // Expense — Utilities & Home
    'Utilities', 'Home Decor', 'Maintenance & DIY', 'Kitchenware',
    // Expense — Health & Wellness
    'Health & Pharmacy', 'Personal Grooming',
    // Expense — Shopping & Lifestyle
    'Clothing & Accessories', 'Electronics & Tech', 'Entertainment',
    // Expense — Miscellaneous
    'Gifts & Celebrations', 'Pet Supplies', 'Impulse Buys', 'Other',
    // Legacy expense categories (keep for existing data)
    'Food', 'Transport', 'Shopping', 'Health',
    // Income
    'Salary', 'Business', 'Investments', 'Shares', 'Freelance', 'Family', 'Rental', 'Gift', 'Other Income',
];
if (!in_array($category, $allowed_categories, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid category']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be > 0']);
    exit;
}
if ($amount > 100000000) {
    echo json_encode(['success' => false, 'message' => 'Amount is too large']);
    exit;
}

if (empty($created_at) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_at)) {
    $created_at = date('Y-m-d');
}
if ($created_at > date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Date cannot be in the future']);
    exit;
}

// Also update the `date` column to fix 0000-00-00
$stmt = $conn->prepare(
    "UPDATE transactions
     SET type=?, category=?, amount=?, description=?, created_at=?, date=?
     WHERE transaction_id=? AND user_id=?"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('ssdsssii', $type, $category, $amount, $description, $created_at, $created_at, $id, $user_id);

$csrf_token = $_SESSION['csrf_token'] ?? '';
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Transaction updated', 'csrf_token' => $csrf_token]);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error, 'csrf_token' => $csrf_token]);
}
$stmt->close();