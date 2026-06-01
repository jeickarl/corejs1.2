<?php
require_once '../config/session.php';
requireAuth('../login/index.php');
require_once '../config/database.php';
require_once '../config/functions.php';

$pdo = db();
$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$report_id) {
    die('ID de informe inválido');
}

// Obtener datos del informe
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasOrderNumber = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'order_number') : false;
$hasClientObservations = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'client_observations') : false;
$hasTrTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'technical_reports') : false;
$hasUserTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'users') : false;
$hasWoTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasClientTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
$hasDeviceTypeTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
$hasCompanyTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasBrandTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;

if (!$perDatabase && $hasWoTenant) {
    $joinUsers = $hasUserTenant ? "LEFT JOIN users u ON tr.created_by = u.id AND u.tenant_id = tr.tenant_id" : "LEFT JOIN users u ON tr.created_by = u.id";
    $joinClients = ($hasClientTenant ? "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id" : "LEFT JOIN clients c ON wo.client_id = c.id");
    $joinDeviceTypes = ($hasDeviceTypeTenant ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id");
    $where = $hasTrTenant ? "WHERE tr.id = ? AND wo.tenant_id = ? AND tr.tenant_id = ?" : "WHERE tr.id = ? AND wo.tenant_id = ?";
    $stmt = $pdo->prepare("SELECT tr.*, 
                                  wo.id as order_id, wo.order_number, wo.device_type_id, wo.device_brand, wo.device_model, wo.serial_number, wo.reported_issue, " . ($hasClientObservations ? "wo.client_observations" : "'' AS client_observations") . ",
                                  c.first_name, c.company_name, c.client_type, c.id_number, c.phone, c.email, c.address,
                                  dt.name as device_type_name,
                                  u.name as created_by_name
                           FROM technical_reports tr
                           JOIN work_orders wo ON tr.order_id = wo.id
                           {$joinClients}
                           {$joinDeviceTypes}
                           {$joinUsers}
                           {$where}");
    $stmt->execute($hasTrTenant ? [$report_id, $tenantValue, $tenantValue] : [$report_id, $tenantValue]);
} else {
    $stmt = $pdo->prepare("SELECT tr.*, 
                                  wo.id as order_id, " . ($hasOrderNumber ? "wo.order_number" : "0 AS order_number") . ", wo.device_type_id, wo.device_brand, wo.device_model, wo.serial_number, wo.reported_issue, " . ($hasClientObservations ? "wo.client_observations" : "'' AS client_observations") . ",
                                  c.first_name, c.company_name, c.client_type, c.id_number, c.phone, c.email, c.address,
                                  dt.name as device_type_name,
                                  u.name as created_by_name
                           FROM technical_reports tr
                           JOIN work_orders wo ON tr.order_id = wo.id
                           LEFT JOIN clients c ON wo.client_id = c.id
                           LEFT JOIN device_types dt ON wo.device_type_id = dt.id
                           LEFT JOIN users u ON tr.created_by = u.id
                           WHERE tr.id = ?");
    $stmt->execute([$report_id]);
}
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die('Informe no encontrado');
}

// Obtener configuración de empresa
$company = [];
try {
    if (!$perDatabase && $hasCompanyTenant) {
        $stmtCompany = $pdo->prepare("SELECT * FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$tenantValue]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT * FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback defaults
}

$company_name = $company['company_name'] ?? 'Servicio Técnico';
$company_address = $company['company_address'] ?? ($company['address'] ?? '');
$company_phone = $company['company_phone'] ?? ($company['phone'] ?? '');
$company_email = $company['company_email'] ?? ($company['email'] ?? '');
$company_logo_file = !empty($company['company_logo']) ? $company['company_logo'] : 'logo.png';
$company_logo_path = '../assets/img/' . $company_logo_file;

// Validar si existe realmente para evitar imagen rota, si no, usar placeholder o nada
if (!file_exists($company_logo_path)) {
    // Intentar buscar en uploads/logos por si acaso
    if (file_exists('../uploads/logos/' . $company_logo_file)) {
        $company_logo_path = '../uploads/logos/' . $company_logo_file;
    } 
    // Si sigue sin existir y es el default, dejarlo pasar para que el navegador muestre icono de imagen rota o nada
}

// Obtener logo de la marca del equipo
$brand_logo = '';
if (!empty($report['device_brand'])) {
    try {
        if (!$perDatabase && $hasBrandTenant) {
            $stmtBrand = $pdo->prepare("SELECT logo FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(:brand)) AND tenant_id = :tenant LIMIT 1");
            $stmtBrand->execute([':brand' => trim($report['device_brand']), ':tenant' => $tenantValue]);
        } else {
            $stmtBrand = $pdo->prepare("SELECT logo FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(:brand)) LIMIT 1");
            $stmtBrand->execute([':brand' => trim($report['device_brand'])]);
        }
        $brandRow = $stmtBrand->fetch(PDO::FETCH_ASSOC);
        if ($brandRow && !empty($brandRow['logo'])) {
            $brand_logo = $brandRow['logo'];
        }
    } catch (Exception $e) {}
}

// Generar QR Code
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
// Apuntamos a la orden o al reporte si existiera vista pública. Por ahora a la orden.
$qr_link = $proto . $host . '/orders/view.php?id=' . $report['order_id'];
$qr_img_src = 'https://api.qrserver.com/v1/create-qr-code/?size=128x128&data=' . urlencode($qr_link);


// Decodificar fotos del informe
$photos = [];
if (!empty($report['photos_json'])) {
    $decoded = json_decode($report['photos_json'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $photos = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe Técnico #<?php echo $report['id']; ?></title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --header-height: 130px; /* Aumentado para acomodar el diseño de orden */
            --footer-height: 40px;
            --primary-color: #000; /* Negro estilo orden */
        }
        
        body { font-family: Arial, sans-serif; margin: 0; color: #2b2b2b; background-color: #f8f9fa; }
        
        /* Header Structure based on print_order.php */
        .header-flex-container {
            display: flex;
            align-items: flex-start; /* Alineación superior para mejor control */
            justify-content: space-between;
            width: 100%;
            padding-bottom: 10px;
            /* border-bottom: 2px solid #000; Eliminar borde duplicado */
        }

        .header-left-group {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            overflow: visible; /* Ensure nothing is hidden */
        }
        
        .header-left-group img.brand-logo {
            flex-shrink: 0; /* Prevent logo from shrinking */
        }

        .header-qr-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin: 0 20px;
        }

        .header-right-box {
            width: 200px;
            flex-shrink: 0;
        }

        /* Utility classes for screen/print visibility */
        .print-only { display: none !important; }
        .no-print { display: block !important; }

        /* 2-Column Info Layout (Restored as requested) */
        .two-col-info {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            width: 100%;
        }
        .info-box {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
            position: relative; /* For brand logo positioning */
        }
        .info-header {
            background: #f8f9fa;
            padding: 6px 10px;
            font-weight: bold;
            font-size: 11px;
            border-bottom: 1px solid #ddd;
            color: #333;
            text-transform: uppercase;
        }
        .info-body {
            padding: 10px;
            font-size: 11px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            border-bottom: 1px dotted #eee;
            padding-bottom: 2px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: bold; color: #555; }
        .info-value { font-weight: 600; text-align: right; color: #000; }

        /* Brand Logo inside Info Box */
        .brand-logo-inline {
            position: absolute;
            top: 35px; /* Adjust based on content */
            right: 10px;
            width: 50px;
            height: 50px;
            object-fit: contain;
            opacity: 0.8;
            background: #fff;
            padding: 2px;
            border-radius: 4px;
        }

        /* Estilos de impresión */
        @media print {
            .print-only { display: block !important; }
            .no-print { display: none !important; }

            @page {
                margin: 0.5cm; /* Margen físico de la hoja */
                size: letter;
            }
            
            body { 
                background: white; 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                margin: 0;
            }

            /* Reset container styles for print to avoid blank pages */
            .page-container {
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                min-height: auto !important;
                width: 100% !important;
                max-width: none !important;
            }
            
            .no-print { display: none !important; }
            /* .print-only is handled above */
            
            /* Encabezado Fijo REAL */
            .print-header-fixed {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 110px; /* Reducido para evitar exceso */
                background: white;
                z-index: 1000;
                overflow: visible; /* Permitir que el contenido se vea si excede */
                border-bottom: 2px solid #000;
            }

            /* Contenido con margen superior para no solapar el header */
            .report-content-wrapper {
                margin-top: 120px; /* Header height + gap */
                margin-bottom: 40px;
            }

            .print-footer-fixed {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 25px;
                background: white;
                border-top: 1px solid #ddd;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 9px;
            }

            /* Prevent image overflow & Photo Pages */
            img { max-width: 100%; }

            .photo-item {
                page-break-before: always;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center; /* Center vertically if needed */
                padding-top: 40px; /* More space from top */
                height: auto; 
                /* Removed min-height to avoid forcing new pages unnecessarily */
            }
            
            .photo-item img {
                width: 90% !important; /* Uniform width */
                height: 14.5cm !important; /* Reduced by ~20% from 18cm */
                object-fit: contain; /* Prevent distortion */
                border: 1px solid #ccc;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                background-color: #fff; /* Ensure white background if aspect ratio differs */
            }

            .photo-desc {
                margin-top: 20px; /* More space for description */
                font-size: 14px;
                font-weight: normal; /* Normal text */
                background: transparent;
                padding: 0;
                border: none;
                border-radius: 0;
                text-align: center;
                width: 90%;
            }
        }

        /* Estilos pantalla (Screen) */
        .page-container {
            max-width: 215.9mm; /* Carta width */
            margin: 20px auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            min-height: 279.4mm; /* Carta height */
            position: relative;
        }

        /* Ensure screen images don't overflow */
        .page-container img {
            max-width: 100%;
            height: auto;
        }

        /* Shared Photo Styles for Screen to match Print */
        .photo-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding-top: 10px;
        }
        .photo-item img {
            width: 90%;
            height: 14.5cm; /* Match print height (reduced) */
            object-fit: contain;
            border: 1px solid #ccc;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .photo-desc {
            margin-top: 20px;
            font-size: 14px;
            text-align: center;
            width: 90%;
        }
        
        .screen-header-placeholder {
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Secciones con títulos negros */
        .section-container { 
            margin-bottom: 15px; 
            background: #fff; 
            border: 1px solid #000; /* Borde negro sólido */
            border-radius: 4px; 
            overflow: hidden; 
        }
        .section-header {
            background: #000; /* Negro puro */
            color: #fff;
            padding: 5px 10px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #000;
        }
        .section-content { 
            padding: 10px; 
            font-size: 11px; 
            line-height: 1.4; 
            color: #000; 
            min-height: 40px; /* Altura mínima para consistencia */
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>
    <!-- Botones de acción -->
    <div class="container-fluid py-3 no-print text-center bg-dark">
        <button onclick="window.print()" class="btn btn-warning fw-bold px-4 me-2">
            <i class="fas fa-print me-2"></i>IMPRIMIR INFORME
        </button>
        <button onclick="downloadPDF()" class="btn btn-success fw-bold px-4 me-2">
            <i class="fas fa-download me-2"></i>DESCARGAR PDF
        </button>
        <button onclick="window.close()" class="btn btn-outline-light px-4">
            <i class="fas fa-times me-2"></i>Cerrar
        </button>
    </div>

    <!-- HEADER PARA IMPRESIÓN (FIJO) -->
    <div class="print-header-fixed print-only">
        <div class="header-flex-container">
            <!-- Grupo Izquierdo: Logo + Info -->
            <div class="header-left-group">
                <img src="<?php echo htmlspecialchars($company_logo_path); ?>" alt="Logo" class="brand-logo" style="width: 80px; height: 80px; object-fit: contain;">
                <div>
                    <div class="brand-name" style="font-size: 20px; font-weight: 800; text-transform: uppercase;"><?php echo htmlspecialchars($company_name); ?></div>
                    <div class="company-info" style="font-size: 10px; color: #444;">
                        <?php if ($company_address) echo '<div>' . htmlspecialchars($company_address) . '</div>'; ?>
                        <?php if ($company_phone) echo '<div>Tel: ' . htmlspecialchars($company_phone) . '</div>'; ?>
                        <?php if ($company_email) echo '<div>' . htmlspecialchars($company_email) . '</div>'; ?>
                    </div>
                </div>
            </div>

            <!-- Centro: QR -->
            <div class="header-qr-section">
                <img src="<?php echo $qr_img_src; ?>" alt="QR Verificación" style="width: 70px; height: 70px;">
                <div style="font-size: 8px; margin-top: 2px;">VERIFICAR</div>
            </div>

            <!-- Derecha: Caja Reporte -->
            <div class="header-right-box">
                <div class="order-box" style="width: 100%; border: 1px solid #000; border-radius: 4px; overflow: hidden;">
                    <div class="title" style="background: #000; color: #fff; text-align: center; font-weight: bold; padding: 4px; font-size: 12px;">INFORME TÉCNICO</div>
                    <div class="body" style="padding: 5px; text-align: center;">
                        <div class="number" style="font-size: 16px; font-weight: 800;">R-<?php echo str_pad($report['id'], 6, '0', STR_PAD_LEFT); ?></div>
                        <div class="date" style="font-size: 10px;"><?php echo date('d/m/Y', strtotime($report['created_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER PARA IMPRESIÓN (FIJO) -->
    <div class="print-footer-fixed print-only">
        <div><?php echo htmlspecialchars($company_name); ?> - Informe Técnico - Página <span class="pageNumber"></span></div>
    </div>

    <div class="page-container">
        <!-- HEADER EN PANTALLA (Similar al impreso pero estático) -->
        <?php ob_start(); ?>
        <div class="screen-header-placeholder no-print">
            <div class="header-flex-container" style="border-bottom: none;">
                <!-- Grupo Izquierdo: Logo + Info -->
                <div class="header-left-group">
                    <img src="<?php echo htmlspecialchars($company_logo_path); ?>" alt="Logo" class="brand-logo" style="width: 80px; height: 80px; object-fit: contain;">
                    <div>
                        <div class="brand-name" style="font-size: 20px; font-weight: 800; text-transform: uppercase;"><?php echo htmlspecialchars($company_name); ?></div>
                        <div class="company-info" style="font-size: 10px; color: #444;">
                            <?php if ($company_address) echo '<div>' . htmlspecialchars($company_address) . '</div>'; ?>
                            <?php if ($company_phone) echo '<div>Tel: ' . htmlspecialchars($company_phone) . '</div>'; ?>
                            <?php if ($company_email) echo '<div>' . htmlspecialchars($company_email) . '</div>'; ?>
                        </div>
                    </div>
                </div>

                <!-- Centro: QR -->
                <div class="header-qr-section">
                    <img src="<?php echo $qr_img_src; ?>" alt="QR Verificación" style="width: 70px; height: 70px;">
                </div>

                <!-- Derecha: Caja Reporte -->
                <div class="header-right-box">
                    <div class="order-box" style="width: 100%; border: 1px solid #000; border-radius: 4px; overflow: hidden;">
                        <div class="title" style="background: #000; color: #fff; text-align: center; font-weight: bold; padding: 4px; font-size: 12px;">INFORME TÉCNICO</div>
                        <div class="body" style="padding: 5px; text-align: center;">
                            <div class="number" style="font-size: 16px; font-weight: 800;">R-<?php echo str_pad($report['id'], 6, '0', STR_PAD_LEFT); ?></div>
                            <div class="date" style="font-size: 10px;"><?php echo date('d/m/Y', strtotime($report['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- <div style="border-bottom: 2px solid #000; margin-top: 10px;"></div> Eliminar línea duplicada -->
        </div>
        <?php $screenHeader = ob_get_clean(); echo $screenHeader; ?>

        <!-- WRAPPER DE CONTENIDO (Padding superior para impresión) -->
        <div class="report-content-wrapper">
            
            <!-- INFO BLOCKS (2 Columns) -->
            <div class="two-col-info">
                <!-- Client Info -->
                <div class="info-box">
                    <div class="info-header">Información del Cliente</div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label">Cliente:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['client_type'] === 'company' ? $report['company_name'] : $report['first_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">ID/RUT:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['id_number']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Teléfono:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['phone']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Dirección:</span>
                            <span class="info-value" style="font-size: 10px;"><?php echo htmlspecialchars($report['address']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Correo:</span>
                            <span class="info-value" style="font-size: 9px;"><?php echo htmlspecialchars($report['email']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Equipment Info -->
                <div class="info-box">
                    <div class="info-header">Información del Equipo</div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label">Referencia:</span>
                            <?php
                                $num = isset($report['order_number']) && (int)$report['order_number'] > 0 ? (int)$report['order_number'] : (int)$report['order_id'];
                                $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
                                $disp = $prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
                            ?>
                            <span class="info-value" style="font-size: 14px; color: #000;"><?php echo htmlspecialchars($disp); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Equipo:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['device_type_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Marca:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['device_brand']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Modelo:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['device_model']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Serial/IMEI:</span>
                            <span class="info-value"><?php echo htmlspecialchars($report['serial_number']); ?></span>
                        </div>
                    </div>
                    <!-- Brand Logo Overlay -->
                    <?php if ($brand_logo && file_exists('../assets/img/brands/' . $brand_logo)): ?>
                        <img src="../assets/img/brands/<?php echo $brand_logo; ?>" class="brand-logo-inline" alt="Marca">
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECCIONES DE CONTENIDO -->
            <div class="section-container">
                <div class="section-header">Problema Reportado</div>
                <div class="section-content">
                    <?php echo nl2br(htmlspecialchars($report['reported_issue'] ?? 'No especificado')); ?>
                </div>
            </div>

            <?php if (!empty(trim((string)($report['client_observations'] ?? '')))): ?>
            <div class="section-container">
                <div class="section-header">Observaciones</div>
                <div class="section-content">
                    <?php echo nl2br(htmlspecialchars((string)($report['client_observations'] ?? ''))); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="section-container">
                <div class="section-header">Diagnóstico Técnico</div>
                <div class="section-content">
                    <?php echo nl2br(htmlspecialchars($report['diagnosis'])); ?>
                </div>
            </div>

            <?php if (!empty($report['procedure_taken'])): ?>
            <div class="section-container">
                <div class="section-header">Procedimiento Realizado</div>
                <div class="section-content">
                    <?php echo nl2br(htmlspecialchars($report['procedure_taken'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['conclusion'])): ?>
            <div class="section-container">
                <div class="section-header">Conclusiones y Observaciones</div>
                <div class="section-content">
                    <?php echo nl2br(htmlspecialchars($report['conclusion'])); ?>
                    <?php if (!empty($report['introduction'])) echo '<br><br>' . nl2br(htmlspecialchars($report['introduction'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Firmas -->
            <div class="row mt-5 pt-4 break-inside-avoid">
                <div class="col-6 text-center">
                    <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">
                        <div class="fw-bold"><?php echo htmlspecialchars($report['created_by_name'] ?? 'Técnico'); ?></div>
                        <div class="small text-muted">Técnico Responsable</div>
                    </div>
                </div>
                <div class="col-6 text-center">
                    <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">
                        <div class="fw-bold">Recibido Conforme</div>
                        <div class="small text-muted">Firma Cliente</div>
                    </div>
                </div>
            </div>

        </div> <!-- End content wrapper -->
    </div> <!-- End page container -->

    <!-- EVIDENCIA FOTOGRÁFICA (Páginas nuevas) -->
    <?php if (!empty($photos)): ?>
        <?php foreach ($photos as $photo): 
            $filename = is_array($photo) ? $photo['filename'] : $photo;
            $desc = is_array($photo) ? ($photo['description'] ?? '') : '';
            $cat = is_array($photo) ? ($photo['category'] ?? '') : '';
            
            // Traducir categoría para el título
            $catTitle = match($cat) {
                'entry' => 'ESTADO INICIAL (INGRESO)',
                'diagnosis' => 'EVIDENCIA DE DIAGNÓSTICO',
                'delivery' => 'ESTADO FINAL (ENTREGA)',
                default => 'EVIDENCIA FOTOGRÁFICA'
            };

            $path = getTenantUploadDir('../uploads/') . "orders/" . $report['order_id'] . "/" . $filename;
            if (file_exists($path)):
        ?>
        <div class="page-container" style="page-break-before: always;">
            <?php echo $screenHeader; ?>
            <div class="report-content-wrapper">
                <div class="photo-item" style="page-break-before: auto;">
                    <div class="section-header w-100 text-center mb-3" style="border-radius: 5px;"><?php echo $catTitle; ?></div>
                    <img src="<?php echo $path; ?>" alt="Evidencia">
                    <?php if ($desc): ?>
                    <div class="photo-desc"><?php echo htmlspecialchars($desc); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; endforeach; ?>
    <?php endif; ?>
    <script>
    async function downloadPDF() {
         const { jsPDF } = window.jspdf;
         const containers = document.querySelectorAll('.page-container');
         
         if (containers.length === 0) return;

         // Detectar si estamos en un iframe (modo silencioso)
         const isIframe = window.self !== window.top;
         const urlParams = new URLSearchParams(window.location.search);
         const isSilent = urlParams.get('action') === 'download_silent';

         // Mostrar indicador de carga solo si no es silencioso (o si se prefiere mostrar algo)
         let loadingDiv = null;
         if (!isSilent) {
             loadingDiv = document.createElement('div');
             loadingDiv.style.position = 'fixed';
             loadingDiv.style.top = '0';
             loadingDiv.style.left = '0';
             loadingDiv.style.width = '100%';
             loadingDiv.style.height = '100%';
             loadingDiv.style.backgroundColor = 'rgba(0,0,0,0.7)';
             loadingDiv.style.color = '#fff';
             loadingDiv.style.display = 'flex';
             loadingDiv.style.justifyContent = 'center';
             loadingDiv.style.alignItems = 'center';
             loadingDiv.style.zIndex = '9999';
             loadingDiv.style.fontSize = '24px';
             loadingDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-3"></i> Generando PDF de alta calidad...';
             document.body.appendChild(loadingDiv);
         }

         try {
             const pdf = new jsPDF('p', 'mm', 'letter'); // Letter size
             const pdfWidth = 215.9;
             const pdfHeight = 279.4;

             for (let i = 0; i < containers.length; i++) {
                 if (i > 0) pdf.addPage();

                 const container = containers[i];
                 
                 // Asegurar que el contenedor sea visible y tenga fondo blanco
                 const originalBg = container.style.background;
                 container.style.background = '#ffffff';

                 const canvas = await html2canvas(container, {
                     scale: 3, // Aumentado a 3 para mejor calidad (equilibrio entre calidad y peso/rendimiento)
                     useCORS: true,
                     logging: false,
                     backgroundColor: '#ffffff',
                     letterRendering: true, // Mejora renderizado de texto
                     allowTaint: true
                 });

                 container.style.background = originalBg;

                 const imgData = canvas.toDataURL('image/jpeg', 0.98); // Calidad JPEG al 98%
                 
                 // Ajustar al ancho de la página PDF
                 const imgProps = pdf.getImageProperties(imgData);
                 const imgHeight = (imgProps.height * pdfWidth) / imgProps.width;
                 
                 pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, imgHeight);
             }

             pdf.save('Informe_Tecnico_<?php echo $report['id']; ?>.pdf');

             // Notificar al padre si es iframe
             if (isIframe) {
                 window.parent.postMessage({ type: 'download_complete', reportId: <?php echo $report['id']; ?> }, '*');
             }
             
         } catch (error) {
             console.error('Error generating PDF:', error);
            if (!isSilent) { if (typeof showError === 'function') showError('Error al generar el PDF.'); }
             if (isIframe) {
                 window.parent.postMessage({ type: 'download_error', reportId: <?php echo $report['id']; ?>, error: error.message }, '*');
             }
         } finally {
             if (loadingDiv) document.body.removeChild(loadingDiv);
         }
     }
 
     document.addEventListener('DOMContentLoaded', () => {
         const urlParams = new URLSearchParams(window.location.search);
         const action = urlParams.get('action');

         if (action === 'print') {
             setTimeout(() => window.print(), 1000);
         } else if (action === 'download') {
             setTimeout(() => downloadPDF(), 1000);
         } else if (action === 'download_silent') {
             // Esperar un poco más para asegurar carga de imágenes
             setTimeout(() => downloadPDF(), 1500);
         } else if (action === 'print_silent') {
             setTimeout(() => {
                 window.print();
                 // Intentar notificar cierre después de imprimir (difícil saber cuándo termina)
                 // window.parent.postMessage({ type: 'print_initiated', reportId: ... }, '*');
             }, 1000);
         }
     });
    </script>
</body>
</html>
