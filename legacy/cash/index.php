<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
$hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');
$cashPaymentMethods = ['Efectivo', 'Transferencia', 'Tarjeta', 'Otros'];
try {
    $hasStatus = false;
    $hasIsActive = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
        $hasStatus = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
        $hasIsActive = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}
    $sqlPm = "SELECT name FROM payment_methods";
    $paramsPm = [];
    if ($hasTenantPaymentMethods && !$perDatabase) {
        $sqlPm .= " WHERE tenant_id = ?";
        $paramsPm[] = $tenantValue;
    } else {
        $sqlPm .= " WHERE 1=1";
    }
    if ($hasStatus) {
        $sqlPm .= " AND status = 'active'";
    } elseif ($hasIsActive) {
        $sqlPm .= " AND is_active = 1";
    }
    $sqlPm .= " ORDER BY name ASC";
    $stmtPm = $pdo->prepare($sqlPm);
    $stmtPm->execute($paramsPm);
    foreach (($stmtPm->fetchAll(PDO::FETCH_COLUMN) ?: []) as $pmName) {
        $n = trim((string)$pmName);
        if ($n !== '' && !in_array($n, $cashPaymentMethods, true)) {
            $cashPaymentMethods[] = $n;
        }
    }
} catch (Throwable $e) {}

