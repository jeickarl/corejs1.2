<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$query = $_GET['query'] ?? '';
$type = $_GET['type'] ?? 'orders';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $results = [];
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantId = getCurrentTenantId();
    $tenantValue = $perDatabase ? 1 : (int)$tenantId;
    $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
    $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
    $hasTenantInventoryProducts = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'inventory_products') : false;
    
    if ($type === 'orders') {
        // Buscar por ID, Modelo, Marca o Nombre del Cliente
        $joinClients = ($hasTenantClients && $hasTenantWorkOrders && !$perDatabase)
            ? "LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id"
            : "LEFT JOIN clients c ON o.client_id = c.id";
        $whereTenant = $hasTenantWorkOrders ? "o.tenant_id = :tid AND " : "";
        $stmt = $pdo->prepare("
            SELECT o.id, o.device_brand, o.device_model, o.status, c.first_name, c.company_name
            FROM work_orders o
            $joinClients
            WHERE $whereTenant (
                o.id LIKE :query 
                OR o.device_model LIKE :query 
                OR o.device_brand LIKE :query
                OR c.first_name LIKE :query
                OR c.company_name LIKE :query
            )
            ORDER BY o.created_at DESC
            LIMIT 5
        ");
        $likeQuery = "%$query%";
        $params = [':query' => $likeQuery];
        if ($hasTenantWorkOrders) { $params[':tid'] = $tenantValue; }
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clientName = $row['company_name'] ?: $row['first_name'];
            $results[] = [
                'type' => 'order',
                'url' => '../orders/view.php?id=' . $row['id'],
                'title' => "Orden #{$row['id']} - {$row['device_brand']} {$row['device_model']}",
                'subtitle' => "Cliente: $clientName • " . getStatusText($row['status']),
                'icon' => 'fa-tools'
            ];
        }
    } elseif ($type === 'clients') {
        $whereTenant = $hasTenantClients ? "tenant_id = :tid AND " : "";
        $stmt = $pdo->prepare("
            SELECT id, first_name, company_name, phone, email
            FROM clients
            WHERE $whereTenant (
                first_name LIKE :query 
                OR company_name LIKE :query
                OR phone LIKE :query
                OR email LIKE :query
            )
            LIMIT 5
        ");
        $likeQuery = "%$query%";
        $params = [':query' => $likeQuery];
        if ($hasTenantClients) { $params[':tid'] = $tenantValue; }
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['company_name'] ?: $row['first_name'];
            $results[] = [
                'type' => 'client',
                'url' => '../clients/view.php?id=' . $row['id'],
                'title' => $name,
                'subtitle' => $row['phone'] . ($row['email'] ? " • {$row['email']}" : ''),
                'icon' => 'fa-user'
            ];
        }
    } elseif ($type === 'inventory') {
        $whereTenant = $hasTenantInventoryProducts ? "tenant_id = :tid AND " : "";
        $stmt = $pdo->prepare("
            SELECT id, name, description, current_stock, price
            FROM inventory_products
            WHERE $whereTenant (
                name LIKE :query 
                OR description LIKE :query
            )
            AND is_active = 1
            LIMIT 5
        ");
        $likeQuery = "%$query%";
        $params = [':query' => $likeQuery];
        if ($hasTenantInventoryProducts) { $params[':tid'] = $tenantValue; }
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'type' => 'product',
                'url' => '../inventory/edit.php?id=' . $row['id'], // O view.php si existe
                'title' => $row['name'],
                'subtitle' => "Stock: {$row['current_stock']} • " . formatCurrency($row['price']),
                'icon' => 'fa-box'
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
