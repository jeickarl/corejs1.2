<?php
require_once __DIR__ . '/env_loader.php';
header('Content-Type: text/html; charset=utf-8');
/**
 * Funciones comunes del sistema Core
 */

require_once __DIR__ . '/company_settings.php';

// Establecer zona horaria predeterminada
date_default_timezone_set('America/Bogota');

/**
 * Corrige problemas de pérdida de AUTO_INCREMENT o Primary Key en las tablas.
 * Útil para prevenir fallos (id = 0) al insertar nuevos registros tras migraciones defectuosas.
 */
function fixTableAutoIncrement(PDO $pdo, string $tableName) {
    try {
        $schemaStmt = $pdo->query("SELECT DATABASE()");
        $dbName = $schemaStmt->fetchColumn();
        
        // Verificar si la columna ID tiene AUTO_INCREMENT
        $aiStmt = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'id'");
        $aiStmt->execute([$dbName, $tableName]);
        $extra = $aiStmt->fetchColumn();
        
        if (!$extra || stripos($extra, 'auto_increment') === false) {
            // Verificar si tiene Primary Key
            $pkCheck = $pdo->prepare("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'");
            $pkCheck->execute();
            $hasPk = $pkCheck->rowCount() > 0;
            
            if (!$hasPk) {
                $pdo->exec("ALTER TABLE `$tableName` ADD PRIMARY KEY (id)");
            }
            
            // Re-aplicar AUTO_INCREMENT
            $pdo->exec("ALTER TABLE `$tableName` MODIFY id int(11) NOT NULL AUTO_INCREMENT");
            
            // Arreglar filas huérfanas con id = 0 que se hayan creado en el intertanto
            $maxStmt = $pdo->query("SELECT MAX(id) FROM `$tableName`");
            $maxId = (int)($maxStmt->fetchColumn() ?: 0);
            
            $zerosStmt = $pdo->query("SELECT id FROM `$tableName` WHERE id = 0");
            $zeroRows = $zerosStmt ? $zerosStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            
            foreach ($zeroRows as $_) {
                $maxId++;
                $upd = $pdo->prepare("UPDATE `$tableName` SET id = ? WHERE id = 0 LIMIT 1");
                $upd->execute([$maxId]);
            }
        }
    } catch (Throwable $e) {
        error_log("Error fijando auto_increment en $tableName: " . $e->getMessage());
    }
}

/**
 * Verifica si una tabla tiene la columna tenant_id, usando caché en sesión para optimizar.
 */
function hasTenantColumnCached(PDO $pdo, string $tableName) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { $c = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE 'tenant_id'"); return ($c && $c->rowCount() > 0); } catch (Throwable $e) { return false; }
    }
    if (!isset($_SESSION['schema_cache_tenant_cols'])) $_SESSION['schema_cache_tenant_cols'] = [];
    if (isset($_SESSION['schema_cache_tenant_cols'][$tableName])) return $_SESSION['schema_cache_tenant_cols'][$tableName];
    
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE 'tenant_id'");
        $has = ($c && $c->rowCount() > 0);
        $_SESSION['schema_cache_tenant_cols'][$tableName] = $has;
        return $has;
    } catch (Throwable $e) { return false; }
}

/**
 * Caché auxiliar para columnas genéricas (ej. is_active, is_visible)
 */
function hasColumnCached(PDO $pdo, string $tableName, string $columnName) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { $c = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'"); return ($c && $c->rowCount() > 0); } catch (Throwable $e) { return false; }
    }
    if (!isset($_SESSION['schema_cache_cols'])) $_SESSION['schema_cache_cols'] = [];
    $key = $tableName . '_' . $columnName;
    if (isset($_SESSION['schema_cache_cols'][$key])) return $_SESSION['schema_cache_cols'][$key];
    
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        $has = ($c && $c->rowCount() > 0);
        $_SESSION['schema_cache_cols'][$key] = $has;
        return $has;
    } catch (Throwable $e) { return false; }
}

/**
 * Obtiene el catálogo de estados normalizado y abstracto para optimización.
 */
function getOrderStatusesCatalog(PDO $pdo, $tenant_id) {
    $status_catalog = [];
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
        if (hasTenantColumnCached($pdo, 'order_statuses')) {
            $stc = $pdo->prepare("SELECT slug, name, emoji, color FROM order_statuses WHERE is_active = 1 AND tenant_id = ?");
            $stc->execute([$tenantValue]);
        } else {
            $stc = $pdo->prepare("SELECT slug, name, emoji, color FROM order_statuses WHERE is_active = 1");
            $stc->execute();
        }
        while ($row = $stc->fetch(PDO::FETCH_ASSOC)) {
            $slug = strtolower(trim((string)($row['slug'] ?? '')));
            if ($slug !== '') {
                $status_catalog[$slug] = [
                    'name' => (string)($row['name'] ?? ''),
                    'emoji' => (string)($row['emoji'] ?? ''),
                    'color' => (string)($row['color'] ?? '')
                ];
            }
        }
    } catch (Throwable $e) {}
    return $status_catalog;
}

