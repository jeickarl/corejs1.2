<?php
// Ensure $pdo is available
if (!isset($pdo)) {
// Fallback or error, but usually included by parent
// We assume $pdo is available from the parent script
}
$pdo = db();

// Calculate suggested amounts if not already set
if (!isset($suggestedCash) || !isset($suggestedTransfer)) {
    $suggestedCash = 0;
    $suggestedTransfer = 0;

    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)getCurrentTenantId();
        $hasTenantCashSessions = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_sessions') : false;
        // Get last closed session stats
        $stmt_modal = $pdo->prepare("
            SELECT total_cash, total_transfer, system_total, final_amount 
            FROM cash_sessions 
            WHERE status = 'closed' " . (($hasTenantCashSessions && !$perDatabase) ? "AND tenant_id = ?" : "") . "
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_modal->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
        $lastSessionStats_modal = $stmt_modal->fetch(PDO::FETCH_ASSOC);

        if ($lastSessionStats_modal) {
            $lastExpenses_modal = $lastSessionStats_modal['system_total'] - $lastSessionStats_modal['final_amount'];
            $suggestedCash = $lastSessionStats_modal['total_cash'] - $lastExpenses_modal;
            $suggestedTransfer = $lastSessionStats_modal['total_transfer'];
            if ($suggestedCash < 0)
                $suggestedCash = 0;
        }
    }
    catch (Exception $e) {
    // Silent fail
    }
}

// Check if we need to auto-show the modal (e.g. for billing when closed)
if (!isset($autoShowModal)) {
    $current_page = basename($_SERVER['PHP_SELF']);
    $module = basename(dirname($_SERVER['PHP_SELF']));
    $is_billing_module = $module === 'billing';
    $is_cash_module = $module === 'cash';
    $autoShowModal = isset($cash_session_open) && !$cash_session_open && (
        ($is_billing_module && in_array($current_page, ['index.php', 'new.php', 'edit.php'])) ||
        ($is_cash_module && in_array($current_page, ['index.php']))
        );
}
?>

<!-- Unified Open Cash Modal -->
<div class="modal fade" id="unifiedOpenCashModal" tabindex="-1" aria-labelledby="unifiedOpenCashModalLabel" aria-hidden="true" <?php echo $autoShowModal ? 'data-bs-backdrop="static" data-bs-keyboard="false"' : ''; ?>>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 bg-warning text-dark p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-white bg-opacity-50 rounded-circle p-2 me-3">
                        <i class="fas fa-cash-register fa-lg text-dark"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold" id="unifiedOpenCashModalLabel">Abrir Caja</h5>
                        <p class="mb-0 small text-dark">Inicia una nueva sesión de ventas</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form id="unifiedOpenCashForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityEnhancements::generateCSRFToken(); ?>">
                    <?php if ($autoShowModal): ?>
                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10 text-warning d-flex align-items-center mb-4 rounded-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <small class="fw-bold">No hay sesión activa. Abre caja para facturar.</small>
                    </div>
                    <?php
endif; ?>

                    <!-- Monto Inicial (Efectivo) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Monto Inicial (Efectivo)</label>
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-white border-end-0 text-success rounded-start-pill px-4">
                                <i class="fas fa-money-bill-wave fa-lg"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 rounded-end-pill px-3 fw-bold text-dark fs-5" 
                                   name="initial_amount" 
                                   id="modal_initial_amount" 
                                   value="<?php echo number_format($suggestedCash, 0, '', ','); ?>"
                                   onkeyup="formatCurrencyInput(this)" 
                                   autocomplete="off"
                                   required>
                        </div>
                        <div class="form-text text-muted mt-2 ms-3">
                            <i class="fas fa-history me-1 small text-primary"></i> <small>Sugerido del último cierre: <strong>$ <?php echo number_format($suggestedCash, 0, '', ','); ?></strong></small>
                        </div>
                    </div>

                    <!-- Saldo Inicial (Transferencia) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Saldo Inicial (Transferencia)</label>
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-white border-end-0 text-info no-theme rounded-start-pill px-4">
                                <i class="fas fa-university fa-lg"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 rounded-end-pill px-3 fw-bold text-dark fs-5" 
                                   name="initial_transfer" 
                                   id="modal_initial_transfer" 
                                   value="<?php echo number_format($suggestedTransfer, 0, '', ','); ?>"
                                   onkeyup="formatCurrencyInput(this)"
                                   autocomplete="off">
                        </div>
                        <div class="form-text text-muted mt-2 ms-3">
                            <i class="fas fa-history me-1 small text-primary"></i> <small>Sugerido del último cierre: <strong>$ <?php echo number_format($suggestedTransfer, 0, '', ','); ?></strong></small>
                        </div>
                    </div>

                    <!-- Observaciones -->
                    <div class="mb-2">
                        <label class="form-label fw-bold text-secondary small text-uppercase mb-2">Observaciones</label>
                        <div class="input-group flex-nowrap shadow-sm">
                            <span class="input-group-text bg-white border-end-0 text-muted rounded-start-5 px-3 align-items-start pt-3">
                                <i class="fas fa-comment-alt"></i>
                            </span>
                            <textarea class="form-control border-start-0 rounded-end-5 px-3 py-3" 
                                      name="notes" 
                                      rows="2" 
                                      placeholder="Notas adicionales sobre la apertura..."
                                      style="resize: none;"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-top-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold ms-auto shadow-sm" id="btnOpenCash">
                        <i class="fas fa-unlock me-2"></i> Abrir Caja
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto show logic
    <?php if ($autoShowModal): ?>
    var myModal = new bootstrap.Modal(document.getElementById('unifiedOpenCashModal'), {
        backdrop: 'static',
        keyboard: false
    });
    myModal.show();
    <?php
endif; ?>

    // Form submission
    const form = document.getElementById('unifiedOpenCashForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('btnOpenCash');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Abriendo...';
            
            const formData = new FormData(this);
            
            // Usar ruta absoluta para evitar problemas en diferentes niveles de directorios
            const fetchUrl = '<?php echo function_exists("getSystemBaseUrl") ? getSystemBaseUrl() : "../"; ?>cash/ajax/open_cash_session.php';
            
            fetch(fetchUrl, {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (err) {
                    console.error('Respuesta no JSON:', text);
                    throw new Error('La respuesta del servidor no es válida. Detalle: ' + text.substring(0, 100));
                }
            })
            .then(data => {
                if (data.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Caja Abierta!',
                            text: 'La sesión ha sido iniciada correctamente.',
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        alert('Caja abierta exitosamente');
                        window.location.reload();
                    }
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'No se pudo abrir la caja',
                            confirmButtonText: 'Entendido'
                        });
                    } else {
                        alert(data.message || 'Error al abrir caja');
                    }
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión',
                        text: error.message || 'No se pudo conectar con el servidor',
                        confirmButtonText: 'Cerrar'
                    });
                } else {
                    alert('Error de conexión: ' + error.message);
                }
            });
        });
    }
});
</script>
