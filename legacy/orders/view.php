<?php
require_once '../config/session.php';
requireAuth('../login/index.php');
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
header('Content-Type: text/html; charset=UTF-8');

$pdo = db();
// Obtener tenant_id
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$portal_tenant_id = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$tenant_id;

// Generar token CSRF si no existe
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Obtener ID de la orden
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: index.php?error=' . urlencode('ID de orden no válido'));
    exit();
}

// Obtener datos de la orden con información del cliente y dispositivo
try {
    if ($perDatabase) {
        if (!hasColumnCached($pdo, 'work_orders', 'approval_status')) {
            try { $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none'"); } catch (Throwable $__ ) {}
        }
        $sql = "SELECT wo.*, 
                       c.client_type, c.first_name, c.company_name, 
                       c.phone, c.email, c.address, c.id_number,
                       dt.name as device_type_name
                FROM work_orders wo
                LEFT JOIN clients c ON wo.client_id = c.id
                LEFT JOIN device_types dt ON wo.device_type_id = dt.id
                WHERE wo.id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
    } else {
        $sql = "SELECT wo.*, 
                       c.client_type, c.first_name, c.company_name, 
                       c.phone, c.email, c.address, c.id_number,
                       dt.name as device_type_name
                FROM work_orders wo
                LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id
                LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id
                WHERE wo.id = ? AND wo.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id, $tenant_id]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header('Location: index.php?error=' . urlencode('Orden no encontrada o acceso denegado'));
        exit();
    }
}
catch (PDOException $e) {
    error_log("Error SQL en view.php: " . $e->getMessage());
    header('Location: index.php?error=' . urlencode('Error al cargar la orden: ' . $e->getMessage()));
    exit();
}

// Plantillas WhatsApp y datos de empresa
$wa_templates = [];
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%'");
        $stmt->execute([]);
    } else {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%' AND tenant_id = ?");
        $stmt->execute([$tenant_id]);
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wa_templates[$row['config_key']] = $row['config_value'];
    }
}
catch (Throwable $e) {
}
$company_name = '';
$company_phone = '';
try {
    if ($perDatabase) {
        $cstmt = $pdo->prepare("SELECT company_name, company_phone FROM company_config LIMIT 1");
        $cstmt->execute([]);
    } else {
        $cstmt = $pdo->prepare("SELECT company_name, company_phone FROM company_config WHERE tenant_id = ? LIMIT 1");
        $cstmt->execute([$tenant_id]);
    }
    $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
    if ($crow) {
        $company_name = $crow['company_name'] ?? '';
        $company_phone = $crow['company_phone'] ?? '';
    }
}
catch (Throwable $e) {
}

// Obtener historial de estados
try {
    $hasTenantHistory = hasTenantColumnCached($pdo, 'order_status_history');
    if ($hasTenantHistory && !$perDatabase) {
        $history_sql = "SELECT h.*, u.name AS user_name 
                        FROM order_status_history h
                        LEFT JOIN users u ON u.id = h.changed_by AND u.tenant_id = ?
                        WHERE h.order_id = ? AND h.tenant_id = ?
                        ORDER BY h.created_at DESC";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute([$tenant_id, $order_id, $tenant_id]);
    } else {
        $history_sql = "SELECT h.*, u.name AS user_name 
                        FROM order_status_history h
                        LEFT JOIN users u ON u.id = h.changed_by
                        WHERE h.order_id = ?
                        ORDER BY h.created_at DESC";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute([$order_id]);
    }
    $status_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $status_history = [];
}

// Catálogo de estados por tenant para usar los mismos (emoji/color/nombre) que configuraciones
$status_catalog = getOrderStatusesCatalog($pdo, $tenant_id);

// Número de orden formateado con prefijo
$order_num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
$order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
$order_display = $order_prefix . '-' . str_pad($order_num, 4, '0', STR_PAD_LEFT);
$page_title = $order_display;

// Obtener accesorios del equipo que se chulearon
try {
    $hasTenantOEA = hasTenantColumnCached($pdo, 'order_equipment_accessories');
    $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
    
    $accessories_sql = "SELECT ea.name, ea.description, ea.category, 
                               oea.is_included, oea.condition_notes
                        FROM equipment_accessories ea
                        INNER JOIN order_equipment_accessories oea ON ea.id = oea.accessory_id
                        WHERE oea.order_id = ? AND oea.is_included = 1";
    $params = [$order_id];
    if (!$perDatabase) {
        if ($hasTenantEA) { $accessories_sql .= " AND ea.tenant_id = ?"; $params[] = $tenant_id; }
        if ($hasTenantOEA) { $accessories_sql .= " AND oea.tenant_id = ?"; $params[] = $tenant_id; }
    }
    $accessories_sql .= " ORDER BY ea.sort_order ASC, ea.name ASC";
    $accessories_stmt = $pdo->prepare($accessories_sql);
    $accessories_stmt->execute($params);
    $equipment_accessories = $accessories_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $equipment_accessories = [];
}
$accessories_list = [];
foreach ($equipment_accessories as $acc) {
    if (!empty($acc['name'])) {
        $accessories_list[] = $acc['name'];
    }
}
$accessories_str = implode(', ', $accessories_list);

