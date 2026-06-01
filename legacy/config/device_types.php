<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/index.php");
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_enhancements.php';
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenant_id = getCurrentTenantId();
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantDeviceTypes = hasTenantColumnCached($pdo, 'device_types');
$hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');

$errors = [];
$success = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar permisos de administrador
    if (!isAdminSession()) {
        $errors[] = "Acceso denegado: Se requieren permisos de administrador";
    }
    else {
        $csrf = $_POST['csrf_token'] ?? '';
        $csrfOk = false;
        if ($csrf !== '') {
            if (class_exists('SecurityEnhancements') && SecurityEnhancements::verifyCSRFToken($csrf)) {
                $csrfOk = true;
            } else {
                $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
                if ($sessionCsrf !== '' && hash_equals($sessionCsrf, (string)$csrf)) {
                    $csrfOk = true;
                }
            }
        }
        if (!$csrfOk) {
            $errors[] = "Token de seguridad inválido. Recarga la página e inténtalo de nuevo.";
        } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fas fa-microchip');

            if (empty($name)) {
                $errors[] = "El nombre del tipo de dispositivo es obligatorio.";
            }
            else {
                try {
                    if ($hasTenantDeviceTypes && !$perDatabase) {
                        $stmt = $pdo->prepare("INSERT INTO device_types (tenant_id, name, description, icon) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$tenantValue, $name, $description, $icon]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO device_types (name, description, icon) VALUES (?, ?, ?)");
                        $stmt->execute([$name, $description, $icon]);
                    }
                    $success = "Tipo de dispositivo creado exitosamente.";
                }
                catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $errors[] = "Ya existe un tipo de dispositivo con ese nombre.";
                    }
                    else {
                        $errors[] = "Error al crear el tipo de dispositivo: " . $e->getMessage();
                    }
                }
            }
        }
        elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? 'fas fa-microchip');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                $errors[] = "El nombre del tipo de dispositivo es obligatorio.";
            }
            else {
                try {
                    $sql = "UPDATE device_types SET name = ?, description = ?, icon = ?, is_active = ? WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $params = [$name, $description, $icon, $is_active, $id];
                    if ($hasTenantDeviceTypes && !$perDatabase) { $params[] = $tenantValue; }
                    $stmt->execute($params);
                    $success = "Tipo de dispositivo actualizado exitosamente.";
                }
                catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $errors[] = "Ya existe un tipo de dispositivo con ese nombre.";
                    }
                    else {
                        $errors[] = "Error al actualizar el tipo de dispositivo: " . $e->getMessage();
                    }
                }
            }
        }
        elseif ($action === 'update_order') {
            $order_data = $_POST['order'] ?? [];

            if (!empty($order_data)) {
                try {
                    $sql = "UPDATE device_types SET sort_order = ? WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    foreach ($order_data as $position => $id) {
                        $params = [$position + 1, $id];
                        if ($hasTenantDeviceTypes && !$perDatabase) { $params[] = $tenantValue; }
                        $stmt->execute($params);
                    }
                    $success = "Orden de tipos de dispositivo actualizado exitosamente.";
                }
                catch (PDOException $e) {
                    $errors[] = "Error al actualizar el orden: " . $e->getMessage();
                }
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';

            try {
                // Verificar si hay órdenes usando este tipo dentro del tenant actual
                $sql = "SELECT COUNT(*) FROM work_orders WHERE device_type_id = ?" . (($hasTenantWorkOrders && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantWorkOrders && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                $count = $stmt->fetchColumn();

                if ($count > 0) {
                    $errors[] = "No se puede eliminar este tipo porque hay $count orden(es) que lo utilizan.";
                }
                else {
                    $sql = "DELETE FROM device_types WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(($hasTenantDeviceTypes && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                    $success = "Tipo de dispositivo eliminado exitosamente.";
                }
            }
            catch (PDOException $e) {
                $errors[] = "Error al eliminar el tipo de dispositivo: " . $e->getMessage();
            }
        }    }
    }
}

