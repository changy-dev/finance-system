<?php

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(); }

$theme   = $_POST['theme'] ?? 'dark';
$allowed = ['dark', 'light', 'auto'];
if (!in_array($theme, $allowed)) { http_response_code(400); exit(); }

$user_id = (int) $_SESSION['user_id'];
$stmt    = $conn->prepare("UPDATE users SET theme=? WHERE user_id=?");
$stmt->bind_param("si", $theme, $user_id);
$stmt->execute();

echo json_encode(['ok' => true]);
