<?php
/**
 * Plantilla reutilizable para el formulario de factura (Nueva/Editar)
 * 
 * Variables esperadas:
 * - $pageTitle: Título de la página
 * - $pageDescription: Descripción de la página
 * - $formData: Array con datos del formulario
 *   - client_id, client_name, client_document, client_phone, client_type
 *   - invoice_date, due_date
 *   - notes, terms_conditions
 *   - items: Array de items
 * - $payment_methods: Array de métodos de pago
 * - $currency_config: Configuración de moneda
 * - $system_config_js: Configuración JS
 * - $isEditing: Boolean
 * - $factura_number: (Opcional) Número de factura si se está editando
 */

// Asegurar valores por defecto
$taxCfg = \CompanySettings::getTaxConfig();
$formData = array_merge([
    'client_id' => '',
    'client_name' => '',
    'client_document' => '',
    'client_phone' => '',
    'client_type' => '',
    'invoice_date' => date('Y-m-d'),
    'due_date' => '',
    'notes' => '',
    'terms_conditions' => '',
    'items' => [],
    'payment_method' => '',
    'payment_amount' => 0,
    'reference_number' => ''
], $formData ?? []);

// Si no hay items, agregar uno vacío por defecto para la UI
if (empty($formData['items'])) {
    $formData['items'][] = [
        'code' => '',
        'description' => '',
        'quantity' => 1,
        'unit_price' => 0,
        'tax' => $taxCfg['enabled'] ? $taxCfg['rate'] : 0,
        'item_id' => '',
        'selected_type' => '',
        'total_price' => 0
    ];
}
?>

<script>
    window.SYSTEM_CONFIG = <?php echo json_encode($system_config_js); ?>;
</script>

