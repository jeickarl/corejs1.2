<?php
require_once '../config/session.php';
requireAuth('../login/index.php');

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';
require_once '../config/performance_optimizer.php';
$pdo = db();

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantClients = hasTenantColumnCached($pdo, 'clients');
$hasTenantStatuses = hasTenantColumnCached($pdo, 'order_statuses');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');
$hasTenantDeviceTypes = hasTenantColumnCached($pdo, 'device_types');
$hasTenantModels = hasTenantColumnCached($pdo, 'models');
$hasTenantAccessories = hasTenantColumnCached($pdo, 'equipment_accessories');
$hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');

// Asegurar esquema de accesorios con tenant_id
if (function_exists('ensureAccessoriesTenant')) {
    ensureAccessoriesTenant($pdo, $tenant_id);
}
if (function_exists('normalizeAccessoriesTenants')) {
    normalizeAccessoriesTenants($pdo, $tenant_id);
}
$fn = 'ensureDeviceTypesTenant';
if (function_exists($fn)) {
    $fn($pdo, $tenant_id);
}
$fn = 'ensureBrandsTenant';
if (function_exists($fn)) {
    $fn($pdo, $tenant_id);
}
$fn = 'ensureModelsTenant';
if (function_exists($fn)) {
    $fn($pdo, $tenant_id);
}
if (function_exists('normalizeCatalogsToTenant')) {
    normalizeCatalogsToTenant($pdo, $tenant_id);
}
if (function_exists('normalizeModelRelationsTenant')) {
    normalizeModelRelationsTenant($pdo, $tenant_id);
}

// Obtener configuración de moneda
$currency_config = CompanySettings::getCurrency();
$system_config_js = [
    'currency' => $currency_config
];

$errors = [];
$success = '';

