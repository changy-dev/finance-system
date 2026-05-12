<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// totals
$stmt = $conn->prepare("SELECT SUM(amount) t FROM transactions WHERE user_id=? AND type='income' AND is_deleted_by_user=0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$income = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

$stmt = $conn->prepare("SELECT SUM(amount) t FROM transactions WHERE user_id=? AND type='expense' AND is_deleted_by_user=0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$expense = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

$balance = $income - $expense;
$savingsRate = $income ? round(($balance/$income)*100) : 0;

// monthly expenses
$months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
$monthly = [];
$stmt = $conn->prepare("SELECT MONTH(created_at) m, SUM(amount) t FROM transactions WHERE user_id=? AND type='expense' AND is_deleted_by_user=0 AND YEAR(created_at)=YEAR(CURDATE()) GROUP BY m");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$map = [];
while ($r = $res->fetch_assoc()) $map[(int)$r['m']] = (float)$r['t'];
foreach (range(1,12) as $i) $monthly[] = $map[$i] ?? 0;

// categories
$stmt = $conn->prepare("SELECT category, SUM(amount) total FROM transactions WHERE user_id=? AND type='expense' AND is_deleted_by_user=0 GROUP BY category ORDER BY total DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$categories = [];
$data = [];
$topCategory = "None";
$max = 0;
while ($row = $res->fetch_assoc()) {
    $categories[] = $row['category'];
    $data[] = (float)$row['total'];
    if ($row['total'] > $max) {
        $max = $row['total'];
        $topCategory = $row['category'];
    }
}

echo json_encode([
    "income"      => $income,
    "expense"     => $expense,
    "balance"     => $balance,
    "savingsRate" => $savingsRate,
    "months"      => $months,
    "monthly"     => $monthly,
    "categories"  => $categories,
    "categoryData"=> $data,
    "topCategory" => $topCategory
]);