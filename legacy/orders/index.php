<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
$pdo = db();
require_once '../config/security_enhancements.php';
require_once '../config/company_settings.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_ORDERS', 'work_orders', null);

// Generar token CSRF para operaciones en esta página
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$portal_tenant_id = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$tenant_id;
$hasTenantSystemConfig = hasTenantColumnCached($pdo, 'system_config');
$hasTenantCompanyConfig = hasTenantColumnCached($pdo, 'company_config');
$hasTenantCompanySettings = hasTenantColumnCached($pdo, 'company_settings');
$hasTenantOrderStatuses = hasTenantColumnCached($pdo, 'order_statuses');
$hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 30;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if (!$perDatabase) {
    $where_conditions[] = 'o.tenant_id = ?';
    $params[] = $tenantValue;
}

if (!empty($search)) {
    $where_conditions[] = "(o.id LIKE ? OR c.first_name LIKE ? OR c.company_name LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ? OR o.serial_number LIKE ?)";
    $search_param = "%$search%";
    // Add params
    for ($i = 0; $i < 6; $i++)
        $params[] = $search_param;
}

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Detectar si existe la columna approved_quote_amount para condicionar la lógica de estado efectivo
$hasApprovedQuoteAmount = false;
try {
    $hasApprovedQuoteAmount = hasColumnCached($pdo, 'work_orders', 'approved_quote_amount');
} catch (Throwable $__) {
    $hasApprovedQuoteAmount = false;
}

try {
    if (!hasColumnCached($pdo, 'work_orders', 'approval_status')) {
        $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none'");
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
            $_SESSION['schema_cache_cols']['work_orders_approval_status'] = true;
        }
    }
} catch (Throwable $__) {
}

// Construir el CASE de status_effective dinámicamente
$hasApprovalStatus = hasColumnCached($pdo, 'work_orders', 'approval_status');
$statusEffectiveSql = "CASE
               WHEN 
                   CASE
                       WHEN TRIM(o.status) IN ('asignado','received','recibido') THEN 'asignado'
                       WHEN TRIM(o.status) IN ('diagnosticando','diagnosing') THEN 'diagnosticando'
                       WHEN TRIM(o.status) IN ('esperando_repuestos','waiting_parts') THEN 'esperando_repuestos'
                       WHEN TRIM(o.status) IN ('reparando','repairing') THEN 'reparando'
                       WHEN TRIM(o.status) IN ('testeando','testing','pruebas') THEN 'testeando'
                       WHEN TRIM(o.status) IN ('completado','completed') THEN 'completado'
                       WHEN TRIM(o.status) IN ('entregado','delivered') THEN 'entregado'
                       WHEN TRIM(o.status) IN ('cancelado','cancelled','canceled') THEN 'cancelado'
                       ELSE 'pendiente'
                   END <> 'pendiente'
               THEN 
                   CASE
                       WHEN TRIM(o.status) IN ('pendiente','pending','') THEN 'pendiente'
                       WHEN TRIM(o.status) IN ('asignado','received','recibido') THEN 'asignado'
                       WHEN TRIM(o.status) IN ('diagnosticando','diagnosing') THEN 'diagnosticando'
                       WHEN TRIM(o.status) IN ('esperando_repuestos','waiting_parts') THEN 'esperando_repuestos'
                       WHEN TRIM(o.status) IN ('reparando','repairing') THEN 'reparando'
                       WHEN TRIM(o.status) IN ('testeando','testing','pruebas') THEN 'testeando'
                       WHEN TRIM(o.status) IN ('completado','completed') THEN 'completado'
                       WHEN TRIM(o.status) IN ('entregado','delivered') THEN 'entregado'
                       WHEN TRIM(o.status) IN ('cancelado','cancelled','canceled') THEN 'cancelado'
                       ELSE 'pendiente'
                   END
               ELSE
" . ($hasApprovalStatus ? (
"                   CASE
" . ($hasApprovedQuoteAmount ? "
                       WHEN TRIM(o.approval_status) IN ('approved','aprobado') 
                            AND (
                                o.approved_quote_amount IS NULL 
                                OR ROUND(COALESCE(o.approved_quote_amount,0),2) <> ROUND(COALESCE(o.estimated_cost,0),2)
                            )
                       THEN 'esperando_aprobacion'
" : "") . "
                       WHEN TRIM(o.approval_status) IN ('pending') THEN 'esperando_aprobacion'
                       WHEN TRIM(o.approval_status) IN ('approved','aprobado') THEN 'aprobado'
                       WHEN TRIM(o.approval_status) IN ('rejected','rechazado') THEN 'rechazado'
                       ELSE 'pendiente'
                   END"
) : "                   'pendiente'") . "
           END AS status_effective";

// Consulta principal con paginación
$query = "
    SELECT o.*, 
           CASE 
                WHEN c.client_type = 'individual' THEN c.first_name
               ELSE c.company_name
           END as client_name,
           c.company_name, c.phone as client_phone, c.email as client_email,
           dt.name AS device_type_name,
           $statusEffectiveSql
    FROM work_orders o
    " . ($perDatabase ? "LEFT JOIN clients c ON o.client_id = c.id" : "LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id") . "
    " . ($perDatabase ? "LEFT JOIN device_types dt ON o.device_type_id = dt.id" : "LEFT JOIN device_types dt ON o.device_type_id = dt.id AND dt.tenant_id = o.tenant_id") . "
    $where_clause
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";

// Agregar parámetros de paginación
$params[] = $per_page;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total de registros para paginación (sin parámetros de paginación)
$count_params = array_slice($params, 0, -2); // Remover los últimos 2 parámetros (limit y offset)
$count_query = "
    SELECT COUNT(*) as total
    FROM work_orders o
    " . ($perDatabase ? "LEFT JOIN clients c ON o.client_id = c.id" : "LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id") . "
    $where_clause