// Obtener lista de clientes para el select
$clients_query = "SELECT id, first_name, company_name, client_type FROM clients" . ((!$perDatabase && $hasTenantClients) ? " WHERE tenant_id = ?" : "") . " ORDER BY first_name, company_name";
$clients_stmt = $pdo->prepare($clients_query);
$clients_stmt->execute((!$perDatabase && $hasTenantClients) ? [$tenantValue] : []);
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener cliente preseleccionado si se pasa client_id en la URL
$preselected_client = null;
$preselected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($preselected_client_id > 0) {
    try {
        $client_query = "SELECT id, first_name, company_name, client_type, phone, email, id_number FROM clients WHERE id = ?" . ((!$perDatabase && $hasTenantClients) ? " AND tenant_id = ?" : "");
        $client_stmt = $pdo->prepare($client_query);
        $client_stmt->execute((!$perDatabase && $hasTenantClients) ? [$preselected_client_id, $tenantValue] : [$preselected_client_id]);
        $preselected_client = $client_stmt->fetch(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e) {
        $preselected_client = null;
    }
}

// Obtener estados de órdenes
try {
    $statuses_query = "SELECT id, name, slug, emoji, color, is_default, sort_order
                       FROM order_statuses
                       WHERE is_active = 1 " . ((!$perDatabase && $hasTenantStatuses) ? "AND tenant_id = ?" : "") . "
                       ORDER BY sort_order ASC, id ASC";
    $statuses_stmt = $pdo->prepare($statuses_query);
    $statuses_stmt->execute((!$perDatabase && $hasTenantStatuses) ? [$tenantValue] : []);
    $rows = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    foreach ($rows as $row) {
        $key = strtolower(trim((string)($row['slug'] ?? '')));
        if ($key === '') {
            continue;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'slug' => (string)($row['slug'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'emoji' => (string)($row['emoji'] ?? ''),
                'color' => (string)($row['color'] ?? ''),
                'is_default' => (int)($row['is_default'] ?? 0),
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'id' => (int)($row['id'] ?? 0),
            ];
            continue;
        }
        if ((int)($row['is_default'] ?? 0) === 1) {
            $groups[$key]['is_default'] = 1;
        }
    }
    $statuses = array_values($groups);
    usort($statuses, function ($a, $b) {
        $ao = (int)($a['sort_order'] ?? 0);
        $bo = (int)($b['sort_order'] ?? 0);
        if ($ao === 0 && $bo !== 0) { return 1; }
        if ($bo === 0 && $ao !== 0) { return -1; }
        if ($ao === $bo) { return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')); }
        return $ao <=> $bo;
    });

    // Obtener estado por defecto
    $default_status = null;
    foreach ($statuses as $status) {
        if ($status['is_default']) {
            $default_status = $status['slug'];
            break;
        }
    }
    if ($default_status === null) {
        $default_status = !empty($statuses) ? (string)($statuses[0]['slug'] ?? 'pending') : 'pending';
    }
}
catch (PDOException $e) {
    // Si la tabla no existe, usar estados por defecto
    $statuses = [
        ['slug' => 'pendiente', 'name' => 'Pendiente', 'color' => '#ffc107', 'emoji' => '⏳'],
        ['slug' => 'asignado', 'name' => 'Asignado', 'color' => '#6cc4ea', 'emoji' => '📥'],
        ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'color' => '#fd7e14', 'emoji' => '🔍'],
        ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'color' => '#6f42c1', 'emoji' => '⏸️'],
        ['slug' => 'reparando', 'name' => 'Reparando', 'color' => '#007bff', 'emoji' => '🔧'],
        ['slug' => 'testeando', 'name' => 'Testeando', 'color' => '#17a2b8', 'emoji' => '🧪'],
        ['slug' => 'completado', 'name' => 'Completado', 'color' => '#28a745', 'emoji' => '✅'],
        ['slug' => 'entregado', 'name' => 'Entregado', 'color' => '#6c757d', 'emoji' => '🚚'],
        ['slug' => 'cancelado', 'name' => 'Cancelado', 'color' => '#dc3545', 'emoji' => '❌']
    ];
    $default_status = 'pendiente';
    error_log("Error obteniendo estados de órdenes: " . $e->getMessage());
}

// Obtener marcas
try {
    $hasActive = hasColumnCached($pdo, 'brands', 'is_active');
    $brands_sql = "SELECT id, name FROM brands WHERE " . ($hasActive ? "is_active = 1" : "1=1") . ((!$perDatabase && $hasTenantBrands) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $brands_stmt = $pdo->prepare($brands_sql);
    $brands_stmt->execute((!$perDatabase && $hasTenantBrands) ? [$tenantValue] : []);
    $brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $brands = [];
    error_log("Error obteniendo marcas: " . $e->getMessage());
}

// Obtener tipos de dispositivos
try {
    $hasActive = hasColumnCached($pdo, 'device_types', 'is_active');
    $hasVisible = hasColumnCached($pdo, 'device_types', 'is_visible');
    $hasSortOrder = hasColumnCached($pdo, 'device_types', 'sort_order');
    $where = [];
    if ($hasActive) {
        $where[] = "is_active = 1";
    }
    if ($hasVisible) {
        $where[] = "is_visible = 1";
    }
    if (!$perDatabase && $hasTenantDeviceTypes) { $where[] = "tenant_id = ?"; }
    $orderBy = $hasSortOrder ? "ORDER BY sort_order, name" : "ORDER BY name";
    $device_types_sql = "SELECT id, name FROM device_types WHERE " . implode(" AND ", $where) . " $orderBy";
    $device_types_stmt = $pdo->prepare($device_types_sql);
    $device_types_stmt->execute((!$perDatabase && $hasTenantDeviceTypes) ? [$tenantValue] : []);
    $device_types = $device_types_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $device_types = [];
    error_log("Error obteniendo tipos de dispositivos: " . $e->getMessage());
}

// Obtener modelos
try {
    $hasActive = hasColumnCached($pdo, 'models', 'is_active');
    $models_sql = "SELECT id, name, brand_id FROM models WHERE " . ($hasActive ? "is_active = 1" : "1=1") . ((!$perDatabase && $hasTenantModels) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $models_stmt = $pdo->prepare($models_sql);
    $models_stmt->execute((!$perDatabase && $hasTenantModels) ? [$tenantValue] : []);
    $models = $models_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $models = [];
    error_log("Error obteniendo modelos: " . $e->getMessage());
}

// Obtener accesorios
$accessories_sql = "SELECT id, name, description, category, sort_order FROM equipment_accessories WHERE is_active = 1" . ((!$perDatabase && $hasTenantAccessories) ? " AND tenant_id = ?" : "") . " ORDER BY sort_order ASC, name ASC";
try {
    $stmtAcc = $pdo->prepare($accessories_sql);
    $stmtAcc->execute((!$perDatabase && $hasTenantAccessories) ? [$tenantValue] : []);
    $equipment_accessories = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $equipment_accessories = [];
    error_log("Error obteniendo accesorios: " . $e->getMessage());
}

// Si no hay accesorios, sembrar por defecto y volver a consultar
if (empty($equipment_accessories) && function_exists('ensureDefaultAccessories')) {
    ensureDefaultAccessories($pdo, $tenant_id);
    try {
        $stmtAcc2 = $pdo->prepare($accessories_sql);
        $stmtAcc2->execute((!$perDatabase && $hasTenantAccessories) ? [$tenantValue] : []);
        $equipment_accessories = $stmtAcc2->fetchAll(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e) {
        error_log("Error reconsultando accesorios: " . $e->getMessage());
    }
}
// Obtener métodos de pago activos
$payment_methods = [];
try {
    $pm_sql = "SELECT * FROM payment_methods WHERE status = 'active'" . ((!$perDatabase && $hasTenantPaymentMethods) ? " AND tenant_id = ?" : "") . " ORDER BY name ASC";
    $pm_stmt = $pdo->prepare($pm_sql);
    $pm_stmt->execute((!$perDatabase && $hasTenantPaymentMethods) ? [$tenantValue] : []);
    $payment_methods = $pm_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    error_log("Error obteniendo métodos de pago: " . $e->getMessage());
}
// Asegurar efectivo
$has_efectivo = false;
foreach ($payment_methods as $pm) {
    if (strcasecmp($pm['name'], 'Efectivo') === 0) {
        $has_efectivo = true;
        break;
    }
}
if (!$has_efectivo) {
    array_unshift($payment_methods, ['id' => 0, 'name' => 'Efectivo']);
}

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();
// Mantener compatibilidad con sesiones antiguas si es necesario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Token de seguridad inválido o expirado. Por favor, intente de nuevo.";
    }
    else {
        // Validación de campos
        $client_id = $_POST['client_id'] ?? '';

        $device_type_id = $_POST['device_type_id'] ?? '';
        $device_brand = trim($_POST['device_brand'] ?? '');
        $device_model = trim($_POST['device_model'] ?? '');
        $device_password = trim($_POST['device_password'] ?? '');
        $serial_number = trim($_POST['serial_number'] ?? '');
        $reported_issue = trim($_POST['problem_description'] ?? '');
        $client_observations = trim($_POST['client_observations'] ?? '');
        $technician_notes = trim($_POST['technician_notes'] ?? '');


        $estimated_cost = parseCurrency($_POST['estimated_cost'] ?? '');
        $estimated_completion = $_POST['estimated_completion'] ?? '';
        $advance_payment = parseCurrency($_POST['advance_payment'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? '';
        $payment_reference = trim($_POST['payment_reference'] ?? '');

        $status = $default_status;
        $priority = $_POST['priority'] ?? 'medium';
        $equipment_accessories_data = $_POST['accessories'] ?? [];

        // Validaciones
        if (empty($client_id)) {
            $errors[] = "Debe seleccionar un cliente.";
        }

        if (empty($device_type_id)) {
            $errors[] = "Debe seleccionar un tipo de dispositivo.";
        }

        if (empty($serial_number)) {
            $errors[] = "El número de serie es obligatorio.";
        }
        if (empty($reported_issue)) {
            $errors[] = "La descripción del problema es obligatoria.";
        }

        // Validar costo estimado si se proporciona
        if (!empty($estimated_cost) && (!is_numeric($estimated_cost) || $estimated_cost < 0)) {
            $errors[] = "El costo estimado debe ser un número válido.";
        }

        // Validar abono
        if ($advance_payment > 0 && empty($payment_method)) {
            $errors[] = "Debe seleccionar un método de pago para el abono.";
        }

        // Si no hay errores, insertar la orden
        if (empty($errors)) {
            try {
                if (function_exists('ensurePortalSchema')) {
                    ensurePortalSchema($pdo, $tenant_id);
                }

                $hasClientObsCol = false;
                try {
                    $hasClientObsCol = hasColumnCached($pdo, 'work_orders', 'client_observations');
                    if (!$hasClientObsCol) {
                        $pdo->exec("ALTER TABLE work_orders ADD COLUMN client_observations TEXT NULL AFTER reported_issue");
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
                            $_SESSION['schema_cache_cols']['work_orders_client_observations'] = true;
                        }
                        $hasClientObsCol = true;
                    }
                } catch (Throwable $__) {}

                if ($perDatabase) {
                    if ($hasClientObsCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO work_orders (client_id, device_type_id, device_category_id, device_brand, device_model, device_password, serial_number, reported_issue, 
                                              client_observations, device_photo, diagnosis, solution, status, priority, estimated_cost, advance_payment, payment_method, payment_reference, final_cost, technician_notes, 
                                              received_date, estimated_completion, completed_date, delivered_date, created_at, updated_at) 
                            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), ?, NULL, NULL, NOW(), NOW())
                        ");
                        $stmt->execute([
                            $client_id,
                            $device_type_id,
                            $device_brand ?: null,
                            $device_model ?: null,
                            $device_password ?: null,
                            $serial_number ?: null,
                            $reported_issue,
                            $client_observations !== '' ? $client_observations : null,
                            $status,
                            $priority,
                            $estimated_cost ?: null,
                            $advance_payment ?: 0,
                            $payment_method ?: null,
                            $payment_reference ?: null,
                            $technician_notes ?: null,
                            $estimated_completion ?: null
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO work_orders (client_id, device_type_id, device_category_id, device_brand, device_model, device_password, serial_number, reported_issue, 
                                              device_photo, diagnosis, solution, status, priority, estimated_cost, advance_payment, payment_method, payment_reference, final_cost, technician_notes, 
                                              received_date, estimated_completion, completed_date, delivered_date, created_at, updated_at) 
                            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), ?, NULL, NULL, NOW(), NOW())
                        ");
                        $stmt->execute([
                            $client_id,
                            $device_type_id,
                            $device_brand ?: null,
                            $device_model ?: null,
                            $device_password ?: null,
                            $serial_number ?: null,
                            $reported_issue,
                            $status,
                            $priority,
                            $estimated_cost ?: null,
                            $advance_payment ?: 0,
                            $payment_method ?: null,
                            $payment_reference ?: null,
                            $technician_notes ?: null,
                            $estimated_completion ?: null
                        ]);
                    }
                } else {
                    if ($hasClientObsCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO work_orders (tenant_id, client_id, device_type_id, device_category_id, device_brand, device_model, device_password, serial_number, reported_issue, 
                                              client_observations, device_photo, diagnosis, solution, status, priority, estimated_cost, advance_payment, payment_method, payment_reference, final_cost, technician_notes, 
                                              received_date, estimated_completion, completed_date, delivered_date, created_at, updated_at) 
                            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), ?, NULL, NULL, NOW(), NOW())
                        ");
                        $stmt->execute([
                            $tenant_id,
                            $client_id,
                            $device_type_id,
                            $device_brand ?: null,
                            $device_model ?: null,
                            $device_password ?: null,
                            $serial_number ?: null,
                            $reported_issue,
                            $client_observations !== '' ? $client_observations : null,
                            $status,
                            $priority,
                            $estimated_cost ?: null,
                            $advance_payment ?: 0,
                            $payment_method ?: null,
                            $payment_reference ?: null,
                            $technician_notes ?: null,
                            $estimated_completion ?: null
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO work_orders (tenant_id, client_id, device_type_id, device_category_id, device_brand, device_model, device_password, serial_number, reported_issue, 
                                              device_photo, diagnosis, solution, status, priority, estimated_cost, advance_payment, payment_method, payment_reference, final_cost, technician_notes, 
                                              received_date, estimated_completion, completed_date, delivered_date, created_at, updated_at) 
                            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), ?, NULL, NULL, NOW(), NOW())
                        ");
                        $stmt->execute([
                            $tenant_id,
                            $client_id,
                            $device_type_id,
                            $device_brand ?: null,
                            $device_model ?: null,
                            $device_password ?: null,
                            $serial_number ?: null,
                            $reported_issue,
                            $status,
                            $priority,
                            $estimated_cost ?: null,
                            $advance_payment ?: 0,
                            $payment_method ?: null,
                            $payment_reference ?: null,
                            $technician_notes ?: null,
                            $estimated_completion ?: null
                        ]);
                    }
                }

                $order_id = $pdo->lastInsertId();
                $order_display = (string)$order_id;
                $order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';

                try {
                    $hasQuote = ($estimated_cost !== null && $estimated_cost !== '' && is_numeric($estimated_cost) && (float)$estimated_cost > 0);
                    if ($hasQuote) {
                        $code = generateVerificationCode(6);
                        if ($perDatabase) {
                            $pdo->prepare("UPDATE work_orders SET verification_code = ? WHERE id = ?")->execute([$code, $order_id]);
                        } else {
                            $pdo->prepare("UPDATE work_orders SET verification_code = ? WHERE id = ? AND tenant_id = ?")->execute([$code, $order_id, $tenant_id]);
                        }
                    }
                } catch (Throwable $e) {}

                try {
                    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
                    $colStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'order_number'");
                    $colStmt->execute([$dbName]);
                    $hasOrderNumber = ((int)$colStmt->fetchColumn() > 0);
                    if (!$hasOrderNumber) {
                        $pdo->exec("ALTER TABLE work_orders ADD COLUMN order_number INT(11) NOT NULL DEFAULT 0 AFTER id");
                        if (!$perDatabase) {
                            $idxStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'unique_order_tenant'");
                            $idxStmt->execute([$dbName]);
                            if ((int)$idxStmt->fetchColumn() === 0) {
                                $pdo->exec("ALTER TABLE work_orders ADD UNIQUE KEY unique_order_tenant (order_number, tenant_id)");
                            }
                        }
                        $list = $perDatabase
                            ? $pdo->prepare("SELECT id FROM work_orders ORDER BY id ASC")
                            : $pdo->prepare("SELECT id FROM work_orders WHERE tenant_id = ? ORDER BY id ASC");
                        $list->execute($perDatabase ? [] : [$tenant_id]);
                        $n = 0;
                        foreach ($list->fetchAll(PDO::FETCH_COLUMN) as $wid) {
                            $n++;
                            if ($perDatabase) {
                                $pdo->prepare("UPDATE work_orders SET order_number = ? WHERE id = ?")->execute([$n, $wid]);
                            } else {
                                $pdo->prepare("UPDATE work_orders SET order_number = ? WHERE id = ? AND tenant_id = ?")->execute([$n, $wid, $tenant_id]);
                            }
                        }
                    }
                    $stmtMax = $perDatabase
                        ? $pdo->prepare("SELECT MAX(order_number) FROM work_orders")
                        : $pdo->prepare("SELECT MAX(order_number) FROM work_orders WHERE tenant_id = ?");
                    $stmtMax->execute($perDatabase ? [] : [$tenant_id]);
                    $maxDb = (int)($stmtMax->fetchColumn() ?: 0);
                    $cfgVal = (int)cfg_get('order_next_number', 0);
                    $startAt = max($maxDb, $cfgVal) + 1;
                    if ($perDatabase) {
                        $nextNum = $startAt;
                        $pdo->prepare("UPDATE work_orders SET order_number = ? WHERE id = ?")->execute([$nextNum, $order_id]);
                    } else {
                        $nextNum = getNextTenantSequence($pdo, $tenant_id, 'work_orders', $startAt);
                        $pdo->prepare("UPDATE work_orders SET order_number = ? WHERE id = ? AND tenant_id = ?")->execute([$nextNum, $order_id, $tenant_id]);
                    }
                    $order_num = isset($nextNum) && (int)$nextNum > 0 ? (int)$nextNum : (int)$order_id;
                    $order_display = $order_prefix . '-' . str_pad((string)$order_num, 4, '0', STR_PAD_LEFT);
                }
                catch (Throwable $e) {
                }

                // Registrar ingreso en caja si hay abono
                if ($advance_payment > 0) {
                    try {
                        // Verificar sesión de caja abierta
                        $hasTenantCashSessions = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_sessions') : false;
                        $sqlSess = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id DESC LIMIT 1";
                        $stmtSession = $pdo->prepare($sqlSess);
                        $stmtSession->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenant_id] : []);
                        $cash_session = $stmtSession->fetch(PDO::FETCH_ASSOC);

                        if ($cash_session) {
                            $desc = "Abono inicial Orden #$order_id ($order_display)";
                            if (!empty($payment_reference)) {
                                $desc .= " - Ref: $payment_reference";
                            }

                            $hasTenantIncome = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_income') : false;
                            $fields = [];
                            $placeholders = [];
                            $values = [];
                            if ($hasTenantIncome) {
                                $fields[] = 'tenant_id';
                                $values[] = $perDatabase ? 1 : (int)$tenant_id;
                                $placeholders[] = '?';
                            }
                            $fields[] = 'cash_session_id';
                            $values[] = (int)$cash_session['id'];
                            $placeholders[] = '?';
                            if (hasColumnCached($pdo, 'cash_income', 'income_type')) {
                                $fields[] = 'income_type';
                                $values[] = 'service';
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'concept_id')) {
                                $fields[] = 'concept_id';
                                $values[] = 1;
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'concept')) {
                                $fields[] = 'concept';
                                $values[] = $desc;
                                $placeholders[] = '?';
                            }
                            $fields[] = 'amount';
                            $values[] = $advance_payment;
                            $placeholders[] = '?';
                            if (hasColumnCached($pdo, 'cash_income', 'payment_method')) {
                                $fields[] = 'payment_method';
                                $values[] = (string)$payment_method;
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'reference_number')) {
                                $fields[] = 'reference_number';
                                $values[] = ($payment_reference ?: null);
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'description')) {
                                $fields[] = 'description';
                                $values[] = $desc;
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'notes')) {
                                $fields[] = 'notes';
                                $values[] = $desc;
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'created_by')) {
                                $fields[] = 'created_by';
                                $values[] = (int)($_SESSION['user_id'] ?? 0);
                                $placeholders[] = '?';
                            }
                            if (hasColumnCached($pdo, 'cash_income', 'created_at')) {
                                $fields[] = 'created_at';
                                $placeholders[] = 'NOW()';
                            }
                            $sql = "INSERT INTO cash_income (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                            $stmtIncome = $pdo->prepare($sql);
                            $stmtIncome->execute($values);
                        }
                    }
                    catch (Exception $e) {
                        error_log("Error registrando abono en caja: " . $e->getMessage());
                    }
                }

                // Procesar fotos del dispositivo si se proporcionaron
                $photo_filenames = [];
                if (!empty($_POST['captured_photos_data']) || !empty($_FILES['device_photo']['name'])) {
                    try {
                        $allowed_galleries = ['entry', 'diagnosis', 'delivery'];
                        $gallery = $_POST['photo_gallery'] ?? 'entry';
                        if (!in_array($gallery, $allowed_galleries, true)) {
                            $gallery = 'entry';
                        }
                        $upload_dir = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/' . $gallery . '/';
                        $maxPhotos = 10;
                        $maxBytes = 5 * 1024 * 1024;
                        $maxPixels = 25_000_000;
                        $mimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp'
                        ];
                        $finfo = new finfo(FILEINFO_MIME_TYPE);

                        // Crear directorio si no existe
                        if (!is_dir($upload_dir)) {
                            if (!@mkdir($upload_dir, 0755, true)) {
                                throw new Exception('No se pudo crear el directorio de subida');
                            }
                        }

                        // Procesar imágenes capturadas desde la cámara
                        if (!empty($_POST['captured_photos_data'])) {
                            $photos_data = json_decode($_POST['captured_photos_data'], true);
                            if (is_array($photos_data)) {
                                foreach ($photos_data as $index => $photo_data) {
                                    if (count($photo_filenames) >= $maxPhotos) {
                                        break;
                                    }
                                    $image_data = (string)($photo_data['data'] ?? '');
                                    $photo_gallery = $photo_data['gallery'] ?? ($gallery ?? 'entry');

                                    // Validar galería específica de la foto
                                    if (!in_array($photo_gallery, $allowed_galleries, true)) {
                                        $photo_gallery = 'entry';
                                    }

                                    $current_upload_dir = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/' . $photo_gallery . '/';
                                    if (!is_dir($current_upload_dir)) {
                                        if (!@mkdir($current_upload_dir, 0755, true)) {
                                            continue;
                                        }
                                    }

                                    // Validar que los datos base64 sean válidos
                                    if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#', $image_data)) {
                                        continue;
                                    }
                                    $pos = strpos($image_data, ',');
                                    if ($pos === false) {
                                        continue;
                                    }
                                    $b64 = substr($image_data, $pos + 1);
                                    $b64 = str_replace(' ', '+', $b64);
                                    $image_decoded = base64_decode($b64, true);
                                    if ($image_decoded === false) {
                                        continue;
                                    }
                                    if (strlen($image_decoded) <= 0 || strlen($image_decoded) > $maxBytes) {
                                        continue;
                                    }
                                    $info = @getimagesizefromstring($image_decoded);
                                    if (!$info || empty($info['mime'])) {
                                        continue;
                                    }
                                    $mime = (string)$info['mime'];
                                    if (!isset($mimeToExt[$mime])) {
                                        continue;
                                    }
                                    $w = (int)($info[0] ?? 0);
                                    $h = (int)($info[1] ?? 0);
                                    if ($w <= 0 || $h <= 0 || ($w * $h) > $maxPixels) {
                                        continue;
                                    }
                                    $photo_filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$mime];
                                    $photo_path = $current_upload_dir . $photo_filename;
                                    if (file_put_contents($photo_path, $image_decoded, LOCK_EX) !== false) {
                                        if (file_exists($photo_path) && filesize($photo_path) > 0) {
                                            $extLower = strtolower(pathinfo($photo_filename, PATHINFO_EXTENSION));
                                            $q = isset($_POST['upload_quality']) ? max(50, min(95, (int)$_POST['upload_quality'])) : 85;
                                            if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                                                PerformanceOptimizer::optimizeImage($photo_path, $photo_path, $q);
                                            }
                                            $photo_filenames[] = $photo_gallery . '/' . $photo_filename;
                                        }
                                    }
                                }
                            }
                        }

                        // Procesar archivos subidos
                        if (!empty($_FILES['device_photo']['name'])) {
                            $uploaded_files = $_FILES['device_photo'];

                            // Si es un solo archivo, convertirlo a array
                            if (!is_array($uploaded_files['name'])) {
                                $uploaded_files = [
                                    'name' => [$uploaded_files['name']],
                                    'type' => [$uploaded_files['type']],
                                    'tmp_name' => [$uploaded_files['tmp_name']],
                                    'error' => [$uploaded_files['error']],
                                    'size' => [$uploaded_files['size']]
                                ];
                            }

                            foreach ($uploaded_files['name'] as $index => $filename) {
                                if (count($photo_filenames) >= $maxPhotos) {
                                    break;
                                }
                                if ($uploaded_files['error'][$index] === UPLOAD_ERR_OK) {
                                    $tmp = $uploaded_files['tmp_name'][$index] ?? '';
                                    if ($tmp === '' || !is_uploaded_file($tmp)) {
                                        continue;
                                    }

                                    $size = (int)($uploaded_files['size'][$index] ?? 0);
                                    if ($size <= 0 || $size > $maxBytes) {
                                        continue;
                                    }

                                    $mime = $finfo->file($tmp) ?: '';
                                    if (!isset($mimeToExt[$mime])) {
                                        continue;
                                    }
                                    $photo_filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$mime];
                                    $photo_path = $upload_dir . $photo_filename;

                                    // Mover archivo
                                    if (move_uploaded_file($tmp, $photo_path)) {
                                        $extLower = strtolower(pathinfo($photo_filename, PATHINFO_EXTENSION));
                                        $q = isset($_POST['upload_quality']) ? max(50, min(95, (int)$_POST['upload_quality'])) : 85;
                                        if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'])) {
                                            PerformanceOptimizer::optimizeImage($photo_path, $photo_path, $q);
                                        }
                                        $photo_filenames[] = $gallery . '/' . $photo_filename;
                                    }
                                }
                            }
                        }

                        // Actualizar la orden con las rutas de las fotos
                        if (!empty($photo_filenames)) {
                            $photos_json = json_encode($photo_filenames);
                            $update_stmt = $pdo->prepare("UPDATE work_orders SET device_photo = ? WHERE id = ? AND tenant_id = ?");
                            $update_stmt->execute([$photos_json, $order_id, $tenant_id]);
                        }

                        // Migración: mover fotos antiguas en 'other/' a 'entry/' respetando tenant
                        try {
                            $tenant_base = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/';
                            $other_dir = $tenant_base . 'other/';
                            $entry_dir = $tenant_base . 'entry/';
                            if (is_dir($other_dir)) {
                                if (!is_dir($entry_dir)) {
                                    mkdir($entry_dir, 0755, true);
                                }
                                $items = @scandir($other_dir) ?: [];
                                foreach ($items as $item) {
                                    if ($item === '.' || $item === '..')
                                        continue;
                                    $src = $other_dir . $item;
                                    if (!is_file($src))
                                        continue;
                                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                                        continue;
                                    $dest_name = $item;
                                    if (file_exists($entry_dir . $dest_name)) {
                                        $base = pathinfo($item, PATHINFO_FILENAME);
                                        $dest_name = $base . '_' . uniqid() . '.' . $ext;
                                    }
                                    $dest = $entry_dir . $dest_name;
                                    if (@rename($src, $dest)) {
                                        $photo_filenames[] = 'entry/' . $dest_name;
                                    }
                                    else if (@copy($src, $dest)) {
                                        @unlink($src);
                                        $photo_filenames[] = 'entry/' . $dest_name;
                                    }
                                }
                                // Si se migró algo, actualizar JSON y limpiar directorio
                                if (!empty($photo_filenames)) {
                                    $photos_json = json_encode($photo_filenames);
                                    $update_stmt = $pdo->prepare("UPDATE work_orders SET device_photo = ? WHERE id = ? AND tenant_id = ?");
                                    $update_stmt->execute([$photos_json, $order_id, $tenant_id]);
                                }
                                @rmdir($other_dir);
                            }
                        }
                        catch (Exception $e) {
                            error_log("Migración de fotos 'other' a 'entry' falló: " . $e->getMessage());
                        }

                    }
                    catch (Exception $e) {
                        // Log error but don't fail the order creation
                        error_log("Error al procesar fotos del dispositivo: " . $e->getMessage());
                    }
                }

                // Procesar accesorios del equipo si se proporcionaron
                if (!empty($equipment_accessories_data) && is_array($equipment_accessories_data)) {
                    try {
                        $hasTenantCol = hasTenantColumnCached($pdo, 'order_equipment_accessories');
                        if ($hasTenantCol) {
                            $accessories_stmt = $pdo->prepare("
                                INSERT INTO order_equipment_accessories (tenant_id, order_id, accessory_id, is_included, condition_notes) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                        }
                        else {
                            $accessories_stmt = $pdo->prepare("
                                INSERT INTO order_equipment_accessories (order_id, accessory_id, is_included, condition_notes) 
                                VALUES (?, ?, ?, ?)
                            ");
                        }

                        foreach ($equipment_accessories_data as $accessory_id => $accessory_data) {
                            $is_included = isset($accessory_data['is_included']) ? 1 : 0;
                            if ($hasTenantCol) {
                                $accessories_stmt->execute([$tenant_id, $order_id, (int)$accessory_id, $is_included, '']);
                            }
                            else {
                                $accessories_stmt->execute([$order_id, (int)$accessory_id, $is_included, '']);
                            }
                        }
                    }
                    catch (Exception $e) {
                        // Log error but don't fail the order creation
                        error_log("Error al procesar accesorios del equipo: " . $e->getMessage());
                    }
                }

                // El historial de estados se crea automáticamente mediante trigger after_work_order_insert

                header("Location: view.php?id=$order_id&success=Orden creada exitosamente");
                exit();
            }
            catch (PDOException $e) {
                $errors[] = "Error al crear la orden: " . $e->getMessage();
            }
        }
    } // Cierre del bloque else de verificación CSRF
}
?>

