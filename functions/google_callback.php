<?php
/*
 * google_callback.php — Handles the Google OAuth2 callback. Exchanges the
 * authorization code for an access token, fetches the user's profile, then
 * either logs in an existing account or creates a new one depending on the
 * action (login / register). Profile pictures are intentionally ignored.
 */
session_start();
require_once '../database/db.php';
require_once 'google_config.php';

$action = $_SESSION['google_action'] ?? 'login';
unset($_SESSION['google_action']);

if (!isset($_GET['code'])) {
    header("Location: ../index.php");
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://oauth2.googleapis.com/token");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'code'          => $_GET['code']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response   = curl_exec($ch);
$token_data = json_decode($response, true);
curl_close($ch);

if (!isset($token_data['access_token'])) {
    $_SESSION['login_error'] = 'Google connection failed. Please try again.';
    header("Location: ../index.php?error=1");
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.googleapis.com/oauth2/v2/userinfo");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token_data['access_token']]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$profile_response = curl_exec($ch);
$google_user      = json_decode($profile_response, true);
curl_close($ch);

$email      = $google_user['email']       ?? '';
$google_id  = $google_user['id']          ?? '';
$first_name = $google_user['given_name']  ?? 'User';
$last_name  = $google_user['family_name'] ?? '';

if (!$email) {
    $_SESSION['login_error'] = 'Could not retrieve email from Google. Please try again.';
    header("Location: ../index.php?error=1");
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, role, auth_provider FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing_user = $stmt->fetch();

    if ($existing_user) {
        if (empty($existing_user['auth_provider']) || $existing_user['auth_provider'] === 'local') {
            $update = $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ? AND google_id IS NULL');
            $update->execute([$google_id, $existing_user['id']]);
        }

        $_SESSION['user_id'] = $existing_user['id'];
        $_SESSION['role']    = $existing_user['role'];

        session_write_close();
        header("Location: ../index.php");
        exit;
    }

    if ($action === 'login') {
        $_SESSION['login_error'] = 'No account found with this Google email. Please register first.';
        header("Location: ../index.php?error=1");
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, role, auth_provider, google_id)
        VALUES (?, ?, ?, 'customer', 'google', ?)
        RETURNING id
    ");
    $insert->execute([$first_name, $last_name, $email, $google_id]);
    $new_user = $insert->fetch();

    $_SESSION['user_id'] = $new_user['id'];
    $_SESSION['role']    = 'customer';

    session_write_close();
    header("Location: ../index.php");
    exit;

} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Database error. Please try again.';
    error_log('Google callback DB error: ' . $e->getMessage());
    header("Location: ../index.php?error=1");
    exit;
}