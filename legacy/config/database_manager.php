<?php

require_once __DIR__ . '/crypto.php';

final class DatabaseManager
{
    private static ?PDO $masterPdo = null;
    private static ?PDO $tenantPdo = null;
    private static ?int $tenantEmpresaId = null;

    public static function mode(): string
    {
        $mode = getenv('SAAS_DB_MODE');
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        if ($mode === 'per_database' || $mode === 'per-db' || $mode === 'perdb') {
            return 'per_database';
        }
        return 'legacy_single_db';
    }

    public static function master(): PDO
    {
        if (self::$masterPdo instanceof PDO) {
            return self::$masterPdo;
        }

        $host = self::env('MASTER_DB_HOST', 'localhost');
        $port = (int)self::env('MASTER_DB_PORT', '3306');
        $db = self::env('MASTER_DB_NAME', 'core_master');
        $user = self::env('MASTER_DB_USER', 'root');
        $pass = self::env('MASTER_DB_PASS', '');

        try {
            $pdo = self::connect($host, $port, $db, $user, $pass);
        } catch (Throwable $e) {
            // Primera ejecución: crear core_master automáticamente si no existe
            $serverPdo = self::connectServer($host, $port, $user, $pass);
            $dbEsc = str_replace('`', '``', $db);
            $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
            $pdo = self::connect($host, $port, $db, $user, $pass);
        }
        self::ensureMasterSchema($pdo);
        self::$masterPdo = $pdo;
        return $pdo;
    }

    public static function tenant(int $empresaId): PDO
    {
        if (self::$tenantPdo instanceof PDO && self::$tenantEmpresaId === $empresaId) {
            return self::$tenantPdo;
        }

        $empresa = self::getEmpresa($empresaId);
        if (!$empresa) {
            throw new RuntimeException('Empresa no encontrada.');
        }
        if (($empresa['estado'] ?? '') !== 'active') {
            throw new RuntimeException('Empresa inactiva.');
        }

        $host = (string)($empresa['db_host'] ?? '');
        $port = (int)($empresa['db_port'] ?? 3306);
        $db = (string)($empresa['db_name'] ?? '');
        $user = (string)($empresa['db_user'] ?? '');
        $enc = (string)($empresa['db_password_enc'] ?? '');
        $iv = (string)($empresa['db_password_iv'] ?? '');
        $tag = (string)($empresa['db_password_tag'] ?? '');

        if ($host === '' || $db === '' || $user === '' || $enc === '' || $iv === '' || $tag === '') {
            throw new RuntimeException('Configuración de base de datos incompleta.');
        }

        $pass = Crypto::decrypt($enc, $iv, $tag);
        $pdo = self::connect($host, $port, $db, $user, $pass);

        self::$tenantPdo = $pdo;
        self::$tenantEmpresaId = $empresaId;
        return $pdo;
    }

