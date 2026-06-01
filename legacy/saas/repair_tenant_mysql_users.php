<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';
require_once __DIR__ . '/../config/crypto.php';

function out(string $line): void
{
    echo $line . PHP_EOL;
}

$adminUser = getenv('PROVISION_DB_ADMIN_USER');
$adminPass = getenv('PROVISION_DB_ADMIN_PASS');
if (!is_string($adminUser) || trim($adminUser) === '') {
    out('ERROR: Falta PROVISION_DB_ADMIN_USER en config/.env.local');
    exit(2);
}
$adminUser = trim($adminUser);
$adminPass = is_string($adminPass) ? $adminPass : '';

$defaultHost = getenv('TENANT_DB_HOST');
if (!is_string($defaultHost) || trim($defaultHost) === '') {
    $defaultHost = getenv('MASTER_DB_HOST');
}
$defaultHost = (is_string($defaultHost) && trim($defaultHost) !== '') ? trim($defaultHost) : 'localhost';

$defaultPort = getenv('TENANT_DB_PORT');
if (!is_string($defaultPort) || trim($defaultPort) === '') {
    $defaultPort = getenv('MASTER_DB_PORT');
}
$defaultPort = (int)((is_string($defaultPort) && trim($defaultPort) !== '') ? trim($defaultPort) : '3306');

$userHost = getenv('TENANT_DB_USER_HOST');
$userHost = (is_string($userHost) && trim($userHost) !== '') ? trim($userHost) : 'localhost';

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
];

$adminCache = [];
$getAdminPdo = function (string $host, int $port) use (&$adminCache, $adminUser, $adminPass, $pdoOptions): PDO {
    $key = $host . ':' . $port;
    if (isset($adminCache[$key]) && $adminCache[$key] instanceof PDO) {
        return $adminCache[$key];
    }
    $dsn = "mysql:host={$host};port={$port};dbname=mysql;charset=utf8mb4";
    $adminCache[$key] = new PDO($dsn, $adminUser, $adminPass, $pdoOptions);
    return $adminCache[$key];
};

$master = DatabaseManager::master();
$empresas = $master->query('SELECT * FROM empresas ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
if (!$empresas) {
    out('No hay empresas en core_master.');
    exit(0);
}

out('=== Repair Tenant MySQL Users ===');
foreach ($empresas as $e) {
    $empresaId = (int)($e['id'] ?? 0);
    if ($empresaId <= 0) {
        continue;
    }

    $nombre = (string)($e['nombre'] ?? '');
    $estado = (string)($e['estado'] ?? '');
    $host = (string)($e['db_host'] ?? $defaultHost);
    $port = (int)($e['db_port'] ?? $defaultPort);
    $dbName = (string)($e['db_name'] ?? '');
    $dbUser = (string)($e['db_user'] ?? '');
    $enc = (string)($e['db_password_enc'] ?? '');
    $iv = (string)($e['db_password_iv'] ?? '');
    $tag = (string)($e['db_password_tag'] ?? '');

    if ($estado === 'deleted') {
        out("[SKIP] {$empresaId} {$nombre} (estado=deleted)");
        continue;
    }
    if ($dbName === '' || $dbUser === '' || $enc === '' || $iv === '' || $tag === '') {
        out("[SKIP] {$empresaId} {$nombre} (config incompleta)");
        continue;
    }

    try {
        $dbPass = Crypto::decrypt($enc, $iv, $tag);
    } catch (Throwable $ex) {
        out("[FAIL] {$empresaId} {$nombre} (decrypt) " . $ex->getMessage());
        continue;
    }

    try {
        $admin = $getAdminPdo($host !== '' ? $host : $defaultHost, $port > 0 ? $port : $defaultPort);
        $dbNameEsc = str_replace('`', '``', $dbName);
        $dbUserEsc = str_replace("'", "''", $dbUser);
        $dbPassEsc = str_replace("'", "''", $dbPass);
        $userHostEsc = str_replace("'", "''", $userHost);

        $admin->exec("CREATE USER IF NOT EXISTS '{$dbUserEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$dbPassEsc}'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$dbNameEsc}`.* TO '{$dbUserEsc}'@'{$userHostEsc}'");
        $admin->exec("FLUSH PRIVILEGES");

        out("[OK] {$empresaId} {$nombre} user={$dbUser}@{$userHost} db={$dbName}");
    } catch (Throwable $ex) {
        out("[FAIL] {$empresaId} {$nombre} " . $ex->getMessage());
        continue;
    }
}

out('Done');