// Obtener última sesión cerrada para sugerir montos iniciales
$stmt = $pdo->prepare("
    SELECT total_cash, total_transfer, system_total, final_amount 
    FROM cash_sessions 
    WHERE status = 'closed' " . (($hasTenantCashSessions && !$perDatabase) ? "AND tenant_id = ?" : "") . "
    ORDER BY id DESC LIMIT 1
");
$stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
$lastSessionStats = $stmt->fetch(PDO::FETCH_ASSOC);

$suggestedCash = 0;
$suggestedTransfer = 0;

if ($lastSessionStats) {
    // Calcular egresos (system_total - final_amount)
    // Asumimos que todos los egresos salen de efectivo (comportamiento estándar del sistema)
    $lastExpenses = $lastSessionStats['system_total'] - $lastSessionStats['final_amount'];

    // El total_cash guardado es el Ingreso Bruto (Inicial + Ventas)
    // Restamos egresos para obtener el efectivo real en caja
    $suggestedCash = $lastSessionStats['total_cash'] - $lastExpenses;

    // Transferencias no suelen tener egresos en caja chica
    $suggestedTransfer = $lastSessionStats['total_transfer'];

    // Asegurar que no sea negativo (por si acaso)
    if ($suggestedCash < 0)
        $suggestedCash = 0;
}

// Obtener sesión actual con nombre de usuario
$joinUsers = ($hasTenantUsers && $hasTenantCashSessions && !$perDatabase) ? "LEFT JOIN users u ON s.opened_by = u.id AND u.tenant_id = s.tenant_id" : "LEFT JOIN users u ON s.opened_by = u.id";
$stmt = $pdo->prepare("
    SELECT s.*, u.name as opener_name 
    FROM cash_sessions s 
    $joinUsers
    WHERE s.status = 'open' " . (($hasTenantCashSessions && !$perDatabase) ? "AND s.tenant_id = ?" : "") . "
    ORDER BY s.id DESC LIMIT 1
");
$stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
$currentSession = $stmt->fetch(PDO::FETCH_ASSOC);

// Inicializar totales
$stats = [
    'income_total' => 0,
    'income_cash' => 0,
    'income_transfer' => 0,
    'income_card' => 0,
    'income_other' => 0,
    'expenses_total' => 0,
    'expenses_cash' => 0,
    'expenses_transfer' => 0,
    'expenses_card' => 0,
    'expenses_other' => 0,
    'balance_cash' => 0,
    'balance_net' => 0
];

if ($currentSession) {
    // Obtener ingresos por método de pago
    $stmt = $pdo->prepare("
        SELECT payment_method, SUM(amount) as total 
        FROM cash_income 
        WHERE cash_session_id = ? " . (($hasTenantCashIncome && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        GROUP BY payment_method
    ");
    $stmt->execute(($hasTenantCashIncome && !$perDatabase) ? [$currentSession['id'], $tenantValue] : [$currentSession['id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = floatval($row['total']);
        $stats['income_total'] += $amount;
        $method = strtolower(trim($row['payment_method'] ?? ''));
        if ($method === '') {
            $stats['income_other'] += $amount;
            continue;
        }
        if (strpos($method, 'efectivo') !== false) {
            $stats['income_cash'] += $amount;
        }
        elseif (strpos($method, 'transfer') !== false || strpos($method, 'banco') !== false || strpos($method, 'nequi') !== false || strpos($method, 'daviplata') !== false || strpos($method, 'pse') !== false) {
            $stats['income_transfer'] += $amount;
        }
        elseif (strpos($method, 'tarjeta') !== false || strpos($method, 'visa') !== false || strpos($method, 'master') !== false || strpos($method, 'crédito') !== false || strpos($method, 'credito') !== false || strpos($method, 'débito') !== false || strpos($method, 'debito') !== false) {
            $stats['income_card'] += $amount;
        }
        else {
            $stats['income_other'] += $amount;
        }
    }

    $hasExpensePaymentMethod = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM cash_expenses LIKE 'payment_method'");
        $hasExpensePaymentMethod = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}
    if ($hasExpensePaymentMethod) {
        $stmt = $pdo->prepare("
            SELECT payment_method, SUM(amount) as total
            FROM cash_expenses
            WHERE cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
            GROUP BY payment_method
        ");
        $stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$currentSession['id'], $tenantValue] : [$currentSession['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = floatval($row['total']);
            $stats['expenses_total'] += $amount;
            $method = strtolower(trim($row['payment_method'] ?? ''));
            if ($method === '' || strpos($method, 'efectivo') !== false) {
                $stats['expenses_cash'] += $amount;
            } elseif (strpos($method, 'transfer') !== false || strpos($method, 'banco') !== false || strpos($method, 'nequi') !== false || strpos($method, 'daviplata') !== false || strpos($method, 'pse') !== false) {
                $stats['expenses_transfer'] += $amount;
            } elseif (strpos($method, 'tarjeta') !== false || strpos($method, 'visa') !== false || strpos($method, 'master') !== false || strpos($method, 'crédito') !== false || strpos($method, 'credito') !== false || strpos($method, 'débito') !== false || strpos($method, 'debito') !== false) {
                $stats['expenses_card'] += $amount;
            } else {
                $stats['expenses_other'] += $amount;
            }
        }
    } else {
        $sql = "SELECT SUM(amount) as total FROM cash_expenses WHERE cash_session_id = ?" . (($hasTenantCashExpenses && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$currentSession['id'], $tenantValue] : [$currentSession['id']]);
        $stats['expenses_total'] = floatval($stmt->fetchColumn());
        $stats['expenses_cash'] = $stats['expenses_total'];
    }

    // Calcular balances
    // NOTA: No sumamos $currentSession['initial_amount'] porque ya está registrado en cash_income como ingreso tipo 'manual'
    $stats['balance_cash'] = $stats['income_cash'] - $stats['expenses_cash'];
    $stats['balance_net'] = $stats['income_total'] - $stats['expenses_total'];
}

// Obtener sesiones recientes
$stmt = $pdo->prepare("
    SELECT s.*, u.name as opener_name 
    FROM cash_sessions s 
    $joinUsers
    " . (($hasTenantCashSessions && !$perDatabase) ? "WHERE s.tenant_id = ?" : "") . "
    ORDER BY s.id DESC 
    LIMIT 5
");
$stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
$recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Caja";
ob_start();
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 mt-3 px-3 px-md-4 gap-3">
    <div class="text-center text-md-start">
        <h2 class="fw-bold mb-0"><i class="fas fa-cash-register me-2"></i>Caja</h2>
        <p class="text-muted mb-0">Gestión de sesiones de caja y movimientos</p>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-end w-100 w-md-auto">
        <?php if ($currentSession): ?>
            <button class="btn btn-outline-danger rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#closeCashModal">
                <i class="fas fa-lock me-2"></i>Cerrar Caja
            </button>
            <a href="movements.php" class="btn btn-outline-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-exchange-alt me-2"></i>Movimientos
            </a>
        <?php
endif; ?>
    </div>
</div>

<div class="px-4 pb-4">

        <?php if ($currentSession): ?>
            <!-- Estado de Caja Banner -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-lock-open fa-lg text-success me-3"></i>
                                <h4 class="mb-0 fw-bold">Estado de Caja</h4>
                            </div>
                            <div class="text-muted">
                                <span class="fw-bold text-success">Caja Abierta</span> desde <?php echo date('d/m/Y H:i', strtotime($currentSession['opening_date'])); ?>
                                <br>
                                <small>Sesión: <?php echo htmlspecialchars($currentSession['session_number']); ?> | Abierta por: <?php echo htmlspecialchars($currentSession['opener_name'] ?? 'Usuario'); ?></small>
                            </div>
                        </div>
                        <div>
                            <button class="btn btn-outline-danger rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#closeCashModal">
                                <i class="fas fa-lock me-2"></i>Cerrar Caja
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Row -->
            <div class="row g-3 mb-4">
                <!-- Total Ingresos -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-primary no-theme mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['income_total']); ?></h5>
                            <small class="text-muted d-block text-truncate">Total Ingresos</small>
                        </div>
                    </div>
                </div>
                <!-- Efectivo -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-success mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['income_cash']); ?></h5>
                            <small class="text-muted d-block text-truncate">Efectivo</small>
                        </div>
                    </div>
                </div>
                <!-- Transferencia -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-info no-theme mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['income_transfer']); ?></h5>
                            <small class="text-muted d-block text-truncate">Transferencia</small>
                        </div>
                    </div>
                </div>
                <!-- Tarjeta -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-warning mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['income_card']); ?></h5>
                            <small class="text-muted d-block text-truncate">Tarjeta</small>
                        </div>
                    </div>
                </div>
                <!-- Egresos -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-danger mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['expenses_total']); ?></h5>
                            <small class="text-muted d-block text-truncate">Egresos</small>
                        </div>
                    </div>
                </div>
                <!-- Neto -->
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                        <div class="card-body p-2">
                            <h5 class="fw-bold text-dark mb-1 fs-5 fs-md-4 text-truncate"><?php echo formatCurrency($stats['balance_net']); ?></h5>
                            <small class="text-muted d-block text-truncate">Neto</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Cards -->
            <div class="row g-4 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100 hover-card">
                        <div class="card-body text-center p-3 p-md-4">
                            <div class="icon-circle bg-success text-white mb-2 mx-auto" style="width: 45px; height: 45px;">
                                <i class="fas fa-plus"></i>
                            </div>
                            <h6 class="fw-bold text-success mb-1">Registrar Ingreso</h6>
                            <p class="text-muted small mb-3 d-none d-sm-block">Agregar dinero a la caja</p>
                            <button class="btn btn-link py-0 text-success text-decoration-none fw-bold small stretched-link" data-bs-toggle="modal" data-bs-target="#incomeModal">
                                Ingreso
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100 hover-card">
                        <div class="card-body text-center p-3 p-md-4">
                            <div class="icon-circle bg-danger text-white mb-2 mx-auto" style="width: 45px; height: 45px;">
                                <i class="fas fa-minus"></i>
                            </div>
                            <h6 class="fw-bold text-danger mb-1">Registrar Egreso</h6>
                            <p class="text-muted small mb-3 d-none d-sm-block">Retirar dinero de la caja</p>
                            <button class="btn btn-link py-0 text-danger text-decoration-none fw-bold small stretched-link" data-bs-toggle="modal" data-bs-target="#expenseModal">
                                Egreso
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100 hover-card">
                        <div class="card-body text-center p-3 p-md-4">
                            <div class="icon-circle bg-info no-theme text-white mb-2 mx-auto" style="width: 45px; height: 45px;">
                                <i class="fas fa-exchange-alt no-theme"></i>
                            </div>
                            <h6 class="fw-bold text-info no-theme mb-1">Movimientos</h6>
                            <p class="text-muted small mb-3 d-none d-sm-block">Ver historial de movimientos</p>
                            <a href="movements.php" class="btn btn-link py-0 text-info no-theme text-decoration-none fw-bold small stretched-link">
                                Ver Movimientos
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100 hover-card">
                        <div class="card-body text-center p-3 p-md-4">
                            <div class="icon-circle bg-warning text-white mb-2 mx-auto" style="width: 45px; height: 45px;">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <h6 class="fw-bold text-warning mb-1">Arqueo</h6>
                            <p class="text-muted small mb-3 d-none d-sm-block">Realizar conteo de caja</p>
                            <a href="count.php" class="btn btn-link py-0 text-warning text-decoration-none fw-bold small stretched-link">
                                Arqueo
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <?php
else: ?>
            <!-- Caja Cerrada State -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 text-center py-5">
                <div class="card-body">
                    <div class="icon-circle bg-secondary text-white mb-3 mx-auto" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="fw-bold text-secondary">La Caja está Cerrada</h3>
                    <p class="text-muted mb-4">Debe abrir una nueva sesión para comenzar a registrar movimientos.</p>
                    <button class="btn btn-primary btn-lg rounded-pill px-5" data-bs-toggle="modal" data-bs-target="#unifiedOpenCashModal">
                        <i class="fas fa-key me-2"></i> Abrir Caja
                    </button>
                </div>
            </div>
        <?php
endif; ?>

        <!-- Sesiones Recientes -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2"></i>Sesiones Recientes</h5>
                <a href="history.php" class="btn btn-sm btn-link">Ver Todas</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0 text-nowrap">
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
                            <?php foreach ($recentSessions as $session): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo htmlspecialchars($session['session_number']); ?></td>
                                    <td>
                                        <?php if ($session['status'] == 'open'): ?>
                                            <span class="badge bg-success rounded-pill px-3">ABIERTA</span>
                                        <?php
    else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3">CERRADA</span>
                                        <?php
    endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-bold"><?php echo date('d/m/Y H:i', strtotime($session['opening_date'])); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($session['opener_name'] ?? 'Usuario'); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($session['closing_date']): ?>
                                            <div class="small"><?php echo date('d/m/Y H:i', strtotime($session['closing_date'])); ?></div>
                                        <?php
    else: ?>
                                            <span class="text-muted">-</span>
                                        <?php
    endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($session['initial_amount']); ?></td>
                                    <td><?php echo $session['final_amount'] > 0 ? formatCurrency($session['final_amount']) : '$ 0'; ?></td>
                                    <td class="<?php echo $session['difference'] < 0 ? 'text-danger' : ($session['difference'] > 0 ? 'text-success' : ''); ?> fw-bold">
                                        <?php echo formatCurrency($session['difference']); ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group">
                                            <a href="view.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-light text-primary no-theme" title="Ver Detalle">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($session['status'] == 'closed'): ?>
                                                <a href="print_closing.php?id=<?php echo $session['id']; ?>" target="_blank" class="btn btn-sm btn-light text-dark" title="Imprimir">
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

                <!-- Vista Móvil (Tarjetas) -->
                <div class="d-block d-lg-none">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recentSessions as $session): ?>
                            <li class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold">Sesión #<?php echo htmlspecialchars($session['session_number']); ?></span>
                                    <?php if ($session['status'] == 'open'): ?>
                                        <span class="badge bg-success rounded-pill px-2">ABIERTA</span>
                                    <?php
    else: ?>
                                        <span class="badge bg-secondary rounded-pill px-2">CERRADA</span>
                                    <?php
    endif; ?>
                                </div>
                                <div class="small mb-2">
                                    <div class="text-muted"><i class="fas fa-lock-open me-1"></i> <?php echo date('d/m/Y H:i', strtotime($session['opening_date'])); ?> por <?php echo htmlspecialchars($session['opener_name'] ?? 'Usuario'); ?></div>
                                    <?php if ($session['closing_date']): ?>
                                        <div class="text-muted"><i class="fas fa-lock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($session['closing_date'])); ?></div>
                                    <?php
    endif; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-0 small">
                                    <div>
                                        <span class="text-muted d-block">Inicial</span>
                                        <span class="fw-bold"><?php echo formatCurrency($session['initial_amount']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="text-muted d-block">Final</span>
                                        <span class="fw-bold"><?php echo $session['final_amount'] > 0 ? formatCurrency($session['final_amount']) : '$ 0'; ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="text-muted d-block">Dif</span>
                                        <span class="fw-bold <?php echo $session['difference'] < 0 ? 'text-danger' : ($session['difference'] > 0 ? 'text-success' : ''); ?>">
                                            <?php echo formatCurrency($session['difference']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3 pt-2 border-top">
                                    <a href="view.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-outline-primary w-100" title="Ver Detalle">
                                        <i class="fas fa-eye me-1"></i> Ver
                                    </a>
                                    <?php if ($session['status'] == 'closed'): ?>
                                        <a href="print_closing.php?id=<?php echo $session['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100" title="Imprimir">
                                            <i class="fas fa-print me-1"></i> Imprimir
                                        </a>
                                    <?php
    endif; ?>
                                </div>
                            </li>
                        <?php
endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.icon-circle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.hover-card {
    transition: transform 0.2s;
}
.hover-card:hover {
    transform: translateY(-5px);
}

#closeCashModal .input-group {
    background-color: #f8fafc;
    border: 1px solid #d9e1ea;
    border-radius: 12px;
    overflow: hidden;
}

#closeCashModal .input-group .input-group-text {
    background-color: transparent !important;
    border: 0 !important;
    color: #6b7280;
    min-width: 54px;
    justify-content: center;
}

