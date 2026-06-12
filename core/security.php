<?php
// ============================================================
// security.php — Include this at the top of EVERY protected page
// Handles:
// - Session validation
// - Idle timeout
// - Browser fingerprint security
//
// Usage:
// require_once 'security.php';
// (AFTER session_start())
// ============================================================

// ── CRITICAL FIX #4: Idle timeout ─────────────────────────
$session_lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1800);

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_lifetime
) {

    session_unset();
    session_destroy();

    header("Location: login.php?reason=timeout");
    exit();
}

// ── Not logged in at all ───────────────────────────────────
if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");
    exit();
}

// ── CRITICAL FIX #4: Session fingerprint check ─────────────
// Stable browser fingerprint (NO IP)
// Prevents false logouts when IP changes

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

    header("Location: login.php?reason=security");
    exit();
}

// ── Refresh activity timestamp ─────────────────────────────
$_SESSION['last_activity'] = time();

// ── Expose user_id as typed integer for all pages ──────────
$user_id = (int) $_SESSION['user_id'];
?>