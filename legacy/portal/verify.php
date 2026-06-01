<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$nocacheHeaders = [
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
    'Pragma: no-cache',
    'Expires: 0'
];
foreach ($nocacheHeaders as $h) {
    header($h);
}

$slug = isset($_GET['t']) ? trim($_GET['t']) : '';
$tenant_id = $slug ? getTenantIdFromSlug($slug) : null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
// Redirect pretty if numeric
if ($tenant_id && ctype_digit($slug)) {
    $pretty = getTenantPreferredSlug((int)$tenant_id);
    if ($pretty) {
        header('Location: ' . getSystemBaseUrl() . 'portal/verify.php?t=' . urlencode($pretty), true, 302);
        exit;
    }
}
if (!$tenant_id) {
    http_response_code(404);
    echo 'Portal no disponible';
    exit;
}
$portalTenantId = (int)$tenant_id;
$tenantValue = $perDatabase ? 1 : $portalTenantId;

if ($perDatabase && class_exists('DatabaseManager')) {
    try {
        $pdo = DatabaseManager::tenant($portalTenantId);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
    } catch (Throwable $e) {
        http_response_code(503);
        echo 'Portal no disponible';
        exit;
    }
}

$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
$hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
$order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_id) : 'ORD';

$mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'order');
$order_no = trim($_POST['order_no'] ?? ($_GET['order_no'] ?? ''));
$client_id = trim($_POST['client_id'] ?? ($_GET['client_id'] ?? ''));
$foundByCode = false;
$codeBelongsAnotherTenant = false;
$isCodeInput = ($order_no !== '' && preg_match('/[A-Za-z]/', (string)$order_no));

// Normalización de Nº de Orden: extraer dígitos
function normalize_order_id($val)
{
    $s = (string)$val;
    if ($s === '')
        return 0;
    if (preg_match_all('/\d+/', $s, $m) && !empty($m[0])) {
        $last = $m[0][count($m[0]) - 1];
        return $last ? (int)$last : 0;
    }
    return 0;
}

$order_id = 0;
$order = null;
try {
    if ($mode === 'order' && $order_no !== '') {
        $alnum = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order_no));
        if ($alnum !== '' && preg_match('/[A-Z]/', $alnum)) {
            $sql = "SELECT id, order_number, client_id, device_brand, device_model, reported_issue, status, approval_status, estimated_cost, created_at, verification_code 
                                   FROM work_orders 
                                   WHERE " . ((!$perDatabase && $hasTenantWorkOrders) ? "tenant_id = ? AND " : "") . "
                                     (verification_code = ? OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(verification_code,'-',''),' ','') , '.', ''), '/', ''), '_', '')) = ?) 
                                   LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $params = [$alnum, $alnum];
            if (!$perDatabase && $hasTenantWorkOrders) { array_unshift($params, $portalTenantId); }
            $stmt->execute($params);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $order_id = $order ? (int)$order['id'] : 0;
            if ($order) {
                $foundByCode = true;
            }
        }
        if (!$order) {
            $order_id = normalize_order_id($order_no);
            $lastNum = $order_id;
            $comboNum = 0;
            if (preg_match_all('/\d+/', (string)$order_no, $mm) && !empty($mm[0])) {
                $comboNum = (int)implode('', $mm[0]);
            }
            try {
                $sql = "SELECT id, order_number, client_id, device_brand, device_model, reported_issue, status, approval_status, estimated_cost, created_at, verification_code 
                                       FROM work_orders 
                                       WHERE " . ((!$perDatabase && $hasTenantWorkOrders) ? "tenant_id = ? AND " : "") . "(id = ? OR order_number = ? OR order_number = ?) 
                                       LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $params = [$lastNum, $lastNum, $comboNum];
                if (!$perDatabase && $hasTenantWorkOrders) { array_unshift($params, $portalTenantId); }
                $stmt->execute($params);
                $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            catch (Throwable $eInner) {
                $sql = "SELECT id, order_number, client_id, device_brand, device_model, reported_issue, status, approval_status, estimated_cost, created_at, verification_code FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$lastNum, $portalTenantId] : [$lastNum]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$perDatabase && $hasTenantWorkOrders && !$order && $alnum !== '' && preg_match('/[A-Z]/', $alnum)) {
                try {
                    $chk = $pdo->prepare("SELECT tenant_id FROM work_orders WHERE (verification_code = ? OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(verification_code,'-',''),' ','') , '.', ''), '/', ''), '_', '')) = ?) LIMIT 1");
                    $chk->execute([$alnum, $alnum]);
                    $tid = (int)($chk->fetchColumn() ?: 0);
                    if ($tid && $tid !== $portalTenantId) {
                        $codeBelongsAnotherTenant = true;
                    }
                }
                catch (Throwable $e) {
                }
            }
        }
    }
    elseif ($mode === 'id' && $client_id !== '') {
        $sql = "SELECT id, order_number, client_id, device_brand, device_model, reported_issue, status, approval_status, estimated_cost, created_at, verification_code 
                FROM work_orders 
                WHERE " . ((!$perDatabase && $hasTenantWorkOrders) ? "tenant_id = ? AND " : "") . "
                  client_id IN (SELECT id FROM clients WHERE " . ((!$perDatabase && $hasTenantClients) ? "tenant_id = ? AND " : "") . " (tax_id = ? OR id_number = ? OR phone = ?))
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $params = [$client_id, $client_id, $client_id];
        if (!$perDatabase && $hasTenantClients) { array_unshift($params, $portalTenantId); }
        if (!$perDatabase && $hasTenantWorkOrders) { array_unshift($params, $portalTenantId); }
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $order_id = $order ? (int)$order['id'] : 0;
    }
}
catch (Throwable $e) {
    $order = null;
}

