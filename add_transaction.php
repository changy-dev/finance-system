<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

/* ── AUTH CHECK ── */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

/* ── ONLY ACCEPT POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ── COLLECT & VALIDATE INPUTS ── */
$type        = trim($_POST['type']        ?? '');
$category    = trim($_POST['category']    ?? '');
$amount      = $_POST['amount']           ?? '';
$description = trim($_POST['description'] ?? '');
$created_at  = trim($_POST['created_at']  ?? '');

// Validate type
$allowed_types = ['expense', 'income'];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
    exit;
}

// Validate category — must match every option in the dashboard dropdown
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
    echo json_encode(['success' => false, 'message' => 'Invalid category: ' . htmlspecialchars($category)]);
    exit;
}

// Validate amount
$amount = floatval($amount);
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
    exit;
}

// Validate date
if (empty($created_at) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_at)) {
    $created_at = date('Y-m-d'); // fall back to today
}

// Sanitise description
$description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

/* ── INSERT ── */
$stmt = $conn->prepare(
    "INSERT INTO transactions (user_id, type, category, amount, description, created_at)
     VALUES (?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('issdss', $user_id, $type, $category, $amount, $description, $created_at);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Transaction saved',
        'id'      => $stmt->insert_id,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
}

$stmt->close();