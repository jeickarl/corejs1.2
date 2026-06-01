<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantPurchaseOrders = hasTenantColumnCached($pdo, 'purchase_orders');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SUPPLIER_REPORTS', 'suppliers', null);

// Obtener parámetros de filtros
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Primer día del mes actual
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Día actual
$supplier_filter = $_GET['supplier'] ?? '';

// Obtener proveedores para filtro
$suppliers = [];
try {
    $sql = "SELECT id, name FROM suppliers WHERE is_active = 1" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener proveedores: " . $e->getMessage());
}

// Construir consulta con filtros (normaliza por fecha usando DATE() para contemplar columnas datetime)
$where_conditions = ["DATE(po.order_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
if ($hasTenantPurchaseOrders && !$perDatabase) {
    $where_conditions[] = "po.tenant_id = ?";
    $params[] = $tenantValue;
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "po.supplier_id = ?";
    $params[] = $supplier_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Obtener reporte de compras por proveedor
$purchase_report = [];
try {
    $sql = "
        SELECT 
            s.name as supplier_name,
            COUNT(po.id) as total_orders,
            SUM(po.grand_total) as total_amount,
            SUM(CASE WHEN po.payment_status = 'paid' THEN po.grand_total ELSE 0 END) as paid_amount,
            SUM(CASE WHEN po.payment_status = 'pending' THEN po.grand_total ELSE 0 END) as pending_amount,
            SUM(CASE WHEN po.payment_status IN ('partial','partially_paid') THEN po.grand_total ELSE 0 END) as partial_amount
        FROM purchase_orders po
        INNER JOIN suppliers s ON po.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantPurchaseOrders && !$perDatabase) ? " AND s.tenant_id = po.tenant_id" : "") . "
        $where_clause
        GROUP BY s.id, s.name
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchase_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener reporte de compras: " . $e->getMessage());
}

// Obtener estadísticas generales
$general_stats = [
    'total_orders' => 0,
    'total_amount' => 0,
    'paid_amount' => 0,
    'pending_amount' => 0,
    'partial_amount' => 0
];

try {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            SUM(grand_total) as total_amount,
            SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE 0 END) as paid_amount,
            SUM(CASE WHEN payment_status = 'pending' THEN grand_total ELSE 0 END) as pending_amount,
            SUM(CASE WHEN payment_status IN ('partial','partially_paid') THEN grand_total ELSE 0 END) as partial_amount
        FROM purchase_orders po
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $general_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener estadísticas generales: " . $e->getMessage());
}

// Obtener compras pendientes
$pending_orders = [];
try {
    $sql = "
        SELECT 
            po.*,
            s.name as supplier_name,
            u.name as created_by_name
        FROM purchase_orders po
        INNER JOIN suppliers s ON po.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantPurchaseOrders && !$perDatabase) ? " AND s.tenant_id = po.tenant_id" : "") . "
        LEFT JOIN users u ON po.created_by = u.id" . (($hasTenantUsers && $hasTenantPurchaseOrders && !$perDatabase) ? " AND u.tenant_id = po.tenant_id" : "") . "
        WHERE po.payment_status IN ('pending', 'partially_paid', 'partial')" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND po.tenant_id = ?" : "") . "
        ORDER BY po.order_date ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$tenantValue] : []);
    $pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener órdenes pendientes: " . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>
