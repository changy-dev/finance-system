<?php
session_start();
include 'db.php';
require_once 'csrf.php';

// ── AUTH CHECK ────────────────────────────────────────────────────────────
// WHY: The original file had no auth check at all — anyone who knew the URL
// could POST to this file and overwrite any user's settings by guessing a user_id.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ── ONLY ACCEPT POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: settings.php");
    exit();
}

// ── CSRF CHECK ────────────────────────────────────────────────────────────
// WHY: Without this, a malicious website could silently submit this form
// on behalf of a logged-in user and change their email, phone, or profile picture.
csrf_verify();

$user_id  = (int) $_SESSION['user_id'];
$email    = trim($_POST['email']    ?? '');
$phone    = trim($_POST['phone']    ?? '');
$language = trim($_POST['language'] ?? 'en');
$theme    = trim($_POST['theme']    ?? 'light');

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: settings.php?error=invalid_email");
    exit();
}

// Whitelist language and theme to prevent junk data
$allowed_languages = ['en', 'fr', 'sw', 'ar'];
$allowed_themes    = ['light', 'dark', 'auto'];
$language = in_array($language, $allowed_languages) ? $language : 'en';
$theme    = in_array($theme,    $allowed_themes)    ? $theme    : 'light';

$profile_pic = null;

// ── FILE UPLOAD VALIDATION ────────────────────────────────────────────────
// WHY: The original code had NO validation — anyone could upload a .php file
// (webshell) disguised as a profile picture and execute it on your server.
if (!empty($_FILES['profile_pic']['name'])) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size      = 2 * 1024 * 1024; // 2MB

    $finfo     = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['profile_pic']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        header("Location: settings.php?error=invalid_file_type");
        exit();
    }

    if ($_FILES['profile_pic']['size'] > $max_size) {
        header("Location: settings.php?error=file_too_large");
        exit();
    }

    $ext         = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
    $safe_name   = time() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $upload_path = "uploads/" . $safe_name;

    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
        $profile_pic = $safe_name;
    } else {
        header("Location: settings.php?error=upload_failed");
        exit();
    }
}

// ── UPDATE DATABASE ───────────────────────────────────────────────────────
if ($profile_pic) {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=?, profile_pic=? WHERE user_id=?");
    $stmt->bind_param("sssssi", $email, $phone, $language, $theme, $profile_pic, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=? WHERE user_id=?");
    $stmt->bind_param("ssssi", $email, $phone, $language, $theme, $user_id);
}

$stmt->execute();
$stmt->close();

header("Location: settings.php?success=1");
exit();