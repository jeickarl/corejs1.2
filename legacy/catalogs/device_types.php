<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

$pdo = db();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;

// Función para obtener todos los tipos de equipo
function getDeviceTypes() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
    $sql = "SELECT * FROM device_types" . (($hasTenantDeviceTypes && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY sort_order, name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantDeviceTypes && !$perDatabase) ? [$tenantValue] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verificar permisos de administrador para cualquier modificación
    if (!isAdminSession()) {
        echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
    
    try {
        switch ($action) {
            case 'create':
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $is_visible = isset($_POST['is_visible']) ? 1 : 0;
                
                if (empty($name)) {
                    throw new Exception('El nombre es obligatorio');
                }
                
                if ($hasTenantDeviceTypes) {
                    $stmt = $pdo->prepare("INSERT INTO device_types (tenant_id, name, description, is_visible) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$tenantValue, $name, $description, $is_visible]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO device_types (name, description, is_visible) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $description, $is_visible]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Tipo de equipo creado exitosamente']);
                break;
                
            case 'update':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $is_visible = isset($_POST['is_visible']) ? 1 : 0;
                
                if (empty($name)) {
                    throw new Exception('El nombre es obligatorio');
                }
                
                $sql = "UPDATE device_types SET name = ?, description = ?, is_visible = ? WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $params = [$name, $description, $is_visible, $id];
                if ($hasTenantDeviceTypes && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'message' => 'Tipo de equipo actualizado exitosamente']);
                break;
                
            case 'delete':
                $id = $_POST['id'];
                
                $sql = "DELETE FROM device_types WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantDeviceTypes && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                
                echo json_encode(['success' => true, 'message' => 'Tipo de equipo eliminado exitosamente']);
                break;
                
            case 'toggle_visibility':
                $id = $_POST['id'];
                $is_visible = $_POST['is_visible'];
                
                $sql = "UPDATE device_types SET is_visible = ? WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $params = [$is_visible, $id];
                if ($hasTenantDeviceTypes && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'message' => 'Visibilidad actualizada exitosamente']);
                break;
                
            case 'update_order':
                $order_data = $_POST['order'] ?? [];
                
                if (!empty($order_data)) {
                    $stmt = $pdo->prepare("UPDATE device_types SET sort_order = ? WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : ""));
                    foreach ($order_data as $position => $id) {
                        $params = [$position + 1, $id];
                        if ($hasTenantDeviceTypes && !$perDatabase) { $params[] = $tenantValue; }
                        $stmt->execute($params);
                    }
                    echo json_encode(['success' => true, 'message' => 'Orden actualizado exitosamente']);
                } else {
                    throw new Exception('No se recibieron datos de orden');
                }
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$deviceTypes = getDeviceTypes();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tipos de Equipo</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 1rem;
        }
        .table th {
            background-color: #f8f9fa;
            border-top: none;
            font-weight: 600;
        }
        .table thead th:first-child {
            border-top-left-radius: 1rem;
            border-bottom-left-radius: 1rem;
        }
        .table thead th:last-child {
            border-top-right-radius: 1rem;
            border-bottom-right-radius: 1rem;
        }
        .btn-action {
            padding: 0.25rem 0.5rem;
            margin: 0 0.125rem;
        }
        
        .visibility-toggle {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .visibility-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .visibility-toggle:active {
            transform: scale(0.95);
        }
        
        /* Estilos para ordenamiento */
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
</head>
<body>
    <div class="container-fluid p-4">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                        <h5 class="mb-0 text-dark fw-bold"><i class="fas fa-laptop me-2"></i>Gestión de Tipos de Equipo</h5>
                        <button class="btn btn-dark rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#deviceTypeModal" onclick="openCreateModal()">
                            <i class="fas fa-plus me-2"></i>Nuevo Tipo
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="border-0 ps-4 text-center" style="width: 50px; border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Orden</th>
                                        <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Nombre</th>
                                        <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Visible en Órdenes</th>
                                        <th class="border-0 pe-4 text-end" style="border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="sortableTbody">
                                    <?php foreach ($deviceTypes as $type): 
                                        // Determinar icono basado en el nombre
                                        $lowerName = strtolower($type['name']);
                                        $icon = 'fa-microchip'; // Default
                                        if (strpos($lowerName, 'laptop') !== false || strpos($lowerName, 'portatil') !== false) { $icon = 'fa-laptop'; }
                                        elseif (strpos($lowerName, 'pc') !== false || strpos($lowerName, 'computador') !== false || strpos($lowerName, 'desktop') !== false) { $icon = 'fa-desktop'; }
                                        elseif (strpos($lowerName, 'celular') !== false || strpos($lowerName, 'movil') !== false || strpos($lowerName, 'phone') !== false) { $icon = 'fa-mobile-alt'; }
                                        elseif (strpos($lowerName, 'tablet') !== false) { $icon = 'fa-tablet-alt'; }
                                        elseif (strpos($lowerName, 'impresora') !== false || strpos($lowerName, 'printer') !== false) { $icon = 'fa-print'; }
                                        elseif (strpos($lowerName, 'consola') !== false || strpos($lowerName, 'game') !== false) { $icon = 'fa-gamepad'; }
                                        elseif (strpos($lowerName, 'monitor') !== false || strpos($lowerName, 'pantalla') !== false) { $icon = 'fa-tv'; }
                                        elseif (strpos($lowerName, 'red') !== false || strpos($lowerName, 'wifi') !== false || strpos($lowerName, 'router') !== false) { $icon = 'fa-wifi'; }
                                        elseif (strpos($lowerName, 'audio') !== false || strpos($lowerName, 'parlante') !== false || strpos($lowerName, 'bocina') !== false) { $icon = 'fa-volume-up'; }
                                        elseif (strpos($lowerName, 'camara') !== false) { $icon = 'fa-camera'; }
                                    ?>
                                    <tr data-id="<?= $type['id'] ?>" class="sortable-row">
                                        <td class="text-center ps-4">
                                            <span class="drag-handle text-muted" title="Arrastra para cambiar el orden" style="cursor: grab; font-size: 1.2rem;">⋮⋮</span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 45px; height: 45px;">
                                                    <i class="fas <?= $icon ?> text-dark fa-lg"></i>
                                                </div>
                                                <div>
                                                    <span class="fw-bold text-dark d-block"><?= htmlspecialchars($type['name']) ?></span>
                                                    <?php if (!empty($type['description'])): ?>
                                                        <small class="text-muted"><?= htmlspecialchars(substr($type['description'], 0, 50)) . (strlen($type['description']) > 50 ? '...' : '') ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($type['is_visible']): ?>
                                                <span class="badge rounded-pill bg-success bg-opacity-10 text-success px-3 py-2 border border-success border-opacity-10 visibility-toggle" 
                                                      style="cursor: pointer;" 
                                                      onclick="toggleVisibility(<?= $type['id'] ?>, <?= $type['is_visible'] ?>)">
                                                    <i class="fas fa-eye me-1"></i> Visible
                                                </span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary px-3 py-2 border border-secondary border-opacity-10 visibility-toggle" 
                                                      style="cursor: pointer;" 
                                                      onclick="toggleVisibility(<?= $type['id'] ?>, <?= $type['is_visible'] ?>)">
                                                    <i class="fas fa-eye-slash me-1"></i> Oculto
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <div class="btn-group shadow-sm" role="group">
                                                <button class="btn btn-sm btn-outline-dark rounded-start btn-action" 
                                                        onclick='openEditModal(<?= htmlspecialchars(json_encode($type), ENT_QUOTES) ?>)' 
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger rounded-end btn-action" 
                                                        onclick='confirmDelete(<?= $type['id'] ?>, <?= json_encode($type['name']) ?>)' 
                                                        title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($deviceTypes)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-laptop fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No hay tipos de equipo registrados</p>
                                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#deviceTypeModal" onclick="openCreateModal()">
                                    <i class="fas fa-plus"></i> Crear Primer Tipo
                                </button>
                            </div>
                            <?php endif; ?>
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
    </div>

    <!-- Modal para Crear/Editar Tipo de Equipo -->
    <div class="modal fade" id="deviceTypeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-plus me-2"></i>Nuevo Tipo de Equipo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="deviceTypeForm">
                    <div class="modal-body bg-light">
                        <input type="hidden" id="deviceTypeId" name="id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <label for="deviceTypeName" class="form-label fw-bold text-dark">Nombre del Tipo <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="deviceTypeName" name="name" required maxlength="100" style="border-radius: 0.5rem;">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="deviceTypeDescription" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="deviceTypeDescription" name="description" rows="3" maxlength="500" style="border-radius: 0.5rem;"></textarea>
                                    <div class="form-text">Opcional. Máximo 500 caracteres.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="deviceTypeVisible" name="is_visible" checked>
                                        <label class="form-check-label" for="deviceTypeVisible">
                                            <i class="fas fa-eye me-1"></i> Visible al crear nuevas órdenes
                                        </label>
                                    </div>
                                    <div class="form-text ms-4">Si está marcado, este tipo de equipo aparecerá en el formulario de nueva orden</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm" id="submitBtn"><i class="fas fa-save me-2"></i>Crear Tipo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/utils.js"></script>
    <script>
        let deleteTypeId = null;

        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Nuevo Tipo de Equipo';
            document.getElementById('submitBtn').textContent = 'Crear Tipo';
            document.getElementById('action').value = 'create';
            document.getElementById('deviceTypeForm').reset();
            document.getElementById('deviceTypeId').value = '';
        }

        function openEditModal(type) {
            document.getElementById('modalTitle').textContent = 'Editar Tipo de Equipo';
            document.getElementById('submitBtn').textContent = 'Actualizar Tipo';
            document.getElementById('action').value = 'update';
            document.getElementById('deviceTypeId').value = type.id;
            document.getElementById('deviceTypeName').value = type.name;
            document.getElementById('deviceTypeDescription').value = type.description || '';
            document.getElementById('deviceTypeVisible').checked = type.is_visible == 1;
            
            new bootstrap.Modal(document.getElementById('deviceTypeModal')).show();
        }
        
        function toggleVisibility(id, currentVisibility) {
            const newVisibility = currentVisibility ? 0 : 1;
            const formData = new FormData();
            formData.append('action', 'toggle_visibility');
            formData.append('id', id);
            formData.append('is_visible', newVisibility);
            
            fetch('device_types.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error de conexión al cambiar la visibilidad');
            });
        }

        function confirmDelete(id, name) {
            showConfirm(`¿Estás seguro de eliminar el tipo de equipo "${name}"? Esta acción no se puede deshacer.`, function() {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);

                fetch('device_types.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data.message);
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showError(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error al eliminar el tipo de equipo');
                });
            });
        }

        document.getElementById('deviceTypeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('device_types.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    const modal = bootstrap.Modal.getInstance(document.getElementById('deviceTypeModal'));
                    modal.hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error al procesar la solicitud');
            });
        });
        
        // Inicializar ordenamiento cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            initSortable();
        });
        
        // Funcionalidad de ordenamiento
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
                    const buttons = row.querySelectorAll('button, .btn, .badge');
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
                        if (e.target.closest('button, .btn, .badge')) {
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
            order.forEach((id, index) => {
                formData.append(`order[${index}]`, id);
            });
            
            fetch('device_types.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('✅ Orden guardado exitosamente');
                
                if (data.success) {
                    // Mostrar mensaje de éxito
                    saveBtn.innerHTML = '<i class="fas fa-check me-2"></i>¡Guardado!';
                    saveBtn.classList.remove('btn-success');
                    saveBtn.classList.add('btn-success');
                    
                    // Recargar la página después de 1 segundo
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    throw new Error(data.message);
                }
            })
            .catch(error => {
                console.error('❌ Error al guardar el orden:', error);
                
                // Restaurar el botón
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                
                // Mostrar alerta de error
                if (typeof showError === 'function') {
                    showError('Error al guardar el orden. Por favor, intenta de nuevo.\n\nDetalles: ' + error.message);
                } else {
                    alert('Error al guardar el orden. Por favor, intenta de nuevo.\n\nDetalles: ' + error.message);
                }
            });
        }
    </script>
</body>
</html>
