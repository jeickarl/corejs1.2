<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_INVOICE_REPORTS', 'invoices', null);

// Obtener parámetros de filtro
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Primer día del mes actual
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Día actual
$status_filter = $_GET['status'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';
$client_id = $_GET['client_id'] ?? '';
$order_id = isset($_GET['order_id']) ? trim($_GET['order_id']) : '';

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

// Obtener clientes para el filtro
$clients = [];
try {
    $hasTenantClients = hasTenantColumnCached($pdo, 'clients');
    $stmt = $pdo->prepare("
        SELECT id, 
               CASE 
                   WHEN client_type = 'company' THEN company_name
                   ELSE first_name
               END as name
        FROM clients 
        WHERE status = 'active' " . (($hasTenantClients && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ORDER BY name
    ");
    $stmt->execute(($hasTenantClients && !$perDatabase) ? [$tenant_id] : []);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener clientes: " . $e->getMessage());
}

// Construir consulta con filtros
$where_conditions = ["DATE(i.invoice_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($payment_status_filter)) {
    $where_conditions[] = "i.payment_status = ?";
    $params[] = $payment_status_filter;
}

if (!empty($client_id)) {
    $where_conditions[] = "i.client_id = ?";
    $params[] = $client_id;
}

// Filtro por Orden vinculada
$has_order_id_col = hasColumnCached($pdo, 'invoices', 'order_id');

if (!empty($order_id)) {
    if ($has_order_id_col) {
        $where_conditions[] = "i.order_id = ?";
        $params[] = intval($order_id);
    } else {
        if ($perDatabase) {
            $where_conditions[] = "(i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id AND ii.description LIKE ?))";
        } else {
            $where_conditions[] = "(i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id AND ii.description LIKE ?))";
        }
        $like = '%Orden #' . $order_id . '%';
        $params[] = $like;
        $params[] = $like;
    }
}

$hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
if (!$perDatabase && $hasTenantInvoices) {
    $where_conditions[] = "i.tenant_id = ?";
    $params[] = $tenant_id;
}
$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Obtener facturas
$invoices = [];
$summary_stats = [
    'total_invoices' => 0,
    'total_amount' => 0,
    'paid_amount' => 0,
    'pending_amount' => 0,
    'cancelled_amount' => 0
];

try {
    // Obtener facturas
    $stmt = $pdo->prepare("
        SELECT i.*,
               CASE 
                   WHEN c.client_type = 'company' THEN c.company_name
                   ELSE c.first_name
               END as client_name,
               u.name as created_by_name
        FROM invoices i
        " . ($perDatabase ? "JOIN clients c ON i.client_id = c.id" : "JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id") . "
        " . ($perDatabase ? "LEFT JOIN users u ON i.created_by = u.id" : "LEFT JOIN users u ON i.created_by = u.id AND u.tenant_id = i.tenant_id") . "
        $where_clause
        ORDER BY i.invoice_date DESC, i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    foreach ($invoices as $invoice) {
        $summary_stats['total_invoices']++;
        $summary_stats['total_amount'] += $invoice['total_amount'];
        $summary_stats['paid_amount'] += $invoice['paid_amount'];
        $summary_stats['pending_amount'] += $invoice['pending_amount'];
        
        if ($invoice['status'] === 'cancelled') {
            $summary_stats['cancelled_amount'] += $invoice['total_amount'];
        }
    }
    
} catch (PDOException $e) {
    error_log("Error al obtener facturas: " . $e->getMessage());
}

// Exportar CSV/Excel con los filtros actuales
if (!empty($_GET['export'])) {
    $export = strtolower(trim($_GET['export']));
    if (in_array($export, ['csv', 'xlsx'], true)) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    i.invoice_number,
                    i.invoice_date,
                    i.due_date,
                    i.status,
                    i.payment_status,
                    i.subtotal,
                    i.tax_amount,
                    i.total_amount,
                    i.paid_amount,
                    i.pending_amount,
                    CASE 
                        WHEN c.client_type = 'company' THEN c.company_name
                        ELSE c.first_name
                    END as client_name,
                    c.email as client_email,
                    c.phone as client_phone
                FROM invoices i
                " . ($perDatabase ? "JOIN clients c ON i.client_id = c.id" : "JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id") . "
                $where_clause
                ORDER BY i.invoice_date DESC, i.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $headers = [
                'Número','Fecha','Vencimiento','Estado','Pago',
                'Subtotal','Impuesto','Total','Pagado','Pendiente',
                'Cliente','Email','Teléfono'
            ];
            
            if ($export === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="facturas_' . date('Y-m-d_H-i-s') . '.csv"');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                $out = fopen('php://output', 'w');
                // BOM UTF-8
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $headers, ';');
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['invoice_number'],
                        $r['invoice_date'],
                        $r['due_date'],
                        $r['status'],
                        $r['payment_status'],
                        $r['subtotal'],
                        $r['tax_amount'],
                        $r['total_amount'],
                        $r['paid_amount'],
                        $r['pending_amount'],
                        $r['client_name'],
                        $r['client_email'],
                        $r['client_phone'],
                    ], ';');
                }
                fclose($out);
                exit;
            } else {
                // TSV con cabeceras de Excel
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="facturas_' . date('Y-m-d_H-i-s') . '.xlsx"');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                $out = fopen('php://output', 'w');
                // BOM UTF-8
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $headers, "\t");
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['invoice_number'],
                        $r['invoice_date'],
                        $r['due_date'],
                        $r['status'],
                        $r['payment_status'],
                        $r['subtotal'],
                        $r['tax_amount'],
                        $r['total_amount'],
                        $r['paid_amount'],
                        $r['pending_amount'],
                        $r['client_name'],
                        $r['client_email'],
                        $r['client_phone'],
                    ], "\t");
                }
                fclose($out);
                exit;
            }
        } catch (Throwable $e) {
            error_log('Error exportando reportes: ' . $e->getMessage());
            header('Location: reports.php?error=No%20se%20pudo%20exportar%20los%20reportes');
            exit();
        }
    }
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-chart-bar me-2 text-primary no-theme"></i>Reportes de Facturación</h2>
            <p class="text-muted mb-0">Análisis y estadísticas detalladas</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
            <?php 
            $baseParams = [
                'date_from' => $date_from,
                'date_to' => $date_to,
                'status' => $status_filter,
                'payment_status' => $payment_status_filter,
                'client_id' => $client_id,
                'order_id' => $order_id
            ];
            $csvUrl = 'reports.php?' . http_build_query(array_merge($baseParams, ['export' => 'csv']));
            $xlsxUrl = 'reports.php?' . http_build_query(array_merge($baseParams, ['export' => 'xlsx']));
            ?>
            <a href="<?php echo htmlspecialchars($csvUrl); ?>" class="btn btn-outline-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-file-csv me-2"></i>Exportar CSV
            </a>
            <a href="<?php echo htmlspecialchars($xlsxUrl); ?>" class="btn btn-outline-success rounded-pill px-4 shadow-sm">
                <i class="fas fa-file-excel me-2"></i>Exportar Excel
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card card-modern mb-4 border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
            <h5 class="fw-bold text-dark">
                <i class="fas fa-filter me-2 text-primary no-theme"></i>Filtros de Búsqueda
            </h5>
        </div>
        <div class="card-body" style="padding-top: 1rem;">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="date_from" class="form-label fw-medium small text-muted ms-2">Fecha Desde</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-pill px-3"><i class="fas fa-calendar text-muted"></i></span>
                        <input type="date" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label fw-medium small text-muted ms-2">Fecha Hasta</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-pill px-3"><i class="fas fa-calendar text-muted"></i></span>
                        <input type="date" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="date_to" name="date_to" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label fw-medium small text-muted ms-2">Estado</label>
                    <select class="form-select bg-light border-0 rounded-pill px-3 shadow-none" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Borrador</option>
                        <option value="sent" <?php echo $status_filter === 'sent' ? 'selected' : ''; ?>>Completada</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Anulada</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="payment_status" class="form-label fw-medium small text-muted ms-2">Estado de Pago</label>
                    <select class="form-select bg-light border-0 rounded-pill px-3 shadow-none" id="payment_status" name="payment_status">
                        <option value="">Todos</option>
                        <option value="paid" <?php echo $payment_status_filter === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                        <option value="partial" <?php echo $payment_status_filter === 'partial' ? 'selected' : ''; ?>>Parcial</option>
                        <option value="pending" <?php echo $payment_status_filter === 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="client_id" class="form-label fw-medium small text-muted ms-2">Cliente</label>
                    <select class="form-select bg-light border-0 rounded-pill px-3 shadow-none" id="client_id" name="client_id">
                        <option value="">Todos</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>" 
                                    <?php echo $client_id == $client['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="order_id" class="form-label fw-medium small text-muted ms-2">ID de Orden</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-pill px-3"><i class="fas fa-link text-muted"></i></span>
                        <input type="number" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="order_id" name="order_id" 
                               value="<?php echo htmlspecialchars($order_id); ?>" placeholder="Ej: 1234">
                    </div>
                </div>
                <div class="col-md-10 d-flex justify-content-end gap-2">
                    <a href="reports.php" class="btn btn-light border rounded-pill px-4 shadow-sm fw-bold">
                        <i class="fas fa-times me-2"></i>Limpiar
                    </a>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">
                        <i class="fas fa-search me-2"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Estadísticas Resumen -->
    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-file-invoice fa-2x text-primary no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?php echo number_format($summary_stats['total_invoices']); ?></h5>
                        <small class="text-muted">Total Facturas</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                        <i class="fas fa-coins fa-2x text-success"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?php echo formatCurrency($summary_stats['total_amount']); ?></h5>
                        <small class="text-muted">Total Facturado</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-check-circle fa-2x text-info no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?php echo formatCurrency($summary_stats['paid_amount']); ?></h5>
                        <small class="text-muted">Total Pagado</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                        <i class="fas fa-clock fa-2x text-warning"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?php echo formatCurrency($summary_stats['pending_amount']); ?></h5>
                        <small class="text-muted">Total Pendiente</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de Facturas -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
            <h5 class="fw-bold text-dark">
                <i class="fas fa-list me-2 text-primary no-theme"></i>Facturas Encontradas
                <span class="badge bg-primary rounded-pill ms-2"><?php echo count($invoices); ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($invoices)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                        <i class="fas fa-file-invoice fa-3x text-muted opacity-50"></i>
                    </div>
                    <h5 class="text-muted fw-bold">No se encontraron facturas</h5>
                    <p class="text-muted mb-0">Intenta ajustar los filtros de búsqueda para ver resultados.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th class="ps-4 border-0 small fw-bold text-uppercase">Número</th>
                                <th class="border-0 small fw-bold text-uppercase">Cliente</th>
                                <th class="border-0 small fw-bold text-uppercase">Fecha</th>
                                <th class="border-0 small fw-bold text-uppercase">Total</th>
                                <th class="border-0 small fw-bold text-uppercase">Pagado</th>
                                <th class="border-0 small fw-bold text-uppercase">Pendiente</th>
                                <th class="border-0 small fw-bold text-uppercase">Estado</th>
                                <th class="border-0 small fw-bold text-uppercase">Pago</th>
                                <th class="text-end pe-4 border-0 small fw-bold text-uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                                        <div class="small text-muted"><?php echo ucfirst($invoice['document_type']); ?></div>
                                        <?php 
                                        $linked_order_id = null;
                                        if (isset($invoice['order_id']) && $invoice['order_id']) {
                                            $linked_order_id = (int)$invoice['order_id'];
                                        } elseif (!empty($invoice['notes']) && preg_match('/Orden\s*#(\d+)/i', $invoice['notes'], $m)) {
                                            $linked_order_id = (int)$m[1];
                                        }
                                        if ($linked_order_id):
                                            try {
                                                if ($perDatabase) {
                                                    $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? LIMIT 1");
                                                    $ordStmt->execute([$linked_order_id]);
                                                } else {
                                                    $tenant_id = getCurrentTenantId();
                                                    $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                                                    $ordStmt->execute([$linked_order_id, $tenant_id]);
                                                }
                                                $ordNum = (int)($ordStmt->fetchColumn() ?: 0);
                                            } catch (Throwable $__) { $ordNum = 0; }
                                            $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
                                            $displayNum = $ordNum > 0 ? $ordNum : $linked_order_id;
                                            $displayText = htmlspecialchars($prefix) . '-' . str_pad($displayNum, 4, '0', STR_PAD_LEFT);
                                            ?>
                                            <span class="badge bg-light text-dark border mt-1">
                                                <i class="fas fa-link me-1 text-primary opacity-75"></i><?php echo $displayText; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-2 bg-light text-primary d-flex align-items-center justify-content-center rounded-circle" style="width: 32px; height: 32px;">
                                                <i class="fas fa-user small"></i>
                                            </div>
                                            <div class="fw-medium"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-muted small"><i class="far fa-calendar-alt me-1"></i><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></div>
                                    </td>
                                    <td>
                                        <strong class="text-dark"><?php echo formatCurrency($invoice['total_amount']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-success fw-bold">
                                            <?php echo formatCurrency($invoice['paid_amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-danger fw-bold">
                                            <?php echo formatCurrency($invoice['pending_amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($invoice['status']) {
                                            case 'draft':
                                                $status_class = 'bg-secondary';
                                                $status_text = 'Borrador';
                                                break;
                                            case 'sent':
                                                $status_class = 'bg-info';
                                                $status_text = 'Completada';
                                                break;
                                            case 'paid':
                                                $status_class = 'bg-success';
                                                $status_text = 'Pagada';
                                                break;
                                            case 'cancelled':
                                                $status_class = 'bg-danger';
                                                $status_text = 'Anulada';
                                                break;
                                        }
                                        ?>
                                        <span class="badge rounded-pill <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $payment_class = '';
                                        $payment_text = '';
                                        switch ($invoice['payment_status']) {
                                            case 'paid':
                                                $payment_class = 'bg-success';
                                                $payment_text = 'Pagada';
                                                break;
                                            case 'partial':
                                                $payment_class = 'bg-warning text-dark';
                                                $payment_text = 'Parcial';
                                                break;
                                            case 'pending':
                                                $payment_class = 'bg-danger';
                                                $payment_text = 'Pendiente';
                                                break;
                                        }
                                        ?>
                                        <span class="badge rounded-pill <?php echo $payment_class; ?>"><?php echo $payment_text; ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="view.php?id=<?php echo $invoice['id']; ?>" 
                                               class="btn btn-sm btn-light text-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Ver Detalles">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($invoice['status'] !== 'cancelled'): ?>
                                                <a href="payment.php?id=<?php echo $invoice['id']; ?>" 
                                                   class="btn btn-sm btn-light text-success shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Registrar Pagos">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </a>
                                                <?php if (hasRole('admin')): ?>
                                                    <a href="cancel.php?id=<?php echo $invoice['id']; ?>" 
                                                       class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Anular Factura">
                                                        <i class="fas fa-ban"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
