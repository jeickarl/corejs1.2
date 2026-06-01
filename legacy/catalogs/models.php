<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';



// Verificar autenticación
requireAuth();

$pdo = db();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
$hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
$hasTenantModels = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'models') : false;

// Función para obtener todas las marcas
function getBrands() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
    if ($hasTenantBrands && !$perDatabase) {
        $stmt = $pdo->prepare("SELECT * FROM brands WHERE tenant_id IN (?, 1, 0) OR tenant_id IS NULL ORDER BY name");
        $stmt->execute([$tenantValue]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM brands ORDER BY name");
        $stmt->execute([]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener todos los tipos de equipo
function getDeviceTypes() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
    if ($hasTenantDeviceTypes && !$perDatabase) {
        $stmt = $pdo->prepare("SELECT * FROM device_types WHERE tenant_id IN (?, 1, 0) OR tenant_id IS NULL ORDER BY name");
        $stmt->execute([$tenantValue]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM device_types ORDER BY name");
        $stmt->execute([]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener todos los modelos
function getModels() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantModels = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'models') : false;
    $hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
    $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;

    if ($hasTenantModels && !$perDatabase) {
        $stmt = $pdo->prepare("
            SELECT m.*, b.name as brand_name, b.logo as brand_logo, dt.name as device_type_name
            FROM models m 
            LEFT JOIN brands b ON m.brand_id = b.id
            LEFT JOIN device_types dt ON m.device_type_id = dt.id
            WHERE (m.tenant_id IN (?, 1, 0) OR m.tenant_id IS NULL)
            ORDER BY b.name, m.name
        ");
        $stmt->execute([$tenantValue]);
    } else {
        $stmt = $pdo->prepare("
            SELECT m.*, b.name as brand_name, b.logo as brand_logo, dt.name as device_type_name
            FROM models m 
            LEFT JOIN brands b ON m.brand_id = b.id
            LEFT JOIN device_types dt ON m.device_type_id = dt.id
            ORDER BY b.name, m.name
        ");
        $stmt->execute([]);
    }
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
    $hasTenantModels = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'models') : false;
    
    try {
        switch ($action) {
            case 'create':
                $name = trim($_POST['name']);
                $brand_id = $_POST['brand_id'];
                $device_type_id = $_POST['device_type_id'];
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name) || empty($brand_id) || empty($device_type_id)) {
                    throw new Exception('El nombre, la marca y el tipo de equipo son obligatorios');
                }
                
                if ($hasTenantModels && !$perDatabase) {
                    $stmt = $pdo->prepare("INSERT INTO models (tenant_id, name, brand_id, device_type_id, description) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tenantValue, $name, $brand_id, $device_type_id, $description]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO models (name, brand_id, device_type_id, description) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $brand_id, $device_type_id, $description]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Modelo creado exitosamente']);
                break;
                
            case 'update':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $brand_id = $_POST['brand_id'];
                $device_type_id = $_POST['device_type_id'];
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name) || empty($brand_id) || empty($device_type_id)) {
                    throw new Exception('El nombre, la marca y el tipo de equipo son obligatorios');
                }
                
                if ($hasTenantModels && !$perDatabase) {
                    $stmt = $pdo->prepare("UPDATE models SET name = ?, brand_id = ?, device_type_id = ?, description = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$name, $brand_id, $device_type_id, $description, $id, $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("UPDATE models SET name = ?, brand_id = ?, device_type_id = ?, description = ? WHERE id = ?");
                    $stmt->execute([$name, $brand_id, $device_type_id, $description, $id]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Modelo actualizado exitosamente']);
                break;
                
            case 'delete':
                $id = $_POST['id'];
                
                if ($hasTenantModels && !$perDatabase) {
                    $stmt = $pdo->prepare("DELETE FROM models WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$id, $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM models WHERE id = ?");
                    $stmt->execute([$id]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Modelo eliminado exitosamente']);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$models = getModels();
$brands = getBrands();
$device_types = getDeviceTypes();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Modelos</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/utils.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            overflow-x: hidden;
        }
        .avatar-sm {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand-logo-sm {
            width: 24px;
            height: 24px;
            object-fit: contain;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container-fluid p-4">
        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
            <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                <h5 class="mb-0 text-dark fw-bold">
                    <i class="fas fa-mobile-alt me-2"></i>Modelos Registrados
                </h5>
                <button class="btn btn-dark rounded-pill shadow-sm" onclick="openCreateModal()">
                    <i class="fas fa-plus me-2"></i>Nuevo Modelo
                </button>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0" style="border-top-left-radius: 1rem; border-bottom-left-radius: 1rem;">Modelo</th>
                                <th class="border-0">Marca</th>
                                <th class="border-0">Tipo de Equipo</th>
                                <th class="border-0">Descripción</th>
                                <th class="border-0" style="border-top-right-radius: 1rem; border-bottom-right-radius: 1rem;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($models)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-mobile-alt fa-3x mb-3"></i>
                                        <p class="mb-0">No hay modelos registrados.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($models as $model): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-light rounded-circle me-3">
                                                <i class="fas fa-mobile text-dark"></i>
                                            </div>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($model['name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($model['brand_logo'])): ?>
                                                <img src="../<?= htmlspecialchars($model['brand_logo']) ?>" class="brand-logo-sm" alt="Logo">
                                                <span class="badge bg-light text-dark border border-secondary fw-normal">
                                                    <?= htmlspecialchars($model['brand_name'] ?? 'N/A') ?>
                                                </span>
                                            <?php else: ?>
                                                <?php 
                                                    // Generar un color pastel basado en el nombre de la marca
                                                    $hash = md5($model['brand_name'] ?? 'default');
                                                    $r = hexdec(substr($hash, 0, 2)) % 100 + 150; // 150-250
                                                    $g = hexdec(substr($hash, 2, 2)) % 100 + 150;
                                                    $b = hexdec(substr($hash, 4, 2)) % 100 + 150;
                                                    $color = "rgb($r, $g, $b)";
                                                    $textColor = "#333";
                                                ?>
                                                <span class="badge" style="background-color: <?= $color ?>; color: <?= $textColor ?>; font-weight: normal; font-size: 0.9em; padding: 0.5em 0.8em;">
                                                    <i class="fas fa-tag me-1"></i><?= htmlspecialchars($model['brand_name'] ?? 'N/A') ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-2">
                                            <i class="fas fa-laptop me-1"></i><?= htmlspecialchars($model['device_type_name'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small"><?= htmlspecialchars($model['description']) ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-dark rounded-start" onclick='openEditModal(<?= htmlspecialchars(json_encode($model), ENT_QUOTES) ?>)' title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger rounded-end" onclick="confirmDelete(<?= $model['id'] ?>)" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear/Editar -->
    <div class="modal fade" id="modelModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-plus me-2"></i>Nuevo Modelo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form id="modelForm">
                                <input type="hidden" id="modelId" name="id">
                                <input type="hidden" id="action" name="action" value="create">
                                
                                <div class="mb-3">
                                    <label for="name" class="form-label fw-bold text-dark">Nombre del Modelo *</label>
                                    <input type="text" class="form-control" id="name" name="name" required maxlength="100" style="border-radius: 0.5rem;">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="brand_id" class="form-label fw-bold text-dark">Marca *</label>
                                    <select class="form-select" id="brand_id" name="brand_id" required style="border-radius: 0.5rem;">
                                        <option value="">Seleccionar marca...</option>
                                        <?php foreach ($brands as $brand): ?>
                                        <option value="<?= $brand['id'] ?>"><?= htmlspecialchars($brand['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="device_type_id" class="form-label fw-bold text-dark">Tipo de Equipo *</label>
                                    <select class="form-select" id="device_type_id" name="device_type_id" required style="border-radius: 0.5rem;">
                                        <option value="">Seleccionar tipo de equipo...</option>
                                        <?php foreach ($device_types as $dt): ?>
                                        <option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" maxlength="255" style="border-radius: 0.5rem;"></textarea>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="saveModel()">
                        <i class="fas fa-save me-2"></i>Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modelModal = new bootstrap.Modal(document.getElementById('modelModal'));

        function openCreateModal() {
            document.getElementById('modelForm').reset();
            document.getElementById('modelId').value = '';
            document.getElementById('action').value = 'create';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Nuevo Modelo';
            modelModal.show();
        }

        function openEditModal(model) {
            document.getElementById('modelForm').reset();
            document.getElementById('modelId').value = model.id;
            document.getElementById('name').value = model.name;
            document.getElementById('brand_id').value = model.brand_id;
            document.getElementById('device_type_id').value = model.device_type_id;
            document.getElementById('description').value = model.description;
            document.getElementById('action').value = 'update';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Editar Modelo';
            modelModal.show();
        }

        function saveModel() {
            const form = document.getElementById('modelForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);

            fetch('models.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message);
                    modelModal.hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Error al procesar la solicitud');
            });
        }

        function confirmDelete(id) {
            showConfirm('¿Estás seguro de eliminar este modelo? Esta acción no se puede deshacer.', function() {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);

                fetch('models.php', {
                    method: 'POST',
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
                    showError('Error al procesar la solicitud');
                });
            });
        }
    </script>
</body>
</html>
