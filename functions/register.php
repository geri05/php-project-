<?php
session_start();
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        $_SESSION['register_error'] = 'This email already exists! Please try another one.';
        header("Location: index.php?show_register=true");
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?)');
    
    if ($stmt->execute([$first_name, $last_name, $email, $password, 'customer'])) {
        $stmt_login = $pdo->prepare('SELECT id, role FROM users WHERE email = ?');
        $stmt_login->execute([$email]);
        $new_user = $stmt_login->fetch();

        $_SESSION['user_id'] = $new_user['id'];
        $_SESSION['role'] = $new_user['role'];
        
        header("Location: index.php");
        exit;
    } else {
        $_SESSION['register_error'] = 'An error occurred during registration. Please try again.';
        header("Location: index.php?show_register=true");
        exit;
    }
} else {
    header("Location: index.php");
    exit;
}
?>