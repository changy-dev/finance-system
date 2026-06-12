<?php
session_start();
include 'db.php';

$user_id = $_SESSION['user_id'];
$cat = $_GET['category'];

$stmt = $conn->prepare("
SELECT * FROM transactions 
WHERE user_id=? AND category=? 
ORDER BY created_at DESC
");

$stmt->bind_param("is",$user_id,$cat);
$stmt->execute();
$res = $stmt->get_result();

while($r = $res->fetch_assoc()){
echo "<div class='tx'>{$r['category']} - KES ".number_format($r['amount'])."</div>";
}