";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Obtener estadísticas dinámicas
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        status,
        COUNT(*) as count
    FROM work_orders
    GROUP BY status
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($perDatabase ? [] : [$tenant_id]);
$stats_raw = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Procesar estadísticas con exclusiones configurables por tenant
$stats = ['total_orders' => 0];
$excluded_statuses = ['delivered', 'canceled', 'cancelled', 'returned', 'devolucion'];
try {
    if ($perDatabase) {
        $exStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_dashboard_excluded_for_total' LIMIT 1");
        $exStmt->execute([]);
    } elseif ($hasTenantSystemConfig) {
        $exStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_dashboard_excluded_for_total' LIMIT 1");
        $exStmt->execute([$tenantValue]);
    } else {
        $exStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_dashboard_excluded_for_total' LIMIT 1");
        $exStmt->execute([]);
    }
    $exJson = $exStmt->fetchColumn();
    if ($exJson) {
        $exArr = json_decode($exJson, true);
        if (is_array($exArr) && count($exArr) > 0) {
            $excluded_statuses = array_values(array_unique(array_map('strval', $exArr)));
        }
    }
}
catch (Throwable $e) {
}
foreach ($stats_raw as $stat) {
    $rawSlug = strtolower(trim((string)$stat['status']));
    $map = [
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
        'returned' => 'devolucion'
    ];
    $normSlug = $map[$rawSlug] ?? $rawSlug;
    if (!in_array($normSlug, $excluded_statuses)) {
        $stats['total_orders'] += $stat['count'];
    }
    $stats[$normSlug . '_orders'] = $stat['count'];
}

// Obtener plantillas de WhatsApp
$wa_templates = [];
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%'");
        $stmt->execute([]);
    } elseif ($hasTenantSystemConfig) {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%' AND tenant_id = ?");
        $stmt->execute([$tenantValue]);
    } else {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%'");
        $stmt->execute([]);
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wa_templates[$row['config_key']] = $row['config_value'];
    }
}
catch (Exception $e) {
// Silencioso
}
// Defaults seguros si faltan claves o textos corruptos
$wa_defaults_json = '{
  "whatsapp_template_reception": "\\uD83D\\uDCDD Recepción de equipo\\n\\uD83D\\uDC64 {{cliente}} | \\u260E\\uFE0F {{cliente_tel}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F Marca: {{marca}} | Modelo: {{modelo}}\\n\\uD83D\\uDD22 SN/IMEI: {{sn}}\\n\\u26A0\\uFE0F Problema reportado: {{falla}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDCB5 Abono: {{abono}}\\n\\uD83D\\uDCB0 Costo aproximado: {{valor}}\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\uD83D\\uDD17 Seguimiento: {{url_seguimiento}}\\n\\uD83C\\uDFE2 {{taller_nombre}} | \\uD83D\\uDCDE {{taller_tel}}",
  "whatsapp_template_ready": "\\u2705 Equipo listo\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F {{marca}} {{modelo}} (SN {{sn}})\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\u26A0\\uFE0F Problema: {{falla}}\\n\\uD83D\\uDD2C Diagnóstico: {{diagnostico}}\\n\\uD83D\\uDEE0\\uFE0F Solución: {{solucion}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDCB0 Total: {{total}} | \\uD83D\\uDCB3 Saldo: {{saldo}}\\n\\uD83D\\uDCCD Retiro: {{fecha_entrega}}\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}",
  "whatsapp_template_delivery": "\\uD83D\\uDCE6 Entrega realizada\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F {{marca}} {{modelo}} (SN {{sn}})\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\uD83D\\uDD2C Diagnóstico: {{diagnostico}}\\n\\uD83D\\uDEE0\\uFE0F Solución: {{solucion}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDE4F Gracias por confiar en nosotros\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}",
  "whatsapp_template_sale": "\\uD83E\\uDDFE Comprobante de Venta #{{factura}}\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDECD\\uFE0F Detalles: {{detalles}}\\n\\uD83D\\uDCB0 Total: {{total}} | \\uD83D\\uDCB3 Saldo: {{saldo}}\\n\\uD83D\\uDE4F ¡Gracias por tu compra!\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}"
}';
$wa_defaults = json_decode($wa_defaults_json, true) ?: [];
foreach (['whatsapp_template_reception','whatsapp_template_ready','whatsapp_template_delivery','whatsapp_template_sale'] as $k) {
    $v = isset($wa_templates[$k]) ? (string)$wa_templates[$k] : '';
    if ($v === '' || strpos($v, "\u{FFFD}") !== false || strpos($v, '????') !== false) {
        $wa_templates[$k] = $wa_defaults[$k] ?? '';
    }
}

// Datos de empresa para mensajes (nombre y teléfono) con fallback
$company_name = '';
$company_phone = '';
try {
    $cstmt = $pdo->prepare("SELECT company_name, company_phone FROM company_config" . ((!$perDatabase && $hasTenantCompanyConfig) ? " WHERE tenant_id = ?" : "") . " LIMIT 1");
    $cstmt->execute((!$perDatabase && $hasTenantCompanyConfig) ? [$tenantValue] : []);
    $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
    if ($crow) {
        $company_name = $crow['company_name'] ?? '';
        $company_phone = $crow['company_phone'] ?? '';
    }
    if ($company_name === '' || $company_phone === '') {
        $cstmt2 = $pdo->prepare("SELECT company_name, company_phone FROM company_settings" . ((!$perDatabase && $hasTenantCompanySettings) ? " WHERE tenant_id = ?" : "") . " ORDER BY id DESC LIMIT 1");
        $cstmt2->execute((!$perDatabase && $hasTenantCompanySettings) ? [$tenantValue] : []);
        $crow2 = $cstmt2->fetch(PDO::FETCH_ASSOC);
        if ($crow2) {
            if ($company_name === '') $company_name = $crow2['company_name'] ?? '';
            if ($company_phone === '') $company_phone = $crow2['company_phone'] ?? '';
        }
    }
}
catch (Throwable $e) {
}