<style>
    /* Estilos globales compactos */
    .form-control, .form-select, .input-group-text, .btn {
        font-size: 0.85rem !important;
    }
    
    /* Tabla de items: ultra compacta estilo imagen */
    #itemsTable { 
        table-layout: fixed; 
        border-collapse: collapse; 
        border-spacing: 0;
        width: 100%;
        margin-bottom: 0;
    }
    
    #itemsTable thead th { 
        background: #e9ecef !important; /* Gris suave del encabezado */
        padding: 6px 8px !important;
        border: 1px solid #dee2e6 !important;
        color: #495057;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: none;
    }
    
    #itemsTable tbody td { 
        padding: 4px 6px !important;
        border: 1px solid #eee !important;
        vertical-align: top;
        background: #fff;
    }
    
    /* Inputs dentro de la tabla */
    #itemsTable .form-control,
    #itemsTable .form-select {
        background-color: transparent !important;
        border: 1px solid #ced4da !important;
        border-radius: 4px !important; /* Bordes rectos o levemente redondeados */
        padding: 4px 8px !important;
        height: auto !important;
        min-height: 32px;
        box-shadow: none !important;
        font-size: 0.85rem !important;
    }
    
    #itemsTable .form-control:focus {
        border-color: #0d6efd !important;
        background-color: #fff !important;
    }
    
    /* Icono de búsqueda en el input */
    .search-input-container {
        position: relative;
    }
    
    .search-input-container i {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        pointer-events: none;
        font-size: 0.8rem;
    }
    
    /* Totales y Formas de Pago */
    .totals-container {
        display: flex;
        justify-content: space-between;
        padding: 20px 0;
        border-top: 1px solid #dee2e6;
        margin-top: 20px;
    }
    
    .payment-methods-section {
        flex: 0 0 50%;
    }
    
    .summary-section {
        flex: 0 0 35%;
        text-align: right;
    }
    
    .summary-row {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 8px;
        font-size: 0.9rem;
    }
    
    .summary-label {
        color: #6c757d;
        width: 150px;
        padding-right: 20px;
    }
    
    .summary-value {
        width: 120px;
        font-weight: 600;
        color: #212529;
    }
    
    .final-total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 0;
        margin-top: 20px;
        border-top: 2px solid #eee;
    }
    
    .total-neto-label {
        font-size: 1.5rem;
        font-weight: 700;
        color: #212529;
    }
    
    .total-neto-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: #000;
    }
    
    /* Animaciones sutiles */
    .item-row:hover {
        background-color: #f8f9fa;
    }
    /* Barra de acciones inferior */
    .invoice-footer-bar {
        left: 210px !important;
        right: 0;
        transition: left 0.3s ease;
    }
    
    body.sidebar-collapsed .invoice-footer-bar {
        left: 70px !important;
    }
    
    @media (max-width: 991.98px) {
        .invoice-footer-bar {
            left: 0 !important;
        }
    }

    @media (max-width: 768px) {
        .invoice-items-table {
            overflow: visible;
        }

        #itemsTable thead {
            display: none;
        }

        #itemsTable,
        #itemsTable tbody,
        #itemsTable tr,
        #itemsTable td {
            display: block;
            width: 100%;
        }

        #itemsTable tr.item-row {
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 10px;
            margin-bottom: 10px;
            background: #fff;
        }

        #itemsTable tbody td {
            border: 0 !important;
            padding: 6px 0 !important;
        }

        #itemsTable tbody td:nth-child(1)::before { content: "#"; }
        #itemsTable tbody td:nth-child(2)::before { content: "Producto"; }
        #itemsTable tbody td:nth-child(3)::before { content: "Descripción"; }
        #itemsTable tbody td:nth-child(4)::before { content: "Cant"; }
        #itemsTable tbody td:nth-child(5)::before { content: "Precio Unit."; }
        #itemsTable tbody td:nth-child(6)::before { content: "Impuestos"; }
        #itemsTable tbody td:nth-child(7)::before { content: "Total"; }
        #itemsTable tbody td:nth-child(8)::before { content: "Acción"; }

        #itemsTable tbody td:nth-child(n)::before {
            display: block;
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 4px;
        }

        #itemsTable tbody td:nth-child(8) {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            padding-top: 8px !important;
            border-top: 1px dashed #e9ecef !important;
            margin-top: 6px;
        }

        .invoice-items-table .code-search-dropdown,
        .invoice-items-table .item-search-dropdown {
            width: 100% !important;
            max-width: 100% !important;
        }

        .invoice-footer-spacer {
            height: 12px !important;
        }

        .invoice-footer-bar {
            position: static !important;
            left: auto !important;
            right: auto !important;
            bottom: auto !important;
            width: 100% !important;
            z-index: auto !important;
            background: transparent !important;
            border-top: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        .invoice-footer-bar .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .invoice-footer-content {
            flex-direction: column;
            align-items: stretch !important;
            gap: 12px;
        }

        .invoice-footer-summary {
            border: 1px solid #e9ecef;
            border-radius: 16px;
            padding: 10px 12px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(0,0,0,0.04);
        }

        .invoice-footer-bar .border-end {
            border-right: 0 !important;
            padding-right: 0 !important;
        }

        .invoice-footer-bar #totalStatus {
            font-size: 1.15rem !important;
            line-height: 1.1;
        }

        .invoice-footer-bar #totalStatusTotal {
            font-size: .95rem !important;
            line-height: 1.1;
        }

        .invoice-footer-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            border-radius: 0 !important;
        }

        .invoice-footer-actions .btn {
            width: 100%;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px !important;
        }

        .invoice-footer-bar .btn .btn-text {
            display: inline !important;
        }

        .invoice-footer-bar .btn i {
            margin-right: .5rem !important;
        }
    }
