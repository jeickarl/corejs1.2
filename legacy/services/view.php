<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantServices = hasTenantColumnCached($pdo, 'services');
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');
$hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');
$hasTenantClients = hasTenantColumnCached($pdo, 'clients');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

$service_id = intval($_GET['id'] ?? 0);

if (!$service_id) {
    header('Location: index.php');
    exit();
}

// Obtener información del servicio
$service = null;
try {
    $joinCategory = ($hasTenantDeviceCategories && $hasTenantServices && !$perDatabase) ? "LEFT JOIN device_categories dc ON s.device_category_id = dc.id AND dc.tenant_id = s.tenant_id" : "LEFT JOIN device_categories dc ON s.device_category_id = dc.id";
    $stmt = $pdo->prepare("
        SELECT s.*, dc.name as category_name
        FROM services s
        $joinCategory
        WHERE s.id = ? " . (($hasTenantServices && !$perDatabase) ? "AND s.tenant_id = ?" : "") . "
    ");
    $stmt->execute(($hasTenantServices && !$perDatabase) ? [$service_id, $tenantValue] : [$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error al obtener servicio: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SERVICE', 'services', $service_id);

// Obtener órdenes que han usado este servicio
$work_orders = [];
try {
    $joinClient = ($hasTenantClients && $hasTenantWorkOrders && !$perDatabase) ? "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id" : "LEFT JOIN clients c ON wo.client_id = c.id";
    $joinUser = ($hasTenantUsers && $hasTenantWorkOrders && !$perDatabase) ? "LEFT JOIN users u ON wo.assigned_technician = u.id AND u.tenant_id = wo.tenant_id" : "LEFT JOIN users u ON wo.assigned_technician = u.id";
    $stmt = $pdo->prepare("
        SELECT wo.*, c.name as client_name, u.name as technician_name
        FROM work_orders wo
        INNER JOIN work_order_services wos ON wo.id = wos.work_order_id
        $joinClient
        $joinUser
        WHERE wos.service_id = ? " . (($hasTenantWorkOrders && !$perDatabase) ? "AND wo.tenant_id = ?" : "") . "
        ORDER BY wo.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(($hasTenantWorkOrders && !$perDatabase) ? [$service_id, $tenantValue] : [$service_id]);
    $work_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener órdenes: " . $e->getMessage());
}

// Obtener estadísticas del servicio
$stats = [
    'total_orders' => 0,
    'total_revenue' => 0,
    'average_price' => 0,
    'last_used' => null
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(wos.total_price) as total_revenue,
            AVG(wos.service_price) as average_price,
            MAX(wo.created_at) as last_used
        FROM work_order_services wos
        INNER JOIN work_orders wo ON wos.work_order_id = wo.id
        WHERE wos.service_id = ? " . (($hasTenantWorkOrders && !$perDatabase) ? "AND wo.tenant_id = ?" : "") . "
    ");
    $stmt->execute(($hasTenantWorkOrders && !$perDatabase) ? [$service_id, $tenantValue] : [$service_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener estadísticas: " . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-tools me-2"></i><?php echo htmlspecialchars($service['name']); ?></h1>
            <p class="text-muted mb-0">Información detallada del servicio</p>
        </div>
        <div class="d-flex gap-2">
            <a href="edit.php?id=<?php echo $service['id']; ?>" class="btn btn-warning">
                <i class="fas fa-edit me-2"></i>Editar
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Información Principal -->
        <div class="col-lg-8">
            <!-- Información del Servicio -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información del Servicio
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Nombre:</strong></td>
                                    <td><?php echo htmlspecialchars($service['name']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Categoría:</strong></td>
                                    <td><?php echo htmlspecialchars($service['category_name'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Precio Base:</strong></td>
                                    <td>
                                        <strong class="text-success">
                                            $<?php echo number_format($service['base_price'], 0, ',', '.'); ?>
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Tiempo Estimado:</strong></td>
                                    <td>
                                        <?php if ($service['estimated_time']): ?>
                                            <?php echo $service['estimated_time']; ?> minutos
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Estado:</strong></td>
                                    <td>
                                        <?php if ($service['active']): ?>
                                            <span class="badge bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Creado:</strong></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($service['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Actualizado:</strong></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($service['updated_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($service['description']): ?>
                        <div class="mt-3">
                            <h6>Descripción:</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($service['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($service['notes']): ?>
                        <div class="mt-3">
                            <h6>Notas:</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($service['notes'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Órdenes Recientes -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-clipboard-list me-2"></i>Órdenes Recientes
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($work_orders)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                            <p class="text-muted">Este servicio no ha sido usado en ninguna orden</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Orden</th>
                                        <th>Cliente</th>
                                        <th>Técnico</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($work_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="text-decoration-none">
                                                    #<?php echo $order['id']; ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($order['technician_name'] ?: '-'); ?></td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                switch ($order['status']) {
                                                    case 'completed':
                                                        $status_class = 'bg-success';
                                                        break;
                                                    case 'in_progress':
                                                        $status_class = 'bg-warning';
                                                        break;
                                                    case 'pending':
                                                        $status_class = 'bg-info';
                                                        break;
                                                    case 'cancelled':
                                                        $status_class = 'bg-secondary';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($order['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Panel Lateral -->
        <div class="col-lg-4">
            <!-- Estadísticas -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Estadísticas
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <h4 class="text-primary"><?php echo number_format($stats['total_orders']); ?></h4>
                            <small class="text-muted">Total Órdenes</small>
                        </div>
                        <div class="col-6">
                            <h4 class="text-success">$<?php echo number_format($stats['total_revenue'] ?: 0, 0, ',', '.'); ?></h4>
                            <small class="text-muted">Ingresos</small>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-6">
                            <h4 class="text-info">$<?php echo number_format($stats['average_price'] ?: 0, 0, ',', '.'); ?></h4>
                            <small class="text-muted">Precio Promedio</small>
                        </div>
                        <div class="col-6">
                            <h4 class="text-warning"><?php echo $stats['last_used'] ? date('d/m/Y', strtotime($stats['last_used'])) : '-'; ?></h4>
                            <small class="text-muted">Último Uso</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Información Adicional -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información Adicional
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb me-2"></i>Uso del Servicio:</h6>
                        <ul class="mb-0">
                            <li>Se puede agregar a <strong>órdenes de trabajo</strong></li>
                            <li>El precio puede <strong>ajustarse</strong> por orden</li>
                            <li>Se registra el <strong>tiempo real</strong> utilizado</li>
                            <li>Aparece en <strong>reportes</strong> de servicios</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
                        <p class="mb-0">Si marcas el servicio como <strong>inactivo</strong>, no aparecerá en las listas de selección para nuevas órdenes.</p>
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
