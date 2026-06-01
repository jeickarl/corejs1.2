<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

// Verificar autenticación y tenant
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
function convertCollationToSpanish()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName) {
            $pdo->exec("ALTER DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
        }
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
        }
        echo json_encode(['success' => true, 'message' => 'Colación actualizada a utf8mb4_spanish_ci']);
    }
    catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
function scanInvalidChars()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    try {
        $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')")->fetchAll(PDO::FETCH_ASSOC);
        $report = [];
        foreach ($rows as $r) {
            $table = $r['TABLE_NAME'];
            $col = $r['COLUMN_NAME'];
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `$table` WHERE `$col` LIKE '%�%'");
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $report[] = ['table' => $table, 'column' => $col, 'count' => $count];
            }
        }
        echo json_encode(['success' => true, 'report' => $report]);
    }
    catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
function fixInvalidChars()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    try {
        $pdo->beginTransaction();
        $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $table = $r['TABLE_NAME'];
            $col = $r['COLUMN_NAME'];
            $pdo->exec("UPDATE `$table` SET `$col` = REPLACE(`$col`, '�', '') WHERE `$col` LIKE '%�%'");
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Caracteres inválidos eliminados']);
    }
    catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function repairTextEncoding()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
        return;
    }
    $tenant_id = getCurrentTenantId();
    $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
    $hasTenant = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        $hasTenant = ($c && $c->rowCount() > 0);
    }
    catch (Throwable $e) {
    }
    $emojiBySlug = [
        'pending' => '⏳',
        'received' => '📦',
        'diagnosing' => '🔍',
        'waiting_parts' => '⏸️',
        'repairing' => '🔧',
        'testing' => '🧪',
        'completed' => '✅',
        'delivered' => '🚚',
        'cancelled' => '❌',
        'devolucion' => '↩️',
        'cancelado' => '❌',
        'entregado' => '🚚'
    ];
    $nameBySlug = [
        'pending' => 'Pendiente',
        'received' => 'Recibido',
        'diagnosing' => 'Diagnosticando',
        'waiting_parts' => 'Esperando Repuestos',
        'repairing' => 'Reparando',
        'testing' => 'Pruebas',
        'completed' => 'Completado',
        'delivered' => 'Entregado',
        'cancelled' => 'Cancelado',
        'devolucion' => 'Devolución',
        'cancelado' => 'Cancelado',
        'entregado' => 'Entregado'
    ];
    $descBySlug = [
        'pending' => 'Orden creada y pendiente de revisión',
        'received' => 'Dispositivo recibido en el taller',
        'diagnosing' => 'En proceso de diagnóstico',
        'waiting_parts' => 'Esperando repuestos para la reparación',
        'repairing' => 'En proceso de reparación',
        'testing' => 'Equipo en pruebas de funcionamiento',
        'completed' => 'Reparación completada exitosamente',
        'delivered' => 'Dispositivo entregado al cliente',
        'cancelled' => 'Orden cancelada',
        'devolucion' => 'Devolución de equipo por componente no conseguido, fuera de costo o reparación no viable'
    ];
    try {
        $pdo->beginTransaction();
        foreach ($nameBySlug as $slug => $name) {
            $emoji = $emojiBySlug[$slug] ?? '';
            $desc = $descBySlug[$slug] ?? '';
            if ($hasTenant) {
                $stmt = $pdo->prepare("UPDATE order_statuses SET name=?, emoji=?, description=? WHERE slug=? AND tenant_id=?");
                $stmt->execute([$name, $emoji, $desc, $slug, $tenantValue]);
            }
            else {
                $stmt = $pdo->prepare("UPDATE order_statuses SET name=?, emoji=?, description=? WHERE slug=?");
                $stmt->execute([$name, $emoji, $desc, $slug]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Textos reparados']);
    }
    catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
function orderInvoiceSummary()
{
    global $pdo;
    $order_id = intval($_POST['order_id'] ?? 0);
    $tenant_id = getCurrentTenantId();
    $perDatabase = isPerDatabaseMode();
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Orden inválida']);
        return;
    }
    try {
        $linked = null;
        $has_order_id_col = false;
        $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
        $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
        try {
            $colStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'order_id'");
            $colStmt->execute();
            $has_order_id_col = (intval($colStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0);
        }
        catch (Throwable $e) {
        }
        if ($has_order_id_col) {
            $sql = "SELECT id, invoice_number, total_amount, paid_amount FROM invoices WHERE order_id = ? AND status != 'cancelled'";
            $params = [$order_id];
            if (!$perDatabase && $hasTenantInvoices) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantValue;
            }
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            $stmtInv = $pdo->prepare($sql);
            $stmtInv->execute($params);
            $linked = $stmtInv->fetch(PDO::FETCH_ASSOC);
        }
        else {
            $like = '%Orden #' . $order_id . '%';
            $joinItems = (!$perDatabase && $hasTenantInvoices && $hasTenantItems) ? "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id" : "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id";
            $sql = "SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount FROM invoices i $joinItems WHERE i.status != 'cancelled'";
            $params = [];
            if (!$perDatabase && $hasTenantInvoices) {
                $sql .= " AND i.tenant_id = ?";
                $params[] = $tenantValue;
            }
            $sql .= " AND (i.notes LIKE ? OR ii.description LIKE ?) ORDER BY i.created_at DESC LIMIT 1";
            $params[] = $like;
            $params[] = $like;
            $stmtInv = $pdo->prepare($sql);
            $stmtInv->execute($params);
            $linked = $stmtInv->fetch(PDO::FETCH_ASSOC);
        }
        if (!$linked) {
            echo json_encode(['success' => false, 'message' => 'No hay factura vinculada']);
            return;
        }
        $inv_id = intval($linked['id'] ?? 0);
        $details = '';
        if ($inv_id > 0) {
            $sql = "SELECT description, quantity FROM invoice_items WHERE invoice_id = ?";
            $params = [$inv_id];
            if ($hasTenantItems && !$perDatabase) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantValue;
            }
            $sql .= " ORDER BY id ASC";
            $stmtItems = $pdo->prepare($sql);
            $stmtItems->execute($params);
            $parts = [];
            while ($it = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
                $desc = trim($it['description'] ?? '');
                $qty = (int)($it['quantity'] ?? 1);
                if ($desc !== '') {
                    $parts[] = $desc . ($qty > 1 ? ' x' . $qty : '');
                }
            }
            if (!empty($parts)) {
                $details = implode(', ', array_slice($parts, 0, 8));
            }
        }
        echo json_encode([
            'success' => true,
            'invoice_number' => $linked['invoice_number'] ?? '',
            'total_amount' => $linked['total_amount'] ?? 0,
            'paid_amount' => $linked['paid_amount'] ?? 0,
            'details' => $details
        ], JSON_UNESCAPED_UNICODE);
    }
    catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Función para actualizar configuración de empresa
function updateCompanyConfig()
{
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = isPerDatabaseMode();
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $empresa_id = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : 0;
    $companyScopeId = $perDatabase ? ($empresa_id > 0 ? $empresa_id : (int)$tenant_id) : (int)$tenant_id;
    $hasTenantCompany = hasTenantColumnCached($pdo, 'company_config');
    $hasTenantsTable = false;
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'tenants'");
        $hasTenantsTable = ($q && $q->rowCount() > 0);
    } catch (Throwable $e) {
        $hasTenantsTable = false;
    }

    // Verificar permisos de administrador
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    // Validar nombre de empresa
    if (!isset($_POST['company_name']) || empty(trim($_POST['company_name']))) {
        echo json_encode(['success' => false, 'message' => 'El nombre de la empresa es requerido']);
        return;
    }

    $company_name = trim($_POST['company_name']);
    $company_phone = trim($_POST['company_phone'] ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $company_website = trim($_POST['company_website'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $logo_filename = null;
    try {
        $slug = getTenantPreferredSlug($companyScopeId) ?? (string)$companyScopeId;
        $base = rtrim(getSystemBaseUrl(), '/');
        $portal_url = $base . '/portal/' . $slug;
        if ($company_website === '') {
            $company_website = $portal_url;
        }
    } catch (Throwable $e) {}

    // Validaciones básicas (no bloqueantes)
    if ($company_email !== '' && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'El correo no tiene un formato válido']);
        return;
    }
    if ($company_website !== '' && !preg_match('/^https?:\/\//i', $company_website)) {
        // Permitir sin protocolo; normalizar añadiendo http://
        $company_website = 'http://' . $company_website;
    }

    require_once __DIR__ . '/performance_optimizer.php';

    // Manejar subida de logo si existe
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
        $file = $_FILES['company_logo'];

        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Archivo inválido']);
            return;
        }

        $maxBytes = 2 * 1024 * 1024;
        if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'El archivo es demasiado grande. Máximo 2MB']);
            return;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        if (!isset($allowed_mimes[$mime])) {
            echo json_encode(['success' => false, 'message' => 'Formato de archivo no permitido. Use JPG, PNG, GIF o WEBP']);
            return;
        }
        $extension = $allowed_mimes[$mime];

        $info = @getimagesize($file['tmp_name']);
        if (!$info || empty($info['mime'])) {
            echo json_encode(['success' => false, 'message' => 'Imagen inválida']);
            return;
        }
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w <= 0 || $h <= 0 || ($w * $h) > 25_000_000) {
            echo json_encode(['success' => false, 'message' => 'Imagen inválida']);
            return;
        }

        $logo_prefix = $perDatabase
            ? ('logo_e' . $companyScopeId . '_')
            : ('logo_t' . $tenant_id . '_');
        $logo_filename = $logo_prefix . time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        // Ruta de destino: Usamos la carpeta assets/img directamente para facilitar la carga en sidebar
        $upload_dir = __DIR__ . '/../assets/img/';

        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }
        $upload_path = $upload_dir . $logo_filename;

        try {
            $oldFiles = glob($upload_dir . $logo_prefix . '*') ?: [];
            foreach ($oldFiles as $p) {
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        } catch (Throwable $e) {
        }

        // Intentar subir archivo
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            error_log("Error al mover archivo a: " . $upload_path);
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo. Verifique permisos de escritura en assets/img']);
            return;
        }
        $extLower = strtolower(pathinfo($logo_filename, PATHINFO_EXTENSION));
        if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            PerformanceOptimizer::optimizeImage($upload_path, $upload_path, 85);
        }
    }

    try {
        // Asegurar tabla company_config con tenant_id
        if (!$perDatabase) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_config (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                company_name VARCHAR(200) NOT NULL DEFAULT 'Mi Empresa',
                company_logo VARCHAR(255) DEFAULT 'company_logo.png',
                company_phone VARCHAR(50) NULL,
                company_email VARCHAR(255) NULL,
                company_website VARCHAR(255) NULL,
                company_address VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                PRIMARY KEY (id),
                UNIQUE KEY unique_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_config (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_name VARCHAR(200) NOT NULL DEFAULT 'Mi Empresa',
                company_logo VARCHAR(255) DEFAULT 'company_logo.png',
                company_phone VARCHAR(50) NULL,
                company_email VARCHAR(255) NULL,
                company_website VARCHAR(255) NULL,
                company_address VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        }
        try {
            $pdo->exec("ALTER TABLE company_config MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
        }
        catch (Throwable $e) {
        }
        if (!$perDatabase) {
            try {
                $checkTenantCol = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_config' AND COLUMN_NAME = 'tenant_id'");
                $checkTenantCol->execute();
                $existsTenantCol = (int)($checkTenantCol->fetch()['cnt'] ?? 0) > 0;
                if (!$existsTenantCol) {
                    $pdo->exec("ALTER TABLE company_config ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 FIRST");
                }
            }
            catch (Throwable $e) {
            }
            try {
                $pdo->exec("ALTER TABLE company_config ADD UNIQUE KEY unique_tenant (tenant_id)");
            }
            catch (Throwable $e) {
            }
            try {
                $pdo->prepare("UPDATE company_config SET tenant_id = ? WHERE tenant_id IS NULL OR tenant_id = 0")->execute([$tenantValue]);
            }
            catch (Throwable $e) {
            }
            $hasTenantCompany = hasTenantColumnCached($pdo, 'company_config');
        }

        // Asegurar columnas nuevas en company_config
        $columns = [
            'company_phone' => 'VARCHAR(50)',
            'company_email' => 'VARCHAR(255)',
            'company_website' => 'VARCHAR(255)',
            'company_address' => 'VARCHAR(255)'
        ];
        foreach ($columns as $col => $type) {
            // Usar INFORMATION_SCHEMA para verificar existencia de columna (SHOW ... LIKE ? no soporta placeholders con emulación desactivada)
            $checkCol = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_config' AND COLUMN_NAME = ?");
            $checkCol->execute([$col]);
            $exists = (int)($checkCol->fetch()['cnt'] ?? 0) > 0;
            if (!$exists) {
                $pdo->exec("ALTER TABLE company_config ADD COLUMN $col $type NULL");
            }
        }

        // Verificar si existe registro por tenant y obtener logo anterior
        $existing = null;
        try {
            if ($hasTenantCompany && !$perDatabase) {
                $get_stmt = $pdo->prepare("SELECT id, company_logo FROM company_config WHERE tenant_id = ?");
                $get_stmt->execute([$tenantValue]);
                $existing = $get_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $fallback_stmt = $pdo->query("SELECT id, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
                $existing = $fallback_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {
            try {
                $fallback_stmt = $pdo->query("SELECT id, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
                $existing = $fallback_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $__) {
                $existing = null;
            }
        }
        $old_logo = $existing['company_logo'] ?? null;

        if (!$existing) {
            // Crear nuevo registro
            if ($hasTenantCompany && !$perDatabase) {
                $stmt = $pdo->prepare("INSERT INTO company_config (tenant_id, company_name, company_logo, company_phone, company_email, company_website, company_address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([$tenantValue, $company_name, $logo_filename ?: 'system_logo.png', $company_phone, $company_email, $company_website, $company_address]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO company_config (company_name, company_logo, company_phone, company_email, company_website, company_address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([$company_name, $logo_filename ?: 'system_logo.png', $company_phone, $company_email, $company_website, $company_address]);
            }
            ensureDefaultOrderStatuses($tenantValue);
            ensureDefaultTenantCatalogs($tenantValue);
            try {
                if (!$perDatabase && $hasTenantsTable) {
                    $updTenant = $pdo->prepare("UPDATE tenants SET company_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $updTenant->execute([$company_name, $tenant_id]);
                }
            }
            catch (Throwable $e) {
            }
        }
        else {
            // Actualizar registro existente
            if ($logo_filename) {
                if ($hasTenantCompany && !$perDatabase) {
                    $stmt = $pdo->prepare("UPDATE company_config SET company_name = ?, company_logo = ?, company_phone = ?, company_email = ?, company_website = ?, company_address = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ?");
                    $stmt->execute([$company_name, $logo_filename, $company_phone, $company_email, $company_website, $company_address, $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("UPDATE company_config SET company_name = ?, company_logo = ?, company_phone = ?, company_email = ?, company_website = ?, company_address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$company_name, $logo_filename, $company_phone, $company_email, $company_website, $company_address, (int)($existing['id'] ?? 0)]);
                }
                if (!empty($old_logo) && $old_logo !== 'company_logo.png' && $old_logo !== 'system_logo.png' && basename($old_logo) !== basename($logo_filename)) {
                    $oldBase = basename((string)$old_logo);
                    $canDelete = (strpos($oldBase, $logo_prefix) === 0);
                    if ($canDelete) {
                        $old_path = __DIR__ . '/../assets/img/' . $oldBase;
                        if (is_file($old_path)) {
                            @unlink($old_path);
                        }
                    }
                }
            }
            else {
                if ($hasTenantCompany && !$perDatabase) {
                    $stmt = $pdo->prepare("UPDATE company_config SET company_name = ?, company_phone = ?, company_email = ?, company_website = ?, company_address = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ?");
                    $stmt->execute([$company_name, $company_phone, $company_email, $company_website, $company_address, $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("UPDATE company_config SET company_name = ?, company_phone = ?, company_email = ?, company_website = ?, company_address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$company_name, $company_phone, $company_email, $company_website, $company_address, (int)($existing['id'] ?? 0)]);
                }
            }
            try {
                if (!$perDatabase && $hasTenantsTable) {
                    $updTenant = $pdo->prepare("UPDATE tenants SET company_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $updTenant->execute([$company_name, $tenant_id]);
                }
            }
            catch (Throwable $e) {
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Configuración actualizada correctamente',
            'logo_filename' => $logo_filename,
            'company_name' => $company_name,
            'deleted_old_logo' => isset($old_path) && isset($logo_filename) && is_string($logo_filename)
        ]);

    }
    catch (Exception $e) {
        error_log("Error en updateCompanyConfig: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error en base de datos: ' . $e->getMessage()]);
    }
}

// Procesar la petición
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$needsCsrf = [
    'update_company',
    'update_regional_settings',
    'payment_methods_add',
    'payment_methods_update',
    'payment_methods_toggle',
    'payment_methods_delete',
    'payment_accounts_add',
    'payment_accounts_update',
    'payment_accounts_toggle',
    'payment_accounts_set_default',
    'payment_accounts_delete',
    'reset_business_data',
    'create_user',
    'update_user',
    'delete_user',
    'change_password',
    'update_whatsapp_templates',
    'update_appearance',
    'reset_appearance',
    'convert_collation_spanish',
    'scan_invalid_chars',
    'fix_invalid_chars',
    'repair_text_encoding'
];
if (in_array($action, $needsCsrf, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }
    $csrf = $_POST['csrf_token'] ?? '';
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
        exit;
    }
}

// Función para actualizar configuraciones regionales
function updateRegionalSettings()
{
    global $pdo;

    // Verificar permisos de administrador
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (id INT(11) NOT NULL AUTO_INCREMENT, tenant_id INT(11) NOT NULL DEFAULT 1, config_key VARCHAR(255) DEFAULT NULL, config_value TEXT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        try {
            $pdo->query("SELECT tenant_id FROM system_config LIMIT 1");
        }
        catch (Throwable $e) {
            $pdo->exec("ALTER TABLE system_config ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 FIRST");
        }
        try {
            $pdo->exec("ALTER TABLE system_config MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
        }
        catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE system_config ADD UNIQUE KEY uq_system_config_config_key_tenant (config_key, tenant_id)");
        }
        catch (Throwable $e) {
        }
    }
    catch (Throwable $e) {
        error_log("ensure system_config schema: " . $e->getMessage());
    }

    try {
        // Configuraciones a actualizar
        $settings = [
            'currency' => $_POST['currency'] ?? 'COP',
            'currency_symbol' => $_POST['currency_symbol'] ?? '$',
            'currency_name' => $_POST['currency_name'] ?? 'Peso Colombiano',
            'phone_prefix' => $_POST['phone_prefix'] ?? '+57',
            'phone_country' => $_POST['phone_country'] ?? 'Colombia',
            'timezone' => $_POST['timezone'] ?? 'America/Bogota',
            'time_format' => $_POST['time_format'] ?? '12',
            'date_format' => $_POST['date_format'] ?? 'd/m/Y',
            'order_prefix' => isset($_POST['order_prefix']) ? trim($_POST['order_prefix']) : '',
            'order_next_number' => $_POST['order_next_number'] ?? '1',
            'invoice_next_number' => $_POST['invoice_next_number'] ?? '1',
            // Campos de impuestos
            'tax_enabled' => isset($_POST['tax_enabled']) ? '1' : '0',
            'tax_name' => $_POST['tax_name'] ?? 'IVA',
            'tax_rate' => $_POST['tax_rate'] ?? '19',

            // Campos de garantía
            'warranty_days' => $_POST['warranty_days'] ?? '30',
            'abandon_days' => $_POST['abandon_days'] ?? '60',
            'warranty_text' => $_POST['warranty_text'] ?? '',
            'warranty_disclaimers' => $_POST['warranty_disclaimers'] ?? '',
            'invoice_due_days_default' => $_POST['invoice_due_days_default'] ?? '7',

            'label_paper_size' => $_POST['label_paper_size'] ?? 'sticker_5030',
            'label_style' => $_POST['label_style'] ?? 'compact',
            'label_custom_width_mm' => $_POST['label_custom_width_mm'] ?? '50',
            'label_custom_height_mm' => $_POST['label_custom_height_mm'] ?? '30',
            'label_padding_mm' => $_POST['label_padding_mm'] ?? '2',
            'label_show_logo' => isset($_POST['label_show_logo']) ? '1' : '0',
            'label_layout' => $_POST['label_layout'] ?? 'qr_bottom',
            'label_font_family' => $_POST['label_font_family'] ?? 'arial',
            'label_multiline_lines' => $_POST['label_multiline_lines'] ?? '3',
            'label_logo_mm' => $_POST['label_logo_mm'] ?? '10',
            'label_qr_mm' => $_POST['label_qr_mm'] ?? '10',
            'label_order_font_pt' => $_POST['label_order_font_pt'] ?? '11',
            'label_line_font_pt' => $_POST['label_line_font_pt'] ?? '7.5',
            'label_uppercase' => isset($_POST['label_uppercase']) ? '1' : '0',
            'label_border' => isset($_POST['label_border']) ? '1' : '0',
            'label_element_order' => isset($_POST['label_element_order']) ? preg_replace('/[^a-z0-9_,]/', '', (string)$_POST['label_element_order']) : 'client,device_type,device,serial,issue,client_observations,tech_notes,accessories,date',
            'label_copies' => $_POST['label_copies'] ?? '1',
            'label_show_qr' => isset($_POST['label_show_qr']) ? '1' : '0',
            'label_show_client' => isset($_POST['label_show_client']) ? '1' : '0',
            'label_show_client_phone' => isset($_POST['label_show_client_phone']) ? '1' : '0',
            'label_show_serial' => isset($_POST['label_show_serial']) ? '1' : '0',
            'label_show_date' => isset($_POST['label_show_date']) ? '1' : '0',
            'label_show_device_type' => isset($_POST['label_show_device_type']) ? '1' : '0',
            'label_show_device' => isset($_POST['label_show_device']) ? '1' : '0',
            'label_show_issue' => isset($_POST['label_show_issue']) ? '1' : '0',
            'label_show_client_observations' => isset($_POST['label_show_client_observations']) ? '1' : '0',
            'label_show_tech_notes' => isset($_POST['label_show_tech_notes']) ? '1' : '0',
            'label_show_accessories' => isset($_POST['label_show_accessories']) ? '1' : '0'
        ];

        $tenant_id = getCurrentTenantId();
        $perDatabase = isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $opRaw = isset($settings['order_prefix']) ? $settings['order_prefix'] : '';
        $opNorm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($opRaw)));
        if (strlen($opNorm) > 6) {
            $opNorm = substr($opNorm, 0, 6);
        }
        $settings['order_prefix'] = $opNorm;
        if ($opNorm !== '' && !$perDatabase) {
            $chk = $pdo->prepare("SELECT tenant_id FROM system_config WHERE config_key = 'order_prefix' AND LOWER(TRIM(config_value)) = LOWER(TRIM(?)) AND tenant_id != ? LIMIT 1");
            $chk->execute([$opNorm, $tenantValue]);
            $existsOther = $chk->fetchColumn();
            if ($existsOther) {
                echo json_encode(['success' => false, 'message' => 'El prefijo ya está en uso por otra empresa']);
                return;
            }
        }

        // Actualizar cada configuración (insertar o actualizar si existe)
        foreach ($settings as $key => $value) {
            // Usar ON DUPLICATE KEY UPDATE para manejar inserciones y actualizaciones de manera segura
            $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?");
            $stmt->execute([$tenantValue, $key, $value, $value]);
        }

        // Actualizar datetime_format basado en time_format
        $time_format = $settings['time_format'];
        $date_format = $settings['date_format'];
        $datetime_format = $time_format === '12' ? $date_format . ' H:i A' : $date_format . ' H:i';

        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'datetime_format', ?) ON DUPLICATE KEY UPDATE config_value = ?");
        $stmt->execute([$tenantValue, $datetime_format, $datetime_format]);

        echo json_encode([
            'success' => true,
            'message' => 'Configuraciones regionales actualizadas correctamente'
        ]);

    }
    catch (Exception $e) {
        error_log("Error en updateRegionalSettings: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error en base de datos: ' . $e->getMessage()]);
    }
}

// Actualizar plantillas de WhatsApp
function updateWhatsappTemplates()
{
    global $pdo;

    if (!isAdminSession()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantSystemConfig = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;

    // Asegurar que la tabla soporte emojis (utf8mb4)
    try {
        $pdo->exec("ALTER TABLE system_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("ALTER TABLE system_config MODIFY config_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("ALTER TABLE system_config MODIFY config_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
    }
    catch (Throwable $e) {
        error_log('Charset system_config: ' . $e->getMessage());
    }

    $keys = [
        'whatsapp_template_reception',
        'whatsapp_template_ready',
        'whatsapp_template_delivery',
        'whatsapp_template_sale'
    ];

    try {
        header('Content-Type: application/json; charset=UTF-8');
        $upsert = function(string $key, string $value) use ($pdo, $perDatabase, $hasTenantSystemConfig, $tenantValue): void {
            if ($perDatabase) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                    $stmt->execute([$key, $value]);
                    return;
                } catch (Throwable $e) {
                    if ($hasTenantSystemConfig) {
                        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                        $stmt->execute([$tenantValue, $key, $value]);
                        return;
                    }
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
                    $stmt->execute([$key]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                        $stmt->execute([$value, $key]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                        $stmt->execute([$key, $value]);
                    }
                    return;
                }
            }
            if ($hasTenantSystemConfig) {
                $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $stmt->execute([$tenantValue, $key, $value]);
                return;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
            $stmt->execute([$key]);
            if ((int)$stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                $stmt->execute([$value, $key]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
            }
        };
        $pdo->beginTransaction();
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $value = (string)$_POST[$key];
                $upsert($key, $value);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Plantillas de WhatsApp actualizadas']);
    }
    catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error en updateWhatsappTemplates: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al guardar las plantillas']);
    }
}

function getAppearanceDefaults()
{
    return [
        'theme_color' => 'black',
        'theme_mode' => 'light'
    ];
}

// Actualizar configuración de apariencia
function updateAppearance()
{
    global $pdo;

    if (!isAdminSession()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $tenantValue = isPerDatabaseMode() ? 1 : (int)$tenant_id;
    $theme_mode = trim($_POST['theme_mode'] ?? 'light');
    if (!in_array($theme_mode, ['light', 'dark'], true)) {
        $theme_mode = 'light';
    }

    try {
        header('Content-Type: application/json; charset=UTF-8');
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'theme_mode', ?) ON DUPLICATE KEY UPDATE config_value = ?");
        $stmt->execute([$tenantValue, $theme_mode, $theme_mode]);

        $stmt2 = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'theme_color', 'black') ON DUPLICATE KEY UPDATE config_value = 'black'");
        $stmt2->execute([$tenantValue]);

        $keysToRemove = [
            'primary_color',
            'primary_light',
            'primary_dark',
            'sidebar_style',
            'sidebar_color',
            'sidebar_active_bg'
        ];
        $placeholders = implode(',', array_fill(0, count($keysToRemove), '?'));
        $del = $pdo->prepare("DELETE FROM system_config WHERE tenant_id = ? AND config_key IN ($placeholders)");
        $del->execute(array_merge([(int)$tenant_id], $keysToRemove));

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Apariencia actualizada']);
    }
    catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error en updateAppearance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error en base de datos: ' . $e->getMessage()]);
    }
}

function resetAppearance()
{
    global $pdo;
    if (!isAdminSession()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    $tenant_id = getCurrentTenantId();
    $defaults = getAppearanceDefaults();
    try {
        header('Content-Type: application/json; charset=UTF-8');
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'theme_mode', ?) ON DUPLICATE KEY UPDATE config_value = ?");
        $stmt->execute([$tenant_id, $defaults['theme_mode'], $defaults['theme_mode']]);

        $stmt2 = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'theme_color', 'black') ON DUPLICATE KEY UPDATE config_value = 'black'");
        $stmt2->execute([$tenant_id]);

        $keysToRemove = [
            'primary_color',
            'primary_light',
            'primary_dark',
            'sidebar_style',
            'sidebar_color',
            'sidebar_active_bg'
        ];
        $placeholders = implode(',', array_fill(0, count($keysToRemove), '?'));
        $del = $pdo->prepare("DELETE FROM system_config WHERE tenant_id = ? AND config_key IN ($placeholders)");
        $del->execute(array_merge([(int)$tenant_id], $keysToRemove));

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Apariencia restablecida']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error en resetAppearance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error en base de datos: ' . $e->getMessage()]);
    }
}

function pmHasStatus()
{
    global $pdo;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
        return $c && $c->rowCount() > 0;
    }
    catch (Exception $e) {
        return false;
    }
}
function pmHasIsActive()
{
    global $pdo;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
        return $c && $c->rowCount() > 0;
    }
    catch (Exception $e) {
        return false;
    }
}
function ensurePaymentMethodsTable()
{
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        PRIMARY KEY (id),
        INDEX(tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'tenant_id'");
        if (!$c || $c->rowCount() === 0) {
            $pdo->exec("ALTER TABLE payment_methods ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_payment_methods_tenant ON payment_methods(tenant_id)"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
function ensurePaymentAccountsTable()
{
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_method_accounts (
        id INT(11) NOT NULL AUTO_INCREMENT,
        tenant_id INT(11) NOT NULL DEFAULT 1,
        method_id INT(11) NOT NULL,
        account_name VARCHAR(100) NULL,
        alias VARCHAR(100) NULL,
        account_number VARCHAR(100) NOT NULL,
        type VARCHAR(50) NULL,
        holder_name VARCHAR(120) NULL,
        holder_id VARCHAR(60) NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        PRIMARY KEY (id),
        INDEX(method_id),
        INDEX(tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_method_accounts LIKE 'tenant_id'");
        if (!$c || $c->rowCount() === 0) {
            $pdo->exec("ALTER TABLE payment_method_accounts ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
            try { $pdo->exec("CREATE INDEX idx_pm_accounts_tenant ON payment_method_accounts(tenant_id)"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
    // Asegurar columnas en invoice_payments y cash_income
    try {
        $pdo->query("SHOW COLUMNS FROM invoice_payments LIKE 'pm_account_id'");
    }
    catch (Exception $e) {
    }
    $c = $pdo->query("SHOW COLUMNS FROM invoice_payments LIKE 'pm_account_id'");
    if (!$c || $c->rowCount() === 0) {
        $pdo->exec("ALTER TABLE invoice_payments ADD COLUMN pm_account_id INT(11) NULL");
    }
    $c2 = $pdo->query("SHOW COLUMNS FROM cash_income LIKE 'payment_account_id'");
    if (!$c2 || $c2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE cash_income ADD COLUMN payment_account_id INT(11) NULL");
    }
}
function paymentAccountsList()
{
    global $pdo;
    ensurePaymentMethodsTable();
    ensurePaymentAccountsTable();
    $tenant_id = getCurrentTenantId();
    $sql = "SELECT a.id, a.method_id, m.name AS method_name, COALESCE(a.alias,a.account_name) AS alias, a.account_number, a.type, a.holder_name, a.holder_id, a.is_default, a.is_active FROM payment_method_accounts a LEFT JOIN payment_methods m ON m.id=a.method_id WHERE a.tenant_id = ? ORDER BY m.name ASC, a.is_default DESC, a.alias ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenant_id]);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode(['success' => true, 'accounts' => $rows]);
}
function paymentAccountsAdd()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentMethodsTable();
    ensurePaymentAccountsTable();
    $method_id = intval($_POST['method_id'] ?? 0);
    $alias = trim($_POST['alias'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $holder = trim($_POST['holder'] ?? '');
    $holder_id = trim($_POST['holder_id'] ?? '');
    $is_default = intval($_POST['is_default'] ?? 0) === 1 ? 1 : 0;
    $tenant_id = getCurrentTenantId();
    if ($method_id <= 0 || $number === '') {
        echo json_encode(['success' => false, 'message' => 'Datos requeridos']);
        return;
    }
    $dup = $pdo->prepare("SELECT id FROM payment_method_accounts WHERE method_id=? AND account_number=? AND tenant_id=? LIMIT 1");
    $dup->execute([$method_id, $number, $tenant_id]);
    if ($dup && $dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Cuenta duplicada']);
        return;
    }
    $ins = $pdo->prepare("INSERT INTO payment_method_accounts (tenant_id, method_id, alias, account_number, type, holder_name, holder_id, is_default, is_active) VALUES (?,?,?,?,?,?,?,?,1)");
    $ins->execute([$tenant_id, $method_id, $alias, $number, $type, $holder, $holder_id, $is_default]);
    if ($is_default === 1) {
        $pdo->prepare("UPDATE payment_method_accounts SET is_default=0 WHERE method_id=? AND tenant_id=? AND id<>LAST_INSERT_ID()")->execute([$method_id, $tenant_id]);
    }
    echo json_encode(['success' => true]);
}
function paymentAccountsUpdate()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentAccountsTable();
    $id = intval($_POST['id'] ?? 0);
    $alias = trim($_POST['alias'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $holder = trim($_POST['holder'] ?? '');
    $holder_id = trim($_POST['holder_id'] ?? '');
    $is_default = intval($_POST['is_default'] ?? 0) === 1 ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? (intval($_POST['is_active']) === 1 ? 1 : 0) : null;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }

    $row = $pdo->prepare("SELECT method_id FROM payment_method_accounts WHERE id=?");
    $row->execute([$id]);
    $m = $row->fetch(PDO::FETCH_ASSOC);
    $method_id = intval($m['method_id'] ?? 0);

    if ($number !== '' && $method_id > 0) {
        $dup = $pdo->prepare("SELECT id FROM payment_method_accounts WHERE method_id=? AND account_number=? AND id<>? LIMIT 1");
        $dup->execute([$method_id, $number, $id]);
        if ($dup && $dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cuenta duplicada']);
            return;
        }
    }

    $sql = "UPDATE payment_method_accounts SET updated_at=CURRENT_TIMESTAMP";
    $params = [];

    if ($alias !== '') {
        $sql .= ", alias=?";
        $params[] = $alias;
    }
    if ($number !== '') {
        $sql .= ", account_number=?";
        $params[] = $number;
    }
    if ($type !== '') {
        $sql .= ", type=?";
        $params[] = $type;
    }
    if ($holder !== '') {
        $sql .= ", holder_name=?";
        $params[] = $holder;
    }
    if ($holder_id !== '') {
        $sql .= ", holder_id=?";
        $params[] = $holder_id;
    }
    if ($is_active !== null) {
        $sql .= ", is_active=?";
        $params[] = $is_active;
    }

    $sql .= " WHERE id=?";
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);

    if ($is_default === 1 && $method_id > 0) {
        $pdo->prepare("UPDATE payment_method_accounts SET is_default=0 WHERE method_id=? AND id<>?")->execute([$method_id, $id]);
        $pdo->prepare("UPDATE payment_method_accounts SET is_default=1 WHERE id=?")->execute([$id]);
    }
    elseif (isset($_POST['is_default'])) {
        $pdo->prepare("UPDATE payment_method_accounts SET is_default=? WHERE id=?")->execute([$is_default, $id]);
    }
    echo json_encode(['success' => true]);
}
function paymentAccountsToggle()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentAccountsTable();
    $tenant_id = getCurrentTenantId();
    $id = intval($_POST['id'] ?? 0);
    $state = $_POST['state'] ?? '';
    if ($id <= 0 || !in_array($state, ['active', 'inactive'])) {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }
    $val = $state === 'active' ? 1 : 0;
    $pdo->prepare("UPDATE payment_method_accounts SET is_active=? WHERE id=? AND tenant_id=?")->execute([$val, $id, $tenant_id]);
    echo json_encode(['success' => true]);
}
function paymentAccountsSetDefault()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentAccountsTable();
    $tenant_id = getCurrentTenantId();
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    $row = $pdo->prepare("SELECT method_id FROM payment_method_accounts WHERE id=? AND tenant_id=?");
    $row->execute([$id, $tenant_id]);
    $m = $row->fetch(PDO::FETCH_ASSOC);
    $method_id = intval($m['method_id'] ?? 0);
    if ($method_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Método no encontrado']);
        return;
    }
    $pdo->prepare("UPDATE payment_method_accounts SET is_default=0 WHERE method_id=? AND tenant_id=?")->execute([$method_id, $tenant_id]);
    $pdo->prepare("UPDATE payment_method_accounts SET is_default=1 WHERE id=? AND tenant_id=?")->execute([$id, $tenant_id]);
    echo json_encode(['success' => true]);
}
function paymentAccountsDelete()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentAccountsTable();
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    $pdo->prepare("DELETE FROM payment_method_accounts WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}
function paymentMethodsList()
{
    global $pdo;
    ensurePaymentMethodsTable();
    $hasStatus = pmHasStatus();
    $hasIsActive = pmHasIsActive();
    $limit = max(1, intval($_POST['limit'] ?? 6));
    $page = max(1, intval($_POST['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    $tenant_id = getCurrentTenantId();
    $totalStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM payment_methods WHERE tenant_id = ?");
    $totalStmt->execute([$tenant_id]);
    $total = intval(($totalStmt && ($r = $totalStmt->fetch(PDO::FETCH_ASSOC))) ? ($r['cnt'] ?? 0) : 0);
    $sql = "SELECT id, name" . ($hasStatus ? ", status" : ($hasIsActive ? ", is_active" : "")) . " FROM payment_methods WHERE tenant_id = ? ORDER BY name ASC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $tenant_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $methods = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode(['success' => true, 'methods' => $methods, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}
function paymentMethodsAdd()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentMethodsTable();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Nombre requerido']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $check = $pdo->prepare("SELECT id FROM payment_methods WHERE LOWER(name) = LOWER(?) AND tenant_id = ? LIMIT 1");
    $check->execute([$name, $tenant_id]);
    if ($check && $check->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['success' => false, 'message' => 'Método duplicado']);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO payment_methods (tenant_id, name) VALUES (?, ?)");
    $stmt->execute([$tenant_id, $name]);
    try {
        $id = $pdo->lastInsertId();
    }
    catch (Exception $e) {
        $id = null;
    }
    echo json_encode(['success' => true, 'method_id' => $id]);
}
function paymentMethodsUpdate()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentMethodsTable();
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $stmt = $pdo->prepare("UPDATE payment_methods SET name = ? WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$name, $id, $tenant_id]);
    echo json_encode(['success' => true]);
}
function paymentMethodsToggle()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentMethodsTable();
    $id = intval($_POST['id'] ?? 0);
    $state = $_POST['state'] ?? '';
    if ($id <= 0 || ($state !== 'active' && $state !== 'inactive')) {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $hasStatus = pmHasStatus();
    $hasIsActive = pmHasIsActive();
    if ($hasStatus) {
        $stmt = $pdo->prepare("UPDATE payment_methods SET status = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$state, $id, $tenant_id]);
    }
    elseif ($hasIsActive) {
        $val = $state === 'active' ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$val, $id, $tenant_id]);
    }
    else {
        // Fallback si no tiene columna, no hacer nada o intentar status
        try {
            $stmt = $pdo->prepare("UPDATE payment_methods SET status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$state, $id, $tenant_id]);
        }
        catch (Exception $e) {
        }
    }
    echo json_encode(['success' => true]);
}
function paymentMethodsDelete()
{
    global $pdo;
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }
    ensurePaymentMethodsTable();
    $tenant_id = getCurrentTenantId();
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    echo json_encode(['success' => true]);
}

function resetBusinessData()
{
    global $pdo, $db_config;

    if (!isAdminSession()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para realizar esta acción']);
        return;
    }

    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
        return;
    }

    $resetMode = $_POST['reset_mode'] ?? 'truncate';
    if ($resetMode !== 'renumber' && $resetMode !== 'factory_reset') {
        $resetMode = 'truncate';
    }
    $dryRun = isset($_POST['dry_run']) ? (($_POST['dry_run'] === '1' || $_POST['dry_run'] === 'true' || $_POST['dry_run'] === 'yes') ? true : false) : false;
    $isPerDbMode = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
    $fileScopeIdForTenant = function (int $tenantId) use ($isPerDbMode): int {
        if ($isPerDbMode && isset($_SESSION['empresa_id']) && (int)$_SESSION['empresa_id'] > 0) {
            return (int)$_SESSION['empresa_id'];
        }
        return $tenantId;
    };

    if ($resetMode === 'factory_reset') {
        if ($dryRun) {
            $tenant_id = getCurrentTenantId();
            $preview = listTenantCleanup($fileScopeIdForTenant((int)$tenant_id));
            echo json_encode(['success' => true, 'mode' => 'factory_reset', 'dry_run' => true, 'delete' => $preview['delete'], 'preserve' => $preview['preserve'], 'counts' => ['delete' => count($preview['delete']), 'preserve' => count($preview['preserve'])]]);
            return;
        }
        // 1. Verificar frase de confirmación
        $phrase = $_POST['confirm_phrase'] ?? '';
        if ($phrase !== 'RESET-FACTORY') {
            echo json_encode(['success' => false, 'message' => 'Frase de confirmación incorrecta']);
            return;
        }

        // 2. Verificar contraseña de administrador
        $password = $_POST['admin_password'] ?? '';
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Contraseña requerida']);
            return;
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentUser || !password_verify($password, $currentUser['password'])) {
            echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
            return;
        }

        try {
            $tenant_id = getCurrentTenantId();
            $isPerDb = $isPerDbMode;
            $fileScopeId = $fileScopeIdForTenant((int)$tenant_id);
            $hasColumn = function (string $table, string $column) use ($pdo): bool {
                try {
                    $t = str_replace('`', '``', $table);
                    $c = str_replace('`', '``', $column);
                    $stmt = $pdo->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
                    return (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
                } catch (Throwable $e) {
                    return false;
                }
            };
            // No usamos transacción porque TRUNCATE causa commit implícito, pero DELETE no.
            // Usaremos transacción para DELETE.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

            // Obtener todas las tablas
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            // Tablas a PRESERVAR (Catálogos y Configuración)
            $keepTables = [
                'system_config',
                'company_config',
                'company_settings',
                'users', // Se maneja aparte
                'brands',
                'device_types',
                'models',
                'problem_types',
                'accessories',
                'payment_methods',
                'order_statuses', // Preservar estados a petición del usuario
                'document_templates',
                'accessories_checklist', // Preservar checklist de accesorios
                'equipment_accessories', // Preservar accesorios de equipos
                'tenants' // CRITICO: Preservar tenants
            ];

            foreach ($tables as $table) {
                if (!in_array($table, $keepTables)) {
                    if ($isPerDb) {
                        $pdo->exec("DELETE FROM `$table`");
                    } else {
                        if ($hasColumn($table, 'tenant_id')) {
                            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE tenant_id = ?");
                            $stmt->execute([$tenant_id]);
                        }
                    }
                }
            }

            if ($isPerDb || $hasColumn('users', 'tenant_id')) {
                $stmt = $pdo->prepare("SELECT photo FROM users WHERE id != ? AND tenant_id = ? AND photo IS NOT NULL AND photo != ''");
                $stmt->execute([$_SESSION['user_id'], $tenant_id]);
            } else {
                $stmt = $pdo->prepare("SELECT photo FROM users WHERE id != ? AND photo IS NOT NULL AND photo != ''");
                $stmt->execute([$_SESSION['user_id']]);
            }
            $photosToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if ($isPerDb || $hasColumn('users', 'tenant_id')) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id != ? AND tenant_id = ?");
                $stmt->execute([$_SESSION['user_id'], $tenant_id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id != ?");
                $stmt->execute([$_SESSION['user_id']]);
            }

            try {
                $resetAuto = function ($table) use ($pdo) {
                    try {
                        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $hasAuto = false;
                        foreach ($cols as $c) {
                            $extra = strtolower((string)($c['Extra'] ?? ''));
                            if (strpos($extra, 'auto_increment') !== false) {
                                $hasAuto = true;
                                break;
                            }
                        }
                        if (!$hasAuto) {
                            return;
                        }
                        $cnt = (int)($pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() ?? 0);
                        if ($cnt === 0) {
                            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                        }
                    } catch (Throwable $e) {
                    }
                };

                foreach ($tables as $t) {
                    $resetAuto($t);
                }
            } catch (Throwable $e) {
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

            cleanupTenantFiles($fileScopeId);
            try {
                $b1 = rtrim(getTenantUploadsFsById($fileScopeId), '/\\') . DIRECTORY_SEPARATOR . 'backups';
                $b2 = rtrim(getTenantStorageFsById($fileScopeId), '/\\') . DIRECTORY_SEPARATOR . 'backups';
                deletePathRecursive($b1);
                deletePathRecursive($b2);
            } catch (Throwable $e) {
            }


            // Log del evento
            logActivity($_SESSION['user_id'], 'FACTORY_RESET', 'system', 0);

            echo json_encode(['success' => true, 'message' => 'Sistema restaurado a fábrica correctamente.']);
        }
        catch (Exception $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            error_log("Error en Factory Reset: " . $e->getMessage());
            $msg = 'Error crítico al restaurar: Operación fallida';
            if (stripos($e->getMessage(), 'no active transaction') !== false) {
                $msg = 'Error crítico al restaurar: No hay una transacción activa';
            }
            echo json_encode(['success' => false, 'message' => $msg]);
        }
        return;
    }

    if ($resetMode === 'renumber') {
        echo json_encode(['success' => false, 'message' => 'Renumeración no soportada en modo Multi-tenant']);
        return;
    }

    $modules = $_POST['modules'] ?? ['orders', 'sales', 'clients', 'inventory', 'cash', 'documents'];
    if (is_string($modules)) {
        $modules = [$modules];
    }
    $modules = array_filter(array_map('strval', $modules));

    if (empty($modules)) {
        $modules = ['orders', 'sales', 'clients', 'inventory', 'cash', 'documents'];
    }

    $moduleTables = [
        'orders' => ['work_orders', 'order_accessories', 'order_equipment_accessories', 'order_checklist', 'order_services', 'order_status_history'],
        'sales' => ['invoice_payments', 'invoice_items', 'invoice_drafts', 'invoices'],
        'clients' => ['clients'],
        'inventory' => ['inventory_movements', 'inventory_products', 'suppliers'],
        'cash' => ['cash_income', 'cash_expenses', 'cash_sessions'],
        'documents' => ['documents']
    ];

    $tablesToTruncate = [];
    foreach ($modules as $mod) {
        if (isset($moduleTables[$mod])) {
            foreach ($moduleTables[$mod] as $t) {
                $tablesToTruncate[$t] = true;
            }
        }
    }

    if (empty($tablesToTruncate)) {
        echo json_encode(['success' => false, 'message' => 'No hay tablas válidas para reiniciar']);
        return;
    }

    try {
        $tenant_id = getCurrentTenantId();
        if ($dryRun) {
            $baseUploads = __DIR__ . '/../../uploads/';
            $tenantUploads = getTenantUploadDir($baseUploads);
            $preserve = ['users', 'brands', 'backups'];
            $targets = [];
            foreach ($modules as $m) {
                if ($m === 'orders') {
                    $targets[] = 'orders';
                }
                if ($m === 'cash') {
                    $targets[] = 'expenses';
                }
                if ($m === 'documents') {
                    $targets[] = 'documents';
                }
            }
            $targets = array_unique($targets);
            $preview = listTenantCleanup($tenant_id, $targets);
            echo json_encode(['success' => true, 'mode' => 'truncate', 'dry_run' => true, 'modules' => $modules, 'delete' => $preview['delete'], 'preserve' => $preview['preserve'], 'counts' => ['delete' => count($preview['delete']), 'preserve' => count($preview['preserve'])]]);
            return;
        }
        $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip($existingTables);

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $order = [
            'invoice_payments',
            'invoice_items',
            'invoice_drafts',
            'order_equipment_accessories',
            'order_accessories',
            'order_checklist',
            'order_services',
            'order_status_history',
            'inventory_movements',
            'cash_income',
            'cash_expenses',
            'invoices',
            'work_orders',
            'cash_sessions',
            'inventory_products',
            'suppliers',
            'clients',
            'documents'
        ];
        $orderedTables = [];
        foreach ($order as $t) {
            if (isset($tablesToTruncate[$t]) && isset($existingSet[$t])) {
                $orderedTables[] = $t;
                unset($tablesToTruncate[$t]);
            }
        }
        foreach (array_keys($tablesToTruncate) as $t) {
            if (isset($existingSet[$t])) {
                $orderedTables[] = $t;
            }
        }

        $preserveCatalogs = ['device_types', 'brands', 'models', 'problem_types', 'accessories', 'equipment_accessories', 'order_statuses'];
        foreach ($orderedTables as $t) {
            // Verificar tenant_id
            $hasTenant = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'tenant_id'");
                $hasTenant = ($c && $c->rowCount() > 0);
            }
            catch (Exception $e) {
            }

            if ($hasTenant && !in_array($t, $preserveCatalogs, true)) {
                $stmt = $pdo->prepare("DELETE FROM `$t` WHERE tenant_id = ?");
                $stmt->execute([$tenant_id]);
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $baseUploads = __DIR__ . '/../../uploads/';
        $tenantUploads = getTenantUploadDir($baseUploads);
        $preserve = ['users', 'brands', 'backups'];
        $targets = [];
        foreach ($modules as $m) {
            if ($m === 'orders') {
                $targets[] = 'orders';
            }
            if ($m === 'cash') {
                $targets[] = 'expenses';
            }
            if ($m === 'documents') {
                $targets[] = 'documents';
            }
        }
        $targets = array_unique($targets);
        cleanupTenantFiles($tenant_id, $targets);

        echo json_encode(['success' => true, 'message' => 'Datos reiniciados correctamente']);
    }
    catch (Exception $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        catch (Exception $e2) {
        }
        error_log("Error en resetBusinessData: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al reiniciar datos: ' . $e->getMessage()]);
    }
}

function createUser()
{
    global $pdo;
    global $perDatabase;
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        return;
    }

    // Verificar permisos de administrador
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'Usuario';

    if ($name === '' || $email === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }

    $valid_roles = ['admin', 'technician', 'inventory', 'user'];
    if (!in_array($role, $valid_roles)) {
        $role_map = [
            'Administrador' => 'admin',
            'Editor' => 'technician',
            'Inventario' => 'inventory',
            'Usuario' => 'user'
        ];
        $role = $role_map[$role] ?? 'user';
    }

    $tenant_id = getCurrentTenantId();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
    $stmt->execute([$email, $tenant_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'El email ya está registrado']);
        return;
    }

    $empresaId = 0;
    if ($perDatabase && class_exists('DatabaseManager')) {
        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            echo json_encode(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        try {
            $master = DatabaseManager::master();
            $chk = $master->prepare('SELECT id FROM usuarios_master WHERE email = ? LIMIT 1');
            $chk->execute([$email]);
            $existing = $chk->fetchColumn();
            if ($existing) {
                echo json_encode(['success' => false, 'message' => 'El correo está asociado a otra empresa']);
                return;
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Error al validar el correo']);
            return;
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    require_once __DIR__ . '/performance_optimizer.php';

    // Procesar foto de perfil
    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photo']['tmp_name'] ?? '';
        if ($tmp !== '' && is_uploaded_file($tmp)) {
            $maxBytes = 2 * 1024 * 1024;
            $size = (int)($_FILES['photo']['size'] ?? 0);
            if ($size > 0 && $size <= $maxBytes) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp) ?: '';
                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp'
                ];
                if (isset($allowed_mimes[$mime])) {
                    $ext = $allowed_mimes[$mime];
                    $info = @getimagesize($tmp);
                    if ($info && !empty($info['mime'])) {
                        $w = (int)($info[0] ?? 0);
                        $h = (int)($info[1] ?? 0);
                        if ($w > 0 && $h > 0 && ($w * $h) <= 25_000_000) {
                    $temp_path = $tmp;
                        }
                    }
                }
            }
        }
    }

    $masterCreated = false;
    if ($perDatabase && class_exists('DatabaseManager')) {
        try {
            $master = DatabaseManager::master();
            $ins = $master->prepare('
                INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
            ');
            $ins->execute([$empresaId, $email, $hash, $role, $name]);
            $masterCreated = true;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Error al crear usuario']);
            return;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
    if ($stmt->execute([$tenant_id, $name, $email, $hash, $role])) {
        $user_id = $pdo->lastInsertId();

        // Si hay foto, guardarla con el ID del usuario
        if (isset($temp_path)) {
            $new_filename = 'user_' . $user_id . '.' . $ext;

            // Usar directorio aislado por tenant (dentro de /core/uploads)
            $uploadsScopeId = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$tenant_id;
            if ($uploadsScopeId <= 0) {
                $uploadsScopeId = (int)$tenant_id;
            }
            $upload_dir = ensureTenantSubdirFs($uploadsScopeId, 'users');
            $target = rtrim($upload_dir, '/\\') . '/' . $new_filename;
            $moved = moveUploadedFileCross($temp_path, $target);
            if ($moved && file_exists($target)) {
                $extLower = strtolower(pathinfo($new_filename, PATHINFO_EXTENSION));
                if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                    PerformanceOptimizer::optimizeImage($target, $target, 85);
                }
                $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")->execute([$new_filename, $user_id]);
            }
        }

        echo json_encode(['success' => true]);
    }
    else {
        if ($masterCreated && $perDatabase && class_exists('DatabaseManager')) {
            try {
                $master = DatabaseManager::master();
                $del = $master->prepare('DELETE FROM usuarios_master WHERE email = ? AND empresa_id = ?');
                $del->execute([$email, $empresaId]);
            } catch (Throwable $e) {
            }
        }
        echo json_encode(['success' => false, 'message' => 'Error al crear usuario']);
    }
}


function updateUser()
{
    global $pdo;
    global $perDatabase;
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        return;
    }

    // Verificar permisos de administrador
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    $id = intval($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $active = isset($_POST['active']) ? intval($_POST['active']) : 1;

    if ($id <= 0 || $name === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }

    // Determinar tenant del usuario editado para evitar desajustes
    $tenant_id_user = null;
    $oldEmail = '';
    $existingPasswordHash = '';
    try {
        $tstmt = $pdo->prepare("SELECT tenant_id, email, password FROM users WHERE id = ? LIMIT 1");
        $tstmt->execute([$id]);
        $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($trow)) {
            $tenant_id_user = (int)($trow['tenant_id'] ?? 0);
            $oldEmail = (string)($trow['email'] ?? '');
            $existingPasswordHash = (string)($trow['password'] ?? '');
        }
    }
    catch (Throwable $e) {
    }
    if (!$tenant_id_user) {
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        return;
    }
    $tenant_id = $tenant_id_user;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND tenant_id = ?");
    $stmt->execute([$email, $id, $tenant_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'El email ya está en uso']);
        return;
    }

    // Evitar colisión con otra empresa en lookup global
    try {
        $lk = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
        $lk->execute([$email]);
        $lkTenant = $lk->fetchColumn();
        if ($lkTenant && (int)$lkTenant !== (int)$tenant_id) {
            echo json_encode(['success' => false, 'message' => 'El correo está asociado a otra empresa']);
            return;
        }
    }
    catch (Throwable $e) {
    }

    $sql = "UPDATE users SET name=?, email=?, active=?";
    $params = [$name, $email, $active];

    if ($role !== '') {
        // Validar que el rol sea uno de los permitidos
        $valid_roles = ['admin', 'technician', 'inventory', 'user'];
        if (!in_array($role, $valid_roles)) {
            // Mapeo de compatibilidad para valores antiguos
            $role_map = [
                'Administrador' => 'admin',
                'Editor' => 'technician',
                'Inventario' => 'inventory',
                'Usuario' => 'user'
            ];
            $role = $role_map[$role] ?? 'user';
        }
        $sql .= ", role=?";
        $params[] = $role;
    }

    require_once __DIR__ . '/performance_optimizer.php';

    // Procesar foto de perfil
    $photoAttempted = (!empty($_FILES['photo']['name'] ?? ''));
    $photoSaved = false;
    if (isset($_FILES['photo'])) {
        $photoError = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($photoError === UPLOAD_ERR_OK) {
            $tmp = $_FILES['photo']['tmp_name'] ?? '';
            $maxBytes = 2 * 1024 * 1024;
            $size = (int)($_FILES['photo']['size'] ?? 0);
            if ($tmp !== '' && is_uploaded_file($tmp) && $size > 0 && $size <= $maxBytes) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp) ?: '';
                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp'
                ];
                if (isset($allowed_mimes[$mime])) {
                    $ext = $allowed_mimes[$mime];
                    $info = @getimagesize($tmp);
                    if (!$info || empty($info['mime'])) {
                        $ext = '';
                    } else {
                        $w = (int)($info[0] ?? 0);
                        $h = (int)($info[1] ?? 0);
                        if ($w <= 0 || $h <= 0 || ($w * $h) > 25_000_000) {
                            $ext = '';
                        }
                    }
                }
                if ($ext !== '') {
                    $new_filename = 'user_' . $id . '.' . $ext;

                // Usar directorio aislado por tenant (dentro de /core/uploads)
                $uploadsScopeId = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$tenant_id;
                if ($uploadsScopeId <= 0) {
                    $uploadsScopeId = (int)$tenant_id;
                }
                $upload_dir = ensureTenantSubdirFs($uploadsScopeId, 'users');
                $pattern = rtrim($upload_dir, '/\\') . '/user_' . $id . '.*';
                foreach (glob($pattern) as $old) {
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }
                $target = rtrim($upload_dir, '/\\') . '/' . $new_filename;
                $moved = moveUploadedFileCross($tmp, $target);
                if ($moved && file_exists($target)) {
                    $extLower = strtolower(pathinfo($new_filename, PATHINFO_EXTENSION));
                    if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                        PerformanceOptimizer::optimizeImage($target, $target, 85);
                    }
                    $sql .= ", photo=?";
                    $params[] = $new_filename;
                    $photoSaved = true;

                    // Si es el usuario actual, actualizar sesión inmediatamente
                    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
                        $_SESSION['user_photo'] = $new_filename;
                    }
                }
                }
            }
        }
    }

    // Actualizar rol en sesión si el usuario se edita a sí mismo
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id && $role !== '') {
        $_SESSION['user_role'] = $role;
    }
    $tenant_id = getCurrentTenantId();
    $sql .= " WHERE id=? AND tenant_id=?";
    $params[] = $id;
    $params[] = $tenant_id;

    $stmt = $pdo->prepare($sql);
    if ($stmt->execute($params)) {
        // Sincronizar lookup global para recuperación/login por correo
        try {
            $ins = $pdo->prepare("INSERT INTO saas_users_lookup (email, tenant_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)");
            $ins->execute([$email, $tenant_id]);
        }
        catch (Throwable $e) {
        }
        if ($perDatabase && class_exists('DatabaseManager') && $oldEmail !== '') {
            $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
            if ($empresaId > 0) {
                try {
                    $master = DatabaseManager::master();
                    if ($email !== $oldEmail) {
                        $chk = $master->prepare('SELECT id FROM usuarios_master WHERE email = ? LIMIT 1');
                        $chk->execute([$email]);
                        if ($chk->fetchColumn()) {
                            echo json_encode(['success' => false, 'message' => 'El correo está asociado a otra empresa']);
                            return;
                        }
                    }

                    $msql = 'UPDATE usuarios_master SET email = ?, nombre = ?, activo = ?, updated_at = NOW()';
                    $mparams = [$email, $name, $active];
                    if ($role !== '') {
                        $msql .= ', rol = ?';
                        $mparams[] = $role;
                    }
                    $msql .= ' WHERE email = ? AND empresa_id = ? LIMIT 1';
                    $mparams[] = $oldEmail;
                    $mparams[] = $empresaId;
                    $mupd = $master->prepare($msql);
                    $mupd->execute($mparams);
                    if ((int)$mupd->rowCount() === 0) {
                        $mins = $master->prepare('
                            INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ');
                        $mins->execute([$empresaId, $email, $existingPasswordHash, ($role !== '' ? $role : 'user'), $name, $active]);
                    }
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar usuario']);
                    return;
                }
            }
        }
        echo json_encode([
            'success' => true,
            'photo_saved' => $photoSaved ? 1 : 0,
            'message' => $photoAttempted && !$photoSaved ? 'Usuario actualizado, pero la foto no se pudo guardar' : 'Usuario actualizado correctamente'
        ]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar']);
    }
}

function deleteUser()
{
    global $pdo;
    global $perDatabase;
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        return;
    }

    // Verificar permisos de administrador
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Requiere permisos de administrador']);
        return;
    }

    $id = intval($_POST['user_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
        echo json_encode(['success' => false, 'message' => 'No puedes eliminar tu propio usuario']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $email = '';
    try {
        $q = $pdo->prepare('SELECT email FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $q->execute([$id, $tenant_id]);
        $email = (string)$q->fetchColumn();
    } catch (Throwable $e) {
    }
    $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND tenant_id=?");
    if ($stmt->execute([$id, $tenant_id])) {
        if ($perDatabase && class_exists('DatabaseManager') && $email !== '') {
            $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
            if ($empresaId > 0) {
                try {
                    $master = DatabaseManager::master();
                    $del = $master->prepare('DELETE FROM usuarios_master WHERE email = ? AND empresa_id = ?');
                    $del->execute([$email, $empresaId]);
                } catch (Throwable $e) {
                }
            }
        }
        echo json_encode(['success' => true]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar']);
    }
}

function changePassword()
{
    global $pdo;
    global $perDatabase;
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        return;
    }

    // Para cambiar contraseña, puede ser el propio usuario o un admin
    // Si es otro usuario, requiere admin
    $id = intval($_POST['user_id'] ?? 0);
    $is_admin = function_exists('hasRole') && hasRole(['admin', 'administrador', 'administrator']);

    if ($id != $_SESSION['user_id'] && !$is_admin) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        return;
    }

    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';

    if ($id <= 0 || $new === '') {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }

    // Si es el mismo usuario, verificar contraseña anterior
    // Si es admin cambiando a otro, tal vez no requiera anterior? 
    // Por seguridad, siempre requerimos anterior si es el propio usuario.
    // Si es admin reseteando password, usualmente no se pide la anterior del usuario, pero aqui el form la pide.
    // Asumiremos que el flujo actual siempre pide anterior.

    if ($old === '') {
        echo json_encode(['success' => false, 'message' => 'Falta la contraseña anterior']);
        return;
    }

    $tenant_id = getCurrentTenantId();
    $stmt = $pdo->prepare("SELECT name, email, role, active, password FROM users WHERE id=? AND tenant_id=?");
    $stmt->execute([$id, $tenant_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        return;
    }

    $oldOk = false;
    $currentHash = (string)($user['password'] ?? '');
    if ($currentHash !== '' && password_verify($old, $currentHash)) {
        $oldOk = true;
    } elseif (strlen($currentHash) === 32 && ctype_xdigit($currentHash) && md5($old) === $currentHash) {
        $oldOk = true;
    }

    if ($perDatabase && class_exists('DatabaseManager')) {
        $email = (string)($user['email'] ?? '');
        if ($email !== '') {
            try {
                $mrow = DatabaseManager::getUsuarioByEmail($email);
                if (is_array($mrow)) {
                    $mh = (string)($mrow['password_hash'] ?? '');
                    if ($mh !== '' && password_verify($old, $mh)) {
                        $oldOk = true;
                    } elseif (strlen($mh) === 32 && ctype_xdigit($mh) && md5($old) === $mh) {
                        $oldOk = true;
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }

    if (!$oldOk) {
        echo json_encode(['success' => false, 'message' => 'La contraseña anterior es incorrecta']);
        return;
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    if ($perDatabase && class_exists('DatabaseManager')) {
        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
        $email = (string)($user['email'] ?? '');
        $name = (string)($user['name'] ?? '');
        $role = (string)($user['role'] ?? 'user');
        $active = (int)($user['active'] ?? 1);

        if ($empresaId > 0 && $email !== '') {
            try {
                $master = DatabaseManager::master();
                $upd = $master->prepare('UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE email = ? AND empresa_id = ? LIMIT 1');
                $upd->execute([$hash, $email, $empresaId]);
                if ((int)$upd->rowCount() === 0) {
                    $ins = $master->prepare('
                        INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ');
                    $ins->execute([$empresaId, $email, $hash, $role, $name, $active]);
                }
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al cambiar la contraseña']);
                return;
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=? AND tenant_id=?");
    if ($stmt->execute([$hash, $id, $tenant_id])) {
        echo json_encode(['success' => true]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar la contraseña']);
    }
}

// catalogsReseed eliminado

function checkOrderPrefix()
{
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $raw = isset($_POST['prefix']) ? $_POST['prefix'] : '';
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($raw)));
    if ($norm === '') {
        echo json_encode(['success' => true, 'available' => true]);
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT tenant_id FROM system_config WHERE config_key = 'order_prefix' AND LOWER(TRIM(config_value)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$norm]);
        $tid = $stmt->fetchColumn();
        $available = (!$tid || (int)$tid === (int)$tenant_id);
        echo json_encode(['success' => true, 'available' => $available]);
    }
    catch (Throwable $e) {
        echo json_encode(['success' => false, 'available' => false, 'message' => 'Error']);
    }
}

switch ($action) {
    case 'update_company':
        updateCompanyConfig();
        break;
    // catalogs_reseed eliminado
    case 'update_regional_settings':
        updateRegionalSettings();
        break;
    case 'check_order_prefix':
        checkOrderPrefix();
        break;
    case 'payment_methods_list':
        paymentMethodsList();
        break;
    case 'payment_methods_add':
        paymentMethodsAdd();
        break;
    case 'payment_methods_update':
        paymentMethodsUpdate();
        break;
    case 'payment_methods_toggle':
        paymentMethodsToggle();
        break;
    case 'payment_methods_delete':
        paymentMethodsDelete();
        break;
    case 'payment_accounts_list':
        paymentAccountsList();
        break;
    case 'payment_accounts_add':
        paymentAccountsAdd();
        break;
    case 'payment_accounts_update':
        paymentAccountsUpdate();
        break;
    case 'payment_accounts_toggle':
        paymentAccountsToggle();
        break;
    case 'payment_accounts_set_default':
        paymentAccountsSetDefault();
        break;
    case 'payment_accounts_delete':
        paymentAccountsDelete();
        break;
    case 'reset_business_data':
        resetBusinessData();
        break;
    case 'create_user':
        createUser();
        break;
    case 'update_user':
        updateUser();
        break;
    case 'delete_user':
        deleteUser();
        break;
    case 'change_password':
        changePassword();
        break;
    case 'update_whatsapp_templates':
        updateWhatsappTemplates();
        break;
    case 'update_appearance':
        updateAppearance();
        break;
    case 'reset_appearance':
        resetAppearance();
        break;
    case 'convert_collation_spanish':
        convertCollationToSpanish();
        break;
    case 'scan_invalid_chars':
        scanInvalidChars();
        break;
    case 'fix_invalid_chars':
        fixInvalidChars();
        break;
    case 'order_invoice_summary':
        orderInvoiceSummary();
        break;
    case 'repair_text_encoding':
        repairTextEncoding();
        break;
    default:
        // Si no hay action específica, verificar si es actualización de empresa
        if (isset($_POST['company_name'])) {
            updateCompanyConfig();
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        }
        break;
}
?>
