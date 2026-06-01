<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_CLIENTS', 'clients', null);

// Generar token CSRF para operaciones en esta página
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if (!$perDatabase) {
    $where_conditions[] = 'tenant_id = ?';
    $params[] = $tenant_id;
}

if (!empty($search)) {
    $where_conditions[] = "(first_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ? OR id_number LIKE ?)";
    $search_param = "%$search%";
    // Add search params
    for ($i = 0; $i < 5; $i++)
        $params[] = $search_param;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Consulta principal con paginación
$query = "
    SELECT *
    FROM clients
    $where_clause
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($query);
$stmt->execute(array_merge($params, [(int)$per_page, (int)$offset]));
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total de registros para paginación
$count_query = "SELECT COUNT(*) as total FROM clients $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Obtener estadísticas
$stats_query = "
    SELECT 
        COUNT(*) as total_clients,
        SUM(CASE WHEN client_type = 'individual' THEN 1 ELSE 0 END) as individual_clients,
        SUM(CASE WHEN client_type = 'company' THEN 1 ELSE 0 END) as company_clients,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as recent_clients
    FROM clients
";
$stats_params = [];
if (!$perDatabase) {
    $stats_query .= " WHERE tenant_id = ? ";
    $stats_params[] = $tenant_id;
}
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Manejo de mensajes
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
?>

<?php
// Configuración del template
$page_title = 'Gestión de Clientes';

$additional_js = ['../assets/js/clients.js'];

// Capturar el contenido de la página
ob_start();
?>
<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-users me-2 text-primary no-theme"></i>Gestión de Clientes</h2>
        <p class="text-muted mb-0">Administra la información de tus clientes</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="new.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-user-plus me-2"></i>Nuevo Cliente
        </a>
    </div>
</div>

<!-- Mensajes de estado -->
<?php if ($mensaje): ?>
    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($mensaje); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php
endif; ?>

<!-- Filtros y Búsqueda -->
<div class="card card-modern mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-8">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" name="search" 
                           placeholder="Buscar por nombre, empresa, email, teléfono o identificación..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary rounded-pill px-3">
                        <i class="fas fa-search me-1"></i>Buscar
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3">
                            <i class="fas fa-times me-1"></i>Limpiar
                        </a>
                    <?php
endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Estadísticas -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-users fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['total_clients']; ?></h5>
                    <small class="text-muted">Total Clientes</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                    <i class="fas fa-user fa-2x text-success"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['individual_clients']; ?></h5>
                    <small class="text-muted">Personas Naturales</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-building fa-2x text-info no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['company_clients']; ?></h5>
                    <small class="text-muted">Empresas</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                    <i class="fas fa-user-plus fa-2x text-warning"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo $stats['recent_clients']; ?></h5>
                    <small class="text-muted">Nuevos (30 días)</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Card -->
<div class="card shadow-sm rounded-4 border-0 overflow-hidden">
    <!-- Tabla de clientes -->
    <div class="card-body p-0">
        <?php if (empty($clients)): ?>
            <div class="text-center py-5">
                <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                    <i class="fas fa-user-slash fa-3x text-muted"></i>
                </div>
                <h5 class="text-muted mb-2">No se encontraron clientes</h5>
                <p class="text-muted mb-3">No hay clientes que coincidan con los criterios de búsqueda.</p>
                <a href="new.php" class="btn btn-primary rounded-pill px-4">Agregar Primer Cliente</a>
            </div>
        <?php
else: ?>
            <!-- Vista de Escritorio (Tabla) -->
            <div class="table-responsive d-none d-lg-block bg-white rounded-bottom-4 shadow-sm border-0 w-100">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Identificación</th>
                            <th>Tipo</th>
                            <th>Contacto</th>
                            <th>Cliente</th>
                            <th>Fecha Registro</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <span class="fw-bold text-primary"><?php echo (int)$client['id']; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($client['id_number']): ?>
                                        <span class="font-monospace text-dark bg-light px-2 py-1 rounded small"><?php echo htmlspecialchars($client['id_number']); ?></span>
                                    <?php
        else: ?>
                                        <span class="text-muted small">No especificado</span>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <?php if ($client['client_type'] === 'company'): ?>
                                        <span class="badge rounded-pill bg-dark text-white">Empresa</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-success text-white">Persona Natural</span>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <?php if ($client['phone']): ?>
                                        <div class="mb-1"><i class="fas fa-phone text-muted me-2 small"></i><?php echo getCompanyFullPhone($client['phone']); ?></div>
                                    <?php
        endif; ?>
                                    <?php if ($client['email']): ?>
                                        <div><i class="fas fa-envelope text-muted me-2 small"></i><?php echo htmlspecialchars($client['email']); ?></div>
                                    <?php
        endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3 bg-light text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <?php if ($client['client_type'] === 'company'): ?>
                                                <i class="fas fa-building"></i>
                                            <?php
        else: ?>
                                                <i class="fas fa-user"></i>
                                            <?php
        endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?php
        if ($client['client_type'] === 'company') {
            echo htmlspecialchars($client['company_name']);
        }
        else {
            echo htmlspecialchars($client['first_name']);
        }
?>
                                            </div>
                                            <?php if ($client['client_type'] === 'company' && $client['legal_representative']): ?>
                                                <small class="text-muted">Rep: <?php echo htmlspecialchars($client['legal_representative']); ?></small>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark"><?php echo formatCompanyDate($client['created_at']); ?></div>
                                    <small class="text-muted"><?php echo formatCompanyTime($client['created_at']); ?></small>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="view.php?id=<?php echo $client['id']; ?>" 
                                           class="btn btn-sm btn-light text-primary no-theme shadow-sm" title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $client['id']; ?>" 
                                           class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-light text-danger shadow-sm" 
                                                onclick="confirmDelete(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name']); ?>')" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                    <?php foreach ($clients as $client): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                    <?php if ($client['client_type'] === 'company'): ?>
                                        <span class="badge rounded-pill bg-dark text-white">Empresa</span>
                                    <?php
        else: ?>
                                        <span class="badge rounded-pill bg-success text-white">Persona Natural</span>
                                    <?php
        endif; ?>
                                    <?php if ($client['id_number']): ?>
                                        <span class="font-monospace text-dark bg-light px-2 py-1 rounded small"><?php echo htmlspecialchars($client['id_number']); ?></span>
                                    <?php
        endif; ?>
                                </div>
                                <div class="card-body py-2">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="avatar-circle me-3 bg-light text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="fas <?php echo $client['client_type'] === 'company' ? 'fa-building' : 'fa-user'; ?> fa-lg"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1 text-dark">
                                                <?php echo htmlspecialchars($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name']); ?>
                                            </h6>
                                            <?php if ($client['client_type'] === 'company' && $client['legal_representative']): ?>
                                                <small class="text-muted">Rep: <?php echo htmlspecialchars($client['legal_representative']); ?></small>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                                        <?php if ($client['phone']): ?>
                                            <div class="mb-1"><i class="fas fa-phone text-muted me-2"></i><?php echo getCompanyFullPhone($client['phone']); ?></div>
                                        <?php
        endif; ?>
                                        <?php if ($client['email']): ?>
                                            <div><i class="fas fa-envelope text-muted me-2"></i><?php echo htmlspecialchars($client['email']); ?></div>
                                        <?php
        endif; ?>
                                        <?php if (!$client['phone'] && !$client['email']): ?>
                                            <div class="fst-italic"><i class="fas fa-info-circle text-muted me-2"></i>Sin contacto registrado</div>
                                        <?php
        endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                                    <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                        <span><i class="fas fa-calendar-alt me-1"></i><?php echo formatCompanyDate($client['created_at']); ?></span>
                                        <span><i class="fas fa-clock me-1"></i><?php echo formatCompanyTime($client['created_at']); ?></span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 pb-1 justify-content-end">
                                        <a href="view.php?id=<?php echo $client['id']; ?>" class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Ver"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo $client['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                        <button type="button" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" onclick="confirmDelete(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name'], ENT_QUOTES); ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
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
                <div class="card-footer bg-white border-top border-light py-3">
                    <nav aria-label="Paginación de clientes">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-left me-1"></i> Anterior
                                    </a>
                                </li>
                            <?php
        endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 rounded-circle <?php echo $i === $page ? 'bg-primary text-white shadow-sm' : 'text-muted'; ?> mx-1 d-flex align-items-center justify-content-center" 
                                       style="width: 35px; height: 35px;"
                                       href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php
        endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link border-0 text-muted" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">
                                        Siguiente <i class="fas fa-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            <?php
        endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php
    endif; ?>
        <?php
endif; ?>
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
                    <strong>Cliente:</strong> <span id="clientName"></span>
                    <br>
                    <small>Esta acción no se puede deshacer. Se eliminará toda la información del cliente.</small>
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
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        const isInModal = !!alert.closest('.modal');
        const isPersistent = alert.hasAttribute('data-persistent') || alert.classList.contains('persistent');
        if (!isInModal && !isPersistent) {
            setTimeout(function() {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        }
    });
});