$initial = ($order_no === '' && $client_id === '');
$notFound = (!$order && !$initial);
$invalidCode = ($isCodeInput && $notFound && !$codeBelongsAnotherTenant);

$company = [];
try {
    if (!$perDatabase && $hasTenantCompany) {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$portalTenantId]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: [];
}
catch (Throwable $e) {
}

$cfg = ['client_portal_show_timeline' => '1', 'client_portal_allow_approval' => '1', 'client_portal_enable_lookup_by_id' => '0'];
try {
    if (!$perDatabase && $hasTenantSystem) {
        $s = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ? AND config_key IN ('client_portal_show_timeline','client_portal_allow_approval','client_portal_enable_lookup_by_id')");
        $s->execute([$portalTenantId]);
    } else {
        $s = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('client_portal_show_timeline','client_portal_allow_approval','client_portal_enable_lookup_by_id')");
        $s->execute([]);
    }
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $cfg[$row['config_key']] = (string)$row['config_value'];
    }
}

catch (Throwable $e) {
}
// No persistimos acceso en sesión para forzar re-verificación al volver atrás
$hasFullAccess = false;
$history = [];
try {
    $hasTenantHist = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_status_history LIKE 'tenant_id'");
        $hasTenantHist = ($c && $c->rowCount() > 0);
    } catch (Throwable $__) {}
    if ($hasTenantHist && !$perDatabase) {
        $stmtHist = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? AND tenant_id = ? ORDER BY created_at ASC");
        $stmtHist->execute([$order_id, $tenantValue]);
    } else {
        $stmtHist = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
        $stmtHist->execute([$order_id]);
    }
    $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
catch (Throwable $e) {
}

