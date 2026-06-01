<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';

requireAuth();

// Obtener sesión de caja actual
$current_session = null;
$session_status = 'closed';
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

try {
    $sql = "
        SELECT * FROM cash_sessions 
        WHERE status = 'open' " . (($hasTenantCashSessions && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ORDER BY opening_date DESC 
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $current_session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_session) {
        $session_status = 'open';
    }
}
catch (PDOException $e) {
    error_log("Error al obtener sesión: " . $e->getMessage());
}

// Filtros
$filter_type = $_GET['type'] ?? 'all';
$session_id = $_GET['session_id'] ?? ($current_session['id'] ?? null);

// Construir consulta
$movements = [];

if ($session_id) {
    try {
        $selectedSessionId = null;
        if (is_numeric($session_id)) {
            $selectedSessionId = (int)$session_id;
        } else {
            $sql = "SELECT id FROM cash_sessions WHERE session_number = ?" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [(string)$session_id, $tenantValue] : [(string)$session_id]);
            $selectedSessionId = (int)($stmt->fetchColumn() ?: 0);
        }

        if (!$selectedSessionId) {
            $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
            $selectedSessionId = (int)($stmt->fetchColumn() ?: 0);
        }

        if ($selectedSessionId) {
            $sql = "SELECT * FROM cash_sessions WHERE id = ?" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$selectedSessionId, $tenantValue] : [$selectedSessionId]);
            $selected = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($selected) {
                $current_session = $selected;
                $session_status = ($current_session['status'] ?? 'closed') === 'open' ? 'open' : 'closed';
            }
        }

        $tableHasColumn = function($table, $column) use ($pdo) {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([(string)$column]);
                return $stmt->rowCount() > 0;
            } catch (Throwable $e) {
                return false;
            }
        };

        $incomeHasTenant = $tableHasColumn('cash_income', 'tenant_id');
        $expenseHasTenant = $tableHasColumn('cash_expenses', 'tenant_id');
        $incomeHasDescription = $tableHasColumn('cash_income', 'description');
        $expenseHasDescription = $tableHasColumn('cash_expenses', 'description');
        $hasExpensePaymentMethod = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM cash_expenses LIKE 'payment_method'");
            $hasExpensePaymentMethod = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        $expensePaymentExpr = $hasExpensePaymentMethod ? "COALESCE(NULLIF(ce.payment_method, ''), 'Efectivo')" : "'Efectivo'";
        $incomeDescriptionExpr = $incomeHasDescription ? "ci.description" : "NULL";
        $expenseDescriptionExpr = $expenseHasDescription ? "ce.description" : "NULL";
        $incomeUserJoin = ($hasTenantUsers && !$perDatabase) ? "LEFT JOIN users u ON ci.created_by = u.id AND u.tenant_id = ?" : "LEFT JOIN users u ON ci.created_by = u.id";
        $expenseUserJoin = ($hasTenantUsers && !$perDatabase) ? "LEFT JOIN users u ON ce.created_by = u.id AND u.tenant_id = ?" : "LEFT JOIN users u ON ce.created_by = u.id";

        $query = "
            SELECT 
                'income' as type,
                ci.id,
                ci.amount,
                ci.payment_method,
                ci.concept,
                $incomeDescriptionExpr as description,
                ci.notes,
                ci.reference_number,
                ci.created_at,
                u.name as user_name,
                tc.name as category_name
            FROM cash_income ci
            $incomeUserJoin
            LEFT JOIN transaction_categories tc ON ci.category_id = tc.id
            WHERE ci.cash_session_id = ?
        ";
        if ($incomeHasTenant && !$perDatabase) {
            $query .= " AND ci.tenant_id = ?";
        }

        if ($filter_type === 'expense') {
            $query = ""; // Limpiar si solo se piden egresos
        }

        $query_expenses = "
            SELECT 
                'expense' as type,
                ce.id,
                ce.amount,
                $expensePaymentExpr as payment_method,
                ce.concept,
                $expenseDescriptionExpr as description,
                ce.notes,
                ce.reference_number,
                ce.created_at,
                u.name as user_name,
                tc.name as category_name
            FROM cash_expenses ce
            $expenseUserJoin
            LEFT JOIN transaction_categories tc ON ce.category_id = tc.id
            WHERE ce.cash_session_id = ?
        ";
        if ($expenseHasTenant && !$perDatabase) {
            $query_expenses .= " AND ce.tenant_id = ?";
        }

        if ($filter_type === 'income') {
            $query_expenses = "";
        }

        $final_query = "";
        $params = [];

        if ($query && $query_expenses) {
            $final_query = "$query UNION ALL $query_expenses ORDER BY created_at DESC";
            $paramsIncome = [];
            if ($hasTenantUsers && !$perDatabase) { $paramsIncome[] = $tenantValue; }
            $paramsIncome[] = $selectedSessionId;
            if ($incomeHasTenant && !$perDatabase) { $paramsIncome[] = $tenantValue; }

            $paramsExpense = [];
            if ($hasTenantUsers && !$perDatabase) { $paramsExpense[] = $tenantValue; }
            $paramsExpense[] = $selectedSessionId;
            if ($expenseHasTenant && !$perDatabase) { $paramsExpense[] = $tenantValue; }

            $params = array_merge($paramsIncome, $paramsExpense);
        }
        elseif ($query) {
            $final_query = "$query ORDER BY created_at DESC";
            $params = [];
            if ($hasTenantUsers && !$perDatabase) { $params[] = $tenantValue; }
            $params[] = $selectedSessionId;
            if ($incomeHasTenant && !$perDatabase) { $params[] = $tenantValue; }
        }
        elseif ($query_expenses) {
            $final_query = "$query_expenses ORDER BY created_at DESC";
            $params = [];
            if ($hasTenantUsers && !$perDatabase) { $params[] = $tenantValue; }
            $params[] = $selectedSessionId;
            if ($expenseHasTenant && !$perDatabase) { $params[] = $tenantValue; }
        }

        if ($final_query) {
            $stmt = $pdo->prepare($final_query);
            $stmt->execute($params);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    }
    catch (PDOException $e) {
        error_log("Error al obtener movimientos: " . $e->getMessage());
    }
}

