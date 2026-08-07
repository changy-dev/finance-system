<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// AJAX-only endpoint: returns JSON for a single transaction
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

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $tx_id   = isset($_POST['transaction_id']) ? (int) $_POST['transaction_id'] : 0;

    if ($tx_id <= 0) {
        csrf_json_exit(['success' => false, 'message' => 'Invalid transaction id']);
    }

    $stmt = $conn->prepare(
        'SELECT transaction_id AS id, user_id, type, category, amount, description, created_at
         FROM transactions
         WHERE transaction_id = ? AND user_id = ? LIMIT 1'
    );

    if (!$stmt) {
        csrf_json_exit(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
    }

    $stmt->bind_param('ii', $tx_id, $user_id);
    if (!$stmt->execute()) {
        csrf_json_exit(['success' => false, 'message' => 'Query failed: ' . $stmt->error]);
    }

    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        $stmt->close();
        csrf_json_exit(['success' => false, 'message' => 'Transaction not found']);
    }

    $tx = $res->fetch_assoc();
    $stmt->close();

    // Return the transaction (no HTML-escaping here; templates do that when rendering)
    echo json_encode(['success' => true, 'transaction' => $tx, 'csrf_token' => $_SESSION['csrf_token'] ?? '']);
    exit;
}

// Non-AJAX fallback
http_response_code(400);
echo 'This endpoint expects a POST AJAX request.';
exit;