</style>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Header Principal -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">
                <i class="fas fa-file-invoice text-primary no-theme me-2"></i><?php echo htmlspecialchars($pageTitle); ?>
            </h2>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($pageDescription); ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="fas fa-arrow-left me-2"></i>Volver a Facturación
            </a>
        </div>
    </div>

    <!-- Mensajes de error -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible bg-white border-danger border-start border-4 shadow-sm fade show mb-4" role="alert">
            <div class="d-flex">
                <i class="fas fa-exclamation-circle fs-4 text-danger me-3 mt-1"></i>
                <div>
                    <strong class="text-dark">Por favor corrige los siguientes errores:</strong>
                    <ul class="mb-0 mt-2 text-muted">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    
    <!-- Mensaje de éxito -->
    <?php elseif (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible bg-white border-success border-start border-4 shadow-sm fade show mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle fs-4 text-success me-3"></i>
                <div class="text-muted"><?php echo htmlspecialchars($_GET['success']); ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="invoiceForm">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityEnhancements::generateCSRFToken(); ?>">
        <input type="hidden" id="invoice_due_days_default" value="<?php echo htmlspecialchars((string)(int)cfg_get('invoice_due_days_default', 7)); ?>">
        <div class="row g-4">
            <div class="col-12">
                <!-- Tarjeta Maestra Unificada -->
                <div class="card card-modern shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body p-4">
                        
                        <!-- SECCIÓN 1: CLIENTE Y FECHAS -->
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-info-circle text-primary me-2"></i>Información General</h5>
                        </div>
                        <div class="row g-4">
                            <!-- Búsqueda de Cliente -->
                            <div class="col-md-7">
                                <label for="client_search" class="form-label fw-medium text-muted small text-uppercase ms-2">Cliente <span class="text-danger">*</span></label>
                                
                                <?php 
                                $hasClient = !empty($formData['client_id']); 
                                $displaySearch = 'block'; // Siempre mostrar buscador
                                $displayInfo = $hasClient ? 'block' : 'none';
                                ?>

                                <div class="position-relative" id="client-search-wrapper" style="display: <?php echo $displaySearch; ?>;">
                                    <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                                        <span class="input-group-text bg-light border-0 text-muted px-3">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" 
                                               class="form-control border-0 bg-light py-2" 
                                               id="client_search" 
                                               placeholder="Buscar por nombre, cédula o NIT..." 
                                               autocomplete="off"
                                               <?php echo $hasClient ? '' : 'required'; ?>>
                                        <button class="btn btn-primary border-0 px-3" type="button" data-bs-toggle="modal" data-bs-target="#newClientModal">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    <div id="client_dropdown" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2" style="max-height: 300px; overflow-y: auto;"></div>
                                </div>
                                
                                <input type="hidden" id="client_id" name="client_id" value="<?php echo htmlspecialchars($formData['client_id']); ?>" required>
                                <input type="hidden" id="order_id" name="order_id" value="<?php echo isset($formData['order_id']) ? htmlspecialchars($formData['order_id']) : ''; ?>">
                                
                                <!-- Tipo de Documento (Oculto) -->
                                <input type="hidden" id="invoice_type" name="invoice_type" value="<?php echo htmlspecialchars($formData['invoice_type'] ?? 'service'); ?>">
                                
                                <!-- Tarjeta de Cliente Seleccionado -->
                                <div id="client-info-section" class="mt-3" style="display: <?php echo $displayInfo; ?>;">
                                    <div class="card border border-primary border-opacity-50 rounded-4 shadow-sm bg-white overflow-hidden no-theme">
                                        <div class="card-body p-0">
                                            <div class="d-flex align-items-stretch">
                                                <div class="d-flex align-items-center justify-content-center px-3" style="background-color: #f0f7ff !important;">
                                                    <i class="fas fa-user-check text-primary fs-4 no-theme"></i>
                                                </div>
                                                <div class="flex-grow-1 p-3 bg-white">
                                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                                        <h6 class="fw-bold text-dark mb-0" id="selected-client-name"><?php echo htmlspecialchars($formData['client_name']); ?></h6>
                                                        <span class="badge fw-bold text-uppercase no-theme" 
                                                              style="font-size: 0.65rem; letter-spacing: 0.5px; background-color: #e7f1ff !important; color: #0d6efd !important; border: 1px solid #cfe2ff !important;" 
                                                              id="selected-client-type">
                                                            <?php echo $formData['client_type'] === 'company' ? 'Empresa / NIT' : 'Persona Natural'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="d-flex gap-3 align-items-center">
                                                        <div class="small text-muted">
                                                            <i class="fas fa-id-card me-1 opacity-50"></i>
                                                            <span id="selected-client-id-number" class="fw-medium"><?php echo htmlspecialchars($formData['client_document']); ?></span>
                                                        </div>
                                                        <div class="small text-muted border-start ps-3">
                                                            <i class="fas fa-phone-alt me-1 opacity-50"></i>
                                                            <span id="selected-client-phone" class="fw-medium"><?php echo htmlspecialchars($formData['client_phone']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center pe-3 bg-white">
                                                    <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle hover-bg-danger hover-text-white transition-all" 
                                                            onclick="clearClientSelection()" title="Cambiar cliente">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Fechas -->
                            <div class="col-md-5">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="invoice_date" class="form-label fw-medium text-muted small text-uppercase ms-2">Fecha de Emisión</label>
                                        <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                                            <span class="input-group-text bg-light border-0 text-muted px-3"><i class="fas fa-calendar-day"></i></span>
                                            <input type="date" class="form-control border-0 bg-light py-2" id="invoice_date" name="invoice_date" 
                                                   value="<?php echo htmlspecialchars($formData['invoice_date']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label for="due_date" class="form-label fw-medium text-muted small text-uppercase ms-2">Fecha de Vencimiento</label>
                                        <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                                            <span class="input-group-text bg-light border-0 text-muted px-3"><i class="fas fa-calendar-check"></i></span>
                                            <input type="date" class="form-control border-0 bg-light py-2" id="due_date" name="due_date" 
                                                   value="<?php echo htmlspecialchars($formData['due_date']); ?>">
                                            <button type="button" class="btn btn-light border-0 px-3 text-primary" onclick="setDueDate()" title="Sumar plazo">
                                                <i class="fas fa-plus-circle"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border-top my-4"></div>

                        <!-- SECCIÓN 2: DETALLE DE ITEMS -->
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-shopping-cart text-primary me-2"></i>Detalle de Items</h5>
                            <span class="badge rounded-pill bg-light text-muted fw-normal px-3 border"><i class="fas fa-keyboard me-2"></i>Ctrl+Enter para agregar</span>
                        </div>
                        
                        <!-- Tabla de Items -->
                        <div class="table-responsive invoice-items-table">
                            <table class="table align-middle" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th width="40" class="text-center">#</th>
                                        <th width="100">Producto</th>
                                        <th width="50%">Descripción del Producto / Servicio</th>
                                        <th width="70" class="text-center">Cant</th>
                                        <th width="120">Precio Unit.</th>
                                        <th width="110">Impuestos</th>
                                        <th width="130" class="text-end">Total</th>
                                        <th width="40"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody">
                                    <?php foreach ($formData['items'] as $index => $item): ?>
                                    <tr class="item-row" data-index="<?php echo $index; ?>">
                                        <td class="text-center text-muted small pt-2"><?php echo $index + 1; ?></td>
                                        <td>
                                            <div class="search-input-container">
                                                <input type="text" class="form-control item-code" 
                                                       name="items[<?php echo $index; ?>][code]" 
                                                       value="<?php echo htmlspecialchars($item['code'] ?? ''); ?>"
                                                       placeholder="Buscar..." autocomplete="off">
                                                <i class="fas fa-search"></i>
                                                <div class="dropdown-menu code-search-dropdown shadow-lg border-0 rounded-4" style="display: none; width: 250px;"></div>
                                            </div>
                                            <input type="hidden" class="selected-item-id" name="items[<?php echo $index; ?>][item_id]" value="<?php echo htmlspecialchars($item['item_id'] ?? ''); ?>">
                                            <input type="hidden" class="selected-item-type" name="items[<?php echo $index; ?>][selected_type]" value="<?php echo htmlspecialchars($item['selected_type'] ?? ''); ?>">
                                        </td>
                                        <td>
                                            <div class="position-relative">
                                                <input type="text" class="form-control item-description" 
                                                       name="items[<?php echo $index; ?>][description]" 
                                                       value="<?php echo htmlspecialchars($item['description']); ?>"
                                                       placeholder="Descripción del producto..." required autocomplete="off">
                                                <div class="dropdown-menu item-search-dropdown shadow-lg border-0 rounded-4" style="display: none; width: 100%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control text-center quantity" 
                                                   name="items[<?php echo $index; ?>][quantity]" 
                                                   value="<?php echo htmlspecialchars($item['quantity']); ?>"
                                                   min="1" step="1" required>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-transparent text-muted small pe-1"><?php echo $currency_config['symbol']; ?></span>
                                                <input type="text" class="form-control border-start-0 unit-price money-input" 
                                                       name="items[<?php echo $index; ?>][unit_price]" 
                                                       value="<?php echo number_format((float)$item['unit_price'], $currency_config['decimals'] ?? 0); ?>"
                                                       required oninput="formatCurrencyInput(this)">
                                            </div>
                                        </td>
                                        <td>
                                            <?php $itemTaxValue = $taxCfg['enabled'] ? ($item['tax'] ?? 0) : 0; ?>
                                            <select class="form-select item-tax" name="items[<?php echo $index; ?>][tax]" <?php echo $taxCfg['enabled'] ? '' : 'disabled'; ?>>
                                                <option value="0" <?php echo ($itemTaxValue == 0) ? 'selected' : ''; ?>>0%</option>
                                                <option value="19" <?php echo ($itemTaxValue == 19) ? 'selected' : ''; ?>>IVA 19%</option>
                                                <option value="5" <?php echo ($itemTaxValue == 5) ? 'selected' : ''; ?>>IVA 5%</option>
                                                <option value="8" <?php echo ($itemTaxValue == 8) ? 'selected' : ''; ?>>IVA 8%</option>
                                            </select>
                                        </td>
                                        <td class="text-end">
                                            <span class="item-total-display">
                                                <?php 
                                                $total = isset($item['total_price']) ? $item['total_price'] : ($item['quantity'] * $item['unit_price'] * (1 + $item['tax']/100));
                                                echo $currency_config['symbol'] . number_format($total, $currency_config['decimals'] ?? 0); 
                                                ?>
                                            </span>
                                            <input type="hidden" class="item-total" value="<?php echo $total; ?>">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-link text-danger p-0 delete-item-btn" onclick="removeItem(this)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 pb-3">
                            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" id="addItemBtn">
                                <i class="fas fa-plus me-2"></i>Agregar Línea
                            </button>
                            <button type="button" class="btn btn-outline-success rounded-pill px-4 shadow-sm fw-bold ms-2" id="addFromOrdersBtn" style="display: none;">
                                <i class="fas fa-clipboard-check me-2"></i>Cargar desde Orden
                            </button>
                        </div>

                        <!-- SECCIÓN 3: TOTALES, PAGOS Y OBSERVACIONES -->
                        <div class="mt-4 pt-4 border-top">
                            <div class="row g-4">
                                <!-- Columna Izquierda: Formas de Pago y Observaciones -->
                                <div class="col-lg-7 border-end">
                                    <!-- Formas de Pago -->
                                    <div class="mb-4">
                                        <h5 class="fw-bold text-primary mb-3"><i class="fas fa-money-bill-wave me-2"></i>Formas de pago</h5>
                                        
                                        <?php if (!empty($existing_payments)): ?>
                                            <div class="mb-3">
                                                <table class="table table-sm table-borderless align-middle small">
                                                    <tbody>
                                                        <?php foreach ($existing_payments as $payment): ?>
                                                        <tr>
                                                            <td class="text-muted"><?php echo date('d/m/y', strtotime($payment['payment_date'])); ?></td>
                                                            <td class="fw-medium"><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                                            <td class="text-end fw-bold text-success">
                                                                <?php echo $currency_config['symbol'] . number_format($payment['payment_amount'], $currency_config['decimals'] ?? 0); ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <div id="payments-container">
                                            <!-- Los pagos se agregarán aquí dinámicamente -->
                                        </div>
                                        
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-link text-primary p-0 fw-bold text-decoration-none" onclick="addPaymentRow()">
                                                <i class="fas fa-plus me-1"></i>+Agregar otra forma de pago
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Observaciones y Condiciones -->
                                    <div class="pt-3 border-top">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="notes" class="form-label fw-medium text-muted small text-uppercase ms-1">Notas Visibles</label>
                                                <textarea class="form-control bg-light border-0 rounded-3 p-2 small" id="notes" name="notes" rows="2" 
                                                          placeholder="Notas en factura..."><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="terms_conditions" class="form-label fw-medium text-muted small text-uppercase ms-1">Términos</label>
                                                <textarea class="form-control bg-light border-0 rounded-3 p-2 small" id="terms_conditions" name="terms_conditions" rows="2" 
                                                          placeholder="Términos y condiciones..."><?php echo htmlspecialchars($formData['terms_conditions']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Template oculto para nuevos pagos -->
                                    <template id="payment-row-template">
                                        <div class="payment-row mb-2 d-flex align-items-center gap-2 bg-light bg-opacity-50 p-2 rounded-3 border border-light">
                                            <div class="flex-grow-1">
                                                <select class="form-select form-select-sm border-0 shadow-sm" name="payments[{index}][method]" required>
                                                    <option value="">Seleccionar método...</option>
                                                    <?php foreach ($payment_methods as $method): ?>
                                                        <option value="<?php echo htmlspecialchars($method['name']); ?>"><?php echo htmlspecialchars($method['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div style="width: 130px;">
                                                <input type="text" class="form-control form-control-sm border-0 shadow-sm" 
                                                       name="payments[{index}][reference]" 
                                                       placeholder="Referencia">
                                            </div>
                                            <div style="width: 140px;">
                                                <div class="input-group input-group-sm shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text border-0 bg-white text-muted small"><?php echo $currency_config['symbol']; ?></span>
                                                    <input type="text" class="form-control border-0 money-input" 
                                                           name="payments[{index}][amount]" 
                                                           placeholder="0" 
                                                           oninput="formatCurrencyInput(this)">
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-link text-danger p-0" onclick="removePaymentRow(this)">
                                                <i class="fas fa-trash-alt small"></i>
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <!-- Columna Derecha: Desglose de Totales -->
                                <div class="col-lg-5">
                                    <div class="summary-section w-100">
                                        <div class="summary-row">
                                            <div class="summary-label">Subtotal:</div>
                                            <div class="summary-value" id="subtotalDisplay"><?php echo $currency_config['symbol']; ?> 0.00</div>
                                        </div>
                                        <div class="summary-row">
                                            <div class="summary-label"><?php echo htmlspecialchars($taxCfg['name']); ?>:</div>
                                            <div class="summary-value" id="taxDisplay"><?php echo $currency_config['symbol']; ?> 0.00</div>
                                        </div>
                                        
                                        <div class="final-total-row mt-4">
                                            <div class="total-neto-label">Total Neto:</div>
                                            <div class="total-neto-value" id="totalDisplay"><?php echo $currency_config['symbol']; ?> 0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Historial de Pagos Oculto (Ya se muestra arriba) -->
                <input type="hidden" id="existing-paid-total" value="<?php echo $totalPaidExisting; ?>">

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Inicializar con un pago si no hay ninguno y no estamos editando pagos existentes
                    // O si venimos de un POST fallido, restaurar los pagos
                    <?php 
                    $existing_post_payments = $_POST['payments'] ?? [];
                    if (!empty($existing_post_payments)) {
                        echo "const postPayments = " . json_encode($existing_post_payments) . ";";
                        echo "postPayments.forEach(p => addPaymentRow(p));";
                    } elseif (empty($formData['payment_amount']) || $formData['payment_amount'] == 0) {
                        // Si no hay pago previo (o es 0), mostramos una fila vacía por defecto para facilitar
                        echo "addPaymentRow();";
                    } else {
                        // Si hay un pago único preexistente (del modelo antiguo), lo agregamos
                        echo "addPaymentRow({
                            method: '" . htmlspecialchars($formData['payment_method']) . "',
                            amount: '" . $formData['payment_amount'] . "',
                            reference: '" . htmlspecialchars($formData['reference_number']) . "'
                        });";
                    }
                    ?>
                });

                let paymentIndex = 0;

                function addPaymentRow(data = null) {
                    const container = document.getElementById('payments-container');
                    const template = document.getElementById('payment-row-template');
                    const clone = template.content.cloneNode(true);
                    
                    const row = clone.querySelector('.payment-row');
                    row.innerHTML = row.innerHTML.replace(/{index}/g, paymentIndex);
                    
                    const amountInput = row.querySelector('input[name*="[amount]"]');
                    const methodSelect = row.querySelector('select[name^="payments"]');
                    const refInput = row.querySelector('input[name*="[reference]"]');

                    if (data) {
                        if (methodSelect && data.method) methodSelect.value = data.method;
                        if (amountInput && data.amount) {
                            amountInput.value = data.amount;
                            formatCurrencyInput(amountInput);
                        }
                        if (refInput && data.reference) refInput.value = data.reference;
                    } else {
                        if (methodSelect) {
                            const hasEfectivo = Array.from(methodSelect.options || []).some(o => (o.value || '').toLowerCase() === 'efectivo');
                            if (hasEfectivo) methodSelect.value = 'Efectivo';
                        }
                    }
                    
                    container.appendChild(row);
                    paymentIndex++;
                }

                function removePaymentRow(btn) {
                    btn.closest('.payment-row').remove();
                }

                // Funciones auxiliares para cálculo de totales
                function getInvoiceTotalFromDOM() {
                    let total = 0;
                    document.querySelectorAll('.item-total').forEach(input => {
                        total += parseFloat(input.value) || 0;
                    });
                    return total;
                }

                function getPaidTotalFromDOM() {
                    let total = 0;
                    
                    // Sumar pagos existentes (historial)
                    const existingPaidInput = document.getElementById('existing-paid-total');
                    if (existingPaidInput) {
                        total += parseFloat(existingPaidInput.value) || 0;
                    }

                    // Sumar nuevos pagos (inputs)
                    document.querySelectorAll('#payments-container .money-input').forEach(input => {
                        // Usar parseCurrency global si existe, o fallback básico
                        if (typeof parseCurrency === 'function') {
                            total += parseCurrency(input.value);
                        } else {
                            // Fallback: eliminar todo excepto dígitos, comas y puntos, luego normalizar
                            // Asumiendo formato local, esto es riesgoso sin parseCurrency
                            // Pero utils.js debería estar cargado
                            total += parseFloat(input.value.replace(/[^\d.-]/g, '')) || 0;
                        }
                    });
                    return total;
                }

                // Escuchar cambios en el total de la factura
                document.addEventListener('invoiceTotalUpdated', function(e) {
                    const newTotal = e.detail.total;
                    const paymentRows = document.querySelectorAll('#payments-container .payment-row');
                    
                    if (paymentRows.length === 1) {
                        const amountInput = paymentRows[0].querySelector('.money-input');
                        if (amountInput && amountInput.dataset.autofilled === '1') {
                            amountInput.value = newTotal;
                            formatCurrencyInput(amountInput);
                        }
                    }
                    
                    // Actualizar contadores de estado (Total a Pagar)
                    if (typeof updateStatusCounters === 'function') {
                        updateStatusCounters();
                    }
                });

                // Escuchar cambios en los pagos para actualizar el "Total a Pagar"
                document.addEventListener('input', function(e) {
                    if (e.target.classList.contains('money-input') && e.target.closest('#payments-container')) {
                        if (typeof updateStatusCounters === 'function') {
                            updateStatusCounters();
                        }
                    }
                });
                
                // Observador para cambios en el DOM de pagos (agregar/quitar filas)
                const paymentsObserver = new MutationObserver(function(mutations) {
                    if (typeof updateStatusCounters === 'function') {
                        updateStatusCounters();
                    }
                });
                
                const paymentsContainer = document.getElementById('payments-container');
                if (paymentsContainer) {
                    paymentsObserver.observe(paymentsContainer, { childList: true, subtree: true });
                }
                </script>
            </div>
        </div>
        
        <!-- Estado de Carga -->
        <div id="loadingStatus" class="mb-3" style="display: none;">
            <div class="alert alert-info d-flex align-items-center rounded-pill shadow-sm border-0 bg-white">
                <div class="spinner-border spinner-border-sm text-primary me-3" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <span id="loadingText" class="fw-medium text-dark">Procesando factura...</span>
            </div>
        </div>
        
        <!-- Spacer para el footer fijo -->
        <div class="invoice-footer-spacer" style="height: 120px;"></div>
        
        <!-- Barra de Acciones Fija (Sticky Footer) -->
        <div class="fixed-bottom bg-white border-top shadow-lg py-3 invoice-footer-bar" style="z-index: 1030;">
            <div class="container-fluid px-4">
                <div class="d-flex justify-content-between align-items-center invoice-footer-content">
                    <div class="d-flex align-items-center gap-4 invoice-footer-summary">
                        <div class="d-flex flex-column border-end pe-4">
                            <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Saldo Pendiente</span>
                            <span class="h3 mb-0 fw-bold text-success" id="totalStatus"><?php echo $currency_config['symbol']; ?> 0</span>
                        </div>
                        <div class="d-flex flex-column me-2">
                            <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Total Factura</span>
                            <span class="h5 mb-0 fw-bold text-dark" id="totalStatusTotal"><?php echo $currency_config['symbol']; ?> 0</span>
                        </div>
                        
                        <div class="d-none d-xl-flex gap-3">
                            <div class="d-flex align-items-center bg-light px-3 py-2 rounded-pill border">
                                <i class="fas fa-box text-secondary me-2 opacity-75"></i>
                                <span class="small fw-bold"><span id="itemsCount">0</span> Items</span>
                            </div>
                            <div class="d-flex align-items-center bg-light px-3 py-2 rounded-pill border" style="max-width: 250px;">
                                <i class="fas fa-user text-primary me-2 opacity-75"></i>
                                <span class="small fw-bold text-truncate" id="clientStatus">Sin cliente</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 invoice-footer-actions">
                        <a href="index.php" class="btn btn-light border-0 rounded-pill px-4 fw-bold text-muted hover-bg-danger hover-text-white transition-all" title="Cancelar" aria-label="Cancelar">
                            <i class="fas fa-times me-2"></i><span class="btn-text">Cancelar</span>
                        </a>
                        <button type="submit" name="action" value="save_pending" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm" id="savePendingBtn" title="<?php echo $isEditing ? 'Actualizar' : 'Guardar'; ?>" aria-label="<?php echo $isEditing ? 'Actualizar' : 'Guardar'; ?>">
                            <i class="fas fa-clock me-2"></i><span class="btn-text"><?php echo $isEditing ? 'Actualizar' : 'Guardar'; ?></span>
                        </button>
                        <button type="submit" name="action" value="save" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm" id="saveBtn" title="<?php echo $isEditing ? 'Actualizar y cobrar' : 'Cobrar'; ?>" aria-label="<?php echo $isEditing ? 'Actualizar y cobrar' : 'Cobrar'; ?>">
                            <i class="fas fa-cash-register me-2"></i><span class="btn-text"><?php echo $isEditing ? 'Actualizar' : 'Cobrar'; ?></span>
                        </button>
                        <button type="submit" name="action" value="save_and_whatsapp" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" id="saveAndSendBtn" title="WhatsApp" aria-label="WhatsApp">
                            <i class="fab fa-whatsapp me-2"></i><span class="btn-text">WhatsApp</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../clients/modal_new_client.php'; ?>
<?php include __DIR__ . '/modal_invoice_preview.php'; ?>

<script src="../assets/js/modal-handlers.js"></script>

<script>
    function updateInvoiceBodyPadding(){
        try {
            var isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
            document.body.style.paddingBottom = isMobile ? '0px' : '80px';
        } catch(e) {
            document.body.style.paddingBottom = '80px';
        }
    }
    updateInvoiceBodyPadding();
    window.addEventListener('resize', updateInvoiceBodyPadding);

    // Función auxiliar para limpiar selección de cliente
    function clearClientSelection() {
        document.getElementById('client_id').value = '';
        document.getElementById('client_search').value = '';
        document.getElementById('client-info-section').style.display = 'none';
        document.getElementById('client-search-wrapper').style.display = 'block';
        document.getElementById('clientStatus').textContent = 'Sin cliente';
        
        // Resetear campos ocultos del cliente
        document.getElementById('selected-client-name').textContent = '';
        document.getElementById('selected-client-id-number').textContent = '';
        document.getElementById('selected-client-phone').textContent = '';
    }
</script>
