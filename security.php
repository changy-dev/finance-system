<?php
// ============================================================
// security.php — Include this at the top of EVERY protected page
// Handles: session validation, idle timeout, fingerprint check
//
// Usage: require_once 'security.php';  (after session_start)
// ============================================================

// ── CRITICAL FIX #4: Idle timeout ─────────────────────────
$session_lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1800);

if (isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_lifetime) {
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
// Detects if a stolen session cookie is used from a different browser/IP
$current_fingerprint = hash('sha256',
    ($_SERVER['REMOTE_ADDR']      ?? '') .
    ($_SERVER['HTTP_USER_AGENT']  ?? '')
);

if (isset($_SESSION['fingerprint']) &&
    !hash_equals($_SESSION['fingerprint'], $current_fingerprint)) {
    // Fingerprint mismatch — possible session hijack, kill session
    session_unset();
    session_destroy();
    header("Location: login.php?reason=security");
    exit();
}

// ── Refresh activity timestamp ─────────────────────────────
$_SESSION['last_activity'] = time();

// ── Expose user_id as a typed int for all pages ────────────
$user_id = (int) $_SESSION['user_id'];