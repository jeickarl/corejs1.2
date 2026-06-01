<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';

requireAuth();

// Obtener sesión de caja actual
$current_session = null;
$session_stats = null;
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
$hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');

try {
    $joinU = ($hasTenantUsers && $hasTenantCashSessions && !$perDatabase) ? "LEFT JOIN users u1 ON cs.opened_by = u1.id AND u1.tenant_id = cs.tenant_id" : "LEFT JOIN users u1 ON cs.opened_by = u1.id";
    $sql = "
        SELECT cs.*, 
               u1.name as opened_by_name
        FROM cash_sessions cs
        $joinU
        WHERE cs.status = 'open' " . (($hasTenantCashSessions && !$perDatabase) ? "AND cs.tenant_id = ?" : "") . "
        ORDER BY cs.opening_date DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $current_session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($current_session) {
        // Calcular totales del sistema
        $stmt = $pdo->prepare("
            SELECT 
                SUM(amount) as total_income,
                SUM(CASE WHEN payment_method = 'Efectivo' THEN amount ELSE 0 END) as cash_income
            FROM cash_income 
            WHERE cash_session_id = ? " . (($hasTenantCashIncome && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ");
        $stmt->execute(($hasTenantCashIncome && !$perDatabase) ? [$current_session['id'], $tenantValue] : [$current_session['id']]);
        $income_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasExpensePaymentMethod = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM cash_expenses LIKE 'payment_method'");
            $hasExpensePaymentMethod = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        if ($hasExpensePaymentMethod) {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(amount) as total_expenses,
                    SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%efectivo%' OR payment_method IS NULL OR payment_method = '' THEN amount ELSE 0 END) as cash_expenses
                FROM cash_expenses 
                WHERE cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(amount) as total_expenses,
                    SUM(amount) as cash_expenses
                FROM cash_expenses 
                WHERE cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
            ");
        }
        $stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$current_session['id'], $tenantValue] : [$current_session['id']]);
        $expense_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $initial_amount = $current_session['initial_amount'];
        $total_cash_income = $income_stats['cash_income'] ?? 0;
        $total_expenses = $expense_stats['total_expenses'] ?? 0;
        $cash_expenses = $expense_stats['cash_expenses'] ?? $total_expenses;
        
        // El total_cash_income ya incluye el monto inicial (concept_id=5)
        // Calculamos los ingresos adicionales para mostrar en el resumen
        $additional_cash_income = $total_cash_income - $initial_amount;
        if ($additional_cash_income < 0) $additional_cash_income = 0;
        
        $expected_cash = $total_cash_income - $cash_expenses;
        
        $session_stats = [
            'expected_cash' => $expected_cash,
            'initial_amount' => $initial_amount,
            'cash_income' => $additional_cash_income, // Solo ingresos adicionales
            'total_expenses' => $total_expenses
        ];
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos para arqueo: " . $e->getMessage());
}

$page_title = 'Arqueo de Caja';


ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1><i class="fas fa-calculator me-2"></i>Arqueo de Caja</h1>
        <p class="text-muted mb-0">Herramienta de conteo y verificación de efectivo</p>
    </div>
    <div class="btn-group">
        <?php if ($current_session): ?>
            <button type="button" class="btn btn-danger" onclick="goCloseCashFromCount()">
                <i class="fas fa-lock me-2"></i>Cerrar Caja
            </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver
        </a>
    </div>
</div>

<?php if (!$current_session): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        No hay una sesión de caja abierta. El arqueo se realiza sobre la sesión activa.
        <a href="index.php" class="alert-link">Ir a Caja</a>
    </div>
<?php else: ?>

<div class="row">
    <!-- Columna Izquierda: Calculadora -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-dark mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Conteo de Efectivo</h5>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="initCalculator()">
                    <i class="fas fa-redo-alt me-1"></i>Reiniciar
                </button>
            </div>
            <div class="card-body p-0">
                <div class="row g-0">
                    <!-- Billetes -->
                    <div class="col-md-6 border-end">
                        <div class="bg-light p-2 border-bottom fw-bold text-center text-muted small">Alta Denominación</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Denominación</th>
                                        <th class="text-center" style="width: 100px;">Cantidad</th>
                                        <th class="text-end">Total</th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-bills">
                                    <!-- JS generará filas -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Monedas -->
                    <div class="col-md-6">
                        <div class="bg-light p-2 border-bottom fw-bold text-center text-muted small">Baja Denominación</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Denominación</th>
                                        <th class="text-center" style="width: 100px;">Cantidad</th>
                                        <th class="text-end">Total</th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-coins">
                                    <!-- JS generará filas -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light p-3">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <button type="button" class="btn btn-outline-dark btn-sm" onclick="addDenominationRow()">
                            <i class="fas fa-plus-circle me-1"></i> Agregar Otra Denominación
                        </button>
                    </div>
                    <div class="col-md-6 text-end">
                        <h4 class="mb-0">
                            Total Físico: <span id="physicalTotalDisplay" class="fw-bold text-primary">$ 0</span>
                        </h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Columna Derecha: Resumen y Comparación -->
    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Resumen del Sistema</h5>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Monto Inicial</span>
                        <span class="fw-bold"><?php echo formatCurrency($session_stats['initial_amount']); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>(+) Ingresos Efectivo</span>
                        <span class="text-success"><?php echo formatCurrency($session_stats['cash_income']); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>(-) Egresos Efectivo</span>
                        <span class="text-danger"><?php echo formatCurrency($session_stats['total_expenses']); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 bg-light mt-2 rounded">
                        <span class="fw-bold">Esperado en Caja</span>
                        <span class="fw-bold fs-5"><?php echo formatCurrency($session_stats['expected_cash']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-secondary">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-balance-scale me-2"></i>Comparativa</h5>
            </div>
            <div class="card-body text-center">
                <p class="text-muted mb-1">Diferencia (Físico - Esperado)</p>
                <h2 id="diffDisplay" class="mb-0 fw-bold text-success">$ 0</h2>
                <div id="diffStatus" class="mt-2 badge bg-success">Cuadrado</div>
            </div>
            <div class="card-footer bg-light text-center small text-muted">
                Este arqueo es informativo y no cierra la caja.
            </div>
        </div>
    </div>
</div>

<script>
const CURRENCY_CONFIG = <?php echo json_encode(CompanySettings::getCurrency()); ?>;
const EXPECTED_CASH = <?php echo $session_stats['expected_cash']; ?>;
const standardDenominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000];