// Obtener estados de órdenes (unión tenant + global evitando duplicados)
$statuses = [];
try {
    if ($hasTenantOrderStatuses && !$perDatabase) {
        $sql = "SELECT slug, name, emoji, color, is_default, sort_order 
                FROM order_statuses 
                WHERE is_active = 1 AND tenant_id = ? AND slug <> 'approved'
                ORDER BY sort_order, name";
        $statuses_stmt = $pdo->prepare($sql);
        $statuses_stmt->execute([$tenantValue]);
        $statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $statuses_stmt = $pdo->prepare("SELECT slug, name, emoji, color, sort_order FROM order_statuses WHERE is_active = 1 AND slug <> 'approved' ORDER BY sort_order, name");
        $statuses_stmt->execute();
        $statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $statuses = [];
}
if (empty($statuses)) {
    $statuses = [
        ['slug' => 'pendiente', 'name' => 'Pendiente', 'color' => '#ffc107', 'emoji' => "\u{23F3}", 'sort_order' => 1],
        ['slug' => 'asignado', 'name' => 'Asignado', 'color' => '#6cc4ea', 'emoji' => "\u{1F4E6}", 'sort_order' => 2],
        ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'color' => '#fd7e14', 'emoji' => "\u{1F50D}", 'sort_order' => 3],
        ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'color' => '#6f42c1', 'emoji' => "\u{23F8}\u{FE0F}", 'sort_order' => 4],
        ['slug' => 'reparando', 'name' => 'Reparando', 'color' => '#007bff', 'emoji' => "\u{1F527}", 'sort_order' => 5],
        ['slug' => 'testeando', 'name' => 'Testeando', 'color' => '#17a2b8', 'emoji' => "\u{1F9EA}", 'sort_order' => 6],
        ['slug' => 'completado', 'name' => 'Completado', 'color' => '#28a745', 'emoji' => "\u{2705}", 'sort_order' => 7],
        ['slug' => 'entregado', 'name' => 'Entregado', 'color' => '#6c757d', 'emoji' => "\u{1F69A}", 'sort_order' => 8],
        ['slug' => 'cancelado', 'name' => 'Cancelado', 'color' => '#dc3545', 'emoji' => "\u{274C}", 'sort_order' => 9],
    ];
}
try {
    $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_statuses_template'" . ((!$perDatabase && $hasTenantSystemConfig) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $tplStmt->execute((!$perDatabase && $hasTenantSystemConfig) ? [$tenantValue] : []);
    $tplJson = (string)$tplStmt->fetchColumn();
    if ($tplJson) {
        $tplArr = json_decode($tplJson, true);
        if (is_array($tplArr) && count($tplArr) > 0) {
            $pos = [];
            foreach ($tplArr as $i => $row) {
                $slug = strtolower(trim((string)($row['slug'] ?? '')));
                if ($slug !== '') {
                    $pos[$slug] = $i;
                }
            }
            usort($statuses, function ($a, $b) use ($pos) {
                $sa = strtolower(trim($a['slug'] ?? ''));
                $sb = strtolower(trim($b['slug'] ?? ''));
                $pa = array_key_exists($sa, $pos) ? $pos[$sa] : PHP_INT_MAX;
                $pb = array_key_exists($sb, $pos) ? $pos[$sb] : PHP_INT_MAX;
                if ($pa === $pb) {
                    $oa = (int)($a['sort_order'] ?? 0);
                    $ob = (int)($b['sort_order'] ?? 0);
                    if ($oa === $ob)
                        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                    return $oa <=> $ob;
                }
                return $pa <=> $pb;
            });
        }
    }
}
catch (Throwable $e) {
}

// Función para obtener el badge del estado (clic abre modal si se pasa order_id)
function getStatusBadge($status, $statuses, $order_id = null)
{
    $st = strtolower(trim((string)$status));
    foreach ($statuses as $status_data) {
        $sd = strtolower(trim((string)($status_data['slug'] ?? '')));
        if ($sd === $st) {
            $color = $status_data['color'];
            $name = $status_data['name'];
            $emoji = getStatusEmoji($sd);
            $badge_id = $order_id ? 'statusBadge_' . (int)$order_id : '';
            $id_attr = $badge_id ? ' id="' . $badge_id . '"' : '';
            $badge_html = '<span' . $id_attr . ' class="badge" style="background-color: ' . htmlspecialchars($color) . '; color: white;">' .
                htmlspecialchars($emoji . ' ' . $name) . '</span>';
            if ($order_id) {
                return '<button type="button" class="btn btn-link p-0 align-middle text-decoration-none" onclick="openChangeStatusModal(' . (int)$order_id . ')" title="Cambiar estado">' . $badge_html . '</button>';
            }
            return $badge_html;
        }
    }
    $aliases = [
        'approved' => ['aprobado'],
        'rejected' => ['rechazado']
    ];
    if (isset($aliases[$st])) {
        foreach ($aliases[$st] as $alt) {
            $alt = strtolower(trim($alt));
            foreach ($statuses as $status_data) {
                $sd = strtolower(trim((string)($status_data['slug'] ?? '')));
                if ($sd === $alt) {
                    $color = $status_data['color'];
                    $name = $status_data['name'];
                    $emoji = getStatusEmoji($sd);
                    $badge_id = $order_id ? 'statusBadge_' . (int)$order_id : '';
                    $id_attr = $badge_id ? ' id="' . $badge_id . '"' : '';
                    $badge_html = '<span' . $id_attr . ' class="badge" style="background-color: ' . htmlspecialchars($color) . '; color: white;">' .
                        htmlspecialchars($emoji . ' ' . $name) . '</span>';
                    if ($order_id) {
                        return '<button type="button" class="btn btn-link p-0 align-middle text-decoration-none" onclick="openChangeStatusModal(' . (int)$order_id . ')" title="Cambiar estado">' . $badge_html . '</button>';
                    }
                    return $badge_html;
                }
            }
        }
    }
    // Fallback para slugs comunes aunque no estén en la tabla
    $fallback = [
        'esperando_aprobacion' => ['name' => 'Esperando Aprobación', 'color' => '#f59e0b'],
        'aprobado' => ['name' => 'Aprobado', 'color' => '#28a745'],
        'rechazado' => ['name' => 'Rechazado', 'color' => '#dc3545'],
        'pendiente' => ['name' => 'Pendiente', 'color' => '#ffc107'],
        'asignado' => ['name' => 'Asignado', 'color' => '#6cc4ea'],
        'diagnosticando' => ['name' => 'Diagnosticando', 'color' => '#fd7e14'],
        'esperando_repuestos' => ['name' => 'Esperando Repuestos', 'color' => '#6f42c1'],
        'reparando' => ['name' => 'Reparando', 'color' => '#007bff'],
        'testeando' => ['name' => 'Testeando', 'color' => '#17a2b8'],
        'completado' => ['name' => 'Completado', 'color' => '#28a745'],
        'entregado' => ['name' => 'Entregado', 'color' => '#6c757d'],
        'cancelado' => ['name' => 'Cancelado', 'color' => '#dc3545'],
    ];
    if (isset($fallback[$st])) {
        $emoji = getStatusEmoji($st);
        $def = $fallback[$st];
        $badge_html = '<span class="badge" style="background-color: ' . htmlspecialchars($def['color']) . '; color: white;">' . htmlspecialchars($emoji . ' ' . $def['name']) . '</span>';
        if ($order_id) {
            return '<button type="button" class="btn btn-link p-0 align-middle text-decoration-none" onclick="openChangeStatusModal(' . (int)$order_id . ')" title="Cambiar estado">' . $badge_html . '</button>';
        }
        return $badge_html;
    }
    return '<span class="badge bg-secondary">Desconocido</span>';
}




