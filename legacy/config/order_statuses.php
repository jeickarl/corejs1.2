<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
$pdo = db();

// Verificar autenticación
requireAuth();

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header('Location: order_statuses.php?error=' . urlencode('Token de seguridad inválido'));
        exit();
    }
    
    // Verificar permisos de administrador para acciones de modificación
    if (!isAdminSession()) {
        header('Location: order_statuses.php?error=' . urlencode('Acceso denegado: Se requieren permisos de administrador'));
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantOrderStatuses = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_statuses') : false;
    $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
    
    try {
        if ($action === 'create') {
            $name = trim($_POST['name']);
            $color = trim($_POST['color']);
            $emoji = trim($_POST['emoji']);
            $description = trim($_POST['description']);
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            // Validaciones
            if (empty($name)) {
                throw new Exception('El nombre del estado es obligatorio');
            }
            
            // Generar slug
            $slug = strtolower(str_replace(' ', '_', $name));
            $slug = preg_replace('/[^a-z0-9_]/', '', $slug);
            
            // Si es por defecto, quitar el default de otros
            if ($is_default) {
                $stmtReset = $pdo->prepare("UPDATE order_statuses SET is_default = 0" . (($hasTenantOrderStatuses && !$perDatabase) ? " WHERE tenant_id = ?" : ""));
                $stmtReset->execute(($hasTenantOrderStatuses && !$perDatabase) ? [$tenantValue] : []);
            }
            
            // Obtener siguiente sort_order
            $sort_stmt = $pdo->prepare("SELECT MAX(sort_order) as max_sort FROM order_statuses" . (($hasTenantOrderStatuses && !$perDatabase) ? " WHERE tenant_id = ?" : ""));
            $sort_stmt->execute(($hasTenantOrderStatuses && !$perDatabase) ? [$tenantValue] : []);
            $max_sort = $sort_stmt->fetch(PDO::FETCH_ASSOC)['max_sort'] ?? 0;
            
            if ($hasTenantOrderStatuses) {
                $sql = "INSERT INTO order_statuses (tenant_id, name, slug, color, emoji, description, is_default, sort_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tenantValue, $name, $slug, $color, $emoji, $description, $is_default, $max_sort + 1]);
            } else {
                $sql = "INSERT INTO order_statuses (name, slug, color, emoji, description, is_default, sort_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $slug, $color, $emoji, $description, $is_default, $max_sort + 1]);
            }
            
            header('Location: order_statuses.php?success=' . urlencode('Estado creado exitosamente'));
            exit();
            
        } elseif ($action === 'update') {
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $color = trim($_POST['color']);
            $emoji = trim($_POST['emoji']);
            $description = trim($_POST['description']);
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validaciones
            if (empty($name)) {
                throw new Exception('El nombre del estado es obligatorio');
            }
            
            // Si es por defecto, quitar el default de otros
            if ($is_default) {
                $stmtReset = $pdo->prepare("UPDATE order_statuses SET is_default = 0 WHERE id != ?" . (($hasTenantOrderStatuses && !$perDatabase) ? " AND tenant_id = ?" : ""));
                $paramsReset = [$id];
                if ($hasTenantOrderStatuses && !$perDatabase) { $paramsReset[] = $tenantValue; }
                $stmtReset->execute($paramsReset);
            }
            
            $sql = "UPDATE order_statuses SET name = ?, color = ?, emoji = ?, description = ?, is_default = ?, is_active = ? WHERE id = ?" . (($hasTenantOrderStatuses && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $paramsUpd = [$name, $color, $emoji, $description, $is_default, $is_active, $id];
            if ($hasTenantOrderStatuses && !$perDatabase) { $paramsUpd[] = $tenantValue; }
            $stmt->execute($paramsUpd);
            
            header('Location: order_statuses.php?success=' . urlencode('Estado actualizado exitosamente'));
            exit();
            
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            
            // Verificar que no sea el único estado activo
            $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM order_statuses WHERE is_active = 1" . (($hasTenantOrderStatuses && !$perDatabase) ? " AND tenant_id = ?" : ""));
            $count_stmt->execute(($hasTenantOrderStatuses && !$perDatabase) ? [$tenantValue] : []);
            $active_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($active_count <= 1) {
                throw new Exception('No se puede eliminar el último estado activo');
            }
            
            // Verificar que no haya órdenes con este estado
            if ($hasTenantWorkOrders && $hasTenantOrderStatuses && !$perDatabase) {
                $orders_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_orders WHERE tenant_id = ? AND status = (SELECT slug FROM order_statuses WHERE id = ? AND tenant_id = ?)");
                $orders_stmt->execute([$tenantValue, $id, $tenantValue]);
            } else {
                $orders_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_orders WHERE status = (SELECT slug FROM order_statuses WHERE id = ?)");
                $orders_stmt->execute([$id]);
            }
            $orders_count = $orders_stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($orders_count > 0) {
                throw new Exception('No se puede eliminar un estado que tiene órdenes asociadas');
            }
            
            $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE id = ?" . (($hasTenantOrderStatuses && !$perDatabase) ? " AND tenant_id = ?" : ""));
            $stmt->execute(($hasTenantOrderStatuses && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            
            header('Location: order_statuses.php?success=' . urlencode('Estado eliminado exitosamente'));
            exit();
        }
        
    } catch (Exception $e) {
        header('Location: order_statuses.php?error=' . urlencode($e->getMessage()));
        exit();
    }
}

// Obtener estados
$tid = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tid;
$hasTenantOrderStatuses = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_statuses') : false;
$statuses_query = "SELECT * FROM order_statuses" . (($hasTenantOrderStatuses && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY sort_order";
$statuses_stmt = $pdo->prepare($statuses_query);
$statuses_stmt->execute(($hasTenantOrderStatuses && !$perDatabase) ? [$tenantValue] : []);
$statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);

// Generar token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Manejo de mensajes
$mensaje = '';
$tipo_mensaje = '';

if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
} elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Estados - Sistema de Órdenes</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-cog me-2"></i>Configuración de Estados</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newStatusModal">
                        <i class="fas fa-plus me-2"></i>Nuevo Estado
                    </button>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Lista de Estados -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Estados de Órdenes</h5>
                        <button id="saveSortOrderHeader" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i>Guardar orden</button>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="small text-muted">Arrastra el ícono para reordenar los estados. Luego guarda.</div>
                            <button id="saveSortOrder" class="btn btn-sm btn-outline-primary"><i class="fas fa-save me-1"></i>Guardar orden</button>
                        </div>
                        <div id="order-statuses-table-wrapper" class="table-responsive">
                            <table id="order-statuses-table" class="table table-striped" data-source="server">
                                <thead>
                                    <tr>
                                        <th style="width:48px;" class="text-center"><i class="fas fa-arrows-alt-v"></i></th>
                                        <th>Estado</th>
                                        <th>Color</th>
                                        <th>Descripción</th>
                                        <th>Por Defecto</th>
                                        <th>Activo</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statuses as $status): ?>
                                        <tr draggable="true" data-id="<?php echo $status['id']; ?>">
                                            <td class="text-center"><span class="drag-handle" title="Arrastrar para reordenar" style="cursor: grab;"><i class="fas fa-grip-lines-vertical text-secondary fs-5"></i></span></td>
                                            <td>
                                                <span class="badge" style="background-color: <?php echo htmlspecialchars($status['color']); ?>; color: white;">
                                                    <?php
                                                    // Usar entidades HTML para evitar problemas de codificación
                                                    $emojiMap = [
                                                        'pending' => '&#x23F3;',            // ⏳
                                                        'received' => '&#x1F4E6;',          // 📦
                                                        'diagnosing' => '&#x1F50D;',        // 🔍
                                                        'waiting_parts' => '&#x23F8;&#xFE0F;', // ⏸️
                                                        'repairing' => '&#x1F527;',         // 🔧
                                                        'testing' => '&#x1F9EA;',           // 🧪
                                                        'completed' => '&#x2705;',          // ✅
                                                        'delivered' => '&#x1F69A;',         // 🚚
                                                        'cancelled' => '&#x274C;',          // ❌
                                                        'devolucion' => '&#x21A9;&#xFE0F;', // ↩️
                                                        'cancelado' => '&#x274C;',          // ❌
                                                        'entregado' => '&#x1F69A;'          // 🚚
                                                    ];
                                                    $raw = (string)($status['emoji'] ?? '');
                                                    $useDefault = ($raw === '' || preg_match('/^\?+$/', $raw));
                                                    $displayEmoji = $useDefault ? ($emojiMap[$status['slug']] ?? '❓') : $raw;
                                                    echo $displayEmoji . ' ' . htmlspecialchars($status['name']);
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="color-preview me-2" style="width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($status['color']); ?>; border-radius: 3px;"></div>
                                                    <code><?php echo htmlspecialchars($status['color']); ?></code>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($status['description']); ?></td>
                                            <td>
                                                <?php if ($status['is_default']): ?>
                                                    <i class="fas fa-check text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times text-muted"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($status['is_active']): ?>
                                                    <i class="fas fa-check text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times text-danger"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                                        onclick="editStatus(<?php echo htmlspecialchars(json_encode($status)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteStatus(<?php echo $status['id']; ?>, '<?php echo htmlspecialchars($status['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Nuevo Estado -->
    <div class="modal fade" id="newStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">Nuevo Estado</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nombre del Estado</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="emoji" class="form-label">Emoji</label>
                            <input type="text" class="form-control" id="emoji" name="emoji" placeholder="🟡">
                        </div>
                        
                        <div class="mb-3">
                            <label for="color" class="form-label">Color</label>
                            <input type="color" class="form-control form-control-color" id="color" name="color" value="#6c757d">
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Descripción</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_default" name="is_default">
                            <label class="form-check-label" for="is_default">
                                Estado por defecto para nuevas órdenes
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear Estado</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Editar Estado -->
    <div class="modal fade" id="editStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="editStatusForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">Editar Estado</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Nombre del Estado</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_emoji" class="form-label">Emoji</label>
                            <input type="text" class="form-control" id="edit_emoji" name="emoji">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_color" class="form-label">Color</label>
                            <input type="color" class="form-control form-control-color" id="edit_color" name="color">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Descripción</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_default" name="is_default">
                            <label class="form-check-label" for="edit_is_default">
                                Estado por defecto para nuevas órdenes
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Estado activo
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Actualizar Estado</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Eliminación -->
    <div class="modal fade" id="deleteStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="deleteStatusForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">Confirmar Eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <p>¿Está seguro de que desea eliminar el estado <strong id="delete_name"></strong>?</p>
                        <p class="text-muted">Esta acción no se puede deshacer.</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/utils.js"></script>
    <script>
        function editStatus(status) {
            document.getElementById('edit_id').value = status.id;
            document.getElementById('edit_name').value = status.name;
            document.getElementById('edit_emoji').value = status.emoji;
            document.getElementById('edit_color').value = status.color;
            document.getElementById('edit_description').value = status.description;
            document.getElementById('edit_is_default').checked = status.is_default == 1;
            document.getElementById('edit_is_active').checked = status.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editStatusModal')).show();
        }
        
        function deleteStatus(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deleteStatusModal')).show();
        }
        
        (function() {
            const tbody = document.querySelector('.card-body tbody');
            if (!tbody) return;
            let draggingRow = null;
            let dragFromHandle = false;
            tbody.addEventListener('mousedown', function(e) {
                dragFromHandle = !!e.target.closest('.drag-handle');
            });
            tbody.addEventListener('dragstart', function(e) {
                const row = e.target.closest('tr[draggable="true"]');
                if (!row) return;
                if (!dragFromHandle) {
                    e.preventDefault();
                    return;
                }
                draggingRow = row;
                row.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });
            tbody.addEventListener('dragover', function(e) {
                e.preventDefault();
                const row = e.target.closest('tr[draggable="true"]');
                if (!row || row === draggingRow) return;
                const rect = row.getBoundingClientRect();
                const after = (e.clientY - rect.top) > rect.height / 2;
                tbody.insertBefore(draggingRow, after ? row.nextSibling : row);
            });
            ['drop','dragend'].forEach(function(evt) {
                tbody.addEventListener(evt, function() {
                    if (draggingRow) draggingRow.classList.remove('opacity-50');
                    draggingRow = null;
                    dragFromHandle = false;
                });
            });
            function saveOrder() {
                    const ids = Array.from(tbody.querySelectorAll('tr[draggable="true"]'))
                        .map(function(tr) { return parseInt(tr.getAttribute('data-id'), 10); })
                        .filter(function(n) { return !isNaN(n); });
                    const body = 'action=reorder&csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>') +
                                 '&ids=' + encodeURIComponent(JSON.stringify(ids));
                    fetch('order_statuses_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                        body: body
                    }).then(window.parseJsonResponse)
                      .then(function(data) {
                          if (data && data.success) {
                              const alert = document.createElement('div');
                              alert.className = 'alert alert-success mt-2';
                              alert.textContent = 'Orden guardado';
                              document.querySelector('.card-body').prepend(alert);
                              setTimeout(function() { window.location.reload(); }, 800);
                          } else {
                              const alert = document.createElement('div');
                              alert.className = 'alert alert-danger mt-2';
                              alert.textContent = (data && data.message) ? data.message : 'Error al guardar el orden';
                              document.querySelector('.card-body').prepend(alert);
                          }
                      }).catch(function() {
                          const alert = document.createElement('div');
                          alert.className = 'alert alert-danger mt-2';
                          alert.textContent = 'Error de red al guardar el orden';
                          document.querySelector('.card-body').prepend(alert);
                      });
            }
            const saveBtn = document.getElementById('saveSortOrder');
            if (saveBtn) { saveBtn.addEventListener('click', saveOrder); }
            const saveHeaderBtn = document.getElementById('saveSortOrderHeader');
            if (saveHeaderBtn) { saveHeaderBtn.addEventListener('click', saveOrder); }
        })();
    </script>
</body>
</html>