<?php $page_title = 'Nueva Orden'; ?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<script>
    window.SYSTEM_CONFIG = <?php echo json_encode($system_config_js); ?>;
</script>


<style>

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 6px;
}
::-webkit-scrollbar-track {
    background: #f1f1f1;
}
::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: #bbb;
}

.photo-upload-zone {
    border: 2px dashed #dee2e6;
    border-radius: 1rem;
    transition: all 0.3s ease;
    background-color: #f8f9fa;
    min-height: 120px; /* Reducido */
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.photo-upload-zone:hover {
    border-color: var(--primary-color);
    background-color: #fff;
}
.photo-item img {
    width: 100%;
    height: 100px;
    object-fit: cover;
}
.photo-actions {
    position: absolute;
    top: 5px;
    right: 5px;
    opacity: 0;
    transition: opacity 0.2s ease;
}
.photo-card:hover .photo-actions {
    opacity: 1;
}
.form-control, .form-select, .input-group-text, .btn {
    font-size: 0.85rem;
}

@media (max-width: 991.98px) {
    input,
    select,
    textarea,
    .form-control,
    .form-select {
        font-size: 16px !important;
    }
}
.card-modern {
    border-radius: 1rem !important;
}
/* Barra de acciones inferior */
.order-footer-bar {
    left: 210px !important;
    right: 0;
    transition: left 0.3s ease;
    background: rgba(255, 255, 255, 0.7);
    -webkit-backdrop-filter: blur(14px);
    backdrop-filter: blur(14px);
}

body.sidebar-collapsed .order-footer-bar {
    left: 70px !important;
}

@media (max-width: 991.98px) {
    .order-footer-bar {
        left: 0 !important;
    }
}
.accessory-checkbox {
    width: 1.1em;
    height: 1.1em;
}
.form-check-label small {
    font-size: 0.75rem;
}

/* Estilos para botones de galería seleccionados */
.btn-check:checked + .btn-outline-primary {
    background-color: rgba(13, 110, 253, 0.1) !important;
    color: #0d6efd !important;
    border-color: #0d6efd !important;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
}
.btn-check:checked + .btn-outline-info {
    background-color: rgba(13, 202, 240, 0.1) !important;
    color: #0aa2c0 !important;
    border-color: #0dcaf0 !important;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(13, 202, 240, 0.2);
}
.btn-check:checked + .btn-outline-success {
    background-color: rgba(25, 135, 84, 0.1) !important;
    color: #198754 !important;
    border-color: #198754 !important;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(25, 135, 84, 0.2);
}
</style>

<!-- Form Wrapper Starts -->
    <div class="container-fluid p-3" style="max-width: 1400px;">
        <!-- Formulario -->
        <div class="w-100">
            <div>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php
    endforeach; ?>
                        </ul>
                    </div>
                <?php
endif; ?>

                <form method="POST" novalidate id="orderForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <!-- TARJETA MAESTRA UNIFICADA -->
                    <div class="card card-modern border-0 shadow-sm overflow-hidden">
                        <div class="card-body p-4">
                            
                            <!-- Título dentro de la tarjeta -->
                            <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3">
                                <h4 class="fw-bold text-dark mb-0">
                                    <i class="fas fa-plus-circle me-2 text-primary no-theme"></i>Nueva Orden de Servicio
                                </h4>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-light text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill no-theme">
                                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('d/m/Y'); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- SECCIÓN 1: CLIENTE -->
                            <div class="d-flex align-items-center justify-content-between mb-4">
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-user text-primary me-2"></i>Información del Cliente</h5>
                                <button type="button" class="btn btn-sm btn-outline-primary no-theme rounded-pill" data-bs-toggle="modal" data-bs-target="#newClientModal">
                                    <i class="fas fa-plus me-1"></i>Nuevo Cliente
                                </button>
                            </div>
                            
                            <div class="row g-4 mb-4">
                                <div class="col-md-5">
                                    <label for="client_search" class="form-label text-muted fw-bold small text-uppercase ms-2">Buscador de Clientes</label>
                                    <div class="position-relative">
                                        <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                                            <span class="input-group-text bg-light border-0 text-muted px-3"><i class="fas fa-search"></i></span>
                                            <input type="text" 
                                                   class="form-control border-0 bg-light py-2" 
                                                   id="client_search" 
                                                   placeholder="Nombre, cédula o NIT..." 
                                                   autocomplete="off"
                                                   required>
                                        </div>
                                        <input type="hidden" id="client_id" name="client_id" value="<?php echo $preselected_client_id; ?>" required>
                                        <div id="client_dropdown" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2" style="max-height: 250px; overflow-y: auto;"></div>
                                    </div>
                                </div>

                                <div class="col-md-7">
                                    <div id="client-info-section" class="d-none h-100">
                                        <div class="card border border-primary border-opacity-50 rounded-4 shadow-sm bg-white overflow-hidden no-theme h-100">
                                            <div class="card-body p-0 d-flex align-items-stretch">
                                                <div class="d-flex align-items-center justify-content-center px-3" style="background-color: #f0f7ff !important;">
                                                    <i class="fas fa-user-check text-primary fs-4 no-theme"></i>
                                                </div>
                                                <div class="flex-grow-1 p-3">
                                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                                        <h6 class="fw-bold text-dark mb-0" id="selected-client-name"></h6>
                                                        <span class="badge fw-bold text-uppercase no-theme" 
                                                              style="font-size: 0.65rem; background-color: #e7f1ff !important; color: #0d6efd !important;" 
                                                              id="selected-client-type"></span>
                                                    </div>
                                                    <div class="d-flex gap-3 align-items-center small text-muted">
                                                        <span><i class="fas fa-id-card me-1 opacity-50"></i><span id="selected-client-id-number"></span></span>
                                                        <span class="border-start ps-3"><i class="fas fa-phone-alt me-1 opacity-50"></i><span id="selected-client-phone"></span></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border-top my-4"></div>

                            <!-- SECCIÓN 2: DISPOSITIVO Y ACCESORIOS -->
                            <div class="row g-4">
                                <div class="col-lg-8 border-end pe-lg-4">
                                    
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label for="serial_number" class="form-label text-muted fw-bold small text-uppercase ms-2">N° de Serie / IMEI / TAG <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control bg-light border-0 rounded-pill px-3" id="serial_number" name="serial_number" required placeholder="S/N, IMEI o Service Tag" value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>">
                                            <div id="serial-lookup-hint" class="small text-muted mt-2 ms-2 d-none"></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="device_type_search" class="form-label text-muted fw-bold small text-uppercase ms-2">Tipo de Equipo <span class="text-danger">*</span></label>
                                            <div class="position-relative">
                                                <input type="text" class="form-control bg-light border-0 rounded-pill px-3 pe-5" id="device_type_search" placeholder="Celular, Laptop..." required autocomplete="off">
                                                <div class="position-absolute end-0 top-50 translate-middle-y me-3 text-muted" style="cursor: pointer; pointer-events: none;">
                                                    <i class="fas fa-chevron-down small"></i>
                                                </div>
                                                <input type="hidden" id="device_type_id" name="device_type_id" required>
                                                <div id="device_type_dropdown" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label for="device_brand_search" class="form-label text-muted fw-bold small text-uppercase ms-2">Marca</label>
                                            <div class="position-relative">
                                                <input type="text" class="form-control bg-light border-0 rounded-pill px-3" id="device_brand_search" placeholder="Marca" autocomplete="off">
                                                <input type="hidden" id="device_brand" name="device_brand">
                                                <div id="brand_dropdown" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="device_model_search" class="form-label text-muted fw-bold small text-uppercase ms-2">Modelo</label>
                                            <div class="position-relative">
                                                <input type="text" class="form-control bg-light border-0 rounded-pill px-3" id="device_model_search" placeholder="Modelo" autocomplete="off">
                                                <input type="hidden" id="device_model" name="device_model">
                                                <div id="model_dropdown" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="device_password" class="form-label text-muted fw-bold small text-uppercase ms-2">Clave de Acceso</label>
                                            <input type="text" class="form-control bg-light border-0 rounded-pill px-3" id="device_password" name="device_password" placeholder="PIN, Patrón o Contraseña">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="problem_description" class="form-label text-muted fw-bold small text-uppercase ms-2">Descripción del Problema <span class="text-danger">*</span></label>
                                        <textarea class="form-control bg-light border-0 rounded-4 p-3" id="problem_description" name="problem_description" rows="3" required placeholder="¿Qué falla presenta el equipo?"><?php echo htmlspecialchars($_POST['problem_description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="client_observations" class="form-label text-muted fw-bold small text-uppercase ms-2">Observaciones</label>
                                        <textarea class="form-control bg-light border-0 rounded-4 p-3" id="client_observations" name="client_observations" rows="2" placeholder="Observaciones para el cliente."><?php echo htmlspecialchars($_POST['client_observations'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="mb-0">
                                        <label for="technician_notes" class="form-label text-muted fw-bold small text-uppercase ms-2">Notas Internas</label>
                                        <textarea class="form-control bg-light border-0 rounded-4 p-3" id="technician_notes" name="technician_notes" rows="2" placeholder="Observaciones para el técnico..."><?php echo htmlspecialchars($_POST['technician_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center justify-content-between mb-4">
                                        <h5 class="fw-bold text-dark mb-0"><i class="fas fa-box-open text-primary me-2"></i>Accesorios</h5>
                                        <button type="button" class="btn btn-sm btn-dark no-theme rounded-pill" data-bs-toggle="modal" data-bs-target="#newAccessoryModal">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="bg-light rounded-4 p-3 border border-light" style="max-height: 400px; overflow-y: auto;">
                                        <div class="row g-2" id="checklist-container-inner">
                                            <?php if (!empty($equipment_accessories)): ?>
                                                <?php foreach ($equipment_accessories as $accessory): ?>
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input accessory-checkbox" type="checkbox" name="accessories[<?php echo $accessory['id']; ?>][is_included]" value="1" id="acc_<?php echo $accessory['id']; ?>">
                                                            <label class="form-check-label small text-truncate w-100" for="acc_<?php echo $accessory['id']; ?>" title="<?php echo htmlspecialchars($accessory['name']); ?>">
                                                                <?php echo htmlspecialchars($accessory['name']); ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="text-center py-4 text-muted small">Sin accesorios configurados</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border-top my-4"></div>

                            <!-- SECCIÓN 3: GESTIÓN Y COSTOS -->
                            <div class="row g-4">
                                <div class="col-lg-6 border-end pe-lg-4">
                                    <h5 class="fw-bold text-dark mb-4"><i class="fas fa-cog text-primary me-2"></i>Información de la Orden</h5>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="status" class="form-label text-muted fw-bold small text-uppercase ms-2">Estado Inicial</label>
                                            <select class="form-select bg-light border-0 rounded-pill px-3" id="status" name="status">
                                                <?php foreach ($statuses as $status): ?>
                                                    <option value="<?php echo htmlspecialchars($status['slug']); ?>" <?php echo ($default_status === $status['slug']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($status['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="priority" class="form-label text-muted fw-bold small text-uppercase ms-2">Prioridad</label>
                                            <select class="form-select bg-light border-0 rounded-pill px-3" id="priority" name="priority">
                                                <option value="low">Baja</option>
                                                <option value="medium" selected>Media</option>
                                                <option value="high">Alta</option>
                                                <option value="urgent">Urgente</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="estimated_cost" class="form-label text-muted fw-bold small text-uppercase ms-2">Presupuesto</label>
                                            <div class="input-group rounded-pill overflow-hidden border border-light">
                                                <span class="input-group-text bg-light border-0 text-muted px-3"><?php echo $currency_config['symbol']; ?></span>
                                                <input type="text" class="form-control border-0 bg-light money-input" id="estimated_cost" name="estimated_cost" placeholder="0" oninput="formatCurrencyInput(this)">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="estimated_completion" class="form-label text-muted fw-bold small text-uppercase ms-2">Entrega Estimada</label>
                                            <input type="date" class="form-control bg-light border-0 rounded-pill px-3" id="estimated_completion" name="estimated_completion">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <h5 class="fw-bold text-dark mb-4"><i class="fas fa-wallet text-primary me-2"></i>Abono Inicial</h5>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="advance_payment" class="form-label text-muted fw-bold small text-uppercase ms-2">Monto del Abono</label>
                                            <div class="input-group rounded-pill overflow-hidden border border-light">
                                                <span class="input-group-text bg-light border-0 text-muted px-3"><?php echo $currency_config['symbol']; ?></span>
                                                <input type="text" class="form-control border-0 bg-light money-input" id="advance_payment" name="advance_payment" placeholder="0" oninput="formatCurrencyInput(this)">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="payment_method" class="form-label text-muted fw-bold small text-uppercase ms-2">Método de Pago</label>
                                            <select class="form-select bg-light border-0 rounded-pill px-3" id="payment_method" name="payment_method">
                                                <option value="">Seleccionar...</option>
                                                <?php foreach ($payment_methods as $pm): ?>
                                                    <option value="<?php echo htmlspecialchars($pm['name']); ?>" <?php echo ($pm['name'] === 'Efectivo') ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($pm['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label for="payment_reference" class="form-label text-muted fw-bold small text-uppercase ms-2">N° de Referencia / Comprobante</label>
                                            <input type="text" class="form-control bg-light border-0 rounded-pill px-3" id="payment_reference" name="payment_reference" placeholder="Ej: 123456789">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border-top my-4"></div>

                            <!-- SECCIÓN 4: FOTOS -->
                            <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-camera text-primary me-2"></i>Registro Fotográfico</h5>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_entry" value="entry" autocomplete="off" checked>
                                        <label class="btn btn-outline-primary px-3 rounded-pill me-2 d-flex align-items-center gap-2" for="gal_entry">
                                            <i class="fas fa-sign-in-alt"></i> Entrada
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_diag" value="diagnosis" autocomplete="off">
                                        <label class="btn btn-outline-info px-3 rounded-pill me-2 d-flex align-items-center gap-2" for="gal_diag">
                                            <i class="fas fa-stethoscope"></i> Diagnóstico
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_delivery" value="delivery" autocomplete="off">
                                        <label class="btn btn-outline-success px-3 rounded-pill d-flex align-items-center gap-2" for="gal_delivery">
                                            <i class="fas fa-check-circle"></i> Entrega
                                        </label>
                                    </div>

                                    <div class="vr mx-1 d-none d-md-block"></div>

                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" id="start-camera">
                                        <i class="fas fa-camera me-1"></i>Cámara
                                    </button>
                                    <label class="btn btn-sm btn-outline-dark rounded-pill px-3 mb-0 cursor-pointer" for="device_photo">
                                        <i class="fas fa-upload me-1"></i>Subir
                                    </label>
                                    <input type="file" class="d-none" id="device_photo" name="device_photo[]" accept="image/*" multiple>

                                    <select class="form-select form-select-sm rounded-pill" id="upload-quality" name="upload_quality" style="width:auto;">
                                        <option value="85" selected>Calidad: Estándar</option>
                                        <option value="95">Alta</option>
                                        <option value="75">Baja</option>
                                    </select>
                                </div>
                            </div>

                            <div id="camera-container" class="mb-3 d-none">
                                <div class="position-relative bg-dark rounded-4 overflow-hidden shadow-lg" style="max-height: 600px;">
                                    <!-- Controles Superiores de Cámara -->
                                    <div class="position-absolute top-0 start-0 w-100 p-3 d-flex justify-content-between align-items-center" style="z-index: 10; background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);">
                                        <div class="d-flex gap-2">
                                            <select class="form-select form-select-sm rounded-pill bg-dark text-white border-secondary opacity-75" id="camera-resolution" style="width: auto; font-size: 0.75rem;">
                                                <option value="640x480">SD (480p)</option>
                                                <option value="1280x720">HD (720p)</option>
                                                <option value="1920x1080" selected>FullHD (1080p)</option>
                                                <option value="3840x2160">4K (UltraHD)</option>
                                            </select>
                                            <select class="form-select form-select-sm rounded-pill bg-dark text-white border-secondary opacity-75" id="camera-quality" style="width: auto; font-size: 0.75rem;">
                                                <option value="0.7">Calidad: Baja</option>
                                                <option value="0.85" selected>Calidad: Alta</option>
                                                <option value="0.95">Calidad: Full</option>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-light rounded-pill border-0" id="close-camera-top">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <video id="camera-feed" autoplay playsinline class="w-100" style="object-fit: contain; height: 500px;"></video>
                                    
                                    <div class="position-absolute bottom-0 start-0 w-100 p-3" style="background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);">
                                        <div class="d-flex justify-content-center align-items-center gap-4">
                                            <button type="button" class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center" id="switch-camera" title="Cambiar Cámara" style="width: 45px; height: 45px;">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <button type="button" class="btn btn-light rounded-circle d-flex align-items-center justify-content-center shadow-lg" id="capture-btn" style="width: 75px; height: 75px; padding: 0;">
                                                <div class="rounded-circle border border-dark border-2" style="width: 60px; height: 60px;"></div>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger rounded-circle d-flex align-items-center justify-content-center" id="close-camera" title="Cerrar" style="width: 45px; height: 45px;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="photo-drop-zone" class="photo-upload-zone p-4 text-center cursor-pointer border-dashed">
                                <div id="drop-zone-prompt">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-primary opacity-25 mb-3"></i>
                                    <h6 class="fw-bold text-dark mb-1">Arrastra fotos o usa los botones de arriba</h6>
                                    <p class="text-muted small mb-0">Se guardarán en la galería de "Entrada"</p>
                                </div>
                                <div id="photos-preview" class="row g-3 text-start w-100 m-0 d-none"></div>
                            </div>
                            <input type="hidden" id="captured_photos_data" name="captured_photos_data">

                            <div class="border-top my-4 d-lg-none"></div>
                            <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap d-lg-none">
                                <a href="index.php" class="btn btn-light border-0 rounded-pill px-4 fw-bold text-muted hover-bg-danger hover-text-white transition-all">
                                    <i class="fas fa-times me-2"></i>Descartar
                                </a>
                                <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                                    <i class="fas fa-save me-2"></i>Crear Orden de Servicio
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- FOOTER FIJO -->
                    <div class="order-footer-spacer d-none d-lg-block" style="height: 100px;"></div>
                    <div class="fixed-bottom border-top py-3 order-footer-bar shadow-lg d-none d-lg-block">
                        <div class="container-fluid px-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-4">
                                    <div class="d-flex flex-column border-end pe-4">
                                        <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Abono Recibido</span>
                                        <span class="h4 mb-0 fw-bold text-success" id="footer_advance_display"><?php echo $currency_config['symbol']; ?> 0</span>
                                    </div>
                                    <div class="d-flex flex-column">
                                        <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Costo Estimado</span>
                                        <span class="h5 mb-0 fw-bold text-dark" id="footer_cost_display"><?php echo $currency_config['symbol']; ?> 0</span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="index.php" class="btn btn-light border-0 rounded-pill px-4 fw-bold text-muted hover-bg-danger hover-text-white transition-all">
                                        <i class="fas fa-times me-2"></i>Descartar
                                    </a>
                                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                                        <i class="fas fa-save me-2"></i>Crear Orden de Servicio
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
<!-- End Form Wrapper -->

<!-- Modal para Nuevo Cliente -->
<?php include __DIR__ . '/../clients/modal_new_client.php'; ?>

<!-- Modal Nuevo Accesorio -->
<div class="modal fade" id="newAccessoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Nuevo Accesorio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <form id="newAccessoryForm">
                    <div class="mb-3">
                        <label for="new_accessory_name" class="form-label small text-muted text-uppercase fw-bold">Nombre del Accesorio</label>
                        <input type="text" class="form-control rounded-pill" id="new_accessory_name" required placeholder="Ej. Cargador, Funda...">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary rounded-pill">
                            <i class="fas fa-plus me-2"></i>Agregar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const newAccessoryForm = document.getElementById('newAccessoryForm');
    const newAccessoryModal = new bootstrap.Modal(document.getElementById('newAccessoryModal'));
    
    newAccessoryForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const nameInput = document.getElementById('new_accessory_name');
        const name = nameInput.value.trim();
        
        if(!name) return;
        
        const btn = this.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...';
        
        const formData = new FormData();
        formData.append('name', name);
        const csrfTokenInput = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrf_token');
        if (csrfTokenInput) {
            formData.append('csrf_token', csrfTokenInput.value);
        }
        
        fetch('ajax_add_accessory.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en el servidor');
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Respuesta no válida del servidor:', text);
                    throw new Error('La respuesta del servidor no es un JSON válido');
                }
            });
        })
        .then(data => {
            if(data.success) {
                const container = document.querySelector('#checklist-container-inner');
                // Quitar mensaje de vacío si existe
                const emptyMsg = container.querySelector('.text-center.py-4.text-muted.small');
                if(emptyMsg) {
                    emptyMsg.remove();
                }
                
                const col = document.createElement('div');
                col.className = 'col-6';
                col.innerHTML = `
                    <div class="form-check">
                        <input class="form-check-input accessory-checkbox" type="checkbox" 
                               name="accessories[${data.accessory.id}][is_included]" 
                               value="1" 
                               id="acc_${data.accessory.id}"
                               checked>
                        <label class="form-check-label small text-truncate w-100" for="acc_${data.accessory.id}" title="${data.accessory.name}">
                            ${data.accessory.name}
                        </label>
                    </div>
                `;
                container.appendChild(col);
                
                // Limpiar y cerrar modal
                nameInput.value = '';
                newAccessoryModal.hide();
                
                // Forzar limpieza de backdrop
                setTimeout(() => {
                    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }, 150);

                if (typeof showToast === 'function') {
                    showToast('Accesorio agregado', 'success');
                }
            } else {
                if (typeof showError === 'function') showError(data.message || 'Error al agregar accesorio');
                else alert(data.message || 'Error al agregar accesorio');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof showError === 'function') showError('Error al guardar: ' + error.message);
            else alert('Error al guardar: ' + error.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const estimatedCostInput = document.getElementById('estimated_cost');
    const advancePaymentInput = document.getElementById('advance_payment');
    const footerCostDisplay = document.getElementById('footer_cost_display');
    const footerAdvanceDisplay = document.getElementById('footer_advance_display');
    const currencySymbol = '<?php echo $currency_config['symbol']; ?>';

    function updateFooter() {
        if (footerCostDisplay) {
            footerCostDisplay.textContent = currencySymbol + ' ' + (estimatedCostInput.value || '0');
        }
        if (footerAdvanceDisplay) {
            footerAdvanceDisplay.textContent = currencySymbol + ' ' + (advancePaymentInput.value || '0');
        }
    }

    if (estimatedCostInput) {
        estimatedCostInput.addEventListener('input', updateFooter);
    }
    if (advancePaymentInput) {
        advancePaymentInput.addEventListener('input', updateFooter);
    }

    // Inicializar footer
    updateFooter();
});
</script>

<?php include '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('orderForm');
    if (!form) return;
    
    // Función isValidEmail ahora está en utils.js como validateEmail
    
    // Función para formatear teléfono
    function formatPhone(input) {
        // Usar función utilitaria común
        input.value = formatPhoneInput ? formatPhoneInput(input) : input.value.replace(/[^0-9+\-\s()]/g, '');
    }
    
    // Función para validar campo individual
    function validateField(field) {
        const value = field.value.trim();
        let isValid = true;
        
        // Excluir el campo de búsqueda de clientes y tipos de dispositivo de la validación visual
        if (field.id === 'client_search' || field.id === 'device_type_search') {
            return true;
        }
        
        // Validar campos requeridos
        if (field.hasAttribute('required') && !value) {
            isValid = false;
        }
        
        // Validar email específicamente
        if (field.type === 'email' && value && !isValidEmail(value)) {
            isValid = false;
        }
        
        // Validar números
        if (field.type === 'number' && value && (isNaN(value) || parseFloat(value) < 0)) {
            isValid = false;
        }
        
        // Aplicar clases de validación
        field.classList.remove('is-invalid', 'is-valid');
        if (value) { // Solo aplicar validación si hay contenido
            if (isValid) {
                // No mostrar validación positiva visualmente (el chulito)
                // field.classList.add('is-valid');
            } else {
                field.classList.add('is-invalid');
            }
        }
        
        return isValid;
    }
    
    // Configurar validación en tiempo real
    const allFields = form.querySelectorAll('input, select, textarea');
    
    allFields.forEach(field => {
        // Formateo especial para campos de teléfono
        if (field.type === 'tel' || field.name.includes('phone')) {
            field.addEventListener('input', function() {
                formatPhone(this);
                validateField(this);
            });
        } else {
            // Validación en tiempo real para otros campos
            field.addEventListener('input', function() {
                if (this.classList.contains('is-invalid')) {
                    validateField(this);
                }
            });
        }

        // Selector de calidad de subida (dropdown)
        (function() {
            const menu = document.getElementById('uploadQualityMenu');
            const btn = document.getElementById('uploadQualityDropdown');
            const hidden = document.getElementById('upload-quality');
            if (menu && btn && hidden) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const open = !menu.classList.contains('show');
                    if (open) {
                        menu.classList.add('show');
                        menu.style.display = 'block';
                        btn.setAttribute('aria-expanded', 'true');
                    } else {
                        menu.classList.remove('show');
                        menu.style.display = 'none';
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
                document.addEventListener('click', function(e) {
                    if (!btn.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.remove('show');
                        menu.style.display = 'none';
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
                menu.querySelectorAll('[data-q]').forEach(item => {
                    item.addEventListener('click', function(e) {
                        e.preventDefault();
                        const val = parseInt(this.dataset.q, 10);
                        hidden.value = isNaN(val) ? 85 : Math.max(50, Math.min(95, val));
                        const text = this.textContent.trim().split('(')[0].trim();
                        btn.textContent = 'Calidad: ' + text;
                        menu.classList.remove('show');
                        menu.style.display = 'none';
                        btn.setAttribute('aria-expanded', 'false');
                    });
                });
            }
        })();
        
        // Validación al perder el foco
        field.addEventListener('blur', function() {
            validateField(this);
        });
    });
    
    // Validación del formulario antes del envío
    form.addEventListener('submit', function(e) {
        let isFormValid = true;
        const requiredFields = form.querySelectorAll('[required]');
        
        requiredFields.forEach(field => {
            if (!validateField(field)) {
                isFormValid = false;
            }
        });
        
        if (!isFormValid) {
            e.preventDefault();
            // Scroll al primer campo inválido
            const firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
            
            // Mostrar alerta
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-3';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>
                Por favor, corrige los errores en el formulario antes de continuar.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const existingAlert = form.querySelector('.alert-danger');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            form.insertBefore(alertDiv, form.firstChild);
        }
    });
    
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });
    
    // Mejorar responsive en móviles
    function adjustForMobile() {
        if (window.innerWidth < 768) {
            // Hacer que los botones ocupen el ancho completo en móvil
            const buttons = form.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.classList.add('w-100');
            });
        } else {
            const buttons = form.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.classList.remove('w-100');
            });
        }
    }
    
    // Ajustar al cargar y al redimensionar
    adjustForMobile();
    window.addEventListener('resize', adjustForMobile);
});

// Función para alternar campos del modal de cliente
function toggleModalClientFields() {
    const clientType = document.querySelector('input[name="modal_client_type"]:checked').value;
    const individualFields = document.getElementById('modal-individual-fields');
    const companyFields = document.getElementById('modal-company-fields');
    
    if (clientType === 'individual') {
        individualFields.style.display = 'block';
        companyFields.style.display = 'none';
        // Limpiar campos de empresa
        document.getElementById('modal_nit_ruc').value = '';
        document.getElementById('modal_company_name').value = '';
        document.getElementById('modal_legal_representative').value = '';
    } else {
        individualFields.style.display = 'none';
        companyFields.style.display = 'block';
        // Limpiar campos de persona natural
        document.getElementById('modal_name').value = '';
        document.getElementById('modal_identification_number').value = '';
    }
}

// Funcionalidad de búsqueda de clientes
document.addEventListener('DOMContentLoaded', function() {
    const clientSearchInput = document.getElementById('client_search');
    const clientIdInput = document.getElementById('client_id');
    const clientDropdown = document.getElementById('client_dropdown');
    let searchTimeout;
    
    // Función para buscar clientes
    function searchClients(searchTerm) {
        console.log('🔍 Iniciando búsqueda con término:', searchTerm);
        
        if (searchTerm.length < 2) {
            console.log('❌ Término muy corto, ocultando dropdown');
            clientDropdown.style.display = 'none';
            return;
        }
        
        console.log('📡 Enviando solicitud AJAX...');
        const formData = new FormData();
        formData.append('search', searchTerm);
        
        fetch('../clients/search_ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('✅ Response status:', response.status);
            console.log('📄 Response headers:', response.headers);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            return response.text(); // Primero obtener como texto
        })
        .then(text => {
            console.log('📥 Raw response text:', text);
            
            try {
                const data = JSON.parse(text);
                console.log('✅ Parsed JSON data:', data);
                
                if (data.clients && data.clients.length > 0) {
                    console.log('✨ Mostrando', data.clients.length, 'clientes encontrados');
                    displaySearchResults(data.clients);
                } else {
                    console.log('❌ No se encontraron clientes');
                    // Mostrar mensaje de no resultados
                    clientDropdown.innerHTML = '<div class="dropdown-item-text text-muted text-center" style="padding: 20px; font-style: italic;"><i class="fas fa-search me-2"></i>No se encontraron clientes que coincidan con la búsqueda</div>';
                    clientDropdown.style.display = 'block';
                }
            } catch (parseError) {
                console.error('❌ Error parsing JSON:', parseError);
                console.error('❌ Raw text that failed to parse:', text);
                clientDropdown.innerHTML = '<div class="dropdown-item-text text-danger text-center" style="padding: 20px;"><i class="fas fa-exclamation-triangle me-2"></i>Error en la respuesta del servidor</div>';
                clientDropdown.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('❌ Error en búsqueda:', error);
            clientDropdown.innerHTML = '<div class="dropdown-item-text text-danger text-center" style="padding: 20px;"><i class="fas fa-exclamation-triangle me-2"></i>Error de conexión</div>';
            clientDropdown.style.display = 'block';
        });
    }
    
    // Función para mostrar resultados de búsqueda
    function displaySearchResults(clients) {
        clientDropdown.innerHTML = '';
        
        if (clients.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'dropdown-item-text text-muted text-center';
            noResults.style.cssText = 'padding: 20px; font-style: italic;';
            noResults.innerHTML = '<i class="fas fa-search me-2"></i>No se encontraron clientes que coincidan con la búsqueda';
            clientDropdown.appendChild(noResults);
        } else {
            clients.forEach(client => {
                const item = document.createElement('a');
                item.className = 'dropdown-item';
                item.href = '#';
                item.style.cssText = 'padding: 12px 16px; border-bottom: 1px solid #f8f9fa; cursor: pointer; transition: background-color 0.15s ease-in-out; white-space: normal;';
                item.innerHTML = `
                    <div>
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong class="text-dark">${client.id_number || 'Sin identificación'}</strong>
                                <br>
                                <small class="text-muted">
                                    <i class="fas fa-user me-1"></i>${client.name} &bull; <i class="fas fa-phone me-1"></i>
                                    <br>
                                    ${client.phone || ''}
                                </small>
                            </div>
                            <small class="badge bg-secondary text-uppercase">${client.client_type === 'company' ? 'Empresa' : 'Persona Natural'}</small>
                        </div>
                    </div>
                `;
                
                // Efectos hover
                item.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = 'white';
                });
                
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    selectClient(client);
                });
                
                clientDropdown.appendChild(item);
            });
        }
        
        clientDropdown.style.display = 'block';
    }
    
    // Función para seleccionar un cliente
    function selectClient(client) {
        clientSearchInput.value = client.name;
        clientIdInput.value = client.id;
        clientDropdown.style.display = 'none';
        
        // Remover cualquier error visual
        clientSearchInput.classList.remove('is-invalid');
        const errorDiv = document.getElementById('client-error');
        if (errorDiv) {
            errorDiv.remove();
        }
        
        // Mostrar información del cliente seleccionado
        showClientInfo(client);
    }
    
    // Función para mostrar información del cliente
    function showClientInfo(client) {
        const clientInfoSection = document.getElementById('client-info-section');
        const clientName = document.getElementById('selected-client-name');
        const clientPhone = document.getElementById('selected-client-phone');
        const clientIdNumber = document.getElementById('selected-client-id-number');
        const clientType = document.getElementById('selected-client-type');
        
        // Llenar los campos con la información del cliente
        clientName.textContent = client.name || 'No especificado';
        clientPhone.textContent = client.phone || 'No especificado';
        clientIdNumber.textContent = client.id_number || 'No especificado';
        clientType.textContent = client.client_type === 'company' ? 'Empresa' : 'Persona Natural';
        
        // Mostrar la sección
        clientInfoSection.classList.remove('d-none');
    }
    
    // Función para ocultar información del cliente
    function hideClientInfo() {
        const clientInfoSection = document.getElementById('client-info-section');
        clientInfoSection.classList.add('d-none');
    }
    
    // Event listeners
    clientSearchInput.addEventListener('input', function() {
        const searchTerm = this.value.trim();
        
        // Limpiar el ID si el campo está vacío
        if (searchTerm === '') {
            clientIdInput.value = '';
            clientDropdown.style.display = 'none';
            hideClientInfo();
            return;
        }
        
        // Debounce para evitar demasiadas peticiones
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            searchClients(searchTerm);
        }, 300);
    });
    
    // Evitar que Enter envíe el formulario desde el buscador
    clientSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });
    
    // Ocultar dropdown al hacer clic fuera
    document.addEventListener('click', function(e) {
        if (!clientSearchInput.contains(e.target) && !clientDropdown.contains(e.target)) {
            clientDropdown.style.display = 'none';
        }
    });
    
    // Validación al enviar el formulario
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', function(e) {
            if (!clientIdInput.value) {
                e.preventDefault();
                
                // Agregar clase de error visual
                clientSearchInput.classList.add('is-invalid');
                
                // Mostrar mensaje de error específico
                let errorDiv = document.getElementById('client-error');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.id = 'client-error';
                    errorDiv.className = 'invalid-feedback';
                    clientSearchInput.parentNode.appendChild(errorDiv);
                }
                errorDiv.textContent = 'Debe seleccionar un cliente de la lista de búsqueda.';
                
                if (typeof showError === 'function') showError('Por favor, selecciona un cliente válido de la lista de búsqueda.');
                clientSearchInput.focus();
                return false;
            } else {
                // Remover clase de error si está presente
                clientSearchInput.classList.remove('is-invalid');
                const errorDiv = document.getElementById('client-error');
                if (errorDiv) {
                    errorDiv.remove();
                }
            }
        });
    }
    
    // Preseleccionar cliente si se pasa client_id en la URL
    <?php if ($preselected_client): ?>
    const preselectedClient = {
        id: <?php echo $preselected_client['id']; ?>,
        name: '<?php echo addslashes($preselected_client['client_type'] === 'company' ? $preselected_client['company_name'] : $preselected_client['first_name']); ?>',
        phone: '<?php echo addslashes($preselected_client['phone'] ?? ''); ?>',
        id_number: '<?php echo addslashes($preselected_client['id_number'] ?? ''); ?>',
        type: '<?php echo $preselected_client['client_type']; ?>'
    };
    
    // Establecer el cliente preseleccionado
    document.getElementById('client_id').value = preselectedClient.id;
    document.getElementById('client_search').value = preselectedClient.name;
    
    // Mostrar información del cliente
    showClientInfo(preselectedClient);
    <?php
