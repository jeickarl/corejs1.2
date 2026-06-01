<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_CLIENT_DETAILS', 'clients', isset($_GET['id']) ? $_GET['id'] : null);

// Verificar tenant
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

$cliente_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($cliente_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de cliente no válido.'));
    exit();
}

// Obtener datos del cliente
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$cliente_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$cliente_id, $tenant_id]);
    }
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        header('Location: index.php?error=' . urlencode('Cliente no encontrado.'));
        exit();
    }
}
catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar el cliente.'));
    exit();
}

// Generar token CSRF para operaciones (como eliminar)
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Obtener órdenes del cliente
try {
    $sql = "SELECT o.*, dt.name as device_type_name 
            FROM work_orders o
        LEFT JOIN device_types dt ON o.device_type_id = dt.id
        WHERE o.client_id = ?
        ORDER BY o.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $params = [$cliente_id];
    if (!$perDatabase) {
        $sql = "SELECT o.*, dt.name as device_type_name 
                FROM work_orders o
                LEFT JOIN device_types dt ON o.device_type_id = dt.id AND dt.tenant_id = o.tenant_id
                WHERE o.client_id = ? AND o.tenant_id = ?
                ORDER BY o.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $params = [$cliente_id, $tenant_id];
    }
    $stmt->execute($params);
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) {
    $ordenes = [];
}

// Estadísticas de órdenes
$stats = [
    'total' => count($ordenes),
    'pendientes' => 0,
    'en_proceso' => 0,
    'completadas' => 0
];

foreach ($ordenes as $orden) {
    switch ($orden['status']) {
        case 'received':
            $stats['pendientes']++;
            break;
        case 'diagnosing':
        case 'repairing':
            $stats['en_proceso']++;
            break;
        case 'completed':
        case 'delivered':
            $stats['completadas']++;
            break;
    }
}

// Mensajes de éxito/error
$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
}
elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}

// Configuración del template
$page_title = 'Detalle de Cliente - ' . ($cliente['client_type'] === 'company' ? $cliente['company_name'] : $cliente['first_name']);

// No specific JS needed for view only, but keeping for consistency if we add interactions
$additional_js = [];

// Capturar el contenido de la página
ob_start();
?>

<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <nav aria-label="breadcrumb" class="mb-2">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Clientes</a></li>
                <li class="breadcrumb-item active" aria-current="page">Detalles</li>
            </ol>
        </nav>
        <h2 class="fw-bold text-dark mb-0">
            <?php if ($cliente['client_type'] === 'company'): ?>
                <i class="fas fa-building me-2 text-primary"></i><?php echo htmlspecialchars($cliente['company_name']); ?>
            <?php
else: ?>
                <i class="fas fa-user me-2 text-primary"></i><?php echo htmlspecialchars($cliente['first_name']); ?>
            <?php
endif; ?>
        </h2>
        <p class="text-muted mb-0 mt-1">
            <?php $clientNumber = $cliente['client_number'] ?? null; ?>
            <?php echo str_pad($clientNumber ?: $cliente['id'], 2, '0', STR_PAD_LEFT); ?> • Registrado el <?php echo formatCompanyDate($cliente['created_at']); ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="../orders/new.php?client_id=<?php echo $cliente_id; ?>" class="btn btn-success rounded-pill px-4 shadow-sm">
            <i class="fas fa-plus-circle me-2"></i>Nueva Orden
        </a>
        <a href="edit.php?id=<?php echo $cliente_id; ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-edit me-2"></i>Editar
        </a>
        <button type="button" class="btn btn-outline-danger rounded-pill px-4 shadow-sm" onclick="confirmDelete()">
            <i class="fas fa-trash-alt me-2"></i>Eliminar
        </button>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fas fa-arrow-left me-2"></i>Volver
        </a>
    </div>
</div>

