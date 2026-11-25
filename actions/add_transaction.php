<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// If this is an AJAX POST request, return JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    csrf_verify_json();

    function csrf_json_exit(array $payload): void {
        $payload['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        echo json_encode($payload);
        exit;
    }

    $user_id = (int) $_SESSION['user_id'];

    $type        = trim($_POST['type']        ?? '');
    $category    = trim($_POST['category']    ?? '');
    $amount      = $_POST['amount']           ?? '';
    $description = trim($_POST['description'] ?? '');
    $created_at  = trim($_POST['created_at']  ?? '');

    $allowed_types = ['expense', 'income'];
    if (!in_array($type, $allowed_types, true)) {
        csrf_json_exit(['success' => false, 'message' => 'Invalid transaction type']);
    }

    $allowed_categories = [
        'Groceries', 'Household Supplies', 'Eating Out',
        'Fuel & Charging', 'Vehicle Maintenance', 'Commuting',
        'Utilities', 'Home Decor', 'Maintenance & DIY', 'Kitchenware',
        'Health & Pharmacy', 'Personal Grooming',
        'Clothing & Accessories', 'Electronics & Tech', 'Entertainment',
        'Gifts & Celebrations', 'Pet Supplies', 'Impulse Buys', 'Other',
        'Food', 'Transport', 'Shopping', 'Health',
        'Salary', 'Business', 'Investments', 'Shares', 'Freelance', 'Family', 'Rental', 'Gift', 'Other Income',
    ];
    if (!in_array($category, $allowed_categories, true)) {
        csrf_json_exit(['success' => false, 'message' => 'Invalid category: ' . htmlspecialchars($category)]);
    }

    $amount = floatval($amount);
    if ($amount <= 0) {
        csrf_json_exit(['success' => false, 'message' => 'Amount must be greater than 0']);
    }
    if ($amount > 100000000) {
        csrf_json_exit(['success' => false, 'message' => 'Amount is too large']);
    }

    if (empty($created_at) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_at)) {
        $created_at = date('Y-m-d');
    }
    if ($created_at > date('Y-m-d')) {
        csrf_json_exit(['success' => false, 'message' => 'Date cannot be in the future']);
    }

    // Attach current time so created_at has the real timestamp instead of 00:00:00
    $full_created_at = ($created_at === date('Y-m-d'))
        ? date('Y-m-d H:i:s')
        : $created_at . ' ' . date('H:i:s');

    // Do NOT htmlspecialchars here — the DB should store raw text.
    // Output escaping happens at render time (in HTML templates).
    // Storing HTML-encoded text causes double-encoding when displayed.

    $stmt = $conn->prepare(
        "INSERT INTO transactions (user_id, type, category, amount, description, date, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        csrf_json_exit(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
    }

    $stmt->bind_param('issdsss', $user_id, $type, $category, $amount, $description, $created_at, $full_created_at);

    if ($stmt->execute()) {
        csrf_json_exit([
            'success' => true,
            'message' => 'Transaction saved successfully!',
            'id'      => $stmt->insert_id,
        ]);
    } else {
        csrf_json_exit(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
    }

    $stmt->close();
    exit;
}
?>