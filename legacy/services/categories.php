<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');
$hasTenantServices = hasTenantColumnCached($pdo, 'services');

// Verificar permisos
if (!hasRole(['admin', 'technician'])) {
    header('Location: ../dashboard.php');
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $sort_order = intval($_POST['sort_order']);
            
            if (empty($name)) {
                throw new Exception('El nombre de la categoría es obligatorio');
            }
            
            // Verificar que el nombre sea único
            $sql = "SELECT id FROM device_categories WHERE name = ?" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$name, $tenantValue] : [$name]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una categoría con ese nombre');
            }
            
            if ($hasTenantDeviceCategories) {
                $stmt = $pdo->prepare("
                    INSERT INTO device_categories (tenant_id, name, description, sort_order) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$tenantValue, $name, $description, $sort_order]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO device_categories (name, description, sort_order) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$name, $description, $sort_order]);
            }
            
            $mensaje = 'Categoría creada exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($action === 'edit') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $sort_order = intval($_POST['sort_order']);
            $active = isset($_POST['active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('El nombre de la categoría es obligatorio');
            }
            
            // Verificar que el nombre sea único (excluyendo el registro actual)
            $sql = "SELECT id FROM device_categories WHERE name = ? AND id != ?" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$name, $id, $tenantValue] : [$name, $id]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una categoría con ese nombre');
            }
            
            $stmt = $pdo->prepare("
                UPDATE device_categories 
                SET name = ?, description = ?, sort_order = ?, active = ?
                WHERE id = ?" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ");
            $params = [$name, $description, $sort_order, $active, $id];
            if ($hasTenantDeviceCategories && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            $mensaje = 'Categoría actualizada exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            
            // Verificar que no haya servicios asociados
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM services WHERE device_category_id = ?" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : ""));
            $stmt->execute(($hasTenantServices && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('No se puede eliminar la categoría porque tiene servicios asociados');
            }
            
            $stmt = $pdo->prepare("DELETE FROM device_categories WHERE id = ?" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : ""));
            $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            
            $mensaje = 'Categoría eliminada exitosamente';
            $tipo_mensaje = 'success';
        }
        
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Obtener categorías
$categories = [];
try {
    $joinServices = ($hasTenantServices && $hasTenantDeviceCategories && !$perDatabase)
        ? "LEFT JOIN services s ON dc.id = s.device_category_id AND s.tenant_id = dc.tenant_id"
        : "LEFT JOIN services s ON dc.id = s.device_category_id";
    $stmt = $pdo->prepare("
        SELECT dc.*, 
               COUNT(s.id) as service_count
        FROM device_categories dc
        $joinServices
        " . (($hasTenantDeviceCategories && !$perDatabase) ? "WHERE dc.tenant_id = ?" : "") . "
        GROUP BY dc.id
        ORDER BY dc.sort_order ASC, dc.name ASC
    ");
    $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_SERVICE_CATEGORIES', 'device_categories', null);

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-tags me-2"></i>Categorías de Servicios</h1>
            <p class="text-muted mb-0">Gestiona las categorías de servicios</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                <i class="fas fa-plus me-2"></i>Nueva Categoría
            </button>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Servicios
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Lista de Categorías -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Categorías
                <span class="badge bg-primary ms-2"><?php echo count($categories); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($categories)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay categorías registradas</h5>
                    <p class="text-muted">Crea la primera categoría para organizar tus servicios.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                        <i class="fas fa-plus me-2"></i>Crear Primera Categoría
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Orden</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Servicios</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $category['sort_order']; ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($category['description']): ?>
                                            <?php echo htmlspecialchars($category['description']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $category['service_count']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($category['active']): ?>
                                            <span class="badge bg-success">Activa</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactiva</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" 
                                                    onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)"
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($category['service_count'] == 0): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')"
                                                        title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
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

<!-- Modal Crear Categoría -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="createCategoryModalLabel">
                        <i class="fas fa-plus me-2"></i>Nueva Categoría
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_name" class="form-label">
                            Nombre <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="create_name" name="name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="create_description" class="form-label">Descripción</label>
                        <textarea class="form-control" id="create_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="create_sort_order" class="form-label">Orden</label>
                        <input type="number" class="form-control" id="create_sort_order" name="sort_order" value="0" min="0">
                        <div class="form-text">Orden de aparición en las listas</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Categoría -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCategoryModalLabel">
                        <i class="fas fa-edit me-2"></i>Editar Categoría
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">
                            Nombre <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="edit_name" name="name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Descripción</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sort_order" class="form-label">Orden</label>
                        <input type="number" class="form-control" id="edit_sort_order" name="sort_order" min="0">
                        <div class="form-text">Orden de aparición en las listas</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_active" name="active" checked>
                            <label class="form-check-label" for="edit_active">
                                Categoría activa
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Eliminar Categoría -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteCategoryModalLabel">
                        <i class="fas fa-trash me-2"></i>Eliminar Categoría
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>¿Estás seguro?</strong>
                    </div>
                    <p>Se eliminará la categoría <strong id="delete_name"></strong>.</p>
                    <p class="text-muted">Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(category) {
    document.getElementById('edit_id').value = category.id;
    document.getElementById('edit_name').value = category.name;
    document.getElementById('edit_description').value = category.description || '';
    document.getElementById('edit_sort_order').value = category.sort_order;
    document.getElementById('edit_active').checked = category.active == 1;
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function deleteCategory(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    
    new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