function ensureSystemConfigSchema() {
    static $schemaChecked = false;
    if ($schemaChecked) { return; }
    $schemaChecked = true;
    global $pdo;
    if (!$pdo) { return; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (id INT(11) NOT NULL AUTO_INCREMENT, tenant_id INT(11) NOT NULL DEFAULT 1, config_key VARCHAR(255) DEFAULT NULL, config_value TEXT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        try { $pdo->query("SELECT tenant_id FROM system_config LIMIT 1"); } catch (Throwable $e) { $pdo->exec("ALTER TABLE system_config ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 FIRST"); }
        try { $pdo->exec("ALTER TABLE system_config MODIFY id INT(11) NOT NULL AUTO_INCREMENT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE system_config ADD UNIQUE KEY uq_system_config_config_key_tenant (config_key, tenant_id)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}
function resolveBaseTenantId() {
    global $pdo;
    $baseId = 1;
    if (!$pdo) { return $baseId; }
    try {
        ensureSystemConfigSchema();
        // 1) Configuración explícita (sin importar tenant)
        try {
            $q = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'base_tenant_id' ORDER BY tenant_id ASC LIMIT 1");
            $q->execute();
            $val = $q->fetchColumn();
            if ($val !== false && (int)$val > 0) { return (int)$val; }
        } catch (Throwable $e) {}
        // 2) Intentar email admin base y persistir bajo ese tenant
        try {
            $q = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
            $q->execute(['admin@core.com']);
            $tid = $q->fetchColumn();
            if ($tid) {
                $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'base_tenant_id', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $ins->execute([(int)$tid, strval((int)$tid)]);
                return (int)$tid;
            }
        } catch (Throwable $e) {}
        try {
            $q = $pdo->prepare("SELECT tenant_id FROM users WHERE email = ? LIMIT 1");
            $q->execute(['admin@core.com']);
            $tid = $q->fetchColumn();
            if ($tid) {
                $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'base_tenant_id', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $ins->execute([(int)$tid, strval((int)$tid)]);
                return (int)$tid;
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    return $baseId;
}
function ensureDefaultOrderStatuses($tenant_id) {
    global $pdo;
    if (!$pdo || !$tenant_id) { return; }
    try {
        $hasTenant = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        $count = 0;
        if ($hasTenant) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE tenant_id = ?");
            $chk->execute([$tenant_id]);
            $count = (int)$chk->fetchColumn();
        }
        $existingSlugs = [];
        if ($hasTenant) {
            try {
                $selExisting = $pdo->prepare("SELECT slug, is_default FROM order_statuses WHERE tenant_id = ?");
                $selExisting->execute([$tenant_id]);
                foreach (($selExisting->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                    $slug = strtolower(trim((string)($r['slug'] ?? '')));
                    if ($slug !== '') $existingSlugs[$slug] = (int)($r['is_default'] ?? 0);
                }
            } catch (Throwable $e) {}
        }

        $rows = [];
        try {
            ensureSystemConfigSchema();
            $tplStmt1 = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = 1 AND config_key = 'order_statuses_template' LIMIT 1");
            $tplStmt1->execute();
            $tpl1 = $tplStmt1->fetchColumn();
            if ($tpl1) {
                $decoded = json_decode($tpl1, true);
                if (is_array($decoded) && count($decoded) > 0) { $rows = $decoded; }
            }
        } catch (Throwable $e) {}
        try {
            if (!$rows) {
                $baseId = resolveBaseTenantId();
                $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_statuses_template' LIMIT 1");
                $tplStmt->execute([(int)$baseId]);
                $tpl = $tplStmt->fetchColumn();
                if ($tpl) {
                    $decoded = json_decode($tpl, true);
                    if (is_array($decoded) && count($decoded) > 0) { $rows = $decoded; }
                }
            }
        } catch (Throwable $e) {}
        try {
            if (!$rows) {
                $sel = $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = 1 ORDER BY sort_order ASC");
                $sel->execute();
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}
        try {
            if (!$rows) {
                $baseId = isset($baseId) ? $baseId : resolveBaseTenantId();
                $sel = $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC");
                $sel->execute([(int)$baseId]);
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}

        if (!$rows) {
            $rows = [
                ['slug' => 'pendiente', 'name' => 'Pendiente', 'emoji' => '⏳', 'color' => '#ffc107', 'description' => 'Orden creada y pendiente de revisión', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1],
                ['slug' => 'asignado', 'name' => 'Asignado', 'emoji' => '📦', 'color' => '#6cc4ea', 'description' => 'Dispositivo recibido en el taller', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 2],
                ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'emoji' => '🔍', 'color' => '#fd7e14', 'description' => 'Equipo en diagnóstico técnico', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 3],
                ['slug' => 'esperando_aprobacion', 'name' => 'Esperando Aprobación', 'emoji' => '✍️', 'color' => '#ffc107', 'description' => 'Orden esperando aprobación del cliente', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 4],
                ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'emoji' => '⏸️', 'color' => '#6f42c1', 'description' => 'Orden en espera de repuestos', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 5],
                ['slug' => 'reparando', 'name' => 'Reparando', 'emoji' => '🔧', 'color' => '#007bff', 'description' => 'Equipo en reparación', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 6],
                ['slug' => 'testeando', 'name' => 'Testeando', 'emoji' => '🧪', 'color' => '#17a2b8', 'description' => 'Equipo en pruebas de funcionamiento', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 7],
                ['slug' => 'completado', 'name' => 'Completado', 'emoji' => '✅', 'color' => '#28a745', 'description' => 'Trabajo completado, listo para entrega', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 8],
                ['slug' => 'entregado', 'name' => 'Entregado', 'emoji' => '🚚', 'color' => '#6c757d', 'description' => 'Dispositivo entregado al cliente', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 9],
                ['slug' => 'cancelado', 'name' => 'Cancelado', 'emoji' => '❌', 'color' => '#dc3545', 'description' => 'Orden cancelada', 'is_default' => 0, 'is_active' => 1, 'sort_order' => 10]
            ];
        }

        if ($hasTenant) {
            $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            foreach ($rows as $r) {
                $slug = strtolower(trim((string)($r['slug'] ?? '')));
                if ($slug === '') continue;
                if (isset($existingSlugs[$slug])) continue;
                $name = (string)($r['name'] ?? ($r['state_name'] ?? ''));
                if ($name === '') $name = $slug;
                try {
                    $ins->execute([
                        (int)$tenant_id,
                        $name,
                        $slug,
                        (string)($r['emoji'] ?? ''),
                        (string)($r['color'] ?? '#6c757d'),
                        (string)($r['description'] ?? ''),
                        (int)($r['is_default'] ?? 0),
                        (int)($r['is_active'] ?? 1),
                        (int)($r['sort_order'] ?? 0)
                    ]);
                    $existingSlugs[$slug] = (int)($r['is_default'] ?? 0);
                    $count++;
                } catch (Throwable $e) {}
            }
        }

        if ($hasTenant && $count > 0) {
            $hasDefault = false;
            foreach ($existingSlugs as $isDef) { if ((int)$isDef === 1) { $hasDefault = true; break; } }
            if (!$hasDefault) {
                try {
                    $stmtPick = $pdo->prepare("SELECT id FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                    $stmtPick->execute([(int)$tenant_id]);
                    $firstId = (int)($stmtPick->fetchColumn() ?: 0);
                    if ($firstId > 0) {
                        $pdo->prepare("UPDATE order_statuses SET is_default = 0 WHERE tenant_id = ?")->execute([(int)$tenant_id]);
                        $pdo->prepare("UPDATE order_statuses SET is_default = 1 WHERE id = ? AND tenant_id = ?")->execute([$firstId, (int)$tenant_id]);
                    }
                } catch (Throwable $e) {}
            }
        }
        try {
            $stmt = $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC");
            $stmt->execute([$tenant_id]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $json = json_encode($list, JSON_UNESCAPED_UNICODE);
            $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'order_statuses_template', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            $ins->execute([$tenant_id, $json]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

function ensureOrderStatusHistoryChangedByFk(PDO $pdo) {
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $name = null; $rule = null;
        $sql = "SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
                FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                JOIN information_schema.KEY_COLUMN_USAGE kcu
                  ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                 AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                WHERE rc.CONSTRAINT_SCHEMA = ?
                  AND rc.TABLE_NAME = 'order_status_history'
                  AND rc.REFERENCED_TABLE_NAME = 'users'
                  AND kcu.COLUMN_NAME = 'changed_by'
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$db]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)$row['CONSTRAINT_NAME'];
            $rule = strtoupper((string)$row['DELETE_RULE']);
        }
        if ($name && $rule !== 'SET NULL') {
            try { $pdo->exec("ALTER TABLE order_status_history DROP FOREIGN KEY `$name`"); } catch (Throwable $__) {}
            $pdo->exec("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_ibfk_2 FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL");
        } elseif (!$name) {
            try {
                $pdo->exec("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_ibfk_2 FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL");
            } catch (Throwable $__) {}
        }
    } catch (Throwable $e) {}
}
function ensureDefaultTenantCatalogs($tenant_id) {
    global $pdo;
    if (!$pdo || !$tenant_id) { return; }
    try {
        $baseId = resolveBaseTenantId();
        try {
            $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
            if ($hasTenant) {
                $sel = $pdo->prepare("SELECT name, description, is_visible, is_active, sort_order FROM device_types WHERE tenant_id = ? ORDER BY sort_order, name");
                $sel->execute([$baseId]);
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    $ins = $pdo->prepare("INSERT INTO device_types (tenant_id, name, description, is_visible, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    foreach ($rows as $r) { $ins->execute([$tenant_id, $r['name'], $r['description'], (int)($r['is_visible'] ?? 1), (int)($r['is_active'] ?? 1), (int)($r['sort_order'] ?? 0)]); }
                }
            }
        } catch (Throwable $e) {}
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM brands WHERE tenant_id = ?");
            $chk->execute([$tenant_id]);
            $countBrands = (int)$chk->fetchColumn();
            if ($countBrands === 0) {
                ensureBrandsFromGlobalAssets($tenant_id);
            }
        } catch (Throwable $e) {}
        try {
            $c = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
            if ($hasTenant) {
                $sel = $pdo->prepare("SELECT name, description, category, is_active, sort_order FROM equipment_accessories WHERE tenant_id = ? ORDER BY sort_order, name");
                $sel->execute([$baseId]);
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    $ins = $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, description, category, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    foreach ($rows as $r) { $ins->execute([$tenant_id, $r['name'], $r['description'], $r['category'], (int)($r['is_active'] ?? 1), (int)($r['sort_order'] ?? 0)]); }
                }
            }
        } catch (Throwable $e) {}
        try {
            $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
            if ($hasTenant) {
                $sel = $pdo->prepare("SELECT name, description, logo_path, logo, is_active FROM brands WHERE tenant_id = ? ORDER BY name");
                $sel->execute([$baseId]);
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    $ins = $pdo->prepare("INSERT INTO brands (tenant_id, name, description, logo_path, logo, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $destDir = ensureTenantSubdirFs($tenant_id, 'brands');
                    foreach ($rows as $r) {
                        $logo = $r['logo'];
                        $logoPath = $r['logo_path'];
                        $bn = null;
                        if ($logo) { $bn = basename($logo); }
                        if (!$bn && $logoPath) { $bn = basename($logoPath); }
                        if ($bn) {
                            $src1 = rtrim(getTenantUploadsFsById($baseId), '/\\') . DIRECTORY_SEPARATOR . 'brands' . DIRECTORY_SEPARATOR . $bn;
                            $src2 = rtrim(__DIR__ . '/../../uploads/brands', '/\\') . DIRECTORY_SEPARATOR . $bn;
                            $dest = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $bn;
                            if (!file_exists($dest)) {
                                if (is_file($src1)) { @copy($src1, $dest); }
                                elseif (is_file($src2)) { @copy($src2, $dest); }
                            }
                            $logo = "uploads/{$tenant_id}/brands/{$bn}";
                            $logoPath = "uploads/{$tenant_id}/brands/{$bn}";
                        }
                        $ins->execute([$tenant_id, $r['name'], $r['description'], $logoPath, $logo, (int)($r['is_active'] ?? 1)]);
                    }
                }
            }
        } catch (Throwable $e) {}
        try {
            $c1 = $pdo->query("SHOW COLUMNS FROM models LIKE 'tenant_id'");
            $c2 = $pdo->query("SHOW COLUMNS FROM brands LIKE 'tenant_id'");
            $c3 = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'tenant_id'");
            $hasTenant = ($c1 && $c1->rowCount() > 0 && $c2 && $c2->rowCount() > 0 && $c3 && $c3->rowCount() > 0);
            if ($hasTenant) {
                $sel = $pdo->prepare("SELECT m.name, m.description, m.is_active, b.name AS brand_name, dt.name AS device_type_name FROM models m JOIN brands b ON m.brand_id = b.id AND b.tenant_id = m.tenant_id JOIN device_types dt ON m.device_type_id = dt.id AND dt.tenant_id = m.tenant_id WHERE m.tenant_id = ? ORDER BY m.name");
                $sel->execute([$baseId]);
                foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $brand = null; $dtype = null;
                    $qb = $pdo->prepare("SELECT id FROM brands WHERE tenant_id = ? AND name = ? LIMIT 1");
                    $qb->execute([$tenant_id, $r['brand_name']]);
                    $brand = $qb->fetchColumn();
                    $qd = $pdo->prepare("SELECT id FROM device_types WHERE tenant_id = ? AND name = ? LIMIT 1");
                    $qd->execute([$tenant_id, $r['device_type_name']]);
                    $dtype = $qd->fetchColumn();
                    if ($brand && $dtype) {
                        $ins = $pdo->prepare("INSERT INTO models (tenant_id, name, brand_id, device_type_id, description, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $ins->execute([$tenant_id, $r['name'], (int)$brand, (int)$dtype, $r['description'], (int)($r['is_active'] ?? 1)]);
                    }
                }
            }
        } catch (Throwable $e) {}
        try {
            ensureSystemConfigSchema();
            $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ? AND config_key LIKE 'whatsapp_template_%'");
            $sel->execute([$baseId]);
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $ins->execute([$tenant_id, $row['config_key'], $row['config_value']]);
            }
        } catch (Throwable $e) {}
        try {
            ensureSystemConfigSchema();
            $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ? AND config_key LIKE 'warranty_%'");
            $sel->execute([$baseId]);
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $ins->execute([$tenant_id, $row['config_key'], $row['config_value']]);
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

function ensureBrandsFromGlobalAssets($tenant_id) {
    global $pdo;
    $dirs = [];
    $d1 = rtrim(__DIR__ . '/../uploads/brands', '/\\');
    $d2 = rtrim(__DIR__ . '/../../uploads/brands', '/\\');
    if (is_dir($d1)) { $dirs[] = $d1; }
    if (is_dir($d2)) { $dirs[] = $d2; }
    if (empty($dirs)) { return; }
    $tenantBrandsDir = ensureTenantSubdirFs($tenant_id, 'brands');
    $allowedExt = ['png','jpg','jpeg','gif','svg'];
    foreach ($dirs as $globalDir) {
        $files = scandir($globalDir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) { continue; }
            $src = $globalDir . DIRECTORY_SEPARATOR . $f;
            if (!is_file($src)) { continue; }
            $name = preg_replace('/\.[^.]+$/', '', $f);
            $name = str_replace('_', ' ', $name);
            $name = trim($name);
            $row = null;
            try {
                $sel = $pdo->prepare("SELECT id, logo, logo_path FROM brands WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
                $sel->execute([$tenant_id, $name]);
                $row = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { $row = null; }
            $destName = sanitizeFileBasename($f);
            $dest = rtrim($tenantBrandsDir, '/\\') . DIRECTORY_SEPARATOR . $destName;
            if (!file_exists($dest)) { @copy($src, $dest); }
            $rel = 'uploads/' . $tenant_id . '/brands/' . $destName;
            try {
                $hasLogoPath = false; $hasLogo = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'logo_path'"); $hasLogoPath = ($c && $c->rowCount() > 0); } catch (Throwable $e) {}
                try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'logo'"); $hasLogo = ($c && $c->rowCount() > 0); } catch (Throwable $e) {}
                if ($row && empty($row['logo']) && (!$hasLogoPath || empty($row['logo_path']))) {
                    if ($hasLogoPath && $hasLogo) {
                        $upd = $pdo->prepare("UPDATE brands SET logo = ?, logo_path = ? WHERE id = ? AND tenant_id = ?");
                        $upd->execute([$rel, $rel, $row['id'], $tenant_id]);
                    } elseif ($hasLogo) {
                        $upd = $pdo->prepare("UPDATE brands SET logo = ? WHERE id = ? AND tenant_id = ?");
                        $upd->execute([$rel, $row['id'], $tenant_id]);
                    }
                } elseif (!$row) {
                    if ($hasLogoPath && $hasLogo) {
                        $ins = $pdo->prepare("INSERT INTO brands (tenant_id, name, description, logo_path, logo, is_active, created_at) VALUES (?, ?, '', ?, ?, 1, NOW())");
                        $ins->execute([$tenant_id, $name, $rel, $rel]);
                    } elseif ($hasLogo) {
                        $ins = $pdo->prepare("INSERT INTO brands (tenant_id, name, description, logo, is_active, created_at) VALUES (?, ?, '', ?, 1, NOW())");
                        $ins->execute([$tenant_id, $name, $rel]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO brands (tenant_id, name, description, is_active, created_at) VALUES (?, ?, '', 1, NOW())");
                        $ins->execute([$tenant_id, $name]);
                    }
                }
            } catch (Throwable $e) {}
        }
    }
}
/**
 * Obtiene el directorio de subidas aislado para el inquilino actual
 * @param string $baseDir Directorio base relativo o absoluto (ej: '../uploads/')
 * @return string Directorio con el ID del tenant (ej: '../uploads/5/')
 */
function getTenantUploadDir($baseDir = '../uploads/') {
    // Asegurar que termina en /
    $baseDir = rtrim($baseDir, '/') . '/';
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        $tid = 0;
        if (function_exists('isPerDatabaseMode') && isPerDatabaseMode() && isset($_SESSION['empresa_id'])) {
            $tid = (int)$_SESSION['empresa_id'];
        }
        if ($tid <= 0 && isset($_SESSION['tenant_id'])) {
            $tid = (int)$_SESSION['tenant_id'];
        }
        if ($tid <= 0 && isset($_SESSION['empresa_id'])) {
            $tid = (int)$_SESSION['empresa_id'];
        }
        if ($tid <= 0) {
            return $baseDir;
        }
        $path = $baseDir . $tid . '/';
        
        // Intentar crear el directorio si es una ruta de sistema de archivos y no existe
        // Verificamos si parece una ruta local (no http://)
        if (strpos($path, '://') === false && !file_exists($path)) {
            @mkdir($path, 0777, true);
        }
        
        return $path;
    }
    
    return $baseDir;
}

function resolveOrderPhotoWebUrl($order_id, $photo, $baseWebDir = '../uploads/') {
    static $cache = [];
    $order_id = (int)$order_id;
    $photo = str_replace('\\', '/', (string)$photo);
    $photo = ltrim($photo, '/');
    $baseWebDir = rtrim((string)$baseWebDir, '/') . '/';
    $tid = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (function_exists('isPerDatabaseMode') && isPerDatabaseMode() && isset($_SESSION['empresa_id'])) {
            $tid = (int)$_SESSION['empresa_id'];
        }
        if ($tid <= 0 && isset($_SESSION['tenant_id'])) { $tid = (int)$_SESSION['tenant_id']; }
        if ($tid <= 0 && isset($_SESSION['empresa_id'])) { $tid = (int)$_SESSION['empresa_id']; }
    }

    $tenantPrefix = ($tid > 0) ? ($tid . '/') : '';
    $web = $baseWebDir . $tenantPrefix . 'orders/' . $order_id . '/' . $photo;
    $fsBase = rtrim(__DIR__ . '/../uploads', '/\\') . DIRECTORY_SEPARATOR;
    $fs = $fsBase . ($tid > 0 ? ($tid . DIRECTORY_SEPARATOR) : '') . 'orders' . DIRECTORY_SEPARATOR . $order_id . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photo);
    if (@is_file($fs)) {
        return $web;
    }

    if ($tid > 0) {
        $legacyWeb = $baseWebDir . 'orders/' . $order_id . '/' . $photo;
        $legacyFs = $fsBase . 'orders' . DIRECTORY_SEPARATOR . $order_id . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photo);
        if (@is_file($legacyFs)) {
            return $legacyWeb;
        }
    }

    $baseName = basename($photo);
    if ($baseName !== '' && $tid > 0) {
        if (isset($cache[$tid][$baseName])) {
            $rel = $cache[$tid][$baseName];
            return $baseWebDir . $tenantPrefix . 'orders/' . $rel;
        }
        $ordersBase = getTenantUploadsFsById($tid) . 'orders' . DIRECTORY_SEPARATOR;
        $found = null;
        if (@is_dir($ordersBase)) {
            $patterns = [
                $ordersBase . '*' . DIRECTORY_SEPARATOR . 'entry' . DIRECTORY_SEPARATOR . $baseName,
                $ordersBase . '*' . DIRECTORY_SEPARATOR . 'diagnosis' . DIRECTORY_SEPARATOR . $baseName,
                $ordersBase . '*' . DIRECTORY_SEPARATOR . 'delivery' . DIRECTORY_SEPARATOR . $baseName,
                $ordersBase . '*' . DIRECTORY_SEPARATOR . $baseName,
            ];
            foreach ($patterns as $p) {
                $m = @glob($p, GLOB_NOSORT);
                if (is_array($m) && !empty($m)) {
                    $found = $m[0];
                    break;
                }
            }
        }
        if ($found) {
            $relFs = substr($found, strlen($ordersBase));
            $relFs = str_replace('\\', '/', $relFs);
            $cache[$tid][$baseName] = $relFs;
            return $baseWebDir . $tenantPrefix . 'orders/' . $relFs;
        }
        $cache[$tid][$baseName] = null;
    }

    return $web;
}

function ensurePortalSchema(PDO $pdo, $tenant_id) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM work_orders")->fetchAll(PDO::FETCH_COLUMN);
        $hasVerification = in_array('verification_code', $cols, true);
        $hasApprovalStatus = in_array('approval_status', $cols, true);
        $hasSignature = in_array('approval_signature_path', $cols, true);
        $hasComment = in_array('approval_comment', $cols, true);
        $hasApprovedAt = in_array('approved_at', $cols, true);
        if (!$hasVerification) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(16) NULL AFTER serial_number, ADD INDEX idx_verification_tenant (tenant_id, verification_code)");
        }
        if (!$hasApprovalStatus) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER status");
        }
        if (!$hasSignature) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_signature_path VARCHAR(255) NULL AFTER approval_status");
        }
        if (!$hasComment) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_comment TEXT NULL AFTER approval_signature_path");
        }
        if (!$hasApprovedAt) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_comment");
        }
    } catch (Throwable $e) { error_log('ensurePortalSchema: ' . $e->getMessage()); }
}

function generateVerificationCode(int $length = 6): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function getTenantIdFromSlug(string $slug): ?int {
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode() && class_exists('DatabaseManager');
        $pdoUse = null;
        if ($perDatabase) {
            $pdoUse = DatabaseManager::master();
        } else {
            global $pdo;
            $pdoUse = $pdo ?? null;
        }
        if (!($pdoUse instanceof PDO)) return null;

        if (ctype_digit($slug)) {
            $id = (int)$slug;
            return $id ?: null;
        }
        $toSlug = function ($s) {
            $s = strtolower(trim((string)$s));
            if ($s === '') return '';
            if (function_exists('iconv')) {
                $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
                if ($t !== false && $t !== null) $s = $t;
            }
            $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
            $s = trim($s, '-');
            return $s;
        };

        if ($perDatabase) {
            $s = strtolower(trim($slug));
            if ($s === 'default') {
                try {
                    $id = $pdoUse->query("SELECT id FROM empresas WHERE estado IN ('active','provisioning','suspended') ORDER BY id ASC LIMIT 1")->fetchColumn();
                    return $id ? (int)$id : null;
                } catch (Throwable $e) { return null; }
            }
            $needle = $toSlug($slug);
            if ($needle === '') return null;
            $rows = $pdoUse->query("SELECT id, nombre FROM empresas WHERE estado IN ('active','provisioning','suspended') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                $name = (string)($r['nombre'] ?? '');
                if ($id && $name !== '' && $toSlug($name) === $needle) return $id;
            }
            return null;
        }

        $stmt = $pdoUse->prepare("SELECT id FROM tenants WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        $needle = $toSlug($slug);
        if ($needle === '') return null;
        $rows = $pdoUse->query("SELECT tenant_id, company_name, id FROM company_config ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $latest = [];
        foreach ($rows as $r) {
            $tid = (int)($r['tenant_id'] ?? 0);
            if ($tid && !isset($latest[$tid])) $latest[$tid] = (string)($r['company_name'] ?? '');
        }
        foreach ($latest as $tid => $name) {
            if ($name !== '' && $toSlug($name) === $needle) return (int)$tid;
        }
        return null;
    } catch (Throwable $e) { return null; }
}

/**
 * Obtiene el slug preferido para el tenant: primero el de la tabla tenants.slug,
 * y si está vacío, genera uno desde company_config.company_name (último registro).
 */
function getTenantPreferredSlug(int $tenant_id): ?string {
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode() && class_exists('DatabaseManager');
        if ($perDatabase) {
            $master = DatabaseManager::master();
            $name = '';
            try {
                $st = $master->prepare("SELECT nombre FROM empresas WHERE id = ? LIMIT 1");
                $st->execute([$tenant_id]);
                $name = trim((string)($st->fetchColumn() ?: ''));
            } catch (Throwable $e) {}
            if ($name === '') return null;
            if (function_exists('iconv')) {
                $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                if ($t !== false && $t !== null) $name = $t;
            }
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
            $slug = trim($slug, '-');
            return $slug !== '' ? $slug : null;
        }

        global $pdo;
        $slugDb = '';
        try {
            $st = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenant_id]);
            $slugDb = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
        if ($slugDb !== '') return $slugDb;
        $name = '';
        try {
            $sc = $pdo->prepare("SELECT company_name FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $sc->execute([$tenant_id]);
            $name = trim((string)($sc->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
        if ($name === '') return null;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($t !== false && $t !== null) $name = $t;
        }
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : null;
    } catch (Throwable $e) { return null; }
}

function ensureApprovedStatus(PDO $pdo, $tenant_id) {
    return;
}

function ensureOrderStatusHistorySchema(PDO $pdo) {
    try {
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_history'");
        $existsStmt->execute();
        $exists = (int)($existsStmt->fetchColumn() ?: 0) > 0;
        if (!$exists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS order_status_history (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    order_id INT(11) NOT NULL,
                    status VARCHAR(50) NOT NULL,
                    notes TEXT NULL,
                    changed_by INT(11) NULL,
                    tenant_id INT(11) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP()
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
            ");
            try {
                $pdo->exec("ALTER TABLE order_status_history ADD CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE");
            } catch (Throwable $e) {}
            try { $pdo->exec("CREATE INDEX idx_order_status_history_tenant ON order_status_history(tenant_id)"); } catch (Throwable $__) {}
        }
        $cols = $pdo->query("SHOW COLUMNS FROM order_status_history")->fetchAll(PDO::FETCH_ASSOC);
        $hasId = false; $isAuto = false;
        $hasTenant = false;
        foreach ($cols as $col) {
            if (($col['Field'] ?? '') === 'id') {
                $hasId = true;
                $isAuto = stripos((string)($col['Extra'] ?? ''), 'auto_increment') !== false;
            }
            if (($col['Field'] ?? '') === 'tenant_id') {
                $hasTenant = true;
            }
        }
        if (!$hasId) {
            $pdo->exec("ALTER TABLE order_status_history ADD COLUMN id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        } else {
            $pkCheck = $pdo->query("SHOW KEYS FROM order_status_history WHERE Key_name = 'PRIMARY'");
            if (!$pkCheck || $pkCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_status_history ADD PRIMARY KEY (id)");
            }
            if (!$isAuto) {
                $pdo->exec("ALTER TABLE order_status_history MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
            }
        }
        if (!$hasTenant) {
            try { $pdo->exec("ALTER TABLE order_status_history ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER changed_by"); } catch (Throwable $__) {}
            try {
                $pdo->exec("
                    UPDATE order_status_history osh
                    JOIN work_orders wo ON wo.id = osh.order_id
                    SET osh.tenant_id = wo.tenant_id
                    WHERE osh.tenant_id IS NULL OR osh.tenant_id = 0 OR osh.tenant_id = 1
                ");
            } catch (Throwable $__) {}
            try { $pdo->exec("CREATE INDEX idx_order_status_history_tenant ON order_status_history(tenant_id)"); } catch (Throwable $__) {}
        }
        // Asegurar que la columna status permite slugs de aprobación y bilingües
        try {
            $statusCol = null;
            foreach ($cols as $col) {
                if (($col['Field'] ?? '') === 'status') {
                    $statusCol = $col;
                    break;
                }
            }
            if ($statusCol) {
                $type = strtolower((string)($statusCol['Type'] ?? ''));
                if (strpos($type, 'enum(') === 0) {
                    $pdo->exec("ALTER TABLE order_status_history MODIFY status ENUM(
                        'pending','received','diagnosing','waiting_parts','repairing','testing','completed','ready','delivered','cancelled',
                        'approved','rejected',
                        'pendiente','asignado','diagnosticando','esperando_repuestos','reparando','testeando','completado','listo','entregado','cancelado',
                        'aprobado','rechazado'
                    ) NOT NULL");
                } else {
                    // Si no es ENUM, aseguramos longitud suficiente
                    $pdo->exec("ALTER TABLE order_status_history MODIFY status VARCHAR(50) NOT NULL");
                }
            }
        } catch (Throwable $e) {}
        // Asegurar FK de changed_by con ON DELETE SET NULL
        try { ensureOrderStatusHistoryChangedByFk($pdo); } catch (Throwable $__) {}
    } catch (Throwable $e) {}
}
/**
 * Formatea una fecha según la configuración de la empresa (o formato estándar)
 */
function formatCompanyDate($date, $include_time = false) {
    if (empty($date)) return '';
    
    $timestamp = is_int($date) ? $date : strtotime($date);
    if (!$timestamp) return $date;
    
    // Por ahora usamos formato fijo d/m/Y, idealmente vendría de company_settings
    $format = 'd/m/Y';
    if ($include_time) {
        $format .= ' H:i';
    }
    
    return date($format, $timestamp);
}

/**
 * Formatea una hora según la configuración de la empresa
 */
function formatCompanyTime($date) {
    return CompanySettings::formatTime($date);
}

/**
 * Formatea moneda según la configuración de la empresa
 */
function formatCompanyCurrency($amount, $showCode = false) {
    // Si no hay monto, devolver solo el símbolo (útil para inputs)
    if ($amount === '' || $amount === null) {
        $currency = CompanySettings::getCurrency();
        return $currency['symbol'];
    }
    return CompanySettings::formatCurrency($amount, $showCode);
}

/**
 * Alias para compatibilidad
 */
function formatCurrency($amount, $showCode = false) {
    return formatCompanyCurrency($amount, $showCode);
}

/**
 * Obtiene el número de teléfono completo (con prefijo si es necesario)
 */
function getCompanyFullPhone($phone) {
    if (empty($phone)) return '';
    // Aquí se podría agregar lógica para prefijos internacionales si se requiere
    return $phone;
}

/**
 * Obtiene el texto descriptivo de un estado
 */
function getStatusText($status) {
    $statuses = [
        'pendiente' => 'Pendiente',
        'esperando_aprobacion' => 'Esperando Aprobación',
        'asignado' => 'Asignado',
        'diagnosticando' => 'Diagnosticando',
        'esperando_repuestos' => 'Esperando Repuestos',
        'reparando' => 'Reparando',
        'testeando' => 'Testeando',
        'completado' => 'Completado',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
        'received' => 'Recibido',
        'diagnosing' => 'Diagnosticando',
        'waiting_parts' => 'Esperando Repuestos',
        'repairing' => 'Reparando',
        'testing' => 'Probando',
        'completed' => 'Completado',
        'delivered' => 'Entregado',
        'cancelled' => 'Cancelado',
        'approved' => 'Aprobado',
        'aprobado' => 'Aprobado',
        'rejected' => 'Rechazado',
        'rechazado' => 'Rechazado',
        'pending' => 'Pendiente',
        'in_progress' => 'En Progreso'
    ];
    return $statuses[$status] ?? ucfirst($status);
}

/**
 * Obtiene la clase CSS para el estado
 */
function getStatusClass($status) {
    $classes = [
        'pendiente' => 'badge bg-warning',
        'asignado' => 'badge bg-info',
        'diagnosticando' => 'badge bg-warning',
        'esperando_repuestos' => 'badge bg-secondary',
        'reparando' => 'badge bg-primary',
        'testeando' => 'badge bg-info',
        'completado' => 'badge bg-success',
        'entregado' => 'badge bg-success',
        'cancelado' => 'badge bg-danger',
        'received' => 'badge bg-info',
        'diagnosing' => 'badge bg-warning',
        'waiting_parts' => 'badge bg-secondary',
        'repairing' => 'badge bg-primary',
        'testing' => 'badge bg-info',
        'completed' => 'badge bg-success',
        'delivered' => 'badge bg-success',
        'cancelled' => 'badge bg-danger',
        'approved' => 'badge bg-success',
        'aprobado' => 'badge bg-success',
        'rejected' => 'badge bg-danger',
        'rechazado' => 'badge bg-danger',
        'pending' => 'badge bg-warning',
        'in_progress' => 'badge bg-primary'
    ];
    return $classes[$status] ?? 'badge bg-secondary';
}

/**
 * Obtiene el color para el estado
 */
function getStatusColor($status) {
    $colors = [
        'pendiente' => 'warning',
        'esperando_aprobacion' => 'warning',
        'asignado' => 'info',
        'diagnosticando' => 'warning',
        'esperando_repuestos' => 'secondary',
        'reparando' => 'primary',
        'testeando' => 'info',
        'completado' => 'success',
        'entregado' => 'success',
        'cancelado' => 'danger',
        'received' => 'info',
        'diagnosing' => 'warning',
        'waiting_parts' => 'secondary',
        'repairing' => 'primary',
        'testing' => 'info',
        'completed' => 'success',
        'delivered' => 'success',
        'cancelled' => 'danger',
        'approved' => 'success',
        'aprobado' => 'success',
        'rejected' => 'danger',
        'rechazado' => 'danger',
        'pending' => 'warning',
        'in_progress' => 'primary'
    ];
    return $colors[$status] ?? 'secondary';
}

function normalizeStatusSlug($slug) {
    $s = strtolower(trim((string)$slug));
    if ($s === '') return '';
    $map = [
        'esperando aprobacion' => 'esperando_aprobacion',
        'esperando-aprobacion' => 'esperando_aprobacion',
        'esperando_aprobación' => 'esperando_aprobacion',
        'esperando aprobación' => 'esperando_aprobacion',
        'esperandoaprobacion' => 'esperando_aprobacion',
        'esperando_aprovacion' => 'esperando_aprobacion',
        'waiting_authorization' => 'esperando_aprobacion',
        'waiting approval' => 'esperando_aprobacion',
        'pending_approval' => 'esperando_aprobacion',
        'pending' => 'pendiente',
        'received' => 'asignado',
        'diagnosing' => 'diagnosticando',
        'waiting_parts' => 'esperando_repuestos',
        'repairing' => 'reparando',
        'testing' => 'testeando',
        'completed' => 'completado',
        'delivered' => 'entregado',
        'cancelled' => 'cancelado',
        'canceled' => 'cancelado',
        'approved' => 'aprobado',
        'rejected' => 'rechazado'
    ];
    return $map[$s] ?? $s;
}

function getEffectiveStatusSlug($status, $approval_status) {
    $st = normalizeStatusSlug($status);
    if ($st === '' || $st === null) $st = 'pendiente';
    if ($st !== 'pendiente' && $st !== 'esperando_aprobacion') return $st;
    $apRaw = strtolower(trim((string)$approval_status));
    $ap = 'none';
    if (in_array($apRaw, ['pending','pendiente'], true)) $ap = 'pending';
    elseif (in_array($apRaw, ['approved','aprobado'], true)) $ap = 'approved';
    elseif (in_array($apRaw, ['rejected','rechazado'], true)) $ap = 'rejected';
    if ($ap === 'pending') return 'esperando_aprobacion';
    if ($ap === 'approved') return 'aprobado';
    if ($ap === 'rejected') return 'rechazado';
    return $st;
}

function getStatusEmoji($slug) {
    $s = normalizeStatusSlug($slug);
    $emojis = [
        'pendiente' => '⏳',
        'asignado' => '📥',
        'diagnosticando' => '🔍',
        'esperando_aprobacion' => '✍️',
        'aprobado' => '✍️',
        'esperando_repuestos' => '🛠️',
        'reparando' => '🔧',
        'testeando' => '🧪',
        'completado' => '✅',
        'entregado' => '🚚',
        'rechazado' => '❌',
        'cancelado' => '❌',
        'devolucion' => '↩️'
    ];
    return $emojis[$s] ?? '❓';
}

function formatRelativeTime($datetime) {
    $ts = is_numeric($datetime) ? intval($datetime) : strtotime((string)$datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;
    if ($diff < 60) return 'hace ' . $diff . 's';
    if ($diff < 3600) return 'hace ' . floor($diff/60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff/3600) . ' h';
    if ($diff < 2592000) return 'hace ' . floor($diff/86400) . ' días';
    if ($diff < 31536000) return 'hace ' . floor($diff/2592000) . ' meses';
    return 'hace ' . floor($diff/31536000) . ' años';
}
/**
 * Obtiene el texto descriptivo de la prioridad
 */
function getPriorityText($priority) {
    $priorities = [
        'low' => 'Baja',
        'medium' => 'Media',
        'high' => 'Alta',
        'urgent' => 'Urgente'
    ];
    return $priorities[$priority] ?? ucfirst($priority);
}

/**
 * Obtiene el color para la prioridad
 */
function getPriorityColor($priority) {
    $colors = [
        'low' => 'success',
        'medium' => 'warning',
        'high' => 'danger',
        'urgent' => 'danger'
    ];
    return $colors[$priority] ?? 'secondary';
}

/**
 * Obtiene configuración del sistema
 */
function cfg_get($key, $default = null) {
    global $pdo;
    ensureSystemConfigSchema();
    $tenant_id = getCurrentTenantId();
    if (!$tenant_id) return $default;
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
        if (hasTenantColumnCached($pdo, 'system_config')) {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? AND tenant_id = ?");
            $stmt->execute([$key, $tenantValue]);
        } else {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
            $stmt->execute([$key]);
        }
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function getCompanyPrefix($tenant_id = null) {
    global $pdo;
    $tid = $tenant_id ?: getCurrentTenantId();
    $override = cfg_get('order_prefix', null);
    if ($override !== null && $override !== '') {
        $s = preg_replace('/[^A-Za-z0-9]/', '', $override);
        return strtoupper($s ?: 'ORD');
    }
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tid;
        if (hasTenantColumnCached($pdo, 'company_config') && !isPerDatabaseMode()) {
            $stmt = $pdo->prepare("SELECT company_name FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tenantValue]);
        } else {
            $stmt = $pdo->prepare("SELECT company_name FROM company_config ORDER BY id DESC LIMIT 1");
            $stmt->execute();
        }
        $name = trim((string)$stmt->fetchColumn());
        if ($name === '') return 'ORD';
        $parts = preg_split('/\s+/', $name);
        $first = preg_replace('/[^A-Za-z0-9]/', '', $parts[0] ?? '');
        if ($first === '') return 'ORD';
        return strtoupper(substr($first, 0, 3));
    } catch (Exception $e) {
        return 'ORD';
    }
}

/**
 * Verifica si hay una sesión de caja abierta
 * @param PDO $pdo Instancia de la base de datos
 * @return bool
 */
function isCashSessionOpen($pdo) {
    if (!$pdo) return false;
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)getCurrentTenantId();
        if (hasTenantColumnCached($pdo, 'cash_sessions')) {
            $stmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE status = 'open' AND tenant_id = ? LIMIT 1");
            $stmt->execute([$tenantValue]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE status = 'open' LIMIT 1");
            $stmt->execute();
        }
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("Error al verificar sesión de caja: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene el ID de la sesión de caja abierta
 * @param PDO $pdo Instancia de la base de datos
 * @return int|null
 */
function getOpenCashSessionId($pdo) {
    if (!$pdo) return null;
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)getCurrentTenantId();
        if (hasTenantColumnCached($pdo, 'cash_sessions')) {
            $stmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE status = 'open' AND tenant_id = ? ORDER BY opening_date DESC LIMIT 1");
            $stmt->execute([$tenantValue]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY opening_date DESC LIMIT 1");
            $stmt->execute();
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    } catch (PDOException $e) {
        error_log("Error al obtener ID de sesión de caja: " . $e->getMessage());
        return null;
    }
}

/**
 * Guarda configuración del sistema
 */
function cfg_set($key, $value) {
    global $pdo;
    ensureSystemConfigSchema();
    $tenant_id = getCurrentTenantId();
    if (!$tenant_id) return false;
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
        if (!hasTenantColumnCached($pdo, 'system_config')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
            $stmt->execute([$key]);
            if ($stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                return $stmt->execute([$value, $key]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                return $stmt->execute([$key, $value]);
            }
        }
        // Verificar si existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ? AND tenant_id = ?");
        $stmt->execute([$key, $tenantValue]);
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ? AND tenant_id = ?");
            return $stmt->execute([$value, $key, $tenantValue]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?)");
            return $stmt->execute([$tenantValue, $key, $value]);
        }
    } catch (Exception $e) {
        return false;
    }
}

function ensureTenantCountersSchema($pdo) {
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $tbl = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenant_counters'");
        $tbl->execute([$db]);
        if ((int)$tbl->fetchColumn() === 0) {
            $pdo->exec("CREATE TABLE tenant_counters (tenant_id INT(11) NOT NULL, entity VARCHAR(64) NOT NULL, counter INT(11) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (tenant_id, entity)) ENGINE=InnoDB");
        }
    } catch (Throwable $__) {}
}

function getNextTenantSequence($pdo, $tenant_id, $entity, $startAt) {
    if (!$tenant_id || !$entity) return 1;
    ensureTenantCountersSchema($pdo);
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
        $ins = $pdo->prepare("INSERT INTO tenant_counters (tenant_id, entity, counter, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)");
        $ins->execute([$tenantValue, $entity, max(0, (int)$startAt - 1)]);
        $upd = $pdo->prepare("UPDATE tenant_counters SET counter = LAST_INSERT_ID(counter + 1), updated_at = NOW() WHERE tenant_id = ? AND entity = ?");
        $upd->execute([$tenantValue, $entity]);
        $val = (int)$pdo->lastInsertId();
        if ($val <= 0) {
            $cur = $pdo->prepare("SELECT counter FROM tenant_counters WHERE tenant_id = ? AND entity = ? LIMIT 1");
            $cur->execute([$tenantValue, $entity]);
            $val = (int)($cur->fetchColumn() ?: 1);
        }
        return $val;
    } catch (Throwable $__) {
        return (int)max(1, $startAt);
    }
}

function generateNextInvoiceNumber($pdo) {
    $tenant_id = getCurrentTenantId();
    if (!$tenant_id) return 'FAC-00001';
    try {
        $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
        if (hasTenantColumnCached($pdo, 'invoices')) {
            $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tenantValue]);
        } else {
            $stmt = $pdo->prepare("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1");
            $stmt->execute();
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row && !empty($row['invoice_number']) ? trim($row['invoice_number']) : '';
        $cfgVal = (int)cfg_get('invoice_next_number', 0);
        $prefCfg = (string)cfg_get('invoice_prefix', 'FAC-');
        $digitsCfg = (int)cfg_get('invoice_digits', 5);
        $prefix = $prefCfg ?: 'FAC-';
        $digitsLen = $digitsCfg > 0 ? $digitsCfg : 5;
        $dbNum = 0;
        if ($current !== '') {
            if (preg_match('/^([^\d]*)(\d+)$/', $current, $matches)) {
                $prefix = $matches[1] !== '' ? $matches[1] : $prefix;
                $digitsLen = strlen($matches[2]);
                $dbNum = (int)$matches[2];
            } elseif (ctype_digit($current)) {
                $dbNum = (int)$current;
                $digitsLen = max(1, strlen($current));
            }
        }
        $startAt = max($dbNum, $cfgVal) + 1;
        if (hasTenantColumnCached($pdo, 'invoices')) {
            try { $pdo->exec("ALTER TABLE invoices ADD UNIQUE KEY uq_invoice_tenant (tenant_id, invoice_number)"); } catch (Throwable $__) {}
        }
        $next = getNextTenantSequence($pdo, $tenantValue, 'invoices', $startAt);
        return $prefix . str_pad((string)$next, $digitsLen, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        return 'FAC-00001';
    }
}

/**
 * Envía un correo del sistema usando PHPMailer
 */
function sendSystemEmail($to, $subject, $body, $isHtml = true, $altBody = null, $attachments = []) {
    global $pdo;
    $GLOBALS['core_last_email_error'] = '';
    $GLOBALS['core_last_email_debug'] = [];
    
    // Cargar PHPMailer si no está cargado
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $vendorDir = __DIR__ . '/../vendor/phpmailer/phpmailer/src';
        if (file_exists($vendorDir . '/PHPMailer.php')) {
            require_once $vendorDir . '/PHPMailer.php';
            require_once $vendorDir . '/SMTP.php';
            require_once $vendorDir . '/Exception.php';
        } else {
            error_log("PHPMailer no encontrado en $vendorDir");
            return false;
        }
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Obtener configuración SMTP
        $smtp_host = cfg_get('smtp_host');
        $smtp_user = cfg_get('smtp_user');
        $smtp_pass = cfg_get('smtp_pass');
        $smtp_port = cfg_get('smtp_port', 587);
        $smtp_secure = cfg_get('smtp_encryption', 'tls');
        $from_email = cfg_get('smtp_from_email', $smtp_user);
        $from_name = cfg_get('smtp_from_name', 'System');

        /* DEBUG EXTREMO: Verificar valores antes de enviar
        error_log("--- SMTP ATTEMPT ---");
        error_log("Host: " . $smtp_host);
        error_log("User: " . $smtp_user);
        error_log("Port: " . $smtp_port);
        // NO loguear password por seguridad, solo longitud
        error_log("Pass Length: " . strlen($smtp_pass));
        */

        if (empty($smtp_host) || empty($smtp_user)) {
            if (($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost') {
                $mail->isMail();
                $from_email = $from_email ?: 'no-reply@localhost';
            } else {
                error_log("Configuración SMTP incompleta");
                return false;
            }
        } else {
            if ((int)$smtp_port === 465 && strtolower((string)$smtp_secure) === 'tls') {
                $smtp_secure = 'ssl';
            }
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = $smtp_secure;
            $mail->Port = $smtp_port;
            $mail->SMTPKeepAlive = false;
            $mail->Timeout = 12;
        }
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);

        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $body = fixEmailTextEncoding($body);
        $mail->Body = $body;
        
        // Debug controlado: localhost o flag de configuración
        $enableDebug = ($_SERVER['SERVER_NAME'] === 'localhost') || (trim((string)cfg_get('smtp_debug', '0')) === '1');
        if ($enableDebug) {
            $mail->SMTPDebug = 2;
        }
        $mail->Debugoutput = function($str, $level) {
            $GLOBALS['core_last_email_debug'][] = $str;
        };

        /*
        $mail->Debugoutput = function($str, $level) {
            error_log("SMTP DEBUG: $str");
        };
        // Forzar debug siempre para diagnosticar, no solo en localhost
        $mail->SMTPDebug = 2; 
        
        /* 
        // Habilitar debug si estamos en localhost
        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            $mail->SMTPDebug = 2; // Mostrar logs detallados
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP DEBUG: $str");
            };
        }
        */

        if ($altBody) {
            $mail->AltBody = fixEmailTextEncoding($altBody);
        }

        if (!empty($attachments)) {
            foreach ($attachments as $att) {
                if (file_exists($att)) {
                    $mail->addAttachment($att);
                }
            }
        }

        $mail->send();
        $GLOBALS['core_last_email_error'] = '';
        return true;
    } catch (Exception $e) {
        error_log("Error enviando email: " . $mail->ErrorInfo);
        $GLOBALS['core_last_email_error'] = $mail->ErrorInfo ?: ($e->getMessage() ?? 'Error desconocido');
        return false;
    }
}

function getLastEmailError() {
    return isset($GLOBALS['core_last_email_error']) ? (string)$GLOBALS['core_last_email_error'] : '';
}
function getLastEmailDebug() {
    $d = $GLOBALS['core_last_email_debug'] ?? [];
    if (is_array($d)) {
        return implode("\n", $d);
    }
    return (string)$d;
}

function fixEmailTextEncoding($s) {
    if (!is_string($s) || $s === '') return $s;
    $map = [
        'Â¡' => '¡',
        'Â¿' => '¿',
        'Â' => '',
        'â?¢' => '•',
        'â€™' => '’',
        'â€œ' => '“',
        'â€' => '”',
        'â€“' => '–',
        'â€”' => '—'
    ];
    return strtr($s, $map);
}
/**
 * Parsea un monto de moneda a float
 * Soporta formatos: 1.000,00 (EU/LATAM) y 1,000.00 (US)
 */
function parseCurrency($amount) {
    if (empty($amount)) return 0.00;
    $amount = trim((string)$amount);
    $hasDot = strpos($amount, '.') !== false;
    $hasComma = strpos($amount, ',') !== false;
    if ($hasDot && $hasComma) {
        $lastDot = strrpos($amount, '.');
        $lastComma = strrpos($amount, ',');
        if ($lastComma > $lastDot) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } else {
            $amount = str_replace(',', '', $amount);
        }
    } elseif ($hasComma) {
        // Si solo hay comas y parece miles (ej: 1,000 o 1,000,000), quitar comas
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $amount)) {
            $amount = str_replace(',', '', $amount);
        } else {
            // Tratar coma como decimal (ej: 1,50 -> 1.50)
            $amount = str_replace(',', '.', $amount);
        }
    } elseif ($hasDot) {
        // Si solo hay puntos y parece miles (ej: 1.000 o 1.000.000), quitar puntos
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $amount)) {
            $amount = str_replace('.', '', $amount);
        }
        // Si es decimal con punto, floatval lo maneja
    }
    return (float)$amount;
}

/**
 * Asegura que existan accesorios por defecto para el tenant actual
 * Inserta una lista mínima si la tabla está vacía para ese tenant
 */
function ensureDefaultAccessories(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $hasTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'equipment_accessories') : false;
        if ($hasTenant) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM equipment_accessories WHERE is_active = 1 AND tenant_id = ?");
            $check->execute([$tenantValue]);
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM equipment_accessories WHERE is_active = 1");
            $check->execute([]);
        }
        $count = (int)$check->fetchColumn();
        if ($count > 0) return;
        $defaults = [
            'Cargador',
            'Cable USB',
            'Memoria SD',
            'SIM',
            'Funda/Case',
            'Teclado',
            'Mouse',
            'Audífonos',
            'Batería externa'
        ];
        $insert = $hasTenant
            ? $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, is_active, sort_order, category) VALUES (?, ?, 1, ?, 'general')")
            : $pdo->prepare("INSERT INTO equipment_accessories (name, is_active, sort_order, category) VALUES (?, 1, ?, 'general')");
        $order = 0;
        foreach ($defaults as $name) {
            $order++;
            if ($hasTenant) { $insert->execute([$tenantValue, $name, $order]); }
            else { $insert->execute([$name, $order]); }
        }
    } catch (Exception $e) {
        // Silenciar errores de siembra para no bloquear la vista
        error_log("ensureDefaultAccessories error: " . $e->getMessage());
    }
}