$page_title = 'Movimientos de Caja';


ob_start();
?>

<style>
/* Diseño Responsivo para Tabla de Movimientos */
@media (max-width: 767.98px) {
    #movementsTable thead {
        display: none;
    }
    #movementsTable, #movementsTable tbody, #movementsTable tr, #movementsTable td {
        display: block;
        width: 100%;
    }
    #movementsTable tr {
        margin-bottom: 1rem;
        background-color: #fff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 0.75rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
    }
    #movementsTable td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    #movementsTable td::before {
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
    #movementsTable td:last-child {
        border-bottom: none;
        background-color: #f8f9fa;
        font-weight: bold;
    }
    /* Special fix for the concept column text truncate on mobile */
    #movementsTable td:nth-child(3) .text-truncate {
        max-width: 15ch !important;
        white-space: normal;
    }
}
</style>

<div class="container-fluid px-0">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-exchange-alt me-2 text-primary"></i>Movimientos</h2>
            <div class="text-muted">
                <?php if ($session_id): ?>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-2">
                        <i class="fas fa-hashtag me-1"></i>Sesión #<?php echo htmlspecialchars($current_session['session_number'] ?? $session_id); ?>
                    </span>
                <?php
else: ?>
                    <span class="text-muted"><i class="fas fa-history me-1"></i>Histórico global</span>
                <?php
endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 d-flex align-items-center">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
            <?php if ($session_status === 'open'): ?>
                <button class="btn btn-success rounded-pill px-4 shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#incomeModal">
                    <i class="fas fa-plus-circle me-2"></i>Ingreso
                </button>
                <button class="btn btn-danger rounded-pill px-4 shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#expenseModal">
                    <i class="fas fa-minus-circle me-2"></i>Egreso
                </button>
            <?php
endif; ?>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card shadow-sm rounded-4 border-0 overflow-hidden">
        <!-- Filter Header -->
        <div class="card-header bg-white py-3 border-bottom border-light">
            <form class="row g-3 align-items-center" method="GET">
                <div class="col-auto">
                    <label class="col-form-label fw-bold text-muted small text-uppercase"><i class="fas fa-filter me-2"></i>Filtrar:</label>
                </div>
                <div class="col-auto">
                    <div class="input-group input-group-sm">
                        <select name="type" class="form-select border-light bg-light fw-semibold" onchange="this.form.submit()" style="min-width: 180px;">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>Todos los movimientos</option>
                            <option value="income" <?php echo $filter_type === 'income' ? 'selected' : ''; ?>>Solo Ingresos</option>
                            <option value="expense" <?php echo $filter_type === 'expense' ? 'selected' : ''; ?>>Solo Egresos</option>
                        </select>
                    </div>
                </div>
                <?php if ($session_id): ?>
                    <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session_id); ?>">
                <?php
