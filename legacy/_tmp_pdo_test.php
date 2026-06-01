<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo disponible por CLI.');
}
$host = $argv[1] ?? 'localhost';
$port = (int)($argv[2] ?? 3306);
$db = $argv[3] ?? '';
$user = $argv[4] ?? '';
$pass = $argv[5] ?? '';
if ($db === '' || $user === '') {
    exit(1);
}
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
    ]);
    echo "OK\n";
    echo $pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    echo "ERR\n";
    echo $e->getMessage();
}