<!-- Mensajes -->
<?php if ($mensaje): ?>
    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show shadow-sm rounded-3" role="alert">
        <?php if ($tipo_mensaje === 'success'): ?>
            <i class="fas fa-check-circle me-2"></i>
        <?php
    else: ?>
            <i class="fas fa-exclamation-circle me-2"></i>
        <?php
    endif; ?>
        <?php echo htmlspecialchars($mensaje); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <script>
    (function(){
        var msg = <?php echo json_encode((string)$mensaje, JSON_UNESCAPED_UNICODE); ?>;
        var type = <?php echo json_encode((string)$tipo_mensaje); ?>;
        function fire(){
            if (type === 'success') {
                if (typeof Swal !== 'undefined' && typeof showSuccess === 'function') showSuccess(msg);
                return;
            }
            if (typeof Swal !== 'undefined' && typeof showError === 'function') showError(msg);
        }
        if (document.readyState === 'complete') {
            fire();
        } else {
            window.addEventListener('load', fire, { once: true });
        }
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url.toString());
        } catch (e) {}
    })();
    </script>
<?php
endif; ?>

<!-- Stats Row -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-clipboard-list fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['total']; ?></h5>
                    <small class="text-muted">Total Órdenes</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                    <i class="fas fa-clock fa-2x text-warning"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['pendientes']; ?></h5>
                    <small class="text-muted">Pendientes</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-tools fa-2x text-info no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['en_proceso']; ?></h5>
                    <small class="text-muted">En Proceso</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                    <i class="fas fa-check-circle fa-2x text-success"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['completadas']; ?></h5>
                    <small class="text-muted">Completadas</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Información del Cliente -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white py-3 border-bottom border-light">
                <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Información del Cliente</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="avatar-circle bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                        <?php if ($cliente['client_type'] === 'company'): ?>
                            <i class="fas fa-building"></i>
                        <?php
else: ?>
                            <i class="fas fa-user"></i>
                        <?php
endif; ?>
                    </div>
                    <h5 class="fw-bold text-dark">
                        <?php echo htmlspecialchars($cliente['client_type'] === 'company' ? $cliente['company_name'] : $cliente['first_name']); ?>
                    </h5>
                    <span class="badge rounded-pill <?php echo $cliente['client_type'] === 'company' ? 'bg-info bg-opacity-10 text-info' : 'bg-success bg-opacity-10 text-success'; ?>">
                        <?php echo $cliente['client_type'] === 'company' ? 'Empresa' : 'Persona Natural'; ?>
                    </span>
                </div>

                <div class="list-group list-group-flush">
                    <?php if ($cliente['client_type'] === 'company' && $cliente['legal_representative']): ?>
                    <div class="list-group-item px-0 border-light">
                        <small class="text-muted d-block mb-1">Representante Legal</small>
                        <div class="fw-medium"><i class="fas fa-user-tie me-2 text-muted"></i><?php echo htmlspecialchars($cliente['legal_representative']); ?></div>
                    </div>
                    <?php
endif; ?>

                    <?php if ($cliente['id_number']): ?>
                    <div class="list-group-item px-0 border-light">
                        <small class="text-muted d-block mb-1">Identificación</small>
                        <div class="fw-medium"><i class="fas fa-id-card me-2 text-muted"></i><?php echo htmlspecialchars($cliente['id_number']); ?></div>
                    </div>
                    <?php
endif; ?>

                    <div class="list-group-item px-0 border-light">
                        <small class="text-muted d-block mb-1">Teléfono</small>
                        <div class="fw-medium">
                            <a href="tel:<?php echo htmlspecialchars($cliente['phone']); ?>" class="text-decoration-none text-dark">
                                <i class="fas fa-phone me-2 text-muted"></i><?php echo getCompanyFullPhone($cliente['phone']); ?>
                            </a>
                        </div>
                    </div>

                    <?php if ($cliente['email']): ?>
                    <div class="list-group-item px-0 border-light">
                        <small class="text-muted d-block mb-1">Correo Electrónico</small>
                        <div class="fw-medium">
                            <a href="mailto:<?php echo htmlspecialchars($cliente['email']); ?>" class="text-decoration-none text-dark">
                                <i class="fas fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($cliente['email']); ?>
                            </a>
                        </div>
                    </div>
                    <?php
