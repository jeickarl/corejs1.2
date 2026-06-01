// Manejadores para modales y botones
document.addEventListener('DOMContentLoaded', function() {
    console.log('Modal handlers loaded');
    
    // Verificar que Bootstrap esté disponible
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap no está cargado');
        return;
    }
    
    // Función para alternar campos del modal de cliente
    window.toggleModalClientFields = function() {
        const clientTypeRadios = document.querySelectorAll('input[name="modal_client_type"]');
        const individualFields = document.getElementById('modal-individual-fields');
        const companyFields = document.getElementById('modal-company-fields');
        
        // Verificar si los elementos existen (solo en páginas que tienen el modal)
        if (!individualFields || !companyFields) {
            console.log('Modal de cliente no presente en esta página');
            return;
        }
        
        let selectedType = 'individual'; // valor por defecto
        
        // Buscar el tipo seleccionado
        for (let radio of clientTypeRadios) {
            if (radio.checked) {
                selectedType = radio.value;
                break;
            }
        }
        
        console.log('Tipo de cliente seleccionado:', selectedType);
        
        if (selectedType === 'individual') {
            individualFields.style.display = 'block';
            companyFields.style.display = 'none';
            // Limpiar campos de empresa
            const nitField = document.getElementById('modal_nit_ruc');
            const companyNameField = document.getElementById('modal_company_name');
            const legalRepField = document.getElementById('modal_legal_representative');
            
            if (nitField) nitField.value = '';
            if (companyNameField) companyNameField.value = '';
            if (legalRepField) legalRepField.value = '';
        } else {
            individualFields.style.display = 'none';
            companyFields.style.display = 'block';
            // Limpiar campos de persona natural
            const nameField = document.getElementById('modal_name');
            const idField = document.getElementById('modal_identification_number');
            
            if (nameField) nameField.value = '';
            if (idField) idField.value = '';
        }
    };
    
    // Función para guardar nuevo cliente
    window.saveNewClient = function() {
        console.log('Intentando guardar nuevo cliente...');
        
        const form = document.getElementById('newClientForm');
        if (!form) {
            console.error('No se encontró el formulario newClientForm');
            if (typeof showError === 'function') {
                showError('Error: No se encontró el formulario');
            } else {
                alert('Error: No se encontró el formulario');
            }
            return;
        }
        
        // Validar campos requeridos
        const clientTypeRadios = document.querySelectorAll('input[name="modal_client_type"]');
        let clientType = 'individual';
        
        for (let radio of clientTypeRadios) {
            if (radio.checked) {
                clientType = radio.value;
                break;
            }
        }
        
        // Obtener valores de los campos del formulario según contexto
        const isModal = document.getElementById('modal_identification_number') !== null;
        console.log('=== DEBUG CONTEXTO ===');
        console.log('isModal:', isModal);
        
        let phone = '';
        if (isModal) {
            const prefixInput = document.getElementById('modal_phone_prefix');
            const phoneInput = document.getElementById('modal_phone');
            
            if (prefixInput && phoneInput) {
                let prefix = prefixInput.value.trim();
                const number = phoneInput.value.trim();
                if (number) {
                    const clean = number.replace(/\D/g, '');
                    prefix = prefix.replace(/[^\d+]/g, '');
                    if (prefix && prefix[0] !== '+') prefix = '+' + prefix.replace(/\D/g, '');
                    phone = (prefix ? (prefix + ' ') : '') + clean;
                }
            } else {
                phone = phoneInput?.value.trim() || '';
            }
        } else {
            phone = document.getElementById('phone')?.value.trim() || '';
        }
        
        console.log('phone obtenido:', phone);
        
        // Obtener número de identificación según tipo de cliente y contexto (modal vs formulario directo)
         let idNumber;
        
        console.log('=== DEBUG ID_NUMBER ===');
        console.log('clientType:', clientType);
        
        if (clientType === 'company') {
            // Para empresas, usar el NIT/RUC como identificación
            if (isModal) {
                console.log('Empresa + Modal: buscando modal_nit_ruc');
                console.log('modal_nit_ruc existe:', document.getElementById('modal_nit_ruc') !== null);
                const nitElement = document.getElementById('modal_nit_ruc');
                console.log('Elemento modal_nit_ruc:', nitElement);
                console.log('Valor modal_nit_ruc:', nitElement?.value);
                idNumber = nitElement?.value.trim() || '';
            } else {
                console.log('Empresa + Formulario directo: buscando tax_id');
                console.log('tax_id existe:', document.getElementById('tax_id') !== null);
                const taxElement = document.getElementById('tax_id');
                console.log('Elemento tax_id:', taxElement);
                console.log('Valor tax_id:', taxElement?.value);
                idNumber = taxElement?.value.trim() || '';
                
                // VERIFICACIÓN ADICIONAL: Si tax_id está vacío, intentar con otros campos
                if (!idNumber) {
                    console.log('tax_id vacío, verificando otros campos...');
                    const nitRucElement = document.getElementById('nit_ruc');
                    console.log('nit_ruc existe:', nitRucElement !== null);
                    if (nitRucElement) {
                        idNumber = nitRucElement.value.trim() || '';
                        console.log('Valor desde nit_ruc:', idNumber);
                    }
                }
            }
        } else {
            // Para personas naturales, usar el campo de identificación
            if (isModal) {
                console.log('Individual + Modal: buscando modal_identification_number');
                const idElement = document.getElementById('modal_identification_number');
                console.log('Elemento modal_identification_number:', idElement);
                console.log('Valor modal_identification_number:', idElement?.value);
                idNumber = idElement?.value.trim() || '';
            } else {
                console.log('Individual + Formulario directo: buscando id_number');
                const idElement = document.getElementById('id_number');
                console.log('Elemento id_number:', idElement);
                console.log('Valor id_number:', idElement?.value);
                idNumber = idElement?.value.trim() || '';
            }
        }
        
        console.log('idNumber final:', idNumber);
        console.log('=== FIN DEBUG ID_NUMBER ===');
        
        let isValid = true;
        let errorMessage = '';
        
        if (!idNumber) {
            isValid = false;
            errorMessage = 'El documento de identidad es obligatorio.';
        }
        
        if (!phone) {
            isValid = false;
            errorMessage = 'El teléfono es obligatorio.';
        }
        
        if (clientType === 'individual') {
            const name = isModal ? 
                document.getElementById('modal_name')?.value.trim() || '' :
                document.getElementById('name')?.value.trim() || '';
            if (!name) {
                isValid = false;
                errorMessage = 'El nombre es obligatorio para personas naturales.';
            }
        } else {
            const nitRuc = isModal ? 
                document.getElementById('modal_nit_ruc')?.value.trim() || '' :
                document.getElementById('tax_id')?.value.trim() || '';
            const companyName = isModal ? 
                document.getElementById('modal_company_name')?.value.trim() || '' :
                document.getElementById('company_name')?.value.trim() || '';
            if (!nitRuc) {
                isValid = false;
                errorMessage = 'El NIT/RUC es obligatorio para empresas.';
            }
            if (!companyName) {
                isValid = false;
                errorMessage = 'La razón social es obligatoria para empresas.';
            }
        }
        
        if (!isValid) {
            if (typeof showError === 'function') {
                showError(errorMessage);
            } else {
                alert(errorMessage);
            }
            return;
        }
        
        // Obtener otros campos según contexto
        const email = isModal ? 
            document.getElementById('modal_email')?.value.trim() || '' :
            document.getElementById('email')?.value.trim() || '';
        const address = isModal ? 
            document.getElementById('modal_address')?.value.trim() || '' :
            document.getElementById('address')?.value.trim() || '';
        
        // Preparar FormData para envío
        const formData = new FormData();
        formData.append('client_type', clientType);
        formData.append('phone', phone);
        formData.append('email', email);
        formData.append('address', address);
        
        // DEBUG: Verificar idNumber antes de enviarlo
        console.log('=== DEBUG ANTES DE ENVIAR ===');
        console.log('idNumber antes de append:', idNumber);
        console.log('idNumber length:', idNumber.length);
        console.log('idNumber type:', typeof idNumber);
        
        formData.append('id_number', idNumber);
        
        // Obtener token CSRF
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || document.querySelector('input[name="csrf_token"]')?.value
            || '';
        if (csrfToken) formData.append('csrf_token', csrfToken);
        
        if (clientType === 'individual') {
            const firstName = isModal ? 
                document.getElementById('modal_name')?.value.trim() || '' :
                document.getElementById('name')?.value.trim() || '';
            formData.append('first_name', firstName);
        } else {
            const companyName = isModal ? 
                document.getElementById('modal_company_name')?.value.trim() || '' :
                document.getElementById('company_name')?.value.trim() || '';
            const taxId = isModal ? 
                document.getElementById('modal_nit_ruc')?.value.trim() || '' :
                document.getElementById('tax_id')?.value.trim() || '';
            const legalRep = isModal ? 
                document.getElementById('modal_legal_representative')?.value.trim() || '' :
                document.getElementById('legal_representative')?.value.trim() || '';
            formData.append('company_name', companyName);
            formData.append('tax_id', taxId);
            formData.append('legal_representative', legalRep);
        }
        
        // DEBUG: Capturar datos reales del formulario
        console.log('=== DEBUG REAL ===');
        console.log('clientType:', clientType);
        console.log('idNumber:', idNumber);
        console.log('phone:', phone);
        console.log('FormData antes del envío:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: '${value}' (type: ${typeof value}, length: ${value.length})`);
        }
        console.log('=== FIN DEBUG ===');
        
        console.log('Enviando datos del cliente...');
        
        // Enviar datos via AJAX
        fetch('../clients/ajax_create.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Respuesta recibida:', response);
            return response.json();
        })
        .then(data => {
            console.log('Datos procesados:', data);
            
            if (data.success) {
                const clientIdInput = document.getElementById('client_id');
                const clientSearchInput = document.getElementById('client_search');
                if (clientIdInput) clientIdInput.value = data.client_id;
                if (clientSearchInput) clientSearchInput.value = data.client_name;
                
                const dropdown = document.getElementById('client_dropdown');
                if (dropdown) dropdown.style.display = 'none';
                
                const selectedClient = {
                    id: data.client_id,
                    name: data.client_name,
                    phone: data.client_phone || '',
                    id_number: data.client_id_number || '',
                    client_type: data.client_type || 'individual'
                };
                if (typeof window.showClientInfo === 'function') {
                    window.showClientInfo(selectedClient);
                } else {
                    const clientInfoSection = document.getElementById('client-info-section');
                    const clientName = document.getElementById('selected-client-name');
                    const clientPhone = document.getElementById('selected-client-phone');
                    const clientIdNumber = document.getElementById('selected-client-id-number');
                    const clientType = document.getElementById('selected-client-type');
                    if (clientInfoSection) clientInfoSection.classList.remove('d-none');
                    if (clientName) clientName.textContent = selectedClient.name || 'No especificado';
                    if (clientPhone) clientPhone.textContent = selectedClient.phone || 'No especificado';
                    if (clientIdNumber) clientIdNumber.textContent = selectedClient.id_number || 'No especificado';
                    if (clientType) clientType.textContent = selectedClient.client_type === 'company' ? 'Empresa' : 'Persona Natural';
                }
                
                // Cerrar modal y limpiar formulario
                const modalElement = document.getElementById('newClientModal');
                if (modalElement) {
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                        
                        // Asegurar que el backdrop se elimine completamente
                        modalElement.addEventListener('hidden.bs.modal', function() {
                            // Eliminar cualquier backdrop residual
                            const backdrops = document.querySelectorAll('.modal-backdrop');
                            backdrops.forEach(backdrop => backdrop.remove());
                            
                            // Restaurar el scroll del body
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                            document.body.style.paddingRight = '';
                        }, { once: true });
                    }
                }
                
                form.reset();
                toggleModalClientFields();
                
                if (typeof showSuccess === 'function') {
                    showSuccess('Cliente creado correctamente');
                } else {
                    alert('Cliente creado exitosamente');
                }
            } else {
                if (typeof showError === 'function') {
                    showError('Error al crear cliente: ' + (data.message || 'Error desconocido'));
                } else {
                    alert('Error al crear cliente: ' + (data.message || 'Error desconocido'));
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof showError === 'function') {
                showError('Error al crear cliente. Por favor, intente nuevamente.');
            } else {
                alert('Error al crear cliente. Por favor, intente nuevamente.');
            }
        });
    };
    
    // Configurar eventos para los radio buttons del tipo de cliente (solo si existen)
    const clientTypeRadios = document.querySelectorAll('input[name="modal_client_type"]');
    if (clientTypeRadios.length > 0) {
        clientTypeRadios.forEach(radio => {
            radio.addEventListener('change', toggleModalClientFields);
        });
        
        // Inicializar campos del modal al cargar
        toggleModalClientFields();
    }
    
    // Manejar el evento de apertura del modal (solo si existe)
    const newClientModal = document.getElementById('newClientModal');
    if (newClientModal) {
        newClientModal.addEventListener('shown.bs.modal', function () {
            console.log('Modal de nuevo cliente abierto');
            toggleModalClientFields();
        });
    }
    
    console.log('Manejadores de modal configurados correctamente');
});
