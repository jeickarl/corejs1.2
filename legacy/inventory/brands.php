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
$hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
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
            
            if (empty($name)) {
                throw new Exception('El nombre de la marca es obligatorio');
            }
            
            // Verificar que el nombre sea único
            $sql = "SELECT id FROM brands WHERE name = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$name, $tenantValue] : [$name]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una marca con ese nombre');
            }
            
            if ($hasTenantBrands) {
                $stmt = $pdo->prepare("
                    INSERT INTO brands (tenant_id, name, description) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$tenantValue, $name, $description]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO brands (name, description) 
                    VALUES (?, ?)
                ");
                $stmt->execute([$name, $description]);
            }
            
            $mensaje = 'Marca creada exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($action === 'edit') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('El nombre de la marca es obligatorio');
            }
            
            // Verificar que el nombre sea único (excluyendo el registro actual)
            $sql = "SELECT id FROM brands WHERE name = ? AND id != ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$name, $id, $tenantValue] : [$name, $id]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una marca con ese nombre');
            }
            
            $sql = "
                UPDATE brands 
                SET name = ?, description = ?, is_active = ?
                WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [$name, $description, $is_active, $id];
            if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            $mensaje = 'Marca actualizada exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            
            // Verificar que no haya productos asociados
            $sql = "SELECT COUNT(*) as count FROM inventory_products WHERE brand_id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('No se puede eliminar la marca porque tiene productos asociados');
            }
            
            $sql = "DELETE FROM brands WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            
            $mensaje = 'Marca eliminada exitosamente';
            $tipo_mensaje = 'success';
        }
        
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Obtener marcas
$brands = [];
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'with_products' => 0
];

try {
    $joinIp = ($hasTenantInventoryProducts && $hasTenantBrands && !$perDatabase)
        ? "LEFT JOIN inventory_products ip ON b.id = ip.brand_id AND ip.tenant_id = b.tenant_id"
        : "LEFT JOIN inventory_products ip ON b.id = ip.brand_id";
    $whereTenant = ($hasTenantBrands && !$perDatabase) ? "WHERE b.tenant_id = ?" : "";
    $stmt = $pdo->prepare("
        SELECT b.*, 
               COUNT(ip.id) as product_count
        FROM brands b
        $joinIp
        $whereTenant
        GROUP BY b.id
        ORDER BY b.name ASC
    ");
    $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$tenantValue] : []);
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    $stats['total'] = count($brands);

    foreach ($brands as $brand) {
        if ($brand['is_active']) {
            $stats['active']++;
        } else {
            $stats['inactive']++;
        }
        
        if ($brand['product_count'] > 0) {
            $stats['with_products']++;
        }
    }
} catch (PDOException $e) {
    error_log("Error al obtener marcas: " . $e->getMessage());
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_BRANDS', 'brands', null);

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
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-copyright me-2 text-primary no-theme"></i>Marcas</h2>
            <p class="text-muted mb-0">Gestiona las marcas del inventario</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createBrandModal">
                <i class="fas fa-plus me-2"></i>Nueva Marca
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
                        <i class="fas fa-tags fa-2x text-primary no-theme"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo number_format($stats['total']); ?></h5>
                        <small class="text-muted">Total Marcas</small>
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

    <!-- Lista de Marcas -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0 fw-bold">Listado de Marcas</h5>
                <div class="input-group w-auto">
                    <span class="input-group-text bg-light border-end-0">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" class="form-control border-start-0 ps-0 bg-light" id="searchBrand" placeholder="Buscar marca...">
                </div>
            </div>

            <?php if (empty($brands)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay marcas registradas</h5>
                    <p class="text-muted">Crea la primera marca para organizar tus productos.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBrandModal">
                        <i class="fas fa-plus me-2"></i>Crear Primera Marca
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="brandsTable">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 rounded-start">Nombre</th>
                                <th class="border-0">Descripción</th>
                                <th class="border-0">Productos</th>
                                <th class="border-0">Estado</th>
                                <th class="border-0 rounded-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brands as $brand): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($brand['name']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($brand['description']): ?>
                                            <span class="text-muted small"><?php echo htmlspecialchars($brand['description']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info bg-opacity-10 text-info rounded-pill no-theme">
                                            <?php echo $brand['product_count']; ?> productos
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($brand['is_active']): ?>
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
                                                    onclick="editBrand(<?php echo htmlspecialchars(json_encode($brand)); ?>)"
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($brand['product_count'] == 0): ?>
                                                <button type="button" class="btn btn-light btn-sm text-danger" 
                                                        onclick="deleteBrand(<?php echo $brand['id']; ?>, '<?php echo htmlspecialchars($brand['name']); ?>')"
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
document.getElementById('searchBrand')?.addEventListener('keyup', function() {
    const searchText = this.value.toLowerCase();
    const table = document.getElementById('brandsTable');
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

// Client-side validation
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

<!-- Modal Crear Marca -->
<div class="modal fade" id="createBrandModal" tabindex="-1" aria-labelledby="createBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="createBrandModalLabel">
                        <i class="fas fa-plus me-2"></i>Nueva Marca
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="create_name" class="form-label fw-bold">
                            Nombre <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="create_name" name="name" required maxlength="100">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-bold">Descripción</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-align-left text-muted"></i>
                            </span>
                            <textarea class="form-control border-start-0 ps-0" id="create_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-pill">
                        <i class="fas fa-save me-2"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Marca -->
<div class="modal fade" id="editBrandModal" tabindex="-1" aria-labelledby="editBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="editBrandModalLabel">
                        <i class="fas fa-edit me-2"></i>Editar Marca
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">
                            Nombre <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-tag text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="edit_name" name="name" required maxlength="100">
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
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" checked>
                            <label class="form-check-label" for="edit_is_active">
                                Marca activa
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning rounded-pill">
                        <i class="fas fa-save me-2"></i>Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Eliminar Marca -->
<div class="modal fade" id="deleteBrandModal" tabindex="-1" aria-labelledby="deleteBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                    <h5 class="modal-title" id="deleteBrandModalLabel">
                        <i class="fas fa-trash me-2"></i>Eliminar Marca
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                        <div class="flex-grow-1">
                            <strong>¿Estás seguro?</strong>
                            <div class="small">Esta acción no se puede deshacer.</div>
                        </div>
                    </div>
                    <p>Se eliminará la marca <strong id="delete_name"></strong>.</p>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill">
                        <i class="fas fa-trash me-2"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editBrand(brand) {
    document.getElementById('edit_id').value = brand.id;
    document.getElementById('edit_name').value = brand.name;
    document.getElementById('edit_description').value = brand.description || '';
    document.getElementById('edit_is_active').checked = brand.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editBrandModal')).show();
}

function deleteBrand(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    
    new bootstrap.Modal(document.getElementById('deleteBrandModal')).show();
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