// Obtener tipos de dispositivo
try {
    $hasActive = false;
    $hasVisible = false;
    $hasSortOrder = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'");
        $hasActive = $c && $c->rowCount() > 0;
    }
    catch (PDOException $e) {
    }
    try {
        $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_visible'");
        $hasVisible = $c && $c->rowCount() > 0;
    }
    catch (PDOException $e) {
    }
    try {
        $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'sort_order'");
        $hasSortOrder = $c && $c->rowCount() > 0;
    }
    catch (PDOException $e) {
    }
    $where = [];
    if ($hasTenantDeviceTypes && !$perDatabase) {
        $where[] = "tenant_id = ?";
    }
    if ($hasActive) {
        $where[] = "is_active = 1";
    }
    if ($hasVisible) {
        $where[] = "is_visible = 1";
    }
    $orderBy = $hasSortOrder ? "ORDER BY sort_order, name" : "ORDER BY name";
    $sql = "SELECT * FROM device_types" . (!empty($where) ? " WHERE " . implode(" AND ", $where) : "") . " $orderBy";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantDeviceTypes && !$perDatabase) ? [$tenantValue] : []);
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $device_types = [];
    $errors[] = "Error al cargar los tipos de dispositivo: " . $e->getMessage();
}

// Iconos disponibles
$available_icons = [
    'fas fa-laptop' => 'Portátil',
    'fas fa-desktop' => 'PC de Escritorio',
    'fas fa-mobile-alt' => 'Celular',
    'fas fa-tablet-alt' => 'Tablet',
    'fas fa-microchip' => 'Motherboard',
    'fas fa-tv' => 'TV/Todo en Uno',
    'fas fa-gamepad' => 'Consola',
    'fas fa-print' => 'Impresora',
    'fas fa-wifi' => 'Router',
    'fas fa-server' => 'Servidor',
    'fas fa-keyboard' => 'Teclado',
    'fas fa-mouse' => 'Mouse',
    'fas fa-headphones' => 'Audífonos',
    'fas fa-camera' => 'Cámara',
    'fas fa-speaker' => 'Altavoz',
    'fas fa-battery-full' => 'Batería',
    'fas fa-charging-station' => 'Cargador',
    'fas fa-tools' => 'Herramientas',
    'fas fa-cog' => 'Configuración',
    'fas fa-microchip' => 'Otros'
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<style>
/* Diseño Responsivo para Tabla de Tipos de Dispositivo */
@media (max-width: 767.98px) {
    #sortableTable thead { display: none; }
    #sortableTable, #sortableTable tbody, #sortableTable tr, #sortableTable td { display: block; width: 100%; }
    #sortableTable tr { margin-bottom: 1rem; background-color: #fff; border: 1px solid rgba(0,0,0,0.1); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); position: relative; }
    #sortableTable td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 0.75rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.05); text-align: right; }
    #sortableTable td::before { content: attr(data-label); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; width: 35%; flex-shrink: 0; margin-right: 1rem; text-align: left; }
    #sortableTable td:last-child { border-bottom: none; background-color: #f8f9fa; font-weight: bold; border-radius: 0 0 0.75rem 0.75rem; justify-content: flex-end; }
}

.main-content {
    background-color: #f8f9fa;
    min-height: 100vh;
    padding: 2rem 0;
}

.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    background: white;
    margin-bottom: 2rem;
}

.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px 15px 0 0 !important;
    padding: 1.5rem 2rem;
    border: none;
}

.card-body {
    padding: 2rem;
}

.btn {
    border-radius: 10px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);
    color: white;
}

.table {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: none;
    font-weight: 600;
    color: #495057;
    padding: 1rem;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-top: 1px solid #f8f9fa;
}

.badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 500;
}

.badge-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
}

