<?php

function logActivity($conn, $user_id, $action) {

    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'];

    $stmt = $conn->prepare("
    INSERT INTO audit_logs
    (user_id, action, ip_address, user_agent)
    VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "isss",
        $user_id,
        $action,
        $ip,
        $agent
    );

    $stmt->execute();
}
?>