/**
 * Garantiza que equipment_accessories tenga tenant_id e índice único por tenant
 */
function ensureAccessoriesTenant(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $col = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'tenant_id'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            // Asignar el tenant actual a los registros existentes
            $upd = $pdo->prepare("UPDATE equipment_accessories SET tenant_id = ? WHERE tenant_id IS NULL OR tenant_id = 1");
            $upd->execute([$tenantValue]);
            // Índice
            try { $pdo->exec("CREATE INDEX idx_ea_tenant ON equipment_accessories(tenant_id)"); } catch (Exception $e) {}
            // Ajustar unique por nombre
            try { $pdo->exec("ALTER TABLE equipment_accessories DROP INDEX name"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE equipment_accessories ADD UNIQUE KEY unique_name_tenant (name, tenant_id)"); } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        error_log("ensureAccessoriesTenant error: " . $e->getMessage());
    }
}

/**
 * Normaliza filas con tenant_id NULL asignándolas al tenant actual
 */
function normalizeAccessoriesTenants(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $upd = $pdo->prepare("UPDATE equipment_accessories SET tenant_id = ? WHERE tenant_id IS NULL");
        $upd->execute([$tenantValue]);
    } catch (Exception $e) {
        error_log("normalizeAccessoriesTenants error: " . $e->getMessage());
    }
}
function ensureDeviceTypesTenant(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $col = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'tenant_id'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE device_types ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_dt_tenant ON device_types(tenant_id)"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE device_types DROP INDEX name"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE device_types ADD UNIQUE KEY unique_dt_name_tenant (name, tenant_id)"); } catch (Exception $e) {}
        }
        $upd = $pdo->prepare("UPDATE device_types SET tenant_id = ? WHERE tenant_id IS NULL");
        $upd->execute([$tenantValue]);
    } catch (Exception $e) { error_log("ensureDeviceTypesTenant error: " . $e->getMessage()); }
}

