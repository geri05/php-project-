<?php
/*
 * logout.php — Clears the session, deletes the session cookie,
 * and redirects the user to the main page.
 */
session_start();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: ../index.php");
exit;