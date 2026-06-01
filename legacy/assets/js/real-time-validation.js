/**
 * Validación en tiempo real para números de identificación y NIT
 * Verifica duplicados en la base de datos mientras el usuario escribe
 */

document.addEventListener('DOMContentLoaded', function() {
    // Configuración de timeouts para debounce
    let idValidationTimeout;
    let nitValidationTimeout;
    
    // Elementos del DOM
    const idNumberInput = document.getElementById('id_number');
    const nitInput = document.getElementById('tax_id') || document.getElementById('nit_ruc') || document.getElementById('modal_nit_ruc');
    
    // Función para crear mensaje de alerta
    function createAlert(input, message, type = 'danger') {
        // Remover alertas existentes
        removeAlert(input);
        
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show mt-2`;
        alertDiv.style.fontSize = '0.875rem';
        alertDiv.innerHTML = `
            <i class="fas fa-exclamation-triangle"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        alertDiv.id = `${input.id}_alert`;
        
        // Insertar después del input
        input.parentNode.insertBefore(alertDiv, input.nextSibling);
        
        // Marcar el input como inválido
        input.classList.add('is-invalid');
        
        // Auto-ocultar después de 5 segundos
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
    
    // Función para remover alertas
    function removeAlert(input) {
        const existingAlert = document.getElementById(`${input.id}_alert`);
        if (existingAlert) {
            existingAlert.remove();
        }
        input.classList.remove('is-invalid');
    }
    
    // Función para validar número de identificación
    function validateIdNumber(idNumber, inputElement) {
        if (!idNumber || idNumber.length < 3) {
            removeAlert(inputElement);
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'check_id_number');
        formData.append('id_number', idNumber);
        
        // Agregar ID del cliente actual si estamos editando
        const clientIdInput = document.getElementById('client_id');
        if (clientIdInput && clientIdInput.value) {
            formData.append('exclude_client_id', clientIdInput.value);
        }
        
        fetch('/core/clients/validation_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                createAlert(
                    inputElement, 
                    `Ya existe un cliente con el documento ${idNumber}. Cliente: ${data.client_name}`,
                    'warning'
                );
            } else {
                removeAlert(inputElement);
            }
        })
        .catch(error => {
            console.error('Error validando número de identificación:', error);
        });
    }
    
    // Función para validar NIT
    function validateNit(nit, inputElement) {
        if (!nit || nit.length < 3) {
            removeAlert(inputElement);
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'check_nit');
        formData.append('nit', nit);
        
        // Agregar ID del cliente actual si estamos editando
        const clientIdInput = document.getElementById('client_id');
        if (clientIdInput && clientIdInput.value) {
            formData.append('exclude_client_id', clientIdInput.value);
        }
        
        fetch('/core/clients/validation_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                createAlert(
                    inputElement, 
                    `Ya existe una empresa con el NIT ${nit}. Empresa: ${data.client_name}`,
                    'warning'
                );
            } else {
                removeAlert(inputElement);
            }
        })
        .catch(error => {
            console.error('Error validando NIT:', error);
        });
    }
    
    // Event listener para número de identificación
    if (idNumberInput) {
        idNumberInput.addEventListener('input', function() {
            const idNumber = this.value.trim();
            
            // Debounce para evitar demasiadas peticiones
            clearTimeout(idValidationTimeout);
            idValidationTimeout = setTimeout(() => {
                validateIdNumber(idNumber, this);
            }, 800); // Esperar 800ms después de que el usuario deje de escribir
        });
        
        // Validar al perder el foco
        idNumberInput.addEventListener('blur', function() {
            clearTimeout(idValidationTimeout);
            const idNumber = this.value.trim();
            if (idNumber) {
                validateIdNumber(idNumber, this);
            }
        });
    }
    
    // Event listener para NIT (tanto en formulario principal como modal)
    if (nitInput) {
        nitInput.addEventListener('input', function() {
            const nit = this.value.trim();
            
            // Debounce para evitar demasiadas peticiones
            clearTimeout(nitValidationTimeout);
            nitValidationTimeout = setTimeout(() => {
                validateNit(nit, this);
            }, 800); // Esperar 800ms después de que el usuario deje de escribir
        });
        
        // Validar al perder el foco
        nitInput.addEventListener('blur', function() {
            clearTimeout(nitValidationTimeout);
            const nit = this.value.trim();
            if (nit) {
                validateNit(nit, this);
            }
        });
    }
    
    // También validar campos del modal si existen
    const modalIdInput = document.getElementById('modal_identification_number');
    if (modalIdInput) {
        modalIdInput.addEventListener('input', function() {
            const idNumber = this.value.trim();
            
            clearTimeout(idValidationTimeout);
            idValidationTimeout = setTimeout(() => {
                validateIdNumber(idNumber, this);
            }, 800);
        });
        
        modalIdInput.addEventListener('blur', function() {
            clearTimeout(idValidationTimeout);
            const idNumber = this.value.trim();
            if (idNumber) {
                validateIdNumber(idNumber, this);
            }
        });
    }
});