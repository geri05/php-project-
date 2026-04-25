<?php
function loadEnv($path) {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

loadEnv(dirname(__DIR__) . '/.env');

$requiredEnv = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
foreach ($requiredEnv as $key) {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        die("Missing required environment variable: {$key}");
    }
}

$host = $_ENV['DB_HOST'];
$port = $_ENV['DB_PORT'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $endpointId = explode('.', $host)[0]; 
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require;options=endpoint=$endpointId";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

   // echo "Connection was successful!";
} catch (PDOException $e) {
    die($e->getMessage());
}
?>