endif; ?>

                    <?php if ($cliente['address']): ?>
                    <div class="list-group-item px-0 border-light">
                        <small class="text-muted d-block mb-1">Dirección</small>
                        <div class="fw-medium"><i class="fas fa-map-marker-alt me-2 text-muted"></i><?php echo nl2br(htmlspecialchars($cliente['address'])); ?></div>
                    </div>
                    <?php
endif; ?>
                </div>

                <?php if ($cliente['notes']): ?>
                <div class="mt-4 p-3 bg-light rounded-3">
                    <small class="text-muted d-block mb-2 fw-bold">Notas Adicionales</small>
                    <p class="mb-0 text-muted small fst-italic"><?php echo nl2br(htmlspecialchars($cliente['notes'])); ?></p>
                </div>
                <?php
endif; ?>
            </div>
            <div class="card-footer bg-white py-3 border-top border-light">
                <small class="text-muted d-block">
                    <i class="far fa-clock me-1"></i> Actualizado: <?php echo formatCompanyDate($cliente['updated_at'] ?? $cliente['created_at'], true); ?>
                </small>
            </div>
        </div>
    </div>

    <!-- Historial de Órdenes -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white py-3 border-bottom border-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i>Historial de Órdenes</h5>
                <?php if (!empty($ordenes)): ?>
                <span class="badge bg-primary rounded-pill"><?php echo count($ordenes); ?> Órdenes</span>
                <?php
endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($ordenes)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                        <i class="fas fa-clipboard-list fa-3x text-muted"></i>
                    </div>
                    <h5 class="text-muted mb-2">No hay órdenes registradas</h5>
                    <p class="text-muted mb-3">Este cliente aún no tiene órdenes de servicio asociadas.</p>
                    <a href="../orders/new.php?client_id=<?php echo $cliente_id; ?>" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-plus me-2"></i>Crear Primera Orden
                    </a>
                </div>
                <?php
