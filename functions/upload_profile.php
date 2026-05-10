<?php
// Handles authenticated user profile image uploads: validates file type, size, and integrity,
// removes the previous profile photo if one exists, saves the new image with a unique filename,
// and updates the user's profile_image_url in the database.

session_start();
require_once __DIR__ . '/../database/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['profile_image'])) {
    header("Location: ../index.php?upload=error");
    exit;
}

$user_id = $_SESSION['user_id'];
$file    = $_FILES['profile_image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    header("Location: ../index.php?upload_error=true");
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    header("Location: ../index.php?upload_error=size");
    exit;
}

$allowed = ['jpg', 'jpeg', 'png'];
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($fileExt, $allowed)) {
    header("Location: ../index.php?upload_error=type");
    exit;
}

$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    header("Location: ../index.php?upload_error=true");
    exit;
}

$stmt = $pdo->prepare("SELECT profile_image_url FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$existing = $stmt->fetch();
if ($existing && $existing['profile_image_url']) {
    $oldPath = __DIR__ . '/../uploads/profiles/' . $existing['profile_image_url'];
    if (file_exists($oldPath)) unlink($oldPath);
}

$newFileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExt;
$uploadDir   = __DIR__ . '/../uploads/profiles/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFileName)) {
    $stmt = $pdo->prepare("UPDATE users SET profile_image_url = ? WHERE id = ?");
    $stmt->execute([$newFileName, $user_id]);
    header("Location: ../index.php?upload=success");
    exit;
}

header("Location: ../index.php?upload=error");
exit;
?>