#closeCashModal .input-group .form-control {
    background-color: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}

#closeCashModal .input-group .btn {
    border: 0 !important;
    border-left: 1px solid #d9e1ea !important;
    background-color: transparent !important;
    color: #374151;
}

#closeCashModal .input-group:focus-within {
    background-color: #ffffff;
    border-color: #9ca3af;
    box-shadow: 0 0 0 0.2rem rgba(156, 163, 175, 0.18);
}

#closeCashModal textarea.form-control {
    background-color: #f8fafc !important;
    border: 1px solid #d9e1ea !important;
    border-radius: 12px;
    box-shadow: none !important;
    resize: vertical;
    min-height: 90px;
}

#closeCashModal textarea.form-control:focus {
    background-color: #ffffff !important;
    border-color: #9ca3af !important;
    box-shadow: 0 0 0 0.2rem rgba(156, 163, 175, 0.18) !important;
}

</style>

<!-- Modal Abrir Caja -->
<?php include '../includes/modals/cash_open_modal.php'; ?>

<?php include '../includes/modals/cash_income_expense_modals.php'; ?>

<!-- Modal Cerrar Caja -->
<div class="modal fade" id="closeCashModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-bottom bg-white text-dark p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-light rounded p-2 me-3 border">
                        <i class="fas fa-lock fa-lg text-secondary"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold">Cerrar Caja</h5>
                        <p class="mb-0 small text-muted">Finaliza la sesión de ventas</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="closeCashForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken()); ?>">
                    <div class="bg-light p-3 mb-4 rounded-3 d-flex justify-content-between align-items-center border">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-info-circle text-primary me-2"></i>
                            <span class="fw-bold small text-uppercase text-secondary">Total en Sistema</span>
                        </div>
                        <span class="h4 mb-0 fw-bold text-dark"><?php echo formatCurrency($stats['balance_net']); ?></span>
                    </div>

                    <!-- Resumen por método (Sistema) -->
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                                <div class="card-body p-2">
                                    <h6 class="fw-bold text-success mb-1 text-truncate"><?php echo formatCurrency($stats['income_cash']); ?></h6>
                                    <small class="text-muted text-truncate d-block">Efectivo (Sistema)</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                                <div class="card-body p-2">
                                    <h6 class="fw-bold text-info no-theme mb-1 text-truncate"><?php echo formatCurrency($stats['income_transfer']); ?></h6>
                                    <small class="text-muted text-truncate d-block">Transferencia (Sistema)</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                                <div class="card-body p-2">
                                    <h6 class="fw-bold text-warning mb-1 text-truncate"><?php echo formatCurrency($stats['income_card']); ?></h6>
                                    <small class="text-muted text-truncate d-block">Tarjeta (Sistema)</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center h-100 py-3">
                                <div class="card-body p-2">
                                    <h6 class="fw-bold text-dark mb-1 text-truncate"><?php echo formatCurrency($stats['income_other']); ?></h6>
                                    <small class="text-muted text-truncate d-block">Otros (Sistema)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Efectivo en Caja</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-end-0 text-muted ps-3">
                                    <i class="fas fa-money-bill-wave"></i>
                                </span>
                                <input type="text" class="form-control bg-light border-start-0 ps-2 fw-bold text-dark" 
                                       name="physical_cash" id="physical_cash" 
                                       onkeyup="formatCurrencyInput(this)" required
                                       style="box-shadow: none;">
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleCalculator()">
                                    <i class="fas fa-calculator"></i>
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1">Sistema: <?php echo formatCurrency($stats['balance_cash']); ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Total Transferencias</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-end-0 text-muted ps-3">
                                    <i class="fas fa-university"></i>
                                </span>
                                <input type="text" class="form-control bg-light border-start-0 ps-2 fw-bold text-dark" 
                                       name="physical_transfer" id="physical_transfer" 
                                       onkeyup="formatCurrencyInput(this)"
                                       style="box-shadow: none;">
                            </div>
                            <small class="text-muted d-block mt-1">Sistema: <?php echo formatCurrency($stats['income_transfer'] - $stats['expenses_transfer']); ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Total Tarjetas</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-end-0 text-muted ps-3">
                                    <i class="fas fa-credit-card"></i>
                                </span>
                                <input type="text" class="form-control bg-light border-start-0 ps-2 fw-bold text-dark" 
                                       name="physical_card" id="physical_card" 
                                       onkeyup="formatCurrencyInput(this)"
                                       style="box-shadow: none;">
                            </div>
                            <small class="text-muted d-block mt-1">Sistema: <?php echo formatCurrency($stats['income_card'] - $stats['expenses_card']); ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Otros</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-end-0 text-muted ps-3">
                                    <i class="fas fa-ellipsis-h"></i>
                                </span>
                                <input type="text" class="form-control bg-light border-start-0 ps-2 fw-bold text-dark" 
                                       name="physical_other" id="physical_other" 
                                       onkeyup="formatCurrencyInput(this)"
                                       style="box-shadow: none;">
                            </div>
                            <small class="text-muted d-block mt-1">Sistema: <?php echo formatCurrency($stats['income_other'] - $stats['expenses_other']); ?></small>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-light text-dark border"><i class="fas fa-balance-scale me-1"></i>Dif. Efectivo: <span id="diffCash">$ 0</span></span>
                            <span class="badge bg-light text-dark border"><i class="fas fa-balance-scale me-1"></i>Dif. Transferencia: <span id="diffTransfer">$ 0</span></span>
                            <span class="badge bg-light text-dark border"><i class="fas fa-balance-scale me-1"></i>Dif. Tarjeta: <span id="diffCard">$ 0</span></span>
                            <span class="badge bg-light text-dark border"><i class="fas fa-balance-scale me-1"></i>Dif. Otros: <span id="diffOther">$ 0</span></span>
                            <span class="badge bg-warning-subtle text-dark border fw-bold">Diferencia Total: <span id="diffTotal">$ 0</span></span>
                        </div>
                    </div>
                    
                    <!-- Calculadora de efectivo (Oculta por defecto) -->
                    <div id="cashCalculator" class="card mt-3 d-none border-warning">
                        <div class="card-header bg-warning-subtle text-dark py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold"><i class="fas fa-coins me-2"></i>Calculadora de Efectivo</span>
                                <span id="calcTotalDisplay" class="fw-bold fs-5">$ 0</span>
                            </div>
                        </div>
                        <div class="card-body p-2">
                            <div class="row">
                                <div class="col-md-6 border-end">
                                    <h6 class="text-center text-muted small mb-2">Billetes</h6>
                                    <table class="table table-sm table-borderless mb-0">
                                        <tbody id="tbody-bills"></tbody>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-center text-muted small mb-2">Monedas</h6>
                                    <table class="table table-sm table-borderless mb-0">
                                        <tbody id="tbody-coins"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white py-2 text-end">
                            <button type="button" class="btn btn-sm btn-warning" onclick="applyCalculatorTotal()">Aplicar Total</button>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">Observaciones de Cierre</label>
                        <textarea class="form-control" name="closing_observations" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-5 fw-bold ms-auto shadow-sm">
                        <i class="fas fa-lock me-2"></i> Confirmar Cierre
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/utils.js"></script>
<script>
// Configuración de moneda global
window.SYSTEM_CONFIG = {
    currency: <?php echo json_encode(CompanySettings::getCurrency()); ?>
};
window.CLOSE_SYSTEM_TOTALS = {
    cash: <?php echo json_encode($stats['balance_cash']); ?>,
    transfer: <?php echo json_encode($stats['income_transfer'] - $stats['expenses_transfer']); ?>,
    card: <?php echo json_encode($stats['income_card'] - $stats['expenses_card']); ?>,
    other: <?php echo json_encode($stats['income_other'] - $stats['expenses_other']); ?>,
    net: <?php echo json_encode($stats['balance_net']); ?>
};

