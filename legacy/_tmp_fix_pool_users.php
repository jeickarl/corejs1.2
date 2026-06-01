<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo disponible por CLI.');
}
require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/crypto.php';

$host = getenv('MASTER_DB_HOST') ?: 'localhost';
$port = (int)(getenv('MASTER_DB_PORT') ?: 3306);
$adminUser = getenv('PROVISION_DB_ADMIN_USER') ?: (getenv('MASTER_DB_USER') ?: 'root');
$adminPass = getenv('PROVISION_DB_ADMIN_PASS') ?: (getenv('MASTER_DB_PASS') ?: '');
$userHost = getenv('TENANT_DB_USER_HOST') ?: 'localhost';

$dsnServer = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdoServer = new PDO($dsnServer, $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdoMaster = new PDO($dsnServer . ";dbname=core_master", $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$rows = $pdoMaster->query("SELECT id, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM tenant_db_pool WHERE status IN ('available','reserved') ORDER BY id ASC")->fetchAll();

$fixed = 0;
$errors = 0;
foreach ($rows as $r) {
    try {
        $dbName = (string)($r['db_name'] ?? '');
        $dbUser = (string)($r['db_user'] ?? '');
        if ($dbName === '' || $dbUser === '') {
            continue;
        }
        $pass = Crypto::decrypt((string)$r['db_password_enc'], (string)$r['db_password_iv'], (string)$r['db_password_tag']);
        $dbNameEsc = str_replace('`', '``', $dbName);
        $dbUserEsc = str_replace("'", "''", $dbUser);
        $dbPassEsc = str_replace("'", "''", $pass);
        $userHostEsc = str_replace("'", "''", $userHost);

        $pdoServer->exec("CREATE USER IF NOT EXISTS '{$dbUserEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$dbPassEsc}'");
        $pdoServer->exec("ALTER USER '{$dbUserEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$dbPassEsc}'");
        $pdoServer->exec("GRANT ALL PRIVILEGES ON `{$dbNameEsc}`.* TO '{$dbUserEsc}'@'{$userHostEsc}'");
        $fixed++;
    } catch (Throwable $e) {
        $errors++;
    }
}
try { $pdoServer->exec('FLUSH PRIVILEGES'); } catch (Throwable $e) {}

echo json_encode(['fixed' => $fixed, 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
