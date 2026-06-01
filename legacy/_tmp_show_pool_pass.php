<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo disponible por CLI.');
}
require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/crypto.php';

$dbName = $argv[1] ?? '';
if ($dbName === '') {
    exit(1);
}

$host = getenv('MASTER_DB_HOST') ?: 'localhost';
$port = (int)(getenv('MASTER_DB_PORT') ?: 3306);
$adminUser = getenv('MASTER_DB_USER') ?: 'root';
$adminPass = getenv('MASTER_DB_PASS') ?: '';
$dsn = "mysql:host={$host};port={$port};dbname=core_master;charset=utf8mb4";
$pdo = new PDO($dsn, $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$st = $pdo->prepare("SELECT db_user, db_password_enc, db_password_iv, db_password_tag FROM tenant_db_pool WHERE db_name = ? ORDER BY id DESC LIMIT 1");
$st->execute([$dbName]);
$r = $st->fetch();
if (!$r) {
    exit(2);
}
$pass = Crypto::decrypt((string)$r['db_password_enc'], (string)$r['db_password_iv'], (string)$r['db_password_tag']);
echo (string)$r['db_user'] . "\n" . $pass;