// Función para obtener el ícono del estado
function getStatusIcon($status)
{
    $icons = [
        'pending' => 'fas fa-clock',
        'in_progress' => 'fas fa-cog fa-spin',
        'completed' => 'fas fa-check-circle',
        'delivered' => 'fas fa-truck',
        'cancelled' => 'fas fa-times-circle'
    ];
    return $icons[$status] ?? 'fas fa-question-circle';
}

// Manejo de mensajes
$mensaje = '';
$tipo_mensaje = '';

if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
}
elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}
?>

<?php
// Configuración del template
$page_title = 'Gestión de Órdenes';

$additional_js = ['../assets/js/orders.js?v=' . time()];

// Capturar el contenido de la página
ob_start();
?>
<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-clipboard-list me-2 text-primary no-theme"></i>Gestión de Órdenes</h2>
        <p class="text-muted mb-0">Administra todas las órdenes de servicio técnico</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="new.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-plus me-2"></i>Nueva Orden
        </a>
    </div>
</div>

<!-- Mensajes de estado -->
<?php if ($mensaje): ?>
    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($mensaje); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php
endif; ?>

<!-- Estadísticas -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card card-modern h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-clipboard-list fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['total_orders']; ?></h5>
                    <small class="text-muted">Total Órdenes</small>
                    <span class="ms-1 align-middle" title="Las tarjetas del dashboard se configuran aparte; su orden es independiente del orden global de estados.">
                        <i class="fas fa-info-circle text-muted"></i>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php
// Selección de tarjetas configurable por tenant
$card_slugs = [];
try {
    $cardsStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_dashboard_cards'" . ((!$perDatabase && $hasTenantSystemConfig) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $cardsStmt->execute((!$perDatabase && $hasTenantSystemConfig) ? [$tenantValue] : []);
    $cardsJson = $cardsStmt->fetchColumn();
    if ($cardsJson) {
        $arr = json_decode($cardsJson, true);
        if (is_array($arr) && count($arr) > 0) {
            $card_slugs = array_values(array_unique(array_map('strval', $arr)));
        }
    }
}
catch (Throwable $e) {
}
if (empty($card_slugs)) {
    // Fallback: primeros 3 activos por sort_order
    $card_slugs = array_slice(array_map(function ($s) {
        return $s['slug'];
    }, $statuses), 0, 3);
}
// Mostrar máximo 3
if (count($card_slugs) > 3) {
    $card_slugs = array_slice($card_slugs, 0, 3);
}
$statusBySlug = [];
foreach ($statuses as $s) {
    $statusBySlug[$s['slug']] = $s;
}
// Definir iconos específicos para cada estado
$status_icons = [
    'received' => 'fas fa-inbox',
    'pending' => 'fas fa-clock',
    'diagnosing' => 'fas fa-search',
    'waiting_parts' => 'fas fa-tools',
    'waiting_authorization' => 'fas fa-user-check',
    'repairing' => 'fas fa-wrench',
    'testing' => 'fas fa-vial',
    'completed' => 'fas fa-check-circle',
    'delivered' => 'fas fa-truck',
    'cancelled' => 'fas fa-times-circle'
];

foreach ($card_slugs as $slug):
    $slugNorm = normalizeStatusSlug(strtolower(trim((string)$slug)));
    $status = $statusBySlug[$slugNorm] ?? null;
    if (!$status) {
        $fallback = [
            'aprobado' => ['slug' => 'aprobado', 'name' => 'Aprobado', 'color' => '#28a745'],
            'rechazado' => ['slug' => 'rechazado', 'name' => 'Rechazado', 'color' => '#dc3545'],
            'pendiente' => ['slug' => 'pendiente', 'name' => 'Pendiente', 'color' => '#ffc107'],
            'asignado' => ['slug' => 'asignado', 'name' => 'Asignado', 'color' => '#6cc4ea'],
            'diagnosticando' => ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'color' => '#fd7e14'],
            'esperando_repuestos' => ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'color' => '#6f42c1'],
            'reparando' => ['slug' => 'reparando', 'name' => 'Reparando', 'color' => '#007bff'],
            'testeando' => ['slug' => 'testeando', 'name' => 'Testeando', 'color' => '#17a2b8'],
            'completado' => ['slug' => 'completado', 'name' => 'Completado', 'color' => '#28a745'],
            'entregado' => ['slug' => 'entregado', 'name' => 'Entregado', 'color' => '#6c757d'],
            'cancelado' => ['slug' => 'cancelado', 'name' => 'Cancelado', 'color' => '#dc3545'],
        ];
        if (!isset($fallback[$slugNorm])) {
            continue;
        }
        $status = $fallback[$slugNorm];
    }
    $count = $stats[$slugNorm . '_orders'] ?? 0;
    $emojiConf = trim((string)($status['emoji'] ?? ''));
    $emoji = $emojiConf !== '' ? $emojiConf : getStatusEmoji($slugNorm);
    // Use hex color with opacity for background
    $bg_color = $status['color'] . '20'; // ~12% opacity
?>
    <div class="col-md-3">
        <div class="card card-modern h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="background-color: <?php echo $bg_color; ?>; width: 56px; height: 56px;">
                    <span style="color: <?php echo htmlspecialchars($status['color']); ?>; font-size: 1.8rem; line-height: 1;"><?php echo htmlspecialchars($emoji); ?></span>
                </div>
                <div>
                    <h5 class="fw-bold mb-0" style="color: <?php echo htmlspecialchars($status['color']); ?>"><?php echo $count; ?></h5>
                    <small class="text-muted"><?php echo htmlspecialchars($status['name']); ?></small>
                </div>
            </div>
        </div>
    </div>
    <?php
endforeach; ?>            
</div>

