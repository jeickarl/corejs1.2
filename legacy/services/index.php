<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SERVICES', 'services', null);
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantServices = hasTenantColumnCached($pdo, 'services');
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if ($hasTenantServices && !$perDatabase) {
    $where_conditions[] = "s.tenant_id = ?";
    $params[] = $tenantValue;
}

if (!empty($search)) {
    $where_conditions[] = "(s.name LIKE ? OR s.description LIKE ?)";
    $search_param = "%$search%";
    $params = array_fill(0, 2, $search_param);
}

if (!empty($category_filter)) {
    $where_conditions[] = "s.device_category_id = ?";
    $params[] = $category_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "s.active = ?";
    $params[] = $status_filter === 'active' ? 1 : 0;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Obtener servicios
$services = [];
$total_services = 0;

try {
    // Contar total de servicios
    $count_sql = "
        SELECT COUNT(*) as total
        FROM services s
        LEFT JOIN device_categories dc ON s.device_category_id = dc.id" . (($hasTenantDeviceCategories && $hasTenantServices && !$perDatabase) ? " AND dc.tenant_id = s.tenant_id" : "") . "
        $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_services = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener servicios
    $sql = "
        SELECT s.*, 
               dc.name as category_name
        FROM services s
        LEFT JOIN device_categories dc ON s.device_category_id = dc.id" . (($hasTenantDeviceCategories && $hasTenantServices && !$perDatabase) ? " AND dc.tenant_id = s.tenant_id" : "") . "
        $where_clause
        ORDER BY s.name ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);


}
catch (PDOException $e) {
    error_log("Error al obtener servicios: " . $e->getMessage());
}

// Obtener categorías para filtro
$categories = [];
try {
    $sql = "SELECT id, name FROM device_categories WHERE active = 1" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Calcular estadísticas
$stats = [
    'total_services' => 0,
    'active_services' => 0,
    'average_price' => 0,
    'total_categories' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_services,
            SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_services,
            AVG(base_price) as average_price
        FROM services
        " . (($hasTenantServices && !$perDatabase) ? "WHERE tenant_id = ?" : "") . "
    ");
    $stmt->execute(($hasTenantServices && !$perDatabase) ? [$tenantValue] : []);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) as total_categories FROM device_categories WHERE active = 1" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : ""));
    $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$tenantValue] : []);
    $stats['total_categories'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_categories'];
}
catch (PDOException $e) {
    error_log("Error al calcular estadísticas: " . $e->getMessage());
}

$total_pages = ceil($total_services / $per_page);

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-tools me-2"></i>Servicios</h1>
            <p class="text-muted mb-0">Gestiona los servicios del taller</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="new.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Nuevo Servicio
            </a>
            <a href="categories.php" class="btn btn-outline-secondary">
                <i class="fas fa-tags me-2"></i>Categorías
            </a>
            <a href="reports.php" class="btn btn-outline-info">
                <i class="fas fa-chart-bar me-2"></i>Reportes
            </a>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-primary"><?php echo number_format($stats['total_services']); ?></h5>
                    <p class="card-text">Total Servicios</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-success"><?php echo number_format($stats['active_services']); ?></h5>
                    <p class="card-text">Servicios Activos</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-info">$<?php echo number_format($stats['average_price'], 0, ',', '.'); ?></h5>
                    <p class="card-text">Precio Promedio</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-warning"><?php echo number_format($stats['total_categories']); ?></h5>
                    <p class="card-text">Categorías</p>
                </div>
            </div>
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
                <div class="col-md-4">
                    <label for="search" class="form-label">Buscar</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Nombre o descripción del servicio">
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
                        <?php
endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Estado</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Activo</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactivo</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Servicios -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Servicios
                <span class="badge bg-primary ms-2"><?php echo number_format($total_services); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($services)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No se encontraron servicios</h5>
                    <p class="text-muted">No hay servicios que coincidan con los filtros aplicados.</p>
                    <a href="new.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Crear Primer Servicio
                    </a>
                </div>
            <?php
else: ?>
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0 text-nowrap">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th>Servicio</th>
                                <th>Categoría</th>
                                <th>Precio Base</th>
                                <th>Duración Est.</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($service['name']); ?></strong>
                                        <?php if ($service['description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($service['description']); ?></small>
                                        <?php
        endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($service['category_name'] ?: '-'); ?></td>
                                    <td>
                                        <strong>$<?php echo number_format($service['base_price'], 0, ',', '.'); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($service['estimated_time']): ?>
                                            <?php echo $service['estimated_time']; ?> min
                                        <?php
        else: ?>
                                            -
                                        <?php
        endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($service['active']): ?>
                                            <span class="badge bg-success">Activo</span>
                                        <?php
        else: ?>
                                            <span class="badge bg-secondary">Inactivo</span>
                                        <?php
        endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view.php?id=<?php echo $service['id']; ?>" 
                                               class="btn btn-outline-primary" title="Ver">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $service['id']; ?>" 
                                               class="btn btn-outline-warning" title="Editar">
                                                <i class="fas fa-edit"></i>
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
                        <?php foreach ($services as $service): ?>
                            <div class="col-12">
                                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                    <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                        <?php if ($service['active']): ?>
                                            <span class="badge rounded-pill bg-success bg-opacity-10 text-success">Activo</span>
                                        <?php
        else: ?>
                                            <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                        <?php
        endif; ?>
                                    </div>
                                    <div class="card-body py-2">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                <i class="fas fa-tools fa-lg text-primary no-theme"></i>
                                            </div>
                                            <div>
                                                <h6 class="fw-bold mb-1 text-dark">
                                                    <?php echo htmlspecialchars($service['name']); ?>
                                                </h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($service['category_name'] ?: 'Sin categoría'); ?></small>
                                            </div>
                                        </div>
                                        
                                        <?php if ($service['description']): ?>
                                        <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                                            <?php echo htmlspecialchars($service['description']); ?>
                                        </div>
                                        <?php
        endif; ?>
                                        
                                        <div class="d-flex justify-content-between p-3 bg-light rounded-3 mt-2 border border-light">
                                            <div class="text-center">
                                                <div class="small text-muted mb-1">Precio Base</div>
                                                <div class="fw-bold text-primary">$<?php echo number_format($service['base_price'], 0, ',', '.'); ?></div>
                                            </div>
                                            <div class="text-center border-start ps-3">
                                                <div class="small text-muted mb-1">Duración Est.</div>
                                                <div class="fw-bold text-dark">
                                                    <?php if ($service['estimated_time']): ?>
                                                        <?php echo $service['estimated_time']; ?> min
                                                    <?php
        else: ?>
                                                        -
                                                    <?php
        endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white border-top-0 pb-3 pt-2">
                                        <div class="d-flex flex-wrap gap-2 pb-1 justify-content-end">
                                            <a href="view.php?id=<?php echo $service['id']; ?>" class="btn btn-sm btn-light text-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Ver"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?php echo $service['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
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
                    <div class="card-footer bg-white border-top border-light py-3 mt-4">
                        <nav aria-label="Paginación de servicios">
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