$nameBySlug = [
    'pending' => 'Pendiente',
    'received' => 'Recibido',
    'diagnosing' => 'Diagnosticando',
    'approved' => 'Aprobado',
    'waiting_parts' => 'Esperando Repuestos',
    'repairing' => 'Reparando',
    'testing' => 'Pruebas',
    'completed' => 'Completado',
    'ready' => 'Listo para Entrega',
    'delivered' => 'Entregado',
    'cancelled' => 'Cancelado',
    'rejected' => 'Rechazado',
    'devolucion' => 'Devolución',
    'cancelado' => 'Cancelado',
    'entregado' => 'Entregado'
];
$emojiBySlug = [
    'pending' => '⏳',
    'received' => '📦',
    'diagnosing' => '🔍',
    'approved' => '✅',
    'waiting_parts' => '⏸️',
    'repairing' => '🔧',
    'testing' => '🧪',
    'completed' => '✅',
    'ready' => '🏁',
    'delivered' => '🚚',
    'cancelled' => '❌',
    'rejected' => '❌',
    'devolucion' => '↩️',
    'cancelado' => '❌',
    'entregado' => '🚚'
];
$statusMap = [];
try {
    $hasTenantStatuses = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        $hasTenantStatuses = ($c && $c->rowCount() > 0);
    } catch (Throwable $__) {}
    if ($hasTenantStatuses && !$perDatabase) {
        $sql = "SELECT slug, name, emoji FROM order_statuses WHERE is_active = 1 AND tenant_id = ?
                UNION ALL
                SELECT slug, name, emoji FROM order_statuses WHERE is_active = 1 AND (tenant_id IS NULL OR tenant_id = 0)
                AND slug NOT IN (SELECT slug FROM order_statuses WHERE is_active = 1 AND tenant_id = ?)";
        $st = $pdo->prepare($sql);
        $st->execute([$tenantValue, $tenantValue]);
    } else {
        $st = $pdo->prepare("SELECT slug, name, emoji FROM order_statuses WHERE is_active = 1");
        $st->execute();
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $s = strtolower(trim($r['slug'] ?? ''));
        if ($s !== '') {
            $statusMap[$s] = ['name' => (string)$r['name'], 'emoji' => (string)($r['emoji'] ?? '')];
        }
    }
}
catch (Throwable $e) {
}
$statusStyles = [
    'pending' => ['icon' => 'fa-hourglass-half', 'color' => '#64748b'],
    'received' => ['icon' => 'fa-box', 'color' => '#0ea5e9'],
    'diagnosing' => ['icon' => 'fa-magnifying-glass', 'color' => '#8b5cf6'],
    'approved' => ['icon' => 'fa-circle-check', 'color' => '#10b981'],
    'waiting_parts' => ['icon' => 'fa-pause', 'color' => '#f59e0b'],
    'repairing' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#2563eb'],
    'testing' => ['icon' => 'fa-flask', 'color' => '#06b6d4'],
    'completed' => ['icon' => 'fa-circle-check', 'color' => '#22c55e'],
    'ready' => ['icon' => 'fa-flag-checkered', 'color' => '#14b8a6'],
    'delivered' => ['icon' => 'fa-truck', 'color' => '#0ea5e9'],
    'cancelled' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
    'esperando_aprobacion' => ['icon' => 'fa-pen-to-square', 'color' => '#f59e0b'],
    'aprobado' => ['icon' => 'fa-circle-check', 'color' => '#10b981'],
    'rechazado' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
    'pendiente' => ['icon' => 'fa-hourglass-half', 'color' => '#64748b'],
    'asignado' => ['icon' => 'fa-box', 'color' => '#0ea5e9'],
    'diagnosticando' => ['icon' => 'fa-magnifying-glass', 'color' => '#8b5cf6'],
    'esperando_repuestos' => ['icon' => 'fa-pause', 'color' => '#f59e0b'],
    'reparando' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#2563eb'],
    'testeando' => ['icon' => 'fa-flask', 'color' => '#06b6d4'],
    'completado' => ['icon' => 'fa-circle-check', 'color' => '#22c55e'],
    'entregado' => ['icon' => 'fa-truck', 'color' => '#0ea5e9'],
    'cancelado' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444']
];

function hexToRgba($hex, $alpha = 0.15) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) {
        $r = hexdec(str_repeat($hex[0], 2));
        $g = hexdec(str_repeat($hex[1], 2));
        $b = hexdec(str_repeat($hex[2], 2));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    $alpha = max(0, min(1, (float)$alpha));
    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
}

$status_slug_current = getEffectiveStatusSlug(($order['status'] ?? ''), ($order['approval_status'] ?? ''));
$status_label = isset($statusMap[$status_slug_current]) 
    ? ($statusMap[$status_slug_current]['name'] ?? 'Pendiente') 
    : getStatusText($status_slug_current);