<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-chart-bar me-2"></i>Reportes de Proveedores</h1>
            <p class="text-muted mb-0">Análisis y estadísticas de compras</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success rounded-pill" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Exportar Excel
            </button>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill">
                <i class="fas fa-arrow-left me-2"></i>Volver a Proveedores
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4">
            <h5 class="card-title mb-0 fw-bold">
                <i class="fas fa-filter me-2 text-primary"></i>Filtros
            </h5>
        </div>
        <div class="card-body px-4 pb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="date_from" class="form-label fw-bold">Fecha Desde</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-calendar text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0 ps-0" id="date_from" name="date_from" 
                               value="<?php echo $date_from; ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label fw-bold">Fecha Hasta</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-calendar text-muted"></i>
                        </span>
                        <input type="date" class="form-control border-start-0 ps-0" id="date_to" name="date_to" 
                               value="<?php echo $date_to; ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="supplier" class="form-label fw-bold">Proveedor</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-truck text-muted"></i>
                        </span>
                        <select class="form-select border-start-0 ps-0" id="supplier" name="supplier">
                            <option value="">Todos los proveedores</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" 
                                        <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary rounded-pill">
                            <i class="fas fa-search me-2"></i>Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Estadísticas Generales -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-primary fw-bold"><?php echo number_format($general_stats['total_orders']); ?></h5>
                    <p class="card-text text-muted small">Total Órdenes</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-info fw-bold">$<?php echo number_format($general_stats['total_amount'], 0, ',', '.'); ?></h5>
                    <p class="card-text text-muted small">Monto Total</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-success fw-bold">$<?php echo number_format($general_stats['paid_amount'], 0, ',', '.'); ?></h5>
                    <p class="card-text text-muted small">Pagado</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-warning fw-bold">$<?php echo number_format($general_stats['partial_amount'], 0, ',', '.'); ?></h5>
                    <p class="card-text text-muted small">Parcial</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-danger fw-bold">$<?php echo number_format($general_stats['pending_amount'], 0, ',', '.'); ?></h5>
                    <p class="card-text text-muted small">Pendiente</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-center">
                <div class="card-body">
                    <h5 class="text-secondary fw-bold"><?php echo $general_stats['total_amount'] > 0 ? round(($general_stats['paid_amount'] / $general_stats['total_amount']) * 100, 1) : 0; ?>%</h5>
                    <p class="card-text text-muted small">% Pagado</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Reporte por Proveedor -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-chart-pie me-2 text-primary"></i>Compras por Proveedor
                    </h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <?php if (empty($purchase_report)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No hay datos para el período seleccionado</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="border-0 rounded-start ps-4">Proveedor</th>
                                        <th class="border-0">Órdenes</th>
                                        <th class="border-0">Total</th>
                                        <th class="border-0">Pagado</th>
                                        <th class="border-0">Parcial</th>
                                        <th class="border-0">Pendiente</th>
                                        <th class="border-0 rounded-end">% Pagado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($purchase_report as $report): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <strong><?php echo htmlspecialchars($report['supplier_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary rounded-pill"><?php echo $report['total_orders']; ?></span>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format($report['total_amount'], 0, ',', '.'); ?></strong>
                                            </td>
                                            <td>
                                                <span class="text-success">
                                                    $<?php echo number_format($report['paid_amount'], 0, ',', '.'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-warning">
                                                    $<?php echo number_format($report['partial_amount'], 0, ',', '.'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-danger">
                                                    $<?php echo number_format($report['pending_amount'], 0, ',', '.'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $percentage = $report['total_amount'] > 0 ? 
                                                    round(($report['paid_amount'] / $report['total_amount']) * 100, 1) : 0;
                                                $percentage_class = $percentage >= 80 ? 'text-success' : 
                                                                  ($percentage >= 50 ? 'text-warning' : 'text-danger');
                                                ?>
                                                <span class="<?php echo $percentage_class; ?>">
                                                    <strong><?php echo $percentage; ?>%</strong>
                                                </span>
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
        
        <!-- Órdenes Pendientes -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Órdenes Pendientes
                    </h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <?php if (empty($pending_orders)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <p class="text-muted">No hay órdenes pendientes</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($pending_orders as $order): ?>
                                <div class="list-group-item border-0 px-0 py-3">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($order['order_number']); ?></h6>
                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($order['order_date'])); ?></small>
                                    </div>
                                    <p class="mb-1 text-muted small"><?php echo htmlspecialchars($order['supplier_name']); ?></p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="text-warning">
                                            $<?php echo number_format($order['grand_total'], 0, ',', '.'); ?>
                                        </strong>
                                        <span class="badge rounded-pill bg-<?php echo $order['payment_status'] === 'pending' ? 'danger' : 'warning'; ?>">
                                            <?php echo ucfirst($order['payment_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <hr class="my-0 text-muted opacity-25">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportToExcel() {
    // Crear tabla HTML para exportar
    const table = document.querySelector('.table');
    if (!table) {
        showWarningAlert('No hay datos para exportar', 'Sin datos');
        return;
    }
    
    // Crear contenido HTML
    let html = `
        <html>
        <head>
            <meta charset="utf-8">
            <title>Reporte de Proveedores</title>
        </head>
        <body>
            <h2>Reporte de Proveedores</h2>
            <p>Período: ${document.getElementById('date_from').value} - ${document.getElementById('date_to').value}</p>
            ${table.outerHTML}
        </body>
        </html>
    `;
    
    // Crear blob y descargar
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'reporte_proveedores_' + new Date().toISOString().split('T')[0] + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
