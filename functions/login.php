<?php
session_start();
require_once '../database/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, password, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && $password === $user['password']) { 
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        header("Location: ../index.php");
        exit;
    } else {
        $_SESSION['login_error'] = 'Invalid email or password!';
        header("Location: ../index.php?error=true");
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>