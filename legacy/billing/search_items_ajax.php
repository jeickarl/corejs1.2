<?php
// Búsqueda AJAX de productos y servicios para facturación
// Headers y error handling primero
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/functions.php';

// Verificar autenticación
if (!isValidSession()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $search = trim($_POST['search'] ?? '');
    $type = trim($_POST['type'] ?? 'all'); // 'product', 'service', 'all'
    $searchBy = trim($_POST['search_by'] ?? 'name'); // 'name', 'code'
    
    if (strlen($search) < 2) {
        echo json_encode(['success' => false, 'message' => 'Término de búsqueda muy corto']);
        exit;
    }

    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenant_id = $perDatabase ? 1 : (int)($_SESSION['tenant_id'] ?? 1);
    
    $results = [];
    
    // Buscar productos si el tipo es 'product' o 'all'
    if ($type === 'product' || $type === 'all') {
        if ($searchBy === 'code') {
            // Búsqueda específica por código/SKU
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    name,
                    sku,
                    default_price,
                    stock_quantity,
                    'product' as item_type
                FROM billable_products 
                WHERE is_active = 1 
                " . ($perDatabase ? "" : "AND tenant_id = ?") . "
                AND sku LIKE ?
                AND stock_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN sku = ? THEN 1
                        WHEN sku LIKE ? THEN 2
                        ELSE 3
                    END,
                    sku ASC
                LIMIT 10
            ");
            
            $searchTerm = "%{$search}%";
            $exactSku = $search;
            $startsSku = "{$search}%";
            
            $params = [$searchTerm, $exactSku, $startsSku];
            if (!$perDatabase) {
                array_unshift($params, $tenant_id);
            }
            $stmt->execute($params);
        } else {
            // Búsqueda por nombre/descripción
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    name,
                    sku,
                    default_price,
                    stock_quantity,
                    'product' as item_type
                FROM billable_products 
                WHERE is_active = 1 
                " . ($perDatabase ? "" : "AND tenant_id = ?") . "
                AND (name LIKE ? OR sku LIKE ? OR description LIKE ?)
                AND stock_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN sku LIKE ? THEN 1
                        WHEN name LIKE ? THEN 2
                        ELSE 3
                    END,
                    name ASC
                LIMIT 10
            ");
            
            $searchTerm = "%{$search}%";
            $exactSku = "{$search}%";
            $exactName = "{$search}%";
            
            $params = [$searchTerm, $searchTerm, $searchTerm, $exactSku, $exactName];
            if (!$perDatabase) {
                array_unshift($params, $tenant_id);
            }
            $stmt->execute($params);
        }
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($products as $product) {
            $results[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'code' => $product['sku'],
                'sku' => $product['sku'],
                'price' => $product['default_price'],
                'stock' => $product['stock_quantity'],
                'type' => 'product',
                'display_name' => $product['sku'] ? "[{$product['sku']}] {$product['name']}" : $product['name']
            ];
        }
    }
    
    // Buscar servicios si el tipo es 'service' o 'all'
    if ($type === 'service' || $type === 'all') {
        if ($searchBy === 'code') {
            // Búsqueda específica por código de servicio
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    name,
                    default_price,
                    CONCAT('SRV-', LPAD(id, 4, '0')) as service_code,
                    'service' as item_type
                FROM billable_services 
                WHERE is_active = 1 
                " . ($perDatabase ? "" : "AND tenant_id = ?") . "
                AND CONCAT('SRV-', LPAD(id, 4, '0')) LIKE ?
                ORDER BY 
                    CASE 
                        WHEN CONCAT('SRV-', LPAD(id, 4, '0')) = ? THEN 1
                        WHEN CONCAT('SRV-', LPAD(id, 4, '0')) LIKE ? THEN 2
                        ELSE 3
                    END,
                    id ASC
                LIMIT 10
            ");
            
            $searchTerm = "%{$search}%";
            $exactCode = $search;
            $startsCode = "{$search}%";
            
            $params = [$searchTerm, $exactCode, $startsCode];
            if (!$perDatabase) {
                array_unshift($params, $tenant_id);
            }
            $stmt->execute($params);
        } else {
            // Búsqueda por nombre/descripción
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    name,
                    default_price,
                    CONCAT('SRV-', LPAD(id, 4, '0')) as service_code,
                    'service' as item_type
                FROM billable_services 
                WHERE is_active = 1 
                " . ($perDatabase ? "" : "AND tenant_id = ?") . "
                AND (name LIKE ? OR description LIKE ?)
                ORDER BY 
                    CASE 
                        WHEN name LIKE ? THEN 1
                        ELSE 2
                    END,
                    name ASC
                LIMIT 10
            ");
            
            $searchTerm = "%{$search}%";
            $exactName = "{$search}%";
            
            $params = [$searchTerm, $searchTerm, $exactName];
            if (!$perDatabase) {
                array_unshift($params, $tenant_id);
            }
            $stmt->execute($params);
        }
        
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($services as $service) {
            $results[] = [
                'id' => $service['id'],
                'name' => $service['name'],
                'code' => $service['service_code'],
                'price' => $service['default_price'],
                'stock' => null, // Los servicios no tienen stock
                'type' => 'service',
                'display_name' => "[{$service['service_code']}] {$service['name']}"
            ];
        }
    }
    
    // Ordenar resultados: productos con SKU primero, luego servicios
    usort($results, function($a, $b) {
        if ($a['type'] === 'product' && $b['type'] === 'service') {
            return -1;
        }
        if ($a['type'] === 'service' && $b['type'] === 'product') {
            return 1;
        }
        return strcasecmp($a['display_name'], $b['display_name']);
    });
    
    echo json_encode([
        'success' => true,
        'items' => $results,
        'count' => count($results)
    ]);
    
} catch (Exception $e) {
    error_log("Error en búsqueda de items: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error interno del servidor'
    ]);
}
?>
