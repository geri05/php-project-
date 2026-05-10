<?php
/*
 * cancel_register.php — Clears any pending registration session data
 * and redirects the user back to the main page.
 */
session_start();

unset(
    $_SESSION['pending_registration'],
    $_SESSION['verify_register_error'],
    $_SESSION['verify_register_info'],
    $_SESSION['register_error']
);

header('Location: ../index.php');
exit;