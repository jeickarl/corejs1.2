<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
$pdo = db();

requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantProductCategories = hasTenantColumnCached($pdo, 'product_categories');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');

// Verificar permisos (política consistente)
if (!hasRole(['admin', 'inventory'])) {
    header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren permisos de inventario.'));
    exit();
}

$errors = [];

// Obtener categorías
try {
    $sql = "SELECT id, name FROM product_categories" . (($hasTenantProductCategories && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantProductCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Obtener marcas
try {
    $sql = "SELECT id, name FROM brands" . (($hasTenantBrands && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$tenantValue] : []);
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $brands = [];
}

// Obtener proveedores
try {
    $sql = "SELECT id, company_name FROM suppliers WHERE is_active = 1" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY company_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Error de seguridad: Token inválido o expirado. Por favor, intente de nuevo.';
    } else {
        // Validar campos requeridos
        $internal_code = trim($_POST['internal_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $category_id = $_POST['category_id'] ?? '';
    $brand_id = $_POST['brand_id'] ?? '';
    $supplier_id = $_POST['supplier_id'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $purchase_price = parseCurrency($_POST['purchase_price'] ?? 0);
    $sale_price = parseCurrency($_POST['sale_price'] ?? 0);
    $current_stock = intval($_POST['current_stock'] ?? 0);
    $min_stock = intval($_POST['min_stock'] ?? 0);
    $max_stock = intval($_POST['max_stock'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $unit_type = $_POST['unit_type'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');

    // Validaciones
    if (empty($internal_code)) {
        $errors[] = 'El código interno es obligatorio.';
    }
    
    if (empty($name)) {
        $errors[] = 'El nombre del producto es obligatorio.';
    }
    
    if (empty($category_id)) {
        $errors[] = 'La categoría es obligatoria.';
    }
    
    if ($sale_price <= 0) {
        $errors[] = 'El precio de venta debe ser mayor a 0.';
    }
    
    if ($current_stock < 0) {
        $errors[] = 'El stock actual no puede ser negativo.';
    }
    
    if ($min_stock < 0) {
        $errors[] = 'El stock mínimo no puede ser negativo.';
    }
    
    if (!empty($internal_code)) {
        // Verificar que el código no esté duplicado
        try {
            $sql = "SELECT id FROM inventory_products WHERE internal_code = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$internal_code, $tenantValue] : [$internal_code]);
            if ($stmt->fetch()) {
                $errors[] = 'Ya existe un producto con este código interno.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar el código: ' . $e->getMessage();
        }
    }
    
    // Si no hay errores, crear el producto
    if (empty($errors)) {
        try {
            if ($hasTenantInventoryProducts) {
                $sql = "INSERT INTO inventory_products (
                        tenant_id, internal_code, name, category_id, brand_id, supplier_id, 
                        description, purchase_price, sale_price, current_stock, 
                        min_stock, max_stock, location, unit_type, is_active, 
                        notes, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $tenantValue,
                    $internal_code, $name, $category_id ?: null, $brand_id ?: null, $supplier_id ?: null,
                    $description ?: null, $purchase_price, $sale_price, $current_stock,
                    $min_stock, $max_stock, $location ?: null, $unit_type ?: null, $is_active,
                    $notes ?: null, $_SESSION['user_id']
                ]);
            } else {
                $sql = "INSERT INTO inventory_products (
                        internal_code, name, category_id, brand_id, supplier_id, 
                        description, purchase_price, sale_price, current_stock, 
                        min_stock, max_stock, location, unit_type, is_active, 
                        notes, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $internal_code, $name, $category_id ?: null, $brand_id ?: null, $supplier_id ?: null,
                    $description ?: null, $purchase_price, $sale_price, $current_stock,
                    $min_stock, $max_stock, $location ?: null, $unit_type ?: null, $is_active,
                    $notes ?: null, $_SESSION['user_id']
                ]);
            }
            
            $product_id = $pdo->lastInsertId();
            
            // Registrar actividad
            try {
                $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    'CREATE',
                    'inventory_products',
                    $product_id,
                    'Producto creado: ' . $name
                ]);
            } catch (PDOException $e) {
                // Log error but don't stop the process
            }
            
            header('Location: view.php?id=' . $product_id . '&success=' . urlencode('Producto creado exitosamente.'));
            exit();
        } catch (PDOException $e) {
            $errors[] = 'Error al crear el producto: ' . $e->getMessage();
        }
    }
    } // Cierre del bloque else de validación CSRF
}

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();

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
$page_title = 'Nuevo Producto';
ob_start();
?>

<?php include __DIR__ . '/_inventory_styles.php'; ?>

<div class="inventory-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="card card-modern border-0 shadow-sm overflow-hidden">
            <div class="card-body p-4">
                <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 gap-3 flex-wrap">
                    <div>
                        <h4 class="fw-bold text-dark mb-1"><i class="fas fa-plus-circle me-2 text-primary no-theme"></i>Nuevo Producto</h4>
                        <div class="text-muted small">Agregar un nuevo producto al inventario</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                            <i class="fas fa-arrow-left me-2"></i>Volver
                        </a>
                        <?php include __DIR__ . '/_subnav.php'; ?>
                    </div>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
                        <?php if ($tipo_mensaje === 'success'): ?>
                            <i class="fas fa-check-circle me-2 fa-lg"></i>
                        <?php elseif ($tipo_mensaje === 'danger'): ?>
                            <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
                        <?php else: ?>
                            <i class="fas fa-info-circle me-2 fa-lg"></i>
                        <?php endif; ?>
                        <div class="flex-grow-1"><?php echo htmlspecialchars($mensaje); ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                            <h6 class="mb-0">Por favor corrige los siguientes errores:</h6>
                        </div>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <!-- Información Básica -->
                        <div class="card card-modern border-0 shadow-sm overflow-hidden mb-4">
                            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Información Básica</h5>
                            </div>
                            <div class="card-body p-4 pt-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="internal_code" class="form-label fw-bold">
                                                Código Interno <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-barcode text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control border-start-0 ps-0" id="internal_code" name="internal_code" 
                                                       value="<?php echo htmlspecialchars($_POST['internal_code'] ?? ''); ?>" 
                                                       required maxlength="50">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="category_id" class="form-label fw-bold">
                                                Categoría <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-tags text-muted"></i>
                                                </span>
                                                <select class="form-select border-start-0 ps-0" id="category_id" name="category_id" required>
                                                    <option value="">Seleccionar categoría</option>
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?php echo $category['id']; ?>" 
                                                                <?php echo ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($category['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="name" class="form-label fw-bold">
                                        Nombre del Producto <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-box text-muted"></i>
                                        </span>
                                        <input type="text" class="form-control border-start-0 ps-0" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                               required maxlength="255">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="brand_id" class="form-label fw-bold">Marca</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-copyright text-muted"></i>
                                                </span>
                                                <select class="form-select border-start-0 ps-0" id="brand_id" name="brand_id">
                                                    <option value="">Seleccionar marca</option>
                                                    <?php foreach ($brands as $brand): ?>
                                                        <option value="<?php echo $brand['id']; ?>" 
                                                                <?php echo ($_POST['brand_id'] ?? '') == $brand['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($brand['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="supplier_id" class="form-label fw-bold">Proveedor</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-truck text-muted"></i>
                                                </span>
                                                <select class="form-select border-start-0 ps-0" id="supplier_id" name="supplier_id">
                                                    <option value="">Seleccionar proveedor</option>
                                                    <?php foreach ($suppliers as $supplier): ?>
                                                        <option value="<?php echo $supplier['id']; ?>" 
                                                                <?php echo ($_POST['supplier_id'] ?? '') == $supplier['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($supplier['company_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label fw-bold">Descripción</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-align-left text-muted"></i>
                                        </span>
                                        <textarea class="form-control border-start-0 ps-0" id="description" name="description" rows="3" 
                                                  maxlength="500"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Información de Inventario -->
                        <div class="card card-modern border-0 shadow-sm overflow-hidden mb-4">
                            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-boxes me-2 text-primary no-theme"></i>Información de Inventario</h5>
                            </div>
                            <div class="card-body p-4 pt-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="purchase_price" class="form-label fw-bold">Precio de Compra</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <script>document.write(window.SYSTEM_CONFIG?.currency?.symbol || '$');</script>
                                                </span>
                                                <input type="text" class="form-control money-input border-start-0 ps-0" id="purchase_price" name="purchase_price" 
                                                       value="<?php echo $_POST['purchase_price'] ?? '0'; ?>" 
                                                       oninput="formatCurrencyInput(this)">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="sale_price" class="form-label fw-bold">
                                                Precio de Venta <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <script>document.write(window.SYSTEM_CONFIG?.currency?.symbol || '$');</script>
                                                </span>
                                                <input type="text" class="form-control money-input border-start-0 ps-0" id="sale_price" name="sale_price" 
                                                       value="<?php echo $_POST['sale_price'] ?? ''; ?>" 
                                                       required oninput="formatCurrencyInput(this)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="current_stock" class="form-label fw-bold">Stock Inicial</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-cubes text-muted"></i>
                                                </span>
                                                <input type="number" class="form-control border-start-0 ps-0" id="current_stock" name="current_stock" 
                                                       value="<?php echo $_POST['current_stock'] ?? '0'; ?>" 
                                                       min="0">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="min_stock" class="form-label fw-bold">Stock Mínimo</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-arrow-down text-muted"></i>
                                                </span>
                                                <input type="number" class="form-control border-start-0 ps-0" id="min_stock" name="min_stock" 
                                                       value="<?php echo $_POST['min_stock'] ?? '5'; ?>" 
                                                       min="0">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="max_stock" class="form-label fw-bold">Stock Máximo</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-arrow-up text-muted"></i>
                                                </span>
                                                <input type="number" class="form-control border-start-0 ps-0" id="max_stock" name="max_stock" 
                                                       value="<?php echo $_POST['max_stock'] ?? '100'; ?>" 
                                                       min="0">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="unit_type" class="form-label fw-bold">Tipo de Unidad</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-ruler text-muted"></i>
                                                </span>
                                                <select class="form-select border-start-0 ps-0" id="unit_type" name="unit_type">
                                                    <option value="unidad" <?php echo ($_POST['unit_type'] ?? '') == 'unidad' ? 'selected' : ''; ?>>Unidad</option>
                                                    <option value="kg" <?php echo ($_POST['unit_type'] ?? '') == 'kg' ? 'selected' : ''; ?>>Kilogramo</option>
                                                    <option value="litro" <?php echo ($_POST['unit_type'] ?? '') == 'litro' ? 'selected' : ''; ?>>Litro</option>
                                                    <option value="metro" <?php echo ($_POST['unit_type'] ?? '') == 'metro' ? 'selected' : ''; ?>>Metro</option>
                                                    <option value="paquete" <?php echo ($_POST['unit_type'] ?? '') == 'paquete' ? 'selected' : ''; ?>>Paquete</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="location" class="form-label fw-bold">Ubicación en Almacén</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="fas fa-map-marker-alt text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control border-start-0 ps-0" id="location" name="location" 
                                                       value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" 
                                                       maxlength="100">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Estado y Notas -->
                        <div class="card card-modern border-0 shadow-sm overflow-hidden mb-4">
                            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-sliders-h me-2 text-primary no-theme"></i>Estado y Notas</h5>
                            </div>
                            <div class="card-body p-4 pt-3">
                                <div class="mb-3">
                                    <label class="form-label fw-bold d-block">Estado</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               <?php echo (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Producto Activo</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label fw-bold">Notas Adicionales</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-sticky-note text-muted"></i>
                                        </span>
                                        <textarea class="form-control border-start-0 ps-0" id="notes" name="notes" rows="4" 
                                                  maxlength="1000"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                
                                <hr class="my-4">
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow-sm">
                                        <i class="fas fa-save me-2"></i>Guardar Producto
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
</div>

<script>
        // Form validation
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

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
