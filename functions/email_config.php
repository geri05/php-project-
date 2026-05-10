<?php
// SMTP configuration and verification code settings for Parkster.
// All credentials are loaded from the .env file — never hardcode them here.

if (!isset($_ENV['SMTP_USER'])) {
    loadEnv(__DIR__ . '/../.env');
}

define('MAIL_FROM_EMAIL', 'ndregjonirenis@gmail.com');
define('MAIL_FROM_NAME',  'Parkster Security');

define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',        587);
define('SMTP_USER',       $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS',       $_ENV['SMTP_PASS'] ?? '');
define('SMTP_SECURE',     'tls');

define('MAIL_DEV_MODE',   false);

define('CODE_LIFETIME_MINUTES',    10);
define('RESEND_COOLDOWN_SECONDS',  60);
define('MAX_CODE_ATTEMPTS',         5);