<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SERVICE_REPORTS', 'services', null);

// Tenant actual
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');
$hasTenantServices = hasTenantColumnCached($pdo, 'services');
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');

// Obtener parámetros de filtros
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Primer día del mes actual
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Hoy
$service_filter = $_GET['service'] ?? '';
$category_filter = $_GET['category'] ?? '';

// Construir consulta con filtros
$where_conditions = ["DATE(wo.created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
if ($hasTenantWorkOrders && !$perDatabase) {
    $where_conditions[] = "wo.tenant_id = ?";
    $params[] = $tenantValue;
}

if (!empty($service_filter)) {
    $where_conditions[] = "wos.service_id = ?";
    $params[] = $service_filter;
}

if (!empty($category_filter)) {
    $where_conditions[] = "s.device_category_id = ?";
    $params[] = $category_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Obtener estadísticas generales
$stats = [
    'total_services' => 0,
    'total_revenue' => 0,
    'average_price' => 0,
    'most_popular_service' => null
];

try {
    $sql = "
        SELECT 
            COUNT(DISTINCT wos.service_id) as total_services,
            SUM(wos.total_price) as total_revenue,
            AVG(wos.service_price) as average_price
        FROM work_order_services wos
        INNER JOIN work_orders wo ON wos.work_order_id = wo.id
        INNER JOIN services s ON wos.service_id = s.id" . (($hasTenantServices && $hasTenantWorkOrders && !$perDatabase) ? " AND s.tenant_id = wo.tenant_id" : "") . "
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener servicio más popular
    $sql = "
        SELECT s.name, COUNT(*) as usage_count
        FROM work_order_services wos
        INNER JOIN work_orders wo ON wos.work_order_id = wo.id
        INNER JOIN services s ON wos.service_id = s.id" . (($hasTenantServices && $hasTenantWorkOrders && !$perDatabase) ? " AND s.tenant_id = wo.tenant_id" : "") . "
        $where_clause
        GROUP BY s.id, s.name
        ORDER BY usage_count DESC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $most_popular = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['most_popular_service'] = $most_popular;
    
} catch (PDOException $e) {
    error_log("Error al obtener estadísticas: " . $e->getMessage());
}

// Obtener servicios más utilizados
$popular_services = [];
try {
    $sql = "
        SELECT s.name, s.base_price, dc.name as category_name,
               COUNT(*) as usage_count,
               SUM(wos.total_price) as total_revenue,
               AVG(wos.service_price) as average_price
        FROM work_order_services wos
        INNER JOIN work_orders wo ON wos.work_order_id = wo.id
        INNER JOIN services s ON wos.service_id = s.id" . (($hasTenantServices && $hasTenantWorkOrders && !$perDatabase) ? " AND s.tenant_id = wo.tenant_id" : "") . "
        LEFT JOIN device_categories dc ON s.device_category_id = dc.id" . (($hasTenantDeviceCategories && $hasTenantServices && !$perDatabase) ? " AND dc.tenant_id = s.tenant_id" : "") . "
        $where_clause
        GROUP BY s.id, s.name, s.base_price, dc.name
        ORDER BY usage_count DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $popular_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener servicios populares: " . $e->getMessage());
}

// Obtener servicios para filtro
$services = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM services WHERE active = 1" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name");
    $stmt->execute(($hasTenantServices && !$perDatabase) ? [$tenantValue] : []);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener servicios: " . $e->getMessage());
}

// Obtener categorías para filtro
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM device_categories WHERE active = 1" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name");
    $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-chart-bar me-2"></i>Reportes de Servicios</h1>
            <p class="text-muted mb-0">Análisis y estadísticas de servicios</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Exportar Excel
            </button>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Servicios
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-filter me-2"></i>Filtros
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="date_from" class="form-label">Fecha Desde</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">Fecha Hasta</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-3">
                    <label for="service" class="form-label">Servicio</label>
                    <select class="form-select" id="service" name="service">
                        <option value="">Todos los servicios</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>" 
                                    <?php echo $service_filter == $service['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($service['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="category" class="form-label">Categoría</label>
                    <select class="form-select" id="category" name="category">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Generar Reporte
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary">
                        <i class="fas fa-refresh me-2"></i>Limpiar Filtros
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Estadísticas Generales -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-primary"><?php echo number_format($stats['total_services']); ?></h5>
                    <p class="card-text">Servicios Utilizados</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-success">$<?php echo number_format($stats['total_revenue'] ?: 0, 0, ',', '.'); ?></h5>
                    <p class="card-text">Ingresos Totales</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-info">$<?php echo number_format($stats['average_price'] ?: 0, 0, ',', '.'); ?></h5>
                    <p class="card-text">Precio Promedio</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-warning"><?php echo $stats['most_popular_service'] ? htmlspecialchars($stats['most_popular_service']['name']) : '-'; ?></h5>
                    <p class="card-text">Servicio Más Popular</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Servicios Más Utilizados -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-trophy me-2"></i>Servicios Más Utilizados
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($popular_services)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay datos para mostrar</h5>
                    <p class="text-muted">No se encontraron servicios utilizados en el período seleccionado.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="servicesTable">
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Categoría</th>
                                <th>Precio Base</th>
                                <th>Usos</th>
                                <th>Precio Promedio</th>
                                <th>Ingresos Totales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popular_services as $service): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($service['name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($service['category_name'] ?: '-'); ?></td>
                                    <td>
                                        $<?php echo number_format($service['base_price'], 0, ',', '.'); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $service['usage_count']; ?></span>
                                    </td>
                                    <td>
                                        <strong>$<?php echo number_format($service['average_price'], 0, ',', '.'); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="text-success">$<?php echo number_format($service['total_revenue'], 0, ',', '.'); ?></strong>
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

<script>
function exportToExcel() {
    // Crear tabla HTML para exportar
    const table = document.getElementById('servicesTable');
    if (!table) {
        alert('No hay datos para exportar');
        return;
    }
    
    // Crear contenido HTML
    let html = `
        <html>
        <head>
            <meta charset="utf-8">
            <title>Reporte de Servicios</title>
        </head>
        <body>
            <h2>Reporte de Servicios</h2>
            <p>Período: ${document.getElementById('date_from').value} - ${document.getElementById('date_to').value}</p>
            <p>Generado el: ${new Date().toLocaleDateString()}</p>
            ${table.outerHTML}
        </body>
        </html>
    `;
    
    // Crear blob y descargar
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'reporte_servicios_' + new Date().toISOString().split('T')[0] + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
