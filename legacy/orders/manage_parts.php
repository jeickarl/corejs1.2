<?php
require_once '../config/auth.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';
require_once '../includes/inventory_integration.php';
$pdo = db();

require_once '../config/security_enhancements.php';
requireLogin();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if (function_exists('ensureOrderPartsTenant')) { ensureOrderPartsTenant($pdo, $perDatabase ? 1 : $tenant_id); }

$currency_config = CompanySettings::getCurrency();
$currency_symbol = $currency_config['symbol'];

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();
// Mantener compatibilidad con sesiones antiguas si es necesario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

$order_id = (int)($_GET['id'] ?? 0);
if (!$order_id) {
    header('Location: index.php');
    exit;
}

// Get work order details
$sqlOrder = "
    SELECT wo.*, 
           c.client_type, c.first_name, c.company_name,
           dt.name as device_type_name
    FROM work_orders wo
    " . ($perDatabase ? "JOIN clients c ON wo.client_id = c.id" : "JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id") . "
    " . ($perDatabase ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id") . "
    WHERE wo.id = ?" . ($perDatabase ? "" : " AND wo.tenant_id = ?") . "
";
$stmt = $pdo->prepare($sqlOrder);
$stmt->execute($perDatabase ? [$order_id] : [$order_id, $tenant_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php?error=order_not_found');
    exit;
}

// Construct helper fields
$order['client_name'] = ($order['client_type'] === 'company') 
    ? $order['company_name'] 
    : $order['first_name'];

// Fallback for order number if it doesn't exist (using ID padded)
if (!isset($order['order_number'])) {
    $order['order_number'] = str_pad($order['id'], 4, '0', STR_PAD_LEFT);
}

// Prefijo y visualización
$num = (int)(isset($order['order_number']) ? $order['order_number'] : $order['id']);
$prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
$order_display = $prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);

// Fallback for equipment type
if (!isset($order['equipment_type']) && isset($order['device_type_name'])) {
    $order['equipment_type'] = $order['device_type_name'];
}

// Get current order parts
$hasTenantOP = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_parts') : false;
$hasTenantProducts = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'products') : false;
$partsSql = "
    SELECT op.*, p.name as product_name, p.sku, p.current_stock, p.cost_price, p.sale_price
    FROM order_parts op
    JOIN products p ON op.product_id = p.id" . (($hasTenantProducts && !$perDatabase) ? " AND p.tenant_id = op.tenant_id" : "") . "
    WHERE op.order_id = ?" . (($hasTenantOP && !$perDatabase) ? " AND op.tenant_id = ?" : "") . "
    ORDER BY op.id
";
$stmt = $pdo->prepare($partsSql);
$stmt->execute(($hasTenantOP && !$perDatabase) ? [$order_id, $tenant_id] : [$order_id]);
$current_parts = $stmt->fetchAll();

// Get available products
$productsSql = "
    SELECT id, name, sku, current_stock, cost_price, sale_price, minimum_stock
    FROM products 
    WHERE status = 'active'" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
    ORDER BY name
";
$stmt = $pdo->prepare($productsSql);
$stmt->execute(($hasTenantProducts && !$perDatabase) ? [$tenant_id] : []);
$products = $stmt->fetchAll();

