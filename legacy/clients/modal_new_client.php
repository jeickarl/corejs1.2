<!-- Modal para Nuevo Cliente -->
<div class="modal fade" id="newClientModal" tabindex="-1" aria-labelledby="newClientModalLabel" aria-hidden="true">
    <style>
        #newClientModal .btn {
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }
        #newClientModal #modal_individual:checked + label {
            background-color: rgba(13, 110, 253, 0.12) !important;
            border-color: #0d6efd !important;
            color: #0d6efd !important;
            box-shadow: 0 2px 6px rgba(13, 110, 253, 0.18) !important;
            font-weight: 700;
        }
        #newClientModal #modal_company:checked + label {
            background-color: rgba(13, 202, 240, 0.12) !important;
            border-color: #0dcaf0 !important;
            color: #0aa2c0 !important;
            box-shadow: 0 2px 6px rgba(13, 202, 240, 0.18) !important;
            font-weight: 700;
        }
    </style>
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header bg-dark text-white rounded-top-3">
                <h5 class="modal-title" id="newClientModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Nuevo Cliente
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="newClientForm">
                    <!-- Token CSRF: Usar el mismo de la sesión principal si existe -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? SecurityEnhancements::generateCSRFToken(); ?>">
                    
                    <!-- Tipo de Cliente -->
                    <div class="mb-3 text-center">
                        <label class="form-label d-block fw-bold mb-2">Tipo de Cliente</label>
                        <div class="d-flex gap-2 w-100">
                            <input type="radio" class="btn-check" name="modal_client_type" value="individual" id="modal_individual" checked onchange="toggleModalClientFields()">
                            <label class="btn btn-outline-primary w-50 rounded-pill py-2" for="modal_individual">
                                <i class="fas fa-user me-2"></i>Persona Natural
                            </label>

                            <input type="radio" class="btn-check" name="modal_client_type" value="company" id="modal_company" onchange="toggleModalClientFields()">
                            <label class="btn btn-outline-info w-50 rounded-pill py-2" for="modal_company">
                                <i class="fas fa-building me-2"></i>Empresa
                            </label>
                        </div>
                    </div>

                    <!-- Campos para Persona Natural -->
                    <div id="modal-individual-fields">
                        <h6 class="text-muted border-bottom pb-2 mb-3"><i class="fas fa-user me-2"></i>Información Personal</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_identification_number" class="form-label fw-medium">Número de Identificación</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-id-card"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_identification_number" name="modal_identification_number" maxlength="20" placeholder="Cédula, pasaporte, etc.">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_name" class="form-label fw-medium">Nombre Completo <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_name" name="modal_name" required maxlength="200" placeholder="Nombre completo del cliente">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Campos para Empresa -->
                    <div id="modal-company-fields" style="display: none;">
                        <h6 class="text-muted border-bottom pb-2 mb-3"><i class="fas fa-building me-2"></i>Información Empresarial</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_nit_ruc" class="form-label fw-medium">NIT/RUC <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-fingerprint"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_nit_ruc" name="modal_nit_ruc" maxlength="20" placeholder="NIT, RUC, etc.">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_company_name" class="form-label fw-medium">Razón Social <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-building"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_company_name" name="modal_company_name" maxlength="200" placeholder="Nombre legal de la empresa">
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="modal_legal_representative" class="form-label fw-medium">Representante Legal</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user-tie"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_legal_representative" name="modal_legal_representative" maxlength="100" placeholder="Nombre del representante legal">
                            </div>
                        </div>
                    </div>

                    <!-- Información de Contacto -->
                    <h6 class="text-muted border-bottom pb-2 mb-3 mt-4"><i class="fas fa-address-book me-2"></i>Información de Contacto</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modal_phone" class="form-label fw-medium">Teléfono <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted rounded-start-pill px-3">
                                    <i class="fas fa-phone"></i>
                                </span>
                                <input type="text" class="form-control border-start-0 text-center bg-light text-muted" 
                                       id="modal_phone_prefix" 
                                       name="modal_phone_prefix" 
                                       value="<?php echo CompanySettings::getPhoneConfig()['prefix']; ?>" 
                                       style="max-width: 80px;"
                                       placeholder="+57">
                                <input type="tel" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_phone" name="modal_phone" required maxlength="20" placeholder="3001234567">
                            </div>
                            <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Prefijo y número de teléfono</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modal_email" class="form-label fw-medium">Email</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="modal_email" name="modal_email" maxlength="100" placeholder="correo@ejemplo.com">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label for="modal_address" class="form-label fw-medium">Dirección</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-map-marker-alt"></i></span>
                                <textarea class="form-control bg-light border-start-0 rounded-end-pill px-3 py-2" id="modal_address" name="modal_address" rows="2" maxlength="255" placeholder="Dirección completa del cliente (opcional)"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <button type="button" class="btn btn-dark rounded-pill px-4" onclick="saveNewClient()">
                    <i class="fas fa-save me-2"></i>Guardar Cliente
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleModalClientFields() {
    const individualRadio = document.getElementById('modal_individual');
    const companyRadio = document.getElementById('modal_company');
    const individualFields = document.getElementById('modal-individual-fields');
    const companyFields = document.getElementById('modal-company-fields');
    
    if (individualRadio.checked) {
        individualFields.style.display = 'block';
        companyFields.style.display = 'none';
    } else {
        individualFields.style.display = 'none';
        companyFields.style.display = 'block';
    }
}

