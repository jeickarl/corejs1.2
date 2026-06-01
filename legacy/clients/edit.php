<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

$cliente_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($cliente_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de cliente no válido.'));
    exit();
}

// Obtener datos del cliente
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$cliente_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$cliente_id, $tenant_id]);
    }
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        header('Location: index.php?error=' . urlencode('Cliente no encontrado.'));
        exit();
    }
}
catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar el cliente.'));
    exit();
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'ACCESS_EDIT_CLIENT', 'clients', $cliente_id);

$errors = [];
$csrf_token = SecurityEnhancements::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Error de seguridad (CSRF). Por favor, envíe el formulario nuevamente.";
    } else {
        // Validación de campos comunes
        $client_type = $_POST['client_type'] ?? $cliente['client_type']; // Default to existing if missing
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Para empresas, usar tax_id como id_number si es necesario, pero mantenemos campos separados
    $id_number = null;
    $tax_id = null;
    $company_name = null;
    $legal_representative = null;
    $name = null;

    // Validación específica según tipo de cliente
    if ($client_type === 'individual') {
        $name = trim($_POST['name'] ?? '');
        $id_number = trim($_POST['id_number'] ?? '');

        if (empty($name)) {
            $errors[] = "El nombre es obligatorio para personas naturales.";
        }
    }
    else {
        $tax_id = trim($_POST['tax_id'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $legal_representative = trim($_POST['legal_representative'] ?? '');

        if (empty($tax_id)) {
            $errors[] = "El NIT/RUC es obligatorio para empresas.";
        }
        if (empty($company_name)) {
            $errors[] = "La razón social es obligatoria para empresas.";
        }
    }

    // Validar teléfono (obligatorio)
    $phone_prefix = trim($_POST['phone_prefix'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    if (empty($phone_number)) {
        $errors[] = "El número de teléfono es obligatorio.";
    }
    else {
        // Concatenar indicativo y número
        // Limpiar número de espacios y guiones para guardar
        $clean_number = preg_replace('/[^0-9]/', '', $phone_number);
        if ($phone_prefix === '') {
            $phone_prefix = CompanySettings::getPhoneConfig()['prefix'];
        }
        $prefixOnly = preg_replace('/[^0-9+]/', '', (string)$phone_prefix);
        $digitsOnly = preg_replace('/\D/', '', $prefixOnly);
        $phone_prefix = ($digitsOnly !== '') ? ('+' . $digitsOnly) : '';
        $phone = trim($phone_prefix) . ' ' . $clean_number;

        // Validar formato del teléfono
        if (!CompanySettings::validatePhone($phone)) {
            $errors[] = "El formato del teléfono no es válido.";
        }
    }

    // Validar email si se proporciona
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El formato del email no es válido.";
    }

    // Si no hay errores, actualizar el cliente
    if (empty($errors)) {
        try {
            // Verificar duplicidad (excluyendo el actual)
            $duplicate_found = false;
            if ($client_type === 'company' && !empty($tax_id)) {
                $norm_tax = preg_replace('/\D/', '', $tax_id);
                if ($perDatabase) {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tax_id,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? AND id != ?");
                    $check_stmt->execute([$norm_tax, $cliente_id]);
                } else {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tax_id,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? AND id != ? AND tenant_id = ?");
                    $check_stmt->execute([$norm_tax, $cliente_id, $tenant_id]);
                }
                if ($check_stmt->fetch()) {
                    $errors[] = "Ya existe otro cliente con este NIT/RUC.";
                }
            }
            elseif ($client_type === 'individual' && !empty($id_number)) {
                $norm_id = preg_replace('/\D/', '', $id_number);
                if ($perDatabase) {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? AND id != ?");
                    $check_stmt->execute([$norm_id, $cliente_id]);
                } else {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? AND id != ? AND tenant_id = ?");
                    $check_stmt->execute([$norm_id, $cliente_id, $tenant_id]);
                }
                if ($check_stmt->fetch()) {
                    $errors[] = "Ya existe otro cliente con esta identificación.";
                }
            }

            if (empty($errors)) {
                $id_number = $id_number !== null ? preg_replace('/\D/', '', $id_number) : null;
                $tax_id = $tax_id !== null ? preg_replace('/\D/', '', $tax_id) : null;
                $sql = "UPDATE clients SET 
                        client_type = ?, 
                        first_name = ?, 
                        company_name = ?, 
                        tax_id = ?, 
                        legal_representative = ?, 
                        phone = ?, 
                        email = ?, 
                        id_number = ?, 
                        address = ?, 
                        notes = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
                $params = [
                    $client_type,
                    $name,
                    $company_name,
                    $tax_id,
                    $legal_representative,
                    $phone,
                    $email ?: null,
                    $id_number ?: null,
                    $address ?: null,
                    $notes ?: null,
                    $cliente_id
                ];
                if (!$perDatabase) {
                    $sql .= " AND tenant_id = ?";
                    $params[] = $tenant_id;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                logActivity($_SESSION['user_id'], 'UPDATE_CLIENT', 'clients', $cliente_id, "Cliente actualizado: " . ($client_type === 'company' ? $company_name : $name));

                header('Location: view.php?id=' . $cliente_id . '&success=' . urlencode('Cliente actualizado exitosamente.'));
                exit();
            }
        }
        catch (PDOException $e) {
            $errors[] = "Error al actualizar el cliente: " . $e->getMessage();
        }
    }
    } // Cierra el else del token CSRF
}

// Configuración del template
$page_title = 'Editar Cliente - ' . ($cliente['client_type'] === 'company' ? $cliente['company_name'] : $cliente['first_name']);

$additional_js = ['../assets/js/real-time-validation.js'];

// Prepara datos para el formulario
$form_client_type = $_POST['client_type'] ?? $cliente['client_type'];
$form_name = $_POST['name'] ?? $cliente['first_name'];
$form_company_name = $_POST['company_name'] ?? $cliente['company_name'];
$form_tax_id = $_POST['tax_id'] ?? $cliente['tax_id'];
$form_legal_representative = $_POST['legal_representative'] ?? $cliente['legal_representative'];
$form_id_number = $_POST['id_number'] ?? $cliente['id_number'];
$form_email = $_POST['email'] ?? $cliente['email'];
$form_address = $_POST['address'] ?? $cliente['address'];
$form_notes = $_POST['notes'] ?? $cliente['notes'];

// Preparar teléfono
$current_full_phone = $_POST['phone'] ?? $cliente['phone'];
$current_prefix = CompanySettings::getPhoneConfig()['prefix'];
$current_number = $current_full_phone;

// Separar prefijo y número (soporta formatos antiguos pegados y nuevo con espacio)
$rawPhone = trim((string)$current_full_phone);
$cfgPrefix = CompanySettings::getPhoneConfig()['prefix'];
$cfgPrefix = trim((string)$cfgPrefix);
$current_prefix = $cfgPrefix !== '' ? $cfgPrefix : $current_prefix;
$current_number = $rawPhone;
if ($rawPhone !== '') {
    if (strpos($rawPhone, ' ') !== false) {
        $parts = preg_split('/\s+/', $rawPhone, 2);
        $pfx = trim((string)($parts[0] ?? ''));
        $num = trim((string)($parts[1] ?? ''));
        if ($pfx !== '' && $pfx[0] === '+') {
            $current_prefix = $pfx;
            $current_number = $num;
        }
    } elseif (strpos($rawPhone, '+') === 0) {
        $candidates = [];
        try {
            foreach (CompanySettings::getCountryList() as $k => $_n) {
                $digits = preg_replace('/\D/', '', (string)$k);
                if ($digits !== '') {
                    $candidates[] = '+' . $digits;
                }
            }
        } catch (Throwable $_e) {
        }
        if ($cfgPrefix !== '' && $cfgPrefix[0] === '+') {
            $candidates[] = $cfgPrefix;
        }
        $candidates = array_values(array_unique($candidates));
        usort($candidates, function ($a, $b) { return strlen($b) <=> strlen($a); });
        $matched = '';
        foreach ($candidates as $cand) {
            if ($cand !== '' && strpos($rawPhone, $cand) === 0) {
                $matched = $cand;
                break;
            }
        }
        if ($matched !== '') {
            $current_prefix = $matched;
            $current_number = substr($rawPhone, strlen($matched));
        } else {
            $current_number = $rawPhone;
        }
    }
    $current_number = preg_replace('/\D/', '', (string)$current_number);
}

$prefixOnly = preg_replace('/[^0-9+]/', '', (string)$current_prefix);
$digitsOnly = preg_replace('/\D/', '', $prefixOnly);
$current_prefix = ($digitsOnly !== '') ? ('+' . $digitsOnly) : (CompanySettings::getPhoneConfig()['prefix'] ?? '+57');

// Si viene del POST, usar los valores enviados
if (isset($_POST['phone_prefix']))
    $current_prefix = $_POST['phone_prefix'];
if (isset($_POST['phone_number']))
    $current_number = $_POST['phone_number'];

ob_start();
?>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <form method="POST" novalidate id="clientForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="card card-modern border-0 shadow-sm overflow-hidden">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-3 mb-4">
                    <div>
                        <h4 class="fw-bold text-dark mb-1"><i class="fas fa-user-edit me-2 text-primary no-theme"></i>Editar Cliente</h4>
                        <div class="text-muted small">Modifica los datos del cliente</div>
                    </div>
                    <a href="view.php?id=<?php echo $cliente_id; ?>" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Detalle
                    </a>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list-ul text-primary me-2 no-theme"></i>Tipo de Cliente</h5>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible bg-white border-danger border-start border-4 shadow-sm fade show mb-4" role="alert">
                        <div class="d-flex">
                            <i class="fas fa-exclamation-circle fs-4 text-danger me-3 mt-1"></i>
                            <div>
                                <strong class="text-dark">Por favor corrige los siguientes errores:</strong>
                                <ul class="mb-0 mt-2 text-muted">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check custom-radio">
                        <input class="form-check-input" type="radio" name="client_type" value="individual" id="individual" 
                               <?php echo($form_client_type === 'individual') ? 'checked' : ''; ?>
                               onchange="toggleClientFields()">
                        <label class="form-check-label cursor-pointer" for="individual">
                            <i class="fas fa-user me-2 text-primary no-theme"></i>Persona Natural
                        </label>
                    </div>
                    <div class="form-check custom-radio">
                        <input class="form-check-input" type="radio" name="client_type" value="company" id="company" 
                               <?php echo($form_client_type === 'company') ? 'checked' : ''; ?>
                               onchange="toggleClientFields()">
                        <label class="form-check-label cursor-pointer" for="company">
                            <i class="fas fa-building me-2 text-info no-theme"></i>Empresa
                        </label>
                    </div>
                </div>

                <div class="border-top my-4"></div>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-user text-primary me-2 no-theme"></i>Información Personal y Empresarial</h5>
                </div>
                    <!-- Campos para Persona Natural -->
                    <div id="individual-fields" style="display: <?php echo($form_client_type === 'individual') ? 'block' : 'none'; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label fw-medium">Nombre Completo <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="name" name="name" 
                                           maxlength="200" placeholder="Ej. Juan Pérez" 
                                           value="<?php echo htmlspecialchars($form_name); ?>">
                                </div>
                                <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Nombre completo del cliente</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="id_number" class="form-label fw-medium">Identificación (Cédula/DNI)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-id-card"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="id_number" name="id_number" 
                                           maxlength="20" placeholder="Ej. 1234567890" 
                                           value="<?php echo htmlspecialchars($form_id_number); ?>">
                                </div>
                                <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Documento de identidad (Opcional)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Campos para Empresa -->
                    <div id="company-fields" style="display: <?php echo($form_client_type === 'company') ? 'block' : 'none'; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="company_name" class="form-label fw-medium">Razón Social <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-building"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="company_name" name="company_name" 
                                           maxlength="200" placeholder="Ej. Soluciones Tecnológicas S.A.S." 
                                           value="<?php echo htmlspecialchars($form_company_name); ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="tax_id" class="form-label fw-medium">NIT/RUC <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-fingerprint"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="tax_id" name="tax_id" 
                                           maxlength="20" placeholder="Ej. 900.123.456-7" 
                                           value="<?php echo htmlspecialchars($form_tax_id); ?>">
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="legal_representative" class="form-label fw-medium">Representante Legal</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user-tie"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="legal_representative" name="legal_representative" 
                                           maxlength="100" placeholder="Nombre del representante legal" 
                                           value="<?php echo htmlspecialchars($form_legal_representative); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                <div class="border-top my-4"></div>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-address-book text-primary me-2 no-theme"></i>Información de Contacto</h5>
                </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="phone_number" class="form-label fw-medium">Teléfono Móvil <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted rounded-start-pill px-3">
                                    <i class="fas fa-phone"></i>
                                </span>
                                <input type="text" class="form-control border-start-0 text-center bg-light text-muted" 
                                       id="phone_prefix" name="phone_prefix" 
                                       style="max-width: 70px;"
                                       value="<?php echo htmlspecialchars($current_prefix); ?>">
                                <input type="tel" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="phone_number" name="phone_number" required 
                                       maxlength="20" placeholder="300 123 4567" 
                                       value="<?php echo htmlspecialchars($current_number); ?>">
                            </div>
                            <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Número de contacto principal (WhatsApp)</div>
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label fw-medium">Correo Electrónico</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="email" name="email" 
                                       maxlength="100" placeholder="cliente@ejemplo.com" 
                                       value="<?php echo htmlspecialchars($form_email); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="address" class="form-label fw-medium">Dirección</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-map-marker-alt"></i></span>
                            <textarea class="form-control bg-light border-start-0 rounded-end-pill px-3 py-2" id="address" name="address" rows="2" 
                                      maxlength="255" placeholder="Dirección completa del cliente"><?php echo htmlspecialchars($form_address); ?></textarea>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="notes" class="form-label fw-medium">Notas Adicionales</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-sticky-note"></i></span>
                            <textarea class="form-control bg-light border-start-0 rounded-end-pill px-3 py-2" id="notes" name="notes" rows="3" 
                                      placeholder="Información adicional, preferencias, etc."><?php echo htmlspecialchars($form_notes); ?></textarea>
                        </div>
                    </div>

                <div class="border-top my-4"></div>

                <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <a href="view.php?id=<?php echo $cliente_id; ?>" class="btn btn-light border rounded-pill px-4 fw-bold text-muted">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                        <i class="fas fa-save me-2"></i>Guardar Cambios
                    </button>
                </div>

            </div>
        </div>
    </form>

<script>
    // Función para alternar campos según tipo de cliente
    function toggleClientFields() {
        const individualRadio = document.getElementById('individual');
        const companyRadio = document.getElementById('company');
        const individualFields = document.getElementById('individual-fields');
        const companyFields = document.getElementById('company-fields');
        
        if (individualRadio.checked) {
            individualFields.style.display = 'block';
            companyFields.style.display = 'none';
            
            // Ajustar requeridos
            document.getElementById('name').required = true;
            document.getElementById('company_name').required = false;
            document.getElementById('tax_id').required = false;
        } else {
            individualFields.style.display = 'none';
            companyFields.style.display = 'block';
            
            // Ajustar requeridos
            document.getElementById('name').required = false;
            document.getElementById('company_name').required = true;
            document.getElementById('tax_id').required = true;
        }
    }

    // Inicializar estado al cargar
    document.addEventListener('DOMContentLoaded', function() {
        toggleClientFields();
        
        // Validación de teléfono en tiempo real
        const phoneInput = document.getElementById('phone_number');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                // Eliminar caracteres no numéricos
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
    });
</script>

</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
