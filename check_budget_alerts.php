<?php
// ============================================================
// check_budget_alerts.php — In-app budget alert checker
// Writes to budget_alerts table so notifications show in-app.
// Email/mailer removed.
// ============================================================

if (php_sapi_name() !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'])) {
        http_response_code(403); exit('Forbidden');
    }
}

require_once __DIR__ . '/db.php';

$alert_count = 0;

$stmt = $conn->prepare("
    SELECT
        b.budget_id, b.user_id, b.category,
        b.amount_limit, b.current_spent,
        COALESCE(ba.threshold, 80) AS threshold,
        ba.sent_at
    FROM budgets b
    JOIN users u ON u.user_id = b.user_id
    LEFT JOIN budget_alerts ba ON ba.budget_id = b.budget_id AND ba.user_id = b.user_id
    WHERE b.amount_limit > 0
      AND COALESCE(ba.is_active, 1) = 1
");
$stmt->execute();
$budgets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($budgets as $budget) {
    $limit     = (float)$budget['amount_limit'];
    $spent     = (float)$budget['current_spent'];
    $pct       = $limit > 0 ? (int)round($spent / $limit * 100) : 0;
    $threshold = (int)$budget['threshold'];

    // Only alert if threshold crossed
    if ($pct < $threshold) continue;

    // Don't re-alert within 24 hours
    if ($budget['sent_at'] && (time() - strtotime($budget['sent_at'])) < 86400) continue;

    // Write alert to DB — this is what the in-app notification reads
    $upsert = $conn->prepare("
        INSERT INTO budget_alerts (user_id, budget_id, threshold, sent_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE sent_at = NOW()
    ");
    $upsert->bind_param("iii", $budget['user_id'], $budget['budget_id'], $threshold);
    $upsert->execute();

    $alert_count++;
}

echo "Budget alerts updated: {$alert_count}.\n";