function saveNewClient() {
    const form = document.getElementById('newClientForm');
    const formData = new FormData(form);
    
    // Validaciones básicas antes de enviar
    const type = formData.get('modal_client_type');
    if (type === 'individual' && !formData.get('modal_name')) {
        alert('El nombre es obligatorio');
        return;
    }
    if (type === 'company' && (!formData.get('modal_company_name') || !formData.get('modal_nit_ruc'))) {
        alert('La razón social y NIT/RUC son obligatorios');
        return;
    }
    if (!formData.get('modal_phone')) {
        alert('El teléfono es obligatorio');
        return;
    }

    // Mapear campos del modal a los nombres esperados por ajax_create.php
    // ajax_create.php espera: client_type, first_name, company_name, tax_id, phone, email, etc.
    // El modal usa prefijo 'modal_' para evitar conflictos de ID si se incluye en otras páginas.
    
    const dataToSend = new FormData();
    dataToSend.append('csrf_token', formData.get('csrf_token'));
    dataToSend.append('client_type', type);
    dataToSend.append('phone', formData.get('modal_phone'));
    dataToSend.append('email', formData.get('modal_email'));
    dataToSend.append('address', formData.get('modal_address'));
    
    // Combinar prefijo con teléfono si es necesario, pero ajax_create espera 'phone' completo o separado?
    // ajax_create toma $_POST['phone']. En el form new.php se envía solo 'phone'.
    // Aquí concatenamos si se usa el prefijo visualmente, o enviamos tal cual.
    // Asumamos que enviamos el número limpio.
    
    if (type === 'individual') {
        dataToSend.append('first_name', formData.get('modal_name'));
        dataToSend.append('id_number', formData.get('modal_identification_number'));
    } else {
        dataToSend.append('company_name', formData.get('modal_company_name'));
        dataToSend.append('tax_id', formData.get('modal_nit_ruc'));
        dataToSend.append('legal_representative', formData.get('modal_legal_representative'));
        dataToSend.append('id_number', formData.get('modal_nit_ruc')); // Usar NIT como ID number también para consistencia
    }

    fetch('ajax_create.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: dataToSend
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            // Cerrar modal
            const modalEl = document.getElementById('newClientModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            
            // Recargar o actualizar UI
            // Si estamos en index.php, recargar
            if (window.location.pathname.includes('/clients/') && (window.location.pathname.endsWith('index.php') || window.location.pathname.endsWith('/'))) {
                window.location.reload();
            } else {
                // Si estamos en órdenes u otro lado, quizás queramos seleccionar el cliente creado
                // Disparar evento personalizado
                const event = new CustomEvent('clientCreated', { detail: data });
                document.dispatchEvent(event);
                alert('Cliente creado exitosamente');
                form.reset();
            }
        } else {
            alert(data.message || 'Error al guardar cliente');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error de conexión al guardar cliente');
    });
}
</script>
