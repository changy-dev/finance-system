<?php
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';


if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// ── ONLY ACCEPT POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/settings.php");
    exit();
}


csrf_verify();

$user_id  = (int) $_SESSION['user_id'];
$email    = trim($_POST['email']    ?? '');
$phone    = trim($_POST['phone']    ?? '');
$language = trim($_POST['language'] ?? 'en');
$theme    = trim($_POST['theme']    ?? 'light');


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../pages/settings.php?error=invalid_email");
    exit();
}


$allowed_languages = ['en', 'fr', 'sw', 'ar'];
$allowed_themes    = ['light', 'dark', 'auto'];
$language = in_array($language, $allowed_languages) ? $language : 'en';
$theme    = in_array($theme,    $allowed_themes)    ? $theme    : 'light';

$profile_pic = null;


if (!empty($_FILES['profile_pic']['name'])) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size      = 2 * 1024 * 1024; // 2MB

    $finfo     = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['profile_pic']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        header("Location: ../pages/settings.php?error=invalid_file_type");
        exit();
    }

    if ($_FILES['profile_pic']['size'] > $max_size) {
        header("Location: ../pages/settings.php?error=file_too_large");
        exit();
    }

    $ext         = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
    $safe_name   = time() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $upload_path = __DIR__ . '/../uploads/' . $safe_name;

    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
        $profile_pic = $safe_name;
    } else {
        header("Location: ../pages/settings.php?error=upload_failed");
        exit();
    }
}


if ($profile_pic) {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=?, profile_pic=? WHERE user_id=?");
    $stmt->bind_param("sssssi", $email, $phone, $language, $theme, $profile_pic, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=? WHERE user_id=?");
    $stmt->bind_param("ssssi", $email, $phone, $language, $theme, $user_id);
}

$stmt->execute();
$stmt->close();

header("Location: ../pages/settings.php?success=1");
exit();