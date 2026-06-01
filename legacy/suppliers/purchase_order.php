<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

// Verificar permisos
if (!hasRole(['admin', 'inventory'])) {
    header('Location: ../dashboard.php');
    exit();
}

SecurityEnhancements::setSecurityHeaders();

$mensaje = '';
$tipo_mensaje = '';

// Obtener proveedor preseleccionado
$preselected_supplier_id = intval(SecurityEnhancements::sanitizeInput($_GET['supplier_id'] ?? 0, 'int'));
// Tenant actual
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantPurchaseOrders = hasTenantColumnCached($pdo, 'purchase_orders');
$hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF
        if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
            SecurityEnhancements::logSecurityEvent('CSRF_VERIFICATION_FAILED', [
                'page' => 'suppliers/purchase_order.php',
                'action' => 'create_purchase_order'
            ], $_SESSION['user_id'] ?? null);
            throw new Exception('Token CSRF inválido o expirado');
        }

        // Saneo de inputs
        $supplier_id = intval(SecurityEnhancements::sanitizeInput($_POST['supplier_id'] ?? '', 'int'));
        $order_date = SecurityEnhancements::sanitizeInput($_POST['order_date'] ?? '', 'string');
        $expected_date = isset($_POST['expected_date']) && $_POST['expected_date'] !== '' 
            ? SecurityEnhancements::sanitizeInput($_POST['expected_date'], 'string') 
            : null;
        $payment_method = isset($_POST['payment_method']) && $_POST['payment_method'] !== '' 
            ? SecurityEnhancements::sanitizeInput($_POST['payment_method'], 'string') 
            : null;
        $payment_terms = isset($_POST['payment_terms']) && $_POST['payment_terms'] !== '' 
            ? SecurityEnhancements::sanitizeInput($_POST['payment_terms'], 'string') 
            : null;
        $notes = SecurityEnhancements::sanitizeInput($_POST['notes'] ?? '', 'string');
        
        // Validaciones
        if (empty($supplier_id)) {
            throw new Exception('Debe seleccionar un proveedor');
        }
        
        if (empty($order_date)) {
            throw new Exception('La fecha de la orden es obligatoria');
        }

        // Validar proveedor existe y activo del tenant
        $sql = "SELECT id FROM suppliers WHERE id = ? AND is_active = TRUE" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_id, $tenantValue] : [$supplier_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Proveedor no válido o inactivo');
        }

        // Validar formato de fechas
        $orderDateObj = DateTime::createFromFormat('Y-m-d', $order_date);
        if (!$orderDateObj || $orderDateObj->format('Y-m-d') !== $order_date) {
            throw new Exception('Formato de fecha de orden inválido');
        }
        $todayObj = new DateTime('today');
        if ($orderDateObj < $todayObj) {
            throw new Exception('La fecha de la orden no puede ser anterior a hoy');
        }

        if (!is_null($expected_date)) {
            $expectedDateObj = DateTime::createFromFormat('Y-m-d', $expected_date);
            if (!$expectedDateObj || $expectedDateObj->format('Y-m-d') !== $expected_date) {
                throw new Exception('Formato de fecha esperada inválido');
            }
            if ($expectedDateObj < $orderDateObj) {
                throw new Exception('La fecha esperada debe ser posterior o igual a la fecha de orden');
            }
        }

        // Validar método de pago si se especifica
        if (!is_null($payment_method)) {
            $stmt = $pdo->prepare("SELECT name FROM payment_methods WHERE is_active = 1 AND name = ?");
            $stmt->execute([$payment_method]);
            $valid_method = $stmt->fetchColumn();
            $fallback_methods = ['efectivo', 'transferencia', 'tarjeta'];
            if (!$valid_method && !in_array(strtolower($payment_method), $fallback_methods)) {
                throw new Exception('Método de pago inválido');
            }
        }
        
        // Validar términos de pago y aplicar prellenado desde proveedor si no se envió
        $allowed_terms = ['immediate','15_days','30_days','45_days','60_days','90_days'];
        if (is_null($payment_terms)) {
            $sql = "SELECT payment_terms FROM suppliers WHERE id = ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_id, $tenantValue] : [$supplier_id]);
            $payment_terms = $stmt->fetchColumn() ?: '30_days';
        }
        if (!in_array($payment_terms, $allowed_terms)) {
            throw new Exception('Términos de pago inválidos');
        }
        
        // Insertar orden de compra (po_number será generado por trigger)
        if ($hasTenantPurchaseOrders) {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    tenant_id, supplier_id, order_date, expected_date,
                    payment_method, payment_terms, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $tenantValue,
                $supplier_id, $order_date, $expected_date,
                $payment_method, $payment_terms, $notes, $_SESSION['user_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    supplier_id, order_date, expected_date,
                    payment_method, payment_terms, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $supplier_id, $order_date, $expected_date,
                $payment_method, $payment_terms, $notes, $_SESSION['user_id']
            ]);
        }
        
        $order_id = $pdo->lastInsertId();

        // Obtener número de PO generado
        $stmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $po_number = $stmt->fetchColumn();
        
        // Registrar actividad
        logActivity($_SESSION['user_id'], 'CREATE_PURCHASE_ORDER', 'purchase_orders', $order_id);
        
        $mensaje = 'Orden de compra creada exitosamente' . ($po_number ? ' (' . $po_number . ')' : '');
        $tipo_mensaje = 'success';
        
        // Redirigir a la orden creada
        header("Location: purchase_order.php?id=$order_id");
        exit();
        
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Obtener proveedores con términos de pago
$suppliers = [];
try {
    $sql = "SELECT id, company_name, payment_terms FROM suppliers WHERE is_active = TRUE" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY company_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener proveedores: " . $e->getMessage());
}

