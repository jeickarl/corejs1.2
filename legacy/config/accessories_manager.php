<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_enhancements.php';
requireAuth();
$pdo = db();

$success = '';
$error = '';
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'equipment_accessories') : false;
$hasTenantOrderAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_equipment_accessories') : false;
$csrf_token = class_exists('SecurityEnhancements') ? SecurityEnhancements::generateCSRFToken() : ($_SESSION['csrf_token'] ?? '');

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'is_active'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    $stmt2 = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'sort_order'");
    if ($stmt2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (function_exists('ensureAccessoriesTenant')) {
        ensureAccessoriesTenant($pdo, $tenantValue);
    }
    if (function_exists('normalizeAccessoriesTenants')) {
        normalizeAccessoriesTenants($pdo, $tenantValue);
    }
    if (function_exists('ensureDefaultAccessories')) {
        ensureDefaultAccessories($pdo, $tenantValue);
    }
} catch (Exception $e) {}

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si es una solicitud AJAX pura (fetch), devolver JSON
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $csrf = $_POST['csrf_token'] ?? '';
    $csrfOk = $csrf !== '' && class_exists('SecurityEnhancements') && SecurityEnhancements::verifyCSRFToken($csrf);
    if (!$csrfOk) {
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
            exit;
        } else {
            $error = "Token de seguridad inválido. Recarga la página e inténtalo de nuevo.";
        }
    }
    
    // Verificar permisos de administrador
    if (!$csrfOk) {
    } elseif (!isAdminSession()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        } else {
            $error = "Acceso denegado: Se requieren permisos de administrador";
        }
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if (!empty($name)) {
                try {
                    // Verificar si ya existe
                    $sql = "SELECT id FROM equipment_accessories WHERE name = ?" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(($hasTenantAccessories && !$perDatabase) ? [$name, $tenantValue] : [$name]);
                    if ($stmt->fetch()) {
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Ya existe un accesorio con ese nombre']);
                            exit;
                        } else {
                            $error = "Ya existe un accesorio con ese nombre";
                        }
                    } else {
                        if ($hasTenantAccessories) {
                            $stmt = $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, is_active, sort_order, category) VALUES (?, ?, 1, 0, 'general')");
                            $stmt->execute([$tenantValue, $name]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO equipment_accessories (name, is_active, sort_order, category) VALUES (?, 1, 0, 'general')");
                            $stmt->execute([$name]);
                        }
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => 'Accesorio agregado exitosamente']);
                            exit;
                        } else {
                            $success = "Accesorio agregado exitosamente";
                        }
                    }
                } catch (PDOException $e) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                        exit;
                    } else {
                        $error = "Error al agregar el accesorio: " . $e->getMessage();
                    }
                }
            } else {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'El nombre es requerido']);
                    exit;
                } else {
                    $error = "El nombre del accesorio es requerido";
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            
            if ($id > 0 && !empty($name)) {
                try {
                    $sql = "UPDATE equipment_accessories SET name = ? WHERE id = ?" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $params = [$name, $id];
                    if ($hasTenantAccessories && !$perDatabase) { $params[] = $tenantValue; }
                    $stmt->execute($params);
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'Accesorio actualizado exitosamente']);
                        exit;
                    } else {
                        $success = "Accesorio actualizado exitosamente";
                    }
                } catch (PDOException $e) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                        exit;
                    } else {
                        $error = "Error al actualizar: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    // Verificar si está siendo usado
                    if ($hasTenantOrderAccessories && !$perDatabase) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_equipment_accessories WHERE accessory_id = ? AND tenant_id = ?");
                        $stmt->execute([$id, $tenantValue]);
                    } else {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_equipment_accessories WHERE accessory_id = ?");
                        $stmt->execute([$id]);
                    }
                    $count = $stmt->fetchColumn();
                    
                    if ($count > 0) {
                        $sql = "UPDATE equipment_accessories SET is_active = 0 WHERE id = ?" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : "");
                        $params = [$id];
                        if ($hasTenantAccessories && !$perDatabase) { $params[] = $tenantValue; }
                        $pdo->prepare($sql)->execute($params);
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => "Accesorio desactivado porque está siendo usado en $count orden(es)"]);
                            exit;
                        } else {
                            $success = "Accesorio desactivado porque está siendo usado en $count orden(es)";
                        }
                    } else {
                        $sql = "DELETE FROM equipment_accessories WHERE id = ?" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : "");
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(($hasTenantAccessories && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => 'Accesorio eliminado exitosamente']);
                            exit;
                        } else {
                            $success = "Accesorio eliminado exitosamente";
                        }
                    }
                } catch (PDOException $e) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                        exit;
                    } else {
                        $error = "Error al eliminar el accesorio: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'update_order') {
            $order_data = $_POST['order'] ?? [];
            if (!empty($order_data)) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE equipment_accessories SET sort_order = ? WHERE id = ?" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : ""));
                    foreach ($order_data as $index => $id) {
                        $params = [$index + 1, $id];
                        if ($hasTenantAccessories && !$perDatabase) { $params[] = $tenantValue; }
                        $stmt->execute($params);
                    }
                    $pdo->commit();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Orden actualizado exitosamente']);
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar el orden']);
                    exit;
                }
            }
        }
    }
}