.badge-danger {
    background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.icon-preview {
    font-size: 1.2rem;
    margin-right: 0.5rem;
}

.modal-content {
    border-radius: 15px;
    border: none;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px 15px 0 0;
    border: none;
}

.modal-footer {
    border-top: 1px solid #e9ecef;
    padding: 1.5rem;
}

.alert {
    border: none;
    border-radius: 10px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border-left: 4px solid #17a2b8;
}

/* Estilos para ordenamiento mejorados */
.sortable-row {
    transition: all 0.3s ease;
    cursor: grab;
    user-select: none;
}

.sortable-row:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.sortable-row.dragging {
    opacity: 0.5;
    transform: rotate(2deg) scale(1.02);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    position: relative;
}

.sortable-row.drag-over {
    border-top: 3px solid #007bff;
    background-color: #e3f2fd;
    transform: translateY(2px);
}

.sortable-row.draggable {
    cursor: grab;
}

.sortable-row.draggable:active {
    cursor: grabbing;
}

.drag-handle {
    color: #6c757d;
    transition: all 0.3s ease;
    cursor: grab;
    font-weight: bold;
    user-select: none;
    font-size: 1.2em;
    padding: 5px;
}

.sortable-row:hover .drag-handle {
    color: #007bff;
    transform: scale(1.2);
}

.drag-handle:active {
    cursor: grabbing;
}

/* Animación para el botón de guardar */
#saveOrderBtn {
    transition: all 0.3s ease;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

#saveOrderBtn:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
}
</style>

<div class="main-content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">
                        <i class="fas fa-microchip me-2"></i>Tipos de Dispositivo
                    </h4>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#createTypeModal">
                        <i class="fas fa-plus me-2"></i>Nuevo Tipo
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php
    endforeach; ?>
                        </ul>
                    </div>
                <?php
endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    </div>
                <?php
endif; ?>

                <!-- Instrucciones de ordenamiento -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Ordenamiento:</strong> Puedes arrastrar y soltar las filas para cambiar el orden de los tipos de dispositivo. 
                    Haz clic en el ícono de "grip" (⋮⋮) o arrastra cualquier parte de la fila para reordenar.
                </div>

                <div class="table-responsive">
                    <table class="table table-hover" id="sortableTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Orden</th>
                                <th>Icono</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="sortableTbody">
                            <?php if (empty($device_types)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
                                        <i class="fas fa-info-circle me-2"></i>No hay tipos de dispositivo configurados
                                    </td>
                                </tr>
                            <?php
else: ?>
                                <?php foreach ($device_types as $type): ?>
                                    <tr data-id="<?php echo $type['id']; ?>" class="sortable-row">
                                        <td class="text-center text-md-start" data-label="Orden">
                                            <span class="drag-handle" title="Arrastra para cambiar el orden">⋮⋮</span>
                                        </td>
                                        <td data-label="Icono" class="text-end text-md-start">
                                            <i class="<?php echo htmlspecialchars($type['icon']); ?> icon-preview"></i>
                                        </td>
                                        <td data-label="Nombre" class="text-end text-md-start">
                                            <strong><?php echo htmlspecialchars($type['name']); ?></strong>
                                        </td>
                                        <td data-label="Descripción" class="text-end text-md-start">
                                            <?php echo htmlspecialchars($type['description'] ?: 'Sin descripción'); ?>
                                        </td>
                                        <td data-label="Estado" class="text-end text-md-start">
                                            <?php if ($type['is_active']): ?>
                                                <span class="badge badge-success">Activo</span>
                                            <?php
        else: ?>
                                                <span class="badge badge-danger">Inactivo</span>
                                            <?php
        endif; ?>
                                        </td>
                                        <td data-label="Acciones" class="text-end text-md-start">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-warning btn-sm" 
                                                        onclick="editType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['name']); ?>', '<?php echo htmlspecialchars($type['description']); ?>', '<?php echo htmlspecialchars($type['icon']); ?>', <?php echo $type['is_active']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="deleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php
    endforeach; ?>
                            <?php
endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Botón para guardar el orden -->
                <div class="mt-3 text-end">
                    <button type="button" class="btn btn-success" id="saveOrderBtn" style="display: none;">
                        <i class="fas fa-save me-2"></i>Guardar Orden
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para crear nuevo tipo -->
<div class="modal fade" id="createTypeModal" tabindex="-1" aria-labelledby="createTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createTypeModalLabel">
                    <i class="fas fa-plus me-2"></i>Nuevo Tipo de Dispositivo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken(), ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required maxlength="100" placeholder="Ej: Portátil, Celular, etc.">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Descripción</label>
                        <textarea class="form-control" id="description" name="description" rows="3" maxlength="255" placeholder="Descripción del tipo de dispositivo"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="icon" class="form-label">Icono</label>
                        <select class="form-select" id="icon" name="icon">
                            <?php foreach ($available_icons as $icon => $label): ?>
                                <option value="<?php echo $icon; ?>"><?php echo $label; ?> (<?php echo $icon; ?>)</option>
                            <?php
endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal para editar tipo -->
<div class="modal fade" id="editTypeModal" tabindex="-1" aria-labelledby="editTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTypeModalLabel">
                    <i class="fas fa-edit me-2"></i>Editar Tipo de Dispositivo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken(), ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Descripción</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3" maxlength="255"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_icon" class="form-label">Icono</label>
                        <select class="form-select" id="edit_icon" name="icon">
                            <?php foreach ($available_icons as $icon => $label): ?>
                                <option value="<?php echo $icon; ?>"><?php echo $label; ?> (<?php echo $icon; ?>)</option>
                            <?php
endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                            <label class="form-check-label" for="edit_is_active">
                                Tipo activo
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal para confirmar eliminación -->
<div class="modal fade" id="deleteTypeModal" tabindex="-1" aria-labelledby="deleteTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteTypeModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>¿Estás seguro de que quieres eliminar el tipo de dispositivo "<strong id="delete_type_name"></strong>"?</p>
                <p class="text-danger"><small>Esta acción no se puede deshacer.</small></p>
            </div>
            <form method="POST">
                <div class="modal-footer">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken(), ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editType(id, name, description, icon, isActive) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_icon').value = icon;
    document.getElementById('edit_is_active').checked = isActive == 1;
    
    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
}

