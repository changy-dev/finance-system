<?php
include '../db.php';
session_start();

$user_id = $_SESSION['user_id'];

// totals
$income = $conn->query("SELECT SUM(amount) t FROM transactions WHERE user_id=$user_id AND type='income'")->fetch_assoc()['t'] ?? 0;
$expense = $conn->query("SELECT SUM(amount) t FROM transactions WHERE user_id=$user_id AND type='expense'")->fetch_assoc()['t'] ?? 0;

$balance = $income - $expense;
$savingsRate = $income ? round(($balance/$income)*100) : 0;

// monthly
$months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
$monthly = [];

for($i=1;$i<=12;$i++){
    $res = $conn->query("
        SELECT SUM(amount) t 
        FROM transactions 
        WHERE user_id=$user_id 
        AND type='expense' 
        AND MONTH(created_at)=$i
    ")->fetch_assoc();

    $monthly[] = $res['t'] ?? 0;
}

// categories
$q = $conn->query("SELECT category, SUM(amount) total FROM transactions WHERE user_id=$user_id AND type='expense' GROUP BY category");

$categories=[];
$data=[];
$topCategory="None";
$max=0;

while($row=$q->fetch_assoc()){
    $categories[]=$row['category'];
    $data[]=$row['total'];

    if($row['total']>$max){
        $max=$row['total'];
        $topCategory=$row['category'];
    }
}

echo json_encode([
    "income"=>$income,
    "expense"=>$expense,
    "balance"=>$balance,
    "savingsRate"=>$savingsRate,
    "months"=>$months,
    "monthly"=>$monthly,
    "categories"=>$categories,
    "categoryData"=>$data,
    "topCategory"=>$topCategory
]);