let clientToDeleteId = 0;

function performDelete(id) {
    const btn = document.getElementById('confirmDeleteBtn');
    const originalHTML = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando...'; btn.disabled = true; }
    fetch('delete_client.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ client_id: id, csrf_token: '<?php echo $csrf_token; ?>' })
    })
    .then(r => r.json())
    .then(data => {
        const deleteModalEl = document.getElementById('deleteModal');
        const modal = deleteModalEl ? bootstrap.Modal.getInstance(deleteModalEl) : null;
        if (data.success) {
            window.location.href = 'index.php?success=' + encodeURIComponent('Cliente eliminado exitosamente');
        } else {
            showAlert('danger', data.message || 'Error al eliminar el cliente');
            if (btn) { btn.innerHTML = originalHTML; btn.disabled = false; }
            if (modal) modal.hide();
        }
    })
    .catch(() => {
        const deleteModalEl = document.getElementById('deleteModal');
        const modal = deleteModalEl ? bootstrap.Modal.getInstance(deleteModalEl) : null;
        if (modal) modal.hide();
        showAlert('danger', 'Error de conexión al eliminar el cliente');
        if (btn) { btn.innerHTML = originalHTML; btn.disabled = false; }
    });
}

function confirmDelete(clientId, clientName) {
    clientToDeleteId = clientId;
    const nameEl = document.getElementById('clientName');
    if (nameEl) nameEl.textContent = clientName;
    const modalEl = document.getElementById('deleteModal');
    if (modalEl) {
        new bootstrap.Modal(modalEl).show();
    } else if (typeof showConfirm === 'function') {
        showConfirm(`¿Estás seguro de que quieres eliminar al cliente "${clientName}"?`, function(){ performDelete(clientId); });
    } else {
        if (confirm(`¿Estás seguro de que quieres eliminar al cliente "${clientName}"?`)) performDelete(clientId);
    }
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!clientToDeleteId) return;
    performDelete(clientToDeleteId);
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
