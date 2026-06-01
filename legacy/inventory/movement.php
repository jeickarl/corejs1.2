<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
$pdo = db();

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantInventoryMovements = hasTenantColumnCached($pdo, 'inventory_movements');
$hasTenantBrands = hasTenantColumnCached($pdo, 'brands');

// Verificar permisos (política consistente)
if (!hasRole(['admin', 'inventory'])) {
    header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren permisos de inventario.'));
    exit();
}

$mensaje = '';
$tipo_mensaje = '';
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Obtener producto preseleccionado
$preselected_product_id = intval($_GET['product_id'] ?? 0);

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Error de seguridad: Token inválido o expirado. Por favor, intente de nuevo.');
        }
        $product_id = intval($_POST['product_id']);
        $movement_type = $_POST['movement_type'];
        $movement_subtype = $_POST['movement_subtype'];
        $quantity = floatval($_POST['quantity']);
        $unit_cost = parseCurrency($_POST['unit_cost']);
        $reason = trim($_POST['reason']);
        $reference_type = $_POST['reference_type'] ?: null;
        $reference_id = $_POST['reference_id'] ?: null;
        $reference_number = trim($_POST['reference_number']) ?: null;
        
        // Validaciones
        if (empty($product_id)) {
            throw new Exception('Debe seleccionar un producto');
        }
        
        if ($quantity <= 0) {
            throw new Exception('La cantidad debe ser mayor a 0');
        }
        
        if ($unit_cost < 0) {
            throw new Exception('El costo unitario no puede ser negativo');
        }
        
        if (empty($reason)) {
            throw new Exception('El motivo es obligatorio');
        }
        
        // Obtener información del producto
        $sql = "SELECT * FROM inventory_products WHERE id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('Producto no encontrado');
        }
        
        $stock_before = $product['current_stock'];
        $total_cost = $quantity * $unit_cost;
        
        // Calcular nuevo stock
        if ($movement_type === 'entry') {
            $stock_after = $stock_before + $quantity;
        } elseif ($movement_type === 'exit') {
            $stock_after = $stock_before - $quantity;
            
            // Verificar stock suficiente (excepto para ajustes)
            if ($movement_subtype !== 'adjustment_decrease' && $stock_after < 0) {
                throw new Exception("Stock insuficiente. Disponible: {$stock_before}, solicitado: {$quantity}");
            }
        } else { // adjustment
            if ($movement_subtype === 'adjustment_increase') {
                $stock_after = $stock_before + $quantity;
            } else {
                $stock_after = $stock_before - $quantity;
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Insertar movimiento
            if ($hasTenantInventoryMovements) {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_movements (
                        tenant_id, product_id, movement_type, movement_subtype, quantity, unit_cost, total_cost,
                        stock_before, stock_after, reference_type, reference_id, reference_number,
                        reason, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $tenantValue, $product_id, $movement_type, $movement_subtype, $quantity, $unit_cost, $total_cost,
                    $stock_before, $stock_after, $reference_type, $reference_id, $reference_number,
                    $reason, $_SESSION['user_id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_movements (
                        product_id, movement_type, movement_subtype, quantity, unit_cost, total_cost,
                        stock_before, stock_after, reference_type, reference_id, reference_number,
                        reason, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $product_id, $movement_type, $movement_subtype, $quantity, $unit_cost, $total_cost,
                    $stock_before, $stock_after, $reference_type, $reference_id, $reference_number,
                    $reason, $_SESSION['user_id']
                ]);
            }
            
            // Actualizar stock del producto
            $stmt = $pdo->prepare("
                UPDATE inventory_products 
                SET current_stock = ?, 
                    purchase_price = CASE 
                        WHEN ? > 0 AND (? = 'entry' OR ? = 'adjustment_increase') 
                        THEN ? 
                        ELSE purchase_price 
                    END
                WHERE id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ");
            $params = [$stock_after, $unit_cost, $movement_type, $movement_type, $unit_cost, $product_id];
            if ($hasTenantInventoryProducts && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            $pdo->commit();
            
            // Registrar actividad
            logActivity($_SESSION['user_id'], 'CREATE_INVENTORY_MOVEMENT', 'inventory_movements', $pdo->lastInsertId());
            
            $mensaje = 'Movimiento registrado exitosamente';
            $tipo_mensaje = 'success';
            
            // Limpiar formulario
            $_POST = [];
            $preselected_product_id = 0;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Obtener productos
$products = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.*, pc.name as category_name, b.name as brand_name
        FROM inventory_products ip
        LEFT JOIN product_categories pc ON ip.category_id = pc.id
        LEFT JOIN brands b ON ip.brand_id = b.id" . (($hasTenantBrands && $hasTenantInventoryProducts && !$perDatabase) ? " AND b.tenant_id = ip.tenant_id" : "") . "
        WHERE ip.is_active = 1" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND ip.tenant_id = ?" : "") . "
        ORDER BY ip.name ASC
    ");
    $stmt->execute(($hasTenantInventoryProducts && !$perDatabase) ? [$tenantValue] : []);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_inventory_styles.php'; ?>

<div class="inventory-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-exchange-alt me-2 text-primary no-theme"></i>Movimiento de Inventario</h2>
            <p class="text-muted mb-0">Registrar entradas, salidas o ajustes de stock</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
            <?php include __DIR__ . '/_subnav.php'; ?>
        </div>
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
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-plus-circle me-2 text-primary"></i>Nuevo Movimiento
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" id="movementForm" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="product_id" class="form-label fw-bold">
                                        Producto <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-box text-muted"></i>
                                        </span>
                                        <select class="form-select border-start-0 ps-0" id="product_id" name="product_id" required>
                                            <option value="">Seleccionar producto</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['id']; ?>" 
                                                        data-stock="<?php echo $product['current_stock']; ?>"
                                                        data-price="<?php echo $product['purchase_price']; ?>"
                                                        <?php echo ($preselected_product_id == $product['id'] || ($_POST['product_id'] ?? '') == $product['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($product['name']); ?> 
                                                    (Stock: <?php echo $product['current_stock']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="movement_type" class="form-label fw-bold">
                                        Tipo de Movimiento <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-exchange-alt text-muted"></i>
                                        </span>
                                        <select class="form-select border-start-0 ps-0" id="movement_type" name="movement_type" required>
                                            <option value="">Seleccionar tipo</option>
                                            <option value="entry" <?php echo ($_POST['movement_type'] ?? '') === 'entry' ? 'selected' : ''; ?>>Entrada</option>
                                            <option value="exit" <?php echo ($_POST['movement_type'] ?? '') === 'exit' ? 'selected' : ''; ?>>Salida</option>
                                            <option value="adjustment" <?php echo ($_POST['movement_type'] ?? '') === 'adjustment' ? 'selected' : ''; ?>>Ajuste</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="movement_subtype" class="form-label fw-bold">
                                        Subtipo <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-tag text-muted"></i>
                                        </span>
                                        <select class="form-select border-start-0 ps-0" id="movement_subtype" name="movement_subtype" required>
                                            <option value="">Seleccionar subtipo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="quantity" class="form-label fw-bold">
                                        Cantidad <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-hashtag text-muted"></i>
                                        </span>
                                        <input type="number" class="form-control border-start-0 ps-0" id="quantity" name="quantity" 
                                               value="<?php echo $_POST['quantity'] ?? ''; ?>" 
                                               min="0.01" step="0.01" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="unit_cost" class="form-label fw-bold">Costo Unitario</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><script>document.write(window.SYSTEM_CONFIG?.currency?.symbol || '$');</script></span>
                                        <input type="text" class="form-control money-input border-start-0 ps-0" id="unit_cost" name="unit_cost" 
                                               value="<?php echo $_POST['unit_cost'] ?? '0'; ?>" 
                                               placeholder="0" oninput="formatCurrencyInput(this)">
                                    </div>
                                    <div class="form-text">Costo por unidad (opcional)</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="reference_number" class="form-label fw-bold">Número de Referencia</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">
                                            <i class="fas fa-file-alt text-muted"></i>
                                        </span>
                                        <input type="text" class="form-control border-start-0 ps-0" id="reference_number" name="reference_number" 
                                               value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>" 
                                               maxlength="50">
                                    </div>
                                    <div class="form-text">Ej: OC-001, Fact-123, etc.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label fw-bold">
                                Motivo <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">
                                    <i class="fas fa-comment-alt text-muted"></i>
                                </span>
                                <textarea class="form-control border-start-0 ps-0" id="reason" name="reason" rows="3" required><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-text">Describe el motivo del movimiento</div>
                        </div>
                        
                        <!-- Campos ocultos para referencia -->
                        <input type="hidden" name="reference_type" value="">
                        <input type="hidden" name="reference_id" value="">
                        
                        <div class="d-flex justify-content-end gap-2 pt-3">
                            <a href="index.php" class="btn btn-light rounded-pill">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary rounded-pill px-4">
                                <i class="fas fa-save me-2"></i>Registrar Movimiento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Información del Producto -->
            <div class="card mb-4 border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-info-circle me-2 text-info"></i>Información del Producto
                    </h5>
                </div>
                <div class="card-body p-4" id="productInfo">
                    <div class="text-center text-muted py-3">
                        <div class="rounded-circle bg-light p-3 d-inline-block mb-3">
                            <i class="fas fa-box fa-2x"></i>
                        </div>
                        <p class="mb-0">Selecciona un producto para ver su información</p>
                    </div>
                </div>
            </div>
            
            <!-- Información -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Ayuda
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info border-0 rounded-3 mb-3">
                        <h6 class="fw-bold"><i class="fas fa-info-circle me-2"></i>Tipos de Movimiento:</h6>
                        <ul class="mb-0 ps-3 small">
                            <li><strong>Entrada:</strong> Aumenta el stock</li>
                            <li><strong>Salida:</strong> Disminuye el stock</li>
                            <li><strong>Ajuste:</strong> Corrige diferencias</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning border-0 rounded-3 mb-0">
                        <h6 class="fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
                        <ul class="mb-0 ps-3 small">
                            <li>Los <strong>ajustes</strong> pueden crear stock negativo</li>
                            <li>El <strong>costo unitario</strong> actualiza el precio de compra</li>
                            <li>Todos los movimientos quedan <strong>registrados</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Opciones de subtipo según el tipo de movimiento
const movementSubtypes = {
    'entry': [
        { value: 'purchase', text: 'Compra' },
        { value: 'adjustment_increase', text: 'Ajuste por Incremento' }
    ],
    'exit': [
        { value: 'sale', text: 'Venta' },
        { value: 'consumption', text: 'Consumo' },
        { value: 'adjustment_decrease', text: 'Ajuste por Decremento' }
    ],
    'adjustment': [
        { value: 'adjustment_increase', text: 'Incremento' },
        { value: 'adjustment_decrease', text: 'Decremento' }
    ]
};

// Actualizar subtipos cuando cambia el tipo
document.getElementById('movement_type').addEventListener('change', function() {
    const movementType = this.value;
    const subtypeSelect = document.getElementById('movement_subtype');
    
    // Limpiar opciones
    subtypeSelect.innerHTML = '<option value="">Seleccionar subtipo</option>';
    
    // Agregar opciones según el tipo
    if (movementType && movementSubtypes[movementType]) {
        movementSubtypes[movementType].forEach(subtype => {
            const option = document.createElement('option');
            option.value = subtype.value;
            option.textContent = subtype.text;
            subtypeSelect.appendChild(option);
        });
    }
});

// Mostrar información del producto seleccionado
document.getElementById('product_id').addEventListener('change', function() {
    const productId = this.value;
    const productInfo = document.getElementById('productInfo');
    
    if (!productId) {
        productInfo.innerHTML = `
            <div class="text-center text-muted">
                <i class="fas fa-box fa-2x mb-3"></i>
                <p>Selecciona un producto para ver su información</p>
            </div>
        `;
        return;
    }
    
    const selectedOption = this.options[this.selectedIndex];
    const stock = selectedOption.dataset.stock;
    const price = selectedOption.dataset.price;
    
    productInfo.innerHTML = `
        <table class="table table-borderless table-sm">
            <tr>
                <td><strong>Stock Actual:</strong></td>
                <td><span class="badge bg-primary">${stock}</span></td>
            </tr>
            <tr>
                <td><strong>Precio Compra:</strong></td>
                <td>$${parseFloat(price).toLocaleString()}</td>
            </tr>
            <tr>
                <td><strong>Valor en Stock:</strong></td>
                <td>$${(parseFloat(stock) * parseFloat(price)).toLocaleString()}</td>
            </tr>
        </table>
    `;
    
    // Actualizar costo unitario con el precio de compra
    const unitCostInput = document.getElementById('unit_cost');
    // Usar entero, sin decimales
    unitCostInput.value = Math.round(parseFloat(price)).toString();
    // Formatear con comas
    if (typeof formatCurrencyInput === 'function') {
        formatCurrencyInput(unitCostInput);
    }
});

// Validación antes de enviar
document.getElementById('movementForm').addEventListener('submit', function(e) {
    const movementType = document.getElementById('movement_type').value;
    const quantity = parseFloat(document.getElementById('quantity').value);
    const productId = document.getElementById('product_id').value;
    
    if (!productId) {
        e.preventDefault();
        alert('Debe seleccionar un producto');
        return false;
    }
    
    if (!movementType) {
        e.preventDefault();
        alert('Debe seleccionar un tipo de movimiento');
        return false;
    }
    
    // Verificar stock para salidas
    if (movementType === 'exit') {
        const selectedOption = document.getElementById('product_id').options[document.getElementById('product_id').selectedIndex];
        const currentStock = parseFloat(selectedOption.dataset.stock);
        
        if (quantity > currentStock) {
            const confirmMessage = `Stock insuficiente. Disponible: ${currentStock}, solicitado: ${quantity}. ¿Desea continuar?`;
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        }
    }
});

// Establecer subtipo inicial si hay datos del POST
<?php if (!empty($_POST['movement_type'])): ?>
document.getElementById('movement_type').dispatchEvent(new Event('change'));
document.getElementById('movement_subtype').value = '<?php echo $_POST['movement_subtype'] ?? ''; ?>';
<?php endif; ?>
</script>

</div>
</div>
</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
