<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Category breakdown (expenses)
$stmt = $conn->prepare("SELECT category, SUM(amount) total
                        FROM transactions
                        WHERE user_id = ? AND type = 'expense' AND is_deleted_by_user = 0
                        GROUP BY category
                        ORDER BY total DESC");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$categories = [];
$cat_data   = [];
while ($row = $res->fetch_assoc()) {
    $categories[] = $row['category'];
    $cat_data[]   = (float)$row['total'];
}

// Monthly expenses (current year)
$year = date('Y');
$monthly = [];
$months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$stmt = $conn->prepare("SELECT MONTH(created_at) m, SUM(amount) total
                        FROM transactions
                        WHERE user_id = ? AND type = 'expense' AND is_deleted_by_user = 0
                          AND YEAR(created_at) = ?
                        GROUP BY MONTH(created_at)");
$stmt->bind_param('ii', $user_id, $year);
$stmt->execute();
$res = $stmt->get_result();
$expense_map = [];
while ($row = $res->fetch_assoc()) {
    $expense_map[(int)$row['m']] = (float)$row['total'];
}
for ($i = 1; $i <= 12; $i++) {
    $monthly[] = $expense_map[$i] ?? 0;
}

// Income vs expense totals
$stmt = $conn->prepare("SELECT
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) expense
    FROM transactions
    WHERE user_id = ? AND is_deleted_by_user = 0");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

echo json_encode([
    'categories'    => $categories,
    'categoryData'  => $cat_data,
    'months'        => $months,
    'monthlyExpenses' => $monthly,
    'totalIncome'   => (float)($totals['income'] ?? 0),
    'totalExpense'  => (float)($totals['expense'] ?? 0),
    'net'           => (float)($totals['income'] ?? 0) - (float)($totals['expense'] ?? 0),
]);