function ensureBrandsTenant(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $col = $pdo->query("SHOW COLUMNS FROM brands LIKE 'tenant_id'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE brands ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_brand_tenant ON brands(tenant_id)"); } catch (Exception $e) {}
        }
        $upd = $pdo->prepare("UPDATE brands SET tenant_id = ? WHERE tenant_id IS NULL");
        $upd->execute([$tenantValue]);
    } catch (Exception $e) { error_log("ensureBrandsTenant error: " . $e->getMessage()); }
}

function ensureModelsTenant(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $col = $pdo->query("SHOW COLUMNS FROM models LIKE 'tenant_id'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE models ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_model_tenant ON models(tenant_id)"); } catch (Exception $e) {}
        }
        $upd = $pdo->prepare("UPDATE models SET tenant_id = ? WHERE tenant_id IS NULL");
        $upd->execute([$tenantValue]);
    } catch (Exception $e) { error_log("ensureModelsTenant error: " . $e->getMessage()); }
}

function normalizeCatalogsToTenant(PDO $pdo, $tenant_id) {
    $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
    ensureDeviceTypesTenant($pdo, $tenant_id);
    ensureBrandsTenant($pdo, $tenant_id);
    ensureModelsTenant($pdo, $tenant_id);
    try {
        $tables = ['device_types','brands','models'];
        foreach ($tables as $t) {
            $stmtCur = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE tenant_id = ?");
            $stmtCur->execute([$tenantValue]);
            $countCur = (int)$stmtCur->fetchColumn();
            $stmtOne = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE tenant_id = 1");
            $stmtOne->execute();
            $countOne = (int)$stmtOne->fetchColumn();
            if ($countCur === 0 && $countOne > 0 && $tenantValue !== 1) {
                $upd = $pdo->prepare("UPDATE `$t` SET tenant_id = ? WHERE tenant_id = 1");
                $upd->execute([$tenantValue]);
            }
        }
    } catch (Exception $e) {
        error_log("normalizeCatalogsToTenant migrate error: " . $e->getMessage());
    }
}

