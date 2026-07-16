<?php

$session_lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1800);

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_lifetime
) {

    session_unset();
    session_destroy();

    header("Location: /finance_system/auth/login.php?reason=timeout");
    exit();
}


if (!isset($_SESSION['user_id'])) {

    header("Location: /finance_system/auth/login.php");
    exit();
}

$current_fingerprint = hash(
    'sha256',
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

// Create fingerprint automatically if missing
if (!isset($_SESSION['fingerprint'])) {

    $_SESSION['fingerprint'] =
        $current_fingerprint;
}

// Validate fingerprint
if (
    !hash_equals(
        $_SESSION['fingerprint'],
        $current_fingerprint
    )
) {

    // Fingerprint mismatch
    // Possible session hijack

    session_unset();
    session_destroy();

    header("Location: /finance_system/auth/login.php?reason=security");
    exit();
}

// ── Refresh activity timestamp ─────────────────────────────
$_SESSION['last_activity'] = time();

// ── Expose user_id as typed integer for all pages ──────────
$user_id = (int) $_SESSION['user_id'];
?>