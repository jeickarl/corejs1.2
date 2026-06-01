<?php
// Headers y error handling
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action !== 'check_stock') {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

try {
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenant_id = $perDatabase ? 1 : (int)($_SESSION['tenant_id'] ?? 1);
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    
    if ($product_id <= 0 || $quantity <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Parámetros inválidos'
        ]);
        exit();
    }
    
    // Verificar si el producto existe en inventario del tenant actual
    $sql = "
        SELECT 
            p.id,
            p.name,
            p.sale_price,
            COALESCE(SUM(CASE WHEN m.movement_type = 'in' THEN m.quantity ELSE -m.quantity END), 0) as current_stock,
            p.min_stock_level,
            p.status
        FROM inventory_products p
        LEFT JOIN inventory_movements m ON p.id = m.product_id" . ($perDatabase ? "" : " AND m.tenant_id = ?") . "
        WHERE p.id = ? AND p.status = 'active'" . ($perDatabase ? "" : " AND p.tenant_id = ?") . "
        GROUP BY p.id
    ";
    $stmt = $pdo->prepare($sql);
    $params = [$product_id];
    if (!$perDatabase) {
        $params = [$tenant_id, $product_id, $tenant_id];
    }
    $stmt->execute($params);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode([
            'success' => false,
            'error' => 'Producto no encontrado o inactivo'
        ]);
        exit();
    }
    
    $current_stock = floatval($product['current_stock']);
    $min_stock = floatval($product['min_stock_level']);
    $available_stock = $current_stock - $min_stock;
    
    $can_sell = $available_stock >= $quantity;
    $stock_status = 'sufficient';
    
    if ($current_stock <= 0) {
        $stock_status = 'out_of_stock';
    } elseif ($available_stock < $quantity) {
        $stock_status = 'insufficient';
    } elseif ($current_stock <= $min_stock) {
        $stock_status = 'low_stock';
    }
    
    $response = [
        'success' => true,
        'product' => [
            'id' => $product['id'],
            'name' => $product['name'],
            'sale_price' => $product['sale_price'],
            'current_stock' => $current_stock,
            'min_stock_level' => $min_stock,
            'available_stock' => $available_stock,
            'requested_quantity' => $quantity,
            'can_sell' => $can_sell,
            'stock_status' => $stock_status
        ]
    ];
    
    $debug = isset($_GET['debug']) ? $_GET['debug'] : (isset($_POST['debug']) ? $_POST['debug'] : '');
    if ($debug) {
        $movSql = "
            SELECT 
                COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END), 0) AS total_in,
                COALESCE(SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END), 0) AS total_out
            FROM inventory_movements
            WHERE product_id = ?" . ($perDatabase ? "" : " AND tenant_id = ?") . "
        ";
        $movStmt = $pdo->prepare($movSql);
        $movStmt->execute($perDatabase ? [$product_id] : [$product_id, $tenant_id]);
        $mov = $movStmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total_in' => 0, 'total_out' => 0];
        $response['diagnostics'] = [
            'movement_count' => (int)$mov['cnt'],
            'total_in' => (float)$mov['total_in'],
            'total_out' => (float)$mov['total_out']
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error al verificar stock: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al verificar stock',
        'message' => $e->getMessage()
    ]);
}
?>