document.addEventListener('DOMContentLoaded', function() {
    // Manejo de formularios
    
    // Formulario de cierre
    const closeCashForm = document.getElementById('closeCashForm');
    if (closeCashForm) {
        closeCashForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Limpiar formatos de moneda
            ['physical_cash', 'physical_transfer', 'physical_card', 'physical_other'].forEach(id => {
                const el = document.getElementById(id);
                if (el) formData.set(id, el.value.replace(/,/g, ''));
            });
            
            submitForm('ajax/close_cash_session.php', formData, '#closeCashModal');
        });
    }
    
    const inputs = ['physical_cash','physical_transfer','physical_card','physical_other'];
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateCloseDifferences);
    });
    const closeModalEl = document.getElementById('closeCashModal');
    if (closeModalEl) closeModalEl.addEventListener('shown.bs.modal', updateCloseDifferences);
    
    // Formulario de ingreso
    const incomeForm = document.getElementById('incomeForm');
    if (incomeForm) {
        incomeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const amount = document.getElementById('income_amount');
            if (amount) formData.set('amount', amount.value.replace(/,/g, ''));
            
            submitForm('ajax/add_income.php', formData, '#incomeModal');
        });
    }
    
    // Formulario de egreso
    const expenseForm = document.getElementById('expenseForm');
    if (expenseForm) {
        expenseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const amount = document.getElementById('expense_amount');
            if (amount) formData.set('amount', amount.value.replace(/,/g, ''));
            
            submitForm('ajax/add_expense.php', formData, '#expenseModal');
        });
    }
    
    // Resetear formularios al cerrar modal
    const modals = ['closeCashModal', 'incomeModal', 'expenseModal'];
    modals.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) form.reset();
                
                // Ocultar calculadora si está abierta en modal de cierre
                if (id === 'closeCashModal') {
                    const calc = document.getElementById('cashCalculator');
                    if (calc && !calc.classList.contains('d-none')) {
                        calc.classList.add('d-none');
                    }
                }
            });
        }
    });

    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('open_close_cash') === '1') {
            const raw = localStorage.getItem('cash_close_prefill');
            if (raw) {
                const data = JSON.parse(raw);
                const setVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (val === null || val === undefined || val === '') return;
                    const n = Number(val);
                    if (!Number.isFinite(n)) return;
                    el.value = n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                };
                setVal('physical_cash', data.physical_cash);
                setVal('physical_transfer', data.physical_transfer);
                setVal('physical_card', data.physical_card);
                setVal('physical_other', data.physical_other);
                localStorage.removeItem('cash_close_prefill');
            }
            const closeEl = document.getElementById('closeCashModal');
            if (closeEl) {
                const m = new bootstrap.Modal(closeEl);
                m.show();
                updateCloseDifferences();
            }
        }
    } catch (e) {}
});

