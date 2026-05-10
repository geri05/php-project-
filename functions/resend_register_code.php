<?php
/*
 * resend_register_code.php — Generates a new 6-digit verification code,
 * replaces it in the session, and resends the email. Enforces the resend cooldown.
 */
session_start();
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/mailer.php';

if (!isset($_SESSION['pending_registration'])) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?verify_register=1');
    exit;
}

$pending = $_SESSION['pending_registration'];

$last_resend = (int) ($pending['last_resend'] ?? 0);
$elapsed     = time() - $last_resend;
if ($last_resend > 0 && $elapsed < RESEND_COOLDOWN_SECONDS) {
    $remaining = RESEND_COOLDOWN_SECONDS - $elapsed;
    $_SESSION['verify_register_error'] = "Please wait $remaining more seconds before requesting a new code.";
    header('Location: ../index.php?verify_register=1');
    exit;
}

$new_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$_SESSION['pending_registration']['code']        = $new_code;
$_SESSION['pending_registration']['expires_at']  = time() + (CODE_LIFETIME_MINUTES * 60);
$_SESSION['pending_registration']['attempts']    = 0;
$_SESSION['pending_registration']['last_resend'] = time();

$sent = send_verification_code(
    $pending['email'],
    $pending['first_name'] ?? 'User',
    $new_code
);

if (!$sent) {
    $_SESSION['verify_register_error'] = 'Failed to send a new code. Please try again.';
    header('Location: ../index.php?verify_register=1');
    exit;
}

$_SESSION['verify_register_info'] = 'A new code has been sent to your email.';
header('Location: ../index.php?verify_register=1');
exit;