    public static function tenantFromSession(): ?PDO
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        if (!isset($_SESSION['empresa_id'])) {
            return null;
        }
        $empresaId = (int)$_SESSION['empresa_id'];
        if ($empresaId <= 0) {
            return null;
        }
        return self::tenant($empresaId);
    }

    public static function getEmpresa(int $empresaId): ?array
    {
        $pdo = self::master();
        $stmt = $pdo->prepare('SELECT * FROM empresas WHERE id = ? LIMIT 1');
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function getUsuarioByEmail(string $email): ?array
    {
        $pdo = self::master();
        $stmt = $pdo->prepare('SELECT * FROM usuarios_master WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function empresaHasLicense(int $empresaId): bool
    {
        if ($empresaId <= 0) {
            return false;
        }
        $pdo = self::master();
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM licencias WHERE empresa_id = ? AND estado = 'usada'");
            $stmt->execute([$empresaId]);
            return (int)($stmt->fetchColumn() ?: 0) > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    public static function createEmpresa(array $data): int
    {
        $pdo = self::master();
        $stmt = $pdo->prepare('
            INSERT INTO empresas (nombre, estado, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, schema_version, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $stmt->execute([
            (string)($data['nombre'] ?? ''),
            (string)($data['estado'] ?? 'active'),
            (string)($data['db_host'] ?? ''),
            (int)($data['db_port'] ?? 3306),
            (string)($data['db_name'] ?? ''),
            (string)($data['db_user'] ?? ''),
            (string)($data['db_password_enc'] ?? ''),
            (string)($data['db_password_iv'] ?? ''),
            (string)($data['db_password_tag'] ?? ''),
            (int)($data['schema_version'] ?? 1),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function createUsuarioMaster(array $data): int
    {
        $pdo = self::master();
        $stmt = $pdo->prepare('
            INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $stmt->execute([
            (int)($data['empresa_id'] ?? 0),
            (string)($data['email'] ?? ''),
            (string)($data['password_hash'] ?? ''),
            (string)($data['rol'] ?? 'admin'),
            (string)($data['nombre'] ?? ''),
            (int)($data['activo'] ?? 1),
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function connect(string $host, int $port, string $db, string $user, string $pass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
        ];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
                $pdo->query('SELECT 1');
                return $pdo;
            } catch (PDOException $e) {
                $code = (string)$e->getCode();
                $m = strtolower((string)$e->getMessage());
                $transient = in_array($code, ['1040', '2002', '2006'], true)
                    || str_contains($m, 'too many connections')
                    || str_contains($m, 'server has gone away')
                    || str_contains($m, 'connection refused')
                    || str_contains($m, 'can\'t connect')
                    || str_contains($m, 'cannot connect');
                if ($attempt === 0 && $transient) {
                    usleep(200000);
                    continue;
                }
                throw $e;
            }
        }
        throw new RuntimeException('No se pudo establecer conexión a la base de datos.');
    }

    private static function connectServer(string $host, int $port, string $user, string $pass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci",
        ];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
                $pdo->query('SELECT 1');
                return $pdo;
            } catch (PDOException $e) {
                $code = (string)$e->getCode();
                $m = strtolower((string)$e->getMessage());
                $transient = in_array($code, ['1040', '2002', '2006'], true)
                    || str_contains($m, 'too many connections')
                    || str_contains($m, 'server has gone away')
                    || str_contains($m, 'connection refused')
                    || str_contains($m, 'can\'t connect')
                    || str_contains($m, 'cannot connect');
                if ($attempt === 0 && $transient) {
                    usleep(200000);
                    continue;
                }
                throw $e;
            }
        }
        throw new RuntimeException('No se pudo establecer conexión a la base de datos.');
    }

    private static function ensureMasterSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS empresas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(255) NOT NULL,
                estado ENUM('active','suspended','deleted','provisioning') NOT NULL DEFAULT 'active',
                db_host VARCHAR(255) NOT NULL,
                db_port INT UNSIGNED NOT NULL DEFAULT 3306,
                db_name VARCHAR(255) NOT NULL,
                db_user VARCHAR(255) NOT NULL,
                db_password_enc TEXT NOT NULL,
                db_password_iv VARCHAR(255) NOT NULL,
                db_password_tag VARCHAR(255) NOT NULL,
                schema_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_empresas_db_name (db_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios_master (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                empresa_id INT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                rol VARCHAR(50) NOT NULL DEFAULT 'admin',
                nombre VARCHAR(255) NOT NULL DEFAULT '',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_usuarios_master_email (email),
                KEY idx_usuarios_master_empresa (empresa_id),
                CONSTRAINT fk_usuarios_master_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS licencias (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(64) NOT NULL,
                plan VARCHAR(50) NOT NULL DEFAULT 'standard',
                estado ENUM('disponible','usada','revocada') NOT NULL DEFAULT 'disponible',
                empresa_id INT UNSIGNED NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_licencias_codigo (codigo),
                KEY idx_licencias_empresa (empresa_id),
                CONSTRAINT fk_licencias_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_db_pool (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                db_host VARCHAR(255) NOT NULL,
                db_port INT UNSIGNED NOT NULL DEFAULT 3306,
                db_name VARCHAR(255) NOT NULL,
                db_user VARCHAR(255) NOT NULL,
                db_password_enc TEXT NOT NULL,
                db_password_iv VARCHAR(255) NOT NULL,
                db_password_tag VARCHAR(255) NOT NULL,
                status ENUM('available','reserved','used','error') NOT NULL DEFAULT 'available',
                empresa_id INT UNSIGNED NULL,
                reserved_at DATETIME NULL,
                used_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tenant_db_pool_db_name (db_name),
                KEY idx_tenant_db_pool_status (status),
                KEY idx_tenant_db_pool_empresa (empresa_id),
                CONSTRAINT fk_tenant_db_pool_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
        ");
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