<!-- Filtros y Búsqueda -->
<div class="card card-modern mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" name="search" 
                           placeholder="Buscar..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select bg-light border-0 text-muted rounded-pill">
                    <option value="">Todos los estados</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo htmlspecialchars($status['slug']); ?>"
                                <?php echo $status_filter === $status['slug'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(getStatusEmoji($status['slug']) . ' ' . $status['name']); ?>
                        </option>
                    <?php
endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary rounded-pill px-3">
                        <i class="fas fa-filter me-1"></i>Filtrar
                    </button>
                    <?php if (!empty($search) || !empty($status_filter)): ?>
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3">
                            <i class="fas fa-times me-1"></i>Limpiar
                        </a>
                    <?php
endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Main Card -->
<div class="card card-modern overflow-hidden">
    <!-- Tabla de órdenes -->
    <div class="card-body p-0">
        <?php if (empty($orders)): ?>
            <div class="text-center py-5">
                <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                    <i class="fas fa-inbox fa-3x text-muted"></i>
                </div>
                <h5 class="text-muted mb-2">No se encontraron órdenes</h5>
                <p class="text-muted mb-3">No hay órdenes que coincidan con los criterios de búsqueda.</p>
                <a href="new.php" class="btn btn-dark rounded-pill px-4">Crear Primera Orden</a>
            </div>
        <?php
else: ?>
            <!-- Vista de Escritorio (Tabla) -->
            <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Cliente</th>
                            <th>Dispositivo</th>
                            <th>Problema</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td class="ps-4">
                                    <?php
        $num = (int)$order['id'];
?>
                                    <span class="fw-bold text-dark">#<?php echo $num; ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?php
        $client_name = !empty($order['company_name']) ? $order['company_name'] : $order['client_name'];
        echo htmlspecialchars($client_name);
?>
                                    </div>
                                    <?php if ($order['client_phone']): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($order['client_phone']); ?>
                                        </small>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($order['device_brand'] ?: 'Marca no especificada'); ?>
                                    </div>
                                    <small class="text-muted">
                                        <?php
        $type = $order['device_type_name'] ?? '';
        $model = $order['device_model'] ?? '';
        $device_info = array_filter([$type, $model]);
        echo !empty($device_info) ? htmlspecialchars(implode(' - ', $device_info)) : 'Sin información extra';
?>
                                    </small>
                                </td>
                                <td>
                                    <?php $problem_desc = $order['reported_issue'] ?? 'Sin descripción'; ?>
                                    <div class="d-flex align-items-center">
                                        <span class="text-truncate d-inline-block text-muted" style="max-width: 180px;" 
                                              title="<?php echo htmlspecialchars($problem_desc); ?>">
                                            <?php echo htmlspecialchars($problem_desc); ?>
                                        </span>
                                        <?php if (strlen($problem_desc) > 50): ?>
                                            <button type="button" class="btn btn-sm btn-link text-info p-0 ms-2" 
                                                    onclick="showProblemDetails(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($problem_desc, ENT_QUOTES); ?>')" 
                                                    title="Ver descripción completa">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        <?php
        endif; ?>
                                    </div>
                                </td>
                                <td><?php echo getStatusBadge($order['status_effective'] ?? $order['status'], $statuses, $order['id']); ?></td>
                                <td>
                                    <div class="text-dark"><?php echo formatCompanyDate($order['created_at']); ?></div>
                                    <small class="text-muted"><?php echo formatCompanyTime($order['created_at']); ?></small>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="view.php?id=<?php echo $order['id']; ?>" 
                                           class="btn btn-sm btn-light text-primary no-theme shadow-sm" title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $order['id']; ?>" 
                                           class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="order_reports.php?id=<?php echo $order['id']; ?>" 
                                           class="btn btn-sm btn-light text-primary no-theme shadow-sm" title="Informes Técnicos">
                                            <i class="fas fa-clipboard"></i>
                                        </a>
                                        <button type="button"
                                                class="btn btn-sm btn-light text-dark shadow-sm"
                                                title="Imprimir"
                                                onclick="quickPrint(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-light text-dark shadow-sm"
                                                title="Etiqueta"
                                                onclick="quickLabel(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-tag"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-light text-danger shadow-sm"
                                                title="PDF"
                                                onclick="quickPdf(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                        <?php
        $displayOrderNumber = (int)$order['id'];
        $wa_client_name = !empty($order['company_name']) ? $order['company_name'] : $order['client_name'];
        $wa_device_info = $order['device_type_name'] ?? 'Equipo';
        if (!empty($order['device_brand'])) $wa_device_info .= ' ' . $order['device_brand'];
        if (!empty($order['device_model'])) $wa_device_info .= ' ' . $order['device_model'];
        
        $brand = $order['device_brand'] ?? '';
        $model = $order['device_model'] ?? '';
        $sn = $order['serial_number'] ?? '';
        $typeDev = $order['device_type_name'] ?? '';
        $diagnosis = $order['diagnosis'] ?? '';
        $solution = $order['solution'] ?? '';
        $fechaEntrega = !empty($order['estimated_completion']) ? formatCompanyDate($order['estimated_completion']) : '';
        $reported_issue = $order['reported_issue'] ?? '';
        $estimated_cost = $order['estimated_cost'] ?? 0;
        $advance_payment = $order['advance_payment'] ?? 0;
        $client_phone = $order['client_phone'] ?? '';
?>
                                        <button type="button" class="btn btn-sm btn-light text-success shadow-sm" 
                                                onclick="openWhatsAppModal('<?php echo $order['id']; ?>', '<?php echo htmlspecialchars($displayOrderNumber, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($wa_client_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($wa_device_info, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reported_issue, ENT_QUOTES); ?>', '<?php echo $estimated_cost; ?>', '<?php echo htmlspecialchars($advance_payment, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($client_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($brand, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($model, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($sn, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($typeDev, ENT_QUOTES); ?>', '<?php echo htmlspecialchars('Sin accesorios', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($diagnosis, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($solution, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES); ?>')" 
                                                title="Enviar por WhatsApp">
                                            <i class="fab fa-whatsapp"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-danger shadow-sm" 
                                                onclick="deleteOrder(<?php echo $order['id']; ?>)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php
    endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vista Móvil (Tarjetas) -->
            <div class="d-block d-lg-none p-3 bg-light">
                <div class="row g-3">
                    <?php foreach ($orders as $order): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                <!-- Cabecera de Tarjeta -->
                                <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                    <?php
        $num = (int)$order['id'];
?>
                                    <span class="fs-5 fw-bold text-dark">#<?php echo $num; ?></span>
                                    <div><?php echo getStatusBadge($order['status_effective'] ?? $order['status'], $statuses, $order['id']); ?></div>
                                </div>
                                <!-- Cuerpo de Tarjeta -->
                                <div class="card-body py-2">
                                    <div class="mb-3">
                                        <?php $client_name = !empty($order['company_name']) ? $order['company_name'] : $order['client_name']; ?>
                                        <h6 class="fw-bold mb-1 text-dark"><i class="fas fa-user text-primary no-theme me-2"></i><?php echo htmlspecialchars($client_name); ?></h6>
                                        <?php if ($order['client_phone']): ?>
                                            <a href="tel:<?php echo htmlspecialchars($order['client_phone']); ?>" class="text-muted text-decoration-none ms-4 d-inline-block small"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($order['client_phone']); ?></a>
                                        <?php
        endif; ?>
                                    </div>
                                    <div class="mb-3">
                                        <?php
        $type = $order['device_type_name'] ?? '';
        $model = $order['device_model'] ?? '';
        $device_info = array_filter([$type, $model]);
        $device_text = !empty($device_info) ? implode(' - ', $device_info) : 'Sin info extra';
?>
                                        <h6 class="fw-bold mb-1 text-dark"><i class="fas fa-mobile-alt text-primary no-theme me-2"></i><?php echo htmlspecialchars($order['device_brand'] ?: 'Marca no especificada'); ?></h6>
                                        <span class="text-muted ms-4 d-inline-block small"><?php echo htmlspecialchars($device_text); ?></span>
                                    </div>
                                    <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                                        <?php $problem_desc = $order['reported_issue'] ?? 'Sin descripción'; ?>
                                        <div class="fw-bold text-dark mb-1">Problema reportado:</div>
                                        <?php echo htmlspecialchars($problem_desc); ?>
                                    </div>
                                </div>
                                <!-- Pie de Tarjeta (Fechas y Acciones) -->
                                <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                                    <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                        <span><i class="fas fa-calendar-alt me-1"></i><?php echo formatCompanyDate($order['created_at']); ?></span>
                                        <span><i class="fas fa-clock me-1"></i><?php echo formatCompanyTime($order['created_at']); ?></span>
                                    </div>
                                    <!-- Botones de Acción (Scroll Horizontal si no caben) -->
                                    <div class="d-flex flex-wrap gap-2 pb-1 justify-content-center justify-content-sm-start">
                                        <a href="view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Ver"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-light text-warning shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                        <a href="order_reports.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Informes"><i class="fas fa-clipboard"></i></a>
                                        <button type="button" class="btn btn-sm btn-light text-dark shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Imprimir" onclick="quickPrint(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-dark shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Etiqueta" onclick="quickLabel(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-tag"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" onclick="quickPdf(<?php echo $order['id']; ?>)" title="PDF"><i class="fas fa-file-pdf"></i></button>
                                        <?php
        $displayOrderNumber = (int)$order['id'];
        $wa_client_name = !empty($order['company_name']) ? $order['company_name'] : $order['client_name'];
        $wa_device_info = $order['device_type_name'] ?? 'Equipo';
        if (!empty($order['device_brand'])) $wa_device_info .= ' ' . $order['device_brand'];
        if (!empty($order['device_model'])) $wa_device_info .= ' ' . $order['device_model'];
        
        $brand = $order['device_brand'] ?? '';
        $model = $order['device_model'] ?? '';
        $sn = $order['serial_number'] ?? '';
        $typeDev = $order['device_type_name'] ?? '';
        $diagnosis = $order['diagnosis'] ?? '';
        $solution = $order['solution'] ?? '';
        $fechaEntrega = !empty($order['estimated_completion']) ? formatCompanyDate($order['estimated_completion']) : '';
        $reported_issue = $order['reported_issue'] ?? '';
        $estimated_cost = $order['estimated_cost'] ?? 0;
        $advance_payment = $order['advance_payment'] ?? 0;
        $client_phone = $order['client_phone'] ?? '';
?>
                                        <button type="button" class="btn btn-sm btn-light text-success shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;"
                                                onclick="openWhatsAppModal('<?php echo $order['id']; ?>', '<?php echo htmlspecialchars($displayOrderNumber, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($wa_client_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($wa_device_info, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reported_issue, ENT_QUOTES); ?>', '<?php echo $estimated_cost; ?>', '<?php echo htmlspecialchars($advance_payment, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($client_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($brand, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($model, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($sn, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($typeDev, ENT_QUOTES); ?>', '<?php echo htmlspecialchars('Sin accesorios', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($diagnosis, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($solution, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES); ?>')" title="WhatsApp">
                                            <i class="fab fa-whatsapp"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;"
                                                onclick="deleteOrder(<?php echo $order['id']; ?>)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php
    endforeach; ?>
                </div>
            </div>

            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top border-light py-3">
                    <nav aria-label="Paginación de órdenes">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        <i class="fas fa-chevron-left me-1"></i> Anterior
                                    </a>
                                </li>
                            <?php
        endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 rounded-circle <?php echo $i === $page ? 'bg-primary text-white shadow-sm' : 'text-muted'; ?> mx-1 d-flex align-items-center justify-content-center" 
                                       style="width: 35px; height: 35px;"
                                       href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php
        endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        Siguiente <i class="fas fa-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            <?php
        endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php
    endif; ?>
        <?php
endif; ?>
    </div>
</div>

<script>
function quickPrint(orderId) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.src = 'print_order.php?id=' + encodeURIComponent(orderId);
    document.body.appendChild(iframe);
    iframe.onload = function() {
        try {
            setTimeout(function() {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 1500);
            }, 250);
        } catch (e) {
            console.error('Error al imprimir:', e);
        }
    };
}

