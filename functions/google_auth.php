<?php
/*
 * google_auth.php — Initiates the Google OAuth2 flow for login or registration,
 * then redirects the user to Google's authorization page.
 */
session_start();
require_once 'google_config.php';

$action = $_GET['action'] ?? 'login';
if (!in_array($action, ['login', 'register'], true)) {
    $action = 'login';
}

$_SESSION['google_action'] = $action;
session_write_close();

$url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'email profile',
    'prompt'        => 'select_account'
]);

header("Location: $url");
exit;