// Función genérica para envío de formularios
function submitForm(url, formData, modalId) {
    const modalEl = document.querySelector(modalId);
    const submitBtn = modalEl.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
    
    fetch(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            // Cerrar modal
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
            
            // Recargar página
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error de conexión al procesar la solicitud');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

// --- Calculadora de Efectivo ---
const standardDenominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000];

function formatDisplay(n) {
    const sym = window.SYSTEM_CONFIG?.currency?.symbol || '$';
    return sym + ' ' + (Number(n || 0)).toLocaleString();
}
function updateCloseDifferences() {
    const pcash = parseCurrency(document.getElementById('physical_cash')?.value || 0);
    const ptransfer = parseCurrency(document.getElementById('physical_transfer')?.value || 0);
    const pcard = parseCurrency(document.getElementById('physical_card')?.value || 0);
    const pother = parseCurrency(document.getElementById('physical_other')?.value || 0);
    const dcash = pcash - (window.CLOSE_SYSTEM_TOTALS.cash || 0);
    const dtransfer = ptransfer - (window.CLOSE_SYSTEM_TOTALS.transfer || 0);
    const dcard = pcard - (window.CLOSE_SYSTEM_TOTALS.card || 0);
    const dother = pother - (window.CLOSE_SYSTEM_TOTALS.other || 0);
    const dtotal = (pcash + ptransfer + pcard + pother) - (window.CLOSE_SYSTEM_TOTALS.net || 0);
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = formatDisplay(val); };
    setText('diffCash', dcash);
    setText('diffTransfer', dtransfer);
    setText('diffCard', dcard);
    setText('diffOther', dother);
    setText('diffTotal', dtotal);
}
function toggleCalculator() {
    const calc = document.getElementById('cashCalculator');
    calc.classList.toggle('d-none');
    if (!calc.classList.contains('d-none')) {
        const tbodyBills = document.getElementById('tbody-bills');
        const tbodyCoins = document.getElementById('tbody-coins');
        if (tbodyBills.children.length === 0 && tbodyCoins.children.length === 0) {
            initCalculator();
        }
    }
}