function quickLabel(orderId) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.src = 'print_label.php?id=' + encodeURIComponent(orderId);
    document.body.appendChild(iframe);
    iframe.onload = function() {
        try {
            setTimeout(function() {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 1500);
            }, 250);
        } catch (e) {
        }
    };
}

function quickPdf(orderId) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.left = '-9999px'; // Mover fuera de pantalla en lugar de hacerlo invisible/diminuto
    iframe.style.top = '0';
    iframe.style.width = '1200px'; // Ancho suficiente para renderizar el layout
    iframe.style.height = '1600px'; // Alto suficiente
    iframe.style.border = '0';
    iframe.src = 'print_order.php?id=' + encodeURIComponent(orderId);
    document.body.appendChild(iframe);
    iframe.onload = function() {
        try {
            setTimeout(function() {
                const w = iframe.contentWindow;
                if (w && typeof w.downloadOrderPDF === 'function') {
                    w.downloadOrderPDF();
                }
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 5000); // Dar más tiempo para generar antes de eliminar
            }, 1000); // Esperar a que carguen estilos/imágenes
        } catch (e) {}
    };
}
</script>

<!-- Modal Cambiar Estado -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4" id="changeStatusModalContent"></div>
    </div>
</div>

<script>
function openChangeStatusModal(orderId) {
    const el = document.getElementById('changeStatusModalContent');
    el.innerHTML = '<div class="p-4 text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div></div>';
    fetch('modal_change_status.php?id=' + encodeURIComponent(orderId))
        .then(r => r.text())
        .then(html => { 
            el.innerHTML = html; 
            const scripts = el.querySelectorAll('script');
            scripts.forEach(orig => {
                const s = document.createElement('script');
                if (orig.src) { s.src = orig.src; } else { s.textContent = orig.textContent; }
                document.body.appendChild(s);
                orig.remove();
            });
        })
        .catch(() => { el.innerHTML = '<div class="p-4 text-center text-danger">Error al cargar contenido</div>'; });
    new bootstrap.Modal(document.getElementById('changeStatusModal')).show();
}
</script>
<!-- Hidden CSRF Token -->
<input type="hidden" id="csrf_token" value="<?php echo $csrf_token; ?>">