// Obtener informes técnicos
$reports = [];
try {
    $hasTenantReports = hasTenantColumnCached($pdo, 'technical_reports');
    $hasTenantUsers = hasTenantColumnCached($pdo, 'users');
    $joinUsers = (!$perDatabase && $hasTenantUsers && $hasTenantReports)
        ? "LEFT JOIN users u ON tr.created_by = u.id AND u.tenant_id = tr.tenant_id"
        : "LEFT JOIN users u ON tr.created_by = u.id";
    $stmtReports = $pdo->prepare("
        SELECT tr.id, tr.report_title, tr.created_at, u.name as created_by_name 
        FROM technical_reports tr
        {$joinUsers}
        WHERE tr.order_id = ?" . ((!$perDatabase && $hasTenantReports) ? " AND tr.tenant_id = ?" : "") . "
        ORDER BY tr.created_at DESC
    ");
    $stmtReports->execute((!$perDatabase && $hasTenantReports) ? [(int)$order['id'], $tenantValue] : [(int)$order['id']]);
    $reports = $stmtReports->fetchAll(PDO::FETCH_ASSOC);
}
catch (Exception $e) {
// Silenciar error si la tabla no existe aún
}

// Decodificar fotos de la orden
$order_photos = [];
if (!empty($order['device_photo'])) {
    $decoded = json_decode($order['device_photo'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $order_photos = $decoded;
    }
    else {
        // Fallback para formato antiguo o string simple
        $order_photos = [$order['device_photo']];
    }
}

// Historial de Pagos
$order_payments = [];
try {
    // Buscar pagos que contengan "Orden #ID" en la descripción
    $hasTenantIncome = hasTenantColumnCached($pdo, 'cash_income');
    $stmtPayments = $pdo->prepare("SELECT * FROM cash_income WHERE description LIKE ?" . ((!$perDatabase && $hasTenantIncome) ? " AND tenant_id = ?" : "") . " ORDER BY created_at DESC");
    $stmtPayments->execute((!$perDatabase && $hasTenantIncome) ? ['%Orden #' . $order['id'] . '%', $tenantValue] : ['%Orden #' . $order['id'] . '%']);
    $order_payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
// Silenciar error
}

$page_title = htmlspecialchars($order_display);
$additional_css = [];
$additional_js = [];
ob_start();
?>
    <style>
        .photo-container {
            transition: transform 0.2s ease-in-out;
        }
        
        .photo-container:hover {
            transform: scale(1.05);
        }
        
        .photo-container img {
            border: 2px solid #e9ecef;
            transition: border-color 0.2s ease-in-out;
        }
        
        .photo-container:hover img {
            border-color: #007bff;
        }
        
        #modalPhoto {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .modal-body {
            background-color: #f8f9fa;
        }
        .status-timeline {
            position: relative;
            margin-left: 22px;
        }
        .status-timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        .status-step {
            position: relative;
            margin-bottom: 16px;
        }
        .status-dot {
            position: absolute;
            left: -2px;
            top: 14px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--dot-color, #6c757d);
            border: 2px solid var(--dot-color, #6c757d);
            box-shadow: 0 0 0 4px #fff;
        }
        .status-card {
            background: #f8fbff;
            border-radius: 12px;
            padding: 12px;
            border-left: 4px solid var(--accent-color, #6c757d);
        }
        .status-title {
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .status-meta {
            font-size: .8rem;
            color: #6c757d;
        }
    </style>
    <!-- Hidden CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="">
            <!-- Encabezado de página -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="fas fa-eye me-2 text-primary no-theme"></i><?php echo htmlspecialchars($order_display); ?></h2>
                    <p class="text-muted mb-0">Detalles completos de la orden de servicio</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php
$linked_invoice = null;
try {
    $has_order_id_col = false;
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $hasTenantInvoiceItems = hasTenantColumnCached($pdo, 'invoice_items');
    $colStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'order_id'");
    $colStmt->execute();
    $has_order_id_col = (intval($colStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0);
    if ($has_order_id_col) {
        $stmtInv = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE order_id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "") . " AND status != 'cancelled' ORDER BY created_at DESC LIMIT 1");
        $stmtInv->execute((!$perDatabase && $hasTenantInvoices) ? [$order_id, $tenantValue] : [$order_id]);
        $linked_invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
    }
    else {
        $joinIi = (!$perDatabase && $hasTenantInvoices && $hasTenantInvoiceItems)
            ? "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id"
            : "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id";
        $stmtInv = $pdo->prepare("SELECT i.id, i.invoice_number FROM invoices i {$joinIi} WHERE i.status != 'cancelled'" . ((!$perDatabase && $hasTenantInvoices) ? " AND i.tenant_id = ?" : "") . " AND (i.notes LIKE ? OR ii.description LIKE ?) ORDER BY i.created_at DESC LIMIT 1");
        $like = '%Orden #' . $order_id . '%';
        $paramsInv = [];
        if (!$perDatabase && $hasTenantInvoices) { $paramsInv[] = $tenantValue; }
        $paramsInv[] = $like;
        $paramsInv[] = $like;
        $stmtInv->execute($paramsInv);
        $linked_invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
    }
}
catch (Throwable $e) {
}

if ($linked_invoice): ?>
                        <a href="../billing/view.php?id=<?php echo (int)$linked_invoice['id']; ?>" class="btn btn-outline-success rounded-pill shadow-sm">
                            <i class="fas fa-file-invoice me-2"></i>Factura <?php echo htmlspecialchars($linked_invoice['invoice_number']); ?>
                        </a>
                    <?php
endif; ?>
                    
                    <?php
require_once '../includes/print_system.php';
// Nota: generatePrintButtons podría necesitar adaptación para estilos, pero por ahora lo dejamos como está
// o lo envolvemos en un contenedor si devuelve botones planos.
// Asumiremos que devuelve HTML de botones.
echo generatePrintButtons('work_order', $order['id'], 'Documento', 'fa-print');
echo generatePrintButtons('work_order_label', $order['id'], 'Etiqueta', 'fa-tag');
?>
                    
                    <a href="order_reports.php?id=<?php echo $order['id']; ?>" class="btn btn-primary no-theme rounded-pill shadow-sm text-white">
                        <i class="fas fa-file-alt me-2"></i>Informes
                    </a>
                    <a href="edit.php?id=<?php echo $order['id']; ?>" class="btn btn-warning rounded-pill shadow-sm text-white">
                        <i class="fas fa-edit me-2"></i>Editar
                    </a>
                    <a href="manage_parts.php?id=<?php echo $order['id']; ?>" class="btn btn-info no-theme rounded-pill shadow-sm text-white">
                        <i class="fas fa-cogs me-2"></i>Partes
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-success rounded-pill shadow-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fab fa-whatsapp me-2"></i>WhatsApp
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="sendWhatsApp('reception')">Recepción</a></li>
                            <li><a class="dropdown-item" href="#" onclick="sendWhatsApp('ready')">Equipo Listo</a></li>
                            <li><a class="dropdown-item" href="#" onclick="sendWhatsApp('delivery')">Entrega</a></li>
                            <?php if (!empty($linked_invoice) && !empty($linked_invoice['id'])): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-success" href="#" onclick="sendWhatsApp('sale')">Comprobante de Venta</a></li>
                            <?php
endif; ?>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-danger rounded-pill shadow-sm" onclick="deleteOrder(<?php echo $order['id']; ?>)">
                        <i class="fas fa-trash me-2"></i>Eliminar
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill shadow-sm">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </a>
                </div>
            </div>

            <!-- Mostrar mensajes -->
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-4" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($order['approval_status']) && $order['approval_status'] !== 'none'): ?>
            <div class="card mb-3 border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom border-light py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-handshake me-2 text-primary no-theme"></i>Decisión del Cliente</h5>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h6 class="text-muted text-uppercase small fw-bold">Estado de Presupuesto</h6>
                            <?php
                                $apStatus = strtolower($order['approval_status']);
                                if ($apStatus === 'approved' || $apStatus === 'aprobado') {
                                    echo '<span class="badge bg-success fs-6 rounded-pill px-3 py-2"><i class="fas fa-check-circle me-1"></i>Aprobada</span>';
                                } elseif ($apStatus === 'rejected' || $apStatus === 'rechazado') {
                                    echo '<span class="badge bg-danger fs-6 rounded-pill px-3 py-2"><i class="fas fa-times-circle me-1"></i>Rechazada</span>';
                                } else {
                                    echo '<span class="badge bg-warning text-dark fs-6 rounded-pill px-3 py-2"><i class="fas fa-clock me-1"></i>Pendiente</span>';
                                }
                            ?>
                            
                            <?php if (!empty($order['approved_at'])): ?>
                                <div class="mt-2 small text-muted">
                                    <i class="fas fa-calendar-alt me-1"></i> Fecha: <?php echo htmlspecialchars($order['approved_at']); ?>
                                </div>
                            <?php endif; ?>

                            <?php 
                                // Determinar si mostrar el monto acordado. Usamos approved_quote_amount si existe.
                                $hasApprovedAmountCol = array_key_exists('approved_quote_amount', $order);
                                if ($hasApprovedAmountCol && !empty($order['approved_quote_amount'])): ?>
                                <div class="mt-2 text-success fw-bold">
                                    <i class="fas fa-dollar-sign me-1"></i> Monto acordado: $<?php echo number_format($order['approved_quote_amount'], 2); ?>
                                </div>
                            <?php elseif ($apStatus === 'approved' || $apStatus === 'aprobado'): ?>
                                <div class="mt-2 text-success fw-bold">
                                    <i class="fas fa-dollar-sign me-1"></i> Monto acordado: $<?php echo number_format($order['estimated_cost'] ?? 0, 2); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['approval_comment'])): ?>
                                <div class="mt-3">
                                    <div class="small text-muted fw-semibold mb-1">Comentario del cliente:</div>
                                    <div class="bg-light p-2 rounded border-start border-3 border-primary text-secondary fst-italic">
                                        <?php echo nl2br(htmlspecialchars($order['approval_comment'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($order['approval_signature_path'])): ?>
                        <div class="col-md-6 text-center text-md-end">
                            <h6 class="text-muted text-uppercase small fw-bold mb-2">Firma Digital</h6>
                            <div class="d-inline-block p-1 border rounded bg-white shadow-sm">
                                <img src="../<?php echo htmlspecialchars($order['approval_signature_path']); ?>" alt="Firma del cliente" class="img-fluid" style="max-height: 120px; object-fit: contain;">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>


            <div class="row">
                <!-- Información Principal -->
                <div class="col-lg-8">
                    <!-- Estado y Prioridad -->
                            <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Estado Actual</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Estado</h6>
                                    <button type="button" class="btn btn-link p-0 align-middle text-decoration-none" onclick="openChangeStatusModal(<?php echo $order['id']; ?>)">
                                        <?php
$st = getEffectiveStatusSlug(($order['status'] ?? ''), ($order['approval_status'] ?? ''));
$em = getStatusEmoji($st);
?>
                                        <?php
$colorHex = '#6c757d';
try {
    $tenant_id = getCurrentTenantId();
    $hasTenant = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        $hasTenant = ($c && $c->rowCount() > 0);
    }
    catch (Throwable $e) {
    }
    if ($hasTenant) {
        $stc = $pdo->prepare("SELECT color FROM order_statuses WHERE slug = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
        $stc->execute([$st, $tenant_id]);
    }
    else {
        $stc = $pdo->prepare("SELECT color FROM order_statuses WHERE slug = ? AND is_active = 1 LIMIT 1");
        $stc->execute([$st]);
    }
    $hex = trim((string)($stc->fetchColumn() ?: ''));
    if ($hex !== '') {
        $colorHex = $hex;
    }
    if (strcasecmp($colorHex, '#fff') === 0 || strcasecmp($colorHex, '#ffffff') === 0) {
        $colorHex = '#6c757d';
    }
}
catch (Throwable $e) {
}
try {
    $tenant_id = getCurrentTenantId();
    $hasTenant = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        $hasTenant = ($c && $c->rowCount() > 0);
    }
    catch (Throwable $e) {
    }
    if ($hasTenant) {
        $ste = $pdo->prepare("SELECT emoji FROM order_statuses WHERE slug = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
        $ste->execute([$st, $tenant_id]);
    }
    else {
        $ste = $pdo->prepare("SELECT emoji FROM order_statuses WHERE slug = ? AND is_active = 1 LIMIT 1");
        $ste->execute([$st]);
    }
    $rawEmoji = trim((string)($ste->fetchColumn() ?: ''));
    if ($rawEmoji !== '' && !preg_match('/^\?+$/', $rawEmoji)) {
        $em = $rawEmoji;
    }
}
catch (Throwable $e) {
}
?>
                                        <span id="currentStatusBadge" class="badge fs-6 rounded-pill px-3 py-2 cursor-pointer" style="background-color: <?php echo htmlspecialchars($colorHex); ?>; color: #fff;">
                                            <?php echo $em . ' ' . getStatusText($st); ?>
                                        </span>
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Prioridad</h6>
                                    <span class="badge bg-<?php echo getPriorityColor($order['priority']); ?> fs-6 rounded-pill px-3 py-2">
                                        <?php echo getPriorityText($order['priority']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Fecha de Creación</h6>
                                    <p class="mb-0 fw-medium"><?php echo formatCompanyDate($order['created_at'], true); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Última Actualización</h6>
                                    <p class="mb-0 fw-medium"><?php echo formatCompanyDate($order['updated_at'], true); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Información del Dispositivo -->
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold">
                                <i class="fas fa-mobile-alt me-2 text-primary no-theme"></i>
                                Información del Dispositivo
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Tipo de Dispositivo</h6>
                                    <p class="fw-medium"><?php echo $order['device_type_name'] ? htmlspecialchars($order['device_type_name']) : 'No especificado'; ?></p>
                                    
                                    <h6 class="text-muted text-uppercase small fw-bold mt-3">Marca</h6>
                                    <p class="fw-medium"><?php echo $order['device_brand'] ? htmlspecialchars($order['device_brand']) : 'No especificada'; ?></p>
                                    
                                    <h6 class="text-muted text-uppercase small fw-bold mt-3">Contraseña/PIN</h6>
                                    <p class="fw-medium font-monospace bg-light d-inline-block px-2 rounded"><?php echo $order['device_password'] ? htmlspecialchars($order['device_password']) : 'No especificada'; ?></p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Modelo</h6>
                                    <p class="fw-medium"><?php echo $order['device_model'] ? htmlspecialchars($order['device_model']) : 'No especificado'; ?></p>
                                    
                                    <h6 class="text-muted text-uppercase small fw-bold mt-3">Número de Serie / IMEI</h6>
                                    <p class="fw-medium font-monospace text-break"><?php echo $order['serial_number'] ? htmlspecialchars($order['serial_number']) : 'No especificado'; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detalles del Problema -->
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-exclamation-triangle me-2 text-primary no-theme"></i>Detalles del Problema</h5>
                        </div>
                        <div class="card-body">
                            <h6 class="text-muted text-uppercase small fw-bold">Falla Reportada</h6>
                            <div class="bg-light p-3 rounded-3 mb-3 border-start border-4 border-primary">
                                <?php echo nl2br(htmlspecialchars($order['reported_issue'])); ?>
                            </div>

                            <?php if (!empty(trim((string)($order['client_observations'] ?? '')))): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mt-3">Observaciones</h6>
                            <div class="bg-light p-3 rounded-3 mb-3 border-start border-4 border-secondary">
                                <?php echo nl2br(htmlspecialchars((string)($order['client_observations'] ?? ''))); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($order['diagnosis']): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mt-3">Diagnóstico</h6>
                            <div class="bg-light p-3 rounded-3 mb-3 border-start border-4 border-info">
                                <?php echo nl2br(htmlspecialchars($order['diagnosis'])); ?>
                            </div>
                            <?php
endif; ?>
                            
                            <?php if ($order['solution']): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mt-3">Solución</h6>
                            <div class="bg-light p-3 rounded-3 mb-3 border-start border-4 border-success">
                                <?php echo nl2br(htmlspecialchars($order['solution'])); ?>
                            </div>
                            <?php
endif; ?>
                            
                            <?php if ($order['technician_notes']): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mt-3">Notas del Técnico</h6>
                            <div class="bg-light p-3 rounded-3 mb-3 border-start border-4 border-warning">
                                <?php echo nl2br(htmlspecialchars($order['technician_notes'])); ?>
                            </div>
                            <?php
endif; ?>
                        </div>
                    </div>

                    <!-- Accesorios del Equipo -->
                    <?php if (!empty($equipment_accessories)): ?>
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold">
                                <i class="fas fa-box me-2 text-primary no-theme"></i>
                                Accesorios Recibidos
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($equipment_accessories as $accessory): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100 border-success bg-success bg-opacity-10">
                                        <div class="card-body p-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-check-circle text-success me-2"></i>
                                                <h6 class="mb-0 fw-bold text-success"><?php echo htmlspecialchars($accessory['name']); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
    endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php
else: ?>
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold">
                                <i class="fas fa-box me-2 text-primary no-theme"></i>
                                Accesorios del Equipo
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-0 fst-italic">No se encontraron accesorios registrados para esta orden.</p>
                        </div>
                    </div>
                    <?php
endif; ?>

                    <!-- Información de Costos -->
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-dollar-sign me-2 text-primary no-theme"></i>Información de Costos</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Costo Estimado</h6>
                                    <p class="fs-4 fw-bold text-info">
                                        <?php echo $order['estimated_cost'] ? formatCurrency($order['estimated_cost']) : 'No especificado'; ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Costo Final</h6>
                                    <p class="fs-4 fw-bold text-success">
                                        <?php echo $order['final_cost'] ? formatCurrency($order['final_cost']) : 'Pendiente'; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="row mt-3 pt-3 border-top">
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Abono / Anticipo</h6>
                                    <p class="fs-5 fw-bold text-primary no-theme">
                                        <?php echo formatCurrency($order['advance_payment']); ?>
                                        <?php if (!empty($order['payment_method'])): ?>
                                            <span class="badge bg-light text-dark border ms-2 fs-6 fw-normal">
                                                <i class="fas fa-wallet me-1"></i><?php echo htmlspecialchars($order['payment_method']); ?>
                                            </span>
                                        <?php
endif; ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted text-uppercase small fw-bold">Saldo Pendiente <?php echo $order['final_cost'] ? '' : '(Estimado)'; ?></h6>
                                    <p class="fs-5 fw-bold text-warning">
                                        <?php
$cost = $order['final_cost'] ?? $order['estimated_cost'] ?? 0;
$total_registered = array_sum(array_column($order_payments, 'amount'));
$balance = max(0, $cost - ($order['advance_payment'] + $total_registered));
echo formatCurrency($balance);
?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($order['estimated_completion']): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6 class="text-muted text-uppercase small fw-bold">Fecha Estimada de Finalización</h6>
                                    <p class="fw-medium"><?php echo formatCompanyDate($order['estimated_completion']); ?></p>
                                </div>
                            </div>
                            <?php
endif; ?>
                            <?php if (isset($order['delivery_payment']) && $order['delivery_payment'] !== null && $order['delivery_payment'] !== ''): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6 class="text-muted text-uppercase small fw-bold">Abono</h6>
                                    <p class="fw-medium">
                                        <?php
    $total_registered = array_sum(array_column($order_payments, 'amount'));
    echo formatCurrency($total_registered);
?>
                                    </p>
                                </div>
                            </div>
                            <?php
endif; ?>

                            <!-- Historial de Abonos -->
                            <div class="mt-4 pt-3 border-top">
                                <h6 class="text-muted text-uppercase small fw-bold mb-3"><i class="fas fa-history me-2"></i>Historial de Pagos</h6>
                                <?php if (empty($order_payments)): ?>
                                    <p class="text-muted small fst-italic">No hay pagos registrados en caja para esta orden.</p>
                                <?php
else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light text-secondary text-uppercase small">
                                                <tr>
                                                    <th class="border-0">Fecha</th>
                                                    <th class="border-0">Método</th>
                                                    <th class="border-0 text-end">Monto</th>
                                                </tr>
                                            </thead>
                                            <tbody class="border-top-0">
                                                <?php foreach ($order_payments as $payment): ?>
                                                    <tr>
                                                        <td class="text-muted small">
                                                            <i class="far fa-calendar-alt me-1"></i>
                                                            <?php echo date('d/m/Y H:i', strtotime($payment['created_at'])); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-light text-dark border fw-normal">
                                                                <?php
        $method_icon = 'fas fa-money-bill';
        if (stripos($payment['payment_method'], 'tarjeta') !== false)
            $method_icon = 'fas fa-credit-card';
        elseif (stripos($payment['payment_method'], 'transfer') !== false || stripos($payment['payment_method'], 'banc') !== false)
            $method_icon = 'fas fa-university';
        elseif (stripos($payment['payment_method'], 'nequi') !== false || stripos($payment['payment_method'], 'davi') !== false)
            $method_icon = 'fas fa-mobile-alt';
?>
                                                                <i class="<?php echo $method_icon; ?> me-1"></i>
                                                                <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                            </span>
                                                            <?php if (!empty($payment['reference_number'])): ?>
                                                                <div class="small text-muted mt-1 ms-1">
                                                                    <i class="fas fa-hashtag me-1" style="font-size: 0.8em;"></i><?php echo htmlspecialchars($payment['reference_number']); ?>
                                                                </div>
                                                            <?php
        endif; ?>
                                                        </td>
                                                        <td class="fw-bold text-end text-success"><?php echo formatCurrency($payment['amount']); ?></td>
                                                    </tr>
                                                <?php
    endforeach; ?>
                                            </tbody>
                                            <tfoot class="border-top fw-bold bg-light">
                                                <tr>
                                                    <td colspan="2" class="text-end text-secondary text-uppercase small py-3">Total Registrado:</td>
                                                    <td class="text-end text-success fs-6 py-3">
                                                        <?php
    $total_registered = array_sum(array_column($order_payments, 'amount'));
    echo formatCurrency($total_registered);
?>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php
endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Fotos del Dispositivo -->
                    <?php
if (!empty($order_photos)):
    $photos_by_category = [
        'entry' => [],
        'diagnosis' => [],
        'delivery' => [],
        'other' => []
    ];

    foreach ($order_photos as $photo) {
        if (strpos($photo, 'entry/') === 0) {
            $photos_by_category['entry'][] = $photo;
        }
        elseif (strpos($photo, 'diagnosis/') === 0) {
            $photos_by_category['diagnosis'][] = $photo;
        }
        elseif (strpos($photo, 'delivery/') === 0) {
            $photos_by_category['delivery'][] = $photo;
        }
        else {
            $photos_by_category['other'][] = $photo;
        }
    }
?>
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold">
                                <i class="fas fa-camera me-2 text-primary no-theme"></i>
                                Fotos del Dispositivo
                            </h5>
                        </div>
                        <div class="card-body">
                            
                            <?php if (!empty($photos_by_category['entry'])): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mb-3"><i class="fas fa-sign-in-alt me-2"></i>Ingreso</h6>
                            <div class="row g-3 mb-4">
                                <?php foreach ($photos_by_category['entry'] as $photo): ?>
                                <?php $photoUrl = resolveOrderPhotoWebUrl((int)$order['id'], $photo, '../uploads/'); ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="photo-container position-relative">
                                        <a href="#" onclick="openPhotoModal(<?php echo json_encode($photoUrl); ?>, <?php echo json_encode($order['device_brand'] . ' ' . $order['device_model']); ?>, <?php echo json_encode(basename(str_replace('\\','/',$photo))); ?>); return false;">
                                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" 
                                                 class="img-fluid rounded shadow-sm w-100" 
                                                 style="height: 150px; object-fit: cover;"
                                                 alt="Foto de Ingreso"
                                                 onerror="this.src='../assets/img/no-image.png'">
                                        </a>
                                    </div>
                                </div>
                                <?php
        endforeach; ?>
                            </div>
                            <?php
    endif; ?>

                            <?php if (!empty($photos_by_category['diagnosis'])): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mb-3 <?php echo !empty($photos_by_category['entry']) ? 'border-top pt-3' : ''; ?>"><i class="fas fa-stethoscope me-2"></i>Diagnóstico</h6>
                            <div class="row g-3 mb-4">
                                <?php foreach ($photos_by_category['diagnosis'] as $photo): ?>
                                <?php $photoUrl = resolveOrderPhotoWebUrl((int)$order['id'], $photo, '../uploads/'); ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="photo-container position-relative">
                                        <a href="#" onclick="openPhotoModal(<?php echo json_encode($photoUrl); ?>, <?php echo json_encode($order['device_brand'] . ' ' . $order['device_model']); ?>, <?php echo json_encode(basename(str_replace('\\','/',$photo))); ?>); return false;">
                                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" 
                                                 class="img-fluid rounded shadow-sm w-100" 
                                                 style="height: 150px; object-fit: cover;"
                                                 alt="Foto de Diagnóstico"
                                                 onerror="this.src='../assets/img/no-image.png'">
                                        </a>
                                    </div>
                                </div>
                                <?php
        endforeach; ?>
                            </div>
                            <?php
    endif; ?>

                            <?php if (!empty($photos_by_category['delivery'])): ?>
                            <h6 class="text-muted text-uppercase small fw-bold mb-3 <?php echo(!empty($photos_by_category['entry']) || !empty($photos_by_category['diagnosis'])) ? 'border-top pt-3' : ''; ?>"><i class="fas fa-check-circle me-2"></i>Entrega</h6>
                            <div class="row g-3 mb-4">
                                <?php foreach ($photos_by_category['delivery'] as $photo): ?>
                                <?php $photoUrl = resolveOrderPhotoWebUrl((int)$order['id'], $photo, '../uploads/'); ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="photo-container position-relative">
                                        <a href="#" onclick="openPhotoModal(<?php echo json_encode($photoUrl); ?>, <?php echo json_encode($order['device_brand'] . ' ' . $order['device_model']); ?>, <?php echo json_encode(basename(str_replace('\\','/',$photo))); ?>); return false;">
                                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" 
                                                 class="img-fluid rounded shadow-sm w-100" 
                                                 style="height: 150px; object-fit: cover;"
                                                 alt="Foto de Entrega"
                                                 onerror="this.src='../assets/img/no-image.png'">
                                        </a>
                                    </div>
                                </div>
                                <?php
        endforeach; ?>
                            </div>
                            <?php
    endif; ?>

                        </div>
                    </div>
                    <?php
endif; ?>
                <!-- Tab content end removed -->
        </div> <!-- End .col-lg-8 -->

                <!-- Información del Cliente -->
                <div class="col-lg-4">
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-user me-2 text-primary no-theme"></i>Información del Cliente</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-4">
                                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                                    <i class="fas fa-user fa-2x text-primary no-theme"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0">
                                        <?php
if ($order['client_type'] === 'company') {
    echo htmlspecialchars($order['company_name']);
}
else {
    echo htmlspecialchars($order['first_name']);
}
?>
                                    </h5>
                                    <span class="badge bg-light text-dark border"><?php echo $order['client_type'] === 'company' ? 'Empresa' : 'Persona Natural'; ?></span>
                                </div>
                            </div>
                            
                            <?php if ($order['id_number']): ?>
                            <div class="mb-3">
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Identificación</h6>
                                <p class="fw-medium"><i class="fas fa-id-card me-2 text-muted"></i><?php echo htmlspecialchars($order['id_number']); ?></p>
                            </div>
                            <?php
endif; ?>
                            
                            <?php if ($order['phone']): ?>
                            <div class="mb-3">
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Teléfono</h6>
                                <p><a href="tel:<?php echo htmlspecialchars($order['phone']); ?>" class="text-decoration-none fw-medium">
                                    <i class="fas fa-phone me-2 text-muted"></i><?php echo getCompanyFullPhone($order['phone']); ?>
                                </a></p>
                            </div>
                            <?php
endif; ?>
                            
                            <?php if ($order['email'] && filter_var($order['email'], FILTER_VALIDATE_EMAIL)): ?>
                            <div class="mb-3">
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Email</h6>
                                <p><a href="mailto:<?php echo htmlspecialchars($order['email']); ?>" class="text-decoration-none fw-medium">
                                    <i class="fas fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($order['email']); ?>
                                </a></p>
                            </div>
                            <?php
endif; ?>
                            
                            <?php if ($order['address']): ?>
                            <div class="mb-0">
                                <h6 class="text-muted text-uppercase small fw-bold mb-1">Dirección</h6>
                                <p class="fw-medium"><i class="fas fa-map-marker-alt me-2 text-muted"></i><?php echo nl2br(htmlspecialchars($order['address'])); ?></p>
                            </div>
                            <?php
endif; ?>
                        </div>
                    </div>

                    <!-- Historial de Estados -->
                    <div class="card mb-4 border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom border-light py-3">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary no-theme"></i>Historial de Estados</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($status_history)): ?>
                            <?php
                                $enriched = [];
                                for ($i = 0; $i < count($status_history); $i++) {
                                    $cur = $status_history[$i];
                                    $fromSlug = null;
                                    if ($i + 1 < count($status_history)) {
                                        $fromSlug = normalizeStatusSlug($status_history[$i + 1]['status']);
                                    }
                                    $cur['from_status'] = $fromSlug;
                                    $cur['to_status'] = normalizeStatusSlug($cur['status']);
                                    $enriched[] = $cur;
                                }
                                $groups = [];
                                foreach ($enriched as $h) {
                                    $k = date('Y-m-d', strtotime($h['created_at']));
                                    if (!isset($groups[$k])) $groups[$k] = [];
                                    $groups[$k][] = $h;
                                }
                            ?>
                            <div class="status-timeline">
                                <?php
                                    $showLimit = 3;
                                    $visible = array_slice($enriched, 0, $showLimit);
                                    $older = array_slice($enriched, $showLimit);
                                ?>
                                <?php foreach ($visible as $history): ?>
                                <?php
                                    $to = $history['to_status'];
                                    $conf = $status_catalog[$to] ?? null;
                                    $emoji = ($conf && trim($conf['emoji']) !== '') ? $conf['emoji'] : getStatusEmoji($to);
                                    $hex = ($conf && trim($conf['color']) !== '') ? trim($conf['color']) : '#6c757d';
                                    if (strcasecmp($hex, '#fff') === 0 || strcasecmp($hex, '#ffffff') === 0) { $hex = '#6c757d'; }
                                    $statusText = ($conf && trim($conf['name']) !== '') ? $conf['name'] : getStatusText($to);
                                    $who = trim((string)($history['user_name'] ?? ''));
                                    if ($who === '') $who = 'Usuario';
                                    $from = $history['from_status'];
                                ?>
                                <div class="status-step">
                                    <div class="status-dot" style="--dot-color: <?php echo htmlspecialchars($hex); ?>;"></div>
                                    <div class="status-card" style="--accent-color: <?php echo htmlspecialchars($hex); ?>;">
                                        <div class="d-flex align-items-center">
                                            <div class="status-title">
                                                <span><?php echo htmlspecialchars($emoji); ?></span>
                                                <span><?php echo htmlspecialchars($statusText); ?></span>
                                            </div>
                                            <div class="ms-auto status-meta">
                                                <span><?php echo htmlspecialchars(formatRelativeTime($history['created_at'])); ?></span>
                                                <span class="mx-1">·</span>
                                                <span><?php echo formatCompanyDate($history['created_at'], true); ?></span>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center mt-1 status-meta">
                                            <span>por <?php echo htmlspecialchars($who); ?></span>
                                        </div>
                                        <?php if ($from): ?>
                                        <div class="mt-1 status-meta">
                                            <span><?php echo htmlspecialchars("Cambio de " . getStatusText($from) . " a " . $statusText); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($history['notes'])): ?>
                                        <div class="mt-1">
                                            <span class="small text-muted fst-italic"><?php echo htmlspecialchars($history['notes']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if (!empty($older)): ?>
                                <a class="text-decoration-none small fw-bold" data-bs-toggle="collapse" href="#olderHistory" role="button">Ver estados anteriores</a>
                                <div class="collapse mt-2" id="olderHistory">
                                    <?php foreach ($older as $history): ?>
                                    <?php
                                        $to = $history['to_status'];
                                        $conf = $status_catalog[$to] ?? null;
                                        $emoji = ($conf && trim($conf['emoji']) !== '') ? $conf['emoji'] : getStatusEmoji($to);
                                        $hex = ($conf && trim($conf['color']) !== '') ? trim($conf['color']) : '#6c757d';
                                        if (strcasecmp($hex, '#fff') === 0 || strcasecmp($hex, '#ffffff') === 0) { $hex = '#6c757d'; }
                                        $statusText = ($conf && trim($conf['name']) !== '') ? $conf['name'] : getStatusText($to);
                                        $who = trim((string)($history['user_name'] ?? ''));
                                        if ($who === '') $who = 'Usuario';
                                        $from = $history['from_status'];
                                    ?>
                                    <div class="status-step">
                                        <div class="status-dot" style="--dot-color: <?php echo htmlspecialchars($hex); ?>;"></div>
                                        <div class="status-card" style="--accent-color: <?php echo htmlspecialchars($hex); ?>;">
                                            <div class="d-flex align-items-center">
                                                <div class="status-title">
                                                    <span><?php echo htmlspecialchars($emoji); ?></span>
                                                    <span><?php echo htmlspecialchars($statusText); ?></span>
                                                </div>
                                                <div class="ms-auto status-meta">
                                                    <span><?php echo htmlspecialchars(formatRelativeTime($history['created_at'])); ?></span>
                                                    <span class="mx-1">·</span>
                                                    <span><?php echo formatCompanyDate($history['created_at'], true); ?></span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center mt-1 status-meta">
                                                <span>por <?php echo htmlspecialchars($who); ?></span>
                                            </div>
                                            <?php if ($from): ?>
                                            <div class="mt-1 status-meta">
                                                <span><?php echo htmlspecialchars("Cambio de " . getStatusText($from) . " a " . $statusText); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($history['notes'])): ?>
                                            <div class="mt-1">
                                                <span class="small text-muted fst-italic"><?php echo htmlspecialchars($history['notes']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php
else: ?>
                            <p class="text-muted mb-0 fst-italic">No hay historial de estados disponible.</p>
                            <?php
endif; ?>
                        </div>
                    </div>
                </div>
            </div>

 
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Modal selección de fotos para PDF ELIMINADO -->
 
    <!-- Modal para ver fotos en tamaño completo -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content rounded-4 shadow">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold" id="photoModalLabel">Foto del Dispositivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center bg-light p-4">
                    <img id="modalPhoto" src="" class="img-fluid rounded shadow-sm" alt="Foto del dispositivo" style="max-height: 70vh;">
                </div>
                <div class="modal-footer border-top-0 justify-content-center">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                    <a id="downloadPhoto" href="" class="btn btn-primary rounded-pill px-4" download>
                        <i class="fas fa-download me-2"></i>Descargar
                    </a>
                </div>
            </div>
        </div>
    </div>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/utils.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/orders.js"></script>
    
    <script>
        function deleteReport(reportId) {
            if (typeof showConfirm === 'function') {
                showConfirm('¿Estás seguro de que deseas eliminar este informe técnico?', function(){
                    fetch('delete_report.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            id: reportId,
                            order_id: <?php echo $order['id']; ?>,
                            csrf_token: document.getElementById('csrf_token').value
                        })
                    })
                    .then(window.parseJsonResponse)
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            if (typeof showError === 'function') showError('Error al eliminar el informe: ' + (data.message || 'Error desconocido'));
                        }
                    })
                    .catch(error => {
                        if (typeof showError === 'function') showError('Error al procesar la solicitud');
                    });
                });
            }
        }
    
        function openPhotoModal(photoUrl, deviceName, downloadName) {
            const modal = document.getElementById('photoModal');
            const modalPhoto = document.getElementById('modalPhoto');
            const modalTitle = document.getElementById('photoModalLabel');
            const downloadLink = document.getElementById('downloadPhoto');
            
            // Configurar la imagen
            modalPhoto.src = photoUrl;
            modalPhoto.alt = 'Foto del dispositivo ' + deviceName;
            
            // Configurar el título
            modalTitle.textContent = 'Foto del Dispositivo - ' + deviceName;
            
            // Configurar el enlace de descarga
            downloadLink.href = photoUrl;
            downloadLink.download = downloadName || 'foto.jpg';
            
            // Mostrar el modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    </script>
    <!-- Script para modal de selección eliminado -->
</body>
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4" id="changeStatusModalContent"></div>
    </div>
    </div>
<script>
function openChangeStatusModal(orderId) {
    const el = document.getElementById('changeStatusModalContent');
    el.innerHTML = '<div class="p-4 text-center"><div class="spinner-border text-primary no-theme" role="status"><span class="visually-hidden">Cargando...</span></div></div>';
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
<script>
<?php
// Preparar datos de factura vinculada para WhatsApp Venta
$inv_number = '';
$inv_total = '';
$inv_paid = '';
$inv_details = '';
if (!empty($linked_invoice) && !empty($linked_invoice['id'])) {
    try {
        $stmtInvData = $pdo->prepare("SELECT invoice_number, total_amount, paid_amount FROM invoices WHERE id = ? AND tenant_id = ?");
        $stmtInvData->execute([(int)$linked_invoice['id'], (int)$tenant_id]);
        $invRow = $stmtInvData->fetch(PDO::FETCH_ASSOC);
        if ($invRow) {
            $inv_number = $invRow['invoice_number'] ?? '';
            $inv_total = isset($invRow['total_amount']) ? (string)$invRow['total_amount'] : '';
            $inv_paid = isset($invRow['paid_amount']) ? (string)$invRow['paid_amount'] : '';
        }
        // Construir resumen de detalles desde items
        $hasTenantCol = hasTenantColumnCached($pdo, 'invoice_items');
        if ($hasTenantCol) {
            $stmtItems = $pdo->prepare("SELECT description, quantity FROM invoice_items WHERE invoice_id = ? AND tenant_id = ? ORDER BY id ASC");
            $stmtItems->execute([(int)$linked_invoice['id'], (int)$tenant_id]);
        } else {
            $stmtItems = $pdo->prepare("SELECT description, quantity FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
            $stmtItems->execute([(int)$linked_invoice['id']]);
        }
        $parts = [];
        while ($it = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
            $desc = trim($it['description'] ?? '');
            $qty = (int)($it['quantity'] ?? 1);
            if ($desc !== '') {
                $parts[] = $desc . ($qty > 1 ? ' x' . $qty : '');
            }
        }
        if (!empty($parts)) {
            $inv_details = implode(', ', array_slice($parts, 0, 8));
        }
    }
    catch (Throwable $e) {
    }
}
?>
var whatsappTemplates = <?php echo json_encode($wa_templates, JSON_UNESCAPED_UNICODE); ?>;
var orderData = {
    id: <?php echo (int)$order['id']; ?>,
    order_number: <?php echo (int)($order['order_number'] ?? 0); ?>,
    client: <?php echo json_encode($order['client_type'] === 'company' ? ($order['company_name'] ?? '') : ($order['first_name'] ?? '')); ?>,
    phone: <?php echo json_encode(preg_replace('/\D+/', '', $order['phone'] ?? '')); ?>,
    equipment: <?php echo json_encode(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''))); ?>,
    brand: <?php echo json_encode($order['device_brand'] ?? ''); ?>,
    model: <?php echo json_encode($order['device_model'] ?? ''); ?>,
    serial: <?php echo json_encode($order['serial_number'] ?? ''); ?>,
    type: <?php echo json_encode($order['device_type_name'] ?? ''); ?>,
    accessories: <?php echo json_encode($accessories_str ?: 'Sin accesorios'); ?>,
    issue: <?php echo json_encode($order['reported_issue'] ?? ''); ?>,
    diagnosis: <?php echo json_encode($order['diagnosis'] ?? ''); ?>,
    solution: <?php echo json_encode($order['solution'] ?? ''); ?>,
    statusText: <?php echo json_encode(getStatusText($order['status'] ?? '')); ?>,
    cost: <?php echo json_encode(($order['final_cost'] ?? $order['estimated_cost'] ?? '0')); ?>,
    abono: <?php echo json_encode($order['advance_payment'] ?? '0'); ?>,
    fechaEntrega: <?php echo json_encode(isset($order['estimated_completion']) && $order['estimated_completion'] ? formatCompanyDate($order['estimated_completion']) : ''); ?>,
    companyName: <?php echo json_encode($company_name); ?>,
    companyPhone: <?php echo json_encode(preg_replace('/\D+/', '', $company_phone)); ?>,
    invoiceNumber: <?php echo json_encode($inv_number); ?>,
    invoiceTotal: <?php echo json_encode($inv_total); ?>,
    invoicePaid: <?php echo json_encode($inv_paid); ?>,
    invoiceDetails: <?php echo json_encode($inv_details); ?>
};
var currencySymbol = <?php echo json_encode(CompanySettings::getCurrency()['symbol']); ?>;
var portalBaseUrl = <?php echo json_encode(getSystemBaseUrl()); ?>;
var tenantSlug = <?php echo json_encode(getTenantPreferredSlug($portal_tenant_id) ?? strval($portal_tenant_id)); ?>;
var orderPrefix = <?php echo json_encode(function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_id) : 'ORD'); ?>;
function buildMessage(type){
    var t = whatsappTemplates['whatsapp_template_'+type] || '';
    if (!t) {
        if (type === 'reception') t = "Hola {{cliente}}, hemos recibido su equipo {{equipo}}. Orden #{{orden}}.";
        else if (type === 'ready') t = "Hola {{cliente}}, su equipo {{equipo}} está listo. Total: {{total}}.";
        else if (type === 'delivery') t = "Hola {{cliente}}, gracias por confiar en nosotros. Entrega realizada.";
        else if (type === 'sale') t = "📝 Comprobante de Venta\nCliente: {{cliente}}\nFactura: {{factura}}\nDetalles: {{detalles}}\nTotal: {{total}}\nSaldo: {{saldo}}\n{{taller_nombre}}";
    }
    var orderNo = orderPrefix + '-' + String(orderData.order_number || orderData.id).padStart(4, '0');
    var urlSeg = portalBaseUrl + 'portal/verify.php?t=' + encodeURIComponent(tenantSlug) + '&order_no=' + encodeURIComponent(orderNo);
    
    var formatMoney = function(val){ 
        var num = parseFloat(String(val).replace(/[^0-9.]/g, '')) || 0; 
        return currencySymbol + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); 
    };
    
    var cost = parseFloat(orderData.cost) || 0;
    var abono = parseFloat(orderData.abono) || 0;
    var saldo = cost - abono;
    
    var totalFmt = formatMoney(cost);
    var abonoFmt = formatMoney(abono);
    var saldoFmt = formatMoney(saldo);
    
    var msg = t
        .replace(/{{cliente}}/g, orderData.client)
        .replace(/{{cliente_tel}}/g, orderData.phone)
        .replace(/{{tipo}}/g, orderData.type)
        .replace(/{{equipo}}/g, orderData.equipment)
        .replace(/{{marca}}/g, orderData.brand)
        .replace(/{{modelo}}/g, orderData.model)
        .replace(/{{sn}}/g, orderData.serial)
        .replace(/{{orden}}/g, orderNo)
        .replace(/{{falla}}/g, orderData.issue)
        .replace(/{{diagnostico}}/g, orderData.diagnosis)
        .replace(/{{solucion}}/g, orderData.solution)
        .replace(/{{estado}}/g, orderData.statusText)
        .replace(/{{valor}}/g, totalFmt)
        .replace(/{{total}}/g, totalFmt)
        .replace(/{{abono}}/g, abonoFmt)
        .replace(/{{saldo}}/g, saldoFmt)
        .replace(/{{fecha_entrega}}/g, orderData.fechaEntrega)
        .replace(/{{url_seguimiento}}/g, urlSeg)
        .replace(/{{accesorios}}/g, orderData.accessories)
        .replace(/{{taller_nombre}}/g, orderData.companyName || 'Servicio Técnico')
        .replace(/{{taller_tel}}/g, orderData.companyPhone || 'N/A');
    if (type === 'sale') {
        var totalInv = orderData.invoiceTotal || '';
        var paidInv = orderData.invoicePaid || '';
        var saldoInv = '';
        if (totalInv !== '' && paidInv !== '') {
            var tNum = parseFloat(totalInv) || 0;
            var pNum = parseFloat(paidInv) || 0;
            saldoInv = String((tNum - pNum));
        }
        var totalInvFmt = formatMoney(totalInv);
        var paidInvFmt = formatMoney(paidInv);
        var saldoInvFmt = formatMoney(saldoInv);
        msg = msg
            .replace(/{{factura}}/g, orderData.invoiceNumber || '')
            .replace(/{{detalles}}/g, (orderData.invoiceDetails || '').trim())
            .replace(/{{abono}}/g, paidInvFmt)
            .replace(/{{total}}/g, totalInvFmt)
            .replace(/{{saldo}}/g, saldoInvFmt);
    }
    return msg;
}
function sendWhatsApp(type){
    var phone = orderData.phone;
    if (!phone || phone.length < 10) { return; }
    var text = buildMessage(type);
    // Insertar tipo de equipo si la plantilla no lo incluye
    text = ensureTypePresent(text, orderData.type);
    // Normalizar emojis si el contenido llegó corrupto
    text = normalizeEmoji(text);
    var base = 'https://api.whatsapp.com/send';
    var params = new URLSearchParams();
    params.set('phone', String(phone).replace(/[^0-9]/g, ''));
    params.set('text', text);
    var url = base + '?' + params.toString();
    window.open(url, '_blank');
}

function normalizeEmoji(text) {
    if (window.normalizeEmoji && window.normalizeEmoji !== normalizeEmoji) {
        return window.normalizeEmoji(text);
    }
    return text;
}
function ensureTypePresent(text, typeVal) {
    if (!typeVal) return text;
    if (text.indexOf('Tipo:') !== -1) return text;
    var lines = text.split('\n');
    if (lines.length > 1) {
        lines.splice(1, 0, '📱 Tipo: ' + typeVal);
    } else {
        lines.push('📱 Tipo: ' + typeVal);
    }
    return lines.join('\n');
}
</script>
<?php
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
