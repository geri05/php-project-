<?php
// Handles step 2 of user registration: validates the 6-digit verification code from the session,
// enforces expiry and attempt limits, and on success inserts the new user into the database,
// creates an authenticated session, and redirects to the dashboard.

session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/email_config.php';

if (!isset($_SESSION['pending_registration'])) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?verify_register=1');
    exit;
}

$pending = $_SESSION['pending_registration'];

$code = trim($_POST['code'] ?? '');
if ($code === '') {
    $parts = '';
    for ($i = 1; $i <= 6; $i++) {
        $parts .= trim($_POST['digit' . $i] ?? '');
    }
    $code = $parts;
}

if (!preg_match('/^\d{6}$/', $code)) {
    $_SESSION['verify_register_error'] = 'The code must be exactly 6 digits.';
    header('Location: ../index.php?verify_register=1');
    exit;
}

if ((int) $pending['expires_at'] < time()) {
    unset($_SESSION['pending_registration']);
    $_SESSION['register_error'] = 'The code has expired. Please fill out the form again.';
    header('Location: ../index.php?show_register=1&error=1');
    exit;
}

if ((int) $pending['attempts'] >= MAX_CODE_ATTEMPTS) {
    unset($_SESSION['pending_registration']);
    $_SESSION['register_error'] = 'Too many attempts. Please fill out the form again.';
    header('Location: ../index.php?show_register=1&error=1');
    exit;
}

if (!hash_equals((string) $pending['code'], $code)) {
    $_SESSION['pending_registration']['attempts'] = (int) $pending['attempts'] + 1;
    $remaining = MAX_CODE_ATTEMPTS - $_SESSION['pending_registration']['attempts'];
    $_SESSION['verify_register_error'] = $remaining > 0
        ? "Incorrect code. You have $remaining attempts remaining."
        : 'Incorrect code.';
    header('Location: ../index.php?verify_register=1');
    exit;
}

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$pending['email']]);
    if ($check->fetch()) {
        unset($_SESSION['pending_registration']);
        $_SESSION['register_error'] = 'This email was already registered. Please log in.';
        header('Location: ../index.php?error=1');
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, phone_number, password, role, auth_provider)
        VALUES (?, ?, ?, ?, ?, 'customer', 'local')
        RETURNING id, role
    ");
    $insert->execute([
        $pending['first_name'],
        $pending['last_name'],
        $pending['email'],
        $pending['phone_number'],
        $pending['password'],
    ]);
    $new_user = $insert->fetch();

    if (!$new_user) {
        $_SESSION['register_error'] = 'Account could not be created. Please try again.';
        header('Location: ../index.php?show_register=1&error=1');
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $new_user['id'];
    $_SESSION['role']    = $new_user['role'];

    unset(
        $_SESSION['pending_registration'],
        $_SESSION['verify_register_error'],
        $_SESSION['verify_register_info'],
        $_SESSION['register_error']
    );

    session_write_close();
    header('Location: ../index.php');
    exit;

} catch (PDOException $e) {
    error_log('verify_register.php: ' . $e->getMessage());
    $_SESSION['verify_register_error'] = 'System error. Please try again.';
    header('Location: ../index.php?verify_register=1');
    exit;
}