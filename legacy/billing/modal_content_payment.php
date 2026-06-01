<div class="modal-header bg-success text-white border-0">
    <h5 class="modal-title">
        <i class="fas fa-cash-register me-2"></i>Registrar Pago
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-4">
    <!-- Resumen de Saldos -->
    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-body bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-center">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Total Factura</small>
                    <span class="fw-bold fs-5"><?php echo formatCurrency($invoice['total_amount']); ?></span>
                </div>
                <div class="vr bg-secondary opacity-25"></div>
                <div class="text-center">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Total Pagado</small>
                    <span class="text-success fw-bold fs-5"><?php echo formatCurrency($invoice['paid_amount']); ?></span>
                </div>
                <div class="vr bg-secondary opacity-25"></div>
                <div class="text-center">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Pendiente</small>
                    <span class="text-warning fw-bold fs-4"><?php echo formatCurrency($invoice['pending_amount']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Historial de Pagos -->
    <?php if (!empty($payments)): ?>
        <div class="card shadow-sm mb-4 border-0 rounded-3">
            <div class="card-header bg-light border-0">
                <h6 class="mb-0 text-secondary"><i class="fas fa-history me-2"></i>Historial de Pagos</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 ps-3">Fecha</th>
                                <th class="border-0">Método</th>
                                <th class="border-0">Ref.</th>
                                <th class="text-end border-0 pe-3">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="ps-3"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td><span class="badge bg-white text-dark border"><?php echo htmlspecialchars($payment['payment_method']); ?></span></td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?: '-'); ?></td>
                                <td class="text-end pe-3"><?php echo formatCurrency($payment['payment_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulario de Pago -->
    <?php if ($invoice['pending_amount'] > 0): ?>
        <?php if ($cash_session_open): ?>
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-light border-0">
                    <h6 class="mb-0 text-success"><i class="fas fa-edit me-2"></i>Nuevo Pago</h6>
                </div>
                <div class="card-body">
                    <form id="paymentForm" onsubmit="submitPayment(event)">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityEnhancements::generateCSRFToken(); ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="payment_amount" class="form-label small fw-bold">Monto a Pagar <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><?php echo formatCompanyCurrency('', false); ?></span>
                                    <input type="text" class="form-control money-input" id="payment_amount" name="payment_amount" 
                                           value="<?php echo (int)$invoice['pending_amount']; ?>" 
                                           required oninput="formatCurrencyInput(this)">
                                </div>
                                <div class="form-text small">Máximo: <?php echo formatCurrency($invoice['pending_amount']); ?></div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="payment_date" class="form-label small fw-bold">Fecha <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="payment_method" class="form-label small fw-bold">Método de Pago <span class="text-danger">*</span></label>
                                <select class="form-select" id="payment_method" name="payment_method" required>
                                    <option value="">Seleccionar método...</option>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo htmlspecialchars($method['name']); ?>" data-mid="<?php echo $method['id']; ?>"><?php echo htmlspecialchars($method['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="reference_number" class="form-label small fw-bold">Referencia</label>
                                <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                       placeholder="Ej: #123456">
                                <div class="form-text small">Número de comprobante (opcional)</div>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label for="notes" class="form-label small fw-bold">Notas</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Observaciones adicionales..."></textarea>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning d-flex align-items-center rounded-3 border-0 shadow-sm" role="alert">
                <i class="fas fa-exclamation-circle fa-2x me-3"></i>
                <div>
                    <strong>¡Caja Cerrada!</strong>
                    <p class="mb-0 small">No se pueden registrar pagos porque no hay una sesión de caja abierta. Por favor, abra la caja primero.</p>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal-footer bg-light border-top-0">
    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
        <i class="fas fa-times me-2"></i>Cancelar
    </button>
    <?php if ($invoice['pending_amount'] > 0 && $cash_session_open): ?>
        <button type="submit" form="paymentForm" class="btn btn-success rounded-pill px-4">
            <i class="fas fa-save me-2"></i>Guardar Pago
        </button>
    <?php endif; ?>
</div>
