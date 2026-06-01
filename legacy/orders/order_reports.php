<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

header('Content-Type: text/html; charset=UTF-8');

requireAuth();

$pdo = db();
// Obtener ID de la orden
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasWoTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTrTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'technical_reports') : false;
$hasUserTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'users') : false;

if (!$order_id) {
    header('Location: index.php?error=' . urlencode('ID de orden no válido'));
    exit();
}

// Obtener datos básicos de la orden
try {
    if (!$perDatabase && $hasWoTenant) {
        $stmt = $pdo->prepare("SELECT id, order_number, device_brand, device_model FROM work_orders WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$order_id, $tenantValue]);
    } else {
        $hasOrderNumber = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'order_number') : false;
        $sql = $hasOrderNumber
            ? "SELECT id, order_number, device_brand, device_model FROM work_orders WHERE id = ?"
            : "SELECT id, 0 AS order_number, device_brand, device_model FROM work_orders WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: index.php?error=' . urlencode('Orden no encontrada'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error de base de datos'));
    exit();
}

// Número de orden formateado con prefijo
$order_num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
$order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
$order_display = $order_prefix . '-' . str_pad($order_num, 6, '0', STR_PAD_LEFT);

// Obtener informes técnicos
$reports = [];
try {
    if (!$perDatabase && $hasTrTenant) {
        $join = $hasUserTenant ? "LEFT JOIN users u ON tr.created_by = u.id AND u.tenant_id = tr.tenant_id" : "LEFT JOIN users u ON tr.created_by = u.id";
        $sql = "
            SELECT tr.id, tr.report_title, tr.created_at, u.name as created_by_name 
            FROM technical_reports tr
            {$join}
            WHERE tr.order_id = ? AND tr.tenant_id = ?
            ORDER BY tr.created_at DESC
        ";
        $stmtReports = $pdo->prepare($sql);
        $stmtReports->execute([$order_id, $tenantValue]);
    } else {
        $stmtReports = $pdo->prepare("
            SELECT tr.id, tr.report_title, tr.created_at, u.name as created_by_name 
            FROM technical_reports tr
            LEFT JOIN users u ON tr.created_by = u.id
            WHERE tr.order_id = ?
            ORDER BY tr.created_at DESC
        ");
        $stmtReports->execute([$order_id]);
    }
    $reports = $stmtReports->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reports = [];
}

$report_seq_by_id = [];
if (!empty($reports)) {
    $reports_asc = $reports;
    usort($reports_asc, function ($a, $b) {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }
        return $ta <=> $tb;
    });

    $i = 1;
    foreach ($reports_asc as $r) {
        $rid = (int)($r['id'] ?? 0);
        if ($rid > 0) {
            $report_seq_by_id[$rid] = $i;
            $i++;
        }
    }
}

// Generar token CSRF para eliminaciones
$csrf_token = SecurityEnhancements::generateCSRFToken();
?>

<?php
$page_title = "Informes Técnicos - " . $order_display;

ob_start();
?>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-3 mb-4">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-file-alt me-2 text-primary no-theme"></i>Informes Técnicos</h4>
                    <div class="text-muted small">
                        <?php echo htmlspecialchars($order_display); ?> -
                        <span class="fw-medium text-dark"><?php echo htmlspecialchars(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''))); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="view.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </a>
                    <a href="create_report.php?order_id=<?php echo $order['id']; ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        <i class="fas fa-plus me-2"></i>Nuevo Informe
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3">N°</th>
                        <th class="py-3">Título</th>
                        <th class="py-3">Creado por</th>
                        <th class="py-3">Fecha</th>
                        <th class="text-end pe-4 py-3">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($reports) > 0): ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td class="ps-4 fw-bold">
                                    #<?php echo (int)($report_seq_by_id[(int)$report['id']] ?? 0); ?>
                                </td>
                                <td><?php echo htmlspecialchars($report['report_title']); ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($report['created_by_name'] ?? 'Sistema'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($report['created_at'])); ?></td>
                                <td class="text-end pe-4">
                                    <button onclick="silentPrint(<?php echo $report['id']; ?>, this)" class="btn btn-sm btn-outline-secondary rounded-pill me-1" title="Imprimir Directamente">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <a href="print_report.php?id=<?php echo $report['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill me-1" title="Visualizar">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button onclick="silentDownload(<?php echo $report['id']; ?>, this)" class="btn btn-sm btn-outline-success rounded-pill me-1" title="Descargar PDF">
                                        <i class="fas fa-download"></i>
                                    </button>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger rounded-pill delete-report-btn"
                                            data-id="<?php echo $report['id']; ?>"
                                            data-order-id="<?php echo $order['id']; ?>"
                                            title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="mb-3">
                                    <div class="rounded-circle bg-light p-4 d-inline-block">
                                        <i class="fas fa-clipboard-list fa-3x text-muted opacity-50"></i>
                                    </div>
                                </div>
                                <h5 class="text-muted fw-normal">No hay informes técnicos</h5>
                                <p class="text-muted small mb-3">Crea el primer informe técnico para esta orden.</p>
                                <a href="create_report.php?order_id=<?php echo $order['id']; ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                    <i class="fas fa-plus me-2"></i>Crear Informe
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <iframe id="reportProcessor" name="reportProcessor" style="position: fixed; left: -10000px; top: 0; width: 1280px; height: 100vh; border: none;"></iframe>
    <input type="hidden" id="csrf_token" value="<?php echo $csrf_token; ?>">
