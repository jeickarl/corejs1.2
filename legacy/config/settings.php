<?php
require_once __DIR__ . '/session.php';
requireAuth();

// Diagnóstico básico en caso de errores que oculten la vista
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Generar CSRF token si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once 'database.php';
require_once 'functions.php';

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');
$hasTenantCompanyConfig = hasTenantColumnCached($pdo, 'company_config');
$hasTenantSystemConfig = hasTenantColumnCached($pdo, 'system_config');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

$userRole = strtolower(trim($_SESSION['user_role'] ?? ''));
$isAdmin = in_array($userRole, ['admin', 'administrador', 'administrator']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_action'])) {
    $pm_action = $_POST['pm_action'];
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
        if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
            throw new Exception('Token CSRF inválido');
        }
        if ($pm_action === 'add') {
            $name = trim($_POST['pm_name'] ?? '');
            if ($name === '') {
                throw new Exception('Nombre requerido');
            }
            $hasStatus = false;
            $hasIsActive = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
                $hasStatus = $c && $c->rowCount() > 0;
            }
            catch (PDOException $e) {
            }
            try {
                $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
                $hasIsActive = $c && $c->rowCount() > 0;
            }
            catch (PDOException $e) {
            }
            if ($hasStatus) {
                $stmt = $pdo->prepare(($hasTenantPaymentMethods ? "INSERT INTO payment_methods (tenant_id, name, status) VALUES (?, ?, 'active')" : "INSERT INTO payment_methods (name, status) VALUES (?, 'active')"));
            }
            elseif ($hasIsActive) {
                $stmt = $pdo->prepare(($hasTenantPaymentMethods ? "INSERT INTO payment_methods (tenant_id, name, is_active) VALUES (?, ?, 1)" : "INSERT INTO payment_methods (name, is_active) VALUES (?, 1)"));
            }
            else {
                $stmt = $pdo->prepare(($hasTenantPaymentMethods ? "INSERT INTO payment_methods (tenant_id, name) VALUES (?, ?)" : "INSERT INTO payment_methods (name) VALUES (?)"));
            }
            $stmt->execute($hasTenantPaymentMethods ? [$tenantValue, $name] : [$name]);
        }
        elseif (strpos($pm_action, 'toggle|') === 0) {
            $parts = explode('|', $pm_action);
            $id = intval($parts[1] ?? 0);
            $state = $parts[2] ?? '';
            if ($id <= 0) {
                throw new Exception('ID inválido');
            }
            $hasStatus = false;
            $hasIsActive = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
                $hasStatus = $c && $c->rowCount() > 0;
            }
            catch (PDOException $e) {
            }
            try {
                $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
                $hasIsActive = $c && $c->rowCount() > 0;
            }
            catch (PDOException $e) {
            }
            if ($hasStatus) {
                $sql = "UPDATE payment_methods SET status = ? WHERE id = ?" . ($hasTenantPaymentMethods ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $params = [$state === 'active' ? 'active' : 'inactive', $id];
                if ($hasTenantPaymentMethods) { $params[] = $tenantValue; }
                $stmt->execute($params);
            }
            elseif ($hasIsActive) {
                $sql = "UPDATE payment_methods SET is_active = ? WHERE id = ?" . ($hasTenantPaymentMethods ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $params = [$state === 'active' ? 1 : 0, $id];
                if ($hasTenantPaymentMethods) { $params[] = $tenantValue; }
                $stmt->execute($params);
            }
        }
        header('Location: settings.php');
        exit();
    }
    catch (Exception $e) {
    // silencioso
    }
}

// Obtener configuración de empresa
try {
    $company_query = "SELECT * FROM company_config" . (($hasTenantCompanyConfig && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY id DESC LIMIT 1";
    $company_stmt = $pdo->prepare($company_query);
    $company_stmt->execute(($hasTenantCompanyConfig && !$perDatabase) ? [$tenantValue] : []);
    $company_config = $company_stmt->fetch(PDO::FETCH_ASSOC);
}
catch (Exception $e) {
    $company_config = null;
}

// Obtener configuraciones del sistema
try {
    $system_query = "SELECT config_key, config_value FROM system_config" . (($hasTenantSystemConfig && !$perDatabase) ? " WHERE tenant_id = ?" : "");
    $system_stmt = $pdo->prepare($system_query);
    $system_stmt->execute(($hasTenantSystemConfig && !$perDatabase) ? [$tenantValue] : []);
    $system_config = [];
    while ($row = $system_stmt->fetch(PDO::FETCH_ASSOC)) {
        $system_config[$row['config_key']] = $row['config_value'];
    }
}
catch (Exception $e) {
    $system_config = [];
}

// Obtener usuarios
try {
    $users_query = "SELECT * FROM users" . (($hasTenantUsers && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY created_at DESC";
    $users_stmt = $pdo->prepare($users_query);
    $users_stmt->execute(($hasTenantUsers && !$perDatabase) ? [$tenantValue] : []);
    $users_result = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (Exception $e) {
    $users_result = [];
}
?>

<?php
$page_title = 'Configuración - Sistema';
ob_start();
?>
<meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
<style>
    /* Estilos para drag and drop */
    .accessory-row {
        transition: all 0.2s ease;
    }
    
    .accessory-row.dragging {
        opacity: 0.5;
        transform: rotate(2deg);
        background-color: #f8f9fa !important;
    }
    
    .accessory-row.drag-over {
        border-top: 3px solid #007bff;
    }
    
    .drag-handle {
        cursor: grab;
    }
    
    .drag-handle:active {
        cursor: grabbing;
    }
    
    .drag-handle i {
        transition: color 0.2s ease;
    }
    
    .drag-handle:hover i {
        color: #007bff !important;
    }
    
    /* Estilo para fila que se está arrastrando */
    .accessory-row.dragging .drag-handle i {
        color: #007bff !important;
    }
    #configTabs{margin-bottom:0 !important}
    #configTabsContent{margin-top:0 !important}
    #catalogs{margin-top:1rem !important}
    #equipment-accessories{margin-top:1rem !important}
    #configTabsContent .tab-pane{padding-top:0 !important;margin-top:0 !important}
    .nav-tabs{border-bottom:none !important}
    #catalogTabs{margin-bottom:0 !important}
    #catalogs .nav.nav-pills{margin-top:0 !important}
    #catalogs #catalogTabsContent{margin-top:0 !important}
    #catalogs .row:first-child,#equipment-accessories .row:first-child,#order-statuses .row:first-child{margin-top:0 !important}
    #catalogs .col-md-12,#equipment-accessories .col-md-12,#order-statuses .col-md-12{padding-top:0 !important;margin-top:0 !important}
    #brands iframe,#models iframe,#device-types iframe{display:block;margin:0 !important;padding:0 !important}
    #order-statuses .card-body{padding-top:0 !important}
    #order-statuses .card,#equipment-accessories .card{margin-top:0 !important}
    .main-content .container-fluid{padding-top:0 !important}
    .main-content .card .card-body{padding-top:0 !important}
    .main-content .card{margin-top:0 !important}
    
    /* Estilos mejorados para las pestañas */
    .nav-tabs {
        border-bottom: 1px solid #dee2e6;
        padding-bottom: 10px;
        margin-bottom: 20px;
        gap: 5px;
    }
    .nav-tabs .nav-link {
        border: none;
        border-radius: 50rem !important; /* Pill shape */
        padding: 10px 20px;
        color: #333;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    .nav-tabs .nav-link:hover {
        background-color: var(--primary-light);
        color: var(--primary-dark);
    }
    .nav-tabs .nav-link.active {
        background-color: var(--primary-dark) !important;
        color: #fff !important;
        box-shadow: 0 4px 6px rgba(var(--primary-dark-rgb, 225, 29, 72), 0.3);
    }
    .nav-tabs .nav-link i {
        font-size: 1.1em;
    }

    /* CSS CRÍTICO PARA FORZAR VISIBILIDAD DE PESTAÑAS */
    .tab-content > .tab-pane {
        display: none;
    }
    .tab-content > .tab-pane.active {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
    }
    iframe {
        width: 100%;
        border: none;
    }

    /* Estilos para pills (sub-pestañas como en Dispositivos) */
    .nav-pills .nav-link {
        color: #333;
        border-radius: 50rem !important;
        padding: 8px 20px;
        transition: all 0.3s ease;
    }
    .nav-pills .nav-link:hover {
        background-color: var(--primary-light);
        color: var(--primary-dark);
    }
    .nav-pills .nav-link.active {
        background-color: var(--primary-dark) !important;
        color: #fff !important;
        box-shadow: 0 4px 6px rgba(var(--primary-dark-rgb, 225, 29, 72), 0.3);
    }
    
    h5 i, .btn i {
        margin-right: 8px;
    }

    /* Scroll horizontal para las pestañas */
    #configTabs {
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 2px;
        margin-bottom: 2px;
        justify-content: flex-start !important;
        max-width: 100%;
    }
    #configTabs::-webkit-scrollbar {
        height: 6px !important;
        display: block !important;
    }
    #configTabs::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 4px;
        margin: 0 10px;
    }
    #configTabs::-webkit-scrollbar-thumb {
        background-color: #94a3b8;
        border-radius: 4px;
    }
    #configTabs .nav-item {
        flex: 0 0 auto !important;
    }
    #configTabs .nav-link {
        white-space: nowrap;
    }
    </style>
    <div class="container-fluid p-0">
            <!-- Encabezado y Menú Superior -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold text-dark mb-0"><i class="fas fa-cog me-2"></i> Configuración del Sistema</h4>
                    </div>
                    
                    <!-- Menú de Navegación Estilo Tarjeta -->
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-2 position-relative">
                            <!-- Flecha indicadora de scroll (opcional visual) -->
                            <div class="d-md-none position-absolute end-0 top-50 translate-middle-y me-1 pe-none" style="z-index:10; background: linear-gradient(90deg, transparent, #fff); width: 40px; height: 100%; border-radius: 1rem;">
                                <i class="fas fa-chevron-right position-absolute top-50 end-0 translate-middle-y me-2 text-muted" style="opacity: 0.5;"></i>
                            </div>
                            <ul class="nav nav-pills gap-2 flex-nowrap" id="configTabs" role="tablist" style="overflow-x: auto; white-space: nowrap; max-width: 100%;">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="company-tab" data-bs-toggle="tab" data-bs-target="#company" type="button" role="tab">
                                        <i class="fas fa-building me-2"></i>Empresa
                                    </button>
                                </li>

                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">
                                        <i class="fas fa-users me-2"></i>Usuarios
                                    </button>
                                </li>
                                <?php if ($isAdmin): ?>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="clients-data-tab" data-bs-toggle="tab" data-bs-target="#clients-data" type="button" role="tab">
                                        <i class="fas fa-database me-2"></i>Respaldo
                                    </button>
                                </li>
                                <?php