// Obtener lista de accesorios
try {
    $sql = "SELECT id, name, created_at FROM equipment_accessories WHERE is_active = 1" . (($hasTenantAccessories && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY sort_order ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAccessories && !$perDatabase) ? [$tenantValue] : []);
    $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accessories = [];
    $error = "Error al cargar los accesorios: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars((string)$csrf_token, ENT_QUOTES); ?>">
    <title>Gestionar Accesorios</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
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
                
                <?php if ($success && empty($_SERVER['HTTP_X_REQUESTED_WITH'])): ?>
                    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius: 10px;">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error && empty($_SERVER['HTTP_X_REQUESTED_WITH'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius: 10px;">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                        <h5 class="mb-0 text-dark fw-bold"><i class="fas fa-plug me-2"></i>Gestión de Accesorios</h5>
                        <button class="btn btn-dark rounded-pill shadow-sm" onclick="openCreateModal()">
                            <i class="fas fa-plus me-2"></i>Nuevo Accesorio
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="border-0 ps-4 text-center" style="width: 50px; border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Orden</th>
                                        <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Nombre del Accesorio</th>
                                        <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Fecha de Registro</th>
                                        <th class="border-0 pe-4 text-end" style="border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="sortableTbody">
                                    <?php foreach ($accessories as $accessory): 
                                        // Determinar icono basado en el nombre
                                        $lowerName = strtolower($accessory['name']);
                                        $icon = 'fa-plug'; // Default
                                        if (strpos($lowerName, 'cargador') !== false || strpos($lowerName, 'adapter') !== false || strpos($lowerName, 'fuente') !== false) { $icon = 'fa-bolt'; }
                                        elseif (strpos($lowerName, 'cable') !== false || strpos($lowerName, 'usb') !== false) { $icon = 'fa-link'; }
                                        elseif (strpos($lowerName, 'funda') !== false || strpos($lowerName, 'case') !== false || strpos($lowerName, 'estuche') !== false) { $icon = 'fa-shield-alt'; }
                                        elseif (strpos($lowerName, 'teclado') !== false || strpos($lowerName, 'keyboard') !== false) { $icon = 'fa-keyboard'; }
                                        elseif (strpos($lowerName, 'mouse') !== false || strpos($lowerName, 'raton') !== false) { $icon = 'fa-mouse'; }
                                        elseif (strpos($lowerName, 'auricular') !== false || strpos($lowerName, 'headset') !== false || strpos($lowerName, 'audifono') !== false) { $icon = 'fa-headphones'; }
                                        elseif (strpos($lowerName, 'memoria') !== false || strpos($lowerName, 'sd') !== false || strpos($lowerName, 'disco') !== false) { $icon = 'fa-sd-card'; }
                                        elseif (strpos($lowerName, 'bateria') !== false || strpos($lowerName, 'battery') !== false || strpos($lowerName, 'pila') !== false) { $icon = 'fa-battery-full'; }
                                    ?>
                                    <tr data-id="<?= $accessory['id'] ?>" class="sortable-row" draggable="true">
                                        <td class="text-center ps-4">
                                            <span class="drag-handle text-muted" title="Arrastra para cambiar el orden" style="cursor: grab; font-size: 1.2rem;">⋮⋮</span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 45px; height: 45px;">
                                                    <i class="fas <?= $icon ?> text-dark fa-lg"></i>
                                                </div>
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($accessory['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?= date('d/m/Y', strtotime($accessory['created_at'])) ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <div class="btn-group shadow-sm" role="group">
                                                <button class="btn btn-sm btn-outline-dark rounded-start btn-action" 
                                                        onclick='openEditModal(<?= json_encode($accessory) ?>)' 
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger rounded-end btn-action" 
                                                        onclick="confirmDelete(<?= $accessory['id'] ?>, '<?= htmlspecialchars($accessory['name']) ?>')" 
                                                        title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($accessories)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No hay accesorios registrados</p>
                                <button class="btn btn-dark" onclick="openCreateModal()">
                                    <i class="fas fa-plus"></i> Crear Primer Accesorio
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

    <!-- Modal para Crear/Editar Accesorio -->
    <div class="modal fade" id="accessoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-plus me-2"></i>Nuevo Accesorio</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="accessoryForm">
                    <div class="modal-body bg-light">
                        <input type="hidden" id="accessoryId" name="id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <label for="name" class="form-label fw-bold text-dark">Nombre del Accesorio <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" required maxlength="100" style="border-radius: 0.5rem;" placeholder="Ej: Cargador Original">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm" id="submitBtn"><i class="fas fa-save me-2"></i>Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-danger text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-trash-alt me-2"></i>Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4 text-center">
                            <div class="avatar-lg bg-danger bg-opacity-10 rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-3">¿Está seguro de que desea eliminar el accesorio <span id="deleteAccessoryName" class="text-danger"></span>?</h5>
                            <p class="text-muted">Esta acción no se puede deshacer y podría afectar a las órdenes asociadas.</p>
                            
                            <div class="mt-4">
                                <button type="button" class="btn btn-secondary rounded-pill px-4 me-2" data-bs-dismiss="modal">Cancelar</button>
                                <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm" id="confirmDeleteBtn"><i class="fas fa-trash me-2"></i>Eliminar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/utils.js"></script>
    <script>
        function getCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) return meta.content;
            const input = document.querySelector('input[name="csrf_token"]');
            if (input && input.value) return input.value;
            return '';
        }

        const accessoryModalEl = document.getElementById('accessoryModal');
        const deleteModalEl = document.getElementById('deleteModal');
        const accessoryModal = new bootstrap.Modal(accessoryModalEl);
        const deleteModal = new bootstrap.Modal(deleteModalEl);
        let deleteId = 0;

        function openCreateModal() {
            document.getElementById('accessoryForm').reset();
            document.getElementById('accessoryId').value = '';
            document.getElementById('action').value = 'create';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Nuevo Accesorio';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-2"></i>Guardar';
            accessoryModal.show();
        }

        function openEditModal(accessory) {
            document.getElementById('accessoryForm').reset();
            document.getElementById('accessoryId').value = accessory.id;
            document.getElementById('name').value = accessory.name;
            document.getElementById('action').value = 'update';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Editar Accesorio';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-2"></i>Actualizar';
            accessoryModal.show();
        }

        document.getElementById('accessoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('csrf_token', getCsrfToken());
            
            // Añadir header para indicar AJAX
            fetch('accessories_manager.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    if (typeof showError === 'function') {
                        showError(data.message || 'Error desconocido');
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error desconocido', timer: 6000, showConfirmButton: false });
                    } else {
                        alert(data.message || 'Error desconocido');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showError === 'function') {
                    showError('Error al procesar la solicitud');
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error al procesar la solicitud', timer: 6000, showConfirmButton: false });
                } else {
                    alert('Error al procesar la solicitud');
                }
            });
        });

        function confirmDelete(id, name) {
            deleteId = id;
            document.getElementById('deleteAccessoryName').textContent = name;
            deleteModal.show();
        }

        // Asegurar que el modal quede visible en el centro del viewport al mostrarse
        deleteModalEl.addEventListener('shown.bs.modal', function(){
            try { deleteModalEl.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e) {}
        });
        accessoryModalEl.addEventListener('shown.bs.modal', function(){
            try { accessoryModalEl.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e) {}
        });

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (!deleteId) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', deleteId);
            formData.append('csrf_token', getCsrfToken());
            
            fetch('accessories_manager.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    if (typeof showError === 'function') {
                        showError(data.message || 'Error desconocido');
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error desconocido', timer: 6000, showConfirmButton: false });
                    } else {
                        alert(data.message || 'Error desconocido');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showError === 'function') {
                    showError('Error al procesar la solicitud');
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error al procesar la solicitud', timer: 6000, showConfirmButton: false });
                } else {
                    alert('Error al procesar la solicitud');
                }
            });
        });

        // Funcionalidad Drag and Drop
        const tbody = document.getElementById('sortableTbody');
        const saveBtn = document.getElementById('saveOrderBtn');
        let draggedItem = null;

        if (tbody) {
            tbody.addEventListener('dragstart', function(e) {
                draggedItem = e.target.closest('tr');
                if (draggedItem) {
                    e.dataTransfer.effectAllowed = 'move';
                    draggedItem.classList.add('dragging');
                }
            });

            tbody.addEventListener('dragend', function(e) {
                if (draggedItem) {
                    draggedItem.classList.remove('dragging');
                    draggedItem = null;
                }
                document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
            });

            tbody.addEventListener('dragover', function(e) {
                e.preventDefault();
                const targetRow = e.target.closest('tr');
                if (targetRow && targetRow !== draggedItem) {
                    const rect = targetRow.getBoundingClientRect();
                    const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                    tbody.insertBefore(draggedItem, next ? targetRow.nextSibling : targetRow);
                    
                    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
                    targetRow.classList.add('drag-over');
                    
                    saveBtn.style.display = 'inline-block';
                }
            });
        }

        saveBtn.addEventListener('click', function() {
            const rows = tbody.querySelectorAll('tr');
            const order = Array.from(rows).map(row => row.getAttribute('data-id'));
            
            const formData = new FormData();
            formData.append('action', 'update_order');
            formData.append('csrf_token', getCsrfToken());
            order.forEach((id, index) => {
                formData.append(`order[${index}]`, id);
            });

            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
            this.disabled = true;

            fetch('accessories_manager.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    this.innerHTML = '<i class="fas fa-check me-2"></i>Guardado!';
                    this.classList.remove('btn-success');
                    this.classList.add('btn-dark');
                    if (typeof showSuccess === 'function') {
                        showSuccess('Orden actualizado exitosamente');
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: '¡Éxito!', text: 'Orden actualizado exitosamente', timer: 800, showConfirmButton: false, position: 'top-end', toast: true });
                    }
                    setTimeout(() => {
                        this.style.display = 'none';
                        this.innerHTML = originalText;
                        this.disabled = false;
                        this.classList.add('btn-success');
                        this.classList.remove('btn-dark');
                    }, 2000);
                } else {
                    if (typeof showError === 'function') {
                        showError('Error: ' + (data.message || 'Error desconocido'));
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error desconocido', timer: 6000, showConfirmButton: false });
                    } else {
                        alert('Error: ' + data.message);
                    }
                    this.innerHTML = originalText;
                    this.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showError === 'function') {
                    showError('Error al guardar el orden');
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error al guardar el orden', timer: 6000, showConfirmButton: false });
                } else {
                    alert('Error al guardar el orden');
                }
                this.innerHTML = originalText;
                this.disabled = false;
            });
        });
    </script>
</body>
