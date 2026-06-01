<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';
require_once __DIR__ . '/../config/tenant_manager.php';
require_once __DIR__ . '/../config/sql_importer.php';

if (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) {
    out('Este script es para migrar desde single-db hacia per_database. Ejecútalo en modo single-db.');
    exit(1);
}

$argv = (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) ? $_SERVER['argv'] : [];

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function qid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . qid($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
    $cols = [];
    foreach ($rows as $r) {
        $field = (string)($r['Field'] ?? '');
        if ($field !== '') {
            $cols[] = $field;
        }
    }
    return $cols;
}

function parseTenantFilter(?string $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    $ids = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $id = (int)$p;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function connectSourceFromEnv(array $argv): PDO
{
    $host = argValue($argv, 'source-host', getenv('LEGACY_DB_HOST') ?: 'localhost');
    $port = (int)(argValue($argv, 'source-port', getenv('LEGACY_DB_PORT') ?: '3306') ?: '3306');
    $db = argValue($argv, 'source-db', getenv('LEGACY_DB_NAME') ?: 'core_db');
    $user = argValue($argv, 'source-user', getenv('LEGACY_DB_USER') ?: (getenv('MASTER_DB_USER') ?: 'root'));
    $pass = argValue($argv, 'source-pass', getenv('LEGACY_DB_PASS') ?: (getenv('MASTER_DB_PASS') ?: ''));

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
    ];
    return new PDO($dsn, (string)$user, (string)$pass, $opts);
}

function resolveEmpresaId(PDO $master, array $legacyTenant): ?int
{
    $legacyId = (int)($legacyTenant['id'] ?? 0);
    $company = trim((string)($legacyTenant['company_name'] ?? ''));

    if ($legacyId > 0) {
        $s = $master->prepare("SELECT id FROM empresas WHERE id = ? AND estado = 'active' LIMIT 1");
        $s->execute([$legacyId]);
        $id = $s->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    if ($company !== '') {
        $s = $master->prepare("SELECT id FROM empresas WHERE nombre = ? AND estado = 'active' ORDER BY id ASC LIMIT 1");
        $s->execute([$company]);
        $id = $s->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    return null;
}

function envOrDefault(string $key, string $default): string
{
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        return $default;
    }
    return trim($v);
}

function connectProvisionAdmin(array $argv): PDO
{
    $host = envOrDefault('TENANT_DB_HOST', envOrDefault('MASTER_DB_HOST', 'localhost'));
    $port = (int)envOrDefault('TENANT_DB_PORT', envOrDefault('MASTER_DB_PORT', '3306'));
    $user = argValue($argv, 'provision-user', getenv('PROVISION_DB_ADMIN_USER') ?: '');
    $pass = argValue($argv, 'provision-pass', getenv('PROVISION_DB_ADMIN_PASS') ?: '');
    if (!is_string($user) || trim($user) === '') {
        throw new RuntimeException('Falta PROVISION_DB_ADMIN_USER para bootstrap de empresas.');
    }

    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
    ];
    return new PDO($dsn, trim((string)$user), (string)$pass, $opts);
}

function connectTenantDirect(string $dbName, string $dbUser, string $dbPass): PDO
{
    $host = envOrDefault('TENANT_DB_HOST', envOrDefault('MASTER_DB_HOST', 'localhost'));
    $port = (int)envOrDefault('TENANT_DB_PORT', envOrDefault('MASTER_DB_PORT', '3306'));
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
    ];
    return new PDO($dsn, $dbUser, $dbPass, $opts);
}

function randomStrongPassword(int $length = 32): string
{
    $bytes = random_bytes((int)ceil($length * 0.75));
    $b64 = rtrim(strtr(base64_encode($bytes), '+/', 'Aa'), '=');
    return substr($b64, 0, $length);
}

