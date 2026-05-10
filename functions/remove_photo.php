<?php
/*
 * remove_photo.php — Deletes the user's profile photo from disk and clears
 * the profile_image_url field in the database.
 */
session_start();
require_once __DIR__ . '/../database/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT profile_image_url FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user && $user['profile_image_url']) {
    $filePath = __DIR__ . '/../uploads/profiles/' . $user['profile_image_url'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
}

$updateStmt = $pdo->prepare("UPDATE users SET profile_image_url = NULL WHERE id = ?");
$updateStmt->execute([$user_id]);

header("Location: ../index.php?photo_removed=true");
exit;