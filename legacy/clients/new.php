<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

// Registrar actividad
logActivity($_SESSION['user_id'], 'ACCESS_CREATE_CLIENT', 'clients', null);

$errors = [];
$csrf_token = SecurityEnhancements::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Error de seguridad (CSRF). Por favor, envíe el formulario nuevamente.";
    } else {
        // Validación de campos comunes
        $client_type = $_POST['client_type'] ?? 'individual';
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Para empresas, usar tax_id como id_number
    if ($client_type === 'company') {
        $id_number = trim($_POST['tax_id'] ?? '');
    }
    else {
        $id_number = trim($_POST['id_number'] ?? '');
    }

    // Validación específica según tipo de cliente
    if ($client_type === 'individual') {
        $name = trim($_POST['name'] ?? '');
        $company_name = null;
        $tax_id = null;
        $legal_representative = null;

        if (empty($name)) {
            $errors[] = "El nombre es obligatorio para personas naturales.";
        }
    }
    else {
        $tax_id = trim($_POST['tax_id'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $legal_representative = trim($_POST['legal_representative'] ?? '');
        $name = null;

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

    // Si no hay errores, insertar el cliente
    if (empty($errors)) {
        try {
            // Verificar duplicidad silenciosamente antes de insertar
            $duplicate_found = false;
            $tenant_id = getCurrentTenantId();
            $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
            if ($client_type === 'company' && !empty($tax_id)) {
                if ($perDatabase) {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE tax_id = ? LIMIT 1");
                    $check_stmt->execute([$tax_id]);
                } else {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE tax_id = ? AND tenant_id = ? LIMIT 1");
                    $check_stmt->execute([$tax_id, $tenant_id]);
                }
                if ($check_stmt->fetch()) {
                    $duplicate_found = true;
                }
            }
            elseif ($client_type === 'individual' && !empty($id_number)) {
                if ($perDatabase) {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE id_number = ? LIMIT 1");
                    $check_stmt->execute([$id_number]);
                } else {
                    $check_stmt = $pdo->prepare("SELECT id FROM clients WHERE id_number = ? AND tenant_id = ? LIMIT 1");
                    $check_stmt->execute([$id_number, $tenant_id]);
                }
                if ($check_stmt->fetch()) {
                    $duplicate_found = true;
                }
            }

            if ($duplicate_found) {
                // Si hay duplicado, redirigir sin mostrar error
                header("Location: index.php");
                exit();
            }

            // Verificar y reparar estructura AUTO_INCREMENT pre-insercion si fuese necesario
            fixTableAutoIncrement($pdo, 'clients');

            if ($perDatabase) {
                $stmt = $pdo->prepare("
                    INSERT INTO clients (client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $client_type,
                    $name,
                    $company_name,
                    $tax_id,
                    $legal_representative,
                    $phone,
                    $email ?: null,
                    $id_number ?: null,
                    $address ?: null
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO clients (tenant_id, client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $tenant_id,
                    $client_type,
                    $name,
                    $company_name,
                    $tax_id,
                    $legal_representative,
                    $phone,
                    $email ?: null,
                    $id_number ?: null,
                    $address ?: null
                ]);
            }

            $new_client_id = $pdo->lastInsertId();
            try {
                $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
                $colStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'client_number'");
                $colStmt->execute([$dbName]);
                if ((int)$colStmt->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE clients ADD COLUMN client_number INT(11) NOT NULL DEFAULT 0 AFTER id");
                    try { $pdo->exec("ALTER TABLE clients ADD UNIQUE KEY unique_client_tenant (client_number, tenant_id)"); } catch (Throwable $__) {}
                }
                $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
                $stmtMax = $perDatabase
                    ? $pdo->prepare("SELECT MAX(client_number) FROM clients")
                    : $pdo->prepare("SELECT MAX(client_number) FROM clients WHERE tenant_id = ?");
                $stmtMax->execute($perDatabase ? [] : [$tenantValue]);
                $maxDb = (int)($stmtMax->fetchColumn() ?: 0);
                $cfgVal = (int)cfg_get('client_next_number', 0);
                $startAt = max($maxDb, $cfgVal) + 1;
                $nextCode = getNextTenantSequence($pdo, $tenant_id, 'clients', $startAt);
                if ($perDatabase) {
                    $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ?")->execute([(int)$nextCode, $new_client_id]);
                } else {
                    $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ? AND tenant_id = ?")->execute([(int)$nextCode, $new_client_id, $tenantValue]);
                }
            } catch (Throwable $__) {}
            logActivity($_SESSION['user_id'], 'CREATE_CLIENT', 'clients', $new_client_id, "Nuevo cliente creado: " . ($client_type === 'company' ? $company_name : $name));

            header("Location: index.php?success=Cliente registrado exitosamente");
            exit();
        }
        catch (PDOException $e) {
            $errors[] = "Error al registrar el cliente: " . $e->getMessage();
        }
    }
    } // Cierra el else del token csrf
}
?>

<?php
// Configuración del template
$page_title = 'Nuevo Cliente';

$additional_js = ['../assets/js/real-time-validation.js'];

// Capturar el contenido de la página
ob_start();
?>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <form method="POST" novalidate id="clientForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="card card-modern border-0 shadow-sm overflow-hidden">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-3 mb-4">
                    <div>
                        <h4 class="fw-bold text-dark mb-1"><i class="fas fa-user-plus me-2 text-primary no-theme"></i>Nuevo Cliente</h4>
                        <div class="text-muted small">Registra un nuevo cliente en el sistema</div>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-left me-2"></i>Volver a Clientes
                    </a>
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

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list-ul text-primary me-2 no-theme"></i>Tipo de Cliente</h5>
                </div>

                <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check custom-radio">
                        <input class="form-check-input" type="radio" name="client_type" value="individual" id="individual"
                               <?php echo(!isset($_POST['client_type']) || $_POST['client_type'] === 'individual') ? 'checked' : ''; ?>
                               onchange="toggleClientFields()">
                        <label class="form-check-label cursor-pointer" for="individual">
                            <i class="fas fa-user me-2 text-primary no-theme"></i>Persona Natural
                        </label>
                    </div>
                    <div class="form-check custom-radio">
                        <input class="form-check-input" type="radio" name="client_type" value="company" id="company"
                               <?php echo(isset($_POST['client_type']) && $_POST['client_type'] === 'company') ? 'checked' : ''; ?>
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

                <div id="individual-fields" style="display: <?php echo(!isset($_POST['client_type']) || $_POST['client_type'] === 'individual') ? 'block' : 'none'; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-medium">Nombre Completo <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="name" name="name" required
                                       maxlength="200" placeholder="Ej. Juan Pérez"
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>
                            <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Nombre completo del cliente</div>
                        </div>

                        <div class="col-md-6">
                            <label for="id_number" class="form-label fw-medium">Identificación (Cédula/DNI)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-id-card"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="id_number" name="id_number"
                                       maxlength="20" placeholder="Ej. 1234567890"
                                       value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>">
                            </div>
                            <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Documento de identidad (Opcional)</div>
                        </div>
                    </div>
                </div>

                <div id="company-fields" style="display: <?php echo(isset($_POST['client_type']) && $_POST['client_type'] === 'company') ? 'block' : 'none'; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="company_name" class="form-label fw-medium">Razón Social <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-building"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="company_name" name="company_name"
                                       maxlength="200" placeholder="Ej. Soluciones Tecnológicas S.A.S."
                                       value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="tax_id" class="form-label fw-medium">NIT/RUC <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-fingerprint"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="tax_id" name="tax_id"
                                       maxlength="20" placeholder="Ej. 900.123.456-7"
                                       value="<?php echo htmlspecialchars($_POST['tax_id'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="col-md-12">
                            <label for="legal_representative" class="form-label fw-medium">Representante Legal</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-user-tie"></i></span>
                                <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="legal_representative" name="legal_representative"
                                       maxlength="100" placeholder="Nombre del representante legal"
                                       value="<?php echo htmlspecialchars($_POST['legal_representative'] ?? ''); ?>">
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
                                   value="<?php
$p = (string)($_POST['phone_prefix'] ?? CompanySettings::getPhoneConfig()['prefix']);
$pOnly = preg_replace('/[^0-9+]/', '', $p);
$pDigits = preg_replace('/\D/', '', $pOnly);
echo htmlspecialchars($pDigits !== '' ? ('+' . $pDigits) : '+57');
?>">
                            <input type="tel" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="phone_number" name="phone_number" required
                                   maxlength="20" placeholder="300 123 4567"
                                   value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                        </div>
                        <div class="form-text mt-2 ms-2"><i class="fas fa-info-circle me-1"></i>Número de contacto principal (WhatsApp)</div>
                    </div>

                    <div class="col-md-6">
                        <label for="email" class="form-label fw-medium">Correo Electrónico</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="email" name="email"
                                   maxlength="100" placeholder="cliente@ejemplo.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-0">
                    <label for="address" class="form-label fw-medium">Dirección</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-map-marker-alt"></i></span>
                        <textarea class="form-control bg-light border-start-0 rounded-end-pill px-3 py-2" id="address" name="address" rows="2"
                                  maxlength="255" placeholder="Dirección completa del cliente"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="border-top my-4"></div>

                <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <a href="index.php" class="btn btn-light border rounded-pill px-4 fw-bold text-muted">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                        <i class="fas fa-save me-2"></i>Guardar Cliente
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

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

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
