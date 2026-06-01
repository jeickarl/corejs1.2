<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
$pdo = db();

requireAuth();

// Verificar tenant
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantInventoryMovements = hasTenantColumnCached($pdo, 'inventory_movements');
$hasTenantProductCategories = hasTenantColumnCached($pdo, 'product_categories');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

$product_id = intval($_GET['id'] ?? 0);

if (!$product_id) {
    header('Location: index.php?error=' . urlencode('ID de producto no válido.'));
    exit();
}

// Obtener información del producto
$product = null;
try {
    $stmt = $pdo->prepare("
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
        WHERE ip.id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND ip.tenant_id = ?" : "") . "
    ");
    $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header('Location: index.php?error=' . urlencode('Producto no encontrado.'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al obtener el producto.'));
    exit();
}

// Registrar actividad
try {
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        'VIEW',
        'inventory_products',
        $product_id,
        'Producto visualizado: ' . $product['name']
    ]);
} catch (PDOException $e) {
    // Log error but don't stop the process
}

// Obtener movimientos recientes (últimos 10)
$movements = [];
try {
    $joinU = ($hasTenantUsers && $hasTenantInventoryMovements && !$perDatabase) ? "LEFT JOIN users u ON im.created_by = u.id AND u.tenant_id = im.tenant_id" : "LEFT JOIN users u ON im.created_by = u.id";
    $sql = "
        SELECT im.*, u.name as created_by_name
        FROM inventory_movements im
        {$joinU}
        WHERE im.product_id = ?" . (($hasTenantInventoryMovements && !$perDatabase) ? " AND im.tenant_id = ?" : "") . "
        ORDER BY im.created_at DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantInventoryMovements && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error but continue
}

// Obtener estadísticas del producto
$stats = [
    'total_movements' => 0,
    'total_entries' => 0,
    'total_exits' => 0,
    'last_movement_date' => null
];

try {
    $sql = "
        SELECT 
            COUNT(*) as total_movements,
            SUM(CASE WHEN movement_type = 'entry' THEN quantity ELSE 0 END) as total_entries,
            SUM(CASE WHEN movement_type = 'exit' THEN quantity ELSE 0 END) as total_exits,
            MAX(created_at) as last_movement_date
        FROM inventory_movements 
        WHERE product_id = ?" . (($hasTenantInventoryMovements && !$perDatabase) ? " AND tenant_id = ?" : "") . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantInventoryMovements && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $stats = $result;
    }
} catch (PDOException $e) {
    // Log error but continue
}

// Mensajes de éxito/error
$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
} elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}
?>

<?php
$page_title = 'Producto - ' . ($product['name'] ?? 'Detalle');
ob_start();
?>

<?php include __DIR__ . '/_inventory_styles.php'; ?>