endif; ?>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="payment-methods-tab" data-bs-toggle="tab" data-bs-target="#payment-methods" type="button" role="tab">
                                        <i class="fas fa-credit-card me-2"></i>Pagos
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="order-statuses-tab" data-bs-toggle="tab" data-bs-target="#order-statuses" type="button" role="tab">
                                        <i class="fas fa-list-alt me-2"></i>Estados
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="client-portal-tab" data-bs-toggle="tab" data-bs-target="#client-portal" type="button" role="tab">
                                        <i class="fas fa-id-badge me-2"></i>Portal Cliente
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="catalogs-tab" data-bs-toggle="tab" data-bs-target="#catalogs" type="button" role="tab">
                                        <i class="fas fa-laptop me-2"></i>Dispositivos
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="equipment-accessories-tab" data-bs-toggle="tab" data-bs-target="#equipment-accessories" type="button" role="tab">
                                        <i class="fas fa-box-open me-2"></i>Accesorios
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="templates-tab" data-bs-toggle="tab" data-bs-target="#templates" type="button" role="tab">
                                        <i class="fas fa-file-invoice me-2"></i>Documentos o Imprimibles
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="whatsapp-tab" data-bs-toggle="tab" data-bs-target="#whatsapp" type="button" role="tab">
                                        <i class="fab fa-whatsapp me-2"></i>WhatsApp
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance" type="button" role="tab">
                                        <i class="fas fa-paint-roller me-2"></i>Apariencia
                                    </button>
                                </li>

                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido Principal -->
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            
                            <div class="tab-content" id="configTabsContent">
                                <!-- TAB: Plantillas (#templates) -->
                                <div class="tab-pane fade" id="templates" role="tabpanel" aria-labelledby="templates-tab">
                                    <form id="templatesForm">
                                    <div class="row mt-4">
                                        <div class="col-md-12">
                                            <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                    <h5 class="mb-0 text-dark">
                                                        <i class="fas fa-print me-2"></i>Impresión de Documentos
                                                    </h5>
                                                    <p class="text-muted small mb-0 ps-1">Opciones básicas para imprimir órdenes y documentos del sistema.</p>
                                                </div>
                                                <div class="card-body p-4">
                                                    <div class="alert alert-light border rounded-3 mb-0">
                                                        <div class="fw-bold text-dark mb-1">Formato estándar</div>
                                                        <div class="text-muted small">La impresión principal usa el formato clásico del sistema para que sea fácil y consistente.</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                    <h5 class="mb-0 text-dark">
                                                        <i class="fas fa-tag me-2"></i>Etiquetas (Adhesivos)
                                                    </h5>
                                                    <p class="text-muted small mb-0 ps-1">Configure tamaño, contenido y estilo de etiquetas para identificar equipos.</p>
                                                </div>
                                                <div class="card-body p-4">
                                                    <?php
                                                    $lp = (string)($system_config['label_paper_size'] ?? 'sticker_5030');
                                                    $lw = (string)($system_config['label_custom_width_mm'] ?? '50');
                                                    $lh = (string)($system_config['label_custom_height_mm'] ?? '30');
                                                    $lpad = (string)($system_config['label_padding_mm'] ?? '2');
                                                    $llogo = (string)($system_config['label_logo_mm'] ?? '10');
                                                    $lqr = (string)($system_config['label_qr_mm'] ?? '10');
                                                    $lorder = (string)($system_config['label_order_font_pt'] ?? '11');
                                                    $lline = (string)($system_config['label_line_font_pt'] ?? '7.5');
                                                    $lcopy = (string)($system_config['label_copies'] ?? '1');
                                                    $lsq = (string)($system_config['label_show_qr'] ?? '1');
                                                    $lsc = (string)($system_config['label_show_client'] ?? '1');
                                                    $lscp = (string)($system_config['label_show_client_phone'] ?? '0');
                                                    $lss = (string)($system_config['label_show_serial'] ?? '1');
                                                    $lsd = (string)($system_config['label_show_date'] ?? '0');
                                                    $lsty = (string)($system_config['label_style'] ?? 'compact');
                                                    $lup = (string)($system_config['label_uppercase'] ?? '0');
                                                    $lbd = (string)($system_config['label_border'] ?? '0');
                                                    $lshowlogo = (string)($system_config['label_show_logo'] ?? '0');
                                                    $llayout = (string)($system_config['label_layout'] ?? 'qr_bottom');
                                                    $lfont = (string)($system_config['label_font_family'] ?? 'arial');
                                                    $lclamp = (string)($system_config['label_multiline_lines'] ?? '3');
                                                    ?>
                                                    <?php
                                                    $lsi = (string)($system_config['label_show_issue'] ?? '1');
                                                    $lsco = (string)($system_config['label_show_client_observations'] ?? '0');
                                                    $lstn = (string)($system_config['label_show_tech_notes'] ?? '0');
                                                    $lsac = (string)($system_config['label_show_accessories'] ?? '0');
                                                    $lsdt = (string)($system_config['label_show_device_type'] ?? '0');
                                                    $lsdev = (string)($system_config['label_show_device'] ?? '1');
                                                    ?>

                                                    <div id="labelConfigRoot" class="row g-4">
                                                        <div class="col-lg-7">
                                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                                                                <div class="d-flex flex-column">
                                                                    <div class="fw-bold text-dark">Editor de Etiqueta</div>
                                                                    <div class="text-muted small">Ajusta opciones y mira el resultado en la vista previa.</div>
                                                                </div>
                                                                <div class="d-flex gap-3 flex-wrap align-items-center">
                                                                    <div style="min-width: 240px;">
                                                                        <label for="label_style" class="form-label small text-muted mb-1">Estilo</label>
                                                                        <select id="label_style" name="label_style" class="form-select form-select-sm" style="border-radius: 0.75rem;">
                                                                            <option value="compact" <?php echo $lsty === 'compact' ? 'selected' : ''; ?>>Compacta</option>
                                                                            <option value="detailed" <?php echo $lsty === 'detailed' ? 'selected' : ''; ?>>Detallada</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="d-flex gap-3 flex-wrap align-items-center pt-3 pt-sm-0">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="label_auto_preview" value="1" checked>
                                                                            <label class="form-check-label" for="label_auto_preview">Vista previa automática</label>
                                                                        </div>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="label_uppercase" name="label_uppercase" value="1" <?php echo $lup === '1' ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label" for="label_uppercase">Mayúsculas</label>
                                                                        </div>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="label_border" name="label_border" value="1" <?php echo $lbd === '1' ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label" for="label_border">Borde</label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="accordion" id="labelAccordion">
                                                                <div class="accordion-item border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden;">
                                                                    <h2 class="accordion-header" id="labelAccSize">
                                                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#labelCollapseSize" aria-expanded="true" aria-controls="labelCollapseSize">
                                                                            <i class="fas fa-ruler-combined me-2 text-muted"></i> Tamaño y copias
                                                                        </button>
                                                                    </h2>
                                                                    <div id="labelCollapseSize" class="accordion-collapse collapse show" aria-labelledby="labelAccSize" data-bs-parent="#labelAccordion">
                                                                        <div class="accordion-body">
                                                                            <div class="row g-3">
                                                                                <div class="col-md-6">
                                                                                    <label for="label_paper_size" class="form-label small text-muted">Tamaño</label>
                                                                                    <select id="label_paper_size" name="label_paper_size" class="form-select form-select-sm" style="border-radius: 0.75rem;">
                                                                                        <option value="sticker_4025" <?php echo $lp === 'sticker_4025' ? 'selected' : ''; ?>>40 x 25 mm</option>
                                                                                        <option value="sticker_5025" <?php echo $lp === 'sticker_5025' ? 'selected' : ''; ?>>50 x 25 mm</option>
                                                                                        <option value="sticker_5030" <?php echo $lp === 'sticker_5030' ? 'selected' : ''; ?>>50 x 30 mm</option>
                                                                                        <option value="sticker_6040" <?php echo $lp === 'sticker_6040' ? 'selected' : ''; ?>>60 x 40 mm</option>
                                                                                        <option value="sticker_7050" <?php echo $lp === 'sticker_7050' ? 'selected' : ''; ?>>70 x 50 mm</option>
                                                                                        <option value="sticker_8050" <?php echo $lp === 'sticker_8050' ? 'selected' : ''; ?>>80 x 50 mm</option>
                                                                                        <option value="sticker_10050" <?php echo $lp === 'sticker_10050' ? 'selected' : ''; ?>>100 x 50 mm</option>
                                                                                        <option value="sticker_100150" <?php echo $lp === 'sticker_100150' ? 'selected' : ''; ?>>100 x 150 mm</option>
                                                                                        <option value="custom" <?php echo $lp === 'custom' ? 'selected' : ''; ?>>Personalizado</option>
                                                                                    </select>
                                                                                </div>
                                                                                <div class="col-md-3 col-6">
                                                                                    <label for="label_padding_mm" class="form-label small text-muted">Margen (mm)</label>
                                                                                    <input type="number" min="0" max="10" step="0.5" id="label_padding_mm" name="label_padding_mm" value="<?php echo htmlspecialchars($lpad); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-md-3 col-6">
                                                                                    <label for="label_copies" class="form-label small text-muted">Copias</label>
                                                                                    <input type="number" min="1" max="10" step="1" id="label_copies" name="label_copies" value="<?php echo htmlspecialchars($lcopy); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div id="labelCustomSizeRow" class="col-12">
                                                                                    <div class="row g-2">
                                                                                        <div class="col-md-3 col-6">
                                                                                            <label for="label_custom_width_mm" class="form-label small text-muted">Ancho (mm)</label>
                                                                                            <input type="number" min="20" max="120" step="1" id="label_custom_width_mm" name="label_custom_width_mm" value="<?php echo htmlspecialchars($lw); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                        </div>
                                                                                        <div class="col-md-3 col-6">
                                                                                            <label for="label_custom_height_mm" class="form-label small text-muted">Alto (mm)</label>
                                                                                            <input type="number" min="15" max="200" step="1" id="label_custom_height_mm" name="label_custom_height_mm" value="<?php echo htmlspecialchars($lh); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div class="accordion-item border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden;">
                                                                    <h2 class="accordion-header" id="labelAccContent">
                                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#labelCollapseContent" aria-expanded="false" aria-controls="labelCollapseContent">
                                                                            <i class="fas fa-list-check me-2 text-muted"></i> Contenido visible
                                                                        </button>
                                                                    </h2>
                                                                    <div id="labelCollapseContent" class="accordion-collapse collapse" aria-labelledby="labelAccContent" data-bs-parent="#labelAccordion">
                                                                        <div class="accordion-body">
                                                                            <div class="row g-2">
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_qr" name="label_show_qr" value="1" <?php echo $lsq === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_qr">QR (abre el portal del cliente)</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_client" name="label_show_client" value="1" <?php echo $lsc === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_client">Cliente</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_client_phone" name="label_show_client_phone" value="1" <?php echo $lscp === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_client_phone">Teléfono</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_serial" name="label_show_serial" value="1" <?php echo $lss === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_serial">Serie / IMEI</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_date" name="label_show_date" value="1" <?php echo $lsd === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_date">Fecha ingreso</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_device_type" name="label_show_device_type" value="1" <?php echo $lsdt === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_device_type">Tipo de equipo</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_device" name="label_show_device" value="1" <?php echo $lsdev === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_device">Referencia (Marca/Modelo)</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_issue" name="label_show_issue" value="1" <?php echo $lsi === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_issue">Falla / Problema</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_accessories" name="label_show_accessories" value="1" <?php echo $lsac === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_accessories">Accesorios</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_client_observations" name="label_show_client_observations" value="1" <?php echo $lsco === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_client_observations">Obs. cliente</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <div class="form-check form-switch">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_tech_notes" name="label_show_tech_notes" value="1" <?php echo $lstn === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_tech_notes">Notas técnicas</label>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div class="accordion-item border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden;">
                                                                    <h2 class="accordion-header" id="labelAccDesign">
                                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#labelCollapseDesign" aria-expanded="false" aria-controls="labelCollapseDesign">
                                                                            <i class="fas fa-wand-magic-sparkles me-2 text-muted"></i> Diseño y tipografía
                                                                        </button>
                                                                    </h2>
                                                                    <div id="labelCollapseDesign" class="accordion-collapse collapse" aria-labelledby="labelAccDesign" data-bs-parent="#labelAccordion">
                                                                        <div class="accordion-body">
                                                                            <div class="row g-3">
                                                                                <div class="col-md-6">
                                                                                    <label for="label_font_family" class="form-label small text-muted">Fuente</label>
                                                                                    <select id="label_font_family" name="label_font_family" class="form-select form-select-sm" style="border-radius: 0.75rem;">
                                                                                        <option value="arial" <?php echo $lfont === 'arial' ? 'selected' : ''; ?>>Arial</option>
                                                                                        <option value="tahoma" <?php echo $lfont === 'tahoma' ? 'selected' : ''; ?>>Tahoma</option>
                                                                                        <option value="verdana" <?php echo $lfont === 'verdana' ? 'selected' : ''; ?>>Verdana</option>
                                                                                    </select>
                                                                                </div>
                                                                                <div class="col-md-6">
                                                                                    <label for="label_layout" class="form-label small text-muted">Diseño de QR</label>
                                                                                    <select id="label_layout" name="label_layout" class="form-select form-select-sm" style="border-radius: 0.75rem;">
                                                                                        <option value="no_qr" <?php echo $llayout === 'no_qr' ? 'selected' : ''; ?>>Sin QR</option>
                                                                                        <option value="qr_bottom" <?php echo $llayout === 'qr_bottom' ? 'selected' : ''; ?>>QR abajo</option>
                                                                                        <option value="qr_right" <?php echo $llayout === 'qr_right' ? 'selected' : ''; ?>>QR derecha arriba</option>
                                                                                    </select>
                                                                                </div>
                                                                                <div class="col-md-4 col-6">
                                                                                    <label for="label_multiline_lines" class="form-label small text-muted">Máx. líneas</label>
                                                                                    <input type="number" min="1" max="5" step="1" id="label_multiline_lines" name="label_multiline_lines" value="<?php echo htmlspecialchars($lclamp); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-md-4 col-6">
                                                                                    <label for="label_qr_mm" class="form-label small text-muted">QR (mm)</label>
                                                                                    <input type="number" min="0" max="60" step="1" id="label_qr_mm" name="label_qr_mm" value="<?php echo htmlspecialchars($lqr); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-md-4 col-12">
                                                                                    <div class="form-check form-switch mt-4 pt-2">
                                                                                        <input class="form-check-input" type="checkbox" id="label_show_logo" name="label_show_logo" value="1" <?php echo $lshowlogo === '1' ? 'checked' : ''; ?>>
                                                                                        <label class="form-check-label" for="label_show_logo">Mostrar logo</label>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-4 col-6">
                                                                                    <label for="label_logo_mm" class="form-label small text-muted">Logo (mm)</label>
                                                                                    <input type="number" min="0" max="40" step="1" id="label_logo_mm" name="label_logo_mm" value="<?php echo htmlspecialchars($llogo); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-md-4 col-6">
                                                                                    <label for="label_order_font_pt" class="form-label small text-muted">Orden (pt)</label>
                                                                                    <input type="number" min="8" max="40" step="0.5" id="label_order_font_pt" name="label_order_font_pt" value="<?php echo htmlspecialchars($lorder); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-md-4 col-6">
                                                                                    <label for="label_line_font_pt" class="form-label small text-muted">Texto (pt)</label>
                                                                                    <input type="number" min="6" max="30" step="0.5" id="label_line_font_pt" name="label_line_font_pt" value="<?php echo htmlspecialchars($lline); ?>" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                                </div>
                                                                                <div class="col-12">
                                                                                    <div class="text-muted small">En etiquetas pequeñas, evita activar demasiadas líneas.</div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div class="accordion-item border-0 shadow-sm" style="border-radius: 1rem; overflow: hidden;">
                                                                    <h2 class="accordion-header" id="labelAccOrder">
                                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#labelCollapseOrder" aria-expanded="false" aria-controls="labelCollapseOrder">
                                                                            <i class="fas fa-grip-vertical me-2 text-muted"></i> Orden de líneas
                                                                        </button>
                                                                    </h2>
                                                                    <div id="labelCollapseOrder" class="accordion-collapse collapse" aria-labelledby="labelAccOrder" data-bs-parent="#labelAccordion">
                                                                        <div class="accordion-body">
                                                                            <?php
                                                                            $orderDefault = 'client,device_type,device,serial,issue,client_observations,tech_notes,accessories,date';
                                                                            $labelOrder = (string)($system_config['label_element_order'] ?? $orderDefault);
                                                                            $labelOrder = preg_replace('/[^a-z0-9_,]/', '', $labelOrder);
                                                                            ?>
                                                                            <input type="hidden" id="label_element_order" name="label_element_order" value="<?php echo htmlspecialchars($labelOrder); ?>">
                                                                            <div class="text-muted small mb-2">Arrastra para reordenar.</div>
                                                                            <div id="labelOrderList" class="list-group" style="border-radius: 0.9rem; overflow: hidden;">
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="client" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Cliente</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="device_type" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Tipo de equipo</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="device" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Marca / Modelo</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="serial" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Serie / IMEI</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="issue" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Falla / Problema</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="client_observations" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Observaciones (cliente)</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="tech_notes" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Notas técnicas</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="accessories" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Accesorios</span>
                                                                                </div>
                                                                                <div class="list-group-item py-2 d-flex align-items-center gap-2" data-key="date" draggable="true" style="cursor: grab;">
                                                                                    <span class="text-muted"><i class="fas fa-grip-vertical"></i></span>
                                                                                    <span>Fecha de ingreso</span>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="col-lg-5">
                                                            <div class="card border-0 shadow-sm h-100" style="border-radius: 1rem; position: sticky; top: 92px;">
                                                                <div class="card-body p-3">
                                                                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                                                        <div>
                                                                            <div class="fw-bold text-dark">Vista previa</div>
                                                                            <div class="text-muted small">Usa una orden real para probar.</div>
                                                                        </div>
                                                                        <span id="labelPreviewScale" class="text-muted small"></span>
                                                                    </div>
                                                                    <div class="row g-2 align-items-end">
                                                                        <div class="col-6">
                                                                            <label for="label_preview_order_id" class="form-label small text-muted">Orden (ID)</label>
                                                                            <input type="number" min="1" step="1" id="label_preview_order_id" class="form-control form-control-sm" style="border-radius: 0.75rem;">
                                                                        </div>
                                                                        <div class="col-6 d-flex gap-2 justify-content-end">
                                                                            <button type="button" class="btn btn-outline-dark btn-sm rounded-pill" onclick="previewLabel(false)">
                                                                                <i class="fas fa-eye me-2"></i>Ver
                                                                            </button>
                                                                            <button type="button" class="btn btn-dark btn-sm rounded-pill" onclick="previewLabel(true)">
                                                                                <i class="fas fa-print me-2"></i>Imprimir
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                    <div class="border rounded-3 overflow-hidden mt-2" style="background: radial-gradient(circle at 20px 20px, #f8fafc 0, #f8fafc 12px, #eef2f7 12px, #eef2f7 24px);">
                                                                        <div id="labelPreviewArea" style="position: relative; width: 100%; height: 320px; overflow: auto; padding: 12px; display: flex; align-items: center; justify-content: center;">
                                                                            <div id="labelPreviewStage" style="position: relative; transform-origin: center center; border-radius: 0; border: 1px dashed rgba(15,23,42,.35); background: transparent;">
                                                                                <iframe id="labelPreviewFrame" title="Vista previa etiqueta" style="border:0; display:block; background: #ffffff; border-radius: 0;" scrolling="no"></iframe>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="text-muted small mt-2">La vista previa no guarda cambios.</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Moved Warranty Section -->
                                            <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                    <h5 class="mb-0 text-dark">
                                                        <i class="fas fa-shield-alt me-2"></i>Garantía
                                                    </h5>
                                                </div>
                                                <div class="card-body p-4">
                                                    <div class="row g-4">
                                                        <div class="col-md-4">
                                                            <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                                                <div class="card-body p-4">
                                                                    <div class="d-flex align-items-center mb-3 justify-content-center">
                                                                        <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                            <i class="fas fa-hourglass-half text-warning fa-lg"></i>
                                                                        </div>
                                                                        <h6 class="mb-0 text-dark fw-bold">Tiempos</h6>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="warranty_days" class="form-label small text-muted">Periodo de garantía (días)</label>
                                                                        <input type="number" min="0" id="warranty_days" name="warranty_days"
                                                                            value="<?php echo htmlspecialchars($system_config['warranty_days'] ?? '30'); ?>" class="form-control form-control-sm text-center fw-bold" style="border-radius: 0.5rem;">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="invoice_due_days_default" class="form-label small text-muted">Plazo de cobro por defecto (días)</label>
                                                                        <input type="number" min="0" id="invoice_due_days_default" name="invoice_due_days_default"
                                                                            value="<?php echo htmlspecialchars($system_config['invoice_due_days_default'] ?? '7'); ?>" class="form-control form-control-sm text-center fw-bold" style="border-radius: 0.5rem;">
                                                                    </div>
                                                                    <div>
                                                                        <label for="abandon_days" class="form-label small text-muted">Abandono tras (días)</label>
                                                                        <input type="number" min="0" id="abandon_days" name="abandon_days"
                                                                            value="<?php echo htmlspecialchars($system_config['abandon_days'] ?? '60'); ?>" class="form-control form-control-sm text-center fw-bold" style="border-radius: 0.5rem;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-8">
                                                            <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                                                <div class="card-body p-4">
                                                                    <div class="d-flex align-items-center mb-3 justify-content-center">
                                                                        <div class="rounded-circle bg-secondary bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                            <i class="fas fa-file-contract text-secondary fa-lg"></i>
                                                                        </div>
                                                                        <h6 class="mb-0 text-dark fw-bold">Términos y Condiciones</h6>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="warranty_text" class="form-label small text-muted">Texto de garantía (opcional)</label>
                                                                        <textarea id="warranty_text" name="warranty_text" rows="2" class="form-control form-control-sm" placeholder="Texto personalizado de cobertura" style="border-radius: 0.5rem;"><?php echo htmlspecialchars($system_config['warranty_text'] ?? ''); ?></textarea>
                                                                    </div>
                                                                    <div>
                                                                        <label for="warranty_disclaimers" class="form-label small text-muted">Excepciones (una por línea)</label>
                                                                        <textarea id="warranty_disclaimers" name="warranty_disclaimers" rows="3" class="form-control form-control-sm" placeholder="Ej.: No aplica por humedad...&#10;Ej.: Manipulación de terceros anula garantía" style="border-radius: 0.5rem;"><?php echo htmlspecialchars($system_config['warranty_disclaimers'] ?? ''); ?></textarea>
                                                                        <small class="text-muted d-block text-end mt-1" style="font-size: 0.7rem;">Se mostrarán como lista en la impresión.</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                             </div>
                                             
                                             <div class="row">
                                                 <div class="col-12 text-end">
                                                     <button type="button" class="btn btn-dark rounded-pill shadow" onclick="saveDocumentsConfig()">
                                                         <i class="fas fa-save me-2"></i>Guardar Documentos
                                                     </button>
                                                 </div>
                                             </div>
                                         </div>
                                     </div>
                                     </form>
                                </div>
                                
                                <!-- TAB: WhatsApp (#whatsapp) -->
                                <div class="tab-pane fade" id="whatsapp" role="tabpanel" aria-labelledby="whatsapp-tab">
                                    <?php include __DIR__ . '/../views/settings/whatsapp.php'; ?>
                                </div>

                                <!-- TAB: Apariencia (#appearance) -->
                                <div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="appearance-tab">
                                    <?php include __DIR__ . '/../views/settings/appearance.php'; ?>
                                </div>

                                <!-- Pestaña Configuración de Empresa -->
                                <!-- TAB: Empresa (#company) -->
                                <div class="tab-pane active" id="company" role="tabpanel">
                                    <div class="row mt-4">
                                        <div class="col-md-8">
                                            <form id="companyForm" enctype="multipart/form-data">
                                                <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                        <h5 class="mb-0 text-dark">
                                                            <i class="fas fa-building me-2 text-dark"></i>Información de la Empresa
                                                        </h5>
                                                    </div>
                                                    <div class="card-body p-4">
                                                        <div class="row">
                                                            <div class="col-12">
                                                                <div class="mb-3 text-center">
                                                                    <input type="file" id="company_logo" name="company_logo" accept="image/*" class="d-none" onchange="previewCompanyLogo(event)">
                                                                    <div class="position-relative d-inline-block mt-1">
                                                                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-light border" style="width: clamp(96px, 18vw, 140px); height: clamp(96px, 18vw, 140px); overflow: hidden;">
                                                                            <img id="company-logo-circle" src="../assets/img/<?php echo($company_config['company_logo'] ?? 'company_logo.png') . '?v=' . time(); ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: cover;">
                                                                        </div>
                                                                        <button type="button" class="btn btn-dark rounded-circle position-absolute shadow-sm d-flex align-items-center justify-content-center" style="bottom: -6px; left: 0; right: 0; margin: 0 auto; width: 44px; height: 44px; z-index: 2;" onclick="document.getElementById('company_logo').click()">
                                                                            <i class="fas fa-camera text-white" style="font-size: 1rem; line-height: 1;"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="mt-3">
                                                                        <small class="text-muted">Haga clic en la cámara para cambiar el logo. Formatos: PNG, JPG.</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        
                                                        
                                                        </div>
                                                        
                                                        
                                                        <div class="row g-4 mt-2">
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label for="company_name" class="form-label small text-muted">Nombre de la Empresa</label>
                                                                    <input type="text" class="form-control form-control-sm bg-light border-0" id="company_name" name="company_name" 
                                                                           value="<?php echo htmlspecialchars($company_config['company_name'] ?? 'Nexar'); ?>" 
                                                                           required style="border-radius:.6rem;" placeholder="Nombre de tu empresa">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label for="company_phone" class="form-label small text-muted">Teléfono</label>
                                                                    <input type="text" class="form-control form-control-sm bg-light border-0" id="company_phone" name="company_phone"
                                                                           value="<?php echo htmlspecialchars($company_config['company_phone'] ?? ''); ?>"
                                                                           placeholder="+57 300 123 4567" style="border-radius:.6rem;">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-4">
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label for="company_email" class="form-label small text-muted">Correo Electrónico</label>
                                                                    <input type="email" class="form-control form-control-sm bg-light border-0" id="company_email" name="company_email" 
                                                                           value="<?php echo htmlspecialchars($company_config['company_email'] ?? ''); ?>"
                                                                           placeholder="correo@miempresa.com" style="border-radius:.6rem;">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label for="company_website" class="form-label small text-muted">Página Web</label>
                                                                    <input type="text" class="form-control form-control-sm bg-light border-0" id="company_website" name="company_website"
                                                                           value="<?php echo htmlspecialchars($company_config['company_website'] ?? ''); ?>"
                                                                           placeholder="www.tuempresa.com" style="border-radius:.6rem;">
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="row mt-2">
                                                            <div class="col-md-12">
                                                                <div class="mb-3">
                                                                    <label for="company_address" class="form-label small text-muted">Dirección Física</label>
                                                                    <input type="text" class="form-control form-control-sm bg-light border-0" id="company_address" name="company_address"
                                                                           value="<?php echo htmlspecialchars($company_config['company_address'] ?? ''); ?>"
                                                                           placeholder="Dirección de tu empresa" style="border-radius:.6rem;">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    
                                                    <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                        <div class="d-flex align-items-center">
                                                            <div class="rounded-circle bg-primary bg-opacity-10 no-theme me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                <i class="fas fa-hashtag text-primary no-theme" style="font-size: 1.1rem;"></i>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold text-dark">Prefijo de órdenes</div>
                                                                <div class="small text-muted">Se mostrará como <span id="order_prefix_preview"><?php echo htmlspecialchars(($system_config['order_prefix'] ?? '') ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $system_config['order_prefix'])) : 'ORD'); ?>-0001</span> <span id="order_prefix_status" class="ms-2"></span></div>
                                                            </div>
                                                        </div>
                                                        <div class="regional-controls" style="gap:.5rem;">
                                                            <div class="input-group input-group-sm">
                                                                <span class="input-group-text bg-light border-end-0 fw-bold">#</span>
                                                                <input type="text" class="form-control border-start-0 text-uppercase" id="order_prefix" name="order_prefix" 
                                                                       value="<?php echo htmlspecialchars($system_config['order_prefix'] ?? ''); ?>" 
                                                                       placeholder="Ej: NEX, ORD" maxlength="6" style="border-radius: 0 0.5rem 0.5rem 0;">
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Inicio de Órdenes -->
                                                    <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                        <div class="d-flex align-items-center">
                                                            <div class="rounded-circle bg-primary bg-opacity-10 no-theme me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                <i class="fas fa-play text-primary no-theme" style="font-size: 1.1rem;"></i>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold text-dark">Inicio de órdenes</div>
                                                                <div class="small text-muted">Próximo número de orden de servicio</div>
                                                            </div>
                                                        </div>
                                                        <div class="regional-controls" style="gap:.5rem;">
                                                            <div class="input-group input-group-sm">
                                                                <span class="input-group-text bg-light border-end-0 fw-bold">#</span>
                                                                <input type="number" min="1" class="form-control border-start-0" id="order_next_number" name="order_next_number" 
                                                                       value="<?php echo htmlspecialchars($system_config['order_next_number'] ?? '1'); ?>" 
                                                                       placeholder="1" style="border-radius: 0 0.5rem 0.5rem 0; width: 100px;">
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Inicio de Facturas -->
                                                    <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                        <div class="d-flex align-items-center">
                                                            <div class="rounded-circle bg-success bg-opacity-10 no-theme me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                <i class="fas fa-file-invoice-dollar text-success no-theme" style="font-size: 1.1rem;"></i>
                                                            </div>
                                                            <div>
                                                                <div class="fw-bold text-dark">Inicio de facturas</div>
                                                                <div class="small text-muted">Próximo número de factura</div>
                                                            </div>
                                                        </div>
                                                        <div class="regional-controls" style="gap:.5rem;">
                                                            <div class="input-group input-group-sm">
                                                                <span class="input-group-text bg-light border-end-0 fw-bold">#</span>
                                                                <input type="number" min="1" class="form-control border-start-0" id="invoice_next_number" name="invoice_next_number" 
                                                                       value="<?php echo htmlspecialchars($system_config['invoice_next_number'] ?? '1'); ?>" 
                                                                       placeholder="1" style="border-radius: 0 0.5rem 0.5rem 0; width: 100px;">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <script>
                                                    (function(){
                                                        var t;
                                                        function parseJsonResponse(response){
                                                            return response.text().then(function(text){
                                                                const ct = response.headers.get('content-type') || '';
                                                                if (ct.indexOf('application/json') === -1) {
                                                                    return { success:false, message:'Respuesta no JSON', raw:text };
                                                                }
                                                                try { return JSON.parse(text); }
                                                                catch(e){ return { success:false, message:'JSON inválido', raw:text }; }
                                                            });
                                                        }
                                                        function up(){
                                                            var el = document.getElementById('order_prefix');
                                                            var pv = document.getElementById('order_prefix_preview');
                                                            var st = document.getElementById('order_prefix_status');
                                                            if (!el || !pv) return;
                                                            var v = String(el.value || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
                                                            if (v === '') v = 'ORD';
                                                            var nextNumEl = document.getElementById('order_next_number');
                                                            var nextNum = nextNumEl ? nextNumEl.value : '1';
                                                            pv.textContent = v + '-' + String(nextNum).padStart(4, '0');
                                                            clearTimeout(t);
                                                            t = setTimeout(function(){
                                                                var fd = new FormData();
                                                                fd.append('action','check_order_prefix');
                                                                fd.append('prefix', String(el.value || '').trim());
                                                                fetch('config_operations.php',{method:'POST', body:fd})
                                                                    .then(function(r){ return parseJsonResponse(r); })
                                                                    .then(function(d){
                                                                        if (!st) return;
                                                                        if (d && d.success && d.available) { st.textContent = 'Disponible'; st.className = 'ms-2 text-success'; }
                                                                        else if (d && d.success && !d.available) { st.textContent = 'No disponible'; st.className = 'ms-2 text-danger'; }
                                                                        else { st.textContent = ''; st.className = 'ms-2'; }
                                                                        var inputEl = document.getElementById('order_prefix');
                                                                        if (inputEl) {
                                                                            if (d && d.success && !d.available) {
                                                                                inputEl.classList.add('is-invalid');
                                                                            } else {
                                                                                inputEl.classList.remove('is-invalid');
                                                                            }
                                                                        }
                                                                    })
                                                                    .catch(function(){ if(st){ st.textContent=''; st.className='ms-2'; } });
                                                            }, 300);
                                                        }
                                                        document.addEventListener('DOMContentLoaded', up);
                                                        document.getElementById('order_prefix')?.addEventListener('input', up);
                                                        document.getElementById('order_prefix')?.addEventListener('change', up);
                                                        document.getElementById('order_next_number')?.addEventListener('input', up);
                                                        document.getElementById('order_next_number')?.addEventListener('change', up);
                                                    })();
                                                    </script>
                                                        
<!-- Botón eliminado para guardado unificado -->
<!--
<div class="text-end mt-4">
    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
        <i class="fas fa-save me-2"></i>Guardar Empresa
    </button>
</div>
-->
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-md-4">
                                            <form id="regionalForm">
                                                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                                                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                        <h5 class="mb-0 text-dark">
                                                            <i class="fas fa-globe me-2 text-dark"></i>Configuración Regional
                                                        </h5>
                                                    </div>
                                                    <div class="card-body p-4">
                                                        <style>
                                                            @media (max-width: 992px){
                                                                .regional-controls{ flex:1 1 100%; margin-top:.5rem; }
                                                            }
                                                            .regional-controls{ display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.5rem; }
                                                            .regional-controls .form-select,
                                                            .regional-controls .form-control,
                                                            .regional-controls .input-group { width: auto; flex: 0 0 auto; }
                                                            /* límites por control para evitar desbordes */
                                                            #currency.regional-select { max-width: 90px; }
                                                            #currency_symbol { max-width: 70px; }
                                                            #phone_country.regional-select { max-width: 130px; }
                                                            .input-group#phone_prefix_group { min-width: 100px; max-width: 110px; }
                                                            #phone_prefix_group .form-control { width: 72px; min-width: 72px; }
                                                            #timezone.regional-select { max-width: 190px; }
                                                            #tax_name { max-width: 120px; }
                                                            .input-group#tax_rate_group { max-width: 120px; }
                                                        </style>
                                                        <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                            <div class="d-flex align-items-center">
                                                                <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                    <i class="fas fa-dollar-sign text-success fa-lg"></i>
                                                                </div>
                                                                <div>
                                                                    <div class="fw-bold text-dark">Moneda</div>
                                                                    <div class="small text-muted">Divisa principal del sistema</div>
                                                                </div>
                                                            </div>
                                                            <div class="d-flex align-items-center regional-controls" style="gap:.5rem;">
                                                                <?php
$currencies = [
    'ARS' => ['symbol' => '$', 'name' => 'Peso Argentino'],
    'BOB' => ['symbol' => 'Bs', 'name' => 'Boliviano'],
    'BRL' => ['symbol' => 'R$', 'name' => 'Real Brasileño'],
    'CLP' => ['symbol' => '$', 'name' => 'Peso Chileno'],
    'COP' => ['symbol' => '$', 'name' => 'Peso Colombiano'],
    'CRC' => ['symbol' => '₡', 'name' => 'Colón Costarricense'],
    'CUP' => ['symbol' => '₱', 'name' => 'Peso Cubano'],
    'DOP' => ['symbol' => 'RD$', 'name' => 'Peso Dominicano'],
    'EUR' => ['symbol' => '€', 'name' => 'Euro'],
    'GTQ' => ['symbol' => 'Q', 'name' => 'Quetzal'],
    'HNL' => ['symbol' => 'L', 'name' => 'Lempira'],
    'MXN' => ['symbol' => '$', 'name' => 'Peso Mexicano'],
    'NIO' => ['symbol' => 'C$', 'name' => 'Córdoba'],
    'PAB' => ['symbol' => 'B/.', 'name' => 'Balboa'],
    'PEN' => ['symbol' => 'S/', 'name' => 'Sol'],
    'PYG' => ['symbol' => '₲', 'name' => 'Guaraní'],
    'USD' => ['symbol' => '$', 'name' => 'Dólar Estadounidense'],
    'UYU' => ['symbol' => '$', 'name' => 'Peso Uruguayo'],
    'VES' => ['symbol' => 'Bs', 'name' => 'Bolívar'],
];
$current_currency = $system_config['currency'] ?? 'COP';
?>
                                                                <select id="currency" name="currency" class="form-select form-select-sm fw-bold text-center w-auto regional-select" style="border-radius:.5rem; min-width:72px;" onchange="updateCurrencyDetails()">
                                                                    <?php
foreach ($currencies as $code => $details) {
    $selected = ($current_currency === $code) ? 'selected' : '';
    echo '<option value="' . $code . '" data-symbol="' . $details['symbol'] . '" data-name="' . $details['name'] . '" ' . $selected . '>' . $code . '</option>';
}
if (!array_key_exists($current_currency, $currencies)) {
    echo '<option value="' . htmlspecialchars($current_currency) . '" selected>' . htmlspecialchars($current_currency) . '</option>';
}
?>
                                                                </select>
                                                                <input type="text" id="currency_symbol" name="currency_symbol" 
                                                                       value="<?php echo htmlspecialchars($system_config['currency_symbol'] ?? '$'); ?>" 
                                                                       class="form-control form-control-sm text-center fw-bold w-auto" placeholder="$" maxlength="5" style="border-radius:.5rem; min-width:52px;">
                                                                <input type="hidden" id="currency_name" name="currency_name" value="<?php echo htmlspecialchars($system_config['currency_name'] ?? ''); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                            <div class="d-flex align-items-center">
                                                                <div class="rounded-circle bg-danger bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                    <i class="fas fa-map-marker-alt text-danger fa-lg"></i>
                                                                </div>
                                                                <div>
                                                                    <div class="fw-bold text-dark">Ubicación</div>
                                                                    <div class="small text-muted">País y prefijo telefónico</div>
                                                                </div>
                                                            </div>
                                                            <div class="d-flex align-items-center regional-controls" style="gap:.5rem;">
                                                                <?php
$current_country = $system_config['phone_country'] ?? 'Colombia';
$countries = [
    'Argentina' => ['code' => '+54', 'currency' => 'ARS'],
    'Bolivia' => ['code' => '+591', 'currency' => 'BOB'],
    'Brasil' => ['code' => '+55', 'currency' => 'BRL'],
    'Chile' => ['code' => '+56', 'currency' => 'CLP'],
    'Colombia' => ['code' => '+57', 'currency' => 'COP'],
    'Costa Rica' => ['code' => '+506', 'currency' => 'CRC'],
    'Cuba' => ['code' => '+53', 'currency' => 'CUP'],
    'Ecuador' => ['code' => '+593', 'currency' => 'USD'],
    'El Salvador' => ['code' => '+503', 'currency' => 'USD'],
    'España' => ['code' => '+34', 'currency' => 'EUR'],
    'Estados Unidos' => ['code' => '+1', 'currency' => 'USD'],
    'Guatemala' => ['code' => '+502', 'currency' => 'GTQ'],
    'Honduras' => ['code' => '+504', 'currency' => 'HNL'],
    'México' => ['code' => '+52', 'currency' => 'MXN'],
    'Nicaragua' => ['code' => '+505', 'currency' => 'NIO'],
    'Panamá' => ['code' => '+507', 'currency' => 'USD'],
    'Paraguay' => ['code' => '+595', 'currency' => 'PYG'],
    'Perú' => ['code' => '+51', 'currency' => 'PEN'],
    'Puerto Rico' => ['code' => '+1', 'currency' => 'USD'],
    'República Dominicana' => ['code' => '+1', 'currency' => 'DOP'],
    'Uruguay' => ['code' => '+598', 'currency' => 'UYU'],
    'Venezuela' => ['code' => '+58', 'currency' => 'VES']
];
$found = false;
?>
                                                                <select id="phone_country" name="phone_country" class="form-select form-select-sm w-auto regional-select" style="border-radius:.5rem; min-width:120px;" onchange="updateRegionalSettings()">
                                                                    <?php
foreach ($countries as $country => $data) {
    $selected = ($current_country === $country) ? 'selected' : '';
    if ($selected)
        $found = true;
    echo '<option value="' . $country . '" data-code="' . $data['code'] . '" data-currency="' . $data['currency'] . '" ' . $selected . '>' . $country . '</option>';
}
if (!$found && !empty($current_country)) {
    echo '<option value="' . htmlspecialchars($current_country) . '" selected>' . htmlspecialchars($current_country) . '</option>';
}
?>
                                                                </select>
                                                                <div class="input-group input-group-sm" id="phone_prefix_group" style="width:auto; min-width:92px;">
                                                                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-phone text-dark"></i></span>
                                                                    <input type="text" id="phone_prefix" name="phone_prefix" value="<?php echo htmlspecialchars($system_config['phone_prefix'] ?? '+57'); ?>" class="form-control border-start-0 text-center fw-bold" placeholder="+57" maxlength="10" style="border-radius: 0.5rem;">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                            <div class="d-flex align-items-center">
                                                                <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                    <i class="fas fa-clock text-info fa-lg no-theme"></i>
                                                                </div>
                                                                <div>
                                                                    <div class="fw-bold text-dark">Zona Horaria</div>
                                                                    <div class="small text-muted">Fecha y hora del sistema</div>
                                                                </div>
                                                            </div>
                                                            <div class="d-flex align-items-center regional-controls" style="gap:.5rem;">
                                                                <?php $tz = $system_config['timezone'] ?? 'America/Bogota'; ?>
                                                                <select id="timezone" name="timezone" class="form-select form-select-sm w-auto regional-select" style="border-radius:.5rem; min-width:150px;">
                                                                    <?php
$timezones = ['America/Bogota', 'America/Mexico_City', 'America/Lima', 'America/Caracas', 'America/Argentina/Buenos_Aires', 'Europe/Madrid', 'UTC'];
foreach ($timezones as $z) {
    $selected = ($tz === $z) ? 'selected' : '';
    echo '<option value="' . $z . '" ' . $selected . '>' . $z . '</option>';
}
?>
                                                                </select>
                                                                <div class="form-check form-switch m-0">
                                                                    <input class="form-check-input" type="checkbox" id="time_format_toggle" <?php echo(($system_config['time_format'] ?? '12') === '12') ? 'checked' : ''; ?>>
                                                                </div>
                                                                <span class="small d-none d-lg-inline">12h</span>
                                                                <input type="hidden" id="time_format" name="time_format" value="<?php echo($system_config['time_format'] ?? '12'); ?>">
                                                                <input type="hidden" id="date_format" name="date_format" value="<?php echo($system_config['date_format'] ?? 'd/m/Y'); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 bg-light" style="border-color:#eee; flex-wrap: wrap; overflow:hidden;">
                                                            <div class="d-flex align-items-center">
                                                                <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                    <i class="fas fa-percentage text-warning fa-lg"></i>
                                                                </div>
                                                                <div>
                                                                    <div class="fw-bold text-dark">Impuestos</div>
                                                                    <div class="small text-muted">Configuración fiscal</div>
                                                                </div>
                                                            </div>
                                                            <div class="d-flex align-items-center regional-controls" style="gap:.5rem;">
                                                                <div class="form-check form-switch m-0">
                                                                    <input class="form-check-input" type="checkbox" id="tax_enabled" name="tax_enabled" <?php echo($system_config['tax_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                                                </div>
                                                                <input type="text" class="form-control form-control-sm" id="tax_name" name="tax_name" value="<?php echo htmlspecialchars($system_config['tax_name'] ?? 'IVA'); ?>" placeholder="IVA" style="width:100px;">
                                                                <div class="input-group input-group-sm" id="tax_rate_group" style="width:auto; min-width:96px;">
                                                                    <input type="number" step="0.01" min="0" max="100" class="form-control text-center fw-bold" id="tax_rate" name="tax_rate" value="<?php echo htmlspecialchars($system_config['tax_rate'] ?? '19'); ?>" placeholder="19">
                                                                    <span class="input-group-text bg-light border-start-0"><i class="fas fa-percent text-muted"></i></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    
<!-- Div eliminado: Causaba cierre prematuro de la pestaña empresa -->
<?php if (false): ?>
                                    <hr class="my-5">
                                        <div class="row mt-4">
                                            <div class="col-12">
                                            
                                            <form id="regionalForm">
                                                <!-- Configuraciones Regionales -->
                                                <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                        <h5 class="mb-0 text-dark">
                                                            <i class="fas fa-globe me-2"></i>Configuraciones Regionales
                                                        </h5>
                                                    </div>
                                                    <div class="card-body p-4">
                                                        <div class="row g-4">
                                                            <!-- Moneda -->
                                                            <div class="col-md-4">
                                                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                                                    <div class="card-body p-4">
                                                                        <div class="d-flex align-items-center mb-3 justify-content-center">
                                                                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                                <i class="fas fa-dollar-sign text-success fa-lg"></i>
                                                                            </div>
                                                                            <h6 class="mb-0 text-dark fw-bold">Moneda</h6>
                                                                        </div>
                                                                        <div class="row g-2">
                                                                            <?php
    $currencies = [
        'ARS' => ['symbol' => '$', 'name' => 'Peso Argentino'],
        'BOB' => ['symbol' => 'Bs', 'name' => 'Boliviano'],
        'BRL' => ['symbol' => 'R$', 'name' => 'Real Brasileño'],
        'CLP' => ['symbol' => '$', 'name' => 'Peso Chileno'],
        'COP' => ['symbol' => '$', 'name' => 'Peso Colombiano'],
        'CRC' => ['symbol' => '₡', 'name' => 'Colón Costarricense'],
        'CUP' => ['symbol' => '₱', 'name' => 'Peso Cubano'],
        'DOP' => ['symbol' => 'RD$', 'name' => 'Peso Dominicano'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'GTQ' => ['symbol' => 'Q', 'name' => 'Quetzal'],
        'HNL' => ['symbol' => 'L', 'name' => 'Lempira'],
        'MXN' => ['symbol' => '$', 'name' => 'Peso Mexicano'],
        'NIO' => ['symbol' => 'C$', 'name' => 'Córdoba'],
        'PAB' => ['symbol' => 'B/.', 'name' => 'Balboa'],
        'PEN' => ['symbol' => 'S/', 'name' => 'Sol'],
        'PYG' => ['symbol' => '₲', 'name' => 'Guaraní'],
        'USD' => ['symbol' => '$', 'name' => 'Dólar Estadounidense'],
        'UYU' => ['symbol' => '$', 'name' => 'Peso Uruguayo'],
        'VES' => ['symbol' => 'Bs', 'name' => 'Bolívar'],
    ];
    $current_currency = $system_config['currency'] ?? 'COP';
?>
                                                                            <div class="col-4">
                                                                                <label for="currency" class="form-label small text-muted">Código</label>
                                                                                <select id="currency" name="currency" class="form-select form-select-sm fw-bold text-center" style="border-radius: 0.5rem;" onchange="updateCurrencyDetails()">
                                                                                    <?php
    foreach ($currencies as $code => $details) {
        $selected = ($current_currency === $code) ? 'selected' : '';
        echo '<option value="' . $code . '" data-symbol="' . $details['symbol'] . '" data-name="' . $details['name'] . '" ' . $selected . '>' . $code . '</option>';
    }
    if (!array_key_exists($current_currency, $currencies)) {
        echo '<option value="' . htmlspecialchars($current_currency) . '" selected>' . htmlspecialchars($current_currency) . '</option>';
    }
?>
                                                                                </select>
                                                                            </div>
                                                                            <div class="col-3">
                                                                                <label for="currency_symbol" class="form-label small text-muted">Símbolo</label>
                                                                                <input type="text" id="currency_symbol" name="currency_symbol" 
                                                                                       value="<?php echo htmlspecialchars($system_config['currency_symbol'] ?? '$'); ?>" 
                                                                                       class="form-control form-control-sm text-center fw-bold" placeholder="$" maxlength="5" style="border-radius: 0.5rem;">
                                                                            </div>
                                                                            <div class="col-5">
                                                                                <label for="currency_name" class="form-label small text-muted">Nombre</label>
                                                                                <input type="text" id="currency_name" name="currency_name" 
                                                                                       value="<?php echo htmlspecialchars($system_config['currency_name'] ?? 'Peso Colombiano'); ?>" 
                                                                                       class="form-control form-control-sm" placeholder="Peso Colombiano" style="border-radius: 0.5rem;">
                                                                            </div>
                                                                            <script>
                                                                            function updateCurrencyDetails() {
                                                                                var select = document.getElementById('currency');
                                                                                var selectedOption = select.options[select.selectedIndex];
                                                                                var symbol = selectedOption.getAttribute('data-symbol');
                                                                                var name = selectedOption.getAttribute('data-name');
                                                                                
                                                                                if (symbol) document.getElementById('currency_symbol').value = symbol;
                                                                                if (name) document.getElementById('currency_name').value = name;
                                                                                
                                                                                updateCurrencyPreview();
                                                                            }
                                                                            
                                                                            function updateCurrencyPreview() {
                                                                                var symbol = document.getElementById('currency_symbol').value;
                                                                                var preview = document.getElementById('currency_preview');
                                                                                if (preview) {
                                                                                    preview.innerText = symbol + ' 1,234.56';
                                                                                }
                                                                            }
                                                                            
                                                                            document.addEventListener('DOMContentLoaded', function() {
                                                                                updateCurrencyPreview();
                                                                                var symbolInput = document.getElementById('currency_symbol');
                                                                                if (symbolInput) {
                                                                                    symbolInput.addEventListener('input', updateCurrencyPreview);
                                                                                }
                                                                            });
                                                                            </script>
                                                                        </div>
                                                                        <div class="mt-3">
                                                                            <div class="d-flex align-items-center justify-content-center p-2 rounded bg-light bg-opacity-50 border-0">
                                                                                <span class="small text-muted me-2">Vista Previa:</span>
                                                                                <span id="currency_preview" class="fw-bold text-success">
                                                                                    <?php echo htmlspecialchars($system_config['currency_symbol'] ?? '$'); ?> 1,234.56
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <!-- Teléfono -->
                                                            <div class="col-md-4">
                                                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                                                    <div class="card-body p-4">
                                                                        <div class="d-flex align-items-center mb-3 justify-content-center">
                                                                            <div class="rounded-circle bg-danger bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                                <i class="fas fa-map-marker-alt text-danger fa-lg"></i>
                                                                            </div>
                                                                            <h6 class="mb-0 text-dark fw-bold">Ubicación</h6>
                                                                        </div>
                                                                        <div class="row g-2">
                                                                            <div class="col-4">
                                                                                <label for="phone_prefix" class="form-label small text-muted">Indicativo</label>
                                                                                <input type="text" id="phone_prefix" name="phone_prefix" 
                                                                                       value="<?php echo htmlspecialchars($system_config['phone_prefix'] ?? '+57'); ?>" 
                                                                                       class="form-control form-control-sm text-center fw-bold" placeholder="+57" maxlength="10" style="border-radius: 0.5rem;">
                                                                            </div>
                                                                            <div class="col-8">
                                                                                <label for="phone_country" class="form-label small text-muted">País</label>
                                                                                <select id="phone_country" name="phone_country" class="form-select form-select-sm" style="border-radius: 0.5rem;" onchange="updateRegionalSettings()">
                                                                                    <?php
    $current_country = $system_config['phone_country'] ?? 'Colombia';
    $countries = [
        'Argentina' => ['code' => '+54', 'currency' => 'ARS'],
        'Bolivia' => ['code' => '+591', 'currency' => 'BOB'],
        'Brasil' => ['code' => '+55', 'currency' => 'BRL'],
        'Chile' => ['code' => '+56', 'currency' => 'CLP'],
        'Colombia' => ['code' => '+57', 'currency' => 'COP'],
        'Costa Rica' => ['code' => '+506', 'currency' => 'CRC'],
        'Cuba' => ['code' => '+53', 'currency' => 'CUP'],
        'Ecuador' => ['code' => '+593', 'currency' => 'USD'],
        'El Salvador' => ['code' => '+503', 'currency' => 'USD'],
        'España' => ['code' => '+34', 'currency' => 'EUR'],
        'Estados Unidos' => ['code' => '+1', 'currency' => 'USD'],
        'Guatemala' => ['code' => '+502', 'currency' => 'GTQ'],
        'Honduras' => ['code' => '+504', 'currency' => 'HNL'],
        'México' => ['code' => '+52', 'currency' => 'MXN'],
        'Nicaragua' => ['code' => '+505', 'currency' => 'NIO'],
        'Panamá' => ['code' => '+507', 'currency' => 'USD'],
        'Paraguay' => ['code' => '+595', 'currency' => 'PYG'],
        'Perú' => ['code' => '+51', 'currency' => 'PEN'],
        'Puerto Rico' => ['code' => '+1', 'currency' => 'USD'],
        'República Dominicana' => ['code' => '+1', 'currency' => 'DOP'],
        'Uruguay' => ['code' => '+598', 'currency' => 'UYU'],
        'Venezuela' => ['code' => '+58', 'currency' => 'VES']
    ];

    $found = false;
    foreach ($countries as $country => $data) {
        $selected = ($current_country === $country) ? 'selected' : '';
        if ($selected)
            $found = true;
        echo '<option value="' . $country . '" data-code="' . $data['code'] . '" data-currency="' . $data['currency'] . '" ' . $selected . '>' . $country . '</option>';
    }

    if (!$found && !empty($current_country)) {
        echo '<option value="' . htmlspecialchars($current_country) . '" selected>' . htmlspecialchars($current_country) . '</option>';
    }
?>
                                                                                </select>
                                                                                <script>
                                                                                function updateRegionalSettings() {
                                                                                    var select = document.getElementById('phone_country');
                                                                                    var selectedOption = select.options[select.selectedIndex];
                                                                                    var code = selectedOption.getAttribute('data-code');
                                                                                    var currency = selectedOption.getAttribute('data-currency');
                                                                                    
                                                                                    if (code) {
                                                                                        document.getElementById('phone_prefix').value = code;
                                                                                    }
                                                                                    
                                                                                    if (currency) {
                                                                                        var currencySelect = document.getElementById('currency');
                                                                                        if (currencySelect) {
                                                                                            currencySelect.value = currency;
                                                                                            // Trigger change event to update details
                                                                                            var event = new Event('change');
                                                                                            currencySelect.dispatchEvent(event);
                                                                                        }
                                                                                    }
                                                                                }
                                                                                </script>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <!-- Fecha y Hora -->
                                                            <div class="col-md-4">
                                                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                                                    <div class="card-body p-4">
                                                                        <div class="d-flex align-items-center mb-3 justify-content-center">
                                                                            <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                                                <i class="fas fa-clock text-info fa-lg no-theme"></i>
                                                                            </div>
                                                                            <h6 class="mb-0 text-dark fw-bold">Fecha y Hora</h6>
                                                                        </div>
                                                                        <div class="mb-3 text-center">
                                                                            <label class="form-label small text-muted d-block">Formato de Hora</label>
                                                                            <div class="btn-group btn-group-sm w-100" role="group">
                                                                                <input type="radio" class="btn-check" name="time_format" id="time_12" value="12" <?php echo($system_config['time_format'] ?? '12') === '12' ? 'checked' : ''; ?>>
                                                                                <label class="btn btn-outline-secondary" for="time_12">12h (AM/PM)</label>

                                                                            <input type="radio" class="btn-check" name="time_format" id="time_24" value="24" <?php echo($system_config['time_format'] ?? '12') === '24' ? 'checked' : ''; ?>>
                                                                            <label class="btn btn-outline-secondary" for="time_24">24h (13:00)</label>
                                                                        </div>
                                                                    </div>
                                                                    <div>
                                                                        <label for="date_format" class="form-label small text-muted">Formato de Fecha</label>
                                                                        <select id="date_format" name="date_format" class="form-select form-select-sm">
                                                                            <option value="d/m/Y" <?php echo($system_config['date_format'] ?? 'd/m/Y') === 'd/m/Y' ? 'selected' : ''; ?>>dd/mm/yyyy</option>
                                                                            <option value="m/d/Y" <?php echo($system_config['date_format'] ?? 'd/m/Y') === 'm/d/Y' ? 'selected' : ''; ?>>mm/dd/yyyy</option>
                                                                            <option value="Y-m-d" <?php echo($system_config['date_format'] ?? 'd/m/Y') === 'Y-m-d' ? 'selected' : ''; ?>>yyyy-mm-dd</option>
                                                                            <option value="d-m-Y" <?php echo($system_config['date_format'] ?? 'd/m/Y') === 'd-m-Y' ? 'selected' : ''; ?>>dd-mm-yyyy</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3">
                                                            <label for="order_prefix" class="form-label small text-muted">Prefijo de órdenes</label>
                                                            <div class="input-group input-group-sm">
                                                                <span class="input-group-text bg-light border-end-0 fw-bold">#</span>
                                                                <input type="text" class="form-control border-start-0 text-uppercase" id="order_prefix" name="order_prefix" 
                                                                       value="<?php echo htmlspecialchars($system_config['order_prefix'] ?? ''); ?>" 
                                                                       placeholder="Ej: NEX, ORD" maxlength="6" style="border-radius: 0 0.5rem 0.5rem 0;">
                                                            </div>
                                                            <small class="text-muted d-block mt-1">Usa letras/números; se mostrará como PREFIJO-0001.</small>
                                                        </div>
                                                    </div>
                                                </div>


                                                
                                                <!-- Configuración de Impuestos -->
                                                <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                                                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                                                        <h5 class="mb-0 text-dark">
                                                            <i class="fas fa-percentage me-2"></i>Impuestos
                                                        </h5>
                                                    </div>
                                                    <div class="card-body p-4">
                                                        <div class="row align-items-center">
                                                            <div class="col-md-4">
                                                                <div class="form-check form-switch card p-3 border-0 bg-light shadow-sm" style="border-radius: 0.5rem;">
                                                                    <input class="form-check-input ms-0 me-3" type="checkbox" id="tax_enabled" name="tax_enabled" 
                                                                           <?php echo($system_config['tax_enabled'] ?? '0') === '1' ? 'checked' : ''; ?> 
                                                                           style="transform: scale(1.3);">
                                                                    <label class="form-check-label fw-bold text-dark pt-1" for="tax_enabled">
                                                                        Habilitar Impuestos
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="mb-3 mb-md-0">
                                                                    <label for="tax_name" class="form-label small text-muted">Nombre del Impuesto</label>
                                                                    <div class="input-group input-group-sm">
                                                                        <span class="input-group-text bg-light border-end-0 fw-bold">T</span>
                                                                        <input type="text" class="form-control border-start-0" id="tax_name" name="tax_name" 
                                                                               value="<?php echo htmlspecialchars($system_config['tax_name'] ?? 'IVA'); ?>" 
                                                                               placeholder="Ej: IVA, IGV" style="border-radius: 0 0.5rem 0.5rem 0;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="mb-0">
                                                                    <label for="tax_rate" class="form-label small text-muted">Porcentaje (%)</label>
                                                                    <div class="input-group input-group-sm">
                                                                        <input type="number" step="0.01" min="0" max="100" class="form-control border-end-0 text-center fw-bold" id="tax_rate" name="tax_rate" 
                                                                               value="<?php echo htmlspecialchars($system_config['tax_rate'] ?? '19'); ?>" 
                                                                               placeholder="19">
                                                                        <span class="input-group-text bg-light border-start-0"><i class="fas fa-percent text-muted"></i></span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>


                                                <!-- Zona de Peligro movida a las acciones inferiores -->
                                                <!-- Vista Previa de Configuración -->
                                                                <div class="card d-none">
                                                    <div class="card-header">
                                                        <h6 class="mb-0"><i class="fas fa-eye me-2"></i>Vista Previa</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="row text-center">
                                                            <div class="col-md-4">
                                                                <div class="border rounded p-3 bg-light">
                                                                    <i class="fas fa-dollar-sign text-dark mb-2"></i>
                                                                    <h6>Moneda</h6>
                                                                    <strong><?php echo htmlspecialchars($system_config['currency_symbol'] ?? '$'); ?> 1,000 <?php echo htmlspecialchars($system_config['currency'] ?? 'COP'); ?></strong>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="border rounded p-3 bg-light">
                                                                    <i class="fas fa-phone text-dark mb-2"></i>
                                                                    <h6>Teléfono</h6>
                                                                    <strong><?php echo htmlspecialchars($system_config['phone_prefix'] ?? '+57'); ?> 300 123 4567</strong>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="border rounded p-3 bg-light">
                                                                    <i class="fas fa-clock text-dark mb-2"></i>
                                                                    <h6>Fecha/Hora</h6>
                                                                    <strong><?php
    $time_format = $system_config['time_format'] ?? '12';
    $date_format = $system_config['date_format'] ?? 'd/m/Y';
    $datetime_format = $time_format === '12' ? $date_format . ' H:i A' : $date_format . ' H:i';
    echo date($datetime_format);
?></strong>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                </div>
                                                
                                            </form>
                                            </div>
                                        </div>
<?php
endif; ?>
                                            
                                            <script>
                                            function previewCompanyLogo(e){
                                                var f = e.target.files && e.target.files[0];
                                                if(!f) return;
                                                var r = new FileReader();
                                                r.onload = function(){
                                                    var img = document.getElementById('company-logo-circle');
                                                    if(img) img.src = r.result;
                                                };
                                                r.readAsDataURL(f);
                                            }
                                            function updateCurrencyDetails(){
                                                var select = document.getElementById('currency');
                                                if(!select) return;
                                                var op = select.options[select.selectedIndex];
                                                var symbol = op ? op.getAttribute('data-symbol') : '';
                                                var name = op ? op.getAttribute('data-name') : '';
                                                var s = document.getElementById('currency_symbol');
                                                var n = document.getElementById('currency_name');
                                                if(s && symbol) s.value = symbol;
                                                if(n && name) n.value = name;
                                            }
                                            function updateRegionalSettings(){
                                                var select = document.getElementById('phone_country');
                                                if(!select) return;
                                                var op = select.options[select.selectedIndex];
                                                var code = op ? op.getAttribute('data-code') : '';
                                                var currency = op ? op.getAttribute('data-currency') : '';
                                                var p = document.getElementById('phone_prefix');
                                                if(p && code) p.value = code;
                                                var c = document.getElementById('currency');
                                                if(c && currency){
                                                    c.value = currency;
                                                    var ev = new Event('change');
                                                    c.dispatchEvent(ev);
                                                }
                                            }
                                            document.addEventListener('DOMContentLoaded', function(){
                                                var tgl = document.getElementById('time_format_toggle');
                                                if(tgl){
                                                    tgl.addEventListener('change', function(){
                                                        var h = document.getElementById('time_format');
                                                        if(h) h.value = tgl.checked ? '12' : '24';
                                                    });
                                                }
                                            });
                                            </script>
                                            
                                            <!-- Acciones Finales: Zona de Peligro y Guardar -->
                                            <div class="row align-items-center mt-5 mb-5 g-4">
                                                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                                                <div class="col-md-6">
                                                    <div class="d-grid gap-2 h-100 align-items-center">
                                                        <button type="button" class="btn btn-outline-danger btn-lg rounded-pill w-100" onclick="openResetOptionsModal()">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>Reseteo de Fábrica
                                                        </button>
                                                        <small class="text-muted text-center">Opciones de reinicio</small>
                                                    </div>
                                                </div>
                                                <?php
endif; ?>

                                                <div class="<?php echo(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 'col-md-6' : 'col-12'; ?>">
                                                    <div class="d-grid gap-2 h-100 align-items-center">
                                                        <button type="button" class="btn btn-dark btn-lg rounded-pill shadow" onclick="saveAllConfigurations()">
                                                            <i class="fas fa-check-circle me-2"></i>Guardar
                                                        </button>
                                                        <small class="text-muted text-center">Guarda los cambios en Configuración Regional y Empresa</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

<!-- Modales movidos al final del archivo -->
                                    
                                <!-- Pestaña Gestión de Usuarios -->
                                <!-- TAB: Usuarios (#users) -->
                                <div class="tab-pane" id="users" role="tabpanel">
                                    <?php include __DIR__ . '/settings_users.php'; ?>
                                </div>
                                
                                <!-- Pestaña Respaldo -->
                                <!-- TAB: Respaldo (#clients-data) -->
                                <?php if ($isAdmin): ?>
                                <div class="tab-pane" id="clients-data" role="tabpanel">
                                    <?php include __DIR__ . '/settings_backup.php'; ?>
                                </div>
                                <?php
endif; ?>
<script>
(function(){
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c]; }); }
    var csrfToken = '';
    try { csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''; } catch(e) { csrfToken = ''; }
    function ensureCsrf(fd){
        try {
            if (!fd || !csrfToken) return;
            if (typeof fd.get === 'function' && fd.get('csrf_token')) return;
            fd.append('csrf_token', csrfToken);
        } catch(e) {}
    }
    function parseJsonResponse(response) {
        return response.text().then(function(text){
            const ct = response.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return { success:false, message:'Respuesta no JSON', raw:text };
            }
            try { return JSON.parse(text); }
            catch(e){ return { success:false, message:'JSON inválido', raw:text }; }
        });
    }
    function fetchJson(fd){
        ensureCsrf(fd);
        return fetch('config_operations.php',{method:'POST', headers:{'Accept':'application/json'}, body:fd, credentials:'same-origin'})
            .then(function(r){ return parseJsonResponse(r); });
    }
    if (typeof window.postJson === 'undefined') {
        window.postJson = function(fd){
            return fetchJson(fd);
        };
    }

    var clientsTabBtn = document.getElementById('clients-data-tab');
    if (clientsTabBtn) {
        clientsTabBtn.addEventListener('shown.bs.tab', function(){
            var pane = document.getElementById('clients-data');
            if (!pane) return;
            pane.querySelectorAll('[data-type="password"]').forEach(function(el){
                try { el.setAttribute('type','password'); } catch(e){}
            });
        });
    }

    function loadMethodsForAccounts(){
        var fd = new FormData(); fd.append('action','payment_methods_list'); fd.append('limit','100'); fd.append('page','1');
        fetchJson(fd).then(function(d){
            var sel = document.getElementById('pa_method'); if (!sel) return; sel.innerHTML='';
            (d.methods||[]).forEach(function(m){ var opt=document.createElement('option'); opt.value=m.id; opt.textContent=m.name; sel.appendChild(opt); });
        }).catch(function(){});
    }
    function renderAccounts(){
        var fd = new FormData(); fd.append('action','payment_accounts_list');
        fetchJson(fd).then(function(d){
            var tbody = document.getElementById('pmAccModalBody'); if (!tbody) return; tbody.innerHTML='';
            (d.accounts||[]).forEach(function(a){
                var tr=document.createElement('tr');
                var active = (parseInt(a.is_active||1)===1);
                var def = (parseInt(a.is_default||0)===1);
                tr.innerHTML = '<td>'+esc(a.alias||a.account_name||'')+'</td>'
                    + '<td>'+esc(a.account_number||'')+'</td>'
                    + '<td>'+esc(a.type||'')+'</td>'
                    + '<td>'+esc(a.holder_name||'')+'</td>'
                    + '<td><span class="badge '+(active?'bg-success':'bg-secondary')+'">'+(active?'Activo':'Inactivo')+'</span></td>'
                    + '<td><span class="badge '+(def?'bg-dark':'bg-light text-dark')+'">'+(def?'Sí':'No')+'</span></td>'
                    + '<td>'
                        + '<button class="btn btn-sm btn-outline-dark" data-action="acc-edit" data-id="'+a.id+'" data-method="'+esc(a.method_name||a.method_id)+'" data-alias="'+esc(a.alias||a.account_name||'')+'" data-number="'+esc(a.account_number||'')+'" data-type="'+esc(a.type||'')+'" data-holder="'+esc(a.holder_name||'')+'" data-holder_id="'+esc(a.holder_id||'')+'" data-default="'+(def?1:0)+'" data-active="'+(active?1:0)+'"><i class="fas fa-edit"></i></button> '
                        + '<button class="btn btn-sm '+(active?'btn-outline-secondary':'btn-outline-success')+'" data-action="acc-toggle" data-id="'+a.id+'" data-next="'+(active?'inactive':'active')+'"><i class="fas '+(active?'fa-eye-slash':'fa-eye')+'"></i></button> '
                        + '<button class="btn btn-sm '+(def?'btn-outline-secondary':'btn-outline-info')+'" data-action="acc-default" data-id="'+a.id+'"><i class="fas fa-star"></i></button> '
                        + '<button class="btn btn-sm btn-outline-danger" data-action="acc-delete" data-id="'+a.id+'"><i class="fas fa-trash"></i></button>'
                        + '</td>';
                tbody.appendChild(tr);
            });
        }).catch(function(err){ console.error('Error rendering accounts:', err); });
    }
    window.loadPM = window.loadPM || function(){};
    document.addEventListener('DOMContentLoaded', function(){
        loadMethodsForAccounts();
        renderAccounts();
        var tabBtn = document.getElementById('payment-methods-tab');
        if (tabBtn) { tabBtn.addEventListener('shown.bs.tab', function(){ loadMethodsForAccounts(); renderAccounts(); }); }
        var addBtn = document.getElementById('pa_add_btn');
        if (addBtn) addBtn.addEventListener('click', function(){
            var fd = new FormData(); fd.append('action','payment_accounts_add');
            fd.append('method_id', (document.getElementById('pa_method')||{}).value||'');
            fd.append('alias', (document.getElementById('pa_alias')||{}).value||'');
            fd.append('number', (document.getElementById('pa_number')||{}).value||'');
            fd.append('type', (document.getElementById('pa_type')||{}).value||'');
            fd.append('holder', (document.getElementById('pa_holder')||{}).value||'');
            fd.append('holder_id', (document.getElementById('pa_holder_id')||{}).value||'');
            fd.append('is_default', (document.getElementById('pa_default')||{checked:false}).checked ? '1':'0');
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } });
        });
    });
    document.addEventListener('click', function(e){
        var b = e.target.closest('button'); if(!b) return; var a=b.getAttribute('data-action'); var id=b.getAttribute('data-id');
        if(a==='acc-toggle'){
            var next=b.getAttribute('data-next'); var fd=new FormData(); fd.append('action','payment_accounts_toggle'); fd.append('id',id); fd.append('state',next);
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } });
        } else if(a==='acc-default'){
            var fd=new FormData(); fd.append('action','payment_accounts_set_default'); fd.append('id',id);
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } });
        } else if(a==='acc-delete'){
            if (typeof showConfirm==='function') {
                showConfirm('¿Estás seguro de eliminar esta cuenta?', function(){
                    var fd=new FormData(); fd.append('action','payment_accounts_delete'); fd.append('id',id);
                    fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); if (typeof showSuccess==='function') showSuccess('Cuenta eliminada'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al eliminar cuenta'); } });
                });
            } else {
                var fd=new FormData(); fd.append('action','payment_accounts_delete'); fd.append('id',id);
                fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } });
            }
        } else if(a==='acc-edit'){
            var modalEl = document.getElementById('accEditModal'); if(!modalEl) return;
            var methodInput = document.getElementById('accEditMethod');
            var aliasInput = document.getElementById('accEditAlias');
            var numberInput = document.getElementById('accEditNumber');
            var typeSelect = document.getElementById('accEditType');
            var holderInput = document.getElementById('accEditHolder');
            var holderIdInput = document.getElementById('accEditHolderId');
            var defChk = document.getElementById('accEditDefault');
            var actChk = document.getElementById('accEditActive');
            var idInput = document.getElementById('accEditId');
            if (methodInput) methodInput.value = b.getAttribute('data-method')||'';
            if (aliasInput && numberInput && idInput){ aliasInput.value = b.getAttribute('data-alias')||''; numberInput.value = b.getAttribute('data-number')||''; idInput.value = id; }
            if (typeSelect) typeSelect.value = b.getAttribute('data-type')||'';
            if (holderInput) holderInput.value = b.getAttribute('data-holder')||'';
            if (holderIdInput) holderIdInput.value = b.getAttribute('data-holder_id')||'';
            if (defChk) defChk.checked = (b.getAttribute('data-default')==='1');
            if (actChk) actChk.checked = (b.getAttribute('data-active')==='1');
            try { document.body.appendChild(modalEl); } catch(e){}
            new bootstrap.Modal(modalEl).show();
        }
    });
    (function(){
        document.addEventListener('DOMContentLoaded', function(){
            var saveAcc = document.getElementById('accEditSave');
            if (saveAcc) saveAcc.addEventListener('click', function(){
                var id = document.getElementById('accEditId')?.value || '';
                var alias = document.getElementById('accEditAlias')?.value || '';
                var number = document.getElementById('accEditNumber')?.value || '';
                var type = document.getElementById('accEditType')?.value || '';
                var holder = document.getElementById('accEditHolder')?.value || '';
                var holder_id = document.getElementById('accEditHolderId')?.value || '';
                var is_default = document.getElementById('accEditDefault')?.checked ? '1':'0';
                var is_active = document.getElementById('accEditActive')?.checked ? '1':'0';
                var fd=new FormData(); fd.append('action','payment_accounts_update'); fd.append('id',id); fd.append('alias',alias); fd.append('number',number); fd.append('type',type); fd.append('holder',holder); fd.append('holder_id',holder_id); fd.append('is_default',is_default); fd.append('is_active',is_active);
                fetchJson(fd).then(function(d){ if(d && d.success){ var mEl=document.getElementById('accEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } renderAccounts(); refreshPMAccountsModal(); if (typeof showSuccess==='function') showSuccess('Cuenta actualizada correctamente'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al actualizar la cuenta'); } });
            });
            var pmSave = document.getElementById('pmEditSave');
            if (pmSave) pmSave.addEventListener('click', function(){
                var id = document.getElementById('pmEditId')?.value || '';
                var name = (document.getElementById('pmEditName')?.value || '').trim();
                var accId = document.getElementById('pmEditAccountId')?.value || '';
                var accNumber = document.getElementById('pmEditAccNumber')?.value || '';
                var accType = document.getElementById('pmEditAccType')?.value || '';
                var accHolder = document.getElementById('pmEditAccHolder')?.value || '';
                var accHolderId = document.getElementById('pmEditAccHolderId')?.value || '';
                if (!id) return;
                var doAccounts = function(){
                    if (accNumber.trim()!==''){
                        var fa = new FormData();
                        if (accId){ fa.append('action','payment_accounts_update'); fa.append('id', accId); }
                        else { fa.append('action','payment_accounts_add'); fa.append('method_id', id); }
                        fa.append('alias','');
                        fa.append('number', accNumber);
                        fa.append('type', accType);
                        fa.append('holder', accHolder);
                        fa.append('holder_id', accHolderId);
                        fa.append('is_default','1');
                        fetchJson(fa).then(function(dx){ var mEl=document.getElementById('pmEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } loadPM && loadPM(); if (dx && dx.success) { if (typeof showSuccess==='function') showSuccess('Cambios de cuenta guardados'); } else { if (typeof showError==='function') showError(dx && dx.message ? dx.message : 'Error al guardar cambios de cuenta'); } });
                    } else { var mEl=document.getElementById('pmEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } loadPM && loadPM(); if (typeof showSuccess==='function') showSuccess('Método actualizado'); }
                };
                if (name){
                    var fd = new FormData(); fd.append('action','payment_methods_update'); fd.append('id', id); fd.append('name', name);
                    var m = document.querySelector('meta[name="csrf-token"]'); if (m) fd.append('csrf_token', m.getAttribute('content'));
                    fetchJson(fd).then(function(d){ if(d && d.success){ doAccounts(); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al actualizar método'); } });
                } else {
                    doAccounts();
                }
            });
            
        });
    })();
})();
</script>

                                <?php if ($isAdmin): ?>
                                <?php include __DIR__ . '/settings_order_statuses.php'; ?>
                                <?php
endif; ?>

                                <!-- Pestaña Portal Cliente -->
                                <div class="tab-pane" id="client-portal" role="tabpanel" aria-labelledby="client-portal-tab">
                                    <?php include __DIR__ . '/settings_client_portal.php'; ?>
                                </div>


                                                                <!-- Pestaña Métodos de Pago -->
                                <?php include __DIR__ . '/settings_payment_methods.php'; ?>
                                
                 <!-- Pestaña Catálogos -->
                                <?php include __DIR__ . '/settings_catalogs.php'; ?>
                                
                                <!-- Pestaña Accesorios del Equipo -->
                                <!-- TAB: Accesorios (#equipment-accessories) -->
                                <?php include __DIR__ . '/settings_accessories.php'; ?>
                                

                            </div>
                        </div>
                    </div>
                </div>
        </div>
    </div>

    <!-- Variable global de permisos -->
    <script>
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    </script>

    <!-- Script de funcionalidades de clientes -->
    <script>


        // Función para exportar clientes
        function exportClients() {
            const format = document.getElementById('export_format').value;
            const fields = [];
            
            if (document.getElementById('export_name').checked) fields.push('name');
            if (document.getElementById('export_phone').checked) fields.push('phone');
            if (document.getElementById('export_email').checked) fields.push('email');
            if (document.getElementById('export_address').checked) fields.push('address');
            if (document.getElementById('export_identification').checked) fields.push('id_number');
            if (document.getElementById('export_dates').checked) fields.push('dates');
            
            if (fields.length === 0) {
                if (typeof showCenteredNotification === 'function') {
                    showCenteredNotification('Advertencia', 'Selecciona al menos un campo para exportar', 'warning');
                } else if (typeof showNotification === 'function') {
                    showNotification('Selecciona al menos un campo para exportar', 'warning');
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Advertencia', text: 'Selecciona al menos un campo para exportar', timer: 2000, showConfirmButton: false });
                } else {
                    alert('Selecciona al menos un campo para exportar');
                }
                return;
            }
            
            // Crear formulario dinámico para enviar datos
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'clients_data_operations.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'export';
            form.appendChild(actionInput);
            
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'format';
            formatInput.value = format;
            form.appendChild(formatInput);
            
            const fieldsInput = document.createElement('input');
            fieldsInput.type = 'hidden';
            fieldsInput.name = 'fields';
            fieldsInput.value = JSON.stringify(fields);
            form.appendChild(fieldsInput);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        // Helper general para parsear respuestas JSON de fetch de forma robusta
        async function parseJsonResponse(response) {
            const ct = response.headers.get('content-type') || '';
            if (!response.ok || !ct.includes('application/json')) {
                const body = await response.text().catch(() => '');
                throw new Error(`HTTP error! status: ${response.status} | content-type: ${ct} | body: ${body.slice(0,200)}`);
            }
            return response.json();
        }

        // Función para importar clientes
        function importClients() {
            const fileInput = document.getElementById('import_file');
            const updateExisting = document.getElementById('update_existing')?.checked;
            
            if (!fileInput || !fileInput.files[0]) {
                showNotification('Selecciona un archivo para importar', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'import');
            formData.append('file', fileInput.files[0]);
            formData.append('update_existing', updateExisting ? '1' : '0');
            
            // Mostrar indicador de carga
            const resultsDiv = document.getElementById('import_results');
            if (!resultsDiv) return;
            
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Procesando archivo...</div>';
            
            fetch('clients_data_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-success">
                            <h6>Importación completada exitosamente</h6>
                            <ul class="mb-0">
                                <li>Clientes importados: ${data.imported}</li>
                                <li>Clientes actualizados: ${data.updated}</li>
                                <li>Clientes omitidos: ${data.skipped}</li>
                                <li>Errores: ${data.errors}</li>
                            </ul>
                        </div>
                    `;
                    // Recargar estadísticas
                    loadClientStats();
                } else {
                    resultsDiv.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = '<div class="alert alert-danger">Error al procesar el archivo</div>';
            });
        }
        
        // Función para cargar estadísticas de clientes
        function loadClientStats() {
            const hasSession = document.cookie.includes('PHPSESSID=');
            if (!IS_ADMIN || !hasSession) return;
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
            const url = 'clients_data_operations.php?action=stats' + (csrf ? '&csrf_token=' + encodeURIComponent(csrf) : '');
            fetch(url)
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    const totalEl = document.getElementById('total_clients');
                    const emailEl = document.getElementById('clients_with_email');
                    const phoneEl = document.getElementById('clients_with_phone');
                    const recentEl = document.getElementById('recent_clients');
                    
                    if (totalEl) totalEl.textContent = data.stats.total;
                    if (emailEl) emailEl.textContent = data.stats.with_email;
                    if (phoneEl) phoneEl.textContent = data.stats.with_phone;
                    if (recentEl) recentEl.textContent = data.stats.recent;
                }
            })
            .catch(error => {
                console.error('Error loading client stats:', error);
            });
        }
        
        // Función para cargar estados de órdenes
        function loadOrderStatuses() {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (!csrfMeta) return;
            fetch('order_statuses_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_all&csrf_token=' + encodeURIComponent(csrfMeta.getAttribute('content'))
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    // Verificar que el elemento existe antes de modificarlo
                const content = document.getElementById('order-statuses-content');
                if (!content) return;
                
                if (data.data.length === 0) {
                    content.innerHTML = `
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No hay estados configurados</h5>
                                <p class="text-muted">Agrega el primer estado para comenzar</p>
                            </div>
                        `;
                    } else {
                        let html = '<div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th class="text-center" style="width:40px;"><i class="fas fa-arrows-alt-v"></i></th><th>Estado</th><th>Color</th><th>Descripción</th><th>Por Defecto</th><th>Activo</th><th>Acciones</th></tr></thead><tbody>';
                        // Fallback de emojis por slug (coherente con órdenes)
                        const emojiBySlug = {
                            pending: '\u23F3',
                            received: '\uD83D\uDCE6',
                            diagnosing: '\uD83D\uDD0D',
                            waiting_parts: '\u23F8\uFE0F',
                            repairing: '\uD83D\uDD27',
                            testing: '\uD83E\uDDEA',
                            completed: '\u2705',
                            delivered: '\uD83D\uDE9A',
                            cancelled: '\u274C',
                            devolucion: '\u21A9\uFE0F',
                            cancelado: '\u274C',
                            entregado: '\uD83D\uDE9A'
                        };
                        const nameBySlug = {
                            pending: 'Pendiente',
                            received: 'Recibido',
                            diagnosing: 'Diagnosticando',
                            waiting_parts: 'En Espera de Repuestos',
                            repairing: 'Reparando',
                            testing: 'Pruebas',
                            completed: 'Completado',
                            delivered: 'Entregado',
                            cancelled: 'Cancelado',
                            devolucion: 'Devolución',
                            cancelado: 'Cancelado',
                            entregado: 'Entregado'
                        };
                        data.data.forEach(status => {
                            html += `
                                <tr draggable="true" class="status-row" data-id="${status.id}">
                                    <td class="text-center">
                                        <span class="drag-handle" title="Arrastrar para reordenar" style="cursor: grab;">
                                            <i class="fas fa-grip-lines-vertical text-secondary fs-5"></i>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background-color: ${status.color}; color: white;">
                                            ${(() => {
                                                const raw = String(status.emoji || '').trim();
                                                const decoded = (window.decodeHtmlEntities ? window.decodeHtmlEntities(raw) : raw);
                                                const presented = (window.ensureEmojiPresentation ? window.ensureEmojiPresentation(decoded) : decoded);
                                                const invalid = (presented === '' || /^\?+$/.test(presented));
                                                const fallback = emojiBySlug[String(status.slug || '').trim()] || '❓';
                                                return invalid ? fallback : presented;
                                            })()} ${(() => {
                                                const rn = String(status.name || '').trim();
                                                const invalidName = (rn === '' || /^\?+$/.test(rn));
                                                return invalidName ? (nameBySlug[String(status.slug || '').trim()] || rn) : rn;
                                            })()}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="color-preview me-2" style="width: 20px; height: 20px; background-color: ${status.color}; border-radius: 3px; border: 1px solid #ddd;"></div>
                                            <code>${status.color}</code>
                                        </div>
                                    </td>
                                    <td>${status.description || ''}</td>
                                    <td>${status.is_default ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'}</td>
                                    <td>${status.is_active ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>'}</td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button 
                                                class="btn btn-sm btn-outline-primary" 
                                                onclick="editOrderStatus(this, ${status.id})" 
                                                title="Editar"
                                                data-id="${status.id}"
                                                data-slug="${String(status.slug||'').replace(/\"/g,'&quot;')}"
                                                data-name="${String(status.name||'').replace(/\"/g,'&quot;')}"
                                                data-emoji="${String(status.emoji||'').replace(/\"/g,'&quot;')}"
                                                data-color="${String(status.color||'#6c757d').replace(/\"/g,'&quot;')}"
                                                data-description="${String(status.description||'').replace(/\"/g,'&quot;')}"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteOrderStatus(${status.id}, '${status.name}')" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                        html += '</tbody></table></div>';
                        content.innerHTML = html;
                        const tbody = content.querySelector('tbody');
                        if (tbody) {
                            let draggingRow = null;
                            let dragFromHandle = false;
                            tbody.addEventListener('mousedown', function(e) {
                                dragFromHandle = !!e.target.closest('.drag-handle');
                            });
                            tbody.addEventListener('dragstart', function(e) {
                                const row = e.target.closest('tr.status-row');
                                if (!row) return;
                                if (!dragFromHandle) {
                                    e.preventDefault();
                                    return;
                                }
                                draggingRow = row;
                                row.classList.add('opacity-50');
                                e.dataTransfer.effectAllowed = 'move';
                            });
                            tbody.addEventListener('dragover', function(e) {
                                e.preventDefault();
                                const targetRow = e.target.closest('tr.status-row');
                                if (!draggingRow || !targetRow || draggingRow === targetRow) return;
                                const rows = Array.from(tbody.querySelectorAll('tr.status-row'));
                                const draggingIndex = rows.indexOf(draggingRow);
                                const targetIndex = rows.indexOf(targetRow);
                                if (draggingIndex < targetIndex) {
                                    targetRow.parentNode.insertBefore(draggingRow, targetRow.nextSibling);
                                } else {
                                    targetRow.parentNode.insertBefore(draggingRow, targetRow);
                                }
                            });
                            const saveOrder = async function() {
                                const ids = Array.from(tbody.querySelectorAll('tr.status-row')).map(r => r.dataset.id);
                                try {
                                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                                    const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
                                    const resp = await fetch('order_statuses_ajax.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'action=reorder&ids=' + encodeURIComponent(JSON.stringify(ids)) + (csrf ? '&csrf_token=' + encodeURIComponent(csrf) : '')
                                    });
                                    const data = await resp.json();
                                    if (data && data.success) {
                                        if (typeof showNotification === 'function') showNotification('Orden guardado', 'success');
                                    } else {
                                        if (typeof showNotification === 'function') showNotification(data.message || 'Error al guardar', 'danger');
                                    }
                                } catch (e) {
                                    if (typeof showNotification === 'function') showNotification('Error de red al guardar', 'danger');
                                }
                            };
                            tbody.addEventListener('drop', function() {
                                if (draggingRow) draggingRow.classList.remove('opacity-50');
                                draggingRow = null;
                                dragFromHandle = false;
                                saveOrder();
                            });
                            tbody.addEventListener('dragend', function() {
                                if (draggingRow) draggingRow.classList.remove('opacity-50');
                                draggingRow = null;
                                dragFromHandle = false;
                            });
                        }
                    }
                } else {
                    const content = document.getElementById('order-statuses-content');
                    if (content) {
                        content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> Error al cargar los estados: ${data.message}
                        </div>
                    `;
                    }
                }
            })
            .catch(error => {
                const content = document.getElementById('order-statuses-content');
                if (content) {
                    content.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error de conexión al cargar los estados
                    </div>
                `;
                }
            });
        }
        
        // Funciones auxiliares para estados
        function updateStatusPreview(mode) {
            const nameInput = document.getElementById(mode === 'create' ? 'name' : 'edit_name');
            const emojiInput = document.getElementById(mode === 'create' ? 'emoji' : 'edit_emoji');
            const colorInput = document.getElementById(mode === 'create' ? 'color' : 'edit_color');
            
            const previewBadge = document.getElementById(mode + '_preview_badge');
            const previewName = document.getElementById(mode + '_preview_name');
            const previewEmoji = document.getElementById(mode + '_preview_emoji');
            
            if (previewBadge && previewName && previewEmoji) {
                previewName.textContent = nameInput.value || 'Nombre del Estado';
                const raw = emojiInput.value || '';
                const decoded = (window.decodeHtmlEntities ? window.decodeHtmlEntities(raw) : raw);
                previewEmoji.textContent = (window.ensureEmojiPresentation ? window.ensureEmojiPresentation(decoded) : decoded);
                previewBadge.style.backgroundColor = colorInput.value;
            }
        }
        
        function setQuickColor(mode, color) {
            const colorInput = document.getElementById(mode === 'create' ? 'color' : 'edit_color');
            if (colorInput) {
                colorInput.value = color;
                updateStatusPreview(mode);
            }
        }

        // Función para abrir modal de crear estado
        function openCreateStatusModal() {
            // Limpiar formulario
            document.getElementById('create-status-form').reset();
            // Inicializar vista previa
            updateStatusPreview('create');
            // Abrir modal
            new bootstrap.Modal(document.getElementById('createStatusModal')).show();
        }
        
        // Función para editar estado
        function editOrderStatus(el, id) {
            // Buscar el estado en los datos cargados
            fetch('order_statuses_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_all&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    const status = data.data.find(s => s.id == id);
                    if (status) {
                        document.getElementById('edit_id').value = status.id;
                        const nameBySlug = {
                            pending: 'Pendiente',
                            received: 'Recibido',
                            diagnosing: 'Diagnosticando',
                            waiting_parts: 'En Espera de Repuestos',
                            repairing: 'Reparando',
                            testing: 'Pruebas',
                            completed: 'Completado',
                            delivered: 'Entregado',
                            cancelled: 'Cancelado',
                            devolucion: 'Devolución',
                            cancelado: 'Cancelado',
                            entregado: 'Entregado'
                        };
                        const emojiBySlug = {
                            pending: '\u23F3',
                            received: '\uD83D\uDCE6',
                            diagnosing: '\uD83D\uDD0D',
                            waiting_parts: '\u23F8\uFE0F',
                            repairing: '\uD83D\uDD27',
                            testing: '\uD83E\uDDEA',
                            completed: '\u2705',
                            delivered: '\uD83D\uDE9A',
                            cancelled: '\u274C',
                            devolucion: '\u21A9\uFE0F',
                            cancelado: '\u274C',
                            entregado: '\uD83D\uDE9A'
                        };
                        const defaultsDesc = {
                            pending: 'Orden creada y pendiente de revisión',
                            received: 'Orden recibida en el taller',
                            diagnosing: 'Equipo en diagnóstico técnico',
                            waiting_parts: 'Orden en espera de repuestos',
                            repairing: 'Equipo en reparación',
                            testing: 'Equipo en pruebas de funcionamiento',
                            completed: 'Trabajo completado, listo para entrega',
                            delivered: 'Orden entregada al cliente',
                            cancelled: 'Orden cancelada',
                            devolucion: 'Orden devuelta por el cliente'
                        };
                        const slug = String(status.slug || '').trim();
                        const rawName = String(status.name || '').trim();
                        const rawEmoji = String(status.emoji || '').trim();
                        const rawDesc = String(status.description || '').trim();
                        // Fallbacks desde el botón si vienen vacíos/corruptos
                        const btnData = (el && el.dataset) ? el.dataset : {};
                        const btnSlug = String(btnData.slug || '').trim();
                        const btnName = String(btnData.name || '').trim();
                        const btnEmoji = String(btnData.emoji || '').trim();
                        const btnDesc = String(btnData.description || '').trim();
                        const decodedName = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawName) : rawName);
                        const decodedEmoji = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawEmoji) : rawEmoji);
                        const decodedDesc = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawDesc) : rawDesc);
                        const nameInvalid = (decodedName === '' || /^\?+$/.test(decodedName));
                        const emojiInvalid = (decodedEmoji === '' || /^\?+$/.test(decodedEmoji));
                        const descInvalid = (decodedDesc === '' || /\?{2,}/.test(decodedDesc));
                        const finalSlug = slug || btnSlug;
                        const finalNameSource = nameInvalid ? (btnName || '') : decodedName;
                        const editNameEl = document.getElementById('edit_name');
                        const chosenName = (finalNameSource === '' || /^\?+$/.test(finalNameSource))
                            ? (nameBySlug[finalSlug] || finalNameSource)
                            : finalNameSource;
                        editNameEl.value = chosenName;
                        editNameEl.dataset.originalName = chosenName;
                        editNameEl.dataset.slug = finalSlug;
                        const finalEmojiSource = emojiInvalid ? (btnEmoji || '') : decodedEmoji;
                        document.getElementById('edit_emoji').value = (finalEmojiSource === '' || /^\?+$/.test(finalEmojiSource))
                            ? (emojiBySlug[finalSlug] || '')
                            : (window.ensureEmojiPresentation ? window.ensureEmojiPresentation(finalEmojiSource) : finalEmojiSource);
                        document.getElementById('edit_color').value = status.color;
                        const finalDescSource = descInvalid ? (btnDesc || '') : decodedDesc;
                        document.getElementById('edit_description').value = (finalDescSource === '' || /\?{2,}/.test(finalDescSource))
                            ? (defaultsDesc[finalSlug] || finalDescSource)
                            : finalDescSource;
                        document.getElementById('edit_is_default').checked = status.is_default == 1;
                        document.getElementById('edit_is_active').checked = status.is_active == 1;
                        
                        updateStatusPreview('edit');
                        
                        new bootstrap.Modal(document.getElementById('editStatusModal')).show();
                    } else {
                         console.error('Estado no encontrado:', id);
                         showNotification('Error: Estado no encontrado', 'danger');
                    }
                } else {
                    showNotification('Error al cargar datos: ' + (data.message || 'Desconocido'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al cargar el estado', 'danger');
            });
        }
        
        // Función para eliminar estado
        function deleteOrderStatus(id, name) {
            // Configurar el modal de confirmación
            document.getElementById('deleteConfirmText').textContent = `¿Estás seguro de que deseas eliminar el estado "${name}"?`;
            
            // Obtener referencia al modal
            const modalElement = document.getElementById('deleteConfirmModal');
            const modal = new bootstrap.Modal(modalElement);
            
            document.getElementById('confirmDeleteBtn').onclick = function() {
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
                })
                .then(async (response) => {
                    const ct = response.headers.get('content-type') || '';
                    const text = await response.text();
                    if (!ct.includes('application/json')) {
                        throw new Error('Respuesta no JSON: ' + text.slice(0, 200));
                    }
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('JSON inválido: ' + text.slice(0, 200));
                    }
                    return data;
                })
                .then(data => {
                    // Cerrar modal primero
                    modal.hide();
                    
                    if (data.success) {
                        loadOrderStatuses(); // Recargar la lista
                        showNotification('Estado eliminado exitosamente', 'success');
                    } else {
                        showNotification('Error al eliminar el estado: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    // Cerrar modal en caso de error
                    modal.hide();
                    showNotification('Error de conexión al eliminar el estado', 'danger');
                });
            };
            
            // Mostrar el modal
            modal.show();
        }
        
        // Cargar estadísticas al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            loadClientStats();
            
            // Cargar estados de órdenes cuando se active la pestaña
            document.getElementById('order-statuses-tab').addEventListener('click', function() {
                loadOrderStatuses();
            });
            
            // Event listener para formulario de crear estado (evitar doble binding entre tabs)
            if (!window.__orderStatusCreateBound) {
            window.__orderStatusCreateBound = true;
            document.getElementById('create-status-form').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'create');
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                // Evitar doble envío mientras procesa
                const submitBtn = this.querySelector('[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;
                
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('createStatusModal')).hide();
                        loadOrderStatuses();
                    } else {
                        showNotification('Error: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error al crear el estado', 'danger');
                })
                .finally(() => { if (submitBtn) submitBtn.disabled = false; });
            });
            }

            // Event listener para formulario de editar estado
            document.getElementById('edit-status-form').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const nameEl = document.getElementById('edit_name');
                if (nameEl && nameEl.value.trim() === '') {
                    const slug = String(nameEl.dataset.slug || '').trim();
                    const original = String(nameEl.dataset.originalName || '').trim();
                    const nameBySlug = {
                        pending: 'Pendiente',
                        received: 'Recibido',
                        diagnosing: 'Diagnosticando',
                        waiting_parts: 'En Espera de Repuestos',
                        repairing: 'Reparando',
                        testing: 'Pruebas',
                        completed: 'Completado',
                        delivered: 'Entregado',
                        cancelled: 'Cancelado',
                        devolucion: 'Devolución',
                        cancelado: 'Cancelado',
                        entregado: 'Entregado'
                    };
                    nameEl.value = original || nameBySlug[slug] || '';
                }
                
                const formData = new FormData(this);
                formData.append('action', 'update');
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('editStatusModal')).hide();
                        loadOrderStatuses();
                    } else {
                        showNotification('Error: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error al actualizar el estado', 'danger');
                });
            });
        });
        
        function showCenteredNotification(title, message, type = 'info') {
            var icon = 'info';
            if (type === 'success') icon = 'success';
            else if (type === 'error' || type === 'danger') icon = 'error';
            else if (type === 'warning') icon = 'warning';
            var timerMs = type === 'success' ? 800 : (type === 'warning' ? 2000 : (type === 'error' || type === 'danger') ? 6000 : 1500);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: icon,
                    title: title,
                    text: message,
                    timer: timerMs,
                    showConfirmButton: false,
                    timerProgressBar: true
                });
            }
        }
        
        function hideNotification() {}
        
        function showNotification(message, type = 'info') {
            var title = 'Información';
            if (type === 'success') title = 'Éxito';
            else if (type === 'error' || type === 'danger') title = 'Error';
            else if (type === 'warning') title = 'Advertencia';
            showCenteredNotification(title, message, type);
        }
    </script>



