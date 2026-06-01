<?php
/**
 * Impresión de Orden de Servicio
 */

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../includes/print_system.php';
require_once '../config/company_settings.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

$pdo = db();
// Inicializar sistema de impresión
$print_system = new PrintSystem($pdo);

// Verificar que el usuario esté logueado, salvo modo vista previa
if (!(isset($_GET['preview']) && $_GET['preview'] === '1')) {
    requireLogin();
}

// Verificar parámetros
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('ID de orden inválido');
}

$order_id = (int)$_GET['id'];

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        if ($amount === null || $amount === '') return 'No especificado';
        if (class_exists('CompanySettings')) {
            $currencyConfig = CompanySettings::getCurrency();
            return $currencyConfig['symbol'] . ' ' . number_format((float)$amount, 0, '.', ',');
        }
        return '$ ' . number_format((float)$amount, 0, '.', ',');
    }
}

// Activar visualización de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Obtener datos de la orden
try {
    $order = $print_system->getWorkOrderData($order_id);
    
    // Depuración - Mostrar datos recibidos
    if (isset($_GET['debug']) && $_GET['debug'] == 1) {
        echo '<pre>';
        print_r($order);
        echo '</pre>';
    }
    
    if (!$order) {
        echo '<div class="alert alert-danger">
                <h4>Error al cargar la orden</h4>
                <p>No se pudo encontrar la orden solicitada o hubo un error en la base de datos.</p>
                <p><a href="../orders/index.php" class="btn btn-primary">Volver a órdenes</a></p>
              </div>';
        exit;
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">
            <h4>Error al procesar la orden</h4>
            <p>Detalles del error: ' . $e->getMessage() . '</p>
            <p><a href="../orders/index.php" class="btn btn-primary">Volver a órdenes</a></p>
          </div>';
    exit;
}

// Formatear fecha
$order_date = isset($order['created_at']) ? date('d/m/Y', strtotime($order['created_at'])) : date('d/m/Y');
$delivery_date = isset($order['delivery_date']) && $order['delivery_date'] ? date('d/m/Y', strtotime($order['delivery_date'])) : 'No especificada';

// NUEVO: formatos adicionales y variables para diseño tipo Samii
$created_datetime = isset($order['created_at']) ? date('m/d/Y h:i:s A', strtotime($order['created_at'])) : date('m/d/Y h:i:s A');
$secondary_date = isset($order['estimated_completion']) && $order['estimated_completion']
    ? date('m/d/Y', strtotime($order['estimated_completion']))
    : (isset($order['delivery_date']) && $order['delivery_date'] ? date('m/d/Y', strtotime($order['delivery_date'])) : '');

$currency_config = CompanySettings::getCurrency();
$currency_symbol = $currency_config['symbol'];

// Configuración dinámica de garantía desde system_config
$warranty_days = (int) CompanySettings::get('warranty_days', 30);
$abandon_days = (int) CompanySettings::get('abandon_days', 60);
$warranty_custom_text = CompanySettings::get('warranty_text', '');
$warranty_disclaimers_raw = CompanySettings::get('warranty_disclaimers', '');
$warranty_disclaimers = [];
if (!empty($warranty_disclaimers_raw)) {
    // Separar por salto de línea o punto y coma
    $parts = preg_split('/\r\n|\n|;/', $warranty_disclaimers_raw);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') { $warranty_disclaimers[] = $p; }
    }
}

$unit_price = isset($order['final_cost']) && $order['final_cost'] !== null ? (float)$order['final_cost']
    : (isset($order['estimated_cost']) ? (float)$order['estimated_cost'] : (isset($order['total_cost']) ? (float)$order['total_cost'] : 0));
$advance_amount = isset($order['advance_payment']) ? (float)$order['advance_payment'] : 0.0; // Si no existe, mostrará 0.00
$passcode = $order['device_passcode'] ?? $order['passcode'] ?? $order['unlock_code'] ?? '';