function bootstrapEmpresaFromLegacy(PDO $master, array $legacyTenant, bool $apply, array $argv): ?int
{
    $legacyId = (int)($legacyTenant['id'] ?? 0);
    $legacyName = trim((string)($legacyTenant['company_name'] ?? ''));
    if ($legacyId <= 0 || $legacyName === '') {
        return null;
    }

    if (!$apply) {
        out("  [PLAN] Se crearía empresa destino para tenant {$legacyId}: {$legacyName}");
        return null;
    }

    $dbPrefix = envOrDefault('TENANT_DB_PREFIX', 'core_tenant_');
    $userPrefix = envOrDefault('TENANT_DB_USER_PREFIX', 'core_u_');
    $dbHost = envOrDefault('TENANT_DB_HOST', envOrDefault('MASTER_DB_HOST', 'localhost'));
    $dbPort = (int)envOrDefault('TENANT_DB_PORT', envOrDefault('MASTER_DB_PORT', '3306'));

    $empresaIdProbe = null;
    $existing = null;
    $byId = $master->prepare("SELECT id, db_name FROM empresas WHERE id = ? LIMIT 1");
    $byId->execute([$legacyId]);
    $existing = $byId->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$existing) {
        $byName = $master->prepare("SELECT id, db_name FROM empresas WHERE nombre = ? ORDER BY id ASC LIMIT 1");
        $byName->execute([$legacyName]);
        $existing = $byName->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $targetEmpresaId = $existing ? (int)$existing['id'] : 0;
    $empresaKey = $targetEmpresaId > 0 ? $targetEmpresaId : $legacyId;

    $dbName = $dbPrefix . str_pad((string)$empresaKey, 6, '0', STR_PAD_LEFT);
    $dbUser = $userPrefix . str_pad((string)$empresaKey, 6, '0', STR_PAD_LEFT);
    $dbPass = randomStrongPassword(32);
    $enc = Crypto::encrypt($dbPass);

    if ($targetEmpresaId > 0) {
        $upd = $master->prepare("
            UPDATE empresas
            SET nombre = ?, estado = 'provisioning', db_host = ?, db_port = ?, db_name = ?, db_user = ?, db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$legacyName, $dbHost, $dbPort, $dbName, $dbUser, $enc['enc'], $enc['iv'], $enc['tag'], $targetEmpresaId]);
        $empresaIdProbe = $targetEmpresaId;
    } else {
        $ins = $master->prepare("
            INSERT INTO empresas (nombre, estado, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, schema_version, created_at, updated_at)
            VALUES (?, 'provisioning', ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $ins->execute([$legacyName, $dbHost, $dbPort, $dbName, $dbUser, $enc['enc'], $enc['iv'], $enc['tag']]);
        $empresaIdProbe = (int)$master->lastInsertId();
    }

    $adminPdo = connectProvisionAdmin($argv);
    $dbEsc = str_replace('`', '``', $dbName);
    $userEsc = str_replace("'", "''", $dbUser);
    $passEsc = str_replace("'", "''", $dbPass);
    $userHost = envOrDefault('TENANT_DB_USER_HOST', 'localhost');
    $userHostEsc = str_replace("'", "''", $userHost);

    // Reintentos de bootstrap: rehacer BD destino para evitar residuos de intentos previos.
    $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbEsc}`");
    $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
    $adminPdo->exec("CREATE USER IF NOT EXISTS '{$userEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$passEsc}'");
    $adminPdo->exec("ALTER USER '{$userEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$passEsc}'");
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `{$dbEsc}`.* TO '{$userEsc}'@'{$userHostEsc}'");
    $adminPdo->exec("FLUSH PRIVILEGES");

    $tenantPdo = connectTenantDirect($dbName, $dbUser, $dbPass);
    $base = realpath(__DIR__ . '/template_clean.sql');
    $multi = realpath(__DIR__ . '/migration_tables.sql');
    if (!is_string($base) || !is_string($multi)) {
        throw new RuntimeException('No se encontró esquema SQL para bootstrap.');
    }
    SqlImporter::importFile($tenantPdo, $base);

    $tenantPdo->exec("
        CREATE TABLE IF NOT EXISTS `tenants` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `company_name` varchar(255) NOT NULL,
          `slug` varchar(64) NOT NULL,
          `status` enum('active','suspended') DEFAULT 'active',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
    ");
    $tenantPdo->exec("INSERT IGNORE INTO tenants (id, company_name, slug, status) VALUES (1, 'Empresa Principal', 'default', 'active')");

    try {
        $hasUserTenant = $tenantPdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
        if (!$hasUserTenant || $hasUserTenant->rowCount() === 0) {
            $tenantPdo->exec("ALTER TABLE users ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            $tenantPdo->exec("CREATE INDEX idx_users_tenant ON users(tenant_id)");
        }
    } catch (Throwable $e) {}

    try {
        $hasOsTenant = $tenantPdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        if (!$hasOsTenant || $hasOsTenant->rowCount() === 0) {
            $tenantPdo->exec("ALTER TABLE order_statuses ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            $tenantPdo->exec("CREATE INDEX idx_order_statuses_tenant ON order_statuses(tenant_id)");
        }
    } catch (Throwable $e) {}

    SqlImporter::importFile($tenantPdo, $multi);

    try {
        $u1 = $tenantPdo->prepare("UPDATE tenants SET company_name = ?, slug = 'default', status = 'active', updated_at = NOW() WHERE id = 1");
        $u1->execute([$legacyName]);
    } catch (Throwable $e) {}

    $updState = $master->prepare("UPDATE empresas SET estado = 'active', updated_at = NOW() WHERE id = ?");
    $updState->execute([$empresaIdProbe]);

    out("  [OK] Empresa creada en core_master (id={$empresaIdProbe}) y BD provisionada ({$dbName})");
    return $empresaIdProbe;
}

function migrateUsers(PDO $source, PDO $master, PDO $tenantPdo, int $legacyTenantId, int $empresaId, bool $apply): array
{
    $result = ['seen' => 0, 'created_master' => 0, 'updated_master' => 0, 'skipped' => 0, 'synced_tenant' => 0];
    $stmt = $source->prepare("SELECT id, name, email, password, role, active FROM users WHERE tenant_id = ? ORDER BY id ASC");
    $stmt->execute([$legacyTenantId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result['seen']++;
        $email = trim((string)($row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['skipped']++;
            continue;
        }

        $name = (string)($row['name'] ?? 'Usuario');
        $role = (string)($row['role'] ?? 'user');
        $active = (int)($row['active'] ?? 1) === 1 ? 1 : 0;
        $hash = (string)($row['password'] ?? '');
        if ($hash === '') {
            $result['skipped']++;
            continue;
        }

        $existing = DatabaseManager::getUsuarioByEmail($email);
        $masterUser = null;

        if (!$existing) {
            if ($apply) {
                DatabaseManager::createUsuarioMaster([
                    'empresa_id' => $empresaId,
                    'email' => $email,
                    'password_hash' => $hash,
                    'rol' => $role,
                    'nombre' => $name,
                    'activo' => $active,
                ]);
            }
            $result['created_master']++;
            $masterUser = [
                'empresa_id' => $empresaId,
                'email' => $email,
                'password_hash' => $hash,
                'rol' => $role,
                'nombre' => $name,
                'activo' => $active,
            ];
        } else {
            if ((int)($existing['empresa_id'] ?? 0) !== $empresaId) {
                $result['skipped']++;
                continue;
            }
            if ($apply) {
                $upd = $master->prepare("UPDATE usuarios_master SET nombre = ?, rol = ?, activo = ?, password_hash = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$name, $role, $active, $hash, (int)$existing['id']]);
            }
            $result['updated_master']++;
            $masterUser = [
                'id' => (int)$existing['id'],
                'empresa_id' => $empresaId,
                'email' => $email,
                'password_hash' => $hash,
                'rol' => $role,
                'nombre' => $name,
                'activo' => $active,
            ];
        }

        if ($apply && is_array($masterUser)) {
            TenantManager::ensureTenantUser($tenantPdo, $masterUser);
        }
        $result['synced_tenant']++;
    }

    return $result;
}

function migrateTenantTables(PDO $source, PDO $tenantPdo, int $legacyTenantId, bool $apply): array
{
    $skip = [
        'tenants', 'users', 'saas_licenses', 'saas_users_lookup',
        'signup_attempts', 'login_attempts',
    ];

    $tablesStmt = $source->query("
        SELECT DISTINCT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'tenant_id'
        ORDER BY TABLE_NAME
    ");
    $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $summary = [];
    if ($apply) {
        $tenantPdo->exec("SET FOREIGN_KEY_CHECKS=0");
    }

    try {
        foreach ($tables as $table) {
            $table = (string)$table;
            if ($table === '' || in_array($table, $skip, true)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }

            $sourceCols = getColumns($source, $table);
            $targetCols = getColumns($tenantPdo, $table);
            if (empty($sourceCols) || empty($targetCols) || !in_array('tenant_id', $targetCols, true)) {
                continue;
            }

            $common = array_values(array_intersect($sourceCols, $targetCols));
            if (empty($common)) {
                continue;
            }

            $selectCols = implode(', ', array_map('qid', $common));
            $srcCountStmt = $source->prepare('SELECT COUNT(*) FROM ' . qid($table) . ' WHERE tenant_id = ?');
            $srcCountStmt->execute([$legacyTenantId]);
            $sourceCount = (int)$srcCountStmt->fetchColumn();
            if ($sourceCount <= 0) {
                continue;
            }

            $inserted = 0;
            if ($apply) {
                $tenantPdo->prepare('DELETE FROM ' . qid($table) . ' WHERE tenant_id = 1')->execute();
                $sel = $source->prepare('SELECT ' . $selectCols . ' FROM ' . qid($table) . ' WHERE tenant_id = ?');
                $sel->execute([$legacyTenantId]);

                $ph = implode(', ', array_fill(0, count($common), '?'));
                $ins = $tenantPdo->prepare('INSERT INTO ' . qid($table) . ' (' . $selectCols . ') VALUES (' . $ph . ')');

                while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
                    if (array_key_exists('tenant_id', $row)) {
                        $row['tenant_id'] = 1;
                    }
                    $vals = [];
                    foreach ($common as $c) {
                        $vals[] = $row[$c] ?? null;
                    }
                    $ins->execute($vals);
                    $inserted++;
                }
            } else {
                $inserted = $sourceCount;
            }

            $summary[] = [
                'table' => $table,
                'source_rows' => $sourceCount,
                'migrated_rows' => $inserted,
            ];
        }
    } finally {
        if ($apply) {
            $tenantPdo->exec("SET FOREIGN_KEY_CHECKS=1");
        }
    }

    return $summary;
}

$apply = hasFlag($argv, 'apply');
$autoBootstrap = hasFlag($argv, 'auto-bootstrap-empresas');
$tenantFilter = parseTenantFilter(argValue($argv, 'tenant', ''));
$source = connectSourceFromEnv($argv);
$master = DatabaseManager::master();

out('=== Migracion Single DB -> Per Database ===');
out('Modo: ' . ($apply ? 'APPLY (escribe cambios)' : 'DRY-RUN (solo simulacion)'));
out('Auto-bootstrap empresas: ' . ($autoBootstrap ? 'ON' : 'OFF'));
out('Fuente: ' . ($source->query('SELECT DATABASE()')->fetchColumn() ?: 'desconocida'));
out('Master: ' . ($master->query('SELECT DATABASE()')->fetchColumn() ?: 'desconocida'));

if ($apply) {
    $masterKey = getenv('MASTER_DB_KEY');
    if (!is_string($masterKey) || trim($masterKey) === '') {
        out('ERROR: Falta MASTER_DB_KEY para modo --apply.');
        exit(2);
    }
}

$tenantSql = "SELECT id, company_name, status, slug, created_at FROM tenants ORDER BY id ASC";
$allLegacy = $source->query($tenantSql)->fetchAll(PDO::FETCH_ASSOC);

if (!empty($tenantFilter)) {
    $allLegacy = array_values(array_filter($allLegacy, function ($r) use ($tenantFilter) {
        return in_array((int)($r['id'] ?? 0), $tenantFilter, true);
    }));
}

if (empty($allLegacy)) {
    out('No hay tenants para procesar.');
    exit(0);
}

$global = [
    'tenants_total' => count($allLegacy),
    'tenants_migrated' => 0,
    'tenants_skipped' => 0,
    'rows_total' => 0,
    'users_seen' => 0,
    'users_created_master' => 0,
    'users_updated_master' => 0,
    'users_skipped' => 0,
];

foreach ($allLegacy as $legacy) {
    $legacyId = (int)($legacy['id'] ?? 0);
    $legacyName = (string)($legacy['company_name'] ?? '');
    if ($legacyId <= 0) {
        continue;
    }

    $empresaId = resolveEmpresaId($master, $legacy);
    if (!$empresaId) {
        if ($autoBootstrap) {
            try {
                $empresaId = bootstrapEmpresaFromLegacy($master, $legacy, $apply, $argv);
            } catch (Throwable $e) {
                $global['tenants_skipped']++;
                out("[SKIP] Tenant {$legacyId} ({$legacyName}): fallo bootstrap: " . $e->getMessage());
                continue;
            }
        }
    }
    if (!$empresaId) {
        $global['tenants_skipped']++;
        out("[SKIP] Tenant {$legacyId} ({$legacyName}): no se encontro empresa destino en core_master.");
        continue;
    }

    try {
        $tenantPdo = DatabaseManager::tenant((int)$empresaId);
    } catch (Throwable $e) {
        $global['tenants_skipped']++;
        out("[SKIP] Tenant {$legacyId} ({$legacyName}): sin conexion a BD destino de empresa {$empresaId}. Motivo: " . $e->getMessage());
        out("       Sugerencia: verifica MASTER_DB_KEY (persistente), credenciales cifradas de empresas y permisos SQL.");
        continue;
    }

    out("[TENANT] {$legacyId} ({$legacyName}) -> empresa {$empresaId}");

    try {
        $usersSummary = migrateUsers($source, $master, $tenantPdo, $legacyId, (int)$empresaId, $apply);
        $tableSummary = migrateTenantTables($source, $tenantPdo, $legacyId, $apply);
    } catch (Throwable $e) {
        $global['tenants_skipped']++;
        out("  [ERROR] " . $e->getMessage());
        continue;
    }

    $rowsMigrated = 0;
    foreach ($tableSummary as $t) {
        $rowsMigrated += (int)$t['migrated_rows'];
    }

    $global['tenants_migrated']++;
    $global['rows_total'] += $rowsMigrated;
    $global['users_seen'] += (int)$usersSummary['seen'];
    $global['users_created_master'] += (int)$usersSummary['created_master'];
    $global['users_updated_master'] += (int)$usersSummary['updated_master'];
    $global['users_skipped'] += (int)$usersSummary['skipped'];

    out("  Tablas migradas: " . count($tableSummary) . " | Filas: {$rowsMigrated}");
    out("  Usuarios: vistos {$usersSummary['seen']}, creados master {$usersSummary['created_master']}, actualizados master {$usersSummary['updated_master']}, omitidos {$usersSummary['skipped']}");
}

out('--- Resumen ---');
out('Tenants procesados: ' . $global['tenants_total']);
out('Tenants migrados: ' . $global['tenants_migrated']);
out('Tenants omitidos: ' . $global['tenants_skipped']);
out('Filas migradas: ' . $global['rows_total']);
out('Usuarios vistos: ' . $global['users_seen']);
out('Usuarios creados en master: ' . $global['users_created_master']);
out('Usuarios actualizados en master: ' . $global['users_updated_master']);
out('Usuarios omitidos: ' . $global['users_skipped']);
out('Estado final: OK');