function normalizeModelRelationsTenant(PDO $pdo, $tenant_id) {
    try {
        $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
        $sql = "SELECT m.id, m.name, m.brand_id, m.device_type_id, b.id AS b_id, b.name AS b_name, b.tenant_id AS b_tenant, dt.id AS dt_id, dt.name AS dt_name, dt.tenant_id AS dt_tenant
                FROM models m
                LEFT JOIN brands b ON b.id = m.brand_id
                LEFT JOIN device_types dt ON dt.id = m.device_type_id
                WHERE m.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantValue]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mid = (int)$row['id'];
            $bid = (int)($row['brand_id'] ?? 0);
            $b_id = (int)($row['b_id'] ?? 0);
            $b_tenant = $row['b_tenant'] !== null ? (int)$row['b_tenant'] : null;
            $dtid = (int)($row['device_type_id'] ?? 0);
            $dt_id = (int)($row['dt_id'] ?? 0);
            $dt_tenant = $row['dt_tenant'] !== null ? (int)$row['dt_tenant'] : null;
            if ($bid > 0 && $b_id > 0 && $b_tenant !== null && $b_tenant !== $tenantValue) {
                $bname = $row['b_name'] ?? null;
                if ($bname) {
                    $findB = $pdo->prepare("SELECT id FROM brands WHERE name = ? AND tenant_id = ? LIMIT 1");
                    $findB->execute([$bname, $tenantValue]);
                    $targetB = $findB->fetchColumn();
                    if (!$targetB) {
                        $insB = $pdo->prepare("INSERT INTO brands (tenant_id, name, is_active) VALUES (?, ?, 1)");
                        $insB->execute([$tenantValue, $bname]);
                        $targetB = $pdo->lastInsertId();
                    }
                    if ($targetB && (int)$targetB !== $bid) {
                        $upd = $pdo->prepare("UPDATE models SET brand_id = ? WHERE id = ? AND tenant_id = ?");
                        $upd->execute([(int)$targetB, $mid, $tenantValue]);
                    }
                }
            }
            if ($dtid > 0 && $dt_id > 0 && $dt_tenant !== null && $dt_tenant !== $tenantValue) {
                $dtname = $row['dt_name'] ?? null;
                if ($dtname) {
                    $findDt = $pdo->prepare("SELECT id FROM device_types WHERE name = ? AND tenant_id = ? LIMIT 1");
                    $findDt->execute([$dtname, $tenantValue]);
                    $targetDt = $findDt->fetchColumn();
                    if (!$targetDt) {
                        $insDt = $pdo->prepare("INSERT INTO device_types (tenant_id, name, is_active, is_visible, sort_order) VALUES (?, ?, 1, 1, 0)");
                        $insDt->execute([$tenantValue, $dtname]);
                        $targetDt = $pdo->lastInsertId();
                    }
                    if ($targetDt && (int)$targetDt !== $dtid) {
                        $upd = $pdo->prepare("UPDATE models SET device_type_id = ? WHERE id = ? AND tenant_id = ?");
                        $upd->execute([(int)$targetDt, $mid, $tenantValue]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("normalizeModelRelationsTenant error: " . $e->getMessage());
    }
}

function ensureOrderPartsTenant(PDO $pdo, $tenant_id) {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM order_parts LIKE 'tenant_id'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE order_parts ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_order_parts_tenant ON order_parts(tenant_id)"); } catch (Exception $e) {}
            try { $pdo->exec("UPDATE order_parts op JOIN work_orders wo ON wo.id = op.order_id SET op.tenant_id = wo.tenant_id WHERE op.tenant_id IS NULL OR op.tenant_id = 1"); } catch (Exception $e) {}
        }
    } catch (Exception $e) { error_log("ensureOrderPartsTenant error: " . $e->getMessage()); }
}
/**
 * Registra una actividad en el log
 */
function logActivity($user_id, $action, $table_name = null, $record_id = null) {
    global $pdo;
    try {
        // Verificar si la tabla activity_log tiene columna 'details' o 'table_name'
        // Asumiremos una estructura genérica o intentaremos adaptar
        // Si no existe, fallará silenciosamente
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, table_name, record_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $table_name, $record_id]);
    } catch (Exception $e) {
        // Intentar fallback si falla (por ejemplo, si la tabla usa 'details' en lugar de table_name/record_id)
        try {
             $details = ($table_name ? "Table: $table_name" : "") . ($record_id ? ", ID: $record_id" : "");
             $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
             $stmt->execute([$user_id, $action, $details]);
        } catch (Exception $e2) {
            // Silenciar error final
        }
    }
}