document.addEventListener('DOMContentLoaded', function() {
    initCalculator();
});

function initCalculator() {
    const tbodyBills = document.getElementById('tbody-bills');
    const tbodyCoins = document.getElementById('tbody-coins');
    tbodyBills.innerHTML = '';
    tbodyCoins.innerHTML = '';
    
    standardDenominations.forEach(val => {
        const targetBody = val >= 10000 ? tbodyBills : tbodyCoins;
        addDenominationRow(val, '', true, targetBody);
    });
    calculateTotals();
}

function addDenominationRow(value = '', count = '', readonly = false, targetTbody = null) {
    if (!targetTbody) {
        targetTbody = document.getElementById('tbody-coins');
    }

    const tr = document.createElement('tr');
    tr.className = 'denomination-row';
    
    const displayValue = value ? parseFloat(value).toLocaleString('en-US') : '';
    const readonlyAttr = readonly ? 'readonly style="background-color: #f8f9fa;"' : '';
    const removeBtn = readonly ? '' : `
        <button type="button" class="btn btn-link text-danger p-0" onclick="removeDenominationRow(this)">
            <i class="fas fa-times"></i>
        </button>`;

    tr.innerHTML = `
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text border-0 bg-transparent ps-0">${CURRENCY_CONFIG.symbol}</span>
                <input type="text" class="form-control form-control-sm money-input denom-value" 
                       placeholder="Valor" value="${displayValue}" ${readonlyAttr} 
                       oninput="formatCurrencyInput(this); calculateTotals()">
            </div>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm denom-count text-center" 
                   placeholder="0" value="${count}" min="0" 
                   oninput="calculateTotals()" onclick="this.select()">
        </td>
        <td class="text-end align-middle">
            <span class="denom-subtotal fw-bold small">0</span>
        </td>
        <td class="align-middle text-center">
            ${removeBtn}
        </td>
    `;
    targetTbody.appendChild(tr);
    if(value && count) calculateTotals();
}

function removeDenominationRow(btn) {
    btn.closest('tr').remove();
    calculateTotals();
}

function calculateTotals() {
    let totalPhysical = 0;
    
    document.querySelectorAll('.denomination-row').forEach(tr => {
        const valueInput = tr.querySelector('.denom-value');
        const countInput = tr.querySelector('.denom-count');
        const subtotalSpan = tr.querySelector('.denom-subtotal');
        
        const value = parseFloat(valueInput.value.replace(/,/g, '')) || 0;
        const count = parseInt(countInput.value) || 0;
        const subtotal = value * count;
        
        totalPhysical += subtotal;
        subtotalSpan.textContent = subtotal.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    });
    
    // Actualizar Total Físico
    const totalDisplay = document.getElementById('physicalTotalDisplay');
    totalDisplay.textContent = CURRENCY_CONFIG.symbol + ' ' + totalPhysical.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    
    // Calcular Diferencia
    const difference = totalPhysical - EXPECTED_CASH;
    const diffDisplay = document.getElementById('diffDisplay');
    const diffStatus = document.getElementById('diffStatus');
    
    diffDisplay.textContent = (difference > 0 ? '+' : '') + CURRENCY_CONFIG.symbol + ' ' + difference.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    
    if (difference === 0) {
        diffDisplay.className = 'mb-0 fw-bold text-success';
        diffStatus.className = 'mt-2 badge bg-success';
        diffStatus.textContent = 'Cuadrado';
    } else if (difference > 0) {
        diffDisplay.className = 'mb-0 fw-bold text-info';
        diffStatus.className = 'mt-2 badge bg-info';
        diffStatus.textContent = 'Sobrante';
    } else {
        diffDisplay.className = 'mb-0 fw-bold text-danger';
        diffStatus.className = 'mt-2 badge bg-danger';
        diffStatus.textContent = 'Faltante';
    }
}

function goCloseCashFromCount() {
    let totalPhysical = 0;
    document.querySelectorAll('.denomination-row').forEach(tr => {
        const valueInput = tr.querySelector('.denom-value');
        const countInput = tr.querySelector('.denom-count');
        const value = parseFloat((valueInput?.value || '').replace(/,/g, '')) || 0;
        const count = parseInt(countInput?.value || '0') || 0;
        totalPhysical += value * count;
    });

    const payload = {
        physical_cash: totalPhysical,
        physical_transfer: null,
        physical_card: null,
        physical_other: null
    };
    try { localStorage.setItem('cash_close_prefill', JSON.stringify(payload)); } catch (e) {}
    window.location.href = 'index.php?open_close_cash=1';
}
</script>

<?php endif; ?>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>

