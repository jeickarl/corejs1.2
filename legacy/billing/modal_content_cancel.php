<div class="modal-header bg-danger text-white border-0" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
    <h5 class="modal-title px-2">
        <i class="fas fa-ban me-2"></i>Anular Factura #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-4 bg-light">
    <?php if ($invoice['status'] !== 'cancelled'): ?>
        <div class="alert alert-warning mb-4 rounded-3 border-0 shadow-sm bg-white">
            <div class="d-flex align-items-center p-2">
                <div class="flex-shrink-0 me-3">
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                    </div>
                </div>
                <div class="flex-grow-1">
                    <h6 class="alert-heading fw-bold text-dark mb-1">Advertencia de Seguridad</h6>
                    <p class="mb-0 text-muted small">
                        Está a punto de anular la factura <strong class="text-dark"><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>.
                        Esta acción es irreversible y generará reversos de los pagos existentes.
                    </p>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: 1rem;">
            <div class="card-body p-4">
                <form id="cancelForm" onsubmit="submitCancel(event)">
                    <input type="hidden" name="action" value="cancel_invoice">
                    <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityEnhancements::generateCSRFToken(); ?>">
                    
                    <div class="mb-4">
                        <label for="cancellation_reason" class="form-label fw-bold text-dark small mb-2">
                            <i class="fas fa-comment-alt me-1 text-primary"></i> Razón de Anulación <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control border-0 bg-light" id="cancellation_reason" name="cancellation_reason" 
                                  rows="3" placeholder="Explique detalladamente el motivo de la anulación..." 
                                  style="border-radius: 0.8rem;" required></textarea>
                    </div>

                    <div class="mb-0">
                        <label for="confirm_cancellation" class="form-label fw-bold text-dark small mb-2">
                            <i class="fas fa-shield-alt me-1 text-primary"></i> Confirmación Requerida <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control border-0 bg-light" id="confirm_cancellation" name="confirm_cancellation" 
                               placeholder="Escriba 'CONFIRMAR'" required pattern="CONFIRMAR"
                               style="border-radius: 0.8rem; text-transform: uppercase;">
                        <div class="form-text small mt-2">
                            <i class="fas fa-info-circle me-1"></i> Por favor, escriba <strong class="text-danger">CONFIRMAR</strong> para proceder.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0 text-center py-5" style="border-radius: 1rem;">
            <div class="card-body">
                <div class="mb-4">
                    <div class="bg-danger bg-opacity-10 p-4 rounded-circle d-inline-block">
                        <i class="fas fa-ban fa-4x text-danger"></i>
                    </div>
                </div>
                <h4 class="text-dark fw-bold">Factura Ya Anulada</h4>
                <p class="text-muted mx-auto" style="max-width: 300px;">Esta factura ya ha sido procesada y se encuentra en estado de anulación.</p>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 mt-3" data-bs-dismiss="modal">
                    Entendido
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal-footer bg-white border-0 p-3" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-muted" data-bs-dismiss="modal">
        <i class="fas fa-times me-2"></i>Cancelar
    </button>
    <?php if ($invoice['status'] !== 'cancelled'): ?>
        <button type="submit" form="cancelForm" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
            <i class="fas fa-check-circle me-2"></i>Anular Factura
        </button>
    <?php endif; ?>
</div>