// QR dinámico del portal de clientes por tenant usando prefijo y número visible
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$current_tenant_id = getCurrentTenantId();
$tenantValue = $perDatabase ? 1 : (int)$current_tenant_id;
$tenant_for_qr = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$current_tenant_id;
$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantCompanyConfig = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
$hasTenantEquipmentAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'equipment_accessories') : false;
$hasTenantOrderEquipmentAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_equipment_accessories') : false;
$baseUrl = getSystemBaseUrl();
$slugQr = getTenantPreferredSlug($tenant_for_qr) ?? (string)$tenant_for_qr;
$prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_for_qr) : 'ORD';
$visibleNum = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order_id;
$orderNoDisplay = $prefix . '-' . str_pad($visibleNum, 4, '0', STR_PAD_LEFT);
$qr_link = $baseUrl . 'portal/verify.php?t=' . urlencode($slugQr) . '&order_no=' . urlencode($orderNoDisplay);
$qr_img_src = 'https://api.qrserver.com/v1/create-qr-code/'
    . '?format=svg'
    . '&ecc=H'
    . '&margin=0'
    . '&size=180x180'
    . '&data=' . urlencode($qr_link);

// Código de verificación para portal cliente
$verification_code = '';
try {
    if (isset($order['verification_code']) && $order['verification_code']) {
        $verification_code = $order['verification_code'];
    } else {
        if (!$perDatabase && $hasTenantWorkOrders) {
            $stmtV = $pdo->prepare("SELECT verification_code FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtV->execute([$order_id, $tenantValue]);
        } else {
            $stmtV = $pdo->prepare("SELECT verification_code FROM work_orders WHERE id = ? LIMIT 1");
            $stmtV->execute([$order_id]);
        }
        $verification_code = (string)($stmtV->fetchColumn() ?: '');
    }
    $verification_code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $verification_code));
    if ($verification_code === '') {
        $verification_code = generateVerificationCode(6);
        if (!$perDatabase && $hasTenantWorkOrders) {
            $upd = $pdo->prepare("UPDATE work_orders SET verification_code = ? WHERE id = ? AND tenant_id = ? LIMIT 1");
            $upd->execute([$verification_code, $order_id, $tenantValue]);
        } else {
            $upd = $pdo->prepare("UPDATE work_orders SET verification_code = ? WHERE id = ? LIMIT 1");
            $upd->execute([$verification_code, $order_id]);
        }
    }
} catch (Throwable $e) { $verification_code = ''; }

// Obtener datos de la empresa y rutas de logos
$company = [];
$company_logo_path = '';
$company_source = 'config';
try {
    if (!$perDatabase && $hasTenantCompanyConfig) {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo, company_phone, company_email, company_website, company_address FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$tenantValue]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo, company_phone, company_email, company_website, company_address FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $company = [];
}
$company_logo_path = 'assets/img/' . (!empty($company['company_logo']) ? $company['company_logo'] : 'logo.png') . '?v=' . time();

// Obtener logo de la marca del equipo (normalizado por nombre) con tenant
$brand_logo = '';
if (!empty($order['device_brand'])) {
    try {
        if (!$perDatabase && $hasTenantBrands) {
            $stmtBrand = $pdo->prepare("SELECT logo FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(:brand)) AND tenant_id = :tenant LIMIT 1");
            $stmtBrand->execute([':brand' => trim($order['device_brand']), ':tenant' => $tenantValue]);
        } else {
            $stmtBrand = $pdo->prepare("SELECT logo FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(:brand)) LIMIT 1");
            $stmtBrand->execute([':brand' => trim($order['device_brand'])]);
        }
        $brandRow = $stmtBrand->fetch(PDO::FETCH_ASSOC);
        if ($brandRow && !empty($brandRow['logo'])) {
            $brand_logo = $brandRow['logo'];
        }
    } catch (Exception $e) {}
}