$errors = [];
$success = false;
$inventory_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Token de seguridad inválido o expirado';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_parts') {
            $parts = $_POST['parts'] ?? [];
            
            // Validate parts
            $valid_parts = [];
            foreach ($parts as $index => $part) {
                $product_id = (int)($part['product_id'] ?? 0);
                $quantity_used = (float)($part['quantity_used'] ?? 0);
                $unit_cost = isset($part['unit_cost']) ? parseCurrency($part['unit_cost']) : 0;
                $notes = trim($part['notes'] ?? '');
                
                if ($product_id <= 0) {
                    $errors[] = "Producto inválido en la línea " . ($index + 1);
                    continue;
                }
                
                if ($quantity_used <= 0) {
                    $errors[] = "Cantidad inválida en la línea " . ($index + 1);
                    continue;
                }
                
                if ($unit_cost < 0) {
                    $errors[] = "Costo inválido en la línea " . ($index + 1);
                    continue;
                }
                
                $valid_parts[] = [
                    'product_id' => $product_id,
                    'quantity_used' => $quantity_used,
                    'unit_cost' => $unit_cost,
                    'notes' => $notes ?: null
                ];
            }
            
            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    
                    // Get old parts for inventory restoration
                    $old_parts = [];
                    foreach ($current_parts as $part) {
                        $old_parts[] = [
                            'product_id' => $part['product_id'],
                            'quantity_used' => $part['quantity_used']
                        ];
                    }
                    
                    // Delete existing parts
                    if ($hasTenantOP && !$perDatabase) {
                        $stmt = $pdo->prepare("DELETE FROM order_parts WHERE order_id = ? AND tenant_id = ?");
                        $stmt->execute([$order_id, $tenant_id]);
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM order_parts WHERE order_id = ?");
                        $stmt->execute([$order_id]);
                    }
                    
                    // Insert new parts
                    if (!empty($valid_parts)) {
                        if ($hasTenantOP) {
                            $stmt = $pdo->prepare("
                                INSERT INTO order_parts (tenant_id, order_id, product_id, quantity_used, unit_cost, notes)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO order_parts (order_id, product_id, quantity_used, unit_cost, notes)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                        }
                        
                        foreach ($valid_parts as $part) {
                            if ($hasTenantOP) {
                                $stmt->execute([
                                    $perDatabase ? 1 : $tenant_id,
                                    $order_id,
                                    $part['product_id'],
                                    $part['quantity_used'],
                                    $part['unit_cost'],
                                    $part['notes']
                                ]);
                            } else {
                                $stmt->execute([
                                    $order_id,
                                    $part['product_id'],
                                    $part['quantity_used'],
                                    $part['unit_cost'],
                                    $part['notes']
                                ]);
                            }
                        }
                    }
                    
                    $pdo->commit();
                    
                    // Update inventory based on changes
                    $inventory_result = updateInventoryFromOrderChange($order_id, $old_parts, $valid_parts);
                    
                    $success = true;
                    
                    // Refresh current parts
                    $stmt = $pdo->prepare($partsSql);
                    $stmt->execute(($hasTenantOP && !$perDatabase) ? [$order_id, $tenant_id] : [$order_id]);
                    $current_parts = $stmt->fetchAll();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Error al actualizar las partes: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get inventory alerts
$inventory_alerts = getInventoryAlertSummary();

// Configuración de la página para el template
$page_title = 'Gestionar Partes - ' . $order_display;
 // Asumimos que existe o se creará si es necesario

ob_start();
?>

<!-- Header de la página (Estilo moderno) -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">
            <i class="fas fa-tools me-2 text-primary"></i>Gestionar Partes
        </h2>
        <p class="text-muted mb-0"><?= htmlspecialchars($order_display) ?> - <?= htmlspecialchars($order['client_name']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="view.php?id=<?= $order['id'] ?>" class="btn btn-outline-info rounded-pill px-3 shadow-sm">
            <i class="fas fa-eye me-1"></i> Ver Orden
        </a>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
            <i class="fas fa-arrow-left me-1"></i> Volver
        </a>
    </div>
</div>

<div class="row">
    <!-- Order Information -->
    <div class="col-lg-3 mb-4">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-secondary">
                    <i class="fas fa-info-circle me-2"></i>Detalles
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted small text-uppercase fw-bold">Cliente</label>
                    <div class="fw-medium text-dark"><?= htmlspecialchars($order['client_name']) ?></div>
                </div>
                <div class="mb-3">
                    <label class="text-muted small text-uppercase fw-bold">Equipo</label>
                    <div class="fw-medium text-dark"><?= htmlspecialchars($order['equipment_type']) ?></div>
                </div>
                <div class="mb-0">
                    <label class="text-muted small text-uppercase fw-bold">Estado</label>
                    <div>
                        <?php
                            $colorHex = '#6c757d';
                            try {
                                $tenant_id = getCurrentTenantId();
                                $hasTenant = hasTenantColumnCached($pdo, 'order_statuses');
                                if ($hasTenant) {
                                    $stc = $pdo->prepare("SELECT color FROM order_statuses WHERE slug = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
                                    $stc->execute([$order['status'], $tenant_id]);
                                } else {
                                    $stc = $pdo->prepare("SELECT color FROM order_statuses WHERE slug = ? AND is_active = 1 LIMIT 1");
                                    $stc->execute([$order['status']]);
                                }
                                $hex = trim((string)($stc->fetchColumn() ?: ''));
                                if ($hex !== '') { $colorHex = $hex; }
                            } catch (Throwable $e) {}
                        ?>
                        <span class="badge px-3 py-2 rounded-pill" style="background-color: <?= htmlspecialchars($colorHex) ?>; color: #fff;">
                            <?= ucfirst($order['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Inventory Alerts -->
        <?php if ($inventory_alerts['out_of_stock_count'] > 0 || $inventory_alerts['low_stock_count'] > 0): ?>
        <div class="card shadow-sm border-0 mt-3 border-start border-4 border-warning rounded-4">
            <div class="card-body">
                <h6 class="card-title text-warning fw-bold mb-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>Alertas de Stock
                </h6>
                
                <?php if ($inventory_alerts['out_of_stock_count'] > 0): ?>
                    <div class="d-flex align-items-center mb-2 text-danger">
                        <i class="fas fa-times-circle me-2"></i>
                        <strong><?= $inventory_alerts['out_of_stock_count'] ?></strong>&nbsp;sin stock
                    </div>
                <?php endif; ?>
                
                <?php if ($inventory_alerts['low_stock_count'] > 0): ?>
                    <div class="d-flex align-items-center mb-3 text-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong><?= $inventory_alerts['low_stock_count'] ?></strong>&nbsp;stock bajo
                    </div>
                <?php endif; ?>
                
                <div class="d-grid">
                    <a href="../inventory/index.php" class="btn btn-sm btn-outline-warning rounded-pill">
                        Ver Inventario
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Parts Management -->
    <div class="col-lg-9">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="fas fa-boxes me-2"></i>Partes y Repuestos Utilizados
                </h5>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" onclick="addPart()">
                    <i class="fas fa-plus me-1"></i> Agregar Parte
                </button>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger shadow-sm border-start border-4 border-danger fade show rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-exclamation-circle fs-4 me-2"></i>
                            <h6 class="alert-heading mb-0 fw-bold">Errores detectados</h6>
                        </div>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success shadow-sm border-start border-4 border-success fade show rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-check-circle fs-4 me-2"></i>
                            <h6 class="alert-heading mb-0 fw-bold">¡Cambios guardados correctamente!</h6>
                        </div>
                        <?php if ($inventory_result): ?>
                            <hr>
                            <?php if (!empty($inventory_result['messages'])): ?>
                                <div class="small">
                                    <strong>Actualizaciones:</strong>
                                    <ul class="mb-1 ps-3">
                                        <?php foreach ($inventory_result['messages'] as $message): ?>
                                            <li><?= htmlspecialchars($message) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($inventory_result['warnings'])): ?>
                                <div class="small text-warning mt-2">
                                    <strong>Advertencias:</strong>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($inventory_result['warnings'] as $warning): ?>
                                            <li><?= htmlspecialchars($warning) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="partsForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="update_parts">
                    
                    <div class="table-responsive rounded-3 border mb-4">
                        <table class="table table-hover align-middle mb-0" id="partsTable">
                            <thead class="bg-light text-secondary">
                                <tr>
                                    <th class="ps-3" width="35%">Producto / Repuesto</th>
                                    <th class="text-center" width="10%">Stock</th>
                                    <th width="15%">Cantidad</th>
                                    <th width="15%">Costo Unit.</th>
                                    <th width="20%">Notas</th>
                                    <th class="text-center" width="5%"></th>
                                </tr>
                            </thead>
                            <tbody id="partsTableBody" class="bg-white">
                                <!-- Parts will be added here dynamically -->
                            </tbody>
                            <tfoot class="bg-light" id="emptyStateRow" style="display: none;">
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fas fa-box-open fs-1 mb-3 d-block opacity-25"></i>
                                        <p class="mb-0">No hay partes agregadas a esta orden.</p>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none" onclick="addPart()">
                                            Agregar la primera parte
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="view.php?id=<?= $order['id'] ?>" class="btn btn-light border rounded-pill px-3">
                            Cancelar
                        </a>
                        <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm rounded-pill">
                            <i class="fas fa-save me-2"></i> Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let partCounter = 0;
const products = <?= json_encode($products) ?>;
const currentParts = <?= json_encode($current_parts) ?>;
const currencySymbol = "<?= $currency_symbol ?>";

// Check empty state
function checkEmptyState() {
    const tbody = document.getElementById('partsTableBody');
    const emptyRow = document.getElementById('emptyStateRow');
    const hasRows = tbody.children.length > 0;
    
    emptyRow.style.display = hasRows ? 'none' : 'table-footer-group';
}

// Add part to table
function addPart(productId = '', quantityUsed = '', unitCost = '', notes = '') {
    partCounter++;
    
    const tbody = document.getElementById('partsTableBody');
    const row = document.createElement('tr');
    row.id = `part-${partCounter}`;
    row.className = 'fade-in-row'; // Animation class if you have css
    
    // Create product options
    let productOptions = '<option value="">Seleccionar producto...</option>';
    products.forEach(product => {
        const selected = product.id == productId ? 'selected' : '';
        const stockInfo = product.current_stock <= 0 ? ' (SIN STOCK)' : 
                         product.current_stock <= product.minimum_stock ? ' (STOCK BAJO)' : '';
        productOptions += `<option value="${product.id}" ${selected} data-stock="${product.current_stock}" data-cost="${product.cost_price}">
            ${product.name} (${product.sku})${stockInfo}
        </option>`;
    });
    
    // Format unitCost if it exists
    if (unitCost) {
        unitCost = Math.floor(unitCost).toLocaleString('en-US');
    }

    row.innerHTML = `
        <td class="ps-3">
            <select class="form-select form-select-sm" name="parts[${partCounter}][product_id]" onchange="updateProductInfo(${partCounter})" required>
                ${productOptions}
            </select>
            <div class="invalid-feedback">Seleccione un producto</div>
        </td>
        <td class="text-center">
            <span class="stock-display badge bg-secondary rounded-pill">-</span>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" class="form-control" name="parts[${partCounter}][quantity_used]" 
                       value="${quantityUsed}" min="0.01" step="0.01" placeholder="1" required>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light">${currencySymbol}</span>
                <input type="text" class="form-control" name="parts[${partCounter}][unit_cost]" 
                       value="${unitCost}" oninput="formatCurrencyInput(this)" required>
            </div>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="parts[${partCounter}][notes]" 
                   value="${notes}" placeholder="Opcional...">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle p-2" onclick="removePart(${partCounter})" title="Eliminar">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
    checkEmptyState();
    
    // Update product info if product is selected
    if (productId) {
        updateProductInfo(partCounter);
    }
}

// Remove part from table
function removePart(partId) {
    const row = document.getElementById(`part-${partId}`);
    if (row) {
        // Optional: Add fade out animation
        row.style.opacity = '0';
        setTimeout(() => {
            row.remove();
            checkEmptyState();
        }, 300);
    }
}

// Update product info when product is selected
function updateProductInfo(partId) {
    const row = document.getElementById(`part-${partId}`);
    if (!row) return;
    
    const select = row.querySelector('select');
    const stockDisplay = row.querySelector('.stock-display');
    const costInput = row.querySelector('input[name*="unit_cost"]');
    
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const stock = parseFloat(selectedOption.dataset.stock) || 0;
        const cost = parseFloat(selectedOption.dataset.cost) || 0;
        
        stockDisplay.textContent = stock;
        if (stock <= 0) {
            stockDisplay.className = 'stock-display badge bg-danger rounded-pill';
        } else if (stock <= 5) { // Threshold for low stock warning
            stockDisplay.className = 'stock-display badge bg-warning text-dark rounded-pill';
        } else {
            stockDisplay.className = 'stock-display badge bg-success rounded-pill';
        }
        
        // Auto-fill cost if empty
        if (!costInput.value) {
            costInput.value = Math.floor(cost).toLocaleString('en-US');
        }
    } else {
        stockDisplay.textContent = '-';
        stockDisplay.className = 'stock-display badge bg-secondary rounded-pill';
    }
}

// Format currency input
function formatCurrencyInput(input) {
    let value = input.value.replace(/\D/g, "");
    if (value === "") {
        input.value = "";
        return;
    }
    input.value = parseInt(value).toLocaleString('en-US');
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Add existing parts
    if (currentParts && currentParts.length > 0) {
        currentParts.forEach(part => {
            addPart(part.product_id, part.quantity_used, part.unit_cost, part.notes);
        });
    } else {
        checkEmptyState();
    }
    
    // Form validation
    const form = document.getElementById('partsForm');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });
});
</script>

<?php
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
