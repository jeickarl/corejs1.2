<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';

requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validación de campos
    $name = trim($_POST['name'] ?? '');
    $document_type = $_POST['document_type'] ?? '';
    $document_number = trim($_POST['document_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $bank = trim($_POST['bank'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $account_number = trim($_POST['account_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validaciones obligatorias
    if (empty($name)) {
        $errors[] = "El nombre del proveedor es obligatorio.";
    }
    
    if (empty($document_type)) {
        $errors[] = "El tipo de documento es obligatorio.";
    }
    
    if (empty($document_number)) {
        $errors[] = "El número de documento es obligatorio.";
    }

    // Validar que al menos un medio de contacto esté presente
    if (empty($phone) && empty($mobile) && empty($email)) {
        $errors[] = "Debe proporcionar al menos un medio de contacto (teléfono, celular o email).";
    }

    // Validar email si se proporciona
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El formato del email no es válido.";
    }
    
    // Si no hay errores, insertar el proveedor
    if (empty($errors)) {
        try {
            // Verificar duplicidad silenciosamente antes de insertar
            $duplicate_found = false;
            if (!empty($document_number)) {
                $sql = "SELECT id FROM suppliers WHERE document_number = ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
                $check_stmt = $pdo->prepare($sql);
                $check_stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$document_number, $tenantValue] : [$document_number]);
                if ($check_stmt->fetch()) {
                    $duplicate_found = true;
                }
            }
            
            if ($duplicate_found) {
                // Si hay duplicado, redirigir sin mostrar error
                header("Location: index.php");
                exit();
            }
            
            if ($hasTenantSuppliers) {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers (tenant_id, supplier_type, company_name, tax_id, document_type, document_number, phone, mobile, email, address, city, country, bank_name, account_type, account_number, notes, is_active, created_at, updated_at) 
                    VALUES (?, 'company', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                ");
                
                $stmt->execute([
                    $tenantValue,
                    $name,
                    $document_number,
                    $document_type,
                    $document_number,
                    $phone ?: null,
                    $mobile ?: null,
                    $email ?: null,
                    $address ?: null,
                    $city ?: null,
                    $country ?: null,
                    $bank ?: null,
                    $account_type ?: null,
                    $account_number ?: null,
                    $notes ?: null
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers (supplier_type, company_name, tax_id, document_type, document_number, phone, mobile, email, address, city, country, bank_name, account_type, account_number, notes, is_active, created_at, updated_at) 
                    VALUES ('company', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                ");
                
                $stmt->execute([
                    $name,
                    $document_number,
                    $document_type,
                    $document_number,
                    $phone ?: null,
                    $mobile ?: null,
                    $email ?: null,
                    $address ?: null,
                    $city ?: null,
                    $country ?: null,
                    $bank ?: null,
                    $account_type ?: null,
                    $account_number ?: null,
                    $notes ?: null
                ]);
            }

            header("Location: index.php?success=Proveedor registrado exitosamente");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Error al registrar el proveedor: " . $e->getMessage();
        }
    }
}
?>

<?php
// Configuración del template
$page_title = 'Nuevo Proveedor';
$additional_js = [];

// Capturar el contenido de la página
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>
<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
            <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 gap-3 flex-wrap">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-plus-circle me-2 text-primary no-theme"></i>Nuevo Proveedor</h4>
                    <div class="text-muted small">Registrar un nuevo proveedor en el sistema</div>
                </div>
                <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Error al guardar</h6>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate id="supplierForm">
        <div class="row">
            <!-- Columna Izquierda: Información Principal -->
            <div class="col-lg-8">
                <!-- Información Básica -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-building me-2 text-primary"></i>Información del Proveedor
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label fw-bold">Nombre del Proveedor <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-signature text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="name" name="name" required 
                                           maxlength="255" placeholder="Nombre completo o razón social" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="document_type" class="form-label fw-bold">Tipo de Documento <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-id-card text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="document_type" name="document_type" required>
                                        <option value="">Seleccionar tipo</option>
                                        <option value="nit" <?php echo ($_POST['document_type'] ?? '') === 'nit' ? 'selected' : ''; ?>>NIT</option>
                                        <option value="cedula" <?php echo ($_POST['document_type'] ?? '') === 'cedula' ? 'selected' : ''; ?>>Cédula de Ciudadanía</option>
                                        <option value="rut" <?php echo ($_POST['document_type'] ?? '') === 'rut' ? 'selected' : ''; ?>>RUT</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="document_number" class="form-label fw-bold">Número de Documento <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-hashtag text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="document_number" name="document_number" required 
                                           maxlength="50" placeholder="Número de identificación" 
                                           value="<?php echo htmlspecialchars($_POST['document_number'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Información de Contacto -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-address-book me-2 text-primary"></i>Información de Contacto
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-info border-0 bg-info bg-opacity-10 rounded-3 mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <small>Debe proporcionar al menos un medio de contacto (teléfono, celular o email).</small>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-bold">Teléfono Fijo</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-phone text-muted"></i>
                                    </span>
                                    <input type="tel" class="form-control border-start-0 ps-0" id="phone" name="phone" 
                                           maxlength="20" placeholder="Ej: (601) 234-5678" 
                                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="mobile" class="form-label fw-bold">Teléfono Celular</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-mobile-alt text-muted"></i>
                                    </span>
                                    <input type="tel" class="form-control border-start-0 ps-0" id="mobile" name="mobile" 
                                           maxlength="20" placeholder="Ej: 300 123 4567" 
                                           value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="email" class="form-label fw-bold">Correo Electrónico</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-envelope text-muted"></i>
                                    </span>
                                    <input type="email" class="form-control border-start-0 ps-0" id="email" name="email" 
                                           maxlength="255" placeholder="contacto@proveedor.com" 
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="address" class="form-label fw-bold">Dirección Completa</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-map-marker-alt text-muted"></i>
                                    </span>
                                    <textarea class="form-control border-start-0 ps-0" id="address" name="address" rows="2" 
                                              placeholder="Dirección física del proveedor"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="city" class="form-label fw-bold">Ciudad</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-city text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="city" name="city" 
                                           maxlength="100" placeholder="Ciudad" 
                                           value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="country" class="form-label fw-bold">País</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-globe-americas text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="country" name="country" 
                                           maxlength="100" placeholder="País" 
                                           value="<?php echo htmlspecialchars($_POST['country'] ?? 'Colombia'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Información Adicional y Acciones -->
            <div class="col-lg-4">
                <!-- Información Financiera -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-university me-2 text-primary"></i>Datos Bancarios
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="bank" class="form-label fw-bold">Entidad Bancaria</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-university text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="bank" name="bank" 
                                           maxlength="100" placeholder="Nombre del banco" 
                                           value="<?php echo htmlspecialchars($_POST['bank'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="account_type" class="form-label fw-bold">Tipo de Cuenta</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-wallet text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="account_type" name="account_type">
                                        <option value="">Seleccionar tipo</option>
                                        <option value="ahorros" <?php echo ($_POST['account_type'] ?? '') === 'ahorros' ? 'selected' : ''; ?>>Ahorros</option>
                                        <option value="corriente" <?php echo ($_POST['account_type'] ?? '') === 'corriente' ? 'selected' : ''; ?>>Corriente</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="account_number" class="form-label fw-bold">Número de Cuenta</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-money-check text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="account_number" name="account_number" 
                                           maxlength="50" placeholder="No. de cuenta" 
                                           value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notas y Acciones -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="card-title mb-0 fw-bold">
                            <i class="fas fa-sticky-note me-2 text-primary"></i>Notas
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <textarea class="form-control" id="notes" name="notes" rows="4" 
                                      placeholder="Observaciones adicionales..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold">
                                <i class="fas fa-save me-2"></i>Guardar Proveedor
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary btn-lg rounded-pill fw-bold">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
</div>
</div>
</div>

<script>
    // Validación básica del lado del cliente
    document.getElementById('supplierForm').addEventListener('submit', function(event) {
        if (!this.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        this.classList.add('was-validated');
    });
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