endif; ?>
            </form>
        </div>
        
        <!-- Table Content -->
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="movementsTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4 py-3 border-0">Fecha</th>
                            <th class="border-0">Tipo</th>
                            <th class="border-0">Concepto</th>
                            <th class="border-0">Método</th>
                            <th class="border-0">Usuario</th>
                            <th class="text-end pe-4 border-0">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="py-5">
                                        <div class="mb-3">
                                            <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle text-muted" style="width: 80px; height: 80px;">
                                                <i class="fas fa-inbox fa-3x opacity-50"></i>
                                            </div>
                                        </div>
                                        <h5 class="text-muted fw-normal">No hay movimientos registrados</h5>
                                        <p class="text-muted small mb-0">Los ingresos y egresos de esta sesión aparecerán aquí.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php
else: ?>
                            <?php foreach ($movements as $mov): ?>
                                <tr>
                                    <td data-label="Fecha" class="ps-md-4 py-3">
                                        <div class="d-flex flex-column text-end text-md-start">
                                            <span class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($mov['created_at'])); ?></span>
                                            <span class="small text-muted"><?php echo date('H:i', strtotime($mov['created_at'])); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Tipo">
                                        <?php if ($mov['type'] === 'income'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                                                <i class="fas fa-arrow-down me-1"></i>Ingreso
                                            </span>
                                        <?php
        else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-2">
                                                <i class="fas fa-arrow-up me-1"></i>Egreso
                                            </span>
                                        <?php
        endif; ?>
                                    </td>
                                    <td data-label="Concepto" style="max-width: 300px;">
                                        <div class="d-flex flex-column align-items-end align-items-md-start">
                                            <div class="fw-bold text-dark text-truncate" title="<?php echo htmlspecialchars($mov['concept'] ?: $mov['description'] ?: 'Sin concepto'); ?>">
                                                <?php echo htmlspecialchars($mov['concept'] ?: $mov['description'] ?: 'Sin concepto'); ?>
                                            </div>
                                            <div class="d-flex gap-2 flex-wrap mt-1 justify-content-end justify-content-md-start">
                                                <?php if ($mov['category_name']): ?>
                                                    <span class="badge bg-light text-secondary border border-light-subtle rounded-1 fw-normal">
                                                        <?php echo htmlspecialchars($mov['category_name']); ?>
                                                    </span>
                                                <?php
        endif; ?>
                                                <?php if ($mov['notes'] || ($mov['concept'] && $mov['description'])): ?>
                                                    <span class="small text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($mov['notes'] ?: $mov['description']); ?>">
                                                        <i class="fas fa-sticky-note me-1 opacity-50"></i><?php echo htmlspecialchars($mov['notes'] ?: $mov['description']); ?>
                                                    </span>
                                                <?php
        endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Método">
                                        <?php
        $methodIcon = 'fa-money-bill-wave';
        $methodClass = 'text-success';
        $methodBg = 'bg-success-subtle';
        $method = strtolower($mov['payment_method'] ?? 'efectivo');

        if (strpos($method, 'tarjeta') !== false) {
            $methodIcon = 'fa-credit-card';
            $methodClass = 'text-warning';
            $methodBg = 'bg-warning-subtle';
        }
        elseif (strpos($method, 'transferencia') !== false) {
            $methodIcon = 'fa-university';
            $methodClass = 'text-info';
            $methodBg = 'bg-info-subtle';
        }
?>
                                        <div class="d-flex align-items-center justify-content-end justify-content-md-start w-100">
                                            <div class="d-flex align-items-center justify-content-center <?php echo $methodBg; ?> rounded-circle me-2" style="width: 32px; height: 32px;">
                                                <i class="fas <?php echo $methodIcon; ?> <?php echo $methodClass; ?> small"></i>
                                            </div>
                                            <div class="d-flex flex-column text-end text-md-start">
                                                <span class="small fw-semibold"><?php echo htmlspecialchars($mov['payment_method'] ?? 'Efectivo'); ?></span>
                                                <?php if ($mov['reference_number']): ?>
                                                    <span class="text-muted" style="font-size: 0.75rem;">Ref: <?php echo htmlspecialchars($mov['reference_number']); ?></span>
                                                <?php
        endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Usuario">
                                        <div class="d-flex align-items-center justify-content-end justify-content-md-start w-100">
                                            <div class="avatar-circle bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center me-2 border" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                <?php echo strtoupper(substr($mov['user_name'], 0, 2)); ?>
                                            </div>
                                            <span class="small text-end text-md-start"><?php echo htmlspecialchars($mov['user_name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Monto" class="text-end pe-md-4">
                                        <div class="fs-6 fw-bold <?php echo $mov['type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $mov['type'] === 'income' ? '+' : '-'; ?> 
                                            <?php echo formatCurrency($mov['amount']); ?>
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
        </div>
    </div>
</div>

<?php include '../includes/modals/cash_income_expense_modals.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
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

    // Reset forms on modal close
    ['incomeModal', 'expenseModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) form.reset();
            });
        }
    });
});

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
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
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
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
