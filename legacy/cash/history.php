<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';
$nonzero = isset($_GET['nonzero']) ? true : false;
$export = $_GET['export'] ?? '';

$where = "1=1";
$params = [];
if ($hasTenantCashSessions && !$perDatabase) {
    $where .= " AND s.tenant_id = ?";
    $params[] = $tenantValue;
}
if ($status === 'open') {
    $where .= " AND s.status = 'open'";
}
if ($status === 'closed') {
    $where .= " AND s.status = 'closed'";
}
if ($date_from !== '' && $date_to !== '') {
    $where .= " AND DATE(s.opening_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;

}
elseif ($date_from !== '') {
    $where .= " AND DATE(s.opening_date) >= ?";
    $params[] = $date_from;

}
elseif ($date_to !== '') {
    $where .= " AND DATE(s.opening_date) <= ?";
    $params[] = $date_to;

}
if ($nonzero) {
    $where .= " AND COALESCE(s.difference,0) <> 0";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_sessions s WHERE $where");
$stmt->execute($params);
$total = intval($stmt->fetchColumn());

$sql = "
    SELECT s.*, u.name as opener_name
    FROM cash_sessions s
    " . (($hasTenantUsers && $hasTenantCashSessions && !$perDatabase) ? "LEFT JOIN users u ON s.opened_by = u.id AND u.tenant_id = s.tenant_id" : "LEFT JOIN users u ON s.opened_by = u.id") . "
    WHERE $where
    ORDER BY s.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
foreach ($params as $idx => $val) {
    $stmt->bindValue($idx + 1, $val);
}
$stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=historial_caja_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sesión', 'Estado', 'Apertura', 'Cierre', 'Monto Inicial', 'Monto Final', 'Diferencia']);
    foreach ($sessions as $s) {
        fputcsv($out, [
            $s['session_number'],
            $s['status'],
            $s['opening_date'],
            $s['closing_date'],
            $s['initial_amount'],
            $s['final_amount'],
            $s['difference']
        ]);
    }
    fclose($out);
    exit;
}
$page_title = "Historial de Caja";
ob_start();
?>
<style>
/* Diseño Responsivo para Tabla de Historial */
@media (max-width: 767.98px) {
    #historyTable thead {
        display: none;
    }
    #historyTable, #historyTable tbody, #historyTable tr, #historyTable td {
        display: block;
        width: 100%;
    }
    #historyTable tr {
        margin-bottom: 1rem;
        background-color: #fff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 0.75rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
    }
    #historyTable td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    #historyTable td::before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #6c757d;
        width: 35%;
        flex-shrink: 0;
        margin-right: 1rem;
        text-align: left;
    }
    #historyTable td:last-child {
        border-bottom: none;
        background-color: #f8f9fa;
        font-weight: bold;
    }
}
</style>

    <div class="p-4">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mb-4 gap-3 text-center text-sm-start">
            <div>
                <h1><i class="fas fa-history me-2"></i>Historial de Caja</h1>
                <p class="text-muted mb-0">Sesiones de caja registradas</p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
                <a href="?export=csv<?php
$q = [];
if ($status !== 'all')
    $q[] = 'status=' . $status;
if ($date_from !== '')
    $q[] = 'from=' . $date_from;
if ($date_to !== '')
    $q[] = 'to=' . $date_to;
if ($nonzero)
    $q[] = 'nonzero=1';
echo count($q) ? '&' . implode('&', $q) : '';
?>" class="btn btn-outline-success">
                    <i class="fas fa-file-csv me-2"></i>Exportar CSV
                </a>
            </div>
        </div>
        <form class="card border-0 shadow-sm rounded-4 mb-3" method="GET">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label text-muted small text-uppercase">Estado</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Todos</option>
                            <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Abiertas</option>
                            <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Cerradas</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted small text-uppercase">Desde</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted small text-uppercase">Hasta</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="nonzero" id="nonzero" <?php echo $nonzero ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="nonzero">Solo con diferencias</label>
                        </div>
                    </div>
                    <div class="col-md-2 text-end">
                        <button class="btn btn-primary"><i class="fas fa-filter me-2"></i>Filtrar</button>
                    </div>
                </div>
            </div>
        </form>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="historyTable">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th class="ps-4 border-0">Sesión</th>
                                <th class="border-0">Estado</th>
                                <th class="border-0">Abierta</th>
                                <th class="border-0">Cerrada</th>
                                <th class="border-0">Monto Inicial</th>
                                <th class="border-0">Monto Final</th>
                                <th class="border-0">Diferencia</th>
                                <th class="text-end pe-4 border-0">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): ?>
                            <?php $diff = floatval($session['difference'] ?? 0); ?>
                            <tr>
                                <td data-label="Sesión" class="ps-md-4 fw-bold text-end text-md-start"><?php echo htmlspecialchars($session['session_number']); ?></td>
                                <td data-label="Estado" class="text-end text-md-start">
                                    <?php if ($session['status'] == 'open'): ?>
                                        <span class="badge bg-success rounded-pill px-3">ABIERTA</span>
                                    <?php
    else: ?>
                                        <span class="badge bg-secondary rounded-pill px-3">CERRADA</span>
                                    <?php
    endif; ?>
                                </td>
                                <td data-label="Abierta" class="text-end text-md-start">
                                    <div class="small fw-bold"><?php echo date('d/m/Y H:i', strtotime($session['opening_date'])); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($session['opener_name'] ?? 'Usuario'); ?></div>
                                </td>
                                <td data-label="Cerrada" class="text-end text-md-start">
                                    <?php if (!empty($session['closing_date'])): ?>
                                        <div class="small"><?php echo date('d/m/Y H:i', strtotime($session['closing_date'])); ?></div>
                                    <?php
    else: ?>
                                        <span class="text-muted">-</span>
                                    <?php
    endif; ?>
                                </td>
                                <td data-label="Monto Inicial" class="text-end text-md-start"><?php echo formatCurrency($session['initial_amount']); ?></td>
                                <td data-label="Monto Final" class="text-end text-md-start"><?php echo($session['final_amount'] > 0) ? formatCurrency($session['final_amount']) : '$ 0'; ?></td>
                                <td data-label="Diferencia" class="<?php echo $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-success' : ''); ?> fw-bold text-end text-md-start">
                                    <?php echo formatCurrency($diff); ?>
                                </td>
                                <td data-label="Acciones" class="text-end pe-md-4">
                                    <div class="btn-group w-100 w-md-auto justify-content-end">
                                        <a href="view.php?id=<?php echo intval($session['id']); ?>" class="btn btn-sm btn-light text-primary" title="Ver Detalle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($session['status'] == 'closed'): ?>
                                        <a href="print_closing.php?id=<?php echo intval($session['id']); ?>" target="_blank" class="btn btn-sm btn-light text-dark" title="Imprimir">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <?php
    endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php
endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php $totalPages = max(1, (int)ceil($total / $limit));
if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-end">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>">Anterior</a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                </li>
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>">Siguiente</a>
                </li>
            </ul>
        </nav>
        <?php
endif; ?>
    </div>
<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