endif; ?>

}); // Cierre del primer DOMContentLoaded

// Función saveNewClient() se encuentra en modal-handlers.js

// ===== FUNCIONES DEL CHECKLIST DE ACCESORIOS DEL EQUIPO =====
    
    // Las funciones JS han sido reemplazadas por renderizado PHP directo para mejorar la fiabilidad.
    


    
    // Establecer valor inicial del tipo de dispositivo si hay un valor POST
    document.addEventListener('DOMContentLoaded', function() {
        const deviceTypeId = document.getElementById('device_type_id');
        const deviceTypeSearch = document.getElementById('device_type_search');
        
        if (deviceTypeId && deviceTypeId.value && deviceTypeSearch) {
            // Si hay un ID de tipo de dispositivo, obtener su nombre
            const formData = new FormData();
            formData.append('action', 'get_device_type_name');
            formData.append('id', deviceTypeId.value);
            
            fetch('../devices/device_autocomplete_ajax.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData,
                credentials: 'same-origin'
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    deviceTypeSearch.value = data.name;
                }
            })
            .catch(error => {
                console.error('Error al obtener nombre del tipo de dispositivo:', error);
            });
        }
         });
     
 </script>

 <!-- JavaScript para manejo de cámara y múltiples fotos -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Device Autocomplete
    if (typeof DeviceAutocomplete !== 'undefined') {
        const deviceAutocomplete = new DeviceAutocomplete();
    }

    (function(){
        const serialInput = document.getElementById('serial_number');
        const hint = document.getElementById('serial-lookup-hint');
        const typeSearch = document.getElementById('device_type_search');
        const typeId = document.getElementById('device_type_id');
        const brandSearch = document.getElementById('device_brand_search');
        const brandHidden = document.getElementById('device_brand');
        const modelSearch = document.getElementById('device_model_search');
        const modelHidden = document.getElementById('device_model');

        if (!serialInput || !hint) return;

        function norm(v) {
            return String(v || '').trim();
        }

        function setHint(html) {
            if (!html) {
                hint.classList.add('d-none');
                hint.innerHTML = '';
                return;
            }
            hint.innerHTML = html;
            hint.classList.remove('d-none');
        }

        function applyIfEmptyOrAuto(el, value, flagKey) {
            if (!el) return;
            const v = norm(value);
            if (!v) return;
            const key = flagKey || 'autofill';
            const allow = !norm(el.value) || el.dataset[key] === '1';
            if (!allow) return;
            el.value = v;
            el.dataset[key] = '1';
            try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
            try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        }

        function clearAutoFlag(el, flagKey) {
            if (!el) return;
            const key = flagKey || 'autofill';
            el.addEventListener('input', function() { delete el.dataset[key]; }, { passive: true });
        }

        clearAutoFlag(typeSearch, 'autofill');
        clearAutoFlag(brandSearch, 'autofill');
        clearAutoFlag(modelSearch, 'autofill');

        let timer = null;
        let inflight = 0;

        function lookup() {
            const serial = norm(serialInput.value);
            const serialNorm = serial.toUpperCase().replace(/[\s\-_]/g, '');
            if (serialNorm.length < 4) {
                setHint('');
                return;
            }
            const my = ++inflight;

            const doRequest = function() {
                if (typeof window.fetchJson === 'function') {
                    return window.fetchJson('ajax_serial_lookup.php', { method: 'POST', body: { serial_number: serial } });
                }
                const fd = new FormData();
                fd.append('serial_number', serial);
                const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                if (csrf) fd.append('csrf_token', csrf);
                return fetch('ajax_serial_lookup.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd, credentials: 'same-origin' })
                    .then(function(r){ return (typeof window.parseJsonResponse === 'function') ? window.parseJsonResponse(r) : r.json(); });
            };

            doRequest().then(function(resp){
                if (my !== inflight) return;
                if (!resp || resp.success !== true) {
                    setHint('');
                    return;
                }
                if (!resp.found) {
                    setHint('');
                    return;
                }
                const d = resp.data || {};
                const orderId = parseInt(d.order_id || 0, 10);
                const typeName = norm(d.device_type_name);
                const typeVal = parseInt(d.device_type_id || 0, 10);
                const brandVal = norm(d.device_brand);
                const modelVal = norm(d.device_model);

                if (typeVal > 0 && typeId) {
                    applyIfEmptyOrAuto(typeId, String(typeVal), 'autofill');
                    applyIfEmptyOrAuto(typeSearch, typeName || typeSearch.value, 'autofill');
                }
                applyIfEmptyOrAuto(brandHidden, brandVal, 'autofill');
                applyIfEmptyOrAuto(brandSearch, brandVal || brandSearch.value, 'autofill');
                applyIfEmptyOrAuto(modelHidden, modelVal, 'autofill');
                applyIfEmptyOrAuto(modelSearch, modelVal || modelSearch.value, 'autofill');

                const parts = [];
                parts.push('Equipo ya registrado');
                if (orderId > 0) parts.push('(última orden #' + orderId + ')');
                setHint('<span class=\"text-info\">' + parts.join(' ') + '</span>');
            }).catch(function(){
                if (my !== inflight) return;
                setHint('');
            });
        }

        serialInput.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(lookup, 450);
        }, { passive: true });
        serialInput.addEventListener('blur', function() {
            clearTimeout(timer);
            lookup();
        }, { passive: true });
    })();

    // Camera & Photos Logic
    const startCameraBtn = document.getElementById('start-camera');
    const cameraContainer = document.getElementById('camera-container');
    const video = document.getElementById('camera-feed');
    const captureBtn = document.getElementById('capture-btn');
    const closeCameraBtn = document.getElementById('close-camera');
    const switchCameraBtn = document.getElementById('switch-camera');
    const photosPreview = document.getElementById('photos-preview');
    const dropZonePrompt = document.getElementById('drop-zone-prompt');
    const photoDropZone = document.getElementById('photo-drop-zone');
    const capturedPhotosInput = document.getElementById('captured_photos_data');
    const devicePhotoInput = document.getElementById('device_photo');
    
    let stream = null;
    let capturedPhotos = [];
    let videoDevices = [];
    let currentDeviceIndex = 0;
    let currentFacingMode = 'environment';

    function updateDropZoneState() {
        const hasPhotos = photosPreview.children.length > 0;
        if (hasPhotos) {
            dropZonePrompt.classList.add('d-none');
            photosPreview.classList.remove('d-none');
        } else {
            dropZonePrompt.classList.remove('d-none');
            photosPreview.classList.add('d-none');
        }
    }

    // Drag and Drop Events
    if (photoDropZone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            photoDropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            photoDropZone.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            photoDropZone.addEventListener(eventName, unhighlight, false);
        });

        function highlight(e) {
            photoDropZone.classList.add('dragover');
        }

        function unhighlight(e) {
            photoDropZone.classList.remove('dragover');
        }

        photoDropZone.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
    }

    function getSelectedGallery() {
        const selected = document.querySelector('input[name="photo_gallery"]:checked');
        return selected ? selected.value : 'entry';
    }

    function getGalleryLabel(value) {
        switch(value) {
            case 'entry': return 'Ingreso';
            case 'diagnosis': return 'Diagnóstico';
            case 'delivery': return 'Entrega';
            default: return 'Ingreso';
        }
    }

    // Actualizar texto del dropzone según galería seleccionada
    const galleryRadios = document.querySelectorAll('input[name="photo_gallery"]');
    galleryRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            const label = getGalleryLabel(this.value);
            const dropzoneText = document.querySelector('#drop-zone-prompt p');
            if (dropzoneText) {
                dropzoneText.textContent = `Se guardarán en la galería de "${label}"`;
            }
        });
    });

    function handleFiles(files) {
        const currentGallery = getSelectedGallery();
        ([...files]).forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const dataUrl = e.target.result;
                    capturedPhotos.push({ 
                        data: dataUrl, 
                        filename: file.name,
                        gallery: currentGallery
                    });
                    capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                    addPhotoPreview(dataUrl, true, 'SUBIDA (' + getGalleryLabel(currentGallery) + ')');
                }
                reader.readAsDataURL(file);
            }
        });
    }

    async function initCameraByDeviceId(id) {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        const resSel = document.getElementById('camera-resolution-overlay') || document.getElementById('camera-resolution');
        let w = null, h = null;
        if (resSel && resSel.value && resSel.value.includes('x')) {
            const parts = resSel.value.split('x');
            w = parseInt(parts[0], 10);
            h = parseInt(parts[1], 10);
        }
        const baseVideo = id ? { deviceId: { exact: id } } : { facingMode: 'environment' };
        if (w && h) {
            baseVideo.width = { ideal: w };
            baseVideo.height = { ideal: h };
        }
        const constraints = { video: baseVideo };
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;
    }

    if(startCameraBtn) {
        startCameraBtn.addEventListener('click', async () => {
            try {
                await initCameraByDeviceId(null);
                const devices = await navigator.mediaDevices.enumerateDevices();
                videoDevices = devices.filter(d => d.kind === 'videoinput');
                currentDeviceIndex = 0;
                
                // Asegurar que el botón de cambio de cámara esté visible si hay dispositivos o como fallback
                if (switchCameraBtn) {
                    switchCameraBtn.classList.remove('d-none');
                }
                
                cameraContainer.classList.remove('d-none');
            } catch (err) {
                if (typeof showError === 'function') showError('No se pudo acceder a la cámara: ' + err.message);
            }
        });
    }

    if(closeCameraBtn) {
        closeCameraBtn.addEventListener('click', () => {
            if(stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraContainer.classList.add('d-none');
        });
    }

    const closeCameraTopBtn = document.getElementById('close-camera-top');
    if (closeCameraTopBtn) {
        closeCameraTopBtn.addEventListener('click', () => {
            if (closeCameraBtn) closeCameraBtn.click();
        });
    }

    const resSelector = document.getElementById('camera-resolution');
    if (resSelector) {
        resSelector.addEventListener('change', async () => {
            if (stream) {
                // Reiniciar cámara con nueva resolución
                const currentDeviceId = videoDevices.length > 0 ? videoDevices[currentDeviceIndex].deviceId : null;
                try {
                    await initCameraByDeviceId(currentDeviceId);
                } catch (err) {
                    if (typeof showError === 'function') showError('No se pudo aplicar la resolución: ' + err.message);
                }
            }
        });
    }

    if (switchCameraBtn) {
        switchCameraBtn.addEventListener('click', async () => {
            if (videoDevices.length > 1) {
                // Si detectamos múltiples cámaras por ID, las rotamos
                currentDeviceIndex = (currentDeviceIndex + 1) % videoDevices.length;
                const nextId = videoDevices[currentDeviceIndex].deviceId;
                try {
                    await initCameraByDeviceId(nextId);
                } catch (err) {
                    if (typeof showError === 'function') showError('No se pudo cambiar de cámara: ' + err.message);
                }
            } else {
                // Fallback: Intentar cambiar modo (frontal/trasera) si no detectamos IDs múltiples
                currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
                if(stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                }
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: currentFacingMode } });
                    video.srcObject = stream;
                } catch (err) {
                    console.error(err);
                    if (typeof showError === 'function') showError('No se pudo cambiar el modo de cámara. Verifica permisos.');
                }
            }
        });
    }

    if(captureBtn) {
        captureBtn.addEventListener('click', () => {
            const vw = video.videoWidth;
            const vh = video.videoHeight;
            const canvas = document.createElement('canvas');
            canvas.width = vw;
            canvas.height = vh;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, vw, vh);
            
            const qs = document.getElementById('camera-quality-overlay') || document.getElementById('camera-quality');
            const q = qs && parseFloat(qs.value) ? parseFloat(qs.value) : 0.85;
            const dataUrl = canvas.toDataURL('image/jpeg', q);
            
            const currentGallery = getSelectedGallery();
            capturedPhotos.push({ 
                data: dataUrl,
                filename: 'camera_capture.jpg',
                gallery: currentGallery
            });
            capturedPhotosInput.value = JSON.stringify(capturedPhotos);
            addPhotoPreview(dataUrl, true, 'CAPTURADA (' + getGalleryLabel(currentGallery) + ')');
        });
    }

    document.addEventListener('keydown', function(e) {
        if (!cameraContainer.classList.contains('d-none')) {
            if (e.code === 'Space') {
                e.preventDefault();
                if (captureBtn) captureBtn.click();
            } else if (e.code === 'Escape') {
                e.preventDefault();
                if (closeCameraBtn) closeCameraBtn.click();
            }
        }
    });

    if(devicePhotoInput) {
        devicePhotoInput.addEventListener('change', function(e) {
            if (this.files && this.files.length > 0) {
                const currentGallery = getSelectedGallery();
                Array.from(this.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const dataUrl = e.target.result;
                        capturedPhotos.push({ 
                            data: dataUrl,
                            filename: file.name,
                            gallery: currentGallery
                        });
                        capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                        addPhotoPreview(dataUrl, true, 'SUBIDA (' + getGalleryLabel(currentGallery) + ')'); 
                    }
                    reader.readAsDataURL(file);
                });
                // Limpiar el input para evitar doble envío y permitir seleccionar el mismo archivo de nuevo
                this.value = '';
            }
        });
    }

    function addPhotoPreview(src, isBase64Stored, label) {
        const col = document.createElement('div');
        col.className = 'col-6 col-sm-4 col-md-3 photo-item';
        
        const l = (label || '').toLowerCase();
        const m = l.match(/\(([^)]+)\)/);
        const catRaw = (m ? m[1] : l).toLowerCase().trim();
        let cat = 'ingreso';
        if (catRaw.includes('ingreso') || catRaw.includes('entrada')) {
            cat = 'ingreso';
        } else if (catRaw.includes('diagnóstico') || catRaw.includes('diagnostico')) {
            cat = 'diagnóstico';
        } else if (catRaw.includes('entrega')) {
            cat = 'entrega';
        }
        const badgeTextMap = { ingreso: 'INGRESO', diagnóstico: 'DIAGNÓSTICO', entrega: 'ENTREGA', otras: 'OTRAS' };
        const badgeText = badgeTextMap[cat] || badgeTextMap['ingreso'];
        const badgeClassMap = { ingreso: 'bg-primary text-primary border-primary', diagnóstico: 'bg-info text-info border-info', entrega: 'bg-success text-success border-success', otras: 'bg-secondary text-secondary border-secondary' };
        const badgeClass = badgeClassMap[cat] || badgeClassMap['ingreso'];

        col.innerHTML = `
            <div class="photo-card shadow-sm rounded-3 bg-white h-100 border">
                <div class="position-relative">
                    <img src="${src}" class="d-block rounded-top-3" alt="Foto">
                    <div class="photo-actions">
                        <button type="button" class="btn btn-danger btn-sm rounded-circle shadow-sm p-0 d-flex align-items-center justify-content-center remove-photo" style="width: 28px; height: 28px;" title="Eliminar">
                            <i class="fas fa-trash-alt fa-xs"></i>
                        </button>
                    </div>
                    <div class="p-2 border-top bg-light rounded-bottom-3">
                            <span class="badge ${badgeClass} no-theme bg-opacity-10 border border-opacity-25 w-100 d-block text-truncate" style="font-size: 0.65rem;">${badgeText}</span>
                    </div>
                </div>
            </div>
        `;
        
        col.querySelector('.remove-photo').onclick = function() {
            // Siempre eliminamos de capturedPhotos ya que ahora todo pasa por ahí
            const index = capturedPhotos.findIndex(p => p.data === src);
            if(index > -1) {
                capturedPhotos.splice(index, 1);
                capturedPhotosInput.value = JSON.stringify(capturedPhotos);
            }
            col.remove();
            updateDropZoneState();
        };
        
        photosPreview.appendChild(col);
        updateDropZoneState();
    }
});
</script>

 <!-- El autocompletado de dispositivos ahora se maneja en device-autocomplete-advanced.js -->




