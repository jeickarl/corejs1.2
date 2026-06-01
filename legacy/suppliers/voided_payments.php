<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
SecurityEnhancements::setSecurityHeaders();

// Verificar autenticación
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSupplierPayments = hasTenantColumnCached($pdo, 'supplier_payments');
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantPurchaseOrders = hasTenantColumnCached($pdo, 'purchase_orders');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

// Verificar permisos
if (!hasRole(['admin', 'inventory'])) {
    header('Location: ../dashboard.php');
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Obtener mensajes de la URL
if (isset($_GET['type']) && isset($_GET['msg'])) {
    $tipo_mensaje = $_GET['type'];
    $mensaje = $_GET['msg'];
}

// Parámetros de filtro
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$search = $_GET['search'] ?? '';
$export = $_GET['export'] ?? '';

// Construir consulta base
$where_conditions = ["sp.status = 'voided'"];
$params = [];
if ($hasTenantSupplierPayments && !$perDatabase) {
    $where_conditions[] = "sp.tenant_id = ?";
    $params[] = $tenantValue;
}

if (!empty($date_from)) {
    $where_conditions[] = "sp.payment_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "sp.payment_date <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if (!empty($supplier_id)) {
    $where_conditions[] = "sp.supplier_id = ?";
    $params[] = $supplier_id;
}

if (!empty($search)) {
    $where_conditions[] = "(s.company_name LIKE ? OR sp.reference_number LIKE ? OR po.po_number LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

// Consulta principal
$sql = "
    SELECT 
        sp.id,
        sp.payment_date,
        sp.payment_amount,
        sp.payment_method,
        sp.reference_number,
        sp.notes,
        s.company_name as supplier_name,
        po.po_number,
        po.total_amount as order_total,
        u.name as created_by_name,
        sp.created_at,
        ce.notes as void_reason
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantSupplierPayments && !$perDatabase) ? " AND s.tenant_id = sp.tenant_id" : "") . "
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.id" . (($hasTenantPurchaseOrders && $hasTenantSupplierPayments && !$perDatabase) ? " AND po.tenant_id = sp.tenant_id" : "") . "
    LEFT JOIN users u ON sp.created_by = u.id" . (($hasTenantUsers && $hasTenantSupplierPayments && !$perDatabase) ? " AND u.tenant_id = sp.tenant_id" : "") . "
    LEFT JOIN cash_expenses ce ON (
        ce.amount = sp.payment_amount 
        AND ce.concept LIKE 'Pago a proveedor%'
        AND ce.notes LIKE '%ANULADO:%'
        AND ABS(TIMESTAMPDIFF(MINUTE, ce.created_at, sp.created_at)) <= 5
    )
    WHERE $where_clause
    ORDER BY sp.payment_date DESC, sp.created_at DESC
";

// Exportar a CSV si se solicita
if ($export === 'csv') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagos_anulados_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Encabezados
    fputcsv($output, [
        'ID', 'Fecha Pago', 'Proveedor', 'Monto', 'Método', 'Referencia', 
        'Orden Compra', 'Total Orden', 'Creado Por', 'Fecha Creación', 'Motivo Anulación'
    ]);
    
    // Datos
    foreach ($payments as $payment) {
        // Extraer motivo de anulación
        $void_reason = '';
        if (!empty($payment['void_reason'])) {
            if (preg_match('/ANULADO:\s*(.+?)(\s*\||$)/', $payment['void_reason'], $matches)) {
                $void_reason = trim($matches[1]);
            }
        }
        
        fputcsv($output, [
            $payment['id'],
            $payment['payment_date'],
            $payment['supplier_name'],
            number_format($payment['payment_amount'], 2),
            $payment['payment_method'],
            $payment['reference_number'],
            $payment['po_number'],
            number_format($payment['order_total'], 2),
            $payment['created_by_name'],
            $payment['created_at'],
            $void_reason
        ]);
    }
    
    fclose($output);
    exit();
}

// Paginación
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Contar total
$count_sql = "
    SELECT COUNT(*) as total
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantSupplierPayments && !$perDatabase) ? " AND s.tenant_id = sp.tenant_id" : "") . "
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.id" . (($hasTenantPurchaseOrders && $hasTenantSupplierPayments && !$perDatabase) ? " AND po.tenant_id = sp.tenant_id" : "") . "
    WHERE $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Obtener registros paginados
$paginated_sql = $sql . " LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($paginated_sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de proveedores para el filtro
$sqlSup = "SELECT id, company_name FROM suppliers" . (($hasTenantSuppliers && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY company_name";
$suppliers_stmt = $pdo->prepare($sqlSup);
$suppliers_stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totales
$totals_sql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(sp.payment_amount) as total_amount
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantSupplierPayments && !$perDatabase) ? " AND s.tenant_id = sp.tenant_id" : "") . "
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.id" . (($hasTenantPurchaseOrders && $hasTenantSupplierPayments && !$perDatabase) ? " AND po.tenant_id = sp.tenant_id" : "") . "
    WHERE $where_clause
";
$stmt = $pdo->prepare($totals_sql);
$stmt->execute($params);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php
$page_title = 'Pagos Anulados';
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>

