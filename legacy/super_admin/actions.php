<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$action = $_REQUEST['action'] ?? '';

function collectDirAudit($path) {
    $result = [
        'path' => $path,
        'exists' => false,
        'files' => 0,
        'dirs' => 0,
        'bytes' => 0
    ];
    $p = rtrim((string)$path, '/\\');
    if ($p === '' || !file_exists($p)) { return $result; }
    $result['exists'] = true;
    if (is_file($p)) {
        $result['files'] = 1;
        $result['bytes'] = (int)@filesize($p);
        return $result;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $node) {
            if ($node->isDir()) { $result['dirs']++; }
            else { $result['files']++; $result['bytes'] += (int)$node->getSize(); }
        }
    } catch (Throwable $e) {}
    return $result;
}

function generateSaasLicenseCode() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8, 4);
}

if (!isPerDatabaseMode()) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
            id INT NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(255) NOT NULL,
            slug VARCHAR(64) NOT NULL,
            status ENUM('active','suspended') DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_users_lookup (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            tenant_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tenant_id (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_licenses (
            id INT NOT NULL AUTO_INCREMENT,
            license_code VARCHAR(50) NOT NULL UNIQUE,
            status ENUM('active','used','expired') DEFAULT 'active',
            license_type ENUM('standard','trial') NOT NULL DEFAULT 'standard',
            tenant_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL DEFAULT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY tenant_id (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM saas_licenses")->fetchAll(PDO::FETCH_COLUMN);
            if ($cols && !in_array('license_type', array_map('strtolower', $cols))) {
                $pdo->exec("ALTER TABLE saas_licenses ADD COLUMN license_type ENUM('standard','trial') NOT NULL DEFAULT 'standard' AFTER status");
            }
            if ($cols && !in_array('expires_at', array_map('strtolower', $cols))) {
                $pdo->exec("ALTER TABLE saas_licenses ADD COLUMN expires_at DATETIME NULL DEFAULT NULL AFTER used_at");
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $sessionCsrf = $_SESSION['csrf_token_sa'] ?? '';
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'cleanup_tenant_residue') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) { throw new Exception('ID de empresa inválido'); }

            $preserveTables = [
                'tenants',
                'users',
                'company_config',
                'company_settings',
                'system_config',
                'order_statuses',
                'brands',
                'device_types',
                'models',
                'problem_types',
                'accessories',
                'equipment_accessories',
                'payment_methods',
                'payment_method_accounts',
                'saas_licenses',
                'saas_users_lookup'
            ];

            $deletedRowsTotal = 0;
            $tablesAffected = [];
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $row = $master->prepare("SELECT id FROM empresas WHERE id = ? LIMIT 1");
                $row->execute([$tenantId]);
                if (!$row->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('Empresa no encontrada');
                }

                $tenantPdo = DatabaseManager::tenant($tenantId);
                $tenantPdo->beginTransaction();
                $fkOff = false;
                try {
                    $tenantPdo->exec("SET FOREIGN_KEY_CHECKS=0");
                    $fkOff = true;

                    $tables = $tenantPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($tables as $t) {
                        $table = (string)$t;
                        if ($table === '' || in_array($table, $preserveTables, true)) { continue; }
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
                        try {
                            $cntStmt = $tenantPdo->query("SELECT COUNT(*) FROM `$table`");
                            $cnt = (int)($cntStmt ? $cntStmt->fetchColumn() : 0);
                            if ($cnt <= 0) { continue; }
                            $delStmt = $tenantPdo->prepare("DELETE FROM `$table`");
                            $delStmt->execute();
                            $deletedRowsTotal += $cnt;
                            $tablesAffected[] = ['table' => $table, 'rows' => $cnt];
                        } catch (Throwable $e) {}
                    }

                    if ($fkOff) {
                        $tenantPdo->exec("SET FOREIGN_KEY_CHECKS=1");
                        $fkOff = false;
                    }
                    $tenantPdo->commit();
                } catch (Throwable $e) {
                    if ($fkOff) {
                        try { $tenantPdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $__) {}
                    }
                    if ($tenantPdo->inTransaction()) { $tenantPdo->rollBack(); }
                    throw $e;
                }
            } else {
                $row = $pdo->prepare("SELECT id FROM tenants WHERE id = ? LIMIT 1");
                $row->execute([$tenantId]);
                if (!$row->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('Empresa no encontrada');
                }

                $pdo->beginTransaction();
                $fkOff = false;
                try {
                    $stmtT = $pdo->prepare("
                        SELECT DISTINCT TABLE_NAME
                        FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND COLUMN_NAME = 'tenant_id'
                        ORDER BY TABLE_NAME
                    ");
                    $stmtT->execute();
                    $tenantTables = $stmtT->fetchAll(PDO::FETCH_COLUMN) ?: [];

                    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                    $fkOff = true;

                    foreach ($tenantTables as $t) {
                        $table = (string)$t;
                        if ($table === '' || in_array($table, $preserveTables, true)) { continue; }
                        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
                        try {
                            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id = ?");
                            $cntStmt->execute([$tenantId]);
                            $cnt = (int)$cntStmt->fetchColumn();
                            if ($cnt <= 0) { continue; }
                            $delStmt = $pdo->prepare("DELETE FROM `$table` WHERE tenant_id = ?");
                            $delStmt->execute([$tenantId]);
                            $deletedRowsTotal += $cnt;
                            $tablesAffected[] = ['table' => $table, 'rows' => $cnt];
                        } catch (Throwable $e) {}
                    }

                    // Limpiar rastros comunes por tenant (si existen)
                    try {
                        $stmt = $pdo->prepare("DELETE FROM delete_queue WHERE tenant_id = ?");
                        $stmt->execute([$tenantId]);
                    } catch (Throwable $e) {}

                    if ($fkOff) {
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                        $fkOff = false;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($fkOff) {
                        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $__) {}
                    }
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    throw $e;
                }
            }

            // Limpieza de archivos operativos (preserva carpetas base y respaldos)
            $cleanupTargets = ['orders', 'reports', 'inventory', 'cash', 'temp', 'tmp'];
            try { cleanupTenantFiles($tenantId, $cleanupTargets); } catch (Throwable $e) {}
            $uploadsAudit = collectDirAudit(getTenantUploadsFsById($tenantId));
            $storageAudit = collectDirAudit(getTenantStorageFsById($tenantId));

            echo json_encode([
                'success' => true,
                'message' => 'Limpieza completada',
                'deleted_rows_total' => $deletedRowsTotal,
                'tables_affected' => $tablesAffected,
                'filesystem' => [
                    'uploads' => $uploadsAudit,
                    'storage' => $storageAudit
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'delete_license') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id <= 0) { throw new Exception('ID de licencia inválido'); }
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $stmt = $master->prepare("DELETE FROM licencias WHERE id = ? AND estado = 'disponible'");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM saas_licenses WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
            }
            header("Location: licenses.php?msg=deleted");
        } catch (Exception $e) {
            die("Error eliminando licencia: " . $e->getMessage());
        }
        exit;
    }

    if ($action === 'delete_tenant') {
        $tenantId = (int)($_POST['id'] ?? 0);
        try {
            if ($tenantId <= 0) { throw new Exception('ID de empresa inválido'); }
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $master->beginTransaction();
                try {
                    $empresaStmt = $master->prepare("SELECT id FROM empresas WHERE id = ? LIMIT 1");
                    $empresaStmt->execute([$tenantId]);
                    if (!$empresaStmt->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception('Empresa no encontrada');
                    }

                    $deactivateUsers = $master->prepare("UPDATE usuarios_master SET activo = 0, updated_at = NOW() WHERE empresa_id = ?");
                    $deactivateUsers->execute([$tenantId]);

                    $markEmpresa = $master->prepare("UPDATE empresas SET estado = 'deleted', updated_at = NOW() WHERE id = ?");
                    $markEmpresa->execute([$tenantId]);

                    $master->commit();
                } catch (Throwable $e) {
                    if ($master->inTransaction()) {
                        $master->rollBack();
                    }
                    throw $e;
                }

                try { deletePathRecursive(__DIR__ . '/../storage/empresas/' . $tenantId); } catch (Throwable $e) {}
                header("Location: index.php?msg=tenant_deleted");
                exit;
            }

            $tenantEmails = [];
            try {
                $stmtEmails = $pdo->prepare("SELECT email FROM users WHERE tenant_id = ? AND email IS NOT NULL AND email <> ''");
                $stmtEmails->execute([$tenantId]);
                $tenantEmails = array_values(array_unique(array_filter($stmtEmails->fetchAll(PDO::FETCH_COLUMN))));
            } catch (Throwable $e) {}
            
            $pdo->beginTransaction();
            $fkOff = false;
            try {
                $stmt = $pdo->prepare("UPDATE saas_licenses SET status = 'active', tenant_id = NULL, used_at = NULL WHERE tenant_id = ?");
                $stmt->execute([$tenantId]);

                $stmtT = $pdo->prepare("
                    SELECT DISTINCT TABLE_NAME
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND COLUMN_NAME = 'tenant_id'
                    ORDER BY TABLE_NAME
                ");
                $stmtT->execute();
                $tenantTables = $stmtT->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $skipTables = ['tenants', 'saas_licenses', 'saas_users_lookup'];

                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                $fkOff = true;
                foreach ($tenantTables as $t) {
                    $table = (string)$t;
                    if ($table === '' || in_array($table, $skipTables, true)) { continue; }
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
                    try {
                        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE tenant_id = ?");
                        $stmt->execute([$tenantId]);
                    } catch (Throwable $e) {}
                }

                $stmt = $pdo->prepare("DELETE FROM saas_users_lookup WHERE tenant_id = ?");
                $stmt->execute([$tenantId]);

                if (!empty($tenantEmails)) {
                    $ph = implode(',', array_fill(0, count($tenantEmails), '?'));
                    $delLa = $pdo->prepare("DELETE FROM login_attempts WHERE email IN ($ph)");
                    $delLa->execute($tenantEmails);
                }

                $stmt = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
                $stmt->execute([$tenantId]);

                if ($fkOff) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    $fkOff = false;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($fkOff) {
                    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $__) {}
                }
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }

            $uploadsTenant = getTenantUploadsFsById($tenantId);
            $storageTenant = getTenantStorageFsById($tenantId);
            $okUploads = deletePathRecursive($uploadsTenant);
            $okStorage = deletePathRecursive($storageTenant);
            if ((!$okUploads && file_exists(rtrim($uploadsTenant, '/\\'))) || (!$okStorage && file_exists(rtrim($storageTenant, '/\\')))) {
                throw new Exception('La empresa fue eliminada de BD, pero quedaron archivos residuales en disco. Revisa permisos del sistema.');
            }

            header("Location: index.php?msg=tenant_deleted");
        } catch (Exception $e) {
            die("Error eliminando empresa: " . $e->getMessage());
        }
        exit;
    }

    if ($action === 'delete_tenant_user') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        try {
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();

                $stmtUser = $master->prepare("SELECT id, email, rol, activo FROM usuarios_master WHERE id = ? AND empresa_id = ? LIMIT 1");
                $stmtUser->execute([$userId, $tenantId]);
                $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    throw new Exception('Usuario no encontrado');
                }

                if (($user['rol'] ?? '') === 'admin' && (int)($user['activo'] ?? 0) === 1) {
                    $stmtAdmins = $master->prepare("SELECT COUNT(*) FROM usuarios_master WHERE empresa_id = ? AND rol = 'admin' AND activo = 1");
                    $stmtAdmins->execute([$tenantId]);
                    $adminCount = (int)$stmtAdmins->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new Exception('No se puede eliminar el último administrador activo.');
                    }
                }

                $del = $master->prepare("DELETE FROM usuarios_master WHERE id = ? AND empresa_id = ?");
                $del->execute([$userId, $tenantId]);

                try {
                    $tenantPdo = DatabaseManager::tenant($tenantId);
                    $delTenant = $tenantPdo->prepare("DELETE FROM users WHERE email = ?");
                    $delTenant->execute([(string)($user['email'] ?? '')]);
                } catch (Throwable $e) {}

                header("Location: tenant_users.php?id=$tenantId&msg=user_deleted");
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$userId, $tenantId]);
            header("Location: tenant_users.php?id=$tenantId&msg=user_deleted");
        } catch (Exception $e) {
            header("Location: tenant_users.php?id=$tenantId&msg=error");
        }
        exit;
    }

    // 1. Create License
    if ($action === 'create_license') {
        try {
            $code = generateSaasLicenseCode();
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $stmt = $master->prepare("INSERT INTO licencias (codigo, plan, estado, created_at, updated_at) VALUES (?, 'standard', 'disponible', NOW(), NOW())");
                $stmt->execute([$code]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_licenses (license_code, status, created_at) VALUES (?, 'active', NOW())");
                $stmt->execute([$code]);
            }
            
            header("Location: licenses.php?msg=created");
        } catch (Exception $e) {
            die("Error creando licencia: " . $e->getMessage());
        }
    }
    if ($action === 'create_trial_license') {
        try {
            $code = generateSaasLicenseCode();
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $stmt = $master->prepare("INSERT INTO licencias (codigo, plan, estado, created_at, updated_at) VALUES (?, 'trial', 'disponible', NOW(), NOW())");
                $stmt->execute([$code]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_licenses (license_code, status, license_type, created_at, expires_at) VALUES (?, 'active', 'trial', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))");
                $stmt->execute([$code]);
            }
            header("Location: licenses.php?msg=created");
        } catch (Exception $e) {
            die("Error creando licencia trial: " . $e->getMessage());
        }
    }

    // 2. Reset Password
    if ($action === 'reset_password') {
        $tenantId = $_POST['tenant_id'];
        $newPass = $_POST['new_password'];

        if (empty($newPass) || strlen($newPass) < 6) {
            die("La contraseña debe tener al menos 6 caracteres.");
        }

        try {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                require_once __DIR__ . '/../config/tenant_manager.php';
                $master = DatabaseManager::master();

                $stmt = $master->prepare("UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE empresa_id = ? AND rol = 'admin'");
                $stmt->execute([$hash, $tenantId]);

                try {
                    $stmtAdmins = $master->prepare("SELECT * FROM usuarios_master WHERE empresa_id = ? AND rol = 'admin'");
                    $stmtAdmins->execute([$tenantId]);
                    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);
                    $tenantPdo = DatabaseManager::tenant((int)$tenantId);
                    foreach ($admins as $adminUser) {
                        TenantManager::ensureTenantUser($tenantPdo, $adminUser);
                    }
                } catch (Throwable $e) {}

                header("Location: index.php?msg=password_reset");
                exit;
            }
            // Update Admin Password (Find admin user for this tenant)
            // Assuming the first admin user or all admins? Let's update all admins for simplicity in recovery
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE tenant_id = ? AND role = 'admin'");
            $stmt->execute([$hash, $tenantId]);

            header("Location: index.php?msg=password_reset");
        } catch (Exception $e) {
            die("Error al resetear clave: " . $e->getMessage());
        }
    }

    // 3. Reset Tenant User Password (Specific User)
    if ($action === 'reset_tenant_user_password') {
        $tenantId = $_POST['tenant_id'];
        $userId = $_POST['user_id'];
        $newPass = $_POST['new_password'];

        if (empty($tenantId) || empty($userId) || empty($newPass)) {
            die("Faltan datos.");
        }

        try {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                require_once __DIR__ . '/../config/tenant_manager.php';
                $master = DatabaseManager::master();

                $stmt = $master->prepare("UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE id = ? AND empresa_id = ?");
                $stmt->execute([$hash, $userId, $tenantId]);

                try {
                    $getUser = $master->prepare("SELECT * FROM usuarios_master WHERE id = ? AND empresa_id = ? LIMIT 1");
                    $getUser->execute([$userId, $tenantId]);
                    $masterUser = $getUser->fetch(PDO::FETCH_ASSOC);
                    if ($masterUser) {
                        $tenantPdo = DatabaseManager::tenant((int)$tenantId);
                        TenantManager::ensureTenantUser($tenantPdo, $masterUser);
                    }
                } catch (Throwable $e) {}

                header("Location: tenant_users.php?id=$tenantId&msg=password_reset");
                exit;
            }
            // Update Password in main DB
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$hash, $userId, $tenantId]);

            header("Location: tenant_users.php?id=$tenantId&msg=password_reset");
        } catch (Exception $e) {
            die("Error al resetear clave: " . $e->getMessage());
        }
    }

    // 4. Create Tenant (Admin Side)
    if ($action === 'create_tenant_admin') {
        $companyName = trim($_POST['company_name']);
        $adminEmail = trim($_POST['admin_email']);
        $adminPassword = $_POST['admin_password'];
        $adminName = trim($_POST['admin_name'] ?? 'Administrador');

        if (empty($companyName) || empty($adminEmail) || empty($adminPassword)) {
            die("Todos los campos son obligatorios.");
        }

        try {
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                require_once __DIR__ . '/../config/provisioning_service.php';

                $master = DatabaseManager::master();
                $licenseCode = generateSaasLicenseCode();
                $insLic = $master->prepare("INSERT INTO licencias (codigo, plan, estado, created_at, updated_at) VALUES (?, 'standard', 'disponible', NOW(), NOW())");
                $insLic->execute([$licenseCode]);

                try {
                    ProvisioningService::provisionFromMasterLicense($licenseCode, $companyName, $adminName, $adminEmail, $adminPassword);
                } catch (Throwable $e) {
                    try {
                        $rollbackLic = $master->prepare("DELETE FROM licencias WHERE codigo = ? AND estado = 'disponible'");
                        $rollbackLic->execute([$licenseCode]);
                    } catch (Throwable $ignored) {
                    }
                    throw $e;
                }

                header("Location: index.php?msg=tenant_created");
                exit;
            }

            // A. Check Email
            $stmt = $pdo->prepare("SELECT id FROM saas_users_lookup WHERE email = ?");
            $stmt->execute([$adminEmail]);
            if ($stmt->fetch()) {
                die("El email ya está registrado en otra empresa.");
            }

            // B. Generate License
            $licenseCode = generateSaasLicenseCode();
            $stmt = $pdo->prepare("INSERT INTO saas_licenses (license_code, status, created_at) VALUES (?, 'active', NOW())");
            $stmt->execute([$licenseCode]);
            $licenseId = $pdo->lastInsertId();

            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $companyName), '-'));
            $stmt = $pdo->prepare("INSERT INTO tenants (company_name, slug, status, created_at) VALUES (?, ?, 'active', NOW())");
            $stmt->execute([$companyName, $slug]);
            $tenantId = $pdo->lastInsertId();

            // D. Register User Lookup
            $stmt = $pdo->prepare("INSERT INTO saas_users_lookup (email, tenant_id) VALUES (?, ?)");
            $stmt->execute([$adminEmail, $tenantId]);

            // E. Update License
            $stmt = $pdo->prepare("UPDATE saas_licenses SET status = 'used', tenant_id = ?, used_at = NOW() WHERE id = ?");
            $stmt->execute([$tenantId, $licenseId]);

            // F. Setup Admin in Main DB
            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmtUser = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (?, ?, ?, ?, 'admin', 1, NOW())");
            $stmtUser->execute([$tenantId, "Administrador", $adminEmail, $passwordHash]);

            // G. Setup Company Config (Single DB)
            $stmtConfig = $pdo->prepare("INSERT INTO company_config (tenant_id, company_name, company_email, company_address, created_at, updated_at) VALUES (?, ?, ?, 'Dirección de la empresa', NOW(), NOW())");
            $stmtConfig->execute([$tenantId, $companyName, $adminEmail]);

            // Seed de estados desde empresa base
            try {
                $hasTenantCol = false;
                try {
                    $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
                    $hasTenantCol = ($c && $c->rowCount() > 0);
                } catch (Exception $e) {}
                if (!$hasTenantCol) {
                    $pdo->exec("ALTER TABLE order_statuses ADD COLUMN tenant_id INT NOT NULL DEFAULT 1");
                    $pdo->exec("CREATE INDEX idx_order_statuses_tenant ON order_statuses(tenant_id)");
                }
                $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE tenant_id = ?");
                $existsStmt->execute([$tenantId]);
                $count = (int)$existsStmt->fetchColumn();
                if ($count === 0) {
                    $baseId = resolveBaseTenantId();
                    $sel = $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC");
                    $sel->execute([$baseId]);
                    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
                    if ($rows && count($rows) > 0) {
                        $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        foreach ($rows as $r) {
                            $ins->execute([$tenantId, $r['name'], $r['slug'], $r['emoji'], $r['color'], $r['description'], (int)($r['is_default'] ?? 0), (int)($r['is_active'] ?? 1), (int)($r['sort_order'] ?? 0)]);
                        }
                    } else {
                        $defaults = [
                            ['pending','Pendiente','⏳','#6c757d','Orden creada y pendiente de revisión',1,1,1],
                            ['received','Recibido','📦','#6c757d','Orden recibida en el taller',0,1,2],
                            ['diagnosing','Diagnosticando','🔍','#ffc107','Equipo en diagnóstico técnico',0,1,3],
                            ['waiting_parts','Esperando Repuestos','⏸️','#fd7e14','Orden en espera de repuestos',0,1,4],
                            ['repairing','Reparando','🔧','#17a2b8','Equipo en reparación',0,1,5],
                            ['testing','Pruebas','🧪','#20c997','Equipo en pruebas de funcionamiento',0,1,6],
                            ['completed','Completado','✅','#28a745','Trabajo completado, listo para entrega',0,1,7],
                            ['delivered','Entregado','🚚','#007bff','Orden entregada al cliente',0,1,8],
                            ['cancelled','Cancelado','❌','#dc3545','Orden cancelada',0,1,9]
                        ];
                        $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        foreach ($defaults as $d) {
                            $ins->execute([$tenantId, $d[1], $d[0], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7]]);
                        }
                    }
                }
            } catch (Exception $e) {
                // silencioso
            }
            try { ensureDefaultTenantCatalogs($tenantId); } catch (Exception $e) {}

            header("Location: index.php?msg=tenant_created");

        } catch (Exception $e) {
            die("Error creando empresa: " . $e->getMessage());
        }
    }
    if ($action === 'rename_tenant') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $companyName = trim($_POST['company_name'] ?? '');
        if ($tenantId <= 0 || $companyName === '') {
            header("Location: index.php?msg=error");
            exit;
        }
        try {
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $stmt = $master->prepare("UPDATE empresas SET nombre = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$companyName, $tenantId]);
                header("Location: index.php?msg=tenant_renamed");
                exit;
            }
            $stmt = $pdo->prepare("UPDATE tenants SET company_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$companyName, $tenantId]);
            try {
                $stmt2 = $pdo->prepare("UPDATE company_config SET company_name = ?, updated_at = NOW() WHERE tenant_id = ?");
                $stmt2->execute([$companyName, $tenantId]);
            } catch (Exception $e) {}
            try {
                $stmt3 = $pdo->prepare("UPDATE company_settings SET company_name = ?, updated_at = NOW() WHERE tenant_id = ?");
                $stmt3->execute([$companyName, $tenantId]);
            } catch (Exception $e) {}
            header("Location: index.php?msg=tenant_renamed");
        } catch (Exception $e) {
            header("Location: index.php?msg=error");
        }
    }

    // 9. Update Tenant User Email (Super Admin)
    if ($action === 'update_tenant_user_email') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $newEmail = trim($_POST['new_email'] ?? '');
        
        if ($tenantId <= 0 || $userId <= 0 || $newEmail === '') {
            header("Location: tenant_users.php?id=$tenantId&msg=error");
            exit;
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            header("Location: tenant_users.php?id=$tenantId&msg=error");
            exit;
        }
        
        try {
            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();

                $stmtCurrent = $master->prepare("SELECT id, email FROM usuarios_master WHERE id = ? AND empresa_id = ? LIMIT 1");
                $stmtCurrent->execute([$userId, $tenantId]);
                $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    throw new Exception('Usuario no encontrado');
                }

                $stmt = $master->prepare("SELECT id FROM usuarios_master WHERE email = ? AND id <> ? LIMIT 1");
                $stmt->execute([$newEmail, $userId]);
                if ($stmt->fetch()) {
                    header("Location: tenant_users.php?id=$tenantId&msg=error");
                    exit;
                }

                $upd = $master->prepare("UPDATE usuarios_master SET email = ?, updated_at = NOW() WHERE id = ? AND empresa_id = ?");
                $upd->execute([$newEmail, $userId, $tenantId]);

                try {
                    $tenantPdo = DatabaseManager::tenant($tenantId);
                    $updTenant = $tenantPdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE email = ?");
                    $updTenant->execute([$newEmail, (string)($current['email'] ?? '')]);
                } catch (Throwable $e) {}

                header("Location: tenant_users.php?id=$tenantId&msg=email_updated");
                exit;
            }

            // Evitar colisión con otro tenant (lookup global)
            $stmt = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
            $stmt->execute([$newEmail]);
            $lkTenant = $stmt->fetchColumn();
            if ($lkTenant && (int)$lkTenant !== $tenantId) {
                header("Location: tenant_users.php?id=$tenantId&msg=error");
                exit;
            }
            
            // Evitar duplicado dentro del mismo tenant con otro usuario
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id <> ? LIMIT 1");
            $stmt->execute([$newEmail, $tenantId, $userId]);
            if ($stmt->fetch()) {
                header("Location: tenant_users.php?id=$tenantId&msg=error");
                exit;
            }
            
            // Actualizar email del usuario
            $upd = $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $upd->execute([$newEmail, $userId, $tenantId]);
            
            // Upsert en lookup
            $ins = $pdo->prepare("INSERT INTO saas_users_lookup (email, tenant_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)");
            $ins->execute([$newEmail, $tenantId]);
            
            header("Location: tenant_users.php?id=$tenantId&msg=email_updated");
        } catch (Exception $e) {
            header("Location: tenant_users.php?id=$tenantId&msg=error");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'audit_tenant' && isset($_GET['id'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $tenantId = (int)$_GET['id'];
            if ($tenantId <= 0) { throw new Exception('ID de empresa inválido'); }

            if (isPerDatabaseMode()) {
                require_once __DIR__ . '/../config/database_manager.php';
                $master = DatabaseManager::master();
                $stmtE = $master->prepare("SELECT id, nombre, estado, db_name FROM empresas WHERE id = ? LIMIT 1");
                $stmtE->execute([$tenantId]);
                $empresa = $stmtE->fetch(PDO::FETCH_ASSOC);
                if (!$empresa) {
                    throw new Exception('Empresa no encontrada');
                }

                $stmtUsers = $master->prepare("SELECT COUNT(*) FROM usuarios_master WHERE empresa_id = ?");
                $stmtUsers->execute([$tenantId]);
                $usersCount = (int)$stmtUsers->fetchColumn();

                $storagePath = __DIR__ . '/../storage/empresas/' . $tenantId;
                $storageAudit = collectDirAudit($storagePath);
                $uploadsPath = __DIR__ . '/../uploads/empresas/' . $tenantId;
                $uploadsAudit = collectDirAudit($uploadsPath);

                echo json_encode([
                    'success' => true,
                    'tenant' => [
                        'id' => (int)$empresa['id'],
                        'company_name' => (string)($empresa['nombre'] ?? ''),
                        'slug' => 'empresa-' . (int)$empresa['id']
                    ],
                    'database' => [
                        'tables_with_rows' => [],
                        'total_rows' => 0,
                        'saas_users_lookup_rows' => 0,
                        'login_attempts_rows' => 0,
                        'master_users_rows' => $usersCount,
                        'tenant_db_name' => (string)($empresa['db_name'] ?? '')
                    ],
                    'filesystem' => [
                        'uploads' => $uploadsAudit,
                        'storage' => $storageAudit
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $row = $pdo->prepare("SELECT id, company_name, slug FROM tenants WHERE id = ? LIMIT 1");
            $row->execute([$tenantId]);
            $tenant = $row->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) { throw new Exception('Empresa no encontrada'); }

            $tablesAudit = [];
            $stmtT = $pdo->prepare("
                SELECT DISTINCT TABLE_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND COLUMN_NAME = 'tenant_id'
                ORDER BY TABLE_NAME
            ");
            $stmtT->execute();
            $tenantTables = $stmtT->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($tenantTables as $t) {
                $table = (string)$t;
                if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
                try {
                    $q = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id = ?");
                    $q->execute([$tenantId]);
                    $cnt = (int)$q->fetchColumn();
                    if ($cnt > 0) {
                        $tablesAudit[] = ['table' => $table, 'rows' => $cnt];
                    }
                } catch (Throwable $e) {}
            }
            usort($tablesAudit, function($a, $b){ return ((int)$b['rows']) <=> ((int)$a['rows']); });

            $lookupCount = 0;
            try {
                $q = $pdo->prepare("SELECT COUNT(*) FROM saas_users_lookup WHERE tenant_id = ?");
                $q->execute([$tenantId]);
                $lookupCount = (int)$q->fetchColumn();
            } catch (Throwable $e) {}

            $emails = [];
            try {
                $q = $pdo->prepare("SELECT email FROM users WHERE tenant_id = ? AND email IS NOT NULL AND email <> ''");
                $q->execute([$tenantId]);
                $emails = array_values(array_unique(array_filter($q->fetchAll(PDO::FETCH_COLUMN))));
            } catch (Throwable $e) {}

            $loginAttemptsCount = 0;
            if (!empty($emails)) {
                try {
                    $ph = implode(',', array_fill(0, count($emails), '?'));
                    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email IN ($ph)");
                    $q->execute($emails);
                    $loginAttemptsCount = (int)$q->fetchColumn();
                } catch (Throwable $e) {}
            }

            $uploadsAudit = collectDirAudit(getTenantUploadsFsById($tenantId));
            $storageAudit = collectDirAudit(getTenantStorageFsById($tenantId));

            echo json_encode([
                'success' => true,
                'tenant' => [
                    'id' => (int)$tenant['id'],
                    'company_name' => (string)($tenant['company_name'] ?? ''),
                    'slug' => (string)($tenant['slug'] ?? '')
                ],
                'database' => [
                    'tables_with_rows' => $tablesAudit,
                    'total_rows' => array_sum(array_map(function($r){ return (int)$r['rows']; }, $tablesAudit)),
                    'saas_users_lookup_rows' => $lookupCount,
                    'login_attempts_rows' => $loginAttemptsCount
                ],
                'filesystem' => [
                    'uploads' => $uploadsAudit,
                    'storage' => $storageAudit
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 5. Delete License
    if ($action === 'delete_license') {
        http_response_code(405);
        die('Método no permitido');
    }

    // 6. Delete Tenant (The Nuclear Option)
    if ($action === 'delete_tenant') {
        http_response_code(405);
        die('Método no permitido');
    }

    // 7. Delete Ghost DB (Deprecated)
    if ($action === 'delete_ghost') {
        // No-op in Single DB
        header("Location: index.php?msg=ghost_deleted");
    }

    // 8. Delete Tenant User
    if ($action === 'delete_tenant_user') {
        http_response_code(405);
        die('Método no permitido');
    }
}
?>
