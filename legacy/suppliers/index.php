<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';

// Verificar autenticación
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

// Obtener configuración de moneda
$currency_config = CompanySettings::getCurrency();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SUPPLIERS', 'suppliers', null);

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$city_filter = $_GET['city'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if ($hasTenantSuppliers && !$perDatabase) {
    $where_conditions[] = 's.tenant_id = ?';
    $params[] = $tenantValue;
}

if (!empty($search)) {
    $where_conditions[] = "(s.company_name LIKE ? OR s.contact_name LIKE ? OR s.tax_id LIKE ? OR s.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($status_filter)) {
    $where_conditions[] = "s.is_active = ?";
    $params[] = ($status_filter === 'active') ? 1 : 0;
}

if (!empty($city_filter)) {
    $where_conditions[] = "s.city = ?";
    $params[] = $city_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Obtener proveedores
$suppliers = [];
$total_suppliers = 0;

try {
    // Contar total de proveedores
    $count_sql = "
        SELECT COUNT(*) as total
        FROM suppliers s
        $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_suppliers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener proveedores
    $sql = "
        SELECT s.*, 
               u.name as created_by_name,
               0 as purchase_orders_count,
               0 as total_purchases,
               0 as pending_amount
        FROM suppliers s
        LEFT JOIN users u ON s.created_by = u.id" . (($hasTenantUsers && $hasTenantSuppliers && !$perDatabase) ? " AND u.tenant_id = s.tenant_id" : "") . "
        $where_clause
        ORDER BY COALESCE(s.company_name, s.contact_name) ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);


}
catch (PDOException $e) {
    error_log("Error al obtener proveedores: " . $e->getMessage());
}

// Obtener ciudades únicas para filtro
$cities = [];
try {
    $sql = "SELECT DISTINCT city FROM suppliers WHERE city IS NOT NULL AND city != ''";
    $params = [];
    if ($hasTenantSuppliers && !$perDatabase) {
        $sql .= " AND tenant_id = ?";
        $params[] = $tenantValue;
    }
    $sql .= " ORDER BY city";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
catch (PDOException $e) {
    error_log("Error al obtener ciudades: " . $e->getMessage());
}

// Calcular estadísticas
$stats = [
    'total_suppliers' => 0,
    'active_suppliers' => 0,
    'total_purchases' => 0,
    'pending_payments' => 0
];

try {
    $sql = "
        SELECT 
            COUNT(*) as total_suppliers,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_suppliers,
            0 as total_purchases,
            0 as pending_payments
        FROM suppliers
        WHERE 1=1" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    error_log("Error al calcular estadísticas: " . $e->getMessage());
}

$total_pages = ceil($total_suppliers / $per_page);

// Configuración del template
$page_title = 'Gestión de Proveedores';


// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>
<div class="suppliers-page">

<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-truck me-2 text-primary no-theme"></i>Proveedores</h2>
        <p class="text-muted mb-0">Gestiona proveedores de insumos y repuestos</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="new.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-plus me-2"></i>Nuevo Proveedor
        </a>
        <a href="purchase_order.php" class="btn btn-outline-info rounded-pill px-3">
            <i class="fas fa-shopping-cart me-2"></i>Nueva Compra
        </a>
        <a href="payment.php" class="btn btn-outline-success rounded-pill px-3">
            <i class="fas fa-money-bill-wave me-2"></i>Registrar Pago
        </a>
        <a href="reports.php" class="btn btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-chart-bar me-2"></i>Reportes
        </a>
    </div>
</div>

<!-- Estadísticas -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-truck fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_suppliers']); ?></h5>
                    <small class="text-muted">Total Proveedores</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-success bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-check-circle fa-2x text-success no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['active_suppliers']); ?></h5>
                    <small class="text-muted">Activos</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-shopping-bag fa-2x text-info no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $currency_config['symbol']; ?> <?php echo number_format($stats['total_purchases'], 0, ',', '.'); ?></h5>
                    <small class="text-muted">Total Compras</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-file-invoice-dollar fa-2x text-warning no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0">$<?php echo number_format($stats['pending_payments'], 0, ',', '.'); ?></h5>
                    <small class="text-muted">Pagos Pendientes</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card card-modern mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Nombre, documento o email">
                </div>
            </div>
            <div class="col-md-3">
                <select class="form-select rounded-pill bg-light border-0" id="status" name="status">
                    <option value="">Estado: Todos</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select rounded-pill bg-light border-0" id="city" name="city">
                    <option value="">Ciudad: Todas</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city); ?>" 
                                <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city); ?>
                        </option>
                    <?php
endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary rounded-pill w-100 shadow-sm">
                    <i class="fas fa-search me-1"></i>Buscar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Main Card -->
<div class="card shadow-sm rounded-4 border-0 overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($suppliers)): ?>
            <div class="text-center py-5">
                <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                    <i class="fas fa-truck fa-3x text-muted"></i>
                </div>
                <h5 class="text-muted mb-2">No se encontraron proveedores</h5>
                <p class="text-muted mb-3">No hay proveedores que coincidan con los filtros aplicados.</p>
                <a href="new.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-plus me-2"></i>Crear Primer Proveedor
                </a>
            </div>
        <?php
else: ?>
            <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="ps-4">Proveedor</th>
                            <th>Info. Fiscal</th>
                            <th>Contacto</th>
                            <th>Ubicación</th>
                            <th>Finanzas</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="fas fa-truck text-primary no-theme"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($supplier['company_name'] ?: $supplier['contact_name']); ?>
                                            </div>
                                            <?php if ($supplier['supplier_code']): ?>
                                                <small class="text-muted font-monospace"><?php echo htmlspecialchars($supplier['supplier_code']); ?></small>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="badge bg-light text-dark border"><?php echo strtoupper($supplier['supplier_type']); ?></div>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($supplier['tax_id']); ?></div>
                                </td>
                                <td>
                                    <?php if ($supplier['phone']): ?>
                                        <div class="small mb-1"><i class="fas fa-phone text-muted me-2" style="width: 15px;"></i><?php echo htmlspecialchars($supplier['phone']); ?></div>
                                    <?php
        endif; ?>
                                    <?php if ($supplier['mobile']): ?>
                                        <div class="small mb-1"><i class="fas fa-mobile-alt text-muted me-2" style="width: 15px;"></i><?php echo htmlspecialchars($supplier['mobile']); ?></div>
                                    <?php
        endif; ?>
                                    <?php if ($supplier['email']): ?>
                                        <div class="small"><i class="fas fa-envelope text-muted me-2" style="width: 15px;"></i><?php echo htmlspecialchars($supplier['email']); ?></div>
                                    <?php
        endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($supplier['city'] ?: '-'); ?></td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="small">
                                            <span class="text-muted">Compras:</span>
                                            <span class="fw-bold"><?php echo $supplier['purchase_orders_count']; ?></span>
                                        </div>
                                        <div class="small">
                                            <span class="text-muted">Pendiente:</span>
                                            <?php if ($supplier['pending_amount'] > 0): ?>
                                                <span class="text-warning fw-bold">
                                                    <?php echo $currency_config['symbol']; ?> <?php echo number_format($supplier['pending_amount'], 0, ',', '.'); ?>
                                                </span>
                                            <?php
        else: ?>
                                                <span class="text-success"><?php echo $currency_config['symbol']; ?> 0</span>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($supplier['is_active']): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success no-theme">Activo</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                    <?php
        endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="view.php?id=<?php echo $supplier['id']; ?>" 
                                           class="btn btn-sm btn-light text-primary shadow-sm" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $supplier['id']; ?>" 
                                           class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="purchase_order.php?supplier_id=<?php echo $supplier['id']; ?>" 
                                           class="btn btn-sm btn-light text-info shadow-sm" title="Nueva Compra">
                                            <i class="fas fa-shopping-cart"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php
    endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vista Móvil (Tarjetas) -->
            <div class="d-block d-lg-none p-3 bg-light">
                <div class="row g-3">
                    <?php foreach ($suppliers as $supplier): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                    <?php if ($supplier['is_active']): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success no-theme">Activo</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                    <?php
        endif; ?>
                                    <div class="badge bg-light text-dark border small"><?php echo strtoupper($supplier['supplier_type']); ?></div>
                                </div>
                                <div class="card-body py-2">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="fas fa-truck fa-lg text-primary no-theme"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1 text-dark">
                                                <?php echo htmlspecialchars($supplier['company_name'] ?: $supplier['contact_name']); ?>
                                            </h6>
                                            <?php if ($supplier['supplier_code']): ?>
                                                <small class="text-muted font-monospace"><?php echo htmlspecialchars($supplier['supplier_code']); ?></small>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                                        <div class="mb-2 d-flex align-items-center">
                                            <i class="fas fa-id-card text-muted me-2" style="width: 15px;"></i>
                                            <span><?php echo htmlspecialchars($supplier['tax_id']); ?></span>
                                        </div>
                                        <?php if ($supplier['phone'] || $supplier['mobile']): ?>
                                            <div class="mb-2 d-flex align-items-center">
                                                <i class="fas fa-phone text-muted me-2" style="width: 15px;"></i>
                                                <a href="tel:<?php echo htmlspecialchars($supplier['mobile'] ?: $supplier['phone']); ?>" class="text-decoration-none text-muted">
                                                    <?php echo htmlspecialchars($supplier['mobile'] ?: $supplier['phone']); ?>
                                                </a>
                                            </div>
                                        <?php
        endif; ?>
                                        <?php if ($supplier['email']): ?>
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-envelope text-muted me-2" style="width: 15px;"></i>
                                                <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none text-muted text-truncate">
                                                    <?php echo htmlspecialchars($supplier['email']); ?>
                                                </a>
                                            </div>
                                        <?php
        endif; ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between p-3 bg-light rounded-3 mt-2 border border-light">
                                        <div class="text-center">
                                            <div class="small text-muted mb-1">Compras</div>
                                            <div class="fw-bold text-dark"><?php echo $supplier['purchase_orders_count']; ?></div>
                                        </div>
                                        <div class="text-center border-start ps-3">
                                            <div class="small text-muted mb-1">Pendiente</div>
                                            <?php if ($supplier['pending_amount'] > 0): ?>
                                                <div class="text-warning fw-bold">
                                                    <?php echo $currency_config['symbol']; ?> <?php echo number_format($supplier['pending_amount'], 0, ',', '.'); ?>
                                                </div>
                                            <?php
        else: ?>
                                                <div class="text-success fw-bold"><?php echo $currency_config['symbol']; ?> 0</div>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top-0 pb-3 pt-2">
                                    <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                        <span><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($supplier['city'] ?: 'Sin ciudad'); ?></span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 pb-1 justify-content-end">
                                        <a href="view.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-light text-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Ver"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                        <a href="purchase_order.php?supplier_id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-light text-info shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Nueva Compra"><i class="fas fa-shopping-cart"></i></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php
    endforeach; ?>
                </div>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top border-light py-3">
                    <nav aria-label="Paginación de proveedores">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="fas fa-chevron-left me-1"></i> Anterior
                                    </a>
                                </li>
                            <?php
        endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 rounded-circle <?php echo $i == $page ? 'bg-primary text-white shadow-sm' : 'text-muted'; ?> mx-1 d-flex align-items-center justify-content-center" 
                                       style="width: 35px; height: 35px;"
                                       href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php
        endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        Siguiente <i class="fas fa-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            <?php
        endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php
    endif; ?>
        <?php
endif; ?>
    </div>
</div>

</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
