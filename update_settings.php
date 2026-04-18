<?php
session_start();
include 'db.php';

$user_id = $_SESSION['user_id'];

$email = $_POST['email'];
$phone = $_POST['phone'];
$language = $_POST['language'];
$theme = $_POST['theme'];

$profile_pic = null;

if (!empty($_FILES['profile_pic']['name'])) {
    $file = time().'_'.$_FILES['profile_pic']['name'];
    move_uploaded_file($_FILES['profile_pic']['tmp_name'], "uploads/".$file);
    $profile_pic = $file;
}

if ($profile_pic) {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=?, profile_pic=? WHERE user_id=?");
    $stmt->bind_param("sssssi", $email, $phone, $language, $theme, $profile_pic, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, language=?, theme=? WHERE user_id=?");
    $stmt->bind_param("ssssi", $email, $phone, $language, $theme, $user_id);
}

$stmt->execute();

/* ✅ REDIRECT WORKS NOW */
header("Location: dashboard.php");
exit();