function deleteType(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_type_name').textContent = name;
    
    new bootstrap.Modal(document.getElementById('deleteTypeModal')).show();
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });
    
    // Inicializar ordenamiento
    initSortable();
});

// Funcionalidad de ordenamiento mejorada
function initSortable() {
    const tbody = document.getElementById('sortableTbody');
    const saveBtn = document.getElementById('saveOrderBtn');
    let originalOrder = [];
    let draggedElement = null;
    
    if (tbody) {
        console.log('🔧 Inicializando ordenamiento...');
        
        // Guardar orden original
        const rows = tbody.querySelectorAll('.sortable-row');
        console.log('📋 Filas encontradas:', rows.length);
        
        rows.forEach(row => {
            originalOrder.push(row.dataset.id);
        });
        
        // Hacer las filas arrastrables
        rows.forEach(row => {
            row.draggable = true;
            row.classList.add('draggable');
            
            // Prevenir que los botones interfieran con el drag
            const buttons = row.querySelectorAll('button, .btn');
            buttons.forEach(button => {
                button.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                });
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });
            
            // Evento de inicio de arrastre
            row.addEventListener('dragstart', function(e) {
                // Verificar si el clic fue en un botón
                if (e.target.closest('button, .btn')) {
                    e.preventDefault();
                    return;
                }
                
                console.log('🚀 Iniciando arrastre de fila:', this.dataset.id);
                draggedElement = this;
                e.dataTransfer.setData('text/plain', this.dataset.id);
                this.classList.add('dragging');
                this.style.opacity = '0.5';
                this.style.transform = 'rotate(2deg)';
            });
            
            // Evento de fin de arrastre
            row.addEventListener('dragend', function() {
                console.log('🏁 Finalizando arrastre de fila:', this.dataset.id);
                this.classList.remove('dragging');
                this.style.opacity = '1';
                this.style.transform = 'none';
                draggedElement = null;
            });
            
            // Evento de pasar sobre otra fila
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                
                // Remover clase de todas las filas
                rows.forEach(r => r.classList.remove('drag-over'));
                
                // Agregar clase a la fila actual
                if (draggedElement !== this) {
                    this.classList.add('drag-over');
                }
            });
            
            // Evento de salir de una fila
            row.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });
            
            // Evento de soltar
            row.addEventListener('drop', function(e) {
                e.preventDefault();
                console.log('📥 Soltando en fila:', this.dataset.id);
                
                const draggedId = e.dataTransfer.getData('text/plain');
                const draggedRow = document.querySelector(`[data-id="${draggedId}"]`);
                const targetRow = this;
                
                if (draggedRow && draggedRow !== targetRow) {
                    console.log('🔄 Moviendo fila:', draggedId, 'a posición de:', targetRow.dataset.id);
                    
                    const tbody = targetRow.parentNode;
                    const draggedIndex = Array.from(tbody.children).indexOf(draggedRow);
                    const targetIndex = Array.from(tbody.children).indexOf(targetRow);
                    
                    if (draggedIndex < targetIndex) {
                        tbody.insertBefore(draggedRow, targetRow.nextSibling);
                    } else {
                        tbody.insertBefore(draggedRow, targetRow);
                    }
                    
                    // Mostrar botón de guardar
                    saveBtn.style.display = 'inline-block';
                    saveBtn.classList.add('btn-success');
                    
                    console.log('✅ Orden actualizado, botón de guardar mostrado');
                }
                
                // Limpiar clases
                rows.forEach(r => r.classList.remove('drag-over'));
            });
        });
        
        // Evento para guardar el orden
        saveBtn.addEventListener('click', function() {
            console.log('💾 Guardando nuevo orden...');
            
            const newOrder = [];
            const rows = tbody.querySelectorAll('.sortable-row');
            rows.forEach(row => {
                newOrder.push(row.dataset.id);
            });
            
            console.log('📋 Orden original:', originalOrder);
            console.log('📋 Nuevo orden:', newOrder);
            
            // Verificar si el orden cambió
            if (JSON.stringify(originalOrder) !== JSON.stringify(newOrder)) {
                saveOrder(newOrder);
            } else {
                console.log('ℹ️ No hay cambios en el orden');
                saveBtn.style.display = 'none';
            }
        });
    } else {
        console.error('❌ No se encontró el elemento tbody con id sortableTbody');
    }
}