</div>

<script>
function silentDownload(reportId, btn) {
    if (btn.classList.contains('disabled')) return;

    const originalHtml = btn.innerHTML;
    const originalTitle = btn.title;

    btn.classList.add('disabled');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.title = "Generando PDF...";

    const iframe = document.getElementById('reportProcessor');
    iframe.src = 'print_report.php?id=' + reportId + '&action=download_silent';

    const messageHandler = function(event) {
        if (event.data && (event.data.type === 'download_complete' || event.data.type === 'download_error') && event.data.reportId == reportId) {
            btn.classList.remove('disabled');
            btn.innerHTML = originalHtml;
            btn.title = originalTitle;

            if (event.data.type === 'download_error') {
                if (typeof showError === 'function') showError('Error: ' + event.data.error);
            }

            window.removeEventListener('message', messageHandler);
        }
    };

    window.addEventListener('message', messageHandler);

    setTimeout(function() {
        if (btn.classList.contains('disabled')) {
            btn.classList.remove('disabled');
            btn.innerHTML = originalHtml;
            btn.title = originalTitle;
            window.removeEventListener('message', messageHandler);
        }
    }, 15000);
}

function silentPrint(reportId, btn) {
    const iframe = document.getElementById('reportProcessor');
    iframe.src = 'print_report.php?id=' + reportId + '&action=print_silent';
}

document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e){
        const btn = e.target.closest('.delete-report-btn');
        if(!btn) return;
        e.preventDefault();

        const reportId = btn.getAttribute('data-id');
        const orderId = btn.getAttribute('data-order-id');
        const csrfTokenEl = document.getElementById('csrf_token');
        const csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

        const performDelete = function() {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('delete_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: reportId,
                    order_id: orderId,
                    csrf_token: csrfToken
                })
            })
            .then(function(response){ return response.json(); })
            .then(function(data){
                if(data.success) {
                    if (typeof showSuccess === 'function') {
                        showSuccess(data.message || 'Informe eliminado').then(function(){ window.location.reload(); });
                    } else {
                        alert(data.message || 'Informe eliminado');
                        window.location.reload();
                    }
                } else {
                    if (typeof showError === 'function') {
                        showError(data.message || 'Error al eliminar');
                    } else {
                        alert(data.message || 'Error al eliminar');
                    }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-trash"></i>';
                }
            })
            .catch(function(){
                if (typeof showError === 'function') {
                    showError('Error de red al intentar eliminar el informe');
                } else {
                    alert('Error de red');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash"></i>';
            });
        };

        if (typeof showConfirm === 'function') {
            showConfirm('¿Estás seguro de eliminar este informe?', performDelete);
        } else {
            if (confirm('¿Estás seguro de eliminar este informe?')) {
                performDelete();
            }
        }
    });
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
