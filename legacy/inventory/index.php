<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/app_config.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_INVENTORY', 'inventory_products', null);

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$stock_filter = $_GET['stock'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantProductCategories = hasTenantColumnCached($pdo, 'product_categories');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if ($hasTenantInventoryProducts && !$perDatabase) {
    $where_conditions[] = 'ip.tenant_id = ?';
    $params[] = $tenantValue;
}

if (!empty($search)) {
    $where_conditions[] = "(ip.internal_code LIKE ? OR ip.name LIKE ? OR ip.description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($category_filter)) {
    $where_conditions[] = "ip.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($brand_filter)) {
    $where_conditions[] = "ip.brand_id = ?";
    $params[] = $brand_filter;
}

if (!empty($stock_filter)) {
    switch ($stock_filter) {
        case 'low':
            $where_conditions[] = "ip.current_stock <= ip.min_stock";
            break;
        case 'zero':
            $where_conditions[] = "ip.current_stock = 0";
            break;
        case 'available':
            $where_conditions[] = "ip.current_stock > 0";
            break;
    }
}

if (!empty($status_filter)) {
    $where_conditions[] = "ip.status = ?";
    $params[] = $status_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Obtener productos
$products = [];
$total_products = 0;

try {
    // Contar total de productos
    $count_sql = "
        SELECT COUNT(*) as total
        FROM inventory_products ip
        LEFT JOIN product_categories pc ON ip.category_id = pc.id" . (($hasTenantProductCategories && $hasTenantInventoryProducts && !$perDatabase) ? " AND pc.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN brands b ON ip.brand_id = b.id" . (($hasTenantBrands && $hasTenantInventoryProducts && !$perDatabase) ? " AND b.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN suppliers s ON ip.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantInventoryProducts && !$perDatabase) ? " AND s.tenant_id = ip.tenant_id" : "") . "
        $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener productos
    $sql = "
        SELECT ip.*, 
               pc.name as category_name,
               b.name as brand_name,
               s.company_name as supplier_name,
               u.name as created_by_name
        FROM inventory_products ip
        LEFT JOIN product_categories pc ON ip.category_id = pc.id" . (($hasTenantProductCategories && $hasTenantInventoryProducts && !$perDatabase) ? " AND pc.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN brands b ON ip.brand_id = b.id" . (($hasTenantBrands && $hasTenantInventoryProducts && !$perDatabase) ? " AND b.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN suppliers s ON ip.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantInventoryProducts && !$perDatabase) ? " AND s.tenant_id = ip.tenant_id" : "") . "
        LEFT JOIN users u ON ip.created_by = u.id" . (($hasTenantUsers && $hasTenantInventoryProducts && !$perDatabase) ? " AND u.tenant_id = ip.tenant_id" : "") . "
        $where_clause
        ORDER BY ip.name ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);


}
catch (PDOException $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
}

// Obtener datos para filtros
$categories = [];
$brands = [];
$suppliers = [];

try {
    $sql = "SELECT id, name FROM product_categories WHERE is_active = 1" . (($hasTenantProductCategories && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantProductCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, name FROM brands WHERE is_active = 1" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$tenantValue] : []);
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, company_name as name FROM suppliers WHERE status = 'active'" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY company_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    error_log("Error al obtener datos de filtros: " . $e->getMessage());
}

// Calcular estadísticas
$stats = [
    'total_products' => 0,
    'low_stock' => 0,
    'zero_stock' => 0,
    'total_value' => 0
];

try {
    $sql = "
        SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN current_stock <= min_stock THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as zero_stock,
            SUM(current_stock * purchase_price) as total_value
        FROM inventory_products 
        WHERE status = 'active'" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$tenantValue] : []);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    error_log("Error al calcular estadísticas: " . $e->getMessage());
}

$total_pages = ceil($total_products / $per_page);

// Configuración del template
$page_title = 'Gestión de Inventario';


// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_inventory_styles.php'; ?>

<div class="inventory-page">

<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-boxes me-2 text-primary no-theme"></i>Inventario</h2>
        <p class="text-muted mb-0">Gestiona productos y repuestos del taller</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
        <?php if (hasRole(['admin', 'inventory'])): ?>
            <?php $base = rtrim((string)($APP_CONFIG['cookie_path'] ?? '/core'), '/'); ?>
            <a href="<?php echo htmlspecialchars($base . '/inventory/new.php'); ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-plus me-2"></i>Nuevo Producto
            </a>
        <?php endif; ?>
        <?php include __DIR__ . '/_subnav.php'; ?>
    </div>
</div>

<!-- Estadísticas -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-box-open fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_products']); ?></h5>
                    <small class="text-muted">Total Productos</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['low_stock']); ?></h5>
                    <small class="text-muted">Stock Bajo</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-danger bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-times-circle fa-2x text-danger no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['zero_stock']); ?></h5>
                    <small class="text-muted">Sin Stock</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-success bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-dollar-sign fa-2x text-success no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo formatCurrency($stats['total_value']); ?></h5>
                    <small class="text-muted">Valor Total</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card card-modern mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Buscar producto...">
                </div>
            </div>
            <div class="col-md-2">
                <select class="form-select rounded-pill bg-light border-0" id="category" name="category">
                    <option value="">Categoría: Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" 
                                <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                    <?php
endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select rounded-pill bg-light border-0" id="brand" name="brand">
                    <option value="">Marca: Todas</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?php echo $brand['id']; ?>" 
                                <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($brand['name']); ?>
                        </option>
                    <?php
endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select rounded-pill bg-light border-0" id="stock" name="stock">
                    <option value="">Stock: Todos</option>
                    <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Stock Bajo</option>
                    <option value="zero" <?php echo $stock_filter === 'zero' ? 'selected' : ''; ?>>Sin Stock</option>
                    <option value="available" <?php echo $stock_filter === 'available' ? 'selected' : ''; ?>>Disponible</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select rounded-pill bg-light border-0" id="status" name="status">
                    <option value="">Estado: Todos</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary rounded-circle shadow-sm" style="width: 38px; height: 38px; padding: 0;">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Main Card -->
<div class="card shadow-sm rounded-4 border-0 overflow-hidden">
    <div class="card-body p-0">
        <?php if (empty($products)): ?>
            <div class="text-center py-5">
                <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                    <i class="fas fa-boxes fa-3x text-muted"></i>
                </div>
                <h5 class="text-muted mb-2">No se encontraron productos</h5>
                <p class="text-muted mb-3">No hay productos que coincidan con los filtros aplicados.</p>
                <?php if (hasRole(['admin', 'inventory'])): ?>
                    <a href="/core/inventory/new.php" class="btn btn-primary rounded-pill px-4" onclick="window.location.href='/core/inventory/new.php'; return false;">
                        <i class="fas fa-plus me-2"></i>Crear Primer Producto
                    </a>
                <?php
    endif; ?>
            </div>
        <?php
else: ?>
            <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="ps-4">Producto</th>
                            <th>Categoría/Marca</th>
                            <th>Stock</th>
                            <th>Precio</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="fas fa-box text-primary no-theme"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </div>
                                            <small class="text-muted font-monospace"><?php echo htmlspecialchars($product['internal_code']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark"><?php echo htmlspecialchars($product['category_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($product['brand_name'] ?: '-'); ?></small>
                                </td>
                                <td>
                                    <?php
        $stock_class = 'text-success';
        $bg_class = 'bg-success bg-opacity-10';
        if ($product['current_stock'] == 0) {
            $stock_class = 'text-danger';
            $bg_class = 'bg-danger bg-opacity-10';
        }
        elseif ($product['current_stock'] <= $product['min_stock']) {
            $stock_class = 'text-warning';
            $bg_class = 'bg-warning bg-opacity-10';
        }
?>
                                    <span class="badge rounded-pill <?php echo $bg_class . ' ' . $stock_class; ?>">
                                        <?php echo number_format($product['current_stock'], 0); ?>
                                    </span>
                                    <?php if ($product['min_stock'] > 0): ?>
                                        <div class="small text-muted mt-1">Mín: <?php echo number_format($product['min_stock'], 0); ?></div>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo formatCurrency($product['sale_price']); ?></div>
                                    <small class="text-muted">Compra: <?php echo formatCurrency($product['purchase_price']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($product['supplier_name'] ?: '-'); ?></td>
                                <td>
                                    <?php if ($product['status'] === 'active'): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success no-theme">Activo</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                    <?php
        endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="view.php?id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-light text-primary shadow-sm" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="movement.php?product_id=<?php echo $product['id']; ?>" 
                                           class="btn btn-sm btn-light text-info shadow-sm" title="Movimiento">
                                            <i class="fas fa-exchange-alt"></i>
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
                    <?php foreach ($products as $product): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                    <?php if ($product['status'] === 'active'): ?>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success no-theme">Activo</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                    <?php
        endif; ?>
                                    <span class="font-monospace text-dark bg-light px-2 py-1 rounded small"><?php echo htmlspecialchars($product['internal_code']); ?></span>
                                </div>
                                <div class="card-body py-2">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="fas fa-box fa-lg text-primary no-theme"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1 text-dark">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($product['category_name']); ?> <?php echo $product['brand_name'] ? '• ' . htmlspecialchars($product['brand_name']) : ''; ?></small>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light d-flex justify-content-between">
                                        <div>
                                            <div class="fw-bold text-dark mb-1">Precio Venta</div>
                                            <div class="text-primary fw-bold"><?php echo formatCurrency($product['sale_price']); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-dark mb-1">Stock</div>
                                            <?php
        $stock_class = 'text-success';
        $bg_class = 'bg-success bg-opacity-10';
        if ($product['current_stock'] == 0) {
            $stock_class = 'text-danger';
            $bg_class = 'bg-danger bg-opacity-10';
        }
        elseif ($product['current_stock'] <= $product['min_stock']) {
            $stock_class = 'text-warning';
            $bg_class = 'bg-warning bg-opacity-10';
        }
?>
                                            <span class="badge rounded-pill <?php echo $bg_class . ' ' . $stock_class; ?>">
                                                <?php echo number_format($product['current_stock'], 0); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                                    <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                        <span><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($product['supplier_name'] ?: 'Sin proveedor'); ?></span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 pb-1 justify-content-center justify-content-sm-start">
                                        <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-light text-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Ver"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-light text-warning shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                        <a href="movement.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-light text-info shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Movimiento"><i class="fas fa-exchange-alt"></i></a>
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
                    <nav aria-label="Paginación de productos">
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
