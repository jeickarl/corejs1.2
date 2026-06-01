/**
 * JavaScript específico para la gestión de órdenes
 */

(function() {
    // Prevenir inicialización múltiple
    if (window.ordersJsInitialized) return;
    window.ordersJsInitialized = true;

    let orderToDeleteId = 0;

    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Configurar el botón de confirmación de eliminación
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', executeDeleteOrder);
        }
        
        // Búsqueda en tiempo real
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        const form = this.closest('form');
                        if (form) {
                            form.submit();
                        }
                    }
                }, 500);
            });
            
            // Mantener el foco si es necesario (opcional, ya que el submit recarga)
            searchInput.focus();
            const val = searchInput.value; 
            searchInput.value = ''; 
            searchInput.value = val;
        }
    });

    // Función para abrir el modal de eliminación (Global)
    window.deleteOrder = function(orderId, orderNumber) {
        orderToDeleteId = orderId;
        const orderIdEl = document.getElementById('orderIdToDelete');
        if (orderIdEl) {
            const raw = (orderNumber !== undefined && orderNumber !== null && String(orderNumber).trim() !== '')
                ? String(orderNumber).trim()
                : String(orderId);
            const digits = raw.replace(/\D+/g, '');
            orderIdEl.textContent = '#' + (digits !== '' ? String(parseInt(digits, 10)) : raw);
        }
        const modalEl = document.getElementById('deleteModal');
        if (modalEl) {
            const deleteModal = new bootstrap.Modal(modalEl);
            deleteModal.show();
        }
    };

    // Función para ejecutar la eliminación vía AJAX
    function executeDeleteOrder() {
        if (!orderToDeleteId) return;

        const button = document.getElementById('confirmDeleteBtn');
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando...';
        button.disabled = true;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || document.getElementById('csrf_token')?.value
            || document.querySelector('input[name="csrf_token"]')?.value
            || '';

        fetch('delete_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                order_id: orderToDeleteId,
                csrf_token: csrfToken
            })
        })
        .then(window.parseJsonResponse)
        .then(data => {
            // Cerrar modal
            const deleteModalEl = document.getElementById('deleteModal');
            const modal = bootstrap.Modal.getInstance(deleteModalEl);
            
            if (data.success) {
                window.location.href = 'index.php?success=' + encodeURIComponent(data.message || 'Orden eliminada exitosamente');
            } else {
                if (typeof showError === 'function') showError(data.message || 'Error al eliminar la orden');
                // Restaurar botón
                button.innerHTML = originalHTML;
                button.disabled = false;
                modal.hide();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const deleteModalEl = document.getElementById('deleteModal');
            const modal = bootstrap.Modal.getInstance(deleteModalEl);
            modal.hide();
            if (typeof showError === 'function') showError('Error de conexión al eliminar la orden');
            // Restaurar botón
            button.innerHTML = originalHTML;
            button.disabled = false;
        });
    }

    // Función para ver detalles completos del problema (Global)
    window.showProblemDetails = function(orderId, problemDescription) {
        // Crearemos un modal dinámico si no existe
        let infoModal = document.getElementById('infoModal');
        if (!infoModal) {
            const modalHTML = `
            <div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content rounded-4 shadow">
                        <div class="modal-header border-bottom-0">
                            <h5 class="modal-title fw-bold">Detalle del Problema</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p id="infoModalContent" class="text-muted" style="word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; white-space: pre-wrap;"></p>
                        </div>
                        <div class="modal-footer border-top-0">
                            <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">Entendido</button>
                        </div>
                    </div>
                </div>
            </div>`;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            infoModal = document.getElementById('infoModal');
        }
        
        document.getElementById('infoModalContent').textContent = problemDescription;
        const modal = new bootstrap.Modal(infoModal);
        modal.show();
    };

})();