<div class="inventory-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-3 mb-4">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-box me-2 text-primary no-theme"></i><?php echo htmlspecialchars($product['name']); ?></h4>
                    <div class="text-muted small">Información detallada del producto</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning rounded-pill px-4">
                        <i class="fas fa-edit me-2"></i>Editar
                    </a>
                    <?php include __DIR__ . '/_subnav.php'; ?>
                </div>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
                    <?php if ($tipo_mensaje == 'success'): ?>
                        <i class="fas fa-check-circle me-2 fa-lg"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold text-primary no-theme">
                                <i class="fas fa-info-circle me-2 text-primary no-theme"></i>Información Básica
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Código Interno</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-barcode me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($product['internal_code']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Categoría</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-tags me-2 text-muted"></i>
                                            <?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : '<span class="text-muted">No asignada</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Nombre del Producto</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-box-open me-2 text-muted"></i>
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Marca</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-copyright me-2 text-muted"></i>
                                            <?php echo $product['brand_name'] ? htmlspecialchars($product['brand_name']) : '<span class="text-muted">No asignada</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Proveedor</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-truck me-2 text-muted"></i>
                                            <?php echo $product['supplier_name'] ? htmlspecialchars($product['supplier_name']) : '<span class="text-muted">No asignado</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($product['description']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Descripción</label>
                                <div class="p-3 bg-light rounded-3 border">
                                    <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Información de Inventario -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold text-primary no-theme">
                                <i class="fas fa-boxes me-2 text-primary no-theme"></i>Información de Inventario
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Precio de Compra</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-money-bill-wave me-2 text-muted"></i>
                                            <?php echo formatCurrency($product['purchase_price']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Precio de Venta</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-tag me-2 text-muted"></i>
                                            <?php echo formatCurrency($product['sale_price']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Stock Actual</label>
                                        <div class="p-2 bg-light rounded-3 border d-flex align-items-center justify-content-between">
                                            <span><i class="fas fa-layer-group me-2 text-muted"></i><?php echo number_format($product['current_stock'], 0); ?></span>
                                            <span class="badge rounded-pill <?php echo $product['current_stock'] <= $product['min_stock'] ? 'bg-danger' : 'bg-success'; ?>">
                                                <?php echo $product['current_stock'] <= $product['min_stock'] ? 'Bajo Stock' : 'OK'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Stock Mínimo</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-arrow-down me-2 text-muted"></i>
                                            <?php echo number_format($product['min_stock'], 0); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Stock Máximo</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-arrow-up me-2 text-muted"></i>
                                            <?php echo $product['max_stock'] ? number_format($product['max_stock'], 0) : '<span class="text-muted">No definido</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Tipo de Unidad</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-ruler me-2 text-muted"></i>
                                            <?php 
                                            $unit_types = [
                                                'unit' => 'Unidad',
                                                'meter' => 'Metro',
                                                'kilogram' => 'Kilogramo',
                                                'liter' => 'Litro',
                                                'box' => 'Caja',
                                                'pack' => 'Paquete'
                                            ];
                                            echo $product['unit_type'] && isset($unit_types[$product['unit_type']]) ? $unit_types[$product['unit_type']] : '<span class="text-muted">No especificado</span>';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Ubicación</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                                            <?php echo $product['location'] ? htmlspecialchars($product['location']) : '<span class="text-muted">No especificada</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Movimientos Recientes -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold text-primary no-theme">
                                <i class="fas fa-exchange-alt me-2 text-primary no-theme"></i>Movimientos Recientes
                            </h5>
                            <a href="movements.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">
                                Ver todos
                            </a>
                        </div>
                        <div class="card-body p-4">
                            <?php if (empty($movements)): ?>
                                <div class="text-center py-4">
                                    <div class="mb-3">
                                        <i class="fas fa-inbox fa-3x text-muted opacity-50"></i>
                                    </div>
                                    <p class="text-muted fw-bold">No hay movimientos registrados</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="border-0 rounded-start-3 ps-3">Fecha</th>
                                                <th class="border-0">Tipo</th>
                                                <th class="border-0">Cantidad</th>
                                                <th class="border-0">Motivo</th>
                                                <th class="border-0 rounded-end-3">Usuario</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movements as $movement): ?>
                                                <tr>
                                                    <td class="ps-3"><?php echo date('d/m/Y H:i', strtotime($movement['created_at'])); ?></td>
                                                    <td>
                                                        <span class="badge rounded-pill <?php echo $movement['movement_type'] === 'entry' ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo $movement['movement_type'] === 'entry' ? 'Entrada' : 'Salida'; ?>
                                                        </span>
                                                    </td>
                                                    <td class="fw-bold"><?php echo number_format($movement['quantity'], 0); ?></td>
                                                    <td><?php echo htmlspecialchars($movement['reason'] ?? 'Sin motivo'); ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-circle-sm bg-light text-primary no-theme me-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 10px;">
                                                                <?php echo strtoupper(substr($movement['created_by_name'] ?? 'U', 0, 1)); ?>
                                                            </div>
                                                            <?php echo htmlspecialchars($movement['created_by_name'] ?? 'Usuario desconocido'); ?>
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
                
                <div class="col-lg-4">
                    <!-- Estadísticas -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-chart-bar me-2 text-primary"></i>Estadísticas
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h4 class="text-primary mb-1 fw-bold"><?php echo number_format($stats['total_movements'] ?? 0); ?></h4>
                                        <small class="text-muted fw-bold">Total Movimientos</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-success mb-1 fw-bold"><?php echo number_format($stats['total_entries'] ?? 0, 0); ?></h4>
                                    <small class="text-muted fw-bold">Total Entradas</small>
                                </div>
                            </div>
                            <hr class="my-4">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h4 class="text-danger mb-1 fw-bold"><?php echo number_format($stats['total_exits'] ?? 0, 0); ?></h4>
                                        <small class="text-muted fw-bold">Total Salidas</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-info mb-1 fw-bold">$<?php echo number_format($product['current_stock'] * $product['purchase_price'], 2); ?></h4>
                                    <small class="text-muted fw-bold">Valor Stock</small>
                                </div>
                            </div>
                            
                            <?php if ($stats['last_movement_date']): ?>
                            <hr class="my-4">
                            <div class="text-center">
                                <small class="text-muted">
                                    <i class="fas fa-history me-1"></i>
                                    Último movimiento: <?php echo date('d/m/Y', strtotime($stats['last_movement_date'])); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Estado y Configuración -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-cog me-2 text-primary"></i>Estado y Sistema
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Estado</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <?php if ($product['is_active']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fas fa-check-circle me-1"></i>Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill"><i class="fas fa-times-circle me-1"></i>Inactivo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">ID del Sistema</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-fingerprint me-2 text-muted"></i>
                                    <?php echo $product['id']; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Creado por</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-user me-2 text-muted"></i>
                                    <?php echo htmlspecialchars($product['created_by_name'] ?? 'Desconocido'); ?>
                                </div>
                            </div>
                            
                            <div class="mb-0">
                                <label class="form-label fw-bold text-muted">Fecha de Creación</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-calendar-plus me-2 text-muted"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($product['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                                    <span class="badge <?php echo $product['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $product['is_active'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </p>
                            </div>
                            
                            <?php if ($product['notes']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Notas</label>
                                <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($product['notes'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Información del Sistema -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-database me-2"></i>Información del Sistema
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">ID</label>
                                <p class="form-control-plaintext"><?php echo $product['id']; ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Creado por</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($product['created_by_name'] ?? 'Usuario desconocido'); ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Fecha de creación</label>
                                <p class="form-control-plaintext"><?php echo date('d/m/Y H:i', strtotime($product['created_at'])); ?></p>
                            </div>
                            
                            <?php if ($product['updated_at']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Última actualización</label>
                                <p class="form-control-plaintext"><?php echo date('d/m/Y H:i', strtotime($product['updated_at'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Acciones Rápidas -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-bolt me-2"></i>Acciones Rápidas
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="movements.php?action=entry&product_id=<?php echo $product['id']; ?>" class="btn btn-success">
                                    <i class="fas fa-plus me-2"></i>Registrar Entrada
                                </a>
                                <a href="movements.php?action=exit&product_id=<?php echo $product['id']; ?>" class="btn btn-danger">
                                    <i class="fas fa-minus me-2"></i>Registrar Salida
                                </a>
                                <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning">
                                    <i class="fas fa-edit me-2"></i>Editar Producto
                                </a>
                            </div>
                        </div>
                    </div>
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