/**
 * Verifica si la sesión actual es de administrador
 */
function isAdminSession() {
    $role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
    $role = str_replace(['-', ' '], '_', $role);
    $role = preg_replace('/_+/', '_', $role);
    return in_array($role, ['admin', 'administrador', 'administrator', 'owner', 'super_admin', 'superadmin'], true);
}

/**
 * Obtiene la URL base del sistema
 */
function getSystemBaseUrl() {
    $xfProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443) || ($xfProto === 'https');
    $protocol = $isHttps ? "https://" : "http://";

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }

    $basePath = '/';
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(__DIR__ . '/..');
    if ($docRoot && $appRoot) {
        $docRootN = rtrim(str_replace('\\', '/', $docRoot), '/');
        $appRootN = rtrim(str_replace('\\', '/', $appRoot), '/');
        if ($docRootN !== '' && strpos($appRootN, $docRootN) === 0) {
            $rel = substr($appRootN, strlen($docRootN));
            $rel = '/' . ltrim((string)$rel, '/');
            $basePath = rtrim($rel, '/') . '/';
            if ($basePath === '//') { $basePath = '/'; }
        }
    }

    return $protocol . $host . $basePath;
}

// 2FA Helper Functions
function generateBase32Secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function verifyTOTP($otp, $secret) {
    $timestamp = floor(time() / 30);
    $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32charsFlipped = array_flip(str_split($base32chars));
    
    $secret = strtoupper($secret);
    $paddingCharCount = substr_count($secret, '=');
    $allowedValues = array(6, 4, 3, 1, 0);
    if (!in_array($paddingCharCount, $allowedValues)) return false;
    
    for ($i = 0; $i < 4; $i++) {
        if ($paddingCharCount == $allowedValues[$i] &&
            substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) return false;
    }
    
    $secret = str_replace('=', '', $secret);
    $secret = str_split($secret);
    $binaryString = '';
    
    foreach ($secret as $char) {
        if (!isset($base32charsFlipped[$char])) return false;
        $binaryString .= str_pad(base_convert($base32charsFlipped[$char], 10, 2), 5, '0', STR_PAD_LEFT);
    }
    
    $secretKey = '';
    $binaryArray = str_split($binaryString, 8);
    foreach ($binaryArray as $bin) {
        $secretKey .= chr(bindec($bin));
    }
    
    // Check current, previous, and next intervals for clock drift
    // Increased tolerance to +/- 3 intervals (90 seconds) to handle local server time drift
    for ($i = -3; $i <= 3; $i++) {
        $tm = $timestamp + $i;
        $time = pack('N*', 0) . pack('N*', $tm);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $hashPart = substr($hash, $offset, 4);
        $value = unpack('N', $hashPart);
        $value = $value[1];
        $value = $value & 0x7FFFFFFF;
        $modulo = $value % 1000000;
        if (str_pad($modulo, 6, '0', STR_PAD_LEFT) === $otp) {
            return true;
        }
    }
    return false;
}