<script>
// Verificar que el archivo de autocompletado se cargue correctamente
console.log('ð?? Verificando carga de device-autocomplete-advanced.js...');
</script>

<script src="../assets/js/device-autocomplete-advanced.js"></script>
<script>
// Verificar que la clase DeviceAutocomplete esté disponible
console.log('ð?? Verificando disponibilidad de DeviceAutocomplete...');
if (typeof DeviceAutocomplete !== 'undefined') {
    console.log('â?? DeviceAutocomplete está disponible');
} else {
    console.log('â? DeviceAutocomplete NO está disponible');
}
</script>
    <script>
    (function(){
        var debug = <?php echo(isset($_GET['debug']) && $_GET['debug'] === '1') ? 'true' : 'false'; ?>;
        window.DEBUG = debug;
        if (!debug) {
            ['log','info','debug'].forEach(function(m){ try { console[m] = function(){}; } catch(e){} });
        }
    })();
    </script>
<?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
    <script>
    // Diagnóstico de catálogos por tenant (solo con ?debug=1)
    document.addEventListener('DOMContentLoaded', function() {
        const fd = new FormData();
        fd.append('action','diagnostics');
        fetch('../devices/device_autocomplete_ajax.php', { method:'POST', body: fd, credentials:'same-origin' })
          .then(r => r.json())
          .then(j => { console.log('ð??? Diagnostics:', j); })
          .catch(e => console.error('â? Diagnostics error', e));
    });
    </script>
<?php
endif; ?>
</body>
</html>
