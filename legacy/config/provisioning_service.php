<?php

require_once __DIR__ . '/database_manager.php';
require_once __DIR__ . '/sql_importer.php';

$perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();

final class ProvisioningService
{
    public static function provisionFromMasterLicense(string $licenseCode, string $companyName, string $adminName, string $adminEmail, string $adminPassword): array
    {
        $licenseCode = strtoupper(trim($licenseCode));
        $companyName = trim($companyName);
        $adminEmail = trim($adminEmail);
        $adminName = trim($adminName);

        if ($companyName === '' || $adminEmail === '' || $adminName === '' || $adminPassword === '' || $licenseCode === '') {
            throw new RuntimeException('Datos incompletos.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Formato de email inválido.');
        }
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $licenseCode)) {
            throw new RuntimeException('Formato de licencia inválido.');
        }

        $master = DatabaseManager::master();
        $master->beginTransaction();
        $poolId = null;

        try {
            $lic = self::getLicenseForProvisioning($master, $licenseCode);
            if (!$lic) {
                throw new RuntimeException('El código de licencia es inválido o ya fue utilizado.');
            }

            $existing = DatabaseManager::getUsuarioByEmail($adminEmail);
            if ($existing) {
                throw new RuntimeException('El email ya está registrado en otra empresa.');
            }

            $tmpDbName = 'pending_' . bin2hex(random_bytes(8));
            $tmp = Crypto::encrypt('tmp');

            $empresaId = DatabaseManager::createEmpresa([
                'nombre' => $companyName,
                'estado' => 'provisioning',
                'db_host' => self::env('TENANT_DB_HOST', DatabaseManager::mode() === 'per_database' ? self::env('MASTER_DB_HOST', 'localhost') : self::env('MASTER_DB_HOST', 'localhost')),
                'db_port' => (int)self::env('TENANT_DB_PORT', self::env('MASTER_DB_PORT', '3306')),
                'db_name' => $tmpDbName,
                'db_user' => 'pending',
                'db_password_enc' => $tmp['enc'],
                'db_password_iv' => $tmp['iv'],
                'db_password_tag' => $tmp['tag'],
                'schema_version' => 1,
            ]);

            $usePool = self::shouldUsePoolProvisioning();
            $tenantDbName = '';
            $tenantDbUser = '';
            $tenantDbPass = '';
            $enc = null;
            $host = self::env('TENANT_DB_HOST', self::env('MASTER_DB_HOST', 'localhost'));
            $port = (int)self::env('TENANT_DB_PORT', self::env('MASTER_DB_PORT', '3306'));

            if ($usePool) {
                $pool = self::reservePoolDatabase($master, $empresaId);
                $poolId = (int)($pool['id'] ?? 0);
                $tenantDbName = (string)($pool['db_name'] ?? '');
                $tenantDbUser = (string)($pool['db_user'] ?? '');
                $host = (string)($pool['db_host'] ?? $host);
                $port = (int)($pool['db_port'] ?? $port);
                $tenantDbPass = Crypto::decrypt(
                    (string)($pool['db_password_enc'] ?? ''),
                    (string)($pool['db_password_iv'] ?? ''),
                    (string)($pool['db_password_tag'] ?? '')
                );
                $enc = [
                    'enc' => (string)($pool['db_password_enc'] ?? ''),
                    'iv' => (string)($pool['db_password_iv'] ?? ''),
                    'tag' => (string)($pool['db_password_tag'] ?? '')
                ];
            } else {
                $tenantDbPrefix = self::env('TENANT_DB_PREFIX', 'core_tenant_');
                $tenantDbName = $tenantDbPrefix . str_pad((string)$empresaId, 6, '0', STR_PAD_LEFT);

                $tenantUserPrefix = self::env('TENANT_DB_USER_PREFIX', 'core_u_');
                $tenantDbUser = $tenantUserPrefix . str_pad((string)$empresaId, 6, '0', STR_PAD_LEFT);

                $tenantDbPass = self::randomPassword(32);
                $enc = Crypto::encrypt($tenantDbPass);
            }

            $upd = $master->prepare('
                UPDATE empresas
                SET db_host = ?, db_port = ?, db_name = ?, db_user = ?, db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([
                $host,
                $port,
                $tenantDbName,
                $tenantDbUser,
                (string)($enc['enc'] ?? ''),
                (string)($enc['iv'] ?? ''),
                (string)($enc['tag'] ?? ''),
                $empresaId
            ]);

            if (!$usePool) {
                self::createTenantDatabaseAndUser($tenantDbName, $tenantDbUser, $tenantDbPass);
            }

            $tenantPdo = self::connectTenantCustom($host, $port, $tenantDbName, $tenantDbUser, $tenantDbPass);
            self::initializeTenantSchema($tenantPdo);
            self::initializeTenantIdentity($tenantPdo, $companyName);

            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $masterUserId = DatabaseManager::createUsuarioMaster([
                'empresa_id' => $empresaId,
                'email' => $adminEmail,
                'password_hash' => $passwordHash,
                'rol' => 'admin',
                'nombre' => $adminName,
                'activo' => 1,
            ]);

            self::upsertTenantAdminUser($tenantPdo, $adminName, $adminEmail, $passwordHash);

            if ($usePool && $poolId > 0) {
                self::markPoolUsed($master, $poolId, $empresaId);
            }

            $mark = $master->prepare("UPDATE licencias SET estado = 'usada', empresa_id = ?, used_at = NOW(), updated_at = NOW() WHERE id = ?");
            $mark->execute([$empresaId, (int)$lic['id']]);

            $activate = $master->prepare("UPDATE empresas SET estado = 'active', updated_at = NOW() WHERE id = ?");
            $activate->execute([$empresaId]);

            $master->commit();

            self::ensureEmpresaStorage($empresaId);

            return [
                'empresa_id' => $empresaId,
                'master_user_id' => $masterUserId,
                'db_name' => $tenantDbName,
            ];
        } catch (Throwable $e) {
            if ($master->inTransaction()) {
                $master->rollBack();
            }
            if ($poolId) {
                try {
                    self::markPoolError($poolId, (string)$e->getMessage());
                } catch (Throwable $e2) {
                }
            }
            throw $e;
        }
    }

    private static function getLicenseForProvisioning(PDO $master, string $code): ?array
    {
        $stmt = $master->prepare("SELECT * FROM licencias WHERE codigo = ? AND estado = 'disponible' LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function createTenantDatabaseAndUser(string $dbName, string $dbUser, string $dbPass): void
    {
        $admin = self::adminPdo();

        $dbNameEsc = str_replace('`', '``', $dbName);
        $admin->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");

        $userHost = self::env('TENANT_DB_USER_HOST', 'localhost');
        $userHostEsc = str_replace("'", "''", $userHost);
        $dbUserEsc = str_replace("'", "''", $dbUser);
        $dbPassEsc = str_replace("'", "''", $dbPass);

        $admin->exec("CREATE USER IF NOT EXISTS '{$dbUserEsc}'@'{$userHostEsc}' IDENTIFIED BY '{$dbPassEsc}'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$dbNameEsc}`.* TO '{$dbUserEsc}'@'{$userHostEsc}'");
        $admin->exec("FLUSH PRIVILEGES");
    }

    private static function shouldUsePoolProvisioning(): bool
    {
        $mode = getenv('TENANT_PROVISION_MODE');
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        if ($mode === 'pool' || $mode === 'hostinger_pool' || $mode === 'shared') {
            return true;
        }

        $adminUser = getenv('PROVISION_DB_ADMIN_USER');
        $adminUser = is_string($adminUser) ? trim($adminUser) : '';
        return $adminUser === '';
    }

    private static function reservePoolDatabase(PDO $master, int $empresaId): array
    {
        $stmt = $master->query("SELECT * FROM tenant_db_pool WHERE status = 'available' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!is_array($row)) {
            throw new RuntimeException('No hay bases de datos disponibles. Intenta más tarde.');
        }
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('No hay bases de datos disponibles. Intenta más tarde.');
        }

        $upd = $master->prepare("UPDATE tenant_db_pool SET status = 'reserved', empresa_id = ?, reserved_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'available'");
        $upd->execute([$empresaId, $id]);
        if ($upd->rowCount() <= 0) {
            throw new RuntimeException('No se pudo reservar una base de datos. Intenta de nuevo.');
        }

        return $row;
    }

    private static function markPoolUsed(PDO $master, int $poolId, int $empresaId): void
    {
        $upd = $master->prepare("UPDATE tenant_db_pool SET status = 'used', empresa_id = ?, used_at = NOW(), updated_at = NOW() WHERE id = ?");
        $upd->execute([$empresaId, $poolId]);
    }

    private static function markPoolError(int $poolId, string $error): void
    {
        $master = DatabaseManager::master();
        $stmt = $master->prepare("UPDATE tenant_db_pool SET status = 'error', empresa_id = NULL, reserved_at = NULL, used_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ? AND status <> 'used'");
        $stmt->execute([$error, $poolId]);
    }

    private static function initializeTenantSchema(PDO $tenantPdo): void
    {
        $base = realpath(__DIR__ . '/../saas/template_clean.sql');
        $single = realpath(__DIR__ . '/../saas/migration_single_db.sql');
        $multi = realpath(__DIR__ . '/../saas/migration_tables.sql');

        if (!is_string($base) || !is_string($single) || !is_string($multi)) {
            throw new RuntimeException('No se encontró el esquema base.');
        }

        SqlImporter::importFile($tenantPdo, $base);
        $mode = DatabaseManager::mode();
        $isPerDb = ($mode === 'per_database' || $mode === 'per-db' || $mode === 'perdb');
        if ($isPerDb) {
            try {
                self::seedFromTemplateTenant($tenantPdo);
            } catch (Throwable $e) {
            }
        }
        if (!$isPerDb) {
            try {
                $hasLegacy = $tenantPdo->query("SHOW TABLES LIKE 'saas_tenants'");
                $hasLegacy = ($hasLegacy && $hasLegacy->fetchColumn());
                if ($hasLegacy) {
                    SqlImporter::importFile($tenantPdo, $single);
                }
            } catch (Throwable $e) {
            }
            SqlImporter::importFile($tenantPdo, $multi);
        }

        try {
            $c = $tenantPdo->query("SHOW COLUMNS FROM work_orders LIKE 'client_observations'");
            if (!$c || $c->rowCount() === 0) {
                $tenantPdo->exec("ALTER TABLE work_orders ADD COLUMN client_observations TEXT NULL AFTER reported_issue");
            }
        } catch (Throwable $e) {
        }
    }

    private static function seedFromTemplateTenant(PDO $tenantPdo): void
    {
        $mode = DatabaseManager::mode();
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        $isPerDb = ($mode === 'per_database' || $mode === 'per-db' || $mode === 'perdb');
        if (!$isPerDb) {
            return;
        }

        $tplDb = getenv('TENANT_TEMPLATE_DB');
        $tplDb = is_string($tplDb) ? trim($tplDb) : '';
        if ($tplDb === '') {
            $prefix = self::env('TENANT_DB_PREFIX', 'core_tenant_');
            $tplDb = $prefix . '000001';
        }

        try {
            $src = self::adminDbPdo($tplDb);
        } catch (Throwable $e) {
            return;
        }

        $tables = [
            'system_config',
            'order_statuses',
            'payment_methods',
            'brands',
            'device_types',
            'models',
            'problem_types',
            'accessories',
            'document_templates',
        ];

        $tableColumns = function (PDO $pdo, string $table): array {
            try {
                $t = str_replace('`', '``', $table);
                $cols = $pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $out = [];
                foreach ($cols as $c) {
                    if (!empty($c['Field'])) {
                        $out[] = (string)$c['Field'];
                    }
                }
                return $out;
            } catch (Throwable $e) {
                return [];
            }
        };

        $hasCol = function (PDO $pdo, string $table, string $col) use ($tableColumns): bool {
            $cols = $tableColumns($pdo, $table);
            return in_array($col, $cols, true);
        };

        try { $tenantPdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) {}

        foreach ($tables as $table) {
            $dstCols = $tableColumns($tenantPdo, $table);
            $srcCols = $tableColumns($src, $table);
            if (!$dstCols || !$srcCols) {
                continue;
            }

            $common = array_values(array_intersect($dstCols, $srcCols));
            if (!$common) {
                continue;
            }

            $dstHasTenant = in_array('tenant_id', $dstCols, true);
            $srcHasTenant = in_array('tenant_id', $srcCols, true);

            try {
                $t = str_replace('`', '``', $table);
                if ($dstHasTenant) {
                    $stmtDel = $tenantPdo->prepare("DELETE FROM `{$t}` WHERE tenant_id = 1");
                    $stmtDel->execute();
                } else {
                    $tenantPdo->exec("DELETE FROM `{$t}`");
                }
            } catch (Throwable $e) {
                continue;
            }

            try {
                $t = str_replace('`', '``', $table);
                $rows = $src->query("SELECT * FROM `{$t}`" . ($srcHasTenant ? " WHERE tenant_id = 1" : ""))->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!$rows) {
                    continue;
                }

                $colsSql = implode(',', array_map(static function ($c) {
                    return '`' . str_replace('`', '``', (string)$c) . '`';
                }, $common));
                $ph = implode(',', array_fill(0, count($common), '?'));
                $ins = $tenantPdo->prepare("INSERT INTO `{$t}` ({$colsSql}) VALUES ({$ph})");
                $tenantIdIndex = array_search('tenant_id', $common, true);
                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($common as $c) {
                        $vals[] = $r[$c] ?? null;
                    }
                    if ($tenantIdIndex !== false) {
                        $vals[(int)$tenantIdIndex] = 1;
                    }
                    $ins->execute($vals);
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        try { $tenantPdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
    }

    private static function initializeTenantIdentity(PDO $tenantPdo, string $companyName): void
    {
        try {
            $stmt = $tenantPdo->prepare("UPDATE tenants SET company_name = ?, slug = 'default', status = 'active', updated_at = NOW() WHERE id = 1");
            $stmt->execute([$companyName]);
        } catch (Throwable $e) {
        }

        try {
            $stmt = $tenantPdo->prepare("INSERT INTO tenants (id, company_name, slug, status, created_at) VALUES (1, ?, 'default', 'active', NOW()) ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), status = 'active', updated_at = NOW()");
            $stmt->execute([$companyName]);
        } catch (Throwable $e) {
        }

        try {
            $stmt = $tenantPdo->prepare("UPDATE company_settings SET company_name = ?, company_email = ? WHERE id = 1");
            $stmt->execute([$companyName, '']);
        } catch (Throwable $e) {
        }

        try {
            $stmt = $tenantPdo->prepare("UPDATE company_config SET company_name = ? WHERE id = 1");
            $stmt->execute([$companyName]);
        } catch (Throwable $e) {
        }
    }

    private static function upsertTenantAdminUser(PDO $tenantPdo, string $name, string $email, string $passwordHash): void
    {
        try {
            $stmt = $tenantPdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $id = $stmt->fetchColumn();
            if ($id) {
                $upd = $tenantPdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', active = 1, updated_at = NOW() WHERE id = ?");
                $upd->execute([$name, $passwordHash, (int)$id]);
                return;
            }
        } catch (Throwable $e) {
        }

        try {
            $ins = $tenantPdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (1, ?, ?, ?, 'admin', 1, NOW())");
            $ins->execute([$name, $email, $passwordHash]);
            return;
        } catch (Throwable $e) {
        }

        $ins = $tenantPdo->prepare("INSERT INTO users (name, email, password, role, active, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())");
        $ins->execute([$name, $email, $passwordHash]);
    }

    private static function connectTenant(string $dbName, string $dbUser, string $dbPass): PDO
    {
        $host = self::env('TENANT_DB_HOST', self::env('MASTER_DB_HOST', 'localhost'));
        $port = (int)self::env('TENANT_DB_PORT', self::env('MASTER_DB_PORT', '3306'));
        return self::connectTenantCustom($host, $port, $dbName, $dbUser, $dbPass);
    }

    private static function connectTenantCustom(string $host, int $port, string $dbName, string $dbUser, string $dbPass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
        ];
        return new PDO($dsn, $dbUser, $dbPass, $options);
    }

    private static function adminPdo(): PDO
    {
        $host = self::env('TENANT_DB_HOST', self::env('MASTER_DB_HOST', 'localhost'));
        $port = (int)self::env('TENANT_DB_PORT', self::env('MASTER_DB_PORT', '3306'));
        $user = getenv('PROVISION_DB_ADMIN_USER');
        $pass = getenv('PROVISION_DB_ADMIN_PASS');

        if (!is_string($user) || trim($user) === '') {
            throw new RuntimeException('Falta configuración de aprovisionamiento.');
        }
        $user = trim($user);
        $pass = is_string($pass) ? $pass : '';

        $dsn = "mysql:host={$host};port={$port};dbname=mysql;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
        ];
        return new PDO($dsn, $user, $pass, $options);
    }

    private static function adminDbPdo(string $dbName): PDO
    {
        $host = self::env('TENANT_DB_HOST', self::env('MASTER_DB_HOST', 'localhost'));
        $port = (int)self::env('TENANT_DB_PORT', self::env('MASTER_DB_PORT', '3306'));
        $user = getenv('PROVISION_DB_ADMIN_USER');
        $pass = getenv('PROVISION_DB_ADMIN_PASS');

        if (!is_string($user) || trim($user) === '') {
            throw new RuntimeException('Falta configuración de aprovisionamiento.');
        }
        $user = trim($user);
        $pass = is_string($pass) ? $pass : '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
        ];
        return new PDO($dsn, $user, $pass, $options);
    }

    private static function ensureEmpresaStorage(int $empresaId): void
    {
        $base = realpath(__DIR__ . '/..');
        if (!is_string($base)) {
            return;
        }
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'empresas' . DIRECTORY_SEPARATOR . $empresaId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        foreach (['logos', 'brands', 'orders'] as $sub) {
            $p = $dir . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($p)) {
                @mkdir($p, 0777, true);
            }
        }
    }

    private static function randomPassword(int $length): string
    {
        $bytes = random_bytes((int)ceil($length * 0.75));
        $b64 = rtrim(strtr(base64_encode($bytes), '+/', 'Aa'), '=');
        return substr($b64, 0, $length);
    }

    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        if (!is_string($v) || $v === '') {
            return $default;
        }
        return $v;
    }
}