// NUEVO: accesorios recibidos y fotos del equipo
$accessories_received = [];
try {
    if (!$perDatabase && $hasTenantEquipmentAccessories && $hasTenantOrderEquipmentAccessories) {
        $stmtAcc = $pdo->prepare("
            SELECT ea.name
            FROM order_equipment_accessories oea
            JOIN equipment_accessories ea ON oea.accessory_id = ea.id
            WHERE oea.order_id = ? AND oea.is_included = 1 AND ea.tenant_id = ? AND oea.tenant_id = ?
            ORDER BY ea.sort_order ASC, ea.name ASC
        ");
        $stmtAcc->execute([$order_id, $tenantValue, $tenantValue]);
    } else {
        $stmtAcc = $pdo->prepare("
            SELECT ea.name
            FROM order_equipment_accessories oea
            JOIN equipment_accessories ea ON oea.accessory_id = ea.id
            WHERE oea.order_id = ? AND oea.is_included = 1
            ORDER BY ea.sort_order ASC, ea.name ASC
        ");
        $stmtAcc->execute([$order_id]);
    }
    $accessories_received = $stmtAcc->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $accessories_received = [];
}

$device_photos = [];
// Permitir selección manual desde la vista
if (!empty($_POST['selected_photos'])) {
    $sel = $_POST['selected_photos'];
    if (is_string($sel)) {
        $tmp = json_decode($sel, true);
        $sel = is_array($tmp) ? $tmp : [$sel];
    }
    if (is_array($sel)) {
        $device_photos = array_values(array_filter($sel, function($p) { return is_string($p) && trim($p) !== ''; }));
    }
}
// Fallback: usar todas las fotos guardadas en la orden
if (empty($device_photos) && !empty($order['device_photo'])) {
    $decoded = json_decode($order['device_photo'], true);
    if (is_array($decoded)) {
        $device_photos = array_filter($decoded, function($p) { return is_string($p) && trim($p) !== ''; });
    }
}
// Generar token CSRF para edición de distribución
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Cargar layout guardado (system_config -> work_order_layout)
$work_order_layout_json = CompanySettings::get('work_order_layout', '');
$work_order_layout = [];
if (!empty($work_order_layout_json)) {
    $decoded_layout = json_decode($work_order_layout_json, true);
    if (is_array($decoded_layout)) {
        $work_order_layout = $decoded_layout;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>
        <?php
            $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
            $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
            echo htmlspecialchars($prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT));
        ?>
    </title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <!-- Incluir Bootstrap para mejorar la apariencia -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ====== Estilo tipo Samii ====== */
        body { font-family: Arial, sans-serif; margin: 0; color: #2b2b2b; }
        .print-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
        .brand-block { display: flex; align-items: center; gap: 15px; }
        .brand-info-group { display: flex; align-items: flex-start; gap: 15px; }
        .brand-logo { width: 90px; height: 90px; border-radius: 12px; background: #f0f0f0; object-fit: contain; } /* Aumentado */
        .brand-name { font-size: 28px; font-weight: 800; line-height: 1.1; } /* Aumentado */
        .brand-inline { display: inline-flex; align-items: center; gap: 6px; }
        .brand-logo-center { display: flex; justify-content: center; align-items: center; padding: 6px 0; }
        .brand-logo-center img { width: 70px; height: 70px; border-radius: 10px; object-fit: contain; background: #fff; border: 1px solid #eee; }
        /* Layout de 3 columnas para Cliente/Equipo/Logo */
        .three-col-equipment {
            display: grid;
            grid-template-columns: 1fr 0.6fr 1fr; /* Distribución equilibrada para eliminar huecos grandes */
            gap: 10px; /* Separación moderada entre columnas */
            align-items: center; 
            width: 100% !important;
            margin-bottom: 12px; 
            font-size: 8.5pt; 
        }
        .three-col-equipment .row-item {
            padding: 3px 0;
            margin-bottom: 2px;
            border-bottom: 1px dashed #eee;
        }
        .three-col-equipment .col-center-logo {
            display: flex;
            justify-content: center; /* Centrar logo en su columna reducida */
            align-items: center;
            height: 100%;
            padding: 4px 0;
            /* Padding eliminado para ajuste natural */
        }
        .three-col-equipment .col-center-logo img {
            width: 80px; /* Aumentado */
            height: 80px;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #eee;
        }
        /* Ajuste responsivo para pantallas pequeñas (no impresión) */
        @media screen and (max-width: 768px) {
            .three-col-equipment { grid-template-columns: 1fr; }
            .three-col-equipment .col-center-logo { order: -1; margin-bottom: 10px; }
        }
        .qr { width: 90px; height: 90px; } /* Aumentado QR */
        .qr-block { display: flex; flex-direction: column; align-items: center; }
        .qr-caption { font-size: 10px; color: #666; margin-top: 4px; }
        .company-info { margin-top: 8px; font-size: 12px; line-height: 1.4; text-align: left; } /* Aumentado info */
        .company-info .name { font-weight: 700; font-size: 14px; }
        .company-info .line { color: #444; }
        .order-box { width: 260px; } /* Aumentado ancho */
        .order-box .title { background: #111; color: #fff; text-align: center; padding: 6px 10px; border-radius: 8px 8px 0 0; font-weight: 700; font-size: 11pt; }
        .order-box .body { border: 1px solid #d9d9d9; border-top: none; border-radius: 0 0 8px 8px; padding: 10px; text-align: center; }
        .order-box .number { font-weight: 800; letter-spacing: 0.5px; font-size: 14pt; }
        .order-box .verify { font-size: 10pt; margin-top: 6px; color: #333; }
        /* Grid de 3 columnas para la parte inferior (Notas -> Pagos -> Costos) */
        .three-col-bottom {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1.5fr; /* 25% - 37.5% - 37.5% */
            gap: 10px;
            width: 100% !important;
            margin-bottom: 8px;
        }

        .compact-section {
            border: 1px solid #ddd;
            border-radius: 5px;
            height: auto; 
            background: #fff;
            overflow: hidden;
        }
        .compact-section .header {
            background: #f1f1f1;
            padding: 2px 5px;
            font-size: 7.5pt; /* Reducido */
            font-weight: 700;
            border-bottom: 1px solid #e5e5e5;
            color: #333;
            text-transform: uppercase;
        }
        .compact-section .content {
            padding: 3px 5px;
            font-size: 7.5pt; /* Reducido */
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        
        /* Garantía Compacta */
        .warranty-compact {
            border: 1px solid #eee;
            border-top: 2px solid #ddd;
            padding: 2px; /* Mínimo padding */
            font-size: 5pt; /* Letra EXTREMADAMENTE pequeña */
            color: #666;
            text-align: justify;
            background: #fbfbfb;
            margin-top: 4px; /* Menor margen */
            line-height: 1.0; /* Interlineado muy apretado */
        }
        
        /* ====== Mejoras Visuales Nuevas (Compactas) ====== */
        .section-title-dark .header {
            background: #222 !important; 
            color: #fff !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 7.5pt; /* Reducido */
            border: 1px solid #222;
            padding: 3px 6px;
        }
        .problem-content {
            font-size: 7.5pt; /* Reducido */
            line-height: 1.2;
            color: #000;
            background: #fff;
        }
        .costs-section {
            border: 1px solid #999 !important; /* BORDE MAS FINO */
        }
        .costs-table tr.total-row td {
            background: #e9ecef; 
            padding: 3px; /* Reducido */
            border-top: 1px solid #ccc; /* BORDE FINO */
            color: #000;
            font-weight: 800;
            font-size: 8.5pt; /* Reducido */
        }
        .costs-table td { padding: 1px 4px; font-size: 7.5pt; }
        
        /* NUEVO: Accesorios inline */
        .accessories-inline {
            font-size: 7.5pt;
            line-height: 1.1;
        }
        .acc-tag {
            display: inline-block;
            background: #f4f4f4;
            border: 1px solid #ddd;
            padding: 0 4px;
            border-radius: 3px;
            margin: 0 2px 1px 0;
            color: #333;
            font-size: 7pt;
        }

        .warranty-compact h5 {
            margin: 0 0 1px 0;
            font-size: 5.5pt; /* Título aún más pequeño */
            font-weight: 700;
            text-transform: uppercase;
            color: #444;
        }
        .warranty-compact ul {
            margin: 0;
            padding-left: 8px; /* Menor indentación */
            columns: 2; /* Dos columnas para los puntos de garantía */
            column-gap: 10px;
        }
        .warranty-compact li { margin-bottom: 1px; }

        /* Ajustes específicos */
        .costs-table { width: 100%; font-size: 7.5pt; }
        .costs-table td { padding: 1px 0; }
        .costs-table .amount { text-align: right; font-weight: 600; }


        /* ====== Ajuste para Media Carta (Half Letter) ====== */
        @page {
            size: 8.5in 5.5in; /* Media Carta Landscape/Rotated */
            margin: 0.2in; /* Margen mínimo */
        }
        @media print {
            html, body {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important; /* Evitar desborde */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                background: #ffffff;
            }
            body { font-size: 7.5pt; } /* Fuente base reducida */

            .three-col-equipment { margin-bottom: 4px !important; font-size: 7pt; }
            .row-item { padding: 1px 0; margin-bottom: 1px; }
            
            td, th { padding: 1px 3px !important; }
            
            .main-content {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }

            /* Asegurar que Bootstrap no interfiera */
            .container, .container-fluid, .row {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .no-print { display: none !important; }
            .section, .warranty-legend { 
                page-break-inside: avoid; 
                border: 1px solid #ddd;
                width: 100% !important;
                box-sizing: border-box;
            }
            
            .two-col { 
                display: grid; 
                grid-template-columns: 1fr 1fr; 
                gap: 10px; 
                width: 100% !important;
            }
            .two-col-compact {
                display: grid;
                grid-template-columns: 1fr 1fr; /* 50% Accesorios, 50% Costos */
                gap: 8px;
                width: 100% !important;
                margin-bottom: 0;
            }
            .three-col-bottom {
                display: grid;
                grid-template-columns: 1fr 1.5fr 1.5fr;
                gap: 8px;
                width: 100% !important;
            }
            
            /* Asegurar que los contenedores usen todo el ancho */
            #sectionsContainer, #extrasContainer, .print-header {
                width: 100% !important;
            }

            img { max-width: 100%; height: auto; }
            
            /* Header distribuido */
            .print-header { 
                display: flex; 
                align-items: flex-start; 
                justify-content: space-between; 
                gap: 10px; 
                margin-bottom: 5px; 
            }
            .brand-block { flex: 1; }
            .company-info { flex: 1; text-align: left; padding: 0 8px; }
            .qr-block { flex: 0 0 auto; margin: 0 10px; }
            .order-box { flex: 0 0 160px; margin-top: 0; }
        }
        @media screen {
            html, body {
                background-color: #f0f0f0; /* Fondo gris para contrastar la hoja */
                min-height: 100vh;
            }
            .main-content {
                width: 8.5in;
                min-height: 5.5in; /* Media carta visual */
                margin: 20px auto;
                background: #ffffff;
                box-shadow: 0 0 15px rgba(0,0,0,0.1);
                padding: 0.2in;
                box-sizing: border-box;
            }
            .two-col { grid-template-columns: 1fr 1fr; }
            .print-header { display: flex; align-items: flex-start; justify-content: space-between; }
            .order-box { width: 160px; }
            .company-info { text-align: left; }
        }

    </style>
    <style>
      @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 12px; display: flex; gap: 8px;">
        <button onclick="window.print()" class="btn btn-primary">Imprimir</button>
        <button onclick="window.close()" class="btn btn-secondary">Cerrar</button>
    </div>
    <main class="main-content">


    <!-- Encabezado tipo Samii -->
    <div class="print-header" id="headerContainer">
        <div class="brand-info-group">
            <div class="brand-block" data-block-key="header_brand">
                <?php if (!empty($company_logo_path)): ?>
                    <img src="../<?php echo htmlspecialchars($company_logo_path); ?>" alt="Logo de la empresa" class="brand-logo">
                <?php else: ?>
                    <div class="brand-logo"></div>
                <?php endif; ?>
            </div>
            <div class="company-info" data-block-key="header_companyinfo">
                <?php if (!empty($company['company_name'])): ?>
                <div class="name"><?php echo htmlspecialchars($company['company_name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($company['company_phone'])): ?>
                <div class="line"><?php echo htmlspecialchars($company['company_phone']); ?></div>
                <?php endif; ?>
                <?php if (!empty($company['company_address'])): ?>
                <div class="line"><?php echo htmlspecialchars($company['company_address']); ?></div>
                <?php endif; ?>
                <?php if (!empty($company['company_email'])): ?>
                <div class="line"><?php echo htmlspecialchars($company['company_email']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="qr-block" data-block-key="header_qr">
            <img class="qr" src="<?php echo htmlspecialchars($qr_img_src); ?>" alt="QR" onerror="this.style.display='none'">
            <?php if (!empty($verification_code)) { ?>
                <div class="qr-caption">Código de verificación: <?php echo htmlspecialchars($verification_code); ?></div>
            <?php } ?>
        </div>

        <div class="order-box" data-block-key="header_orderbox">
            <div class="title">Referencia</div>
            <div class="body">
                <div class="number"><?php $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id']; $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD'; echo htmlspecialchars($prefix) . '-' . str_pad($num, 4, '0', STR_PAD_LEFT); ?></div>
                <div class="subinfo"><?php echo $secondary_date ?: $order_date; ?></div>
            </div>
        </div>
    </div>

    <!-- Cliente y Equipo (3 Columnas) -->
    <div id="sectionsContainer">
        <div data-block-key="section_client_equipment">
            <div class="three-col-equipment">
                
                <!-- Columna 1: Datos del Equipo -->
                <div>
                    <div class="row-item" data-row-key="brand_inline">
                        <span class="label">Marca:</span>
                        <span class="brand-inline-name"><?php echo htmlspecialchars($order['device_brand'] ?? ''); ?></span>
                    </div>
                    <?php if (!empty($order['device_type'])): ?>
                    <div class="row-item" data-row-key="device_type"><span class="label">Tipo:</span> <?php echo htmlspecialchars($order['device_type']); ?></div>
                    <?php endif; ?>
                    <div class="row-item" data-row-key="device_model"><span class="label">Modelo:</span> <?php echo htmlspecialchars($order['device_model'] ?? ''); ?></div>
                    <?php if (!empty($order['serial_number'])): ?>
                    <div class="row-item" data-row-key="serial_number"><span class="label">IMEI:</span> <?php echo htmlspecialchars($order['serial_number']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($passcode)): ?>
                    <div class="row-item" data-row-key="passcode"><span class="label">Passcode:</span> <?php echo htmlspecialchars($passcode); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Columna 2: Logo Central (Pegado a la izquierda) -->
                <div class="col-center-logo">
                    <?php if (!empty($brand_logo)): ?>
                        <img src="../<?php echo htmlspecialchars($brand_logo); ?>" alt="Logo de marca">
                    <?php else: ?>
                        <!-- Espacio vacío si no hay logo -->
                    <?php endif; ?>
                </div>

                <!-- Columna 3: Datos del Cliente -->
                <div>
                    <div class="row-item" data-row-key="client_name"><span class="label">Nombre:</span> <?php echo htmlspecialchars($order['client_name'] ?? ''); ?></div>
                    <?php if (!empty($order['client_tax_id'])): ?>
                    <div class="row-item" data-row-key="client_tax_id"><span class="label">ID/NIT:</span> <?php echo htmlspecialchars($order['client_tax_id']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['client_phone'])): ?>
                    <div class="row-item" data-row-key="client_phone"><span class="label">Teléfono:</span> <?php echo htmlspecialchars($order['client_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['client_email'])): ?>
                    <div class="row-item" data-row-key="client_email"><span class="label">Correo:</span> <?php echo htmlspecialchars($order['client_email']); ?></div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- Problema Reportado (Ancho Completo) -->
    <div class="compact-section section-title-dark" style="margin-bottom: 5px;">
        <div class="header">Problema reportado</div>
        <div class="content problem-content">
            <?php echo nl2br(htmlspecialchars($order['reported_issue'] ?? '')); ?>
        </div>
    </div>

    <?php if (!empty(trim((string)($order['client_observations'] ?? '')))): ?>
    <div class="compact-section section-title-dark" style="margin-bottom: 5px;">
        <div class="header">Observaciones</div>
        <div class="content problem-content">
            <?php echo nl2br(htmlspecialchars((string)($order['client_observations'] ?? ''))); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Accesorios y Costos (2 columnas abajo) -->
    <div class="two-col-compact">
        
        <!-- Accesorios -->
        <div class="compact-section">
            <div class="header">Accesorios recibidos</div>
            <div class="content accessories-inline">
                <?php if (!empty($accessories_received)) { ?>
                    <div>
                        <?php foreach ($accessories_received as $acc) { ?>
                            <span class="acc-tag"><?php echo htmlspecialchars($acc); ?></span>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div style="color: #888; font-style: italic;">Ninguno registrado</div>
                <?php } ?>
            </div>
        </div>

        <!-- Costos -->
        <div class="compact-section costs-section">
            <div class="header">Resumen de Costos</div>
            <div class="content" style="padding: 0;">
                <table class="costs-table" style="margin: 0; width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px;">Anticipo:</td>
                        <td class="amount" style="padding: 8px;"><?php echo CompanySettings::formatCurrency($advance_amount); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>TOTAL:</td>
                        <td class="amount">
                            <?php echo CompanySettings::formatCurrency($unit_price); ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Garantía Compacta y Optimizada -->
    <div class="warranty-compact">
        <h5>Términos y Condiciones de Garantía</h5>
        <?php if (!empty($warranty_custom_text)) { ?>
            <p style="margin-bottom: 6px;"><?php echo nl2br(htmlspecialchars($warranty_custom_text)); ?></p>
        <?php } else { ?>
            <p style="margin-bottom: 6px;">
                Garantía de <?php echo (int)$warranty_days; ?> días sobre refacciones y mano de obra. 
                Válida únicamente presentando esta orden.
            </p>
        <?php } ?>
        <ul>
            <?php if (!empty($warranty_disclaimers)) {
                foreach ($warranty_disclaimers as $item) { ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
            <?php } } else { ?>
                <li>No aplica por humedad, golpes o manipulación externa.</li>
                <li>Equipos abandonados por ><?php echo (int)$abandon_days; ?> días causan almacenaje/disposición.</li>
                <li>Riesgo de pérdida de datos; respalde su información.</li>
                <li>Diagnóstico sujeto a cambios por daños ocultos.</li>
            <?php } ?>
        </ul>
    </div>

    </main>

    <script>
    // ====== Layout guardado embebido ======
    const SAVED_LAYOUT = <?php echo json_encode($work_order_layout, JSON_UNESCAPED_UNICODE); ?>;



    function applyLayout(layout) {
        try {
            // Reordenar bloques por contenedor
            if (layout && layout.containers) {
                Object.entries(layout.containers).forEach(([containerId, orderKeys]) => {
                    const container = document.getElementById(containerId);
                    if (!container || !Array.isArray(orderKeys)) return;
                    orderKeys.forEach(key => {
                        const el = container.querySelector(`[data-block-key="${key}"]`);
                        if (el) container.appendChild(el);
                    });
                });
            }
            // Reordenar filas dentro de secciones
            if (layout && layout.rows) {
                Object.entries(layout.rows).forEach(([sectionKey, rowKeys]) => {
                    const section = document.querySelector(`.section[data-block-key="${sectionKey}"]`);
                    const content = section ? section.querySelector('.content') : null;
                    if (!content || !Array.isArray(rowKeys)) return;
                    rowKeys.forEach(rk => {
                        const row = content.querySelector(`.row-item[data-row-key="${rk}"]`);
                        if (row) content.appendChild(row);
                    });
                });
            }
        } catch (e) {
            console.error('Error aplicando layout:', e);
        }
    }



    // Aplicar layout guardado al cargar
    applyLayout(SAVED_LAYOUT);
    </script>

    <!-- Librerías para generar PDF del contenido -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
      async function downloadOrderPDF() {
        try {
          const { jsPDF } = window.jspdf || jspdf;
          const el = document.querySelector('.main-content');
          if (!el) { if (typeof showError === 'function') showError('No se encontró el contenido para exportar.'); return; }

          const canvas = await html2canvas(el, {
            scale: 5, // Alta resolución equilibrada
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false,
            imageTimeout: 0
          });

          // Usar JPEG con alta calidad reduce drásticamente el peso comparado con PNG
          const imgData = canvas.toDataURL('image/jpeg', 0.95);

          // Usar unidades en mm para una conversión estable
          const pdf = new jsPDF('p', 'mm', 'letter'); // 215.9 x 279.4 mm
          const pageWidth = pdf.internal.pageSize.getWidth();
          const margin = 10; // mm

          const imgProps = pdf.getImageProperties(imgData);
          const pdfWidth = pageWidth - (margin * 2);
          const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;

          pdf.addImage(imgData, 'JPEG', margin, margin, pdfWidth, pdfHeight);

          const orderId = <?php echo isset($order['id']) ? (int)$order['id'] : 0; ?>;
          const padded = String(orderId).padStart(6, '0');
          pdf.save(`orden_${padded}.pdf`);
        } catch (err) {
          console.error(err);
          if (typeof showError === 'function') showError('No se pudo generar el PDF.');
        }
      }
    </script>
</body>
</html>
