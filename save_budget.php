<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ── VALIDATE INPUT ── */
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

/* ── ALLOWED CATEGORIES ── */
$allowed = ['Food','Transport','Utilities','Entertainment','Shopping','Health','Other'];

/* ── UPSERT EACH CATEGORY ── */
// Uses INSERT ... ON DUPLICATE KEY UPDATE so one query handles both create and update.
// Requires a UNIQUE KEY on (user_id, category) in the budgets table.
//
// CREATE TABLE IF NOT EXISTS budgets (
//   budget_id  INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
//   user_id    INT          NOT NULL,
//   category   VARCHAR(50)  NOT NULL,
//   amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
//   updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//   UNIQUE KEY uq_user_cat (user_id, category)
// );

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
    // Skip disallowed categories
    if (!in_array($category, $allowed, true)) continue;

    $amount = max(0, (float) $amount); // never negative

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
