<?php
/**
 * check_budget_period_alerts.php
 * Returns JSON array of over-budget alerts (daily / monthly / yearly)
 * for the logged-in user, based on live transaction totals.
 * Also inserts into budget_over_notifications so alerts are not repeated
 * more than once per period.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['alerts' => []]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$today   = date('Y-m-d');
$month   = date('Y-m');
$year    = date('Y');

// ── FETCH BUDGETS WITH PERIOD LIMITS ──────────────────────────────────────
$budgets = [];
$bRes = $conn->query(
    "SELECT category, amount_limit, daily_limit, monthly_limit, yearly_limit
     FROM budgets
     WHERE user_id = $user_id"
);
if ($bRes) {
    while ($row = $bRes->fetch_assoc()) {
        $budgets[$row['category']] = $row;
    }
}

if (empty($budgets)) {
    echo json_encode(['alerts' => []]);
    exit;
}

// ── FETCH SPENDING FOR EACH PERIOD ────────────────────────────────────────
function get_spent($conn, $user_id, $from, $to) {
    $res = $conn->query(
        "SELECT category, SUM(amount) AS total
         FROM transactions
         WHERE user_id = $user_id
           AND type = 'expense'
           AND is_deleted_by_user = 0
           AND created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'
         GROUP BY category"
    );
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[$row['category']] = (float)$row['total'];
        }
    }
    return $data;
}

$today_dt   = date('Y-m-d');
$month_s    = date('Y-m-01');
$month_e    = date('Y-m-t');
$year_s     = date('Y-01-01');
$year_e     = date('Y-12-31');

$spent_today   = get_spent($conn, $user_id, $today_dt, $today_dt);
$spent_monthly = get_spent($conn, $user_id, $month_s,  $month_e);
$spent_yearly  = get_spent($conn, $user_id, $year_s,   $year_e);

// ── BUILD ALERTS ──────────────────────────────────────────────────────────
$alerts = [];

$periods = [
    'daily'   => ['key' => $today,  'spent_map' => $spent_today,   'limit_col' => 'daily_limit',   'label' => 'Today'],
    'monthly' => ['key' => $month,  'spent_map' => $spent_monthly, 'limit_col' => 'monthly_limit', 'label' => date('F Y')],
    'yearly'  => ['key' => $year,   'spent_map' => $spent_yearly,  'limit_col' => 'yearly_limit',  'label' => 'This Year'],
];

// Ensure notification table exists (graceful degradation)
$conn->query("CREATE TABLE IF NOT EXISTS `budget_over_notifications` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT NOT NULL,
    `category`     VARCHAR(100) NOT NULL,
    `period_type`  ENUM('daily','monthly','yearly') NOT NULL,
    `period_key`   VARCHAR(20) NOT NULL,
    `limit_amount` DECIMAL(15,2) NOT NULL,
    `spent_amount` DECIMAL(15,2) NOT NULL,
    `notified_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notify` (`user_id`,`category`,`period_type`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$upsert = $conn->prepare(
    "INSERT IGNORE INTO budget_over_notifications (user_id, category, period_type, period_key, limit_amount, spent_amount)
     VALUES (?, ?, ?, ?, ?, ?)"
);

foreach ($budgets as $category => $budget) {
    foreach ($periods as $period_type => $period) {
        $limit = (float)($budget[$period['limit_col']] ?? 0);
        if ($limit <= 0) continue;

        $spent = $spent_map[$category] ?? ($period['spent_map'][$category] ?? 0);
        $spent = (float)($period['spent_map'][$category] ?? 0);
        if ($spent <= $limit) continue;

        $over_by = $spent - $limit;
        $pct     = round($spent / $limit * 100);

        $alerts[] = [
            'category'    => $category,
            'period_type' => $period_type,
            'period_label'=> $period['label'],
            'limit'       => $limit,
            'spent'       => $spent,
            'over_by'     => $over_by,
            'pct'         => $pct,
        ];

        // Log the notification (IGNORE means it won't duplicate per period)
        if ($upsert) {
            $upsert->bind_param('isssdd', $user_id, $category, $period_type, $period['key'], $limit, $spent);
            $upsert->execute();
        }
    }
}

echo json_encode(['alerts' => $alerts]);