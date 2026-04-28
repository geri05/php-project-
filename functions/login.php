<?php
// filepath: c:\Users\User\Desktop\php-project-\functions\login.php
session_start();
require_once '../database/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, password, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $isValid = false;

    if ($user) {
        // Preferred: hashed password check
        if (password_verify($password, $user['password'])) {
            $isValid = true;
        }
        // Temporary fallback: supports old plain-text passwords
        elseif (hash_equals((string)$user['password'], (string)$password)) {
            $isValid = true;

            // Auto-upgrade old plain-text password to hash
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $upd->execute([$newHash, $user['id']]);
        }
    }

    if ($isValid) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];

        $redirect = ($user['role'] ?? 'user') === 'admin'
            ? '../admin.php'
            : '../index.php';

        header('Location: ' . $redirect);
        exit;
    }

    $_SESSION['login_error'] = 'Invalid email or password!';
    header('Location: ../index.php?error=true');
    exit;
}

header('Location: ../index.php');
exit;
?>