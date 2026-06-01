<?php
require_once '../../config/session.php';
require_once '../../config/functions.php';
require_once '../../config/database.php';
require_once '../../config/security_enhancements.php';
$pdo = db();

header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantInventoryProducts = hasTenantColumnCached($pdo, 'inventory_products');
$hasTenantInventoryMovements = hasTenantColumnCached($pdo, 'inventory_movements');

// Verificar permisos
if (!hasRole(['admin', 'inventory'])) {
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Error de seguridad: Token inválido o expirado. Por favor, intente de nuevo.']);
    exit();
}

try {
    $product_id = intval($_POST['product_id']);
    $movement_type = $_POST['movement_type'];
    $movement_subtype = $_POST['movement_subtype'];
    $quantity = floatval($_POST['quantity']);
    $unit_cost = parseCurrency($_POST['unit_cost'] ?? '0');
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
                    reason, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
                    reason, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
                END,
                updated_at = NOW()
            WHERE id = ?" . (($hasTenantInventoryProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ");
        
        $params = [$stock_after, $unit_cost, $movement_type, $movement_type, $unit_cost, $product_id];
        if ($hasTenantInventoryProducts && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        
        $pdo->commit();
        
        // Registrar actividad
        logActivity($_SESSION['user_id'], 'CREATE_INVENTORY_MOVEMENT', 'inventory_movements', $pdo->lastInsertId());
        
        echo json_encode(['success' => true, 'message' => 'Movimiento registrado exitosamente']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