/**
 * Wrapper de PDO para manejo seguro de Multi-tenancy
 */
class TenantPDO extends PDO {
    private $tenant_id;
    private $is_super_admin_context = false;

    public function __construct($dsn, $username, $passwd, $options, $tenant_id = null) {
        parent::__construct($dsn, $username, $passwd, $options);
        $this->tenant_id = $tenant_id;
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TenantPDOStatement::class, [$this]]);
    }

    public function setTenantId($id) {
        $this->tenant_id = $id;
    }

    public function getTenantId() {
        return $this->tenant_id;
    }

    // Permitir bypassear el tenant scope para tareas administrativas
    public function sudo() {
        $this->is_super_admin_context = true;
    }
}

/**
 * Statement Wrapper para inyectar tenant_id (Concepto Avanzado)
 * NOTA: En PHP nativo sin un Query Builder, inyectar SQL automáticamente es riesgoso y complejo.
 * Por ahora, usaremos este wrapper para facilitar la depuración y validación manual.
 */
class TenantPDOStatement extends PDOStatement {
    protected $pdo;

    protected function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function execute(?array $input_parameters = null): bool {
        try {
            $mode = getenv('SAAS_DB_MODE');
            $mode = is_string($mode) ? strtolower(trim($mode)) : '';
            if ($mode === 'per_database' || $mode === 'per-db' || $mode === 'perdb') {
                return parent::execute($input_parameters);
            }
            $sql = isset($this->queryString) ? strtolower((string)$this->queryString) : '';
            $multi = [
                'work_orders','invoices','invoice_items','invoice_payments',
                'clients','cash_sessions','cash_income','cash_expenses',
                'inventory_products','models','brands','device_types','system_config'
            ];
            $mentionsTenant = strpos($sql, 'tenant_id') !== false;
            $targetsMentioned = false;
            foreach ($multi as $t) {
                if (strpos($sql, ' '.$t.' ') !== false || strpos($sql, ' '.$t."\n") !== false || strpos($sql, ' '.$t.'.') !== false) {
                    $targetsMentioned = true;
                    break;
                }
            }
            if ($targetsMentioned && !$mentionsTenant) {
                debugLog('WARN: query without tenant_id => ' . substr($sql, 0, 200));
            }
        } catch (Throwable $e) {}
        return parent::execute($input_parameters);
    }
}

// Inicializar Tenant Context globalmente si está disponible
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Asegurar que getCurrentTenantId esté disponible
if (!function_exists('getCurrentTenantId')) {
    function getCurrentTenantId() {
        $tid = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
        if ($tid > 0) { return $tid; }
        if (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) {
            $_SESSION['tenant_id'] = 1;
            return 1;
        }
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        try {
            if ($uid > 0 && isset($GLOBALS['pdo'])) {
                $stmt = $GLOBALS['pdo']->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['tenant_id'])) {
                    $_SESSION['tenant_id'] = (int)$row['tenant_id'];
                    return (int)$row['tenant_id'];
                }
            }
            if (isset($GLOBALS['pdo'])) {
                $stmt2 = $GLOBALS['pdo']->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                $val = $stmt2 ? $stmt2->fetchColumn() : null;
                if ($val) {
                    $_SESSION['tenant_id'] = (int)$val;
                    return (int)$val;
                }
            }
        } catch (Exception $e) {
            // Silenciar
        }
        $_SESSION['tenant_id'] = 1;
        return 1;
    }
}

if (!function_exists('isPerDatabaseMode')) {
    function isPerDatabaseMode(): bool {
        $mode = getenv('SAAS_DB_MODE');
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        if ($mode === '') {
            $envLocalPath = __DIR__ . DIRECTORY_SEPARATOR . '.env.local';
            if (is_file($envLocalPath) && is_readable($envLocalPath)) {
                $envLocal = file_get_contents($envLocalPath);
                if (is_string($envLocal)) {
                    if (preg_match('/^\s*SAAS_DB_MODE\s*=\s*(per_database|per-db|perdb)\s*$/mi', $envLocal, $m)) {
                        $mode = strtolower(trim((string)($m[1] ?? '')));
                    }
                }
            }
        }

        if ($mode === '' && class_exists('DatabaseManager')) {
            try {
                $m = DatabaseManager::mode();
                $m = is_string($m) ? strtolower(trim($m)) : '';
                if ($m !== '') {
                    $mode = $m;
                }
            } catch (Throwable $e) {
            }
        }

        return $mode === 'per_database' || $mode === 'per-db' || $mode === 'perdb';
    }
}

if (!function_exists('generateSaasLicenseCode')) {
    function generateSaasLicenseCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8, 4);
    }
}

/**
 * Asegura tenant_id y unicidad por tenant en una tabla dada
 * $uniqueCols: columnas que deben ser únicas por tenant (ej: ['name'] -> UNIQUE(name, tenant_id))
 */