function initCalculator() {
    const tbodyBills = document.getElementById('tbody-bills');
    const tbodyCoins = document.getElementById('tbody-coins');
    tbodyBills.innerHTML = '';
    tbodyCoins.innerHTML = '';
    
    standardDenominations.forEach(val => {
        const targetBody = val >= 2000 ? tbodyBills : tbodyCoins;
        addDenominationRow(val, '', true, targetBody);
    });
    calculateCashTotal();
}

function addDenominationRow(value = '', count = '', readonly = false, targetTbody = null) {
    if (!targetTbody) {
        targetTbody = document.getElementById('tbody-coins');
    }

    const tr = document.createElement('tr');
    tr.className = 'denomination-row';
    
    const displayValue = value ? parseFloat(value).toLocaleString('es-ES') : '';
    const readonlyAttr = readonly ? 'readonly style="background-color: #f8f9fa;"' : '';
    const removeBtn = readonly ? '' : `
        <button type="button" class="btn btn-link text-danger p-0" onclick="removeDenominationRow(this)">
            <i class="fas fa-times"></i>
        </button>`;

    tr.innerHTML = `
        <td style="width: 40%" class="py-1">
            <input type="text" class="form-control form-control-sm money-input denom-value px-1 py-0" 
                   style="height: 24px; font-size: 0.9rem;"
                   placeholder="Valor" value="${displayValue}" ${readonlyAttr} 
                   oninput="formatCurrencyInput(this); calculateCashTotal()">
        </td>
        <td style="width: 25%" class="py-1">
            <input type="number" class="form-control form-control-sm denom-count px-1 py-0 text-center" 
                   style="height: 24px; font-size: 0.9rem;"
                   placeholder="0" value="${count}" min="0" 
                   oninput="calculateCashTotal()" onclick="this.select()">
        </td>
        <td style="width: 30%" class="text-end align-middle py-1">
            <span class="denom-subtotal fw-bold small" style="font-size: 0.9rem;">0</span>
        </td>
        <td class="align-middle text-center py-1">
            ${removeBtn}
        </td>
    `;
    targetTbody.appendChild(tr);
    if(value && count) calculateCashTotal();
}

