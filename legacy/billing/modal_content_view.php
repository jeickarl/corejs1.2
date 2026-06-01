<div class="modal-header bg-dark text-white border-0">
    <h5 class="modal-title">
        <i class="fas fa-file-invoice me-2"></i>Detalles de Factura #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-4">
    <!-- Información General -->
    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-body">
            <h6 class="card-title text-dark mb-3"><i class="fas fa-info-circle me-2"></i>Información General</h6>
            <?php 
            $paidAmountTop = 0;
            if (!empty($payments)) {
                foreach ($payments as $pTop) { $paidAmountTop += ($pTop['payment_amount'] ?? 0); }
            }
            $totalAmountTop = floatval($invoice['total_amount'] ?? 0);
            $pendingAmountTop = max(0, $totalAmountTop - $paidAmountTop);
            $paymentStatusTop = ($paidAmountTop <= 0) ? 'pending' : (($paidAmountTop + 0.00001 < $totalAmountTop) ? 'partial' : 'paid');
            $paymentColorsTop = ['paid'=>'success','partial'=>'warning','pending'=>'danger'];
            $linked_order_id = null;
            if (!empty($invoice['order_id'])) {
                $linked_order_id = intval($invoice['order_id']);
            } elseif (!empty($invoice['notes']) && preg_match('/Orden\s*#(\d+)/i', $invoice['notes'], $m)) {
                $linked_order_id = intval($m[1]);
            }
            ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label text-muted small mb-0 text-uppercase fw-bold">Cliente</label>
                        <div class="fs-5"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                        <div class="small text-muted">
                            <?php if (!empty($invoice['client_phone'])): ?>
                                <span class="me-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($invoice['client_phone']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($invoice['client_email'])): ?>
                                <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($invoice['client_email']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small mb-0 text-uppercase fw-bold">Fecha de Emisión</label>
                        <div class="fs-5"><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></div>
                    </div>
                    <?php if ($linked_order_id):
                        try {
                            $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
                            $hasTenantWo = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
                            if (!$perDatabase && $hasTenantWo) {
                                $tenant_id = getCurrentTenantId();
                                $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                                $ordStmt->execute([$linked_order_id, $tenant_id]);
                            } else {
                                $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? LIMIT 1");
                                $ordStmt->execute([$linked_order_id]);
                            }
                            $ordNum = (int)($ordStmt->fetchColumn() ?: 0);
                        } catch (Throwable $__) { $ordNum = 0; }
                        $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
                        $displayNum = $ordNum > 0 ? $ordNum : $linked_order_id;
                        $displayText = htmlspecialchars($prefix) . '-' . str_pad($displayNum, 4, '0', STR_PAD_LEFT);
                    ?>
                    <div class="mb-3">
                        <span class="badge bg-secondary">
                            <i class="fas fa-clipboard-list me-1"></i>
                            <?php echo $displayText; ?>
                        </span>
                        <a class="ms-2 text-decoration-none" href="../orders/view.php?id=<?php echo $linked_order_id; ?>">
                            Ver Orden
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label text-muted small mb-0 text-uppercase fw-bold">Estado</label>
                        <div>
                            <?php
                            $status_colors = [
                                'draft' => 'secondary', 'sent' => 'info',
                                'paid' => 'success', 'cancelled' => 'danger'
                            ];
                            $status_texts = [
                                'draft' => 'Borrador', 'sent' => 'Completada',
                                'paid' => 'Pagada', 'cancelled' => 'Anulada'
                            ];
                            ?>
                            <span class="badge rounded-pill bg-<?php echo $status_colors[$invoice['status']]; ?> px-3 py-2">
                                <?php echo $status_texts[$invoice['status']]; ?>
                            </span>
                            <span class="badge rounded-pill bg-<?php echo $paymentColorsTop[$paymentStatusTop]; ?> px-3 py-2 ms-2">
                                <?php echo $paymentStatusTop === 'paid' ? 'Pagada' : ($paymentStatusTop === 'partial' ? 'Pago Parcial' : 'Pendiente'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small mb-0 text-uppercase fw-bold">Total Factura</label>
                        <div class="h4 text-dark mb-0"><?php echo formatCurrency($invoice['total_amount']); ?></div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Pagado: <span class="text-success fw-bold"><?php echo formatCurrency($paidAmountTop); ?></span></span>
                                <span>Pendiente: <span class="text-warning fw-bold"><?php echo formatCurrency($pendingAmountTop); ?></span></span>
                            </div>
                            <div class="progress mt-1" style="height: 8px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $totalAmountTop>0 ? min(100, round(($paidAmountTop/$totalAmountTop)*100)) : 0; ?>%;" aria-valuenow="<?php echo $totalAmountTop>0 ? round(($paidAmountTop/$totalAmountTop)*100) : 0; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detalle de Items -->
    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-light border-0">
            <h6 class="mb-0 text-dark"><i class="fas fa-list me-2"></i>Detalle de Items</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="border-0 ps-4">Descripción</th>
                            <th class="text-center border-0">Cant.</th>
                            <th class="text-end border-0">Precio</th>
                            <th class="text-end border-0 pe-4">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="ps-4" style="max-width: 300px; white-space: normal; word-wrap: break-word; overflow-wrap: break-word;">
                                <span class="fw-medium"><?php echo htmlspecialchars($item['description']); ?></span>
                                <div class="small text-muted"><?php echo ucfirst($item['item_type']); ?></div>
                            </td>
                            <td class="text-center align-middle"><?php echo number_format($item['quantity'], 0); ?></td>
                            <td class="text-end align-middle"><?php echo formatCurrency($item['unit_price']); ?></td>
                            <td class="text-end align-middle pe-4 fw-bold"><?php echo formatCurrency($item['total_price']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <td colspan="3" class="text-end border-0 pt-3">Subtotal:</td>
                            <td class="text-end border-0 pt-3 pe-4"><?php echo formatCurrency($invoice['subtotal']); ?></td>
                        </tr>
                        <?php if ($invoice['tax_amount'] > 0): ?>
                        <tr>
                            <td colspan="3" class="text-end border-0">Impuesto:</td>
                            <td class="text-end border-0 pe-4"><?php echo formatCurrency($invoice['tax_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="3" class="text-end border-0 pb-3"><strong class="text-dark">TOTAL:</strong></td>
                            <td class="text-end border-0 pb-3 pe-4"><strong class="h5 text-dark mb-0"><?php echo formatCurrency($invoice['total_amount']); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Historial de Pagos -->
    <?php if (!empty($payments)): ?>
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-header bg-light border-0">
                <h6 class="mb-0 text-success"><i class="fas fa-money-bill-wave me-2"></i>Historial de Pagos</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 ps-4">Fecha</th>
                                <th class="border-0">Método</th>
                                <th class="border-0">Ref.</th>
                                <th class="text-end border-0 pe-4">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="ps-4"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($payment['payment_method']); ?></span></td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?: '-'); ?></td>
                                <td class="text-end pe-4 text-success fw-bold"><?php echo formatCurrency($payment['payment_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Estado de Anulación -->
    <?php if ($invoice['status'] === 'cancelled'): ?>
        <div class="alert alert-danger rounded-3 mt-3 border-0 shadow-sm">
            <h6 class="alert-heading fw-bold"><i class="fas fa-ban me-2"></i>Factura Anulada</h6>
            <hr>
            <p class="mb-0" style="word-wrap: break-word; overflow-wrap: break-word;">
                <strong>Razón:</strong> <?php echo htmlspecialchars($invoice['cancellation_reason']); ?><br>
                <strong>Por:</strong> <?php echo htmlspecialchars($invoice['cancelled_by_name']); ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<div class="modal-footer bg-light border-top-0" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
    <?php 
    // Calcular monto pagado
    $paidAmount = 0;
    if (!empty($payments)) {
        foreach ($payments as $payment) {
            $paidAmount += $payment['payment_amount'];
        }
    }
    
    $hasPhone = !empty($invoice['client_phone']);
    // Construir resumen de detalles para WhatsApp (descripciones x cantidad)
    $detailsParts = [];
    if (!empty($items)) {
        foreach ($items as $it) {
            $desc = trim($it['description'] ?? '');
            $qty = isset($it['quantity']) ? (int)$it['quantity'] : 1;
            if ($desc !== '') {
                $detailsParts[] = $desc . ($qty > 1 ? " x{$qty}" : "");
            }
        }
    }
    $detailsSummary = implode(', ', array_slice($detailsParts, 0, 6));
    $waOnClick = $hasPhone ? "openWhatsAppModal(this.dataset.invoice, this.dataset.client, this.dataset.total, this.dataset.paid, this.dataset.phone, this.dataset.details)" : "alert('El cliente no tiene teléfono configurado');";
    ?>
    <button type="button" class="btn btn-success rounded-pill px-4" 
            <?php if ($hasPhone): ?>
            data-invoice="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
            data-client="<?php echo htmlspecialchars($invoice['client_name']); ?>"
            data-total="<?php echo $invoice['total_amount']; ?>"
            data-paid="<?php echo $paidAmount; ?>"
            data-phone="<?php echo htmlspecialchars($invoice['client_phone']); ?>"
            data-details="<?php echo htmlspecialchars($detailsSummary); ?>"
            <?php endif; ?>
            onclick="<?php echo $waOnClick; ?>" aria-label="WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </button>
    <?php if ($invoice['status'] !== 'cancelled'): ?>
    <a href="edit.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-warning rounded-pill px-4 text-white">
        <i class="fas fa-edit me-2"></i>Editar
    </a>
    <?php endif; ?>
    <?php echo generatePrintButtons('sale', $invoice['id']); ?>
    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
        <i class="fas fa-times me-2"></i>Cerrar
    </button>
</div>