<script>
(function(){
    var pmPage = 1;
    var pmLimit = 1000;
    var pmTotal = 0;
    var pmReqCounter = 0;
    var pcSaving = false;
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>\"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function safeJson(text){ try{ return JSON.parse(text); }catch(e){ return null; } }
    // Función para manejar errores de JSON y logging
    function postJson(fd) {
        if (document.visibilityState === 'hidden') {
            return Promise.resolve({ success: false, message: 'skipped: page hidden' });
        }
        try {
            if (fd && typeof fd.append === 'function') {
                var m = document.querySelector('meta[name="csrf-token"]');
                var csrf = m ? m.getAttribute('content') : '';
                if (csrf && typeof fd.get === 'function' && !fd.get('csrf_token')) {
                    fd.append('csrf_token', csrf);
                }
            }
        } catch(e) {}
        return fetch('config_operations.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: fd,
            cache: 'no-store',
            keepalive: true,
            credentials: 'same-origin'
        })
        .then(function(r) {
            if (typeof window.parseJsonResponse === 'function') {
                return window.parseJsonResponse(r);
            }
            if (!r.ok) throw new Error('HTTP Error ' + r.status);
            return r.text().then(function(t){
                const json = safeJson(t);
                if (!json) throw new Error('Respuesta no válida del servidor');
                return json;
            });
        })
        .catch(function(err) {
            console.error('Error en postJson:', err);
            return { success: false, message: err.message };
        });
    }
    function renderMethodAccounts(mid, title){
        var fd = new FormData(); fd.append('action','payment_accounts_list');
        postJson(fd).then(function(d){
            var tbody = document.getElementById('pmAccModalBody'); if(!tbody) return; tbody.innerHTML='';
            var list = (d.accounts||[]).filter(function(a){ return String(a.method_id)===String(mid); });
            list.forEach(function(a){
                var active = (parseInt(a.is_active||1)===1);
                var def = (parseInt(a.is_default||0)===1);
                var tr=document.createElement('tr');
                tr.innerHTML = '<td>'+esc(a.alias||a.account_name||'')+'</td>'
                    + '<td>'+esc(a.account_number||'')+'</td>'
                    + '<td>'+esc(a.type||'')+'</td>'
                    + '<td>'+esc(a.holder_name||'')+'</td>'
                    + '<td><span class="badge '+(active?'bg-success':'bg-secondary')+'">'+(active?'Activo':'Inactivo')+'</span></td>'
                    + '<td><span class="badge '+(def?'bg-dark':'bg-light text-dark')+'">'+(def?'Sí':'No')+'</span></td>'
                    + '<td>'
                    + '<button class="btn btn-sm btn-outline-dark" data-action="acc-edit" data-id="'+a.id+'" data-method="'+esc(title||a.method_name||a.method_id)+'" data-alias="'+esc(a.alias||a.account_name||'')+'" data-number="'+esc(a.account_number||'')+'" data-type="'+esc(a.type||'')+'" data-holder="'+esc(a.holder_name||'')+'" data-holder_id="'+esc(a.holder_id||'')+'" data-default="'+(def?1:0)+'" data-active="'+(active?1:0)+'"><i class="fas fa-edit"></i></button> '
                    + '<button class="btn btn-sm '+(active?'btn-outline-secondary':'btn-outline-success')+'" data-action="acc-toggle" data-id="'+a.id+'" data-next="'+(active?'inactive':'active')+'"><i class="fas '+(active?'fa-eye-slash':'fa-eye')+'"></i></button> '
                    + '<button class="btn btn-sm '+(def?'btn-outline-secondary':'btn-outline-info')+'" data-action="acc-default" data-id="'+a.id+'"><i class="fas fa-star"></i></button> '
                    + '<button class="btn btn-sm btn-outline-danger" data-action="acc-delete" data-id="'+a.id+'"><i class="fas fa-trash"></i></button>'
                    + '</td>';
                tbody.appendChild(tr);
            });
        }).catch(function(){});
    }
    window.refreshPMAccountsModal = function(){
        var mEl = document.getElementById('pmAccountsModal');
        if(!mEl) return; var isOpen = mEl.classList.contains('show'); if(!isOpen) return;
        var mid = mEl.getAttribute('data-mid') || ''; var title = document.getElementById('pmAccModalTitle')?.textContent || '';
        if (mid) renderMethodAccounts(mid, title);
    };
    function loadPM(){
        var myReqId = ++pmReqCounter;
        var fd = new FormData();
        fd.append('action','payment_methods_list');
        fd.append('page', pmPage);
        fd.append('limit', pmLimit);
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m) fd.append('csrf_token', m.getAttribute('content'));
        postJson(fd).then(function(d){
            if (myReqId !== pmReqCounter) return;
            var tbody = document.getElementById('pmTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            if(!d || !d.success) return;
            pmTotal = parseInt(d.total||0);
            var fdAcc = new FormData(); fdAcc.append('action','payment_accounts_list');
            postJson(fdAcc).then(function(accData){
                if (myReqId !== pmReqCounter) return;
                var accList = accData && accData.accounts ? accData.accounts : [];
                (d.methods||[]).forEach(function(m){
                    var active = m.status ? (m.status==='active') : ((typeof m.is_active !== 'undefined') ? (parseInt(m.is_active)===1) : true);
                    var icon = 'fa-money-bill-wave';
                    var lowerName = (m.name||'').toLowerCase();
                    if (lowerName.includes('tarjeta') || lowerName.includes('visa') || lowerName.includes('master')) { icon = 'fa-credit-card'; }
                    else if (lowerName.includes('banco') || lowerName.includes('transferencia')) { icon = 'fa-university'; }
                    else if (lowerName.includes('efectivo') || lowerName.includes('cash')) { icon = 'fa-coins'; }
                    else if (lowerName.includes('nequi') || lowerName.includes('daviplata') || lowerName.includes('movil')) { icon = 'fa-mobile-alt'; }
                    else if (lowerName.includes('cheque')) { icon = 'fa-money-check-alt'; }
                    
                    var accs = accList.filter(function(a){ return String(a.method_id)===String(m.id); });
                    var chosen = accs.find(function(a){ return parseInt(a.is_default||0)===1; }) || accs[0] || null;
                    var num = chosen ? (chosen.account_number||'') : '';
                    var typ = chosen ? (chosen.type||'') : '';
                    var hold = chosen ? (chosen.holder_name||'') : '';
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="ps-4">'
                        + '<div class="d-flex align-items-center">'
                        + '<div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 45px; height: 45px;">'
                        + '<i class="fas '+icon+' text-dark fa-lg"></i>'
                        + '</div>'
                        + '<span class="fw-bold text-dark">'+esc(m.name)+'</span>'
                        + '</div>'
                        + '</td>'
                        + '<td><span class="text-muted">'+esc(num)+'</span></td>'
                        + '<td><span class="text-muted">'+esc(hold)+'</span></td>'
                        + '<td><span class="text-muted">'+esc(typ)+'</span></td>'
                        + '<td>'
                        + '<span class="badge rounded-pill bg-'+(active?'success':'secondary')+' bg-opacity-10 text-'+(active?'success':'secondary')+' px-3 py-2 border border-'+(active?'success':'secondary')+' border-opacity-10">'
                        + '<i class="fas fa-'+(active?'check-circle':'times-circle')+' me-1"></i>'
                        + (active?'Activo':'Inactivo')
                        + '</span>'
                        + '</td>'
                        + '<td class="pe-4 text-end">'
                        + '<div class="btn-group shadow-sm" role="group">'
                        + '<button class="btn btn-sm btn-outline-dark rounded-start" data-action="edit" data-id="'+m.id+'" title="Editar"><i class="fas fa-edit"></i></button>'
                        + '<button class="btn btn-sm '+(active?'btn-outline-secondary':'btn-outline-dark')+'" data-action="toggle" data-id="'+m.id+'" data-next="'+(active?'inactive':'active')+'" title="'+(active?'Desactivar':'Activar')+'"><i class="fas '+(active?'fa-eye-slash':'fa-eye')+'"></i></button>'
                        + '<button class="btn btn-sm btn-outline-danger rounded-end" data-action="delete" data-id="'+m.id+'" title="Eliminar"><i class="fas fa-trash"></i></button>'
                        + '</div>'
                        + '</td>';
                    tbody.appendChild(tr);
                });
                var pager = document.getElementById('pmPager');
                if (pager) pager.style.display = 'none';
            });
        });
    }
    window.loadPM = loadPM;
    document.addEventListener('DOMContentLoaded', function(){
        var tabBtn = document.getElementById('payment-methods-tab');
        if (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', function(){ pmPage=1; loadPM(); });
        }
        pmPage=1; loadPM();
        var addBtn = document.getElementById('pm_add_btn');
        if (addBtn) {
            addBtn.addEventListener('click', function(){
                var mEl = document.getElementById('pmCreateModal');
                if (!mEl) return;
                var nameEl = document.getElementById('pc_name'); if (nameEl) nameEl.value='';
                var numEl = document.getElementById('pc_acc_number'); if (numEl) numEl.value='';
                var typeEl = document.getElementById('pc_acc_type'); if (typeEl) typeEl.value='wallet';
                var holderEl = document.getElementById('pc_acc_holder'); if (holderEl) holderEl.value='';
                var docEl = document.getElementById('pc_acc_holder_id'); if (docEl) docEl.value='';
                var defEl = document.getElementById('pc_acc_default'); if (defEl) defEl.checked = true;
                pcSaving=false;
                try { document.body.appendChild(mEl); } catch(e){}
                new bootstrap.Modal(mEl).show();
            });
        }
        function handleCreate(){
            if (pcSaving) return; pcSaving = true;
            var name = (document.getElementById('pc_name')||{}).value || '';
            name = name.trim();
            if(!name){ pcSaving=false; return; }
            var accNumber = (document.getElementById('pc_acc_number')||{}).value || '';
            var accType = (document.getElementById('pc_acc_type')||{}).value || '';
            var accHolder = (document.getElementById('pc_acc_holder')||{}).value || '';
            var accHolderId = (document.getElementById('pc_acc_holder_id')||{}).value || '';
            var accDefault = (document.getElementById('pc_acc_default')||{checked:true}).checked ? '1':'0';
            var fd = new FormData(); fd.append('action','payment_methods_add'); fd.append('name', name);
            var m = document.querySelector('meta[name="csrf-token"]'); if (m) fd.append('csrf_token', m.getAttribute('content'));
            postJson(fd).then(function(d){ if(d && d.success){ var mid = d.method_id || 0; if (mid && accNumber.trim()!==''){
                var fd2 = new FormData(); fd2.append('action','payment_accounts_add'); fd2.append('method_id', String(mid)); fd2.append('alias',''); fd2.append('number', accNumber); fd2.append('type', accType); fd2.append('holder', accHolder); fd2.append('holder_id', accHolderId); fd2.append('is_default', accDefault);
                postJson(fd2).then(function(){ var mEl=document.getElementById('pmCreateModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } pmPage=1; loadPM(); pcSaving=false; if (typeof showSuccess==='function') showSuccess('Método y cuenta creados'); });
            } else { var mEl=document.getElementById('pmCreateModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } pmPage=1; loadPM(); pcSaving=false; if (typeof showSuccess==='function') showSuccess('Método de pago creado'); } } else { pcSaving=false; if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al crear método'); } }).catch(function(){ pcSaving=false; if (typeof showError==='function') showError('Error de red al crear método'); });
        }
        var pcSave = document.getElementById('pc_save');
        if (pcSave) pcSave.addEventListener('click', handleCreate);
        document.addEventListener('click', function(ev){ var trg = ev.target.closest('#pc_save'); if(!trg) return; handleCreate(); });
        var prev = document.getElementById('pmPrev');
        var next = document.getElementById('pmNext');
        if (prev) prev.addEventListener('click', function(){ if(pmPage>1){ pmPage--; loadPM(); } });
        if (next) next.addEventListener('click', function(){ if(pmPage*pmLimit<pmTotal){ pmPage++; loadPM(); } });
    });
    document.addEventListener('click', function(e){
        var el = e.target.closest('[data-action]');
        if(!el) return;
        var action = el.getAttribute('data-action');
        if(!action) return;
        var id = el.getAttribute('data-id');
        if(action==='edit'){
            var row = el.closest('tr');
            var currentName = row ? row.children[0].textContent : '';
            var mEl = document.getElementById('pmEditModal');
            var nameInput = document.getElementById('pmEditName');
            var idInput = document.getElementById('pmEditId');
            var accIdInput = document.getElementById('pmEditAccountId');
            var accNumInput = document.getElementById('pmEditAccNumber');
            var accTypeInput = document.getElementById('pmEditAccType');
            var accHolderInput = document.getElementById('pmEditAccHolder');
            var accHolderIdInput = document.getElementById('pmEditAccHolderId');
            if (mEl && nameInput && idInput){
                nameInput.value = (currentName||''); idInput.value = id;
                var fdAcc = new FormData(); fdAcc.append('action','payment_accounts_list');
                postJson(fdAcc).then(function(accData){
                    var accs = (accData.accounts||[]).filter(function(a){ return String(a.method_id)===String(id); });
                    var chosen = accs.find(function(a){ return parseInt(a.is_default||0)===1; }) || accs[0] || null;
                    if (accIdInput) accIdInput.value = chosen ? (chosen.id||'') : '';
                    if (accNumInput) accNumInput.value = chosen ? (chosen.account_number||'') : '';
                    if (accTypeInput) accTypeInput.value = chosen ? (chosen.type||'') : '';
                    if (accHolderInput) accHolderInput.value = chosen ? (chosen.holder_name||'') : '';
                    if (accHolderIdInput) accHolderIdInput.value = chosen ? (chosen.holder_id||'') : '';
                    try{ document.body.appendChild(mEl); }catch(e){}
                    new bootstrap.Modal(mEl).show();
                });
            }
        } else if(action==='toggle'){
            var nxt = el.getAttribute('data-next');
            var fd = new FormData();
            fd.append('action','payment_methods_toggle');
            fd.append('id', id);
            fd.append('state', nxt);
            var m = document.querySelector('meta[name="csrf-token"]');
            if (m) fd.append('csrf_token', m.getAttribute('content'));
            postJson(fd).then(function(d){ if(d && d.success){ loadPM(); if (typeof showSuccess==='function') showSuccess(nxt==='active' ? 'Método activado' : 'Método desactivado'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al cambiar estado'); } });
        } else if(action==='delete'){
            if (typeof showConfirm==='function') {
                showConfirm('¿Estás seguro de eliminar este método de pago?', function(){
                    var fd = new FormData();
                    fd.append('action','payment_methods_delete');
                    fd.append('id', id);
                    var m = document.querySelector('meta[name="csrf-token"]');
                    if (m) fd.append('csrf_token', m.getAttribute('content'));
                    postJson(fd).then(function(d){ if(d && d.success){ pmPage=1; loadPM(); if (typeof showSuccess==='function') showSuccess('Método eliminado'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al eliminar método'); } });
                });
                return;
            }
            var fd = new FormData();
            fd.append('action','payment_methods_delete');
            fd.append('id', id);
            var m = document.querySelector('meta[name="csrf-token"]');
            if (m) fd.append('csrf_token', m.getAttribute('content'));
            postJson(fd).then(function(d){ if(d && d.success){ pmPage=1; loadPM(); if (typeof showSuccess==='function') showSuccess('Método eliminado'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al eliminar método'); } });
        }
    });
})();
</script>


                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    
    <script>
    // Configuración de empresa - Versión modernizada con SweetAlert2
    console.log('Inicializando configuración de empresa...');
    
    // Función para mostrar notificaciones (Sobrescrita por utils.js si existe, pero por seguridad)
    // Ya tenemos utils.js cargado en el footer/head, así que usamos showSuccess y showError directamente.
    
    // Esperar a que el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM cargado');
        
        // Buscar el formulario
        const form = document.getElementById('companyForm');
        if (!form) {
            console.error('Formulario no encontrado');
            return;
        }
        
        console.log('Formulario encontrado, agregando event listener');
        
        // Agregar event listener al formulario
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('Formulario enviado');
            
            // Obtener datos del formulario
            const formData = new FormData(this);
            const companyName = formData.get('company_name');
            
            // Validar nombre
            if (!companyName || companyName.trim() === '') {
                showError('El nombre de la empresa es requerido');
                return;
            }
            
            // Mostrar indicador de carga
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
            submitBtn.disabled = true;
            
            // Enviar datos
            fetch('config_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(async (response) => {
                const ct = response.headers.get('content-type') || '';
                const text = await response.text();
                if (!ct.includes('application/json')) {
                    throw new Error('Respuesta no JSON: ' + text.slice(0, 200));
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('JSON inválido: ' + text.slice(0, 200));
                }
                return data;
            })
            .then(data => {
                if (data.success) {
                    showSuccess('Configuración guardada exitosamente');
                    
                    // Actualizar logo si hay uno nuevo
                    if (data.logo_filename) {
                        const previewImg = document.getElementById('company-logo-circle');
                        if (previewImg) {
                            previewImg.src = '../assets/img/' + data.logo_filename + '?t=' + new Date().getTime();
                        }
                        // Refrescar el logo del sidebar sin recargar la página
                        try {
                            var sidebarLogo = document.querySelector('.sidebar-modern .brand-icon');
                            if (sidebarLogo) {
                                sidebarLogo.src = '../assets/img/' + data.logo_filename + '?t=' + Date.now();
                            }
                        } catch(e) { /* silent */ }
                    }
                    // Actualizar nombre en el sidebar si cambió
                    if (data.company_name) {
                        try {
                            var brandText = document.querySelector('.sidebar-modern .brand-text');
                            if (brandText) {
                                brandText.textContent = String(data.company_name || '').trim() || brandText.textContent;
                            }
                        } catch(e) { /* silent */ }
                    }
                } else {
                    showError('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error al guardar la configuración: ' + error.message);
            })
            .finally(() => {
                // Restaurar botón
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    });
    </script>
    <script>
    // Funciones de usuarios
    function parseJsonResponse(response){
        return response.text().then(function(text){
            var ct = (response.headers && response.headers.get && response.headers.get('content-type')) || '';
            if (!ct || ct.indexOf('application/json') === -1) {
                console.error('Respuesta no JSON:', String(text||'').slice(0,200));
                return { success:false, message:'Respuesta no JSON', raw:text };
            }
            try { return JSON.parse(text); }
            catch(e){
                console.error('JSON inválido:', String(text||'').slice(0,200));
                return { success:false, message:'JSON inválido', raw:text };
            }
        });
    }

    function ensureModalFromTemplate(modalId, templateId) {
        if (!document.getElementById(modalId)) {
            var tpl = document.getElementById(templateId);
            if (!tpl) return false;
            var clone = tpl.content.cloneNode(true);
            document.body.appendChild(clone);
            // Convertir inputs marcados como data-type=password a type=password
            var addedModal = document.getElementById(modalId);
            if (addedModal) {
                addedModal.querySelectorAll('[data-type="password"]').forEach(function(el){
                    try { el.setAttribute('type','password'); } catch(e){}
                });
            }
        }
        return true;
    }
    function openCreateUserModal() {
        if (!ensureModalFromTemplate('createUserModal','createUserModalTemplate')) return;
        var p = document.getElementById('create_password');
        var f = document.getElementById('createUserForm');
        if (f) f.reset();
        if (p) p.disabled = false;
        var modalEl = document.getElementById('createUserModal');
        var modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function onHide() {
            modalEl.removeEventListener('hidden.bs.modal', onHide);
            if (p) { p.value=''; p.disabled = true; }
            if (f) f.reset();
            modalEl.remove();
        });
        modal.show();
    }

    function createUser() {
        const formData = new FormData(document.getElementById('createUserForm'));
        formData.append('action', 'create_user');
        
        fetch('config_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonResponse)
        .then(data => {
            if(data.success) {
                showSuccess('Usuario creado correctamente');
                setTimeout(() => location.reload(), 1500);
            } else {
                showError('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error al crear el usuario');
        });
    }

    function editUser(userId, name, email, role, active, photo) {
        document.getElementById('edit_user_id').value = userId;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_email').value = email;
        if(role) document.getElementById('edit_role').value = role;
        document.getElementById('edit_active').value = active;
        
        // Reset file input
        document.getElementById('edit_photo').value = '';
        
        // Mostrar foto actual si existe (tenant-aware)
        const photoContainer = document.getElementById('current_photo_container');
        if (photo) {
            var tenantId = <?php echo json_encode($perDatabase ? (((int)($_SESSION['empresa_id'] ?? 0)) ?: (int)$tenant_id) : (int)$tenant_id); ?>;
            photoContainer.innerHTML = `<img src="../uploads/${tenantId}/users/${photo}?v=${Date.now()}" alt="Foto actual" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">`;
        } else {
            photoContainer.innerHTML = '<div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center text-white" style="width: 40px; height: 40px;"><i class="fas fa-user"></i></div>';
        }

        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }
    
    // Función para previsualizar imagen seleccionada
    function previewImage(input) {
        const photoContainer = document.getElementById('current_photo_container');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                photoContainer.innerHTML = `<img src="${e.target.result}" alt="Previsualización" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">`;
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    function updateUser() {
        const formData = new FormData(document.getElementById('editUserForm'));
        formData.append('action', 'update_user');
        
        fetch('config_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonResponse)
        .then(data => {
            if (data && data.success) {
                const msg = (data.message && String(data.message).trim()) ? data.message : 'Usuario actualizado correctamente';
                showSuccess(msg);
                setTimeout(() => location.reload(), 1000);
            } else {
                showError('Error: ' + ((data && data.message) ? data.message : 'No se pudo actualizar'));
            }
        })
        .catch(err => {
            console.error('updateUser error:', err);
            showError('Error de conexión al actualizar usuario');
        });
    }

    // catalogsReseed eliminado

    function changePassword(userId) {
        if (!ensureModalFromTemplate('changePasswordModal','changePasswordModalTemplate')) return;
        var f = document.getElementById('changePasswordForm');
        var o = document.getElementById('old_password');
        var n = document.getElementById('new_password');
        var c = document.getElementById('confirm_password');
        document.getElementById('password_user_id').value = userId;
        if (f) f.reset();
        document.getElementById('password_user_id').value = userId;
        if (o) o.disabled = false;
        if (n) n.disabled = false;
        if (c) c.disabled = false;
        var modalEl = document.getElementById('changePasswordModal');
        var modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function onHide() {
            modalEl.removeEventListener('hidden.bs.modal', onHide);
            if (o) { o.value=''; o.disabled = true; }
            if (n) { n.value=''; n.disabled = true; }
            if (c) { c.value=''; c.disabled = true; }
            if (f) f.reset();
            modalEl.remove();
        });
        modal.show();
    }

    function updatePassword() {
        const oldPassword = document.getElementById('old_password').value;
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if(!oldPassword) {
            showError('Debes ingresar la contraseña anterior');
            return;
        }

        if(newPassword !== confirmPassword) {
            showError('Las contraseñas no coinciden');
            return;
        }
        
        const formData = new FormData(document.getElementById('changePasswordForm'));
        formData.append('action', 'change_password');
        
        fetch('config_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(parseJsonResponse)
        .then(data => {
            if(data.success) {
                showSuccess('Contraseña actualizada correctamente');
                bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
            } else {
                showError('Error: ' + data.message);
            }
        });
    }

    function deleteUser(userId, userName) {
        showConfirm(`¿Estás seguro de eliminar al usuario "${userName || 'seleccionado'}"?`, function() {
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            
            fetch('config_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if(data.success) {
                    showSuccess('Usuario eliminado correctamente');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error al eliminar usuario');
            });
        });
    }

    // Funcion dummy para compatibilidad si se llama confirmDeleteUser desde algun modal antiguo
    function confirmDeleteUser() {
        console.warn('confirmDeleteUser is deprecated, use deleteUser directly');
    }
    </script>

    <script>
    (function(){
        var pmPage = 1;
        var pmLimit = 6;
        var pmTotal = 0;
        function esc(s){ return String(s == null ? '' : s).replace(/[&<>\"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
        
        // Función para exportar clientes
        function exportClients() {
            const format = document.getElementById('export_format').value;
            const fields = [];
            
            if (document.getElementById('export_name').checked) fields.push('name');
            if (document.getElementById('export_phone').checked) fields.push('phone');
            if (document.getElementById('export_email').checked) fields.push('email');
            if (document.getElementById('export_address').checked) fields.push('address');
            if (document.getElementById('export_identification').checked) fields.push('id_number');
            if (document.getElementById('export_dates').checked) fields.push('dates');
            
            if (fields.length === 0) {
                showNotification('Selecciona al menos un campo para exportar', 'warning');
                return;
            }
            
            // Crear formulario dinámico para enviar datos
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'clients_data_operations.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'export';
            form.appendChild(actionInput);
            
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'format';
            formatInput.value = format;
            form.appendChild(formatInput);
            
            const fieldsInput = document.createElement('input');
            fieldsInput.type = 'hidden';
            fieldsInput.name = 'fields';
            fieldsInput.value = JSON.stringify(fields);
            form.appendChild(fieldsInput);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        // Cargar estadísticas y checklist sólo al abrir sus pestañas
        document.addEventListener('DOMContentLoaded', function() {
            var clientsTab = document.getElementById('clients-data-tab');
            if (clientsTab) {
                clientsTab.addEventListener('click', function(){
                    loadClientStats();
                });
            }

        });
        
    // Funciones del Checklist
    function loadChecklistItems() {
        fetch('checklist_operations.php?action=get_items')
        .then(parseJsonResponse)
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('checklistTableBody');
                tbody.innerHTML = '';
                
                if (data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No hay items configurados</td></tr>';
                }
                
                data.items.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.name}</td>
                        <td>${item.description || '-'}</td>
                        <td>${item.display_order}</td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary rounded-pill me-1" onclick="editChecklistItem(${item.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger rounded-pill" onclick="deleteChecklistItem(${item.id}, '${item.name}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                showError('Error cargando checklist: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error de conexión al cargar checklist');
        });
    }
        
        function openResetOptionsModal() {
            if (!ensureModalFromTemplate('resetOptionsModal','resetOptionsModalTemplate')) return;
            var a = document.getElementById('admin_password_confirm');
            var f = document.getElementById('resetSystemForm');
            if (a) a.disabled = false;
            
            var modalEl = document.getElementById('resetOptionsModal');
            var modal = new bootstrap.Modal(modalEl);
            modalEl.addEventListener('hidden.bs.modal', function onHide() {
                modalEl.removeEventListener('hidden.bs.modal', onHide);
                if (a) { a.value=''; a.disabled = true; }
                if (f) f.reset();
                modalEl.remove();
            });
            modal.show();
        }
        
        function openCreateChecklistItemModal() {
            document.getElementById('createChecklistItemForm').reset();
            new bootstrap.Modal(document.getElementById('createChecklistItemModal')).show();
        }
        
        function createChecklistItem() {
            const formData = new FormData(document.getElementById('createChecklistItemForm'));
            formData.append('action', 'create_item');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            
            fetch('checklist_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showNotification('Item creado correctamente', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('createChecklistItemModal')).hide();
                    loadChecklistItems();
                } else {
                    showNotification('Error: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al crear el item', 'danger');
            });
        }
        
        function editChecklistItem(itemId) {
            fetch(`checklist_operations.php?action=get_item&item_id=${itemId}`)
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    const item = data.item;
                    document.getElementById('edit_item_id').value = item.id;
                    document.getElementById('edit_item_name').value = item.name;
                    document.getElementById('edit_item_description').value = item.description || '';
                    document.getElementById('edit_item_order').value = item.display_order;
                    document.getElementById('edit_item_active').checked = item.is_active == 1;
                    
                    new bootstrap.Modal(document.getElementById('editChecklistItemModal')).show();
                } else {
                    showNotification('Error al cargar los datos del item', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al cargar el item', 'danger');
            });
        }
        
        function updateChecklistItem() {
            const formData = new FormData(document.getElementById('editChecklistItemForm'));
            formData.append('action', 'update_item');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            
            fetch('checklist_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showNotification('Item actualizado correctamente', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editChecklistItemModal')).hide();
                    loadChecklistItems();
                } else {
                    showNotification('Error: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al actualizar el item', 'danger');
            });
        }
        
        function deleteChecklistItem(itemId, itemName) {
            if (confirm(`¿Está seguro de que desea eliminar el item "${itemName}"?`)) {
                const formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('item_id', itemId);
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                fetch('checklist_operations.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        showSuccess('Item eliminado correctamente');
                        loadChecklistItems();
                    } else {
                        showError('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error al eliminar el item');
                });
            }
        }

        // ===== FUNCIONES PARA ACCESORIOS DEL EQUIPO =====
        
        // Función para cargar accesorios
        function loadAccessories() {
            fetch('equipment_accessories_operations.php?action=get_accessories', { headers: { 'Accept': 'application/json' } })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    displayAccessories(data.accessories);
                } else {
                    showError('Error al cargar los accesorios: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error de conexión al cargar los accesorios');
            });
        }

        // Función para mostrar accesorios en la tabla
        function displayAccessories(accessories) {
            const tbody = document.getElementById('accessories-table-body');
            if (!tbody) return;
            
            if (accessories.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="3" class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No hay accesorios configurados</h5>
                            <p class="text-muted">Agrega el primer accesorio para comenzar</p>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            accessories.forEach((accessory, index) => {
                html += `
                    <tr data-id="${accessory.id}" class="accessory-row" draggable="true">
                        <td class="drag-handle text-center">
                            <i class="fas fa-grip-vertical text-muted" style="cursor: grab;"></i>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-box-open text-primary"></i>
                                </div>
                                <strong>${escapeHtml(accessory.name)}</strong>
                            </div>
                        </td>
                        <td>
                            <div class="btn-group" role="group">
                                <button class="btn btn-sm btn-outline-primary rounded-pill me-1" onclick="editAccessory(${accessory.id})" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger rounded-pill" onclick="deleteAccessory(${accessory.id}, '${escapeHtml(accessory.name)}')" title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            
            // Inicializar drag and drop después de renderizar la tabla
            initializeDragAndDrop();
        }

        // Función para inicializar drag and drop
        function initializeDragAndDrop() {
            const tbody = document.getElementById('accessories-table-body');
            if (!tbody) return;
            const rows = tbody.querySelectorAll('.accessory-row');
            
            rows.forEach(row => {
                row.setAttribute('draggable', 'true'); // Asegurar atributo
                
                // Prevenir que los botones interfieran con el drag
                const buttons = row.querySelectorAll('button, .btn, .btn-group');
                buttons.forEach(button => {
                    button.addEventListener('mousedown', function(e) { e.stopPropagation(); });
                    button.addEventListener('click', function(e) { e.stopPropagation(); });
                    // Prevenir drag start desde botones
                    button.addEventListener('dragstart', function(e) { 
                        e.preventDefault(); 
                        e.stopPropagation(); 
                    });
                });

                row.addEventListener('dragstart', handleDragStart);
                row.addEventListener('dragend', handleDragEnd);
                row.addEventListener('dragover', handleDragOver);
                row.addEventListener('drop', handleDrop);
                row.addEventListener('dragenter', handleDragEnter);
                row.addEventListener('dragleave', handleDragLeave);
            });
        }

        // Función para manejar inicio del drag
        function handleDragStart(e) {
            // Asegurar que el target es la fila
            const row = e.target.closest('.accessory-row');
            if (!row) {
                e.preventDefault();
                return;
            }

            // Si el clic fue en un botón (aunque el stopPropagation debería prevenirlo), cancelar
            if (e.target.closest('button, .btn')) {
                e.preventDefault();
                return;
            }

            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', row.outerHTML);
            e.dataTransfer.setData('text/plain', row.dataset.id);
            
            // Mejorar imagen fantasma
            try {
                e.dataTransfer.setDragImage(row, 0, 0);
            } catch(err) {}
        }

        // Función para manejar fin del drag
        function handleDragEnd(e) {
            e.target.classList.remove('dragging');
            document.querySelectorAll('.accessory-row').forEach(row => {
                row.classList.remove('drag-over');
            });
        }

        // Función para manejar drag over
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }

        // Función para manejar drop
        function handleDrop(e) {
            e.preventDefault();
            const draggedId = e.dataTransfer.getData('text/plain');
            const draggedRow = document.querySelector(`[data-id="${draggedId}"]`);
            const targetRow = e.target.closest('.accessory-row');
            
            if (draggedRow && targetRow && draggedRow !== targetRow) {
                const tbody = document.getElementById('accessories-table-body');
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('.accessory-row'));
                const draggedIndex = rows.indexOf(draggedRow);
                const targetIndex = rows.indexOf(targetRow);
                
                // Mover la fila
                if (draggedIndex < targetIndex) {
                    targetRow.parentNode.insertBefore(draggedRow, targetRow.nextSibling);
                } else {
                    targetRow.parentNode.insertBefore(draggedRow, targetRow);
                }
                
                // Actualizar orden en la base de datos
                updateAccessoriesOrder();
            }
        }

        // Función para manejar drag enter
        function handleDragEnter(e) {
            e.preventDefault();
            e.target.closest('.accessory-row').classList.add('drag-over');
        }

        // Función para manejar drag leave
        function handleDragLeave(e) {
            e.preventDefault();
            if (!e.target.closest('.accessory-row')) {
                e.target.closest('.accessory-row').classList.remove('drag-over');
            }
        }

        // Función para actualizar el orden de los accesorios
        function updateAccessoriesOrder() {
            const tbody = document.getElementById('accessories-table-body');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('.accessory-row'));
            const orderData = rows.map((row, index) => ({
                id: row.dataset.id,
                sort_order: index + 1
            }));
            
            const formData = new FormData();
            formData.append('action', 'update_order');
            formData.append('accessories', JSON.stringify(orderData));
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            
            fetch('equipment_accessories_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showNotification('Orden actualizado exitosamente', 'success');
                } else {
                    showNotification('Error al actualizar el orden: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al actualizar el orden', 'danger');
            });
        }

        // Función para obtener nombre de categoría
        function getCategoryDisplayName(category) {
            const categories = {
                'power': 'Carga y Energía',
                'protection': 'Protección',
                'hardware': 'Hardware',
                'connectivity': 'Conectividad',
                'storage': 'Almacenamiento',
                'audio': 'Audio',
                'general': 'General'
            };
            return categories[category] || category;
        }

        // Función para obtener icono de categoría
        function getCategoryIcon(category) {
            const icons = {
                'power': 'fas fa-battery-full',
                'protection': 'fas fa-shield-alt',
                'hardware': 'fas fa-microchip',
                'connectivity': 'fas fa-wifi',
                'storage': 'fas fa-hdd',
                'audio': 'fas fa-headphones',
                'general': 'fas fa-box'
            };
            return icons[category] || 'fas fa-box';
        }

        // Función para abrir modal de crear accesorio
        function openCreateAccessoryModal() {
            document.getElementById('createAccessoryForm').reset();
            new bootstrap.Modal(document.getElementById('createAccessoryModal')).show();
        }

        // Función para crear accesorio
        function createAccessory() {
            const form = document.getElementById('createAccessoryForm');
            const formData = new FormData(form);
            formData.append('action', 'create_accessory');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

            fetch('equipment_accessories_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createAccessoryModal')).hide();
                    loadAccessories();
                    showSuccess('Accesorio creado exitosamente');
                } else {
                    showError('Error al crear el accesorio: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error de conexión al crear el accesorio');
            });
        }

        // Función para editar accesorio
        function editAccessory(id) {
            fetch(`equipment_accessories_operations.php?action=get_accessory&id=${id}`)
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    const accessory = data.accessory;
                    
                    document.getElementById('edit_accessory_id').value = accessory.id;
                    document.getElementById('edit_accessory_name').value = accessory.name;
                    
                    new bootstrap.Modal(document.getElementById('editAccessoryModal')).show();
                } else {
                    showError('Error al cargar el accesorio: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error de conexión al cargar el accesorio');
            });
        }

        // Función para actualizar accesorio
        function updateAccessory() {
            const form = document.getElementById('editAccessoryForm');
            const formData = new FormData(form);
            formData.append('action', 'update_accessory');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

            fetch('equipment_accessories_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editAccessoryModal')).hide();
                    loadAccessories();
                    showSuccess('Accesorio actualizado exitosamente');
                } else {
                    showError('Error al actualizar el accesorio: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error de conexión al actualizar el accesorio');
            });
        }

        // Función para eliminar accesorio
        function deleteAccessory(id, name) {
            showConfirm(`¿Estás seguro de que deseas eliminar el accesorio "${name}"?`, function() {
                const formData = new FormData();
                formData.append('action', 'delete_accessory');
                formData.append('id', id);
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

                fetch('equipment_accessories_operations.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        loadAccessories();
                        showSuccess('Accesorio eliminado exitosamente');
                    } else {
                        showError('Error al eliminar el accesorio: ' + data.message);
                    }
                })
                .catch(error => {
                    showError('Error de conexión al eliminar el accesorio');
                });
            });
        }

        // Función para escapar HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Cargar accesorios cuando se active la pestaña
        document.addEventListener('DOMContentLoaded', function() {
            // Listener eliminado para evitar conflicto con settings-tabs.js y iframe

            
        // Expose functions to global scope for onclick events
        window.exportClients = exportClients;
        window.importClients = importClients;
        window.loadClientStats = loadClientStats;
        window.loadChecklistItems = loadChecklistItems;
        window.editChecklistItem = editChecklistItem;
        window.deleteChecklistItem = deleteChecklistItem;
        window.openCreateChecklistItemModal = openCreateChecklistItemModal;
        window.createChecklistItem = createChecklistItem;
        window.updateChecklistItem = updateChecklistItem;
        
        window.loadAccessories = loadAccessories;
        window.openCreateAccessoryModal = openCreateAccessoryModal;
        window.createAccessory = createAccessory;
        window.editAccessory = editAccessory;
        window.updateAccessory = updateAccessory;
        window.deleteAccessory = deleteAccessory;
        window.updateAccessoriesOrder = updateAccessoriesOrder;

        // Función para guardar todas las configuraciones
            window.saveAllConfigurations = function() {
                // Primero guardar configuración de empresa
                const companyForm = document.getElementById('companyForm');
                const companyFormData = new FormData(companyForm);
                companyFormData.append('action', 'update_company');
                companyFormData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                fetch('config_operations.php', {
                    method: 'POST',
                    body: companyFormData
                })
                .then(async (response) => {
                    const ct = response.headers.get('content-type') || '';
                    const text = await response.text();
                    if (!ct.includes('application/json')) {
                        throw new Error('Respuesta no JSON: ' + text.slice(0, 200));
                    }
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('JSON inválido: ' + text.slice(0, 200));
                    }
                    return data;
                })
                .then(data => {
                    if (data.success) {
                        // Luego guardar configuraciones regionales
                        const regionalForm = document.getElementById('regionalForm');
                        const regionalFormData = new FormData(regionalForm);
                        
                        // Copiar campos de Empresa a Regional para guardarlos en system_config
                        (function(){
                            var op = document.getElementById('order_prefix');
                            if (op) { regionalFormData.append('order_prefix', (op.value || '').trim()); }
                            
                            var on = document.getElementById('order_next_number');
                            if (on) { regionalFormData.append('order_next_number', (on.value || '').trim()); }
                            
                            var invn = document.getElementById('invoice_next_number');
                            if (invn) { regionalFormData.append('invoice_next_number', (invn.value || '').trim()); }
                        })();
                        // Append templates form data (warranty) to regionalFormData
                        const templatesForm = document.getElementById('templatesForm');
                        if (templatesForm) {
                            const templatesFormData = new FormData(templatesForm);
                            for (var pair of templatesFormData.entries()) {
                                regionalFormData.append(pair[0], pair[1]);
                            }
                        }

                        regionalFormData.append('action', 'update_regional_settings');
                        regionalFormData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                        
                        return fetch('config_operations.php', {
                            method: 'POST',
                            body: regionalFormData
                        });
                    } else {
                        throw new Error(data.message || 'Error al guardar configuración de empresa');
                    }
                })
                .then(async (response) => {
                    const ct = response.headers.get('content-type') || '';
                    const text = await response.text();
                    if (!ct.includes('application/json')) {
                        throw new Error('Respuesta no JSON: ' + text.slice(0, 200));
                    }
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('JSON inválido: ' + text.slice(0, 200));
                    }
                    return data;
                })
                .then(data => {
                    if (data.success) {
                        showNotification('Todas las configuraciones guardadas exitosamente', 'success');
                        // Recargar la página para mostrar los cambios
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Error al guardar las configuraciones regionales: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error al guardar las configuraciones: ' + error.message, 'error');
                });
            };
            
            window.saveDocumentsConfig = function() {
                const regionalForm = document.getElementById('regionalForm');
                const regionalFormData = new FormData(regionalForm);
                
                // Append templates form data (warranty) to regionalFormData
                const templatesForm = document.getElementById('templatesForm');
                if (templatesForm) {
                    const templatesFormData = new FormData(templatesForm);
                    for (var pair of templatesFormData.entries()) {
                        regionalFormData.append(pair[0], pair[1]);
                    }
                }

                regionalFormData.append('action', 'update_regional_settings');
                const csrfToken = document.querySelector('meta[name="csrf-token"]');
                if(csrfToken) regionalFormData.append('csrf_token', csrfToken.getAttribute('content'));
                
                fetch('config_operations.php', {
                    method: 'POST',
                    body: regionalFormData
                })
                .then(async (response) => {
                    const ct = response.headers.get('content-type') || '';
                    const text = await response.text();
                    if (!ct.includes('application/json')) throw new Error('Respuesta no JSON');
                    return JSON.parse(text);
                })
                .then(data => {
                    if (data.success) {
                        showNotification('Configuración de documentos guardada exitosamente', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error al guardar: ' + error.message, 'error');
                });
            };

            window.previewLabel = function(doPrint) {
                const idEl = document.getElementById('label_preview_order_id');
                const frame = document.getElementById('labelPreviewFrame');
                const stage = document.getElementById('labelPreviewStage');
                const area = document.getElementById('labelPreviewArea');
                const scaleEl = document.getElementById('labelPreviewScale');
                const orderId = idEl ? parseInt(idEl.value || '0', 10) : 0;
                if (!orderId || orderId <= 0 || !frame) {
                    showNotification('Ingrese un ID de orden válido para la vista previa', 'error');
                    return;
                }
                const params = new URLSearchParams();
                params.set('id', String(orderId));
                params.set('preview', '1');
                if (doPrint) params.set('print', 'true');

                const read = (id) => {
                    const el = document.getElementById(id);
                    if (!el) return null;
                    if (el.type === 'checkbox') return el.checked ? '1' : '0';
                    return (el.value !== undefined) ? String(el.value) : null;
                };

                const keys = [
                    'label_paper_size',
                    'label_style',
                    'label_custom_width_mm',
                    'label_custom_height_mm',
                    'label_padding_mm',
                    'label_show_logo',
                    'label_layout',
                    'label_font_family',
                    'label_multiline_lines',
                    'label_logo_mm',
                    'label_qr_mm',
                    'label_order_font_pt',
                    'label_line_font_pt',
                    'label_uppercase',
                    'label_border',
                    'label_element_order',
                    'label_copies',
                    'label_show_qr',
                    'label_show_client',
                    'label_show_client_phone',
                    'label_show_serial',
                    'label_show_date',
                    'label_show_device_type',
                    'label_show_device',
                    'label_show_issue',
                    'label_show_client_observations',
                    'label_show_tech_notes',
                    'label_show_accessories',
                    'label_preview_zoom'
                ];
                for (const k of keys) {
                    const v = read(k);
                    if (v !== null && v !== '') params.set(k, v);
                }
                if (!doPrint) {
                    params.set('label_copies', '1');
                }
                params.set('_ts', String(Date.now()));

                const mmToPx = 3.7795275591;
                const preset = read('label_paper_size') || 'sticker_5030';
                let wmm = 50, hmm = 30;
                if (preset === 'sticker_4025') { wmm = 40; hmm = 25; }
                if (preset === 'sticker_5025') { wmm = 50; hmm = 25; }
                if (preset === 'sticker_5030') { wmm = 50; hmm = 30; }
                if (preset === 'sticker_6040') { wmm = 60; hmm = 40; }
                if (preset === 'sticker_7050') { wmm = 70; hmm = 50; }
                if (preset === 'sticker_8050') { wmm = 80; hmm = 50; }
                if (preset === 'sticker_10050') { wmm = 100; hmm = 50; }
                if (preset === 'sticker_100150') { wmm = 100; hmm = 150; }
                if (preset === 'custom') {
                    const cw = parseFloat(read('label_custom_width_mm') || '50');
                    const ch = parseFloat(read('label_custom_height_mm') || '30');
                    if (!isNaN(cw) && cw > 0) wmm = cw;
                    if (!isNaN(ch) && ch > 0) hmm = ch;
                }

                const wpx = Math.max(60, Math.round(wmm * mmToPx));
                const hpx = Math.max(60, Math.round(hmm * mmToPx));
                const frameW = wpx;
                const frameH = hpx;
                frame.setAttribute('scrolling', 'no');
                const previewZoom = doPrint ? 1 : 2;
                params.set('label_preview_zoom', String(previewZoom));
                frame.style.width = Math.round(frameW * previewZoom) + 'px';
                frame.style.height = Math.round(frameH * previewZoom) + 'px';
                if (stage && area) {
                    stage.style.width = Math.round(frameW * previewZoom) + 'px';
                    stage.style.height = Math.round(frameH * previewZoom) + 'px';
                    stage.style.zoom = '';
                    stage.style.transform = 'none';
                    if (scaleEl) {
                        scaleEl.textContent = 'Zoom: ' + Math.round(previewZoom * 100) + '%';
                    }
                }

                frame.src = '../orders/print_label.php?' + params.toString();
            };

            const initLabelEditor = function() {
                const root = document.getElementById('labelConfigRoot');
                if (!root) return;

                const presetEl = document.getElementById('label_paper_size');
                const customRow = document.getElementById('labelCustomSizeRow');
                const wEl = document.getElementById('label_custom_width_mm');
                const hEl = document.getElementById('label_custom_height_mm');
                const showQrEl = document.getElementById('label_show_qr');
                const qrMmEl = document.getElementById('label_qr_mm');
                const showLogoEl = document.getElementById('label_show_logo');
                const logoMmEl = document.getElementById('label_logo_mm');
                const layoutEl = document.getElementById('label_layout');
                const showClientEl = document.getElementById('label_show_client');
                const showClientPhoneEl = document.getElementById('label_show_client_phone');
                const autoEl = document.getElementById('label_auto_preview');
                const idEl = document.getElementById('label_preview_order_id');
                const orderList = document.getElementById('labelOrderList');
                const orderInput = document.getElementById('label_element_order');

                const applySwitchStateBadges = function() {
                    const switches = root.querySelectorAll('.form-check.form-switch');
                    switches.forEach(sw => {
                        const input = sw.querySelector('input.form-check-input[type="checkbox"]');
                        const label = sw.querySelector('label.form-check-label');
                        if (!input || !label) return;
                        let badge = label.querySelector('span[data-switch-state="1"]');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.setAttribute('data-switch-state', '1');
                            badge.className = 'badge ms-2';
                            badge.style.fontSize = '0.7rem';
                            label.appendChild(badge);
                        }
                        const on = !!input.checked;
                        badge.textContent = on ? 'Visible' : 'Oculto';
                        badge.classList.toggle('bg-success', on);
                        badge.classList.toggle('bg-secondary', !on);
                    });
                };

                const sync = function() {
                    applySwitchStateBadges();
                    const preset = presetEl ? String(presetEl.value || '') : '';
                    const isCustom = preset === 'custom';
                    if (customRow) customRow.style.display = isCustom ? '' : 'none';
                    if (wEl) wEl.disabled = !isCustom;
                    if (hEl) hEl.disabled = !isCustom;

                    const layoutVal = layoutEl ? String(layoutEl.value || '') : '';
                    const wantsNoQr = layoutVal === 'no_qr';
                    const showQr = !!(showQrEl && showQrEl.checked) && !wantsNoQr;
                    if (showQrEl) showQrEl.disabled = wantsNoQr;
                    if (showQrEl && wantsNoQr) showQrEl.checked = false;
                    if (qrMmEl) qrMmEl.disabled = !showQr;
                    if (layoutEl) layoutEl.disabled = false;

                    const showLogo = !!(showLogoEl && showLogoEl.checked);
                    if (logoMmEl) {
                        logoMmEl.disabled = !showLogo;
                        if (!showLogo) logoMmEl.value = '0';
                        if (showLogo && (String(logoMmEl.value || '') === '0')) logoMmEl.value = '10';
                    }

                    const showClient = !!(showClientEl && showClientEl.checked);
                    if (showClientPhoneEl) {
                        showClientPhoneEl.disabled = !showClient;
                        if (!showClient) showClientPhoneEl.checked = false;
                    }
                };

                let t = null;
                const schedule = function() {
                    sync();
                    if (!autoEl || !autoEl.checked) return;
                    if (!idEl || !idEl.value) return;
                    clearTimeout(t);
                    t = setTimeout(function() { previewLabel(false); }, 350);
                };

                sync();

                root.addEventListener('change', function(e) {
                    const target = e.target;
                    if (!target) return;
                    if (target.id === 'label_auto_preview') return;
                    schedule();
                });
                root.addEventListener('input', function(e) {
                    const target = e.target;
                    if (!target) return;
                    if (target.id === 'label_preview_order_id') return;
                    schedule();
                });

                if (idEl) {
                    idEl.addEventListener('input', function() {
                        if (!autoEl || !autoEl.checked) return;
                        clearTimeout(t);
                        t = setTimeout(function() { previewLabel(false); }, 350);
                    });
                    idEl.addEventListener('keydown', function(ev) {
                        if (ev.key === 'Enter') {
                            ev.preventDefault();
                            previewLabel(false);
                        }
                    });
                }

                if (autoEl) {
                    autoEl.addEventListener('change', function() {
                        sync();
                        if (autoEl.checked && idEl && idEl.value) previewLabel(false);
                    });
                }

                const updateOrderInput = function() {
                    if (!orderList || !orderInput) return;
                    const keys = Array.from(orderList.querySelectorAll('[data-key]')).map(el => el.getAttribute('data-key')).filter(Boolean);
                    orderInput.value = keys.join(',');
                };

                const applySavedOrder = function() {
                    if (!orderList || !orderInput) return;
                    const desired = String(orderInput.value || '').split(',').map(s => s.trim()).filter(Boolean);
                    if (!desired.length) return;
                    const map = new Map();
                    Array.from(orderList.children).forEach(el => {
                        const k = el.getAttribute('data-key');
                        if (k) map.set(k, el);
                    });
                    desired.forEach(k => {
                        const el = map.get(k);
                        if (el) orderList.appendChild(el);
                    });
                    Array.from(map.keys()).forEach(k => {
                        const el = map.get(k);
                        if (el) orderList.appendChild(el);
                    });
                    updateOrderInput();
                };

                const enableDnD = function() {
                    if (!orderList) return;
                    let dragEl = null;
                    orderList.addEventListener('dragstart', function(e) {
                        const target = e.target && e.target.closest ? e.target.closest('[data-key]') : null;
                        if (!target) return;
                        dragEl = target;
                        e.dataTransfer.effectAllowed = 'move';
                        target.style.opacity = '0.6';
                    });
                    orderList.addEventListener('dragend', function() {
                        if (dragEl) dragEl.style.opacity = '';
                        dragEl = null;
                    });
                    orderList.addEventListener('dragover', function(e) {
                        if (!dragEl) return;
                        e.preventDefault();
                        const over = e.target && e.target.closest ? e.target.closest('[data-key]') : null;
                        if (!over || over === dragEl || over.parentNode !== orderList) return;
                        const rect = over.getBoundingClientRect();
                        const next = (e.clientY - rect.top) > (rect.height / 2);
                        orderList.insertBefore(dragEl, next ? over.nextSibling : over);
                    });
                    orderList.addEventListener('drop', function(e) {
                        if (!dragEl) return;
                        e.preventDefault();
                        updateOrderInput();
                        schedule();
                    });
                };

                applySavedOrder();
                enableDnD();
            };

            initLabelEditor();
        });
    // Exponer funciones al scope global
    window.openResetOptionsModal = openResetOptionsModal;
    window.exportClients = exportClients;
    window.loadChecklistItems = loadChecklistItems;
    window.createChecklistItem = createChecklistItem;
    window.editChecklistItem = editChecklistItem;
    window.updateChecklistItem = updateChecklistItem;
    window.deleteChecklistItem = deleteChecklistItem;
    window.openCreateChecklistItemModal = openCreateChecklistItemModal;

    })();
    </script>

<!-- Modales Checklist, Accesorio, Reset movidos al final -->


<!-- Modales Movidos -->
<div class="modal fade" id="accEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Cuenta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-body p-4">
                        <input type="hidden" id="accEditId">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Método de pago</label>
                            <input type="text" class="form-control bg-light border-0" id="accEditMethod" readonly style="border-radius: 0.5rem;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Alias</label>
                            <input type="text" class="form-control" id="accEditAlias" placeholder="Alias" style="border-radius: 0.5rem;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Número</label>
                            <input type="text" class="form-control" id="accEditNumber" placeholder="Número de cuenta" style="border-radius: 0.5rem;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Tipo</label>
                            <select class="form-select" id="accEditType" style="border-radius: 0.5rem;">
                                <option value="wallet">Billetera</option>
                                <option value="ahorros">Ahorros</option>
                                <option value="corriente">Corriente</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Titular</label>
                            <input type="text" class="form-control" id="accEditHolder" placeholder="Nombre del titular" style="border-radius: 0.5rem;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Documento</label>
                            <input type="text" class="form-control" id="accEditHolderId" placeholder="CC/NIT" style="border-radius: 0.5rem;">
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="accEditDefault">
                            <label class="form-check-label" for="accEditDefault">Marcar como predeterminada</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="accEditActive">
                            <label class="form-check-label" for="accEditActive">Cuenta activa</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" id="accEditSave">
                    <i class="fas fa-save me-2"></i>Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pmAccountsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title fw-bold"><i class="fas fa-university me-2"></i>Cuentas asociadas: <span id="pmAccModalTitle"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Alias</th>
                                        <th>Número</th>
                                        <th>Tipo</th>
                                        <th>Titular</th>
                                        <th>Estado</th>
                                        <th>Predeterminada</th>
                                        <th class="pe-4">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="pmAccModalBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pmEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Método de Pago</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-body p-4">
                        <input type="hidden" id="pmEditId">
                        <input type="hidden" id="pmEditAccountId">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Nombre</label>
                            <input type="text" class="form-control" id="pmEditName" placeholder="Nombre del método" style="border-radius: 0.5rem;">
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Número</label>
                                <input type="text" class="form-control" id="pmEditAccNumber" placeholder="Número de cuenta" style="border-radius: 0.5rem;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Tipo</label>
                                <select class="form-select" id="pmEditAccType" style="border-radius: 0.5rem;">
                                    <option value="wallet">Billetera</option>
                                    <option value="ahorros">Ahorros</option>
                                    <option value="corriente">Corriente</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Titular</label>
                                <input type="text" class="form-control" id="pmEditAccHolder" placeholder="Nombre del titular" style="border-radius: 0.5rem;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">Documento</label>
                                <input type="text" class="form-control" id="pmEditAccHolderId" placeholder="CC/NIT" style="border-radius: 0.5rem;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" id="pmEditSave">
                    <i class="fas fa-save me-2"></i>Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pmCreateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Crear Método de Pago</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Nombre del método</label>
                            <input type="text" class="form-control" id="pc_name" placeholder="Nombre del método" style="border-radius: 0.5rem;">
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Número</label>
                                <input type="text" class="form-control" id="pc_acc_number" placeholder="Número de cuenta" style="border-radius: 0.5rem;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Tipo</label>
                                <select class="form-select" id="pc_acc_type" style="border-radius: 0.5rem;">
                                    <option value="wallet">Billetera</option>
                                    <option value="ahorros">Ahorros</option>
                                    <option value="corriente">Corriente</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">Titular</label>
                                <input type="text" class="form-control" id="pc_acc_holder" placeholder="Nombre del titular" style="border-radius: 0.5rem;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">Documento</label>
                                <input type="text" class="form-control" id="pc_acc_holder_id" placeholder="CC/NIT" style="border-radius: 0.5rem;">
                            </div>
                            <div class="col-md-6 form-check ms-2 pt-4">
                                <input class="form-check-input" type="checkbox" id="pc_acc_default" checked>
                                <label class="form-check-label" for="pc_acc_default">Por defecto</label>
                            </div>
                            <div class="col-12 mt-3">
                                <div class="alert alert-info border-0 shadow-sm" style="border-radius: 0.5rem;">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <small>Si completas estos campos, se creará y asociará la cuenta al método nuevo.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" id="pc_save">
                    <i class="fas fa-save me-2"></i>Guardar
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Template: Modal Crear Usuario -->
    <template id="createUserModalTemplate">
        <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="createUserForm" autocomplete="off">
                                <div class="mb-3">
                                    <label for="create_name" class="form-label fw-bold text-dark">Nombre</label>
                                    <input type="text" class="form-control" id="create_name" name="name" required style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="create_email" class="form-label fw-bold text-dark">Email</label>
                                    <input type="email" class="form-control" id="create_email" name="email" required style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="create_photo" class="form-label fw-bold text-dark">Foto de Perfil (Opcional)</label>
                                    <input type="file" class="form-control" id="create_photo" name="photo" accept="image/*" style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="create_password" class="form-label fw-bold text-dark">Contraseña</label>
                                    <input type="text" data-type="password" class="form-control" id="create_password" name="password" required autocomplete="new-password" disabled style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="create_role" class="form-label fw-bold text-dark">Rol</label>
                                    <select class="form-select" id="create_role" name="role" required style="border-radius: 0.5rem;">
                                        <option value="Usuario">Usuario</option>
                                        <option value="Editor">Editor</option>
                                        <option value="Administrador">Administrador</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="createUser()">
                        <i class="fas fa-save me-2"></i>Crear Usuario
                    </button>
                </div>
            </div>
        </div>
        </div>
    </template>

    <!-- Modal Editar Usuario -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i>Editar Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="editUserForm">
                                <input type="hidden" id="edit_user_id" name="user_id">
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label fw-bold text-dark">Nombre</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_email" class="form-label fw-bold text-dark">Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" required style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_photo" class="form-label fw-bold text-dark">Foto de Perfil (Opcional)</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <div id="current_photo_container"></div>
                                        <input type="file" class="form-control" id="edit_photo" name="photo" accept="image/*" style="border-radius: 0.5rem;" onchange="previewImage(this)">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_role" class="form-label fw-bold text-dark">Rol</label>
                                    <select class="form-select" id="edit_role" name="role" required style="border-radius: 0.5rem;">
                                        <option value="user">Usuario</option>
                                        <option value="technician">Técnico</option>
                                        <option value="admin">Administrador</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_active" class="form-label fw-bold text-dark">Estado</label>
                                    <select class="form-select" id="edit_active" name="active" required style="border-radius: 0.5rem;">
                                        <option value="1">Activo</option>
                                        <option value="0">Inactivo</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="updateUser()">
                        <i class="fas fa-save me-2"></i>Actualizar Usuario
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Template: Modal Cambiar Contraseña -->
    <template id="changePasswordModalTemplate">
        <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-warning text-dark" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-key me-2"></i>Cambiar Contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="changePasswordForm" autocomplete="off">
                                <input type="hidden" id="password_user_id" name="user_id">
                                <div class="mb-3">
                                    <label for="old_password" class="form-label fw-bold text-dark">Contraseña Anterior</label>
                                    <input type="text" data-type="password" class="form-control" id="old_password" name="old_password" required autocomplete="current-password" disabled style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label fw-bold text-dark">Nueva Contraseña</label>
                                    <input type="text" data-type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password" disabled style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label fw-bold text-dark">Confirmar Contraseña</label>
                                    <input type="text" data-type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" disabled style="border-radius: 0.5rem;">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning rounded-pill px-4 shadow-sm" onclick="updatePassword()">
                        <i class="fas fa-save me-2"></i>Cambiar Contraseña
                    </button>
                </div>
            </div>
            </div>
        </div>
    </template>

    <!-- Template: Modal Eliminar Usuario -->
    <template id="deleteUserModalTemplate">
        <div class="modal fade" id="deleteUserModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                    <div class="modal-header border-0 bg-danger text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                        <h5 class="modal-title fw-bold"><i class="fas fa-trash-alt me-2"></i>Eliminar Usuario</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body bg-light">
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4 text-center">
                                <div class="mb-3">
                                    <i class="fas fa-exclamation-circle text-danger fa-4x mb-3"></i>
                                    <h5 class="text-dark fw-bold">¿Estás seguro?</h5>
                                    <p class="text-muted mb-0">Esta acción eliminará permanentemente al usuario <strong id="delete_user_name"></strong>. No se puede deshacer.</p>
                                </div>
                                <input type="hidden" id="delete_user_id">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm" onclick="confirmDeleteUser()">
                            <i class="fas fa-trash-alt me-2"></i>Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Modal Crear Estado -->
    <div class="modal fade" id="createStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <form id="create-status-form">
                    <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                        <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Nuevo Estado</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body bg-light">
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4">
                                <!-- Vista Previa -->
                                <div class="mb-4 text-center">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Vista Previa</label>
                                    <div class="p-3 bg-white rounded border d-flex justify-content-center align-items-center" style="min-height: 80px;">
                                        <span id="create_preview_badge" class="badge rounded-pill fs-6 px-3 py-2" style="background-color: #6c757d; color: white;">
                                            <span id="create_preview_emoji">🟡</span> <span id="create_preview_name">Nombre del Estado</span>
                                        </span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="name" class="form-label fw-bold text-dark">Nombre del Estado</label>
                                    <input type="text" class="form-control" id="name" name="name" required style="border-radius: 0.5rem;" oninput="updateStatusPreview('create')">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="emoji" class="form-label fw-bold text-dark">Emoji</label>
                                        <input type="text" class="form-control" id="emoji" name="emoji" placeholder="🟡" style="border-radius: 0.5rem;" oninput="updateStatusPreview('create')">
                                    </div>
                                    <div class="col-md-8 mb-3">
                                        <label for="color" class="form-label fw-bold text-dark">Color</label>
                                        <div class="d-flex align-items-center">
                                            <input type="color" class="form-control form-control-color me-2" id="color" name="color" value="#6c757d" style="border-radius: 0.5rem;" oninput="updateStatusPreview('create')">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #28a745; border-color: #28a745; width: 25px; height: 25px;" onclick="setQuickColor('create', '#28a745')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #17a2b8; border-color: #17a2b8; width: 25px; height: 25px;" onclick="setQuickColor('create', '#17a2b8')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #ffc107; border-color: #ffc107; width: 25px; height: 25px;" onclick="setQuickColor('create', '#ffc107')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #dc3545; border-color: #dc3545; width: 25px; height: 25px;" onclick="setQuickColor('create', '#dc3545')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #6c757d; border-color: #6c757d; width: 25px; height: 25px;" onclick="setQuickColor('create', '#6c757d')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #343a40; border-color: #343a40; width: 25px; height: 25px;" onclick="setQuickColor('create', '#343a40')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #007bff; border-color: #007bff; width: 25px; height: 25px;" onclick="setQuickColor('create', '#007bff')"></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                
                                <div class="card p-3 border-0 bg-white shadow-sm" style="border-radius: 0.5rem;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default">
                                        <label class="form-check-label" for="is_default">
                                            Estado por defecto para nuevas órdenes
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm">
                            <i class="fas fa-save me-2"></i>Crear Estado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Editar Estado -->
    <div class="modal fade" id="editStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <form id="edit-status-form">
                    <input type="hidden" id="edit_id" name="id">
                    
                    <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Estado</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body bg-light">
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4">
                                <!-- Vista Previa -->
                                <div class="mb-4 text-center">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Vista Previa</label>
                                    <div class="p-3 bg-white rounded border d-flex justify-content-center align-items-center" style="min-height: 80px;">
                                        <span id="edit_preview_badge" class="badge rounded-pill fs-6 px-3 py-2" style="background-color: #6c757d; color: white;">
                                            <span id="edit_preview_emoji"></span> <span id="edit_preview_name">Nombre del Estado</span>
                                        </span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="edit_name" class="form-label fw-bold text-dark">Nombre del Estado</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required style="border-radius: 0.5rem;" oninput="updateStatusPreview('edit')">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="edit_emoji" class="form-label fw-bold text-dark">Emoji</label>
                                        <input type="text" class="form-control" id="edit_emoji" name="emoji" style="border-radius: 0.5rem;" oninput="updateStatusPreview('edit')">
                                    </div>
                                    <div class="col-md-8 mb-3">
                                        <label for="edit_color" class="form-label fw-bold text-dark">Color</label>
                                        <div class="d-flex align-items-center">
                                            <input type="color" class="form-control form-control-color me-2" id="edit_color" name="color" style="border-radius: 0.5rem;" oninput="updateStatusPreview('edit')">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #28a745; border-color: #28a745; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#28a745')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #17a2b8; border-color: #17a2b8; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#17a2b8')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #ffc107; border-color: #ffc107; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#ffc107')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #dc3545; border-color: #dc3545; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#dc3545')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #6c757d; border-color: #6c757d; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#6c757d')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #343a40; border-color: #343a40; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#343a40')"></button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" style="background-color: #007bff; border-color: #007bff; width: 25px; height: 25px;" onclick="setQuickColor('edit', '#007bff')"></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_description" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="3" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                
                                <div class="card mb-3 p-3 border-0 bg-white shadow-sm" style="border-radius: 0.5rem;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_is_default" name="is_default">
                                        <label class="form-check-label" for="edit_is_default">
                                            Estado por defecto para nuevas órdenes
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="card p-3 border-0 bg-white shadow-sm" style="border-radius: 0.5rem;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                        <label class="form-check-label" for="edit_is_active">
                                            Estado activo
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm">
                            <i class="fas fa-save me-2"></i>Actualizar Estado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Crear Item de Checklist -->
    <div class="modal fade" id="createChecklistItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-list-ul me-2"></i>Crear Nuevo Item de Checklist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="createChecklistItemForm">
                                <div class="mb-3">
                                    <label for="create_item_name" class="form-label fw-bold text-dark">Nombre del Item</label>
                                    <input type="text" class="form-control" id="create_item_name" name="name" required placeholder="Ej: Funda, Cargador, Batería" style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="create_item_description" class="form-label fw-bold text-dark">Descripción (Opcional)</label>
                                    <textarea class="form-control" id="create_item_description" name="description" rows="2" placeholder="Descripción adicional del item" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="create_item_order" class="form-label fw-bold text-dark">Orden de Visualización</label>
                                    <input type="number" class="form-control" id="create_item_order" name="display_order" value="1" min="1" style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="create_item_active" name="is_active" checked>
                                        <label class="form-check-label" for="create_item_active">
                                            Item activo
                                        </label>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="createChecklistItem()">
                        <i class="fas fa-save me-2"></i>Crear Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Editar Item de Checklist -->
    <div class="modal fade" id="editChecklistItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Item de Checklist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="editChecklistItemForm">
                                <input type="hidden" id="edit_item_id" name="item_id">
                                <div class="mb-3">
                                    <label for="edit_item_name" class="form-label fw-bold text-dark">Nombre del Item</label>
                                    <input type="text" class="form-control" id="edit_item_name" name="name" required style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_item_description" class="form-label fw-bold text-dark">Descripción (Opcional)</label>
                                    <textarea class="form-control" id="edit_item_description" name="description" rows="2" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_item_order" class="form-label fw-bold text-dark">Orden de Visualización</label>
                                    <input type="number" class="form-control" id="edit_item_order" name="display_order" min="1" style="border-radius: 0.5rem;">
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_item_active" name="is_active">
                                        <label class="form-check-label" for="edit_item_active">
                                            Item activo
                                        </label>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="updateChecklistItem()">
                        <i class="fas fa-save me-2"></i>Actualizar Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear Accesorio -->
    <div class="modal fade" id="createAccessoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Crear Nuevo Accesorio</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="createAccessoryForm">
                                <div class="mb-3">
                                    <label for="create_accessory_name" class="form-label fw-bold text-dark">Nombre del Accesorio</label>
                                    <input type="text" class="form-control" id="create_accessory_name" name="name" required style="border-radius: 0.5rem;">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" onclick="createAccessory()">
                        <i class="fas fa-save me-2"></i>Crear Accesorio
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Editar Accesorio -->
    <div class="modal fade" id="editAccessoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Accesorio</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="editAccessoryForm">
                                <input type="hidden" id="edit_accessory_id" name="accessory_id">
                                <div class="mb-3">
                                    <label for="edit_accessory_name" class="form-label fw-bold text-dark">Nombre del Accesorio</label>
                                    <input type="text" class="form-control" id="edit_accessory_name" name="name" required style="border-radius: 0.5rem;">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" onclick="updateAccessory()">
                        <i class="fas fa-save me-2"></i>Actualizar Accesorio
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar estado -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-danger text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold" id="deleteConfirmModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Confirmar eliminación
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4 text-center">
                            <div class="avatar-lg bg-danger-subtle rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                                <i class="fas fa-trash-alt fa-2x text-danger"></i>
                            </div>
                            <p id="deleteConfirmText" class="fs-5 text-dark mb-3"></p>
                            <div class="alert alert-warning d-flex align-items-center border-0 shadow-sm" role="alert" style="border-radius: 0.5rem;">
                                <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                                <div>
                                    Esta acción no se puede deshacer.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>
                        Eliminar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Template: Modal de Opciones de Reseteo -->
    <template id="resetOptionsModalTemplate">
        <div class="modal fade" id="resetOptionsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-danger text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-radiation-alt me-2"></i>Reseteo de Fábrica</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="resetSystemForm" autocomplete="off">
                                <input type="hidden" name="reset_mode" value="factory_reset">
                                
                                <div class="alert alert-danger mb-4 shadow-sm border-danger">
                                    <div class="d-flex">
                                        <div class="me-3">
                                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                                        </div>
                                        <div>
                                            <h5 class="alert-heading fw-bold">¡ZONA DE PELIGRO!</h5>
                                            <p class="mb-0">Estás a punto de realizar un <strong>Reseteo de Fábrica</strong>. Esta acción eliminará permanentemente:</p>
                                            <ul class="mb-0 mt-2 small">
                                                <li>Todas las Facturas y Cotizaciones</li>
                                                <li>Todas las Órdenes de Trabajo</li>
                                                <li>Todos los Clientes</li>
                                                <li>Movimientos de Inventario y Caja</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Inputs para Factory Reset -->
                                <div id="factory_reset_inputs" class="card border-0 bg-white shadow-sm p-3" style="border-radius: 0.5rem; border: 1px solid #dee2e6;">
                                    <h6 class="card-title text-dark fw-bold mb-3"><i class="fas fa-user-shield me-2"></i>Confirmación de Seguridad</h6>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_phrase" class="form-label fw-bold text-dark">Escribe la frase: <span class="badge bg-danger">RESET-FACTORY</span></label>
                                        <input type="text" class="form-control" id="confirm_phrase" placeholder="RESET-FACTORY" style="border-radius: 0.5rem;">
                                    </div>
                                    <div class="mb-0">
                                        <label for="admin_password_confirm" class="form-label fw-bold text-dark">Contraseña de Administrador</label>
                                        <input type="password" class="form-control" id="admin_password_confirm" placeholder="Tu contraseña actual" autocomplete="current-password" disabled style="border-radius: 0.5rem;">
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" id="btnExecuteReset" onclick="executeReset()">
                        Aplicar Cambios
                    </button>
                </div>
            </div>
            </div>
        </div>
    </template>

<script>
function executeReset() {
    const modeInput = document.querySelector('input[name="reset_mode"]');
    const mode = modeInput ? modeInput.value : 'factory_reset';
    const btn = document.getElementById('btnExecuteReset');
    const formData = new FormData();
    
    formData.append('action', 'reset_business_data');
    formData.append('reset_mode', mode);
    formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
    
    // Validar reseteo de fábrica
    const phrase = document.getElementById('confirm_phrase').value;
    const password = document.getElementById('admin_password_confirm').value;
    
    if (phrase !== 'RESET-FACTORY') {
        showError('La frase de confirmación es incorrecta');
        return;
    }
    
    if (!password) {
        showError('Debes ingresar tu contraseña');
        return;
    }
    
    formData.append('confirm_phrase', phrase);
    formData.append('admin_password', password);
    
    showConfirm('¿Estás seguro de realizar esta acción? Esta operación es irreversible.', function() {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';
        
        fetch('config_operations.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        })
        .then(window.parseJsonResponse)
        .then(data => {
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showError(data.message);
                btn.disabled = false;
                btn.innerHTML = 'Aplicar Cambios';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error al procesar la solicitud');
            btn.disabled = false;
            btn.innerHTML = 'Aplicar Cambios';
        });
    });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var cuModal = document.getElementById('createUserModal');
    if (cuModal) {
        cuModal.addEventListener('shown.bs.modal', function() {
            var p = document.getElementById('create_password');
            if (p) p.disabled = false;
        });
        cuModal.addEventListener('hidden.bs.modal', function() {
            var p = document.getElementById('create_password');
            if (p) { p.value=''; p.disabled = true; }
            var f = document.getElementById('createUserForm');
            if (f) f.reset();
        });
    }
    var cpModal = document.getElementById('changePasswordModal');
    if (cpModal) {
        cpModal.addEventListener('shown.bs.modal', function() {
            var o = document.getElementById('old_password');
            var n = document.getElementById('new_password');
            var c = document.getElementById('confirm_password');
            if (o) o.disabled = false;
            if (n) n.disabled = false;
            if (c) c.disabled = false;
        });
        cpModal.addEventListener('hidden.bs.modal', function() {
            var o = document.getElementById('old_password');
            var n = document.getElementById('new_password');
            var c = document.getElementById('confirm_password');
            if (o) { o.value=''; o.disabled = true; }
            if (n) { n.value=''; n.disabled = true; }
            if (c) { c.value=''; c.disabled = true; }
            var f = document.getElementById('changePasswordForm');
            if (f) f.reset();
        });
    }
    var rsModal = document.getElementById('resetOptionsModal');
    if (rsModal) {
        rsModal.addEventListener('shown.bs.modal', function() {
            var a = document.getElementById('admin_password_confirm');
            if (a) a.disabled = false;
        });
        rsModal.addEventListener('hidden.bs.modal', function() {
            var a = document.getElementById('admin_password_confirm');
            if (a) { a.value=''; a.disabled = true; }
            var f = document.getElementById('resetSystemForm');
            if (f) f.reset();
        });
    }
});
</script>

<?php
$page_content = ob_get_clean();
$additional_js = ['../assets/js/settings-tabs.js?v=' . time()];
include __DIR__ . '/../includes/page_template.php';
?>
