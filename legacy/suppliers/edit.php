<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';

requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');

$errors = [];
$supplier = null;
$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($supplier_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de proveedor no válido.'));
    exit();
}

// Obtener datos del proveedor
try {
    $sql = "SELECT * FROM suppliers WHERE id = ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_id, $tenantValue] : [$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        header('Location: index.php?error=' . urlencode('Proveedor no encontrado.'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar el proveedor.'));
    exit();
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar campos requeridos
    $supplier_code = trim($_POST['supplier_code'] ?? '');
    $supplier_type = $_POST['supplier_type'] ?? '';
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $payment_terms = $_POST['payment_terms'] ?? '';
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $rating = $_POST['rating'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    // Validaciones
    if (empty($supplier_code)) {
        $errors[] = 'El código del proveedor es obligatorio.';
    }
    
    if (empty($supplier_type)) {
        $errors[] = 'El tipo de proveedor es obligatorio.';
    }
    
    if (empty($company_name)) {
        $errors[] = 'El nombre de la empresa es obligatorio.';
    }
    
    if (empty($contact_name)) {
        $errors[] = 'El nombre del contacto es obligatorio.';
    }
    
    if (empty($tax_id)) {
        $errors[] = 'El NIT/RUT es obligatorio.';
    }
    
    if (empty($phone) && empty($mobile) && empty($email)) {
        $errors[] = 'Debe proporcionar al menos un medio de contacto (teléfono, celular o email).';
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El formato del email no es válido.';
    }
    
    if (!empty($supplier_code)) {
        // Verificar que el código no esté duplicado (excepto para el proveedor actual)
        try {
            $sql = "SELECT id FROM suppliers WHERE supplier_code = ? AND id != ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_code, $supplier_id, $tenantValue] : [$supplier_code, $supplier_id]);
            if ($stmt->fetch()) {
                $errors[] = 'Ya existe otro proveedor con este código.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar el código: ' . $e->getMessage();
        }
    }
    
    if (!empty($tax_id)) {
        // Verificar que el NIT/RUT no esté duplicado (excepto para el proveedor actual)
        try {
            $sql = "SELECT id FROM suppliers WHERE tax_id = ? AND id != ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tax_id, $supplier_id, $tenantValue] : [$tax_id, $supplier_id]);
            if ($stmt->fetch()) {
                $errors[] = 'Ya existe otro proveedor con este NIT/RUT.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar el NIT/RUT: ' . $e->getMessage();
        }
    }
    
    // Si no hay errores, actualizar el proveedor
    if (empty($errors)) {
        try {
            $sql = "UPDATE suppliers SET 
                    supplier_code = ?, supplier_type = ?, company_name = ?, contact_name = ?, 
                    tax_id = ?, phone = ?, mobile = ?, email = ?, website = ?, address = ?, 
                    city = ?, state = ?, country = ?, postal_code = ?, payment_terms = ?, 
                    credit_limit = ?, discount_percentage = ?, bank_name = ?, account_number = ?, 
                    account_type = ?, is_active = ?, rating = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [
                $supplier_code, $supplier_type, $company_name, $contact_name,
                $tax_id, $phone ?: null, $mobile ?: null, $email ?: null, $website ?: null, $address ?: null,
                $city ?: null, $state ?: null, $country ?: null, $postal_code ?: null, $payment_terms ?: null,
                $credit_limit, $discount_percentage, $bank_name ?: null, $account_number ?: null,
                $account_type ?: null, $is_active, $rating ?: null, $notes ?: null, $supplier_id
            ];
            if ($hasTenantSuppliers && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            // Actualizar los datos del proveedor para mostrar en el formulario
            $supplier = array_merge($supplier, [
                'supplier_code' => $supplier_code,
                'supplier_type' => $supplier_type,
                'company_name' => $company_name,
                'contact_name' => $contact_name,
                'tax_id' => $tax_id,
                'phone' => $phone,
                'mobile' => $mobile,
                'email' => $email,
                'website' => $website,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'postal_code' => $postal_code,
                'payment_terms' => $payment_terms,
                'credit_limit' => $credit_limit,
                'discount_percentage' => $discount_percentage,
                'bank_name' => $bank_name,
                'account_number' => $account_number,
                'account_type' => $account_type,
                'is_active' => $is_active,
                'rating' => $rating,
                'notes' => $notes
            ]);
            
            header('Location: view.php?id=' . $supplier_id . '&success=' . urlencode('Proveedor actualizado exitosamente.'));
            exit();
        } catch (PDOException $e) {
            $errors[] = 'Error al actualizar el proveedor: ' . $e->getMessage();
        }
    }
}

// Mensajes de éxito/error
$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
} elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}
?>

<?php
$page_title = 'Editar Proveedor';
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>

<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
            <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 gap-3 flex-wrap">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-edit me-2 text-primary no-theme"></i>Editar Proveedor</h4>
                    <div class="text-muted small">Modificar información del proveedor</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="view.php?id=<?php echo $supplier['id']; ?>" class="btn btn-outline-primary rounded-pill px-4">
                        <i class="fas fa-eye me-2"></i>Ver
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </a>
                </div>
            </div>

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-circle'; ?> fs-4 me-3"></i>
                        <div>
                            <?php echo htmlspecialchars($mensaje); ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
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
                    <div class="col-lg-8">
                        <!-- Información Básica -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-building me-2 text-primary"></i>Información Básica
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="supplier_code" class="form-label fw-bold">
                                            Código del Proveedor <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-barcode text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="supplier_code" name="supplier_code" 
                                                   value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" 
                                                   required maxlength="20">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="supplier_type" class="form-label fw-bold">
                                            Tipo de Proveedor <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-user-tag text-muted"></i>
                                            </span>
                                            <select class="form-select border-start-0 ps-0" id="supplier_type" name="supplier_type" required>
                                                <option value="">Seleccionar</option>
                                                <option value="company" <?php echo $supplier['supplier_type'] === 'company' ? 'selected' : ''; ?>>Empresa</option>
                                                <option value="individual" <?php echo $supplier['supplier_type'] === 'individual' ? 'selected' : ''; ?>>Persona Natural</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-8">
                                        <label for="company_name" class="form-label fw-bold">
                                            Nombre de la Empresa <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-building text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="company_name" name="company_name" 
                                                   value="<?php echo htmlspecialchars($supplier['company_name']); ?>" 
                                                   required maxlength="255">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="tax_id" class="form-label fw-bold">
                                            NIT/RUT <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-id-card text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="tax_id" name="tax_id" 
                                                   value="<?php echo htmlspecialchars($supplier['tax_id']); ?>" 
                                                   required maxlength="20">
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label for="contact_name" class="form-label fw-bold">
                                            Nombre del Contacto <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-user text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="contact_name" name="contact_name" 
                                                   value="<?php echo htmlspecialchars($supplier['contact_name']); ?>" 
                                                   required maxlength="255">
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
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="phone" class="form-label fw-bold">Teléfono</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-phone text-muted"></i>
                                            </span>
                                            <input type="tel" class="form-control border-start-0 ps-0" id="phone" name="phone" 
                                                   value="<?php echo htmlspecialchars($supplier['phone']); ?>" 
                                                   maxlength="20">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mobile" class="form-label fw-bold">Celular</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-mobile-alt text-muted"></i>
                                            </span>
                                            <input type="tel" class="form-control border-start-0 ps-0" id="mobile" name="mobile" 
                                                   value="<?php echo htmlspecialchars($supplier['mobile']); ?>" 
                                                   maxlength="20">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="email" class="form-label fw-bold">Email</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-envelope text-muted"></i>
                                            </span>
                                            <input type="email" class="form-control border-start-0 ps-0" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($supplier['email']); ?>" 
                                                   maxlength="255">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="website" class="form-label fw-bold">Sitio Web</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-globe text-muted"></i>
                                            </span>
                                            <input type="url" class="form-control border-start-0 ps-0" id="website" name="website" 
                                                   value="<?php echo htmlspecialchars($supplier['website']); ?>" 
                                                   maxlength="255">
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label for="address" class="form-label fw-bold">Dirección</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-map-marker-alt text-muted"></i>
                                            </span>
                                            <textarea class="form-control border-start-0 ps-0" id="address" name="address" rows="2" 
                                                      maxlength="500"><?php echo htmlspecialchars($supplier['address']); ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label for="city" class="form-label fw-bold">Ciudad</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-city text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="city" name="city" 
                                                   value="<?php echo htmlspecialchars($supplier['city']); ?>" 
                                                   maxlength="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="state" class="form-label fw-bold">Estado/Departamento</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-map text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="state" name="state" 
                                                   value="<?php echo htmlspecialchars($supplier['state']); ?>" 
                                                   maxlength="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="country" class="form-label fw-bold">País</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-globe-americas text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="country" name="country" 
                                                   value="<?php echo htmlspecialchars($supplier['country']); ?>" 
                                                   maxlength="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="postal_code" class="form-label fw-bold">Código Postal</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-mail-bulk text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="postal_code" name="postal_code" 
                                                   value="<?php echo htmlspecialchars($supplier['postal_code']); ?>" 
                                                   maxlength="10">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Información Financiera -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-university me-2 text-primary"></i>Información Financiera
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="payment_terms" class="form-label fw-bold">Términos de Pago</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-clock text-muted"></i>
                                            </span>
                                            <select class="form-select border-start-0 ps-0" id="payment_terms" name="payment_terms">
                                                <option value="">Seleccionar</option>
                                                <option value="contado" <?php echo $supplier['payment_terms'] === 'contado' ? 'selected' : ''; ?>>Contado</option>
                                                <option value="15_dias" <?php echo $supplier['payment_terms'] === '15_dias' ? 'selected' : ''; ?>>15 Días</option>
                                                <option value="30_dias" <?php echo $supplier['payment_terms'] === '30_dias' ? 'selected' : ''; ?>>30 Días</option>
                                                <option value="60_dias" <?php echo $supplier['payment_terms'] === '60_dias' ? 'selected' : ''; ?>>60 Días</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="credit_limit" class="form-label fw-bold">Límite de Crédito</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-dollar-sign text-muted"></i>
                                            </span>
                                            <input type="number" step="0.01" class="form-control border-start-0 ps-0" id="credit_limit" name="credit_limit" 
                                                   value="<?php echo $supplier['credit_limit']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="discount_percentage" class="form-label fw-bold">Descuento (%)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-percent text-muted"></i>
                                            </span>
                                            <input type="number" step="0.01" class="form-control border-start-0 ps-0" id="discount_percentage" name="discount_percentage" 
                                                   value="<?php echo $supplier['discount_percentage']; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 border-top my-3"></div>
                                    
                                    <div class="col-md-4">
                                        <label for="bank_name" class="form-label fw-bold">Banco</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-university text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="bank_name" name="bank_name" 
                                                   value="<?php echo htmlspecialchars($supplier['bank_name']); ?>" 
                                                   maxlength="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="account_number" class="form-label fw-bold">Número de Cuenta</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-money-check text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="account_number" name="account_number" 
                                                   value="<?php echo htmlspecialchars($supplier['account_number']); ?>" 
                                                   maxlength="50">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="account_type" class="form-label fw-bold">Tipo de Cuenta</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0">
                                                <i class="fas fa-wallet text-muted"></i>
                                            </span>
                                            <select class="form-select border-start-0 ps-0" id="account_type" name="account_type">
                                                <option value="">Seleccionar</option>
                                                <option value="savings" <?php echo $supplier['account_type'] === 'savings' ? 'selected' : ''; ?>>Ahorros</option>
                                                <option value="checking" <?php echo $supplier['account_type'] === 'checking' ? 'selected' : ''; ?>>Corriente</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Estado y Valoración -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-star me-2 text-primary"></i>Estado y Valoración
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               <?php echo $supplier['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="is_active">Proveedor Activo</label>
                                    </div>
                                    <div class="form-text">Desactivar si ya no se realizan compras a este proveedor.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="rating" class="form-label fw-bold">Valoración</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-star text-muted"></i>
                                        </span>
                                        <select class="form-select border-start-0 ps-0" id="rating" name="rating">
                                            <option value="">Sin valoración</option>
                                            <option value="1" <?php echo $supplier['rating'] == 1 ? 'selected' : ''; ?>>1 Estrella - Malo</option>
                                            <option value="2" <?php echo $supplier['rating'] == 2 ? 'selected' : ''; ?>>2 Estrellas - Regular</option>
                                            <option value="3" <?php echo $supplier['rating'] == 3 ? 'selected' : ''; ?>>3 Estrellas - Bueno</option>
                                            <option value="4" <?php echo $supplier['rating'] == 4 ? 'selected' : ''; ?>>4 Estrellas - Muy Bueno</option>
                                            <option value="5" <?php echo $supplier['rating'] == 5 ? 'selected' : ''; ?>>5 Estrellas - Excelente</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-sticky-note me-2 text-primary"></i>Notas
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <textarea class="form-control" id="notes" name="notes" rows="6" 
                                              placeholder="Notas internas sobre el proveedor..."><?php echo htmlspecialchars($supplier['notes']); ?></textarea>
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold">
                                        <i class="fas fa-save me-2"></i>Guardar Cambios
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        // Validación del formulario
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
</body>
</html>
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-dollar-sign me-2"></i>Información Financiera
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="payment_terms" class="form-label">Términos de Pago</label>
                                            <select class="form-select" id="payment_terms" name="payment_terms">
                                                <option value="">Seleccionar</option>
                                                <option value="immediate" <?php echo $supplier['payment_terms'] === 'immediate' ? 'selected' : ''; ?>>Inmediato</option>
                                                <option value="15_days" <?php echo $supplier['payment_terms'] === '15_days' ? 'selected' : ''; ?>>15 días</option>
                                                <option value="30_days" <?php echo $supplier['payment_terms'] === '30_days' ? 'selected' : ''; ?>>30 días</option>
                                                <option value="45_days" <?php echo $supplier['payment_terms'] === '45_days' ? 'selected' : ''; ?>>45 días</option>
                                                <option value="60_days" <?php echo $supplier['payment_terms'] === '60_days' ? 'selected' : ''; ?>>60 días</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="credit_limit" class="form-label">Límite de Crédito</label>
                                            <input type="number" class="form-control" id="credit_limit" name="credit_limit" 
                                                   value="<?php echo $supplier['credit_limit']; ?>" 
                                                   min="0" step="0.01">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="discount_percentage" class="form-label">Descuento (%)</label>
                                            <input type="number" class="form-control" id="discount_percentage" name="discount_percentage" 
                                                   value="<?php echo $supplier['discount_percentage']; ?>" 
                                                   min="0" max="100" step="0.01">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="rating" class="form-label">Calificación</label>
                                            <select class="form-select" id="rating" name="rating">
                                                <option value="">Seleccionar</option>
                                                <option value="excellent" <?php echo $supplier['rating'] === 'excellent' ? 'selected' : ''; ?>>Excelente</option>
                                                <option value="good" <?php echo $supplier['rating'] === 'good' ? 'selected' : ''; ?>>Bueno</option>
                                                <option value="regular" <?php echo $supplier['rating'] === 'regular' ? 'selected' : ''; ?>>Regular</option>
                                                <option value="poor" <?php echo $supplier['rating'] === 'poor' ? 'selected' : ''; ?>>Malo</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="bank_name" class="form-label">Banco</label>
                                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                                   value="<?php echo htmlspecialchars($supplier['bank_name']); ?>" 
                                                   maxlength="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="account_type" class="form-label">Tipo de Cuenta</label>
                                            <select class="form-select" id="account_type" name="account_type">
                                                <option value="">Seleccionar</option>
                                                <option value="savings" <?php echo $supplier['account_type'] === 'savings' ? 'selected' : ''; ?>>Ahorros</option>
                                                <option value="checking" <?php echo $supplier['account_type'] === 'checking' ? 'selected' : ''; ?>>Corriente</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="account_number" class="form-label">Número de Cuenta</label>
                                            <input type="text" class="form-control" id="account_number" name="account_number" 
                                                   value="<?php echo htmlspecialchars($supplier['account_number']); ?>" 
                                                   maxlength="50">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Estado y Notas -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-cog me-2"></i>Estado y Configuración
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               <?php echo $supplier['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Proveedor Activo
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notas</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              maxlength="1000"><?php echo htmlspecialchars($supplier['notes']); ?></textarea>
                                    <div class="form-text">Información adicional sobre el proveedor</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Información del Sistema -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info me-2"></i>Información del Sistema
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="mb-2"><strong>ID:</strong> <?php echo $supplier['id']; ?></p>
                                <p class="mb-2"><strong>Creado:</strong> <?php echo date('d/m/Y H:i', strtotime($supplier['created_at'])); ?></p>
                                <p class="mb-0"><strong>Actualizado:</strong> <?php echo date('d/m/Y H:i', strtotime($supplier['updated_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm">
                        <i class="fas fa-save me-2"></i>Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