<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
            <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 gap-3 flex-wrap">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-ban me-2 text-primary no-theme"></i>Pagos Anulados</h4>
                    <div class="text-muted small">Historial de pagos anulados a proveedores</div>
                </div>
                <a href="payment.php" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="fas fa-arrow-left me-2"></i>Volver a Pagos
                </a>
            </div>

                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje === 'danger' ? 'danger' : 'success'; ?> border-0 shadow-sm rounded-3 mb-4 alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-<?php echo $tipo_mensaje === 'danger' ? 'exclamation-circle' : 'check-circle'; ?> fs-4 me-3"></i>
                            <div>
                                <h6 class="alert-heading fw-bold mb-1">
                                    <?php echo $tipo_mensaje === 'danger' ? '¡Error!' : '¡Éxito!'; ?>
                                </h6>
                                <p class="mb-0"><?php echo htmlspecialchars($mensaje); ?></p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3 rounded-top-4">
                        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-filter me-2"></i>Filtros de Búsqueda</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="date_from" class="form-label fw-bold">Fecha Desde</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-calendar text-muted"></i>
                                    </span>
                                    <input type="date" class="form-control border-start-0 ps-0" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="date_to" class="form-label fw-bold">Fecha Hasta</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-calendar text-muted"></i>
                                    </span>
                                    <input type="date" class="form-control border-start-0 ps-0" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="supplier_id" class="form-label fw-bold">Proveedor</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-building text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="supplier_id" name="supplier_id">
                                        <option value="">Todos los proveedores</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_id == $supplier['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="search" class="form-label fw-bold">Buscar</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-search text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="search" name="search" 
                                           placeholder="Proveedor, referencia, orden..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            <div class="col-12 text-end mt-4">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 me-2">
                                    <i class="fas fa-search me-2"></i>Filtrar Resultados
                                </button>
                                <a href="voided_payments.php" class="btn btn-outline-secondary rounded-pill px-4 me-2">
                                    <i class="fas fa-times me-2"></i>Limpiar
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success rounded-pill px-4">
                                    <i class="fas fa-file-csv me-2"></i>Exportar CSV
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Resumen -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm rounded-4 bg-danger text-white h-100 overflow-hidden">
                            <div class="card-body p-4 position-relative">
                                <div class="d-flex justify-content-between align-items-center position-relative z-1">
                                    <div>
                                        <h6 class="card-title text-white-50 mb-2">Total Pagos Anulados</h6>
                                        <h2 class="mb-0 fw-bold"><?php echo number_format($totals['total_count']); ?></h2>
                                    </div>
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                        <i class="fas fa-ban fa-2x"></i>
                                    </div>
                                </div>
                                <i class="fas fa-ban position-absolute bottom-0 end-0 opacity-10" style="font-size: 8rem; transform: translate(20%, 20%);"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm rounded-4 bg-warning text-dark h-100 overflow-hidden">
                            <div class="card-body p-4 position-relative">
                                <div class="d-flex justify-content-between align-items-center position-relative z-1">
                                    <div>
                                        <h6 class="card-title text-dark-50 mb-2">Monto Total Anulado</h6>
                                        <h2 class="mb-0 fw-bold">$<?php echo number_format($totals['total_amount'], 2); ?></h2>
                                    </div>
                                    <div class="bg-dark bg-opacity-10 rounded-circle p-3">
                                        <i class="fas fa-dollar-sign fa-2x text-dark"></i>
                                    </div>
                                </div>
                                <i class="fas fa-dollar-sign position-absolute bottom-0 end-0 opacity-10" style="font-size: 8rem; transform: translate(20%, 20%);"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de pagos anulados -->
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-0 py-3 rounded-top-4">
                        <h5 class="mb-0 text-primary fw-bold">Listado de Pagos Anulados</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="fas fa-folder-open fa-3x text-muted opacity-50"></i>
                                </div>
                                <h5 class="text-muted fw-bold">No se encontraron resultados</h5>
                                <p class="text-muted mb-0">Intenta ajustar los filtros de búsqueda.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4 border-0 rounded-start">ID</th>
                                            <th class="border-0">Fecha</th>
                                            <th class="border-0">Proveedor</th>
                                            <th class="text-end border-0">Monto</th>
                                            <th class="border-0">Método</th>
                                            <th class="border-0">Referencia</th>
                                            <th class="border-0">Orden</th>
                                            <th class="border-0">Motivo Anulación</th>
                                            <th class="border-0 rounded-end">Creado Por</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php foreach ($payments as $payment): ?>
                                            <?php
                                            // Extraer motivo de anulación
                                            $void_reason = 'No especificado';
                                            if (!empty($payment['void_reason'])) {
                                                if (preg_match('/ANULADO:\s*(.+?)(\s*\||$)/', $payment['void_reason'], $matches)) {
                                                    $void_reason = trim($matches[1]);
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td class="ps-4"><span class="badge rounded-pill bg-danger">#<?php echo $payment['id']; ?></span></td>
                                                <td class="text-muted"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                                <td class="fw-bold"><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                                                <td class="text-end">
                                                    <strong class="text-danger">$<?php echo number_format($payment['payment_amount'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary border border-secondary">
                                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted small"><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                                <td>
                                                    <?php if ($payment['po_number']): ?>
                                                        <a href="view.php?id=<?php echo $payment['id']; ?>" class="badge rounded-pill bg-primary text-decoration-none">
                                                            <?php echo htmlspecialchars($payment['po_number']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center text-danger small">
                                                        <i class="fas fa-exclamation-circle me-1"></i>
                                                        <span class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($void_reason); ?>">
                                                            <?php echo htmlspecialchars($void_reason); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-light rounded-circle p-1 me-2 d-flex justify-content-center align-items-center" style="width: 24px; height: 24px;">
                                                            <i class="fas fa-user fa-xs text-muted"></i>
                                                        </div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($payment['created_by_name']); ?></small>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <div class="p-4 border-top">
                                    <nav aria-label="Paginación">
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link rounded-pill me-1" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                        <i class="fas fa-chevron-left"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link rounded-pill me-1" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link rounded-pill" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                        <i class="fas fa-chevron-right"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
        </div>
    </div>
</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