function ensureTableTenant(PDO $pdo, $table, array $uniqueCols = [], $assignTenantId = null) {
    try {
        $t = $pdo->prepare("SHOW TABLES LIKE ?");
        $t->execute([(string)$table]);
        if (!$t->fetchColumn()) {
            return;
        }
        $has = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'tenant_id'");
        if (!$has || $has->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1");
            try { $pdo->exec("CREATE INDEX idx_{$table}_tenant ON `$table`(tenant_id)"); } catch (Exception $e) {}
        }
        if (!empty($uniqueCols)) {
            foreach ($uniqueCols as $col) {
                try { $pdo->exec("ALTER TABLE `$table` DROP INDEX `$col`"); } catch (Exception $e) {}
            }
            $idxName = "uq_{$table}_" . implode('_', $uniqueCols) . "_tenant";
            $cols = implode(',', array_map(function($c){ return "`$c`"; }, $uniqueCols));
            try { $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$idxName` ($cols, tenant_id)"); } catch (Exception $e) {}
        }
        if ($assignTenantId !== null) {
            $stmt = $pdo->prepare("UPDATE `$table` SET tenant_id = ? WHERE tenant_id IS NULL");
            $stmt->execute([$assignTenantId]);
        }
    } catch (Exception $e) {
    }
}

/**
 * Bootstrap: asegura esquema multi-tenant para tablas clave
 */
function ensureTenantBootstrap(PDO $pdo, $tenant_id) {
    if ($tenant_id === null) return;
    $tenantValue = (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) ? 1 : (int)$tenant_id;
    if ($tenantValue <= 0) { $tenantValue = 1; }
    $list = [
        ['table' => 'equipment_accessories', 'unique' => ['name']],
        ['table' => 'payment_methods', 'unique' => ['name']],
        ['table' => 'payment_method_accounts', 'unique' => []],
        ['table' => 'device_types', 'unique' => ['name']],
        ['table' => 'brands', 'unique' => ['name']],
        ['table' => 'models', 'unique' => []],
        ['table' => 'clients', 'unique' => []],
        ['table' => 'suppliers', 'unique' => []],
        ['table' => 'inventory_products', 'unique' => ['sku']],
        ['table' => 'inventory_movements', 'unique' => []],
        ['table' => 'cash_income', 'unique' => []],
        ['table' => 'cash_expenses', 'unique' => []],
        ['table' => 'cash_sessions', 'unique' => []],
        ['table' => 'invoices', 'unique' => ['invoice_number']],
        ['table' => 'invoice_items', 'unique' => []],
        ['table' => 'invoice_payments', 'unique' => []],
        ['table' => 'work_orders', 'unique' => []],
        ['table' => 'technical_reports', 'unique' => []],
        ['table' => 'order_statuses', 'unique' => ['slug']],
        ['table' => 'system_config', 'unique' => ['config_key']],
        ['table' => 'company_settings', 'unique' => []],
    ];
    foreach ($list as $it) {
        ensureTableTenant($pdo, $it['table'], $it['unique'], $tenantValue);
    }
}

function ensureDeleteQueueSchema() {
    global $pdo;
    if (!$pdo) { return; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS delete_queue (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, type VARCHAR(64) NOT NULL, payload TEXT, status VARCHAR(16) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        try { $pdo->query("SHOW COLUMNS FROM delete_queue LIKE 'tenant_id'"); } catch (Throwable $e) { $pdo->exec("ALTER TABLE delete_queue ADD COLUMN tenant_id INT NOT NULL"); }
    } catch (Throwable $e) {}
}

function enqueueDeleteJob($tenant_id, $type, array $payload) {
    global $pdo;
    if (!$pdo) { return; }
    ensureDeleteQueueSchema();
    try {
        $stmt = $pdo->prepare("INSERT INTO delete_queue (tenant_id, type, payload, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$tenant_id, $type, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {}
}

function getTenantUploadsFsById($tenant_id) {
    $base = rtrim(__DIR__ . '/../uploads', '/\\');
    return $base . DIRECTORY_SEPARATOR . $tenant_id . DIRECTORY_SEPARATOR;
}

function getTenantStorageFsById($tenant_id) {
    $base = rtrim(__DIR__ . '/../storage/tenants', '/\\');
    return $base . DIRECTORY_SEPARATOR . $tenant_id . DIRECTORY_SEPARATOR;
}

function deletePathRecursive($path) {
    $p = rtrim($path, '/\\');
    if (!file_exists($p)) { return true; }
    if (is_file($p) || is_link($p)) { return @unlink($p); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if ($file->isDir()) { @rmdir($file->getPathname()); } else { @unlink($file->getPathname()); }
    }
    return @rmdir($p);
}

function deleteOrderAssets($tenant_id, $order_id) {
    $uploadsTenant = getTenantUploadsFsById($tenant_id);
    $storageTenant = getTenantStorageFsById($tenant_id);
    $dirs = [];
    $dirs[] = $uploadsTenant . 'orders' . DIRECTORY_SEPARATOR . $order_id;
    $dirs[] = rtrim(__DIR__ . '/../../uploads/orders', '/\\') . DIRECTORY_SEPARATOR . $order_id;
    $dirs[] = $storageTenant . 'orders' . DIRECTORY_SEPARATOR . $order_id;
    foreach ($dirs as $d) { deletePathRecursive($d); }
}

function cleanupTenantFiles($tenant_id, ?array $targets = null) {
    global $pdo;
    $uploads = getTenantUploadsFsById($tenant_id);
    $storage = getTenantStorageFsById($tenant_id);
    $preserveDirs = ['brands','logos','backups','users'];
    $adminPhotos = [];
    try {
        $stmt = $pdo->prepare("SELECT id, photo FROM users WHERE tenant_id = ? AND role IN ('admin','administrador','administrator','owner','super_admin','superadmin')");
        $stmt->execute([$tenant_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (!empty($u['photo'])) { $adminPhotos[] = $u['photo']; }
        }
    } catch (Throwable $e) {}
    $bases = [$uploads,$storage];
    foreach ($bases as $base) {
        if (!is_dir($base)) { continue; }
        $items = scandir($base) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $full = $base . $item;
            if (is_dir($full)) {
                if (in_array($item, $preserveDirs, true)) {
                    if ($item === 'users') {
                        $userDir = rtrim($full, '/\\') . DIRECTORY_SEPARATOR;
                        $files = scandir($userDir) ?: [];
                        foreach ($files as $f) {
                            if ($f === '.' || $f === '..') { continue; }
                            if (is_file($userDir . $f) && !in_array($f, $adminPhotos, true)) { @unlink($userDir . $f); }
                        }
                    }
                    continue;
                }
                if ($targets === null || in_array($item, $targets, true)) { deletePathRecursive($full); }
            } else {
                if ($targets === null || in_array(basename($full), $targets, true)) { @unlink($full); }
            }
        }
    }
}

function listTenantCleanup($tenant_id, ?array $targets = null) {
    $uploads = getTenantUploadsFsById($tenant_id);
    $storage = getTenantStorageFsById($tenant_id);
    $preserveDirs = ['brands','logos','backups','users'];
    $result = ['delete'=>[],'preserve'=>[]];
    $bases = [$uploads,$storage];
    foreach ($bases as $base) {
        if (!is_dir($base)) { continue; }
        $items = scandir($base) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $full = $base . $item;
            if (is_dir($full)) {
                if (in_array($item, $preserveDirs, true)) { $result['preserve'][] = $full; continue; }
                if ($targets === null || in_array($item, $targets, true)) { $result['delete'][] = $full; }
            } else {
                if ($targets === null || in_array(basename($full), $targets, true)) { $result['delete'][] = $full; }
            }
        }
    }
    return $result;
}

function moveUploadedFileCross($tmp, $target) {
    $ok = false;
    if (is_uploaded_file($tmp)) {
        $ok = move_uploaded_file($tmp, $target);
    } else {
        $ok = @rename($tmp, $target);
    }
    if (!$ok || !file_exists($target)) {
        if (is_file($tmp)) {
            @copy($tmp, $target);
            $ok = file_exists($target);
            if ($ok) { @chmod($target, 0644); }
        }
    }
    return ($ok && file_exists($target));
}

function ensureTenantSubdirFs($tenant_id, $subdir) {
    $base = rtrim(getTenantUploadsFsById($tenant_id), '/\\');
    $path = $base . DIRECTORY_SEPARATOR . trim($subdir, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($path)) { @mkdir($path, 0755, true); }
    return $path;
}

function sanitizeFileBasename($name) {
    $n = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return $n;
}

function getMinimalFreeTenantId($pdo, $minId = 1) {
    try {
        $ids = [];
        $stmt = $pdo->query("SELECT id FROM tenants ORDER BY id ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) { $ids[(int)$id] = true; }
        $i = max(1, (int)$minId);
        while ($i <= PHP_INT_MAX) {
            if (!isset($ids[$i])) { return $i; }
            $i++;
        }
    } catch (Throwable $e) {}
    return null;
}

function debugLog($message) {
    try {
        error_log('[CORE] ' . (string)$message);
    } catch (Throwable $e) {}
}

function dbHealthCheck(PDO $pdo) {
    $out = [
        'schema' => null,
        'missing_tables' => [],
        'missing_columns' => [],
        'missing_fks' => [],
        'missing_indexes' => [],
        'suggested_indexes' => []
    ];
    try {
        $schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $out['schema'] = $schema ?: null;
    } catch (Throwable $e) {}
    $tables = [
        'work_orders','invoices','invoice_items','invoice_payments',
        'clients','cash_sessions','cash_income','cash_expenses',
        'inventory_products','system_config'
    ];
    foreach ($tables as $t) {
        try {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $exists->execute([$t]);
            if ((int)$exists->fetchColumn() === 0) {
                $out['missing_tables'][] = $t;
                continue;
            }
            $cols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
            $cols->execute([$t]);
            $have = array_flip(array_map('strtolower', $cols->fetchAll(PDO::FETCH_COLUMN)));
            if (!isset($have['tenant_id'])) {
                $out['missing_columns'][] = ['table' => $t, 'column' => 'tenant_id'];
            }
            $idx = $pdo->prepare("SHOW INDEX FROM `$t`");
            $idx->execute();
            $idxRows = $idx->fetchAll(PDO::FETCH_ASSOC);
            $keyNames = array_flip(array_map(function($r){ return $r['Key_name']; }, $idxRows));
            if (!isset($keyNames["idx_{$t}_tenant"])) {
                $out['missing_indexes'][] = ['table' => $t, 'index' => "idx_{$t}_tenant"];
            }
        } catch (Throwable $e) {
            debugLog("dbHealthCheck table error {$t}: " . $e->getMessage());
        }
    }
    $expectedFks = [
        ['table'=>'order_status_history','column'=>'order_id','ref_table'=>'work_orders','ref_column'=>'id'],
        ['table'=>'invoice_items','column'=>'invoice_id','ref_table'=>'invoices','ref_column'=>'id'],
        ['table'=>'invoice_payments','column'=>'invoice_id','ref_table'=>'invoices','ref_column'=>'id'],
        ['table'=>'cash_income','column'=>'cash_session_id','ref_table'=>'cash_sessions','ref_column'=>'id'],
        ['table'=>'cash_expenses','column'=>'cash_session_id','ref_table'=>'cash_sessions','ref_column'=>'id'],
        ['table'=>'work_orders','column'=>'client_id','ref_table'=>'clients','ref_column'=>'id']
    ];
    foreach ($expectedFks as $fk) {
        try {
            $exists = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?
            ");
            $exists->execute([$fk['table'], $fk['column'], $fk['ref_table'], $fk['ref_column']]);
            if ((int)$exists->fetchColumn() === 0) {
                $out['missing_fks'][] = $fk;
            }
        } catch (Throwable $e) {}
    }
    $out['suggested_indexes'] = [
        ['table'=>'invoices','name'=>'idx_invoices_tenant_status_created','columns'=>'tenant_id,status,created_at'],
        ['table'=>'work_orders','name'=>'idx_work_orders_tenant_status_created','columns'=>'tenant_id,status,created_at'],
        ['table'=>'work_orders','name'=>'idx_work_orders_client','columns'=>'client_id'],
        ['table'=>'invoice_items','name'=>'idx_invoice_items_invoice','columns'=>'invoice_id'],
        ['table'=>'invoice_payments','name'=>'idx_invoice_payments_invoice','columns'=>'invoice_id'],
        ['table'=>'cash_income','name'=>'idx_cash_income_session','columns'=>'cash_session_id'],
        ['table'=>'cash_expenses','name'=>'idx_cash_expenses_session','columns'=>'cash_session_id']
    ];
    return $out;
}

function ensureCoreIndexes(PDO $pdo) {
    $defs = [
        ['table'=>'invoices','name'=>'idx_invoices_tenant_status_created','ddl'=>'CREATE INDEX idx_invoices_tenant_status_created ON invoices(tenant_id, status, created_at)'],
        ['table'=>'work_orders','name'=>'idx_work_orders_tenant_status_created','ddl'=>'CREATE INDEX idx_work_orders_tenant_status_created ON work_orders(tenant_id, status, created_at)'],
        ['table'=>'work_orders','name'=>'idx_work_orders_client','ddl'=>'CREATE INDEX idx_work_orders_client ON work_orders(client_id)'],
        ['table'=>'invoice_items','name'=>'idx_invoice_items_invoice','ddl'=>'CREATE INDEX idx_invoice_items_invoice ON invoice_items(invoice_id)'],
        ['table'=>'invoice_payments','name'=>'idx_invoice_payments_invoice','ddl'=>'CREATE INDEX idx_invoice_payments_invoice ON invoice_payments(invoice_id)'],
        ['table'=>'cash_income','name'=>'idx_cash_income_session','ddl'=>'CREATE INDEX idx_cash_income_session ON cash_income(cash_session_id)'],
        ['table'=>'cash_expenses','name'=>'idx_cash_expenses_session','ddl'=>'CREATE INDEX idx_cash_expenses_session ON cash_expenses(cash_session_id)']
    ];
    foreach ($defs as $d) {
        try {
            $q = $pdo->prepare("SHOW INDEX FROM `{$d['table']}` WHERE Key_name = ?");
            $q->execute([$d['name']]);
            if ($q->rowCount() === 0) { $pdo->exec($d['ddl']); }
        } catch (Throwable $e) { debugLog("ensureCoreIndexes {$d['table']}: " . $e->getMessage()); }
    }
}
