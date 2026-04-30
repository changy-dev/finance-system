<?php
/**
 * CSRF protection helper
 * Include after session_start(). Provides:
 *   csrf_token()        — generates/returns the token for the current session
 *   csrf_field()        — outputs a hidden <input> for HTML forms
 *   csrf_verify()       — verifies POST token, dies with 403 on failure
 *   csrf_verify_json()  — same but returns JSON error (for AJAX endpoints)
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF token mismatch. Please refresh and try again.');
    }
}

function csrf_verify_json(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
        exit;
    }
}
