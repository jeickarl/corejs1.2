<?php
if (!isset($tenant_id) || (int)$tenant_id <= 0) {
    $tenant_id = function_exists('getCurrentTenantId') ? (int)getCurrentTenantId() : 0;
}

if (!isset($cashPaymentMethods) || !is_array($cashPaymentMethods) || count($cashPaymentMethods) === 0) {
    $cashPaymentMethods = ['Efectivo', 'Transferencia', 'Tarjeta', 'Otros'];
    if (isset($pdo) && $tenant_id > 0) {
        try {
            $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
            $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
            $hasTenantPm = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'payment_methods') : false;
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
            $sqlPm = "SELECT name FROM payment_methods WHERE 1=1";
            if ($hasTenantPm && !$perDatabase) { $sqlPm .= " AND tenant_id = ?"; }
            if ($hasStatus) {
                $sqlPm .= " AND status = 'active'";
            } elseif ($hasIsActive) {
                $sqlPm .= " AND is_active = 1";
            }
            $sqlPm .= " ORDER BY name ASC";
            $stmtPm = $pdo->prepare($sqlPm);
            $stmtPm->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
            foreach (($stmtPm->fetchAll(PDO::FETCH_COLUMN) ?: []) as $pmName) {
                $n = trim((string)$pmName);
                if ($n !== '' && !in_array($n, $cashPaymentMethods, true)) {
                    $cashPaymentMethods[] = $n;
                }
            }
        } catch (Throwable $e) {}
    }
}
?>

<style>
#incomeModal .modal-dialog,
#expenseModal .modal-dialog {
    max-width: 680px;
}

#incomeModal .modal-header,
#expenseModal .modal-header {
    padding: 1.25rem !important;
}

#incomeModal .modal-body,
#expenseModal .modal-body {
    padding: 1.25rem !important;
}

#incomeModal .modal-footer,
#expenseModal .modal-footer {
    padding-left: 1.25rem !important;
    padding-right: 1.25rem !important;
    padding-bottom: 1.25rem !important;
}

#incomeModal .input-group-lg > .form-control,
#incomeModal .input-group-lg > .form-select,
#incomeModal .input-group-lg > .input-group-text,
#expenseModal .input-group-lg > .form-control,
#expenseModal .input-group-lg > .form-select,
#expenseModal .input-group-lg > .input-group-text {
    padding-top: 0.6rem;
    padding-bottom: 0.6rem;
    font-size: 1rem;
}

#incomeModal textarea.form-control,
#expenseModal textarea.form-control {
    min-height: 80px;
}

#incomeModal .input-group,
#expenseModal .input-group {
    background-color: #f8fafc;
    border: 1px solid #d9e1ea;
    border-radius: 12px;
    overflow: hidden;
}

#incomeModal .input-group .input-group-text,
#expenseModal .input-group .input-group-text {
    background-color: transparent !important;
    border: 0 !important;
    color: #6b7280;
    min-width: 54px;
    justify-content: center;
}

#incomeModal .input-group .form-control,
#expenseModal .input-group .form-control,
#incomeModal .input-group .form-select,
#expenseModal .input-group .form-select {
    background-color: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}

#incomeModal .input-group:focus-within,
#expenseModal .input-group:focus-within {
    background-color: #ffffff;
    border-color: #9ca3af;
    box-shadow: 0 0 0 0.2rem rgba(156, 163, 175, 0.18);
}

#incomeModal textarea.form-control,
#expenseModal textarea.form-control {
    resize: vertical;
}
</style>

<div class="modal fade" id="incomeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-bottom bg-white text-dark p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-light rounded p-2 me-3 border">
                        <i class="fas fa-arrow-down fa-lg text-success"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold">Registrar Ingreso</h5>
                        <p class="mb-0 small text-muted">Agrega dinero a la caja</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="incomeForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken()); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Concepto</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-tag"></i>
                            </span>
                            <input type="text" class="form-control ps-2 fw-bold text-dark"
                                   name="concept"
                                   placeholder="Ej: Venta mostrador, abono cliente..."
                                   required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Monto</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-money-bill-wave"></i>
                            </span>
                            <input type="text" class="form-control ps-2 fw-bold text-dark"
                                   name="amount"
                                   id="income_amount"
                                   onkeyup="formatCurrencyInput(this)"
                                   required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Método de Pago</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-credit-card"></i>
                            </span>
                            <select class="form-select ps-2 fw-bold text-dark" name="payment_method" required>
                                <?php foreach ($cashPaymentMethods as $pm): ?>
                                    <option value="<?php echo htmlspecialchars($pm); ?>"><?php echo htmlspecialchars($pm); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Notas</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text align-items-start pt-3">
                                <i class="fas fa-sticky-note"></i>
                            </span>
                            <textarea class="form-control ps-2 fw-bold text-dark"
                                      name="notes"
                                      rows="2"
                                      placeholder="Opcional"
                                      ></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold ms-auto shadow-sm">
                        <i class="fas fa-check me-2"></i> Guardar Ingreso
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-bottom bg-white text-dark p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-light rounded p-2 me-3 border">
                        <i class="fas fa-arrow-up fa-lg text-danger"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold">Registrar Egreso</h5>
                        <p class="mb-0 small text-muted">Registra una salida de dinero</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="expenseForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken()); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Concepto</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-tag"></i>
                            </span>
                            <input type="text" class="form-control ps-2 fw-bold text-dark"
                                   name="concept"
                                   placeholder="Ej: Compra insumos, pago transporte..."
                                   required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Monto</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-money-bill-wave"></i>
                            </span>
                            <input type="text" class="form-control ps-2 fw-bold text-dark"
                                   name="amount"
                                   id="expense_amount"
                                   onkeyup="formatCurrencyInput(this)"
                                   required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Sale de</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">
                                <i class="fas fa-wallet"></i>
                            </span>
                            <select class="form-select ps-2 fw-bold text-dark" name="payment_method" required>
                                <?php foreach ($cashPaymentMethods as $pm): ?>
                                    <option value="<?php echo htmlspecialchars($pm); ?>" <?php echo (strtolower($pm) === 'efectivo') ? 'selected' : ''; ?>><?php echo htmlspecialchars($pm); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Notas</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text align-items-start pt-3">
                                <i class="fas fa-sticky-note"></i>
                            </span>
                            <textarea class="form-control ps-2 fw-bold text-dark"
                                      name="notes"
                                      rows="2"
                                      placeholder="Opcional"
                                      ></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-5 fw-bold ms-auto shadow-sm">
                        <i class="fas fa-check me-2"></i> Guardar Egreso
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
