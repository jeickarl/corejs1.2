/**
 * JavaScript específico para la gestión de clientes
 */

// Funciones para la página de clientes
document.addEventListener('DOMContentLoaded', function() {
    // Confirmación de eliminación de clientes
    const deleteButtons = document.querySelectorAll('.btn-delete-client');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const clientName = this.dataset.clientName;
            if (typeof showConfirm === 'function') {
                showConfirm(`¿Estás seguro de que deseas eliminar al cliente "${clientName}"?\n\nEsta acción no se puede deshacer.`, () => {
                    window.location.href = this.href;
                });
            } else {
                if (confirm(`¿Estás seguro de que deseas eliminar al cliente "${clientName}"?\n\nEsta acción no se puede deshacer.`)) {
                    window.location.href = this.href;
                }
            }
        });
    });

    // Validación de formularios de cliente
    const clientForm = document.getElementById('clientForm');
    if (clientForm) {
        clientForm.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                if (typeof showError === 'function') {
                    showError('Por favor, completa todos los campos requeridos.');
                } else {
                    alert('Por favor, completa todos los campos requeridos.');
                }
            }
        });
    }

    // Teléfonos: mantener solo dígitos (el prefijo va separado en la UI)
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.dataset && this.dataset.phoneFormat === 'us') {
                let value = this.value.replace(/\D/g, '');
                if (value.length >= 6) {
                    value = `(${value.slice(0,3)}) ${value.slice(3,6)}-${value.slice(6,10)}`;
                } else if (value.length >= 3) {
                    value = `(${value.slice(0,3)}) ${value.slice(3)}`;
                }
                this.value = value;
                return;
            }
            this.value = this.value.replace(/\D/g, '');
        });
    });

    // Búsqueda en tiempo real
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    // Enviar búsqueda
                    const form = this.closest('form');
                    if (form) {
                        form.submit();
                    }
                }
            }, 500);
        });
    }
});

// Función para exportar datos de clientes
function exportClients() {
    if (typeof showConfirm === 'function') {
        showConfirm('¿Deseas exportar todos los datos de clientes a CSV?', () => {
            window.location.href = 'export.php?format=csv';
        });
    } else {
        if (confirm('¿Deseas exportar todos los datos de clientes a CSV?')) {
            window.location.href = 'export.php?format=csv';
        }
    }
}

// Función para imprimir lista de clientes
function printClientList() {
    window.print();
}
