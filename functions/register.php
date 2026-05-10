<?php
/*
 * register.php — Step 1 of registration. Validates form input, checks that the
 * email is not already taken, generates a 6-digit verification code, stores the
 * pending registration in the session, and emails the code to the user.
 * The account is only created after the code is confirmed in verify_register.php.
 */
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

$first_name = trim($_POST['first_name']   ?? '');
$last_name  = trim($_POST['last_name']    ?? '');
$email      = trim($_POST['email']        ?? '');
$phone      = trim($_POST['phone_number'] ?? '');
$password   = $_POST['password']          ?? '';

$errors = [];

if ($first_name === '' || strlen($first_name) > 50) {
    $errors[] = 'First name is required (max 50 characters).';
}
if ($last_name === '' || strlen($last_name) > 50) {
    $errors[] = 'Last name is required (max 50 characters).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address.';
}
if ($phone !== '' && !preg_match('/^\+?[0-9\s\-]{7,15}$/', $phone)) {
    $errors[] = 'Invalid phone number.';
}
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}

if ($errors) {
    $_SESSION['register_error'] = implode(' ', $errors);
    header('Location: ../index.php?show_register=1&error=1');
    exit;
}

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) {
        $_SESSION['register_error'] = 'This email is already registered. Try logging in.';
        header('Location: ../index.php?show_register=1&error=1');
        exit;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['pending_registration'] = [
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'email'        => $email,
        'phone_number' => $phone !== '' ? $phone : null,
        'password'     => password_hash($password, PASSWORD_DEFAULT),
        'code'         => $code,
        'expires_at'   => time() + (CODE_LIFETIME_MINUTES * 60),
        'attempts'     => 0,
        'last_resend'  => 0,
        'started_at'   => time(),
    ];

    $sent = send_verification_code($email, $first_name, $code);
    if (!$sent) {
        unset($_SESSION['pending_registration']);
        $_SESSION['register_error'] = 'Failed to send verification code. Please try again.';
        header('Location: ../index.php?show_register=1&error=1');
        exit;
    }

    session_write_close();
    header('Location: ../index.php?verify_register=1');
    exit;

} catch (PDOException $e) {
    error_log('register.php: ' . $e->getMessage());
    $_SESSION['register_error'] = 'A system error occurred. Please try again.';
    header('Location: ../index.php?show_register=1&error=1');
    exit;
}