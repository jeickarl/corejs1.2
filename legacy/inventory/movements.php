<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';
$pdo = db();

// Verificar autenticación
requireAuth();

$csrf_token = SecurityEnhancements::generateCSRFToken();

// Autocuración: Crear tabla activity_logs si no existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) DEFAULT NULL,
      `action` varchar(255) NOT NULL,
      `table_name` varchar(255) DEFAULT NULL,
      `record_id` int(11) DEFAULT NULL,
      `old_values` text DEFAULT NULL,
      `new_values` text DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `user_agent` varchar(255) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Ignorar error si no se puede crear, logActivity manejará el error de inserción
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_INVENTORY_MOVEMENTS', 'inventory_movements', null);

// Obtener parámetros de filtros
$product_filter = $_GET['product'] ?? '';
$movement_type_filter = $_GET['movement_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryMovements = hasTenantColumnCached($pdo, 'inventory_movements');
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantProductCategories = hasTenantColumnCached($pdo, 'product_categories');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

$where_conditions = [];
$params = [];
if ($hasTenantInventoryMovements && !$perDatabase) {
    $where_conditions[] = 'im.tenant_id = ?';
    $params[] = $tenantValue;
}

if (!empty($product_filter)) {
    $where_conditions[] = "im.product_id = ?";
    $params[] = $product_filter;
}

if (!empty($movement_type_filter)) {
    $where_conditions[] = "im.movement_type = ?";
    $params[] = $movement_type_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(im.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(im.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Obtener movimientos
$movements = [];
$total_movements = 0;

try {
    // Contar total de movimientos
    $count_sql = "
        SELECT COUNT(*) as total
        FROM inventory_movements im
        LEFT JOIN inventory_products ip ON im.product_id = ip.id" . (($hasTenantInventoryProducts && $hasTenantInventoryMovements && !$perDatabase) ? " AND ip.tenant_id = im.tenant_id" : "") . "
        LEFT JOIN product_categories pc ON ip.category_id = pc.id" . (($hasTenantProductCategories && $hasTenantInventoryProducts && !$perDatabase) ? " AND pc.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN brands b ON ip.brand_id = b.id" . (($hasTenantBrands && $hasTenantInventoryProducts && !$perDatabase) ? " AND b.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN users u ON im.created_by = u.id" . (($hasTenantUsers && $hasTenantInventoryMovements && !$perDatabase) ? " AND u.tenant_id = im.tenant_id" : "") . "
        $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_movements = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Obtener movimientos
    $sql = "
        SELECT im.*, 
               ip.name as product_name,
               ip.internal_code as product_code,
               pc.name as category_name,
               b.name as brand_name,
               u.name as created_by_name
        FROM inventory_movements im
        LEFT JOIN inventory_products ip ON im.product_id = ip.id" . (($hasTenantInventoryProducts && $hasTenantInventoryMovements && !$perDatabase) ? " AND ip.tenant_id = im.tenant_id" : "") . "
        LEFT JOIN product_categories pc ON ip.category_id = pc.id" . (($hasTenantProductCategories && $hasTenantInventoryProducts && !$perDatabase) ? " AND pc.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN brands b ON ip.brand_id = b.id" . (($hasTenantBrands && $hasTenantInventoryProducts && !$perDatabase) ? " AND b.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN users u ON im.created_by = u.id" . (($hasTenantUsers && $hasTenantInventoryMovements && !$perDatabase) ? " AND u.tenant_id = im.tenant_id" : "") . "
        $where_clause
        ORDER BY im.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error al obtener movimientos: " . $e->getMessage());
}

// Obtener productos para filtro y modales
$products = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, internal_code, current_stock
        FROM inventory_products 
        WHERE status = 'active' " . (($hasTenantInventoryProducts && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ORDER BY name ASC
    ");
    $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$tenantValue] : []);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
}

// Calcular estadísticas
$stats = [
    'total_movements' => 0,
    'total_entries' => 0,
    'total_exits' => 0,
    'total_adjustments' => 0
];

try {
    $sql = "
        SELECT 
            COUNT(*) as total_movements,
            SUM(CASE WHEN movement_type = 'entry' THEN 1 ELSE 0 END) as total_entries,
            SUM(CASE WHEN movement_type = 'exit' THEN 1 ELSE 0 END) as total_exits,
            SUM(CASE WHEN movement_type = 'adjustment' THEN 1 ELSE 0 END) as total_adjustments
        FROM inventory_movements im
        WHERE 1=1
    ";
    $stats_params = [];
    if ($hasTenantInventoryMovements && !$perDatabase) {
        $sql .= " AND im.tenant_id = ?";
        $stats_params[] = $tenantValue;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($stats_params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al calcular estadísticas: " . $e->getMessage());
}

$total_pages = ceil($total_movements / $per_page);

// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_inventory_styles.php'; ?>

<div class="inventory-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-history me-2 text-primary no-theme"></i>Movimientos de Inventario</h2>
            <p class="text-muted mb-0">Historial completo de movimientos (Kardex)</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
            <button type="button" class="btn btn-outline-success rounded-pill px-4" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Exportar Excel
            </button>
            <?php include __DIR__ . '/_subnav.php'; ?>
        </div>
    </div>

    <!-- Acciones Rápidas (Estilo Configuración) -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 70px; height: 70px;">
                        <i class="fas fa-plus-circle fa-2x text-success no-theme"></i>
                    </div>
                    <div>
                        <h5 class="card-title fw-bold text-dark mb-1">Registrar Entrada</h5>
                        <p class="card-text text-muted mb-3 small">Agregar stock al inventario (Compras, Devoluciones)</p>
                        <button class="btn btn-outline-success rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#entryModal">
                            <i class="fas fa-plus me-2"></i>Nueva Entrada
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle bg-danger bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 70px; height: 70px;">
                        <i class="fas fa-minus-circle fa-2x text-danger no-theme"></i>
                    </div>
                    <div>
                        <h5 class="card-title fw-bold text-dark mb-1">Registrar Salida</h5>
                        <p class="card-text text-muted mb-3 small">Retirar stock del inventario (Consumo, Mermas)</p>
                        <button class="btn btn-outline-danger rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#exitModal">
                            <i class="fas fa-minus me-2"></i>Nueva Salida
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-exchange-alt fa-2x text-primary no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_movements']); ?></h5>
                        <small class="text-muted">Total Movimientos</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-arrow-down fa-2x text-success no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_entries']); ?></h5>
                        <small class="text-muted">Entradas</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-danger bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-arrow-up fa-2x text-danger no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_exits']); ?></h5>
                        <small class="text-muted">Salidas</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-warning bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-sync-alt fa-2x text-warning no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_adjustments']); ?></h5>
                        <small class="text-muted">Ajustes</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros y Tabla -->
    <div class="card border-0 shadow-sm mb-4 rounded-4">
        <div class="card-header bg-white border-0 pt-4 px-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-0 fw-bold">
                    <i class="fas fa-list me-2 text-primary no-theme"></i>Listado de Movimientos
                </h5>
                <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                    <i class="fas fa-filter me-2"></i>Filtros
                </button>
            </div>
            
            <div class="collapse <?php echo (!empty($product_filter) || !empty($movement_type_filter) || !empty($date_from)) ? 'show' : ''; ?>" id="filterCollapse">
                <div class="card card-body bg-light border-0 rounded-3 mb-3">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Producto</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fas fa-box text-muted"></i>
                                </span>
                                <select name="product" class="form-select border-start-0 ps-0">
                                    <option value="">Todos</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $product_filter == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Tipo</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fas fa-exchange-alt text-muted"></i>
                                </span>
                                <select name="movement_type" class="form-select border-start-0 ps-0">
                                    <option value="">Todos</option>
                                    <option value="entry" <?php echo $movement_type_filter == 'entry' ? 'selected' : ''; ?>>Entrada</option>
                                    <option value="exit" <?php echo $movement_type_filter == 'exit' ? 'selected' : ''; ?>>Salida</option>
                                    <option value="adjustment" <?php echo $movement_type_filter == 'adjustment' ? 'selected' : ''; ?>>Ajuste</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Desde</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fas fa-calendar text-muted"></i>
                                </span>
                                <input type="date" name="date_from" class="form-control border-start-0 ps-0" value="<?php echo $date_from; ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Hasta</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fas fa-calendar text-muted"></i>
                                </span>
                                <input type="date" name="date_to" class="form-control border-start-0 ps-0" value="<?php echo $date_to; ?>">
                            </div>
                        </div>
                        <div class="col-12 text-end mt-2">
                            <a href="movements.php" class="btn btn-outline-secondary rounded-pill me-2">
                                <i class="fas fa-times me-2"></i>Limpiar
                            </a>
                            <button type="submit" class="btn btn-primary rounded-pill">
                                <i class="fas fa-filter me-2"></i>Aplicar Filtros
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 border-0 rounded-start">Fecha</th>
                            <th class="border-0">Producto</th>
                            <th class="border-0">Tipo</th>
                            <th class="border-0">Cantidad</th>
                            <th class="border-0">Stock Resultante</th>
                            <th class="border-0">Usuario</th>
                            <th class="border-0 rounded-end">Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No se encontraron movimientos</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movements as $m): ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php echo date('d/m/Y', strtotime($m['created_at'])); ?>
                                        <br>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($m['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($m['product_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($m['product_code']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $badges = [
                                            'entry' => 'bg-success',
                                            'exit' => 'bg-danger',
                                            'adjustment' => 'bg-warning'
                                        ];
                                        $labels = [
                                            'entry' => 'Entrada',
                                            'exit' => 'Salida',
                                            'adjustment' => 'Ajuste'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badges[$m['movement_type']] ?? 'bg-secondary'; ?>">
                                            <?php echo $labels[$m['movement_type']] ?? $m['movement_type']; ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?php 
                                            $subtypes = [
                                                'purchase' => 'Compra',
                                                'sale' => 'Venta',
                                                'return' => 'Devolución',
                                                'adjustment_increase' => 'Ajuste (+)',
                                                'adjustment_decrease' => 'Ajuste (-)',
                                                'initial_stock' => 'Stock Inicial',
                                                'manual_entry' => 'Manual',
                                                'manual_exit' => 'Manual'
                                            ];
                                            echo $subtypes[$m['movement_subtype']] ?? $m['movement_subtype']; 
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="fw-bold <?php echo $m['movement_type'] === 'entry' ? 'text-success' : ($m['movement_type'] === 'exit' ? 'text-danger' : 'text-dark'); ?>">
                                            <?php echo $m['movement_type'] === 'exit' ? '-' : '+'; ?><?php echo number_format($m['quantity']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($m['stock_after']); ?></td>
                                    <td><?php echo htmlspecialchars($m['created_by_name']); ?></td>
                                    <td>
                                        <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($m['reason']); ?>">
                                            <?php echo htmlspecialchars($m['reason']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top-0 py-3">
            <nav aria-label="Navegación de páginas">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Entrada -->
<div class="modal fade" id="entryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Registrar Entrada</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="entryForm" onsubmit="saveMovement(event, 'entry')" class="needs-validation" novalidate>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="movement_type" value="entry">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-box text-muted"></i>
                            </span>
                            <select class="form-select border-start-0 ps-0" name="product_id" required id="entry_product_id">
                                <option value="">Seleccione un producto</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> (Stock: <?php echo $p['current_stock']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subtipo</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <select class="form-select border-start-0 ps-0" name="movement_subtype" required>
                                <option value="purchase">Compra</option>
                                <option value="return">Devolución</option>
                                <option value="manual_entry">Ajuste Manual (+)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cantidad <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="fas fa-hashtag text-muted"></i>
                                </span>
                                <input type="number" class="form-control border-start-0 ps-0" name="quantity" required min="1" step="1">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Costo Unitario</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="fas fa-dollar-sign text-muted"></i>
                                </span>
                                <input type="text" class="form-control border-start-0 ps-0" name="unit_cost" onkeyup="formatCurrencyInput(this)">
                            </div>
                            <small class="text-muted">Opcional</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Motivo / Descripción <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-comment-alt text-muted"></i>
                            </span>
                            <textarea class="form-control border-start-0 ps-0" name="reason" required rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Referencia (Opcional)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-file-alt text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" name="reference_number" placeholder="Ej. Factura #123">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4">Guardar Entrada</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Salida -->
<div class="modal fade" id="exitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                <h5 class="modal-title"><i class="fas fa-minus-circle me-2"></i>Registrar Salida</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="exitForm" onsubmit="saveMovement(event, 'exit')" class="needs-validation" novalidate>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="movement_type" value="exit">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-box text-muted"></i>
                            </span>
                            <select class="form-select border-start-0 ps-0" name="product_id" required id="exit_product_id">
                                <option value="">Seleccione un producto</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> (Stock: <?php echo $p['current_stock']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subtipo</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <select class="form-select border-start-0 ps-0" name="movement_subtype" required>
                                <option value="manual_exit">Ajuste Manual (-)</option>
                                <option value="damage">Merma / Daño</option>
                                <option value="consumption">Consumo Interno</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Cantidad <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-hashtag text-muted"></i>
                            </span>
                            <input type="number" class="form-control border-start-0 ps-0" name="quantity" required min="1" step="1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Motivo / Descripción <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-comment-alt text-muted"></i>
                            </span>
                            <textarea class="form-control border-start-0 ps-0" name="reason" required rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">Guardar Salida</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Resetear formularios al cerrar modal
    const modals = ['entryModal', 'exitModal'];
    modals.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) {
                    form.reset();
                    form.classList.remove('was-validated');
                }
            });
        }
    });
});

function saveMovement(event, type) {
    event.preventDefault();
    const form = event.target;
    
    if (!form.checkValidity()) {
        event.stopPropagation();
        form.classList.add('was-validated');
        return;
    }

    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    const modalId = type === 'entry' ? '#entryModal' : '#exitModal';
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
    
    // Limpiar formatos de moneda
    const costInput = form.querySelector('input[name="unit_cost"]');
    if (costInput) {
        formData.set('unit_cost', costInput.value.replace(/,/g, ''));
    }
    
    fetch('ajax/save_movement.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            alert('Movimiento registrado exitosamente');
            // Cerrar modal
            const modalEl = document.querySelector(modalId);
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
            
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al procesar la solicitud');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

function exportToExcel() {
    // Implementar exportación si es necesario o mantener el link
    alert('Funcionalidad de exportación en desarrollo');
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