// Obtener métodos de pago
$payment_methods = [];
try {
    $sql = "SELECT name FROM payment_methods WHERE is_active = 1" . (($hasTenantPaymentMethods && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPaymentMethods && !$perDatabase) ? [$tenantValue] : []);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error al obtener métodos de pago: " . $e->getMessage());
}

// Iniciar buffer de salida
$csrf_token = SecurityEnhancements::generateCSRFToken();
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>
<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-shopping-cart me-2"></i>Nueva Orden de Compra</h1>
            <p class="text-muted mb-0">Crear una nueva orden de compra</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Proveedores
        </a>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center" role="alert">
            <?php if ($tipo_mensaje === 'success'): ?>
                <i class="fas fa-check-circle me-2 fa-lg"></i>
            <?php elseif ($tipo_mensaje === 'danger'): ?>
                <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
            <?php else: ?>
                <i class="fas fa-info-circle me-2 fa-lg"></i>
            <?php endif; ?>
            <div class="flex-grow-1">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-file-invoice me-2 text-primary"></i>Información de la Orden
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="supplier_id" class="form-label fw-bold">
                                    Proveedor <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-building text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="supplier_id" name="supplier_id" required>
                                        <option value="">Seleccionar proveedor</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" 
                                                    <?php echo ($preselected_supplier_id == $supplier['id'] || ($_POST['supplier_id'] ?? '') == $supplier['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['company_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="order_date" class="form-label fw-bold">
                                    Fecha de Orden <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-calendar text-muted"></i>
                                    </span>
                                    <input type="date" class="form-control border-start-0 ps-0" id="order_date" name="order_date" 
                                           value="<?php echo $_POST['order_date'] ?? date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="expected_date" class="form-label fw-bold">Fecha Esperada</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-calendar-check text-muted"></i>
                                    </span>
                                    <input type="date" class="form-control border-start-0 ps-0" id="expected_date" name="expected_date" 
                                           value="<?php echo $_POST['expected_date'] ?? ''; ?>">
                                </div>
                                <div class="form-text">Fecha estimada de entrega</div>
                            </div>
                            <div class="col-md-6">
                                <label for="payment_method" class="form-label fw-bold">Método de Pago</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-money-bill-wave text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="payment_method" name="payment_method">
                                        <option value="">Seleccionar método</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo htmlspecialchars($method); ?>" 
                                                    <?php echo ($_POST['payment_method'] ?? '') === $method ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($method); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="efectivo" <?php echo ($_POST['payment_method'] ?? '') === 'efectivo' ? 'selected' : ''; ?>>Efectivo</option>
                                        <option value="transferencia" <?php echo ($_POST['payment_method'] ?? '') === 'transferencia' ? 'selected' : ''; ?>>Transferencia</option>
                                        <option value="tarjeta" <?php echo ($_POST['payment_method'] ?? '') === 'tarjeta' ? 'selected' : ''; ?>>Tarjeta</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="payment_terms" class="form-label fw-bold">Términos de Pago</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-clock text-muted"></i>
                                    </span>
                                    <select class="form-select border-start-0 ps-0" id="payment_terms" name="payment_terms">
                                        <option value="">Seleccionar términos</option>
                                        <option value="immediate" <?php echo (($_POST['payment_terms'] ?? '') === 'immediate') ? 'selected' : ''; ?>>Inmediato</option>
                                        <option value="15_days" <?php echo (($_POST['payment_terms'] ?? '') === '15_days') ? 'selected' : ''; ?>>15 días</option>
                                        <option value="30_days" <?php echo (($_POST['payment_terms'] ?? '') === '30_days') ? 'selected' : ''; ?>>30 días</option>
                                        <option value="45_days" <?php echo (($_POST['payment_terms'] ?? '') === '45_days') ? 'selected' : ''; ?>>45 días</option>
                                        <option value="60_days" <?php echo (($_POST['payment_terms'] ?? '') === '60_days') ? 'selected' : ''; ?>>60 días</option>
                                        <option value="90_days" <?php echo (($_POST['payment_terms'] ?? '') === '90_days') ? 'selected' : ''; ?>>90 días</option>
                                    </select>
                                </div>
                                <div class="form-text">Se prellenará según el proveedor seleccionado.</div>
                            </div>
                            
                            <div class="col-12">
                                <label for="notes" class="form-label fw-bold">Notas</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                <div class="form-text">Observaciones adicionales sobre la orden</div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Crear Orden
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Información
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info border-0 bg-info bg-opacity-10 rounded-3 mb-3">
                        <h6 class="fw-bold text-info"><i class="fas fa-lightbulb me-2"></i>Proceso:</h6>
                        <ol class="mb-0 small text-dark">
                            <li>Selecciona el <strong>proveedor</strong></li>
                            <li>Define las <strong>fechas</strong> de orden y entrega</li>
                            <li>Especifica el <strong>método de pago</strong></li>
                            <li>Agrega <strong>productos</strong> a la orden</li>
                            <li>Confirma y <strong>envía</strong> la orden</li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10 rounded-3 mb-3">
                        <h6 class="fw-bold text-warning"><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
                        <ul class="mb-0 small text-dark">
                            <li>El <strong>número de orden</strong> se genera automáticamente</li>
                            <li>Los <strong>productos</strong> se agregan después de crear la orden</li>
                            <li>La orden inicia con estado <strong>Pendiente</strong></li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-success border-0 bg-success bg-opacity-10 rounded-3">
                        <h6 class="fw-bold text-success"><i class="fas fa-check-circle me-2"></i>Campos Obligatorios:</h6>
                        <ul class="mb-0 small text-dark">
                            <li>Proveedor</li>
                            <li>Fecha de orden</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Validación Bootstrap
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

// Establecer fecha mínima como hoy
document.getElementById('order_date').setAttribute('min', new Date().toISOString().split('T')[0]);
document.getElementById('expected_date').setAttribute('min', new Date().toISOString().split('T')[0]);

// Validar que la fecha esperada sea posterior a la fecha de orden
document.getElementById('order_date').addEventListener('change', function() {
    const orderDate = this.value;
    const expectedDateField = document.getElementById('expected_date');
    
    if (orderDate) {
        expectedDateField.setAttribute('min', orderDate);
    }
});

// Auto-llenar términos de pago según proveedor
const supplierSelect = document.getElementById('supplier_id');
const termsSelect = document.getElementById('payment_terms');
const supplierTermsMap = <?php echo json_encode(array_column($suppliers, 'payment_terms', 'id')); ?>;

function setTermsFromSupplier() {
    if (!supplierSelect || !termsSelect) return;
    const sid = supplierSelect.value;
    const term = supplierTermsMap[sid] || '';
    const shouldAutofill = termsSelect.dataset.autofill === 'true';
    if (term && shouldAutofill) {
        termsSelect.value = term;
    }
}

if (termsSelect) {
    termsSelect.dataset.autofill = "<?php echo isset($_POST['payment_terms']) && $_POST['payment_terms'] !== '' ? 'false' : 'true'; ?>";
}

if (supplierSelect) {
    supplierSelect.addEventListener('change', setTermsFromSupplier);
    document.addEventListener('DOMContentLoaded', function() {
        setTermsFromSupplier();
    });
}
</script>

</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
