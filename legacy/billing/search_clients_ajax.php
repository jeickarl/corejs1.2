<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/session.php';
require_once '../config/database.php';
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

// Obtener término de búsqueda
$search = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search = trim($_POST['search'] ?? '');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = trim($_GET['q'] ?? '');
}

if (empty($search) || strlen($search) < 2) {
    echo json_encode(['success' => true, 'clients' => []]);
    exit();
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

try {
    // Consulta SQL simplificada para evitar problemas
    $query = "
        SELECT 
            id,
            first_name,
            company_name,
            client_type,
            id_number,
            phone,
            email,
            address,
            created_at
        FROM clients 
        WHERE (
            first_name LIKE ? 
            OR company_name LIKE ?
            OR id_number LIKE ?
            OR phone LIKE ?
            OR email LIKE ?
        )
        ORDER BY 
            CASE 
                WHEN client_type = 'company' THEN company_name
                ELSE first_name
            END
        LIMIT 15
    ";
    $searchParam = '%' . $search . '%';

    $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    if (!$perDatabase) {
        $query = str_replace('ORDER BY', 'AND tenant_id = ? ORDER BY', $query);
        $params[] = $tenant_id;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear los datos para la respuesta con información adicional
    $formattedClients = [];
    foreach ($clients as $client) {
        $display_name = $client['company_name'] ?: $client['first_name'];
        
        // Calcular relevancia basada en coincidencias exactas
        $relevance = 0;
        $search_lower = strtolower($search);
        
        if (stripos($display_name, $search) === 0) $relevance += 10;
        if (stripos($client['id_number'], $search) === 0) $relevance += 8;
        if (stripos($client['phone'], $search) === 0) $relevance += 6;
        if (stripos($client['email'], $search) === 0) $relevance += 4;
        
        $formattedClients[] = [
            'id' => (int)$client['id'],
            'name' => $display_name,
            'first_name' => $client['first_name'],
            'company_name' => $client['company_name'],
            'client_type' => $client['client_type'],
            'id_number' => $client['id_number'],
            'phone' => $client['phone'],
            'email' => $client['email'],
            'address' => $client['address'],
            'relevance' => $relevance,
            'created_at' => $client['created_at']
        ];
    }
    
    // Ordenar por relevancia si no hay orden específico
    usort($formattedClients, function($a, $b) {
        return $b['relevance'] - $a['relevance'];
    });
    
    // Respuesta JSON
    $response = [
        'success' => true,
        'clients' => $formattedClients,
        'count' => count($formattedClients)
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Error en búsqueda de clientes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'message' => $e->getMessage()
    ]);
}
?>
