<?php
/*
 * login.php — Authenticates a user with email and password,
 * then starts a session and redirects to the main page.
 */
session_start();
require_once '../database/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Please fill in all fields.';
    header("Location: ../index.php?error=1");
    exit;
}

$stmt = $pdo->prepare('SELECT id, password, role, auth_provider FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['login_error'] = 'Incorrect email or password. Please try again.';
    header("Location: ../index.php?error=1");
    exit;
}

if ($user['auth_provider'] === 'google' || empty($user['password'])) {
    $_SESSION['login_error'] = 'This account was created with Google. Please use "Continue with Google" to sign in.';
    header("Location: ../index.php?error=1");
    exit;
}

if (!password_verify($password, $user['password'])) {
    $_SESSION['login_error'] = 'Incorrect email or password. Please try again.';
    header("Location: ../index.php?error=1");
    exit;
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['role']    = $user['role'];

header("Location: ../index.php");
exit;