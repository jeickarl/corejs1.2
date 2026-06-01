<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/security_enhancements.php';
$pdo = db();

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Security Hardening: Only Super Admin can change binary paths
$isSuperAdmin = isset($_SESSION['SAAS_SUPERADMIN_ID']);
$isTenantAdmin = in_array(strtolower(trim($_SESSION['user_role'] ?? '')), ['admin', 'administrador', 'administrator']);

if (!$isTenantAdmin && !$isSuperAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? '';
if ($csrf === '' || !class_exists('SecurityEnhancements') || !SecurityEnhancements::verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit;
}

try {
    if ($action === 'get') {
        ensureSystemConfigSchema();
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $keys = [
            'backup_mysqldump_path',
            'backup_mysql_path',
            'backup_include_triggers',
            'backup_include_routines',
            'backup_include_events',
            'backup_retention_zip_count',
            'backup_retention_sql_count'
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT config_key, config_value FROM system_config WHERE config_key IN ($placeholders)" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $params = $keys;
        if ($hasTenantSystem && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Ocultar rutas de binarios si no es super admin para mayor seguridad
        if (!$isSuperAdmin) {
            $rows['backup_mysqldump_path'] = 'PROTEGIDO';
            $rows['backup_mysql_path'] = 'PROTEGIDO';
        }
        
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    } elseif ($action === 'save') {
        ensureSystemConfigSchema();
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        
        $pairs = [
            'backup_include_triggers' => ($_POST['backup_include_triggers'] ?? '0') === '1' ? '1' : '0',
            'backup_include_routines' => ($_POST['backup_include_routines'] ?? '0') === '1' ? '1' : '0',
            'backup_include_events' => ($_POST['backup_include_events'] ?? '0') === '1' ? '1' : '0',
            'backup_retention_zip_count' => (string)max(1, (int)($_POST['backup_retention_zip_count'] ?? 10)),
            'backup_retention_sql_count' => (string)max(1, (int)($_POST['backup_retention_sql_count'] ?? 5)),
        ];

        // Solo permitir guardar rutas de binarios al Super Administrador
        if ($isSuperAdmin) {
            $pairs['backup_mysqldump_path'] = trim($_POST['backup_mysqldump_path'] ?? '');
            $pairs['backup_mysql_path'] = trim($_POST['backup_mysql_path'] ?? '');
        }

        if ($perDatabase) {
            foreach ($pairs as $k => $v) {
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                $stmt->execute([$v, $k]);
                if ($stmt->rowCount() > 0) { continue; }
                try {
                    $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                    $stmt->execute([$k, $v]);
                } catch (Throwable $e) {
                    if ($hasTenantSystem) {
                        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                        $stmt->execute([$tenantValue, $k, $v]);
                        continue;
                    }
                    throw $e;
                }
            }
        } elseif ($hasTenantSystem) {
            $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            foreach ($pairs as $k => $v) {
                $stmt->execute([$tenantValue, $k, $v]);
            }
        } else {
            foreach ($pairs as $k => $v) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
                $stmt->execute([$k]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                    $stmt->execute([$v, $k]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                    $stmt->execute([$k, $v]);
                }
            }
        }
        echo json_encode(['success' => true, 'message' => 'Configuración guardada']);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción inválida']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