else: ?>
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th class="ps-4">Orden</th>
                                <th>Dispositivo</th>
                                <th>Problema</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordenes as $orden):
                                $displayOrderNumber = (isset($orden['order_number']) && (int)$orden['order_number'] > 0)
                                    ? (int)$orden['order_number']
                                    : (int)$orden['id'];
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="fw-bold text-primary">#<?php echo $displayOrderNumber; ?></span>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($orden['device_brand'] . ' ' . $orden['device_model']); ?></div>
                                    <?php if ($orden['device_type_name']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($orden['device_type_name']); ?></small>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <span class="d-inline-block text-truncate text-muted" style="max-width: 200px;" title="<?php echo htmlspecialchars($orden['reported_issue']); ?>">
                                        <?php echo htmlspecialchars($orden['reported_issue']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-<?php echo getStatusColor($orden['status']); ?>">
                                        <?php echo getStatusText($orden['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-dark"><?php echo formatCompanyDate($orden['created_at']); ?></div>
                                    <small class="text-muted"><?php echo formatCompanyTime($orden['created_at']); ?></small>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="../orders/view.php?id=<?php echo $orden['id']; ?>" class="btn btn-sm btn-light text-primary no-theme shadow-sm" title="Ver Detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../orders/edit.php?id=<?php echo $orden['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
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
                        <?php foreach ($ordenes as $orden):
                            $displayOrderNumber = (isset($orden['order_number']) && (int)$orden['order_number'] > 0)
                                ? (int)$orden['order_number']
                                : (int)$orden['id'];
                        ?>
                            <div class="col-12">
                                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                    <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                        <span class="fs-6 fw-bold text-primary">#<?php echo $displayOrderNumber; ?></span>
                                        <span class="badge rounded-pill bg-<?php echo getStatusColor($orden['status']); ?>">
                                            <?php echo getStatusText($orden['status']); ?>
                                        </span>
                                    </div>
                                    <div class="card-body py-2">
                                        <div class="mb-3">
                                            <h6 class="fw-bold mb-1 text-dark"><i class="fas fa-mobile-alt text-primary no-theme me-2"></i><?php echo htmlspecialchars($orden['device_brand'] . ' ' . $orden['device_model']); ?></h6>
                                            <?php if ($orden['device_type_name']): ?>
                                                <span class="text-muted ms-4 d-inline-block small"><?php echo htmlspecialchars($orden['device_type_name']); ?></span>
                                            <?php
        endif; ?>
                                        </div>
                                        <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                                            <div class="fw-bold text-dark mb-1">Problema reportado:</div>
                                            <?php echo htmlspecialchars($orden['reported_issue']); ?>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                                        <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                            <span><i class="fas fa-calendar-alt me-1"></i><?php echo formatCompanyDate($orden['created_at']); ?></span>
                                            <span><i class="fas fa-clock me-1"></i><?php echo formatCompanyTime($orden['created_at']); ?></span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 pb-1 justify-content-end">
                                            <a href="../orders/view.php?id=<?php echo $orden['id']; ?>" class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Ver Detalles"><i class="fas fa-eye"></i></a>
                                            <a href="../orders/edit.php?id=<?php echo $orden['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php
    endforeach; ?>
                    </div>
                </div>
                <?php
endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmación de Eliminación -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-trash-alt text-danger" style="font-size: 3rem;"></i>
                </div>
                <h6 class="text-center mb-3">¿Estás seguro de que quieres eliminar este cliente?</h6>
                <div class="alert alert-warning">
                    <strong>Cliente:</strong> <?php echo htmlspecialchars($cliente['client_type'] === 'company' ? $cliente['company_name'] : $cliente['first_name']); ?>
                    <br>
                    <small>Esta acción no se puede deshacer y podría afectar las órdenes asociadas.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancelar
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i>Eliminar Definitivamente
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    const button = this;
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando...';
    button.disabled = true;
    
    // Enviar solicitud de eliminación
    fetch('delete_client.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            client_id: <?php echo $cliente_id; ?>,
            csrf_token: '<?php echo $csrf_token; ?>'
        })
    })
    .then(window.parseJsonResponse)
    .then(data => {
        // Cerrar modal
        const deleteModalEl = document.getElementById('deleteModal');
        const modal = bootstrap.Modal.getInstance(deleteModalEl);
        modal.hide();
        
        if (data.success) {
            window.location.href = 'index.php?success=' + encodeURIComponent('Cliente eliminado exitosamente');
        } else {
            showAlert('danger', data.message || 'Error al eliminar el cliente');
            // Restaurar botón
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        const deleteModalEl = document.getElementById('deleteModal');
        const modal = bootstrap.Modal.getInstance(deleteModalEl);
        modal.hide();
        showAlert('danger', 'Error de conexión al eliminar el cliente');
        // Restaurar botón
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
});

function showAlert(type, message) {
    // Eliminar alertas existentes
    const existingAlerts = document.querySelectorAll('.alert-dismissible');
    existingAlerts.forEach(alert => alert.remove());

    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show fixed-top m-3 shadow-lg`;
    alertDiv.style.maxWidth = '500px';
    alertDiv.style.left = '50%';
    alertDiv.style.transform = 'translateX(-50%)';
    alertDiv.style.zIndex = '1050';
    
    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    
    alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-${icon} me-2 fa-lg"></i>
            <div>${message}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Auto-ocultar después de 5 segundos
    setTimeout(() => {
        if (alertDiv.parentNode) {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        }
    }, 5000);
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