// Intentar resolver por historial si no se obtuvo etiqueta válida o slug desconocido
if ($status_label === '' || !isset($statusStyles[$status_slug_current])) {
    if (!empty($history)) {
        $last = $history[count($history) - 1];
        $altSlug = normalizeStatusSlug(strtolower(trim($last['status'] ?? '')));
        if ($altSlug !== '') {
            $status_slug_current = $altSlug;
            $status_label = isset($statusMap[$altSlug]) ? ($statusMap[$altSlug]['name'] ?? $last['status']) : ($last['status'] ?? 'Pendiente');
        }
    }
}
$badgeHex = $statusStyles[$status_slug_current]['color'] ?? '#f59e0b';
$badgeBg = hexToRgba($badgeHex, 0.14);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
$company_name = htmlspecialchars($company['company_name'] ?? 'Portal de Clientes');
$order_title = $order_id ? (htmlspecialchars($order_prefix) . '-' . str_pad((isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order_id), 6, '0', STR_PAD_LEFT)) : 'Portal de Clientes';
echo $company_name . ' | ' . $order_title;
?></title>
    <link rel="icon" type="image/png" href="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo htmlspecialchars($company['company_logo'] ?? 'logo.png'); ?>">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --primary-light: #e0f2fe;
            --text-main: #2b3445;
            --text-muted: #7d879c;
            --bg-color: #f3f6f9;
        }

        body { 
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color); 
            background-image: radial-gradient(circle at top right, rgba(13, 110, 253, 0.05) 0%, transparent 40%),
                              radial-gradient(circle at bottom left, rgba(13, 110, 253, 0.08) 0%, transparent 40%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
        }

        /* Contenedor Principal Animado */
        .portal-container {
            max-width: 600px;
            margin: 0 auto;
            animation: fadeInUP 0.6s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }

        @keyframes fadeInUP {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Tarjetas estilo App */
        .card { 
            border: none; 
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.04), 0 5px 15px rgba(0,0,0,0.02); 
            border-radius: 24px; 
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 20px 40px rgba(0,0,0,0.06), 0 5px 15px rgba(0,0,0,0.03); 
        }

        /* Badges de Estado */
        .badge-state { 
            background: var(--primary-light); 
            color: var(--primary-color); 
            border: 1px solid rgba(13, 110, 253, 0.1); 
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }

        /* Botones */
        .btn-outline-primary, .btn-outline-secondary { border-radius: 12px; font-weight: 500; }
        .btn-primary { border-radius: 12px; font-weight: 500; }
        .btn-lg { 
            border-radius: 16px; 
            font-weight: 600; 
            padding: 14px 24px;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.2);
            transition: all 0.3s ease;
        }
        .btn-lg:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
        }

        /* Formularios */
        .form-control-lg {
            border-radius: 16px;
            padding: 14px 20px;
            border: 2px solid #eef2f6;
            background-color: #f8fafc;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .form-control-lg:focus {
            border-color: rgba(13, 110, 253, 0.4);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
        }
        .form-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Cabecera de la Orden */
        .header-order { 
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f6 100%); 
            border-radius: 18px; 
            padding: 16px 20px; 
            border: 1px solid rgba(255,255,255,0.8);
        }
        
        /* Detalles de la Orden */
        .order-detail-row {
            display: flex;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px dashed #eef2f6;
        }
        .order-detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .detail-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-right: 16px;
            flex-shrink: 0;
        }
        .detail-content strong {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .detail-content span {
            font-weight: 500;
            font-size: 1.05rem;
            color: var(--text-main);
        }

        /* Línea de tiempo rediseñada */
        .timeline-modern {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }
        .timeline-modern::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 10px;
            bottom: 20px;
            width: 2px;
            background: #eef2f6;
            border-radius: 2px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 24px;
            padding-bottom: 4px;
        }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-icon {
            position: absolute;
            left: -30px;
            top: 0;
            width: 30px;
            height: 30px;
            background: #fff;
            border: 2px solid #eef2f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            z-index: 1;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .timeline-item.active .timeline-icon {
            border-color: var(--primary-color);
            background: var(--primary-light);
        }
        .timeline-content {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-left: 15px;
            border: 1px solid #eef2f6;
            transition: transform 0.2s;
        }
        .timeline-content:hover {
            transform: translateX(4px);
            border-color: rgba(13, 110, 253, 0.2);
        }
        .timeline-time {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Logo y Header de la Empresa */
        .company-header {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInUP 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }
        .company-logo {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            object-fit: contain;
            background: #fff;
            border: 2px solid #fff;
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            margin-bottom: 12px;
            padding: 4px;
        }
        .company-name {
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="portal-container">
            <!-- Company Header -->
            <div class="company-header">
                <img src="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo htmlspecialchars($company['company_logo'] ?? 'logo.png'); ?>" alt="Logo" class="company-logo" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='<?php echo getSystemBaseUrl(); ?>assets/img/logo.png';">
                <div class="company-name"><?php echo htmlspecialchars($company['company_name'] ?? 'Portal de Clientes'); ?></div>
                <div class="text-muted small mt-1">Consulta tu orden</div>
            </div>

            <?php if ($order): ?>
            <!-- Vista de Orden Encontrada -->
            <div class="card p-4 p-md-5 mb-4">
                <!-- Botón Volver -->
                <div class="mb-3 text-start">
                    <a href="<?php echo htmlspecialchars($slug); ?>" class="btn btn-sm btn-light text-muted fw-bold shadow-sm" style="border-radius: 12px;">
                        <i class="fa-solid fa-arrow-left me-2"></i>Volver
                    </a>
                </div>
                <div class="d-flex justify-content-between align-items-center header-order mb-4">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-1">Orden de Servicio</div>
                        <div class="h4 mb-0 fw-bold"><?php echo htmlspecialchars($order_prefix); ?>-<?php echo str_pad((isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order_id), 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                    <span class="badge badge-state" style="color: <?php echo htmlspecialchars($badgeHex); ?>; background: <?php echo htmlspecialchars($badgeBg); ?>; border-color: <?php echo htmlspecialchars($badgeHex); ?>;">
                        <?php echo htmlspecialchars($status_label); ?>
                    </span>
                </div>
                <?php if ($foundByCode): ?>
                <div class="d-flex justify-content-end mb-3">
                    <a class="btn btn-outline-secondary btn-sm rounded-pill d-inline-flex align-items-center" href="receipt.php?t=<?php echo urlencode($slug); ?>&order_id=<?php echo (int)$order_id; ?>">
                        <i class="fa-solid fa-file-pdf me-2"></i>Descargar Comprobante
                    </a>
                </div>
                <?php endif; ?>
                <?php if (!$foundByCode): ?>
                <div class="text-muted small mt-2">
                    <i class="fa-solid fa-lock me-1 opacity-75"></i>
                    Para ver historial y presupuesto, ingresa tu código de verificación.
                </div>
                <?php
    endif; ?>
                
                <div class="order-details mt-4">
                    <div class="order-detail-row">
                        <div class="detail-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                        <div class="detail-content">
                            <strong>Equipo</strong>
                            <span><?php echo htmlspecialchars($order['device_brand'] . ' ' . ($order['device_model'] ?? '')); ?></span>
                        </div>
                    </div>
                    
                    <div class="order-detail-row">
                        <div class="detail-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div class="detail-content">
                            <strong>Problema Reportado</strong>
                            <span><?php echo htmlspecialchars($order['reported_issue'] ?? 'No especificado'); ?></span>
                        </div>
                    </div>
                    
                    <div class="order-detail-row">
                        <div class="detail-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                        <div class="detail-content">
                            <strong>Costo Estimado</strong>
                            <?php if ($foundByCode): ?>
                                <span class="text-success fw-bold"><?php echo CompanySettings::formatCurrency($order['estimated_cost'] ?? 0); ?></span>
                            <?php
    else: ?>
                                <span class="text-muted">Disponible tras verificación</span>
                            <?php
    endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (($cfg['client_portal_allow_approval'] ?? '1') === '1'): ?>
                    <hr class="my-4" style="border-color: #eef2f6; opacity: 1;">
                    
                    <?php if ($foundByCode): ?>
                        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success rounded-4 p-3 d-flex align-items-center mb-0">
                            <i class="fa-solid fa-circle-check fs-4 me-3"></i>
                            <div>
                                <strong class="d-block mb-1">Código Verificado</strong>
                                <span class="small">Redirigiendo a las opciones de aprobación...</span>
                            </div>
                        </div>
                        <form id="autoApproveForm" class="d-none" method="POST" action="approve.php?t=<?php echo urlencode($slug); ?>">
                            <input type="hidden" name="order_id" value="<?php echo (int)$order_id; ?>">
                            <input type="hidden" name="verification_code" value="<?php echo htmlspecialchars(strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order_no))); ?>">
                        </form>
                        <script>setTimeout(function(){ try{document.getElementById('autoApproveForm').submit();}catch(e){} }, 800);</script>
                    <?php
        else: ?>
                        <div class="p-4 bg-light rounded-4 border" style="border-color: #eef2f6 !important;">
                            <h6 class="fw-bold mb-3"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Verificación</h6>
                            <p class="text-muted small mb-3">Ingresá la cédula completa del cliente o el código del comprobante.</p>
                            
                            <form method="POST" action="approve.php?t=<?php echo urlencode($slug); ?><?php echo (isset($_GET['debug']) && $_GET['debug'] === '1') ? '&debug=1' : ''; ?>">
                                <input type="hidden" name="order_id" value="<?php echo (int)$order_id; ?>">
                                <input type="hidden" name="order_no" id="hidden_order_no" value="<?php echo htmlspecialchars((string)($order['order_number'] ?? '')); ?>">
                                <input type="hidden" name="verification_code" id="hidden_verification_code">
                                <input type="hidden" name="id_full" id="hidden_id_full">
                                <div class="mb-3">
                                    <input type="text" id="verify_input" class="form-control form-control-lg text-center" placeholder="Cédula del cliente o código del comprobante" style="letter-spacing: 0.5px;">
                                </div>
                                <button class="btn btn-primary w-100" type="submit" style="border-radius: 12px; padding: 12px; font-weight: 600;">
                                    Verificar identidad y continuar <i class="fa-solid fa-arrow-right ms-2"></i>
                                </button>
                            </form>
                            <script>
                                (function(){
                                    var form = document.currentScript && document.currentScript.previousElementSibling && document.currentScript.previousElementSibling.tagName === 'FORM'
                                        ? document.currentScript.previousElementSibling : null;
                                    if (!form) form = document.querySelector('form[action^="approve.php"]');
                                    if (!form) return;
                                    form.addEventListener('submit', function(e){
                                        var val = (form.querySelector('#verify_input')?.value || '').trim();
                                        var idFull = '';
                                        var code = '';
                                        var digits = val.replace(/\D/g, '');
                                        // Si el texto contiene solo dígitos y separadores (no letras) y tiene suficientes dígitos, tratar como cédula
                                        var onlyDigitsAndSeparators = /^[\d\s\-._/]+$/.test(val);
                                        if (onlyDigitsAndSeparators && digits.length >= 5) {
                                            idFull = digits;
                                        } else {
                                            code = val.toUpperCase().replace(/[^A-Z0-9]/g,'');
                                        }
                                        form.querySelector('#hidden_verification_code').value = code;
                                        form.querySelector('#hidden_id_full').value = idFull;
                                        // preservamos order_no por si el id no se resuelve en el servidor
                                        if (!code && !idFull) {
                                            e.preventDefault();
                                            try { form.querySelector('#verify_input').focus(); } catch (err) {}
                                        }
                                    });
                                })();
                            </script>
                        </div>
                    <?php
        endif; ?>
                <?php
    else: ?>
                    <div class="alert alert-info border-0 rounded-4 p-3 d-flex align-items-center mt-4 mb-0">
                        <i class="fa-solid fa-circle-info fs-4 me-3"></i>
                        <span class="small">Para aprobar o rechazar presupuestos, por favor comunícate directamente con nuestro equipo.</span>
                    </div>
                <?php
    endif; ?>
            </div>

            <?php if (($cfg['client_portal_show_timeline'] ?? '1') === '1' && $foundByCode): ?>
            <div class="card p-4 p-md-5">
                <h5 class="fw-bold mb-1"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Historial de la Orden</h5>
                <p class="text-muted small mb-4">Avance de tu equipo paso a paso.</p>
                
                <?php if (empty($history)): ?>
                    <div class="text-center py-4 bg-light rounded-4 text-muted">Aún no hay movimientos registrados para esta orden.</div>
                <?php
        else: ?>
                    <div class="timeline-modern">
                        <?php
            $total = count($history);
            foreach ($history as $index => $h):
                $isLast = ($index === $total - 1);
                $stSlug = normalizeStatusSlug(strtolower(trim($h['status'] ?? '')));
                $label = isset($statusMap[$stSlug])
                    ? ($statusMap[$stSlug]['name'] ?? getStatusText($stSlug))
                    : getStatusText($stSlug);
                $style = $statusStyles[$stSlug] ?? ['icon' => 'fa-circle-dot', 'color' => '#0d6efd'];
                $clr = $style['color'];
                $icon = $style['icon'];
                $when = formatCompanyDate($h['created_at'] ?? '', true);
                $note = trim((string)($h['notes'] ?? ''));
?>
                        <div class="timeline-item <?php echo $isLast ? 'active' : ''; ?>">
                            <div class="timeline-icon" style="border-color: <?php echo htmlspecialchars($clr); ?>; background: #fff;">
                                <i class="fa-solid <?php echo htmlspecialchars($icon); ?>" style="color: <?php echo htmlspecialchars($clr); ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-bold" style="color: <?php echo $isLast ? 'var(--primary-color)' : 'var(--text-main)'; ?>;">
                                    <?php echo htmlspecialchars($label); ?>
                                </div>
                                <?php if ($note !== ''): ?>
                                <div class="text-muted small mt-2 bg-white p-2 rounded-3 border" style="border-color:#eef2f6;">
                                    <i class="fa-solid fa-quote-left me-1 opacity-50"></i> <?php echo nl2br(htmlspecialchars($note)); ?>
                                </div>
                                <?php
                endif; ?>
                                <div class="timeline-time">
                                    <i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars($when); ?>
                                </div>
                            </div>
                        </div>
                        <?php
            endforeach; ?>
                    </div>
                <?php
        endif; ?>
            </div>
            <?php
    elseif (($cfg['client_portal_show_timeline'] ?? '1') === '1'): ?>
            <div class="card p-4 p-md-5">
                <h5 class="fw-bold mb-1"><i class="fa-solid fa-lock text-warning me-2"></i>Historial de la Orden</h5>
                <p class="text-muted small mb-0">Ingresa tu código para ver el historial completo.</p>
            </div>
            <?php
    endif; ?>

            <?php
else: ?>
            <!-- Vista de Búsqueda -->
            <div class="card p-4 p-md-5 text-center">
                <!-- Botón Volver (Dentro de la tarjeta) -->
                <div class="text-start mb-2">
                    <a href="<?php echo htmlspecialchars($slug); ?>" class="btn btn-sm btn-light text-muted fw-bold shadow-sm" style="border-radius: 12px;">
                        <i class="fa-solid fa-arrow-left me-2"></i>Volver al Inicio
                    </a>
                </div>

                <div class="mb-4">
                    <div class="d-inline-flex justify-content-center align-items-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <h3 class="fw-bold mb-2">Consulta tu orden</h3>
                </div>

                <?php if ($notFound): ?>
                    <div class="alert alert-danger border-0 rounded-4 d-flex align-items-center text-start p-3 mb-4">
                        <i class="fa-solid fa-circle-exclamation fs-4 me-3"></i>
                        <div>
                            <?php if ($invalidCode && !$codeBelongsAnotherTenant): ?>
                                <strong>Código inválido.</strong>
                                <div class="small mt-1">El código de verificación no coincide. Revisa tu comprobante e intenta nuevamente.</div>
                            <?php
        else: ?>
                                <strong>No pudimos encontrar tu orden.</strong>
                                <div class="small mt-1">Verifica que los datos ingresados sean correctos e intenta nuevamente.</div>
                            <?php
        endif; ?>
                        </div>
                    </div>
                    <?php if ($codeBelongsAnotherTenant): ?>
                    <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center text-start p-3 mb-4 mt-2">
                        <i class="fa-solid fa-triangle-exclamation fs-4 me-3"></i>
                        <span class="small">El código ingresado pertenece a otra empresa. Verifica si accediste al portal correcto a través del enlace que recibiste.</span>
                    </div>
                    <?php
        endif; ?>
                <?php
    endif; ?>

                

                <form id="lookupForm" method="POST" action="verify.php?t=<?php echo urlencode($slug); ?>" class="text-start">
                    <input type="hidden" name="mode" id="mode" value="order">
                    
                    <div class="mb-4" id="order-input">
                        <label class="form-label ms-1">Orden o Código</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted ps-4" style="border-radius: 16px 0 0 16px; border: 2px solid #eef2f6;"><i class="fa-solid fa-hashtag"></i></span>
                            <input name="order_no" type="text" class="form-control form-control-lg border-start-0 ps-2" placeholder="Ej: <?php echo htmlspecialchars($order_prefix); ?>-123 o ABCD23" inputmode="text" style="border-radius: 0 16px 16px 0;">
                        </div>
                    </div>
                    
                    <?php if (($cfg['client_portal_enable_lookup_by_id'] ?? '0') === '1'): ?>
                    <div class="mb-4" id="id-input">
                        <label class="form-label ms-1">Tu Documento de Identidad</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted ps-4" style="border-radius: 16px 0 0 16px; border: 2px solid #eef2f6;"><i class="fa-solid fa-id-card"></i></span>
                            <input name="client_id" type="text" class="form-control form-control-lg border-start-0 ps-2" placeholder="Ej: 12345678" style="border-radius: 0 16px 16px 0;">
                        </div>
                    </div>
                    <?php
    endif; ?>

                    <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">
                        Consultar Estado <i class="fa-solid fa-arrow-right ms-2"></i>
                    </button>
                    
                    <!-- Texto auxiliar ocultado para simplificar interfaz móvil -->
                </form>
            </div>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('lookupForm');
                var mode = document.getElementById('mode');
                var orderInput = document.querySelector('#order-input input[name="order_no"]');
                var idInput = document.querySelector('#id-input input[name="client_id"]');
                if (form) {
                    form.addEventListener('submit', function(e){
                        var orderVal = orderInput ? (orderInput.value || '').trim() : '';
                        var idVal = idInput ? (idInput.value || '').trim() : '';
                        if (!orderVal && !idVal) {
                            e.preventDefault();
                            try {
                                if (orderInput) { orderInput.focus(); }
                                else if (idInput) { idInput.focus(); }
                            } catch (err) {}
                            return;
                        }
                        if (orderVal) {
                            mode.value = 'order';
                            if (orderInput) orderInput.setAttribute('required','required');
                            if (idInput) idInput.removeAttribute('required');
                        } else {
                            mode.value = 'id';
                            if (idInput) idInput.setAttribute('required','required');
                            if (orderInput) orderInput.removeAttribute('required');
                        }
                    });
                }
            });
            </script>
            
            <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
            <div class="card p-3 mt-4 border border-warning">
                <div class="small fw-bold text-warning mb-2"><i class="fa-solid fa-bug me-1"></i>Modo Diagnóstico</div>
                <div class="small text-muted font-monospace bg-light p-2 rounded border">Tenant: <?php echo (int)$tenant_id; ?> | Valor: <?php echo htmlspecialchars($order_no); ?> | Normalizado: <?php echo htmlspecialchars(strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$order_no))); ?></div>
                <div class="small text-muted font-monospace bg-light p-2 rounded border mt-1">Encontrado por código: <?php echo $foundByCode ? 'SÍ' : 'NO'; ?> | Otro tenant detectado: <?php echo $codeBelongsAnotherTenant ? 'SÍ' : 'NO'; ?></div>
            </div>
            <?php
    endif; ?>
            
            <?php
endif; ?>
            
            <div class="text-center mt-5 mb-4 opacity-75">
                <a href="../index.php" class="text-decoration-none text-muted small d-inline-flex align-items-center">
                    <img src="<?php echo getSystemBaseUrl(); ?>assets/img/system_logo.png" alt="Core" style="height: 16px; filter: grayscale(1);" class="me-2 opacity-50">
                    Desarrollado con <strong class="ms-1">Core</strong>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