function removeDenominationRow(btn) {
    btn.closest('tr').remove();
    calculateCashTotal();
}

function calculateCashTotal() {
    let total = 0;
    const currencyConfig = window.SYSTEM_CONFIG?.currency || { symbol: '$' };
    const symbol = currencyConfig.symbol;
    
    document.querySelectorAll('.denomination-row').forEach(tr => {
        const valueInput = tr.querySelector('.denom-value');
        const countInput = tr.querySelector('.denom-count');
        const subtotalSpan = tr.querySelector('.denom-subtotal');
        
        const value = parseFloat(valueInput.value.replace(/,/g, '')) || 0;
        const count = parseInt(countInput.value) || 0;
        const subtotal = value * count;
        
        total += subtotal;
        subtotalSpan.textContent = symbol + ' ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    });
    
    const totalDisplay = document.getElementById('calcTotalDisplay');
    if (totalDisplay) {
        totalDisplay.textContent = symbol + ' ' + total.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
}

function applyCalculatorTotal() {
    let total = 0;
    document.querySelectorAll('.denomination-row').forEach(tr => {
        const valueInput = tr.querySelector('.denom-value');
        const countInput = tr.querySelector('.denom-count');
        const value = parseFloat(valueInput.value.replace(/,/g, '')) || 0;
        const count = parseInt(countInput.value) || 0;
        total += value * count;
    });
    
    const physicalCashInput = document.getElementById('physical_cash');
    if (physicalCashInput) {
        physicalCashInput.value = total.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        // Disparar evento input para formateo si es necesario
        physicalCashInput.dispatchEvent(new Event('input'));
    }
    
    toggleCalculator();
}
</script>
<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
