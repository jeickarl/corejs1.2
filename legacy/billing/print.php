<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Obtener ID de la factura
$invoice_id = $_GET['id'] ?? '';
if (empty($invoice_id)) {
    die("Error: ID de factura no proporcionado. GET data: " . print_r($_GET, true));
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

// Variables para datos
$invoice = null;
$items = [];
$payments = [];
$company_config = [];
$system_config = [];

try {
    // Obtener factura
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $hasTenantClients = hasTenantColumnCached($pdo, 'clients');
    $hasTenantUsers = hasTenantColumnCached($pdo, 'users');
    if ($perDatabase) {
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.client_type, c.email as client_email, c.phone as client_phone,
                   c.address as client_address,
                   c.id_number, c.tax_id as nit,
                   u.name as created_by_name
            FROM invoices i
            JOIN clients c ON i.client_id = c.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
    } else {
        $joinClients = $hasTenantClients ? "JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id" : "JOIN clients c ON i.client_id = c.id";
        $joinUsers = $hasTenantUsers ? "LEFT JOIN users u ON i.created_by = u.id AND u.tenant_id = i.tenant_id" : "LEFT JOIN users u ON i.created_by = u.id";
        $sql = "
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.client_type, c.email as client_email, c.phone as client_phone,
                   c.address as client_address,
                   c.id_number, c.tax_id as nit,
                   u.name as created_by_name
            FROM invoices i
            {$joinClients}
            {$joinUsers}
            WHERE i.id = ?" . ($hasTenantInvoices ? " AND i.tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute((!$perDatabase && $hasTenantInvoices) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    }
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        die("Error: Factura no encontrada. ID buscado: " . htmlspecialchars($invoice_id));
    }
    
    // Obtener items de la factura
    $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
    $sql = "SELECT * FROM invoice_items WHERE invoice_id = ?" . (($hasTenantItems && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantItems && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener pagos de la factura
    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    $joinPayUsers = ($hasTenantUsers && !$perDatabase) ? "LEFT JOIN users u ON ip.created_by = u.id AND u.tenant_id = ip.tenant_id" : "LEFT JOIN users u ON ip.created_by = u.id";
    $sql = "
        SELECT ip.*, u.name as created_by_name
        FROM invoice_payments ip
        {$joinPayUsers}
        WHERE ip.invoice_id = ?" . (($hasTenantPayments && !$perDatabase) ? " AND ip.tenant_id = ?" : "") . "
        ORDER BY ip.payment_date DESC, ip.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPayments && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener configuración de la empresa
    $hasTenantCompany = hasTenantColumnCached($pdo, 'company_config');
    $stmt = $pdo->prepare("SELECT * FROM company_config" . (($hasTenantCompany && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " LIMIT 1");
    $stmt->execute(($hasTenantCompany && !$perDatabase) ? [$tenantValue] : []);
    $company_config = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];

    // Obtener configuración del sistema (regional/impresión)
    $hasTenantSystem = hasTenantColumnCached($pdo, 'system_config');
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config" . (($hasTenantSystem && !$perDatabase) ? " WHERE tenant_id = ?" : ""));
    $stmt->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
    $system_settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $system_config = [];
    foreach ($system_settings_raw as $row) {
        $system_config[$row['config_key']] = $row['config_value'];
    }
    
} catch (PDOException $e) {
    error_log("Error al obtener datos de la factura: " . $e->getMessage());
    die("Error de base de datos: " . $e->getMessage());
}

// Configuración de impresión
$print_format = $system_config['print_format'] ?? 'letter';
$currency_config = CompanySettings::getCurrency();
$currency_symbol = $currency_config['symbol'];

// Registrar actividad
logActivity($_SESSION['user_id'], 'PRINT_INVOICE', 'invoices', $invoice_id);

// Modo impresión
$printParam = strtolower((string)($_GET['print'] ?? ''));
$is_print = in_array($printParam, ['1', 'true', 'yes', 'on'], true);

if ($is_print && $print_format === 'ticket') {
    // --- FORMATO TICKET ---
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Ticket Factura #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
        <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
        <style>
            body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 10px; width: <?php echo $ticket_width ?? 80; ?>mm; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .bold { font-weight: bold; }
            .line { border-bottom: 1px dashed #000; margin: 5px 0; }
            .table { width: 100%; border-collapse: collapse; }
            .table th, .table td { text-align: left; vertical-align: top; }
            .table .amount { text-align: right; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body onload="window.print()">
        <div class="text-center">
            <h3 style="margin:0"><?php echo htmlspecialchars($company_config['company_name'] ?? 'Mi Empresa'); ?></h3>
            <div><?php echo htmlspecialchars($company_config['address'] ?? ''); ?></div>
            <div>Tel: <?php echo htmlspecialchars($company_config['phone'] ?? ''); ?></div>
        </div>
        <div class="line"></div>
        <div>
            <strong>Factura N°:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br>
            <strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?><br>
            <strong>Cliente:</strong> <?php echo htmlspecialchars($invoice['client_name']); ?>
        </div>
        <div class="line"></div>
        <table class="table">
            <thead>
                <tr>
                    <th>Desc</th>
                    <th class="amount">Tot</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['description']); ?> (x<?php echo $item['quantity']; ?>)</td>
                    <td class="amount"><?php echo formatCurrency($item['total_price']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="line"></div>
        <table class="table">
            <tr><td>Subtotal:</td><td class="amount"><?php echo formatCurrency($invoice['subtotal']); ?></td></tr>
            <?php if ($invoice['tax_amount'] > 0): ?>
            <tr><td>Impuesto:</td><td class="amount"><?php echo formatCurrency($invoice['tax_amount']); ?></td></tr>
            <?php endif; ?>
            <tr class="bold"><td>TOTAL:</td><td class="amount"><?php echo formatCurrency($invoice['total_amount']); ?></td></tr>
            <?php if (!empty($payments)): ?>
                <tr><td colspan="2"><div class="line"></div></td></tr>
                <tr><td colspan="2" class="text-center">--- PAGOS ---</td></tr>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?php echo htmlspecialchars($pay['payment_method']); ?>:</td>
                    <td class="amount"><?php echo formatCurrency($pay['payment_amount']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
        <div class="line"></div>
        <div class="text-center">¡Gracias por su compra!</div>
    </body>
    </html>
    <?php
    exit;
}

// --- FORMATO CARTA (Estilo Orden de Servicio / Samii) ---

// QR Code Link
$qr_data = "FACT:" . $invoice['invoice_number'] . "|TOT:" . $invoice['total_amount'] . "|DATE:" . $invoice['invoice_date'];
$qr_img_src = 'https://api.qrserver.com/v1/create-qr-code/?size=128x128&data=' . urlencode($qr_data);

$company_logo_path = '';
if (!empty($company_config['company_logo'])) {
    $company_logo_path = 'assets/img/' . $company_config['company_logo'];
} elseif (!empty($company_config['logo'])) {
    $company_logo_path = $company_config['logo'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <!-- Incluir Bootstrap para mejorar la apariencia -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ====== Estilo tipo Samii ====== */
        body { font-family: Arial, sans-serif; margin: 0; color: #2b2b2b; }
        .print-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; }
        .brand-block { display: flex; align-items: center; gap: 15px; }
        .brand-info-group { display: flex; align-items: flex-start; gap: 15px; }
        .brand-logo { width: 80px; height: 80px; border-radius: 12px; background: #f0f0f0; object-fit: contain; }
        .brand-name { font-size: 24px; font-weight: 800; line-height: 1.1; }
        .brand-inline { display: inline-flex; align-items: center; gap: 6px; }
        .brand-logo-center { display: flex; justify-content: center; align-items: center; padding: 6px 0; }
        .brand-logo-center img { width: 60px; height: 60px; border-radius: 10px; object-fit: contain; background: #fff; border: 1px solid #eee; }
        
        /* Layout de 3 columnas para Cliente/Equipo/Logo */
        .three-col-equipment {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
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
        .three-col-equipment .row-item .label {
            font-weight: 700;
            color: #444;
            margin-right: 5px;
        }
        
        @media screen and (max-width: 768px) {
            .three-col-equipment { grid-template-columns: 1fr; }
        }
        
        .qr { width: 90px; height: 90px; }
        .qr-block { display: flex; flex-direction: column; align-items: center; }
        .company-info { margin-top: 8px; font-size: 12px; line-height: 1.4; text-align: left; }
        .company-info .name { font-weight: 700; font-size: 14px; }
        .company-info .line { color: #444; }
        
        .order-box { width: 260px; }
        .order-box .title { background: #111; color: #fff; text-align: center; padding: 6px 10px; border-radius: 8px 8px 0 0; font-weight: 700; font-size: 11pt; }
        .order-box .body { border: 1px solid #d9d9d9; border-top: none; border-radius: 0 0 8px 8px; padding: 10px; text-align: center; }
        .order-box .number { font-weight: 800; letter-spacing: 0.5px; font-size: 14pt; }
        .order-box .subinfo { font-size: 11px; color: #666; }
        
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
        
        /* Grid de 3 columnas para Notas/Pagos/Costos */
        .three-col-compact {
            display: grid;
            grid-template-columns: 2fr 3fr 3fr; /* 25% Notas, 37.5% Pagos, 37.5% Costos */
            gap: 8px;
            width: 100% !important;
            margin-bottom: 8px;
        }
        
        .compact-section {
            border: 1px solid #ddd;
            border-radius: 5px;
            height: auto; 
            background: #fff;
        }
        .compact-section .header {
            background: #f1f1f1;
            padding: 2px 5px;
            font-size: 8pt;
            font-weight: 700;
            border-bottom: 1px solid #e5e5e5;
            color: #333;
            border-radius: 5px 5px 0 0;
            text-transform: uppercase;
        }
        .compact-section .content {
            padding: 3px 5px;
            font-size: 8pt;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        
        /* Estilos específicos tipo Samii */
        .section-title-dark .header {
            background: #222 !important; 
            color: #fff !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 8pt;
            border: 1px solid #222;
            padding: 3px 6px;
        }
        
        .costs-section {
            border: 1px solid #999 !important;
        }
        .costs-table { width: 100%; font-size: 9pt; border-collapse: collapse; margin: 0; }
        .costs-table td { padding: 2px 4px; text-align: right; }
        .costs-table td:first-child { text-align: left; color: #555; font-weight: 600; }
        .costs-table .amount { text-align: right; font-weight: 700; color: #000; }
        
        .costs-table tr.total-row td {
            background: #e9ecef; 
            padding: 4px;
            border-top: 1px solid #ccc;
            color: #000;
            font-weight: 800;
            font-size: 10pt;
        }
        
        /* Tabla de Items Estilo Factura */
        .items-table { width: 100%; font-size: 9pt; border-collapse: collapse; }
        .items-table th { 
            background: #222; 
            color: #fff; 
            padding: 4px 6px; 
            font-size: 8pt; 
            text-align: left; 
            font-weight: 600;
            text-transform: uppercase;
        }
        .items-table td { 
            padding: 3px 6px;
            font-size: 9pt; 
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        .items-table tr:last-child td { border-bottom: none; }
        .items-table .text-end { text-align: right; }
        .items-table .text-center { text-align: center; }

        /* Garantía Compacta */
        .warranty-compact {
            border: 1px solid #eee;
            border-top: 2px solid #ddd;
            padding: 2px;
            font-size: 5pt;
            color: #666;
            text-align: justify;
            background: #fbfbfb;
            margin-top: 4px;
            line-height: 1.0;
        }
        .warranty-compact h5 {
            margin: 0 0 1px 0;
            font-size: 5.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #444;
        }
        
        /* ====== Ajuste para Media Carta (Half Letter / Statement) ====== */
        @page {
            size: 8.5in 5.5in; /* Tamaño Media Carta */
            margin: 0.25in;     /* Márgenes reducidos */
        }
        @media print {
            html, body {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important; /* Evitar páginas extra */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                background: #ffffff;
            }
            body { font-size: 7.5pt; } /* Fuente compacta */
            
            .main-content {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }

            .container, .container-fluid, .row {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .no-print { display: none !important; }
            .compact-section { page-break-inside: avoid; margin-bottom: 4px; }
            .compact-section .header { padding: 1px 4px; font-size: 7pt; }
            
            /* Ajustes de compactación vertical */
            .brand-logo { width: 50px !important; height: 50px !important; }
            .qr { width: 60px !important; height: 60px !important; }
            .order-box { width: 160px !important; flex: 0 0 160px !important; }
            .order-box .title { padding: 2px; font-size: 8pt; }
            .order-box .number { font-size: 11pt; }
            .order-box .body { padding: 4px; }
            
            .three-col-equipment { margin-bottom: 4px; font-size: 7pt; }
            .row-item { padding: 1px 0; margin-bottom: 1px; }
            
            td, th { padding: 1px 3px !important; }
            
            .three-col-compact { 
                display: grid; 
                grid-template-columns: 2fr 3fr 3fr;
                gap: 6px; 
                width: 100% !important;
                margin-bottom: 0;
            }
            
            .print-header { 
                display: flex; 
                align-items: flex-start; 
                justify-content: space-between; 
                gap: 5px; 
                margin-bottom: 5px; 
                width: 100% !important;
            }
            .qr-block { flex: 0 0 auto; margin: 0 5px; }
        }
        @media screen {
            html, body {
                background-color: #f0f0f0;
                min-height: 100vh;
            }
            .main-content {
                width: 8.5in;
                min-height: 11in;
                margin: 20px auto;
                background: #ffffff;
                box-shadow: 0 0 15px rgba(0,0,0,0.1);
                padding: 0.3in;
                box-sizing: border-box;
            }
            .print-header { display: flex; align-items: flex-start; justify-content: space-between; }
            .order-box { width: 200px; }
            .company-info { text-align: left; }
        }
    </style>
    <!-- Librerías para PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body<?php echo $is_print ? ' onload="try{window.focus();}catch(e){} setTimeout(function(){window.print();},150);"' : ''; ?>>

    <!-- Toolbar de acciones -->
    <div class="container mt-3 mb-3 no-print" style="max-width: 850px;">
        <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded shadow-sm">
            <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button onclick="downloadInvoicePDF()" class="btn btn-danger btn-sm">
                    <i class="fas fa-file-pdf"></i> Descargar PDF
                </button>
            </div>
        </div>
    </div>

    <!-- Contenido Principal -->
    <div class="main-content">
        
        <!-- Encabezado (Idéntico a Orders) -->
        <div class="print-header">
            <div class="brand-info-group">
                <div class="brand-block">
                    <?php if (!empty($company_logo_path)): ?>
                        <img src="../<?php echo htmlspecialchars($company_logo_path); ?>" alt="Logo de la empresa" class="brand-logo">
                    <?php else: ?>
                        <div class="brand-logo"></div>
                    <?php endif; ?>
                </div>
                <div class="company-info">
                    <?php if (!empty($company_config['company_name'])): ?>
                    <div class="name"><?php echo htmlspecialchars($company_config['company_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company_config['company_phone'])): ?>
                    <div class="line"><?php echo htmlspecialchars($company_config['company_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company_config['company_address'])): ?>
                    <div class="line"><?php echo htmlspecialchars($company_config['company_address']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company_config['company_email'])): ?>
                    <div class="line"><?php echo htmlspecialchars($company_config['company_email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company_config['company_website'])): ?>
                    <div class="line"><?php echo htmlspecialchars($company_config['company_website']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="qr-block">
                <img class="qr" src="<?php echo htmlspecialchars($qr_img_src); ?>" alt="QR">
            </div>

            <div class="order-box">
                <div class="title">REMISIÓN</div>
                <div class="body">
                    <div class="number">#<?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    <div class="subinfo">
                        <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?>
                        <br>
                        <?php if ($invoice['status'] === 'paid'): ?>
                            <span class="badge bg-success text-white" style="font-size:8px;">PAGADA</span>
                        <?php elseif ($invoice['status'] === 'cancelled'): ?>
                            <span class="badge bg-danger text-white" style="font-size:8px;">ANULADA</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark" style="font-size:8px;">PENDIENTE</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2 Columnas: Cliente | Detalles -->
        <div class="three-col-equipment">
            
            <!-- Columna 1: Cliente -->
            <div>
                <div class="row-item"><span class="label">Cliente:</span> <?php echo htmlspecialchars($invoice['client_name']); ?></div>
                <div class="row-item"><span class="label">Doc/NIT:</span> <?php echo htmlspecialchars($invoice['nit'] ?? $invoice['id_number'] ?? 'N/A'); ?></div>
                <div class="row-item"><span class="label">Teléfono:</span> <?php echo htmlspecialchars($invoice['client_phone'] ?? 'N/A'); ?></div>
                <div class="row-item"><span class="label">Dirección:</span> <?php echo htmlspecialchars($invoice['client_address'] ?? 'N/A'); ?></div>
            </div>

            <!-- Columna 2: Detalles Factura -->
            <div>
                <div class="row-item"><span class="label">Vendedor:</span> <?php echo htmlspecialchars($invoice['created_by_name'] ?? 'Sistema'); ?></div>
                <div class="row-item"><span class="label">Vencimiento:</span> <?php echo !empty($invoice['due_date']) ? date('d/m/Y', strtotime($invoice['due_date'])) : 'N/A'; ?></div>
                <div class="row-item"><span class="label">Método Pago:</span> 
                    <?php 
                    if (!empty($payments)) {
                        $methods = array_unique(array_column($payments, 'payment_method'));
                        echo htmlspecialchars(implode(', ', $methods)); 
                    } else {
                        echo 'Pendiente';
                    }
                    ?>
                </div>
                <div class="row-item"><span class="label">Estado:</span> 
                    <?php echo ($invoice['payment_status'] === 'paid') ? 'Pagado' : 'Pendiente'; ?>
                </div>
            </div>
        </div>

        <!-- Items (Estilo "Problema Reportado" de Orders) -->
        <div class="compact-section section-title-dark" style="margin-bottom: 15px;">
            <div class="header">ITEMS DE VENTA</div>
            <div class="content" style="padding: 0;">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Descripción / Producto</th>
                            <th class="text-center" style="width: 15%;">Cant.</th>
                            <th class="text-end" style="width: 15%;">Precio Unit.</th>
                            <th class="text-end" style="width: 20%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                            <td class="text-end"><?php echo formatCurrency($item['total_price']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Relleno visual -->
                        <?php if (count($items) < 3): ?>
                            <?php for($k=0; $k < (3 - count($items)); $k++): ?>
                            <tr><td style="color:transparent;">.</td><td></td><td></td><td></td></tr>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notas, Pagos y Costos (3 columnas) -->
        <div class="three-col-compact">
            
            <!-- Columna 1: Notas -->
            <div class="compact-section">
                <div class="header">NOTAS</div>
                <div class="content" style="padding: 5px; font-style: italic; font-size: 8pt; height: 100%;">
                    <?php echo !empty($invoice['notes']) ? nl2br(htmlspecialchars($invoice['notes'])) : 'Sin notas.'; ?>
                </div>
            </div>

            <!-- Columna 2: Pagos Realizados -->
            <div class="compact-section">
                <div class="header">PAGOS REALIZADOS</div>
                <div class="content" style="padding: 0;">
                    <?php if (!empty($payments)): ?>
                        <table class="costs-table">
                            <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td style="color: #444;"><?php echo htmlspecialchars($pay['payment_method']); ?> (<?php echo date('d/m/y', strtotime($pay['payment_date'])); ?>):</td>
                                <td class="amount text-dark"><?php echo formatCurrency($pay['payment_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else: ?>
                        <div style="padding: 5px; font-style: italic; font-size: 8pt;">Pendiente de pago</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Columna 3: Costos -->
            <div class="compact-section costs-section">
                <div class="header">Resumen de Costos</div>
                <div class="content" style="padding: 0;">
                    <table class="costs-table">
                        <tr>
                            <td>Subtotal:</td>
                            <td class="amount"><?php echo formatCurrency($invoice['subtotal']); ?></td>
                        </tr>
                        <?php if ($invoice['discount_amount'] > 0): ?>
                        <tr>
                            <td>Descuento:</td>
                            <td class="amount text-danger">- <?php echo formatCurrency($invoice['discount_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['tax_amount'] > 0): ?>
                        <tr>
                            <td>Impuestos:</td>
                            <td class="amount"><?php echo formatCurrency($invoice['tax_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="total-row">
                            <td>TOTAL:</td>
                            <td class="amount"><?php echo formatCurrency($invoice['total_amount']); ?></td>
                        </tr>
                        
                        <?php if ($invoice['paid_amount'] > 0 && $invoice['payment_status'] !== 'paid'): ?>
                        <tr>
                            <td>Abonado:</td>
                            <td class="amount text-success"><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                        </tr>
                        <tr>
                            <td>Pendiente:</td>
                            <td class="amount text-danger"><?php echo formatCurrency($invoice['pending_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Garantía / Términos -->
        <div class="warranty-compact">
            <h5>Términos y Condiciones</h5>
            <p>
                Esta factura de venta se asimila en todos sus efectos a una Letra de Cambio (Art. 774 del Código de Comercio). 
                El comprador declara haber recibido real y materialmente las mercancías o servicios descritos.
                <?php echo htmlspecialchars($company_config['invoice_terms'] ?? ''); ?>
            </p>
        </div>

    </div>

    <script>
        // Auto-print si se solicita
        <?php if (isset($_GET['print']) && $_GET['print'] == 'true'): ?>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
        <?php endif; ?>

        // Función PDF Cliente
        async function downloadInvoicePDF() {
            const { jsPDF } = window.jspdf;
            const element = document.querySelector('.main-content');
            
            try {
                const canvas = await html2canvas(element, {
                    scale: 5,
                    backgroundColor: '#ffffff',
                    useCORS: true,
                    logging: false
                });

                const imgData = canvas.toDataURL('image/jpeg', 0.95);
                const pdf = new jsPDF('p', 'mm', 'letter');
                
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 10;
                
                const imgProps = pdf.getImageProperties(imgData);
                const pdfWidth = pageWidth - (margin * 2);
                const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;

                pdf.addImage(imgData, 'JPEG', margin, margin, pdfWidth, pdfHeight);
                pdf.save('Factura_<?php echo $invoice['invoice_number']; ?>.pdf');
            } catch (err) {
                console.error(err);
                alert('Error al generar PDF: ' + err.message);
            }
        }
    </script>
</body>
</html>
