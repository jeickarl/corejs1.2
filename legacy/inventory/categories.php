<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
$pdo = db();

// Verificar autenticación
requireAuth();

// Verificar permisos
if (!hasRole(['admin', 'inventory'])) {
    header('Location: ../dashboard.php');
    exit();
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantCategories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'product_categories') : false;
$hasTenantInventoryProducts = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'inventory_products') : false;

$mensaje = '';
$tipo_mensaje = '';
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Error de seguridad: Token inválido o expirado. Por favor, intente de nuevo.');
        }
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $sort_order = intval($_POST['sort_order']);
            
            if (empty($name)) {
                throw new Exception('El nombre de la categoría es obligatorio');
            }
            
            // Verificar que el nombre sea único
            $sql = "SELECT id FROM product_categories WHERE name = ?" . (($hasTenantCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCategories && !$perDatabase) ? [$name, $tenantValue] : [$name]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una categoría con ese nombre');
            }
            
            if ($hasTenantCategories) {
                $stmt = $pdo->prepare("
                    INSERT INTO product_categories (tenant_id, name, description, sort_order) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$tenantValue, $name, $description, $sort_order]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO product_categories (name, description, sort_order) 
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
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('El nombre de la categoría es obligatorio');
            }
            
            // Verificar que el nombre sea único (excluyendo el registro actual)
            $sql = "SELECT id FROM product_categories WHERE name = ? AND id != ?" . (($hasTenantCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCategories && !$perDatabase) ? [$name, $id, $tenantValue] : [$name, $id]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una categoría con ese nombre');
            }
            
            $sql = "
                UPDATE product_categories 
                SET name = ?, description = ?, sort_order = ?, is_active = ?
                WHERE id = ?" . (($hasTenantCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [$name, $description, $sort_order, $is_active, $id];
            if ($hasTenantCategories && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            $mensaje = 'Categoría actualizada exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            
            // Verificar que no haya productos asociados
            $sql = "SELECT COUNT(*) as count FROM inventory_products WHERE category_id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('No se puede eliminar la categoría porque tiene productos asociados');
            }
            
            $sql = "DELETE FROM product_categories WHERE id = ?" . (($hasTenantCategories && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCategories && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            
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
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'with_products' => 0
];

try {
    $joinIp = ($hasTenantInventoryProducts && $hasTenantCategories && !$perDatabase)
        ? "LEFT JOIN inventory_products ip ON pc.id = ip.category_id AND ip.tenant_id = pc.tenant_id"
        : "LEFT JOIN inventory_products ip ON pc.id = ip.category_id";
    $whereTenant = ($hasTenantCategories && !$perDatabase) ? "WHERE pc.tenant_id = ?" : "";
    $stmt = $pdo->prepare("
        SELECT pc.*, 
               COUNT(ip.id) as product_count
        FROM product_categories pc
        $joinIp
        $whereTenant
        GROUP BY pc.id
        ORDER BY pc.sort_order ASC, pc.name ASC
    ");
    $stmt->execute(($hasTenantCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    $stats['total'] = count($categories);

    foreach ($categories as $category) {
        if ($category['is_active']) {
            $stats['active']++;
        } else {
            $stats['inactive']++;
        }
        
        if ($category['product_count'] > 0) {
            $stats['with_products']++;
        }
    }
} catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_CATEGORIES', 'product_categories', null);

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
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-tags me-2 text-primary no-theme"></i>Categorías</h2>
            <p class="text-muted mb-0">Gestiona las categorías del inventario</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                <i class="fas fa-plus me-2"></i>Nueva Categoría
            </button>
            <?php include __DIR__ . '/_subnav.php'; ?>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center" role="alert">
            <?php if ($tipo_mensaje === 'success'): ?>
                <i class="fas fa-check-circle me-2 fa-lg"></i>
            <?php elseif ($tipo_mensaje === 'danger'): ?>
                <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
            <?php else: ?>
                <i class="fas fa-info-circle me-2 fa-lg"></i>
            <?php endif; ?>
            <div class="flex-grow-1">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-layer-group fa-2x text-primary no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total']); ?></h5>
                        <small class="text-muted">Total Categorías</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-check-circle fa-2x text-success no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['active']); ?></h5>
                        <small class="text-muted">Activas</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                        <i class="fas fa-box-open fa-2x text-info no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['with_products']); ?></h5>
                        <small class="text-muted">Con Productos</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de Categorías -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0 fw-bold">Listado de Categorías</h5>
                <div class="input-group w-auto">
                    <span class="input-group-text bg-light border-end-0">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" class="form-control border-start-0 ps-0 bg-light" id="searchCategory" placeholder="Buscar categoría...">
                </div>
            </div>

            <?php if (empty($categories)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay categorías registradas</h5>
                    <p class="text-muted">Crea la primera categoría para organizar tus productos.</p>
                    <button type="button" class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                        <i class="fas fa-plus me-2"></i>Crear Primera Categoría
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="categoriesTable">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 rounded-start">Orden</th>
                                <th class="border-0">Nombre</th>
                                <th class="border-0">Descripción</th>
                                <th class="border-0">Productos</th>
                                <th class="border-0">Estado</th>
                                <th class="border-0 rounded-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo $category['sort_order']; ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($category['name']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($category['description']): ?>
                                            <span class="text-muted small"><?php echo htmlspecialchars($category['description']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info bg-opacity-10 text-info rounded-pill no-theme">
                                            <?php echo $category['product_count']; ?> productos
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($category['is_active']): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill no-theme">
                                                <i class="fas fa-check me-1 no-theme"></i>Activa
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill no-theme">
                                                <i class="fas fa-times me-1 no-theme"></i>Inactiva
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-light btn-sm text-primary" 
                                                    onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)"
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($category['product_count'] == 0): ?>
                                                <button type="button" class="btn btn-light btn-sm text-danger" 
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

<script>
document.getElementById('searchCategory')?.addEventListener('keyup', function() {
    const searchText = this.value.toLowerCase();
    const table = document.getElementById('categoriesTable');
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();
        if (text.includes(searchText)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});
</script>

<!-- Modal Crear Categoría -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="createCategoryModalLabel">
                        <i class="fas fa-plus me-2"></i>Nueva Categoría
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="create_name" class="form-label fw-bold">Nombre</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="create_name" name="name" required maxlength="100" placeholder="Ej: Electrónica">
                            <div class="invalid-feedback">
                                Por favor ingresa el nombre de la categoría.
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-bold">Descripción</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-align-left text-muted"></i>
                            </span>
                            <textarea class="form-control border-start-0 ps-0" id="create_description" name="description" rows="3" placeholder="Breve descripción de la categoría"></textarea>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="create_sort_order" class="form-label fw-bold">Orden de Visualización</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-sort-numeric-down text-muted"></i>
                            </span>
                            <input type="number" class="form-control border-start-0 ps-0" id="create_sort_order" name="sort_order" value="0" min="0">
                        </div>
                        <div class="form-text">Menor número aparece primero en las listas.</div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-save me-2"></i>Guardar Categoría
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Categoría -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="editCategoryModalLabel">
                        <i class="fas fa-edit me-2"></i>Editar Categoría
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">Nombre</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="edit_name" name="name" required maxlength="100">
                            <div class="invalid-feedback">
                                Por favor ingresa el nombre de la categoría.
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-bold">Descripción</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-align-left text-muted"></i>
                            </span>
                            <textarea class="form-control border-start-0 ps-0" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_sort_order" class="form-label fw-bold">Orden</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="fas fa-sort-numeric-down text-muted"></i>
                                </span>
                                <input type="number" class="form-control border-start-0 ps-0" id="edit_sort_order" name="sort_order" min="0">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                <label class="form-check-label fw-bold" for="edit_is_active">
                                    Categoría Activa
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-sync-alt me-2"></i>Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Eliminar Categoría -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header bg-danger text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="deleteCategoryModalLabel">
                        <i class="fas fa-trash-alt me-2"></i>Eliminar Categoría
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-danger bg-danger bg-opacity-10 border-0 rounded-3 mb-4">
                        <div class="d-flex">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger me-3"></i>
                            <div>
                                <h5 class="alert-heading fw-bold mb-1">¡Atención!</h5>
                                <p class="mb-0">Estás a punto de eliminar la categoría <strong id="delete_name"></strong>.</p>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted text-center mb-0">Esta acción no se puede deshacer y podría afectar a los informes históricos.</p>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">
                        <i class="fas fa-trash-alt me-2"></i>Sí, Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Validación de formularios Bootstrap
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>

<script>
function editCategory(category) {
    document.getElementById('edit_id').value = category.id;
    document.getElementById('edit_name').value = category.name;
    document.getElementById('edit_description').value = category.description || '';
    document.getElementById('edit_sort_order').value = category.sort_order;
    document.getElementById('edit_is_active').checked = category.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function deleteCategory(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    
    new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
}
</script>

</div>
</div>
</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