function saveOrder(order) {
    console.log('💾 Iniciando guardado del orden:', order);
    
    const saveBtn = document.getElementById('saveOrderBtn');
    const originalText = saveBtn.innerHTML;
    
    // Cambiar el botón a estado de carga
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
    saveBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'update_order');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="csrf_token"]')?.value
        || '';
    if (csrfToken) formData.append('csrf_token', csrfToken);
    order.forEach((id, index) => {
        formData.append(`order[${index}]`, id);
    });
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📥 Respuesta del servidor:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(data => {
        console.log('✅ Orden guardado exitosamente');
        
        // Mostrar mensaje de éxito
        saveBtn.innerHTML = '<i class="fas fa-check me-2"></i>¡Guardado!';
        saveBtn.classList.remove('btn-success');
        saveBtn.classList.add('btn-success');
        
        // Recargar la página después de 1 segundo
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    })
    .catch(error => {
        console.error('❌ Error al guardar el orden:', error);
        
        // Restaurar el botón
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
        
        if (typeof showError === 'function') {
            showError('Error al guardar el orden. ' + (error && error.message ? ('Detalles: ' + error.message) : ''));
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error al guardar el orden. ' + (error && error.message ? ('Detalles: ' + error.message) : ''), timer: 6000, showConfirmButton: false });
        } else {
            alert('Error al guardar el orden. Por favor, intenta de nuevo.\n\nDetalles: ' + error.message);
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