<!-- Modal de Confirmación de Eliminación -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold text-danger" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <div class="mb-3">
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3 d-inline-block">
                        <i class="fas fa-trash-alt fa-2x text-danger"></i>
                    </div>
                </div>
                <h5 class="fw-bold mb-2">¿Estás seguro?</h5>
                <p class="text-muted mb-0">
                    Vas a eliminar la orden <span id="orderIdToDelete" class="fw-bold text-dark"></span>.
                    <br>Esta acción no se puede deshacer.
                </p>
            </div>
            <div class="modal-footer border-top-0 justify-content-center pb-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="confirmDeleteBtn" class="btn btn-danger rounded-pill px-4">
                    <i class="fas fa-trash me-2"></i>Eliminar Orden
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de WhatsApp -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header bg-success text-white border-0" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title"><i class="fab fa-whatsapp me-2"></i>Enviar Mensaje WhatsApp</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" id="waOrderId">
                <input type="hidden" id="waOrderNumber">
                <input type="hidden" id="waClientName">
                <input type="hidden" id="waEquipment">
                <input type="hidden" id="waProblem">
                <input type="hidden" id="waCost">
                <input type="hidden" id="waAbono">
                <input type="hidden" id="waPhone">
                <input type="hidden" id="waBrand">
                <input type="hidden" id="waModel">
                <input type="hidden" id="waSN">
                <input type="hidden" id="waType">
                <input type="hidden" id="waAccessories">
                <input type="hidden" id="waDiagnosis">
                <input type="hidden" id="waSolution">
                <input type="hidden" id="waFechaEntrega">
                
                <div class="mb-3">
                    <label class="form-label">Seleccionar Plantilla</label>
                    <select class="form-select rounded-pill shadow-sm" id="waTemplateSelect" onchange="updateWhatsAppMessage()">
                        <option value="reception">Recepción de Equipo</option>
                        <option value="ready">Equipo Listo</option>
                        <option value="delivery">Equipo Entregado</option>
                        <option value="sale">Comprobante de Venta</option>
                        <option value="custom">Mensaje Personalizado</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Mensaje</label>
                    <textarea class="form-control rounded-3 shadow-sm" id="waMessage" rows="8"></textarea>
                    <div class="form-text">
                        Variables: {{cliente}}, {{equipo}}, {{falla}}, {{valor}}, {{abono}}, {{orden}}
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success rounded-pill px-4" onclick="sendWhatsAppFromModal()">
                    <i class="fab fa-whatsapp me-2"></i>Enviar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Plantillas desde PHP
const whatsappTemplates = <?php echo json_encode($wa_templates, JSON_UNESCAPED_UNICODE); ?>;
const companyName = <?php echo json_encode($company_name); ?>;
const companyPhone = <?php echo json_encode(CompanySettings::getFullPhone($company_phone)); ?>;
const currencySymbol = <?php echo json_encode(CompanySettings::getCurrency()['symbol']); ?>;
const portalBaseUrl = <?php echo json_encode(getSystemBaseUrl()); ?>;
const tenantSlug = <?php echo json_encode(getTenantPreferredSlug($portal_tenant_id) ?? strval($portal_tenant_id)); ?>;
const orderPrefix = <?php echo json_encode(function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_id) : 'ORD'); ?>;

