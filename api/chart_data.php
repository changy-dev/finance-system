<?php
session_start();
include '../db.php';

$user_id = $_SESSION['user_id'];

$month = $_GET['month'] ?? date('m');

$data = [];

for($i=1;$i<=12;$i++){
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE user_id=? AND type='expense' AND MONTH(created_at)=?
    ");
    $stmt->bind_param("ii",$user_id,$i);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    $data[] = $res['total'] ?? 0;
}

echo json_encode($data);