<?php
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

csrf_verify_json();

$user_id = (int) $_SESSION['user_id'];

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

$daily_limits   = json_decode($_POST['daily_limits']   ?? '{}', true) ?: [];
$monthly_limits = json_decode($_POST['monthly_limits'] ?? '{}', true) ?: [];
$yearly_limits  = json_decode($_POST['yearly_limits']  ?? '{}', true) ?: [];

$allowed = ['Food', 'Transport', 'Utilities', 'Entertainment', 'Shopping', 'Health', 'Other'];

$stmt = $conn->prepare(
    "INSERT INTO budgets (user_id, category, amount_limit, daily_limit, monthly_limit, yearly_limit)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         amount_limit  = VALUES(amount_limit),
         daily_limit   = VALUES(daily_limit),
         monthly_limit = VALUES(monthly_limit),
         yearly_limit  = VALUES(yearly_limit)"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

$saved   = 0;
$errors  = [];
$MAX_AMT = 100000000; 

function valid_limit($v) {
   
    if ($v === null || $v === '') return [true, null];
    if (!is_numeric($v)) return [false, null];
    $f = (float) $v;
    if ($f < 0 || $f > 100000000) return [false, null];
    return [true, $f];
}

foreach ($budgets as $category => $amount) {
    if (!in_array($category, $allowed, true)) continue;

    [$okAmt, $amount]   = valid_limit($amount);
    [$okD, $daily]      = valid_limit($daily_limits[$category]   ?? null);
    [$okM, $monthly]    = valid_limit($monthly_limits[$category] ?? null);
    [$okY, $yearly]     = valid_limit($yearly_limits[$category]  ?? null);

    if (!$okAmt || !$okD || !$okM || !$okY) {
        $errors[] = "$category (invalid amount)";
        continue;
    }
    $amount = $amount ?? 0;

    $stmt->bind_param('isdddd', $user_id, $category, $amount, $daily, $monthly, $yearly);
    if ($stmt->execute()) {
        $saved++;
    } else {
        $errors[] = $category;
    }
}

$stmt->close();


$new_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $new_token;

if (count($errors) === 0) {
    echo json_encode(['success' => true,  'message' => "$saved budget(s) saved successfully", 'saved' => $saved, 'csrf_token' => $new_token]);
} else {
    echo json_encode(['success' => false, 'message' => 'Some failed: ' . implode(', ', $errors), 'saved' => $saved, 'csrf_token' => $new_token]);
}