function openWhatsAppModal(orderId, orderNumber, clientName, equipment, problem, cost, abono, phone, brand, model, sn, type, accessories, diagnosis, solution, fechaEntrega) {
    document.getElementById('waOrderId').value = orderId;
    document.getElementById('waOrderNumber').value = orderNumber;
    document.getElementById('waClientName').value = clientName;
    document.getElementById('waEquipment').value = equipment;
    document.getElementById('waProblem').value = problem;
    document.getElementById('waCost').value = cost;
    document.getElementById('waAbono').value = abono || '';
    document.getElementById('waPhone').value = phone || '';
    document.getElementById('waBrand').value = brand || '';
    document.getElementById('waModel').value = model || '';
    document.getElementById('waSN').value = sn || '';
    document.getElementById('waType').value = type || '';
    document.getElementById('waAccessories').value = accessories || 'Sin accesorios';
    document.getElementById('waDiagnosis').value = diagnosis || '';
    document.getElementById('waSolution').value = solution || '';
    document.getElementById('waFechaEntrega').value = fechaEntrega || '';
    
    // Seleccionar plantilla por defecto (ej. recepción)
    document.getElementById('waTemplateSelect').value = 'reception';
    updateWhatsAppMessage();
    
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

function updateWhatsAppMessage() {
    const type = document.getElementById('waTemplateSelect').value;
    const orderId = document.getElementById('waOrderId').value;
    const orderNumber = document.getElementById('waOrderNumber').value;
    const clientName = document.getElementById('waClientName').value;
    const equipment = document.getElementById('waEquipment').value;
    const problem = document.getElementById('waProblem').value;
    const cost = document.getElementById('waCost').value;
    const phone = document.getElementById('waPhone').value;
    const brand = document.getElementById('waBrand').value;
    const model = document.getElementById('waModel').value;
    const sn = document.getElementById('waSN').value;
    const typeDev = document.getElementById('waType').value;
    const accessories = document.getElementById('waAccessories').value;
    const diagnosis = document.getElementById('waDiagnosis').value;
    const solution = document.getElementById('waSolution').value;
    const fechaEntrega = document.getElementById('waFechaEntrega').value;
    const abono = document.getElementById('waAbono').value || '';
    
    if (type === 'custom') return;
    
    let template = whatsappTemplates[`whatsapp_template_${type}`] || '';
    if (!template) {
        // Fallbacks por defecto si no hay configuración
        if (type === 'reception') template = "Hola {{cliente}}, hemos recibido su equipo {{equipo}}. Orden #{{orden}}.";
        else if (type === 'ready') template = "Hola {{cliente}}, su equipo {{equipo}} ya está listo. Total: {{valor}}.";
        else if (type === 'delivery') template = "Hola {{cliente}}, gracias por confiar en nosotros. Su equipo {{equipo}} ha sido entregado.";
        else if (type === 'sale') template = "\uD83E\uDDFE Comprobante de Venta\nCliente: {{cliente}}\nFactura: {{factura}}\nDetalles: {{detalles}}\nTotal: {{total}}\nSaldo: {{saldo}}\n{{taller_nombre}}";
    }
    
    const orderNo = orderPrefix + '-' + String(orderNumber || orderId).padStart(4, '0');
    const urlSeg = portalBaseUrl + 'portal/verify.php?t=' + encodeURIComponent(tenantSlug) + '&order_no=' + encodeURIComponent(orderNo);
    if (type === 'sale') {
        const fd = new FormData();
        fd.append('action', 'order_invoice_summary');
        fd.append('order_id', orderId);
        fetch('../config/config_operations.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                let totalInv = '';
                let paidInv = '';
                let saldoInv = '';
                let invoiceNumber = '';
                let details = '';
                if (data && data.success) {
                    invoiceNumber = data.invoice_number || '';
                    totalInv = (data.total_amount !== undefined) ? String(data.total_amount) : '';
                    paidInv = (data.paid_amount !== undefined) ? String(data.paid_amount) : '';
                    if (totalInv !== '' && paidInv !== '') {
                        const tNum = parseFloat(totalInv) || 0;
                        const pNum = parseFloat(paidInv) || 0;
                        saldoInv = String((tNum - pNum).toFixed(2));
                    }
                    details = (data.details || '').trim();
                }
                const formatMoney = (val) => {
                    const num = parseFloat(String(val).replace(/[^0-9.]/g, '')) || 0;
                    return currencySymbol + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                };
                const totalFmt = formatMoney(totalInv);
                const paidFmt = formatMoney(paidInv);
                const saldoFmt = formatMoney(saldoInv);
                let message = template
                    .replace(/{{cliente}}/g, clientName)
                    .replace(/{{factura}}/g, invoiceNumber)
                    .replace(/{{detalles}}/g, details)
                    .replace(/{{abono}}/g, paidFmt)
                    .replace(/{{total}}/g, totalFmt)
                    .replace(/{{saldo}}/g, saldoFmt)
                    .replace(/{{taller_nombre}}/g, companyName);
                document.getElementById('waMessage').value = normalizeEmoji(message);
            })
            .catch(() => {
                const formatMoney = (val) => {
                    const num = parseFloat(String(val).replace(/[^0-9.]/g, '')) || 0;
                    return currencySymbol + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                };
                let message = template
                    .replace(/{{cliente}}/g, clientName)
                    .replace(/{{factura}}/g, '')
                    .replace(/{{detalles}}/g, '')
                    .replace(/{{abono}}/g, formatMoney(''))
                    .replace(/{{total}}/g, formatMoney(''))
                    .replace(/{{saldo}}/g, formatMoney(''))
                    .replace(/{{taller_nombre}}/g, companyName);
                document.getElementById('waMessage').value = normalizeEmoji(message);
            });
        return;
    }
    const formatMoney = (val) => {
        const num = parseFloat(String(val).replace(/[^0-9.]/g, '')) || 0;
        return currencySymbol + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    };
    const costFmt = formatMoney(cost);
    const abonoFmt = formatMoney(abono);
    
    const saldo = parseFloat(cost) - parseFloat(abono);
    const saldoFmt = formatMoney(saldo);
    
    const displayOrderNo = orderPrefix + '-' + String(orderNumber || orderId).padStart(4, '0');
    
    let message = template
        .replace(/{{cliente}}/g, clientName)
        .replace(/{{cliente_tel}}/g, phone.replace(/[^0-9]/g, ''))
        .replace(/{{equipo}}/g, equipment)
        .replace(/{{marca}}/g, brand)
        .replace(/{{modelo}}/g, model)
        .replace(/{{sn}}/g, sn)
        .replace(/{{tipo}}/g, typeDev)
        .replace(/{{accesorios}}/g, accessories)
        .replace(/{{diagnostico}}/g, diagnosis)
        .replace(/{{solucion}}/g, solution)
        .replace(/{{falla}}/g, problem)
        .replace(/{{valor}}/g, costFmt)
        .replace(/{{total}}/g, costFmt)
        .replace(/{{abono}}/g, abonoFmt)
        .replace(/{{saldo}}/g, saldoFmt)
        .replace(/{{orden}}/g, displayOrderNo)
        .replace(/{{fecha_entrega}}/g, fechaEntrega)
        .replace(/{{url_seguimiento}}/g, urlSeg)
        .replace(/{{taller_nombre}}/g, companyName || 'Servicio Técnico')
        .replace(/{{taller_tel}}/g, companyPhone || 'N/A');
    message = ensureTypePresent(message, typeDev);
    document.getElementById('waMessage').value = message;
}

function sendWhatsAppFromModal() {
    let message = document.getElementById('waMessage').value;
    // Normalizar emojis si el contenido llegó corrupto
    message = normalizeEmoji(message);
    const phone = document.getElementById('waPhone').value.replace(/[^0-9]/g, '');
    const base = 'https://api.whatsapp.com/send';
    const params = new URLSearchParams();
    if (phone) params.set('phone', phone.replace(/[^0-9]/g, ''));
    params.set('text', message);
    const url = `${base}?${params.toString()}`;
    window.open(url, '_blank');
    const modal = bootstrap.Modal.getInstance(document.getElementById('whatsappModal'));
    if (modal) modal.hide();
}

function normalizeEmoji(text) {
    return String(text || '').replace(/\uFFFD/g, '');
}
function ensureTypePresent(text, typeVal) {
    if (!typeVal) return text;
    if (text.indexOf('Tipo:') !== -1) return text;
    const lines = text.split('\n');
    if (lines.length > 1) {
        lines.splice(1, 0, '\uD83D\uDCF1 Tipo: ' + typeVal);
    } else {
        lines.push('\uD83D\uDCF1 Tipo: ' + typeVal);
    }
    return lines.join('\n');
}
</script>

<?php
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
