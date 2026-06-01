<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../config/session.php';
require_once '../config/database.php';

// Verificar autenticación
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Obtener el término de búsqueda
$search = trim($_POST['search'] ?? '');

if (empty($search)) {
    echo json_encode(['clients' => []]);
    exit;
}

try {
    // Buscar clientes por nombre, cédula, NIT o nombre de empresa
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $sql = "SELECT 
                id,
                client_type,
                first_name,
                company_name,
                id_number,
                tax_id,
                phone,
                email
            FROM clients 
            WHERE 
                (
                    first_name LIKE ? OR
                    company_name LIKE ? OR
                    id_number LIKE ? OR
                    tax_id LIKE ? OR
                    phone LIKE ?
                )
            ORDER BY 
                CASE 
                    WHEN client_type = 'individual' THEN first_name
                    ELSE company_name
                END
            LIMIT 10";
    $params = [];
    
    $stmt = $pdo->prepare($sql);
    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    if (!$perDatabase) {
        $sql = str_replace('ORDER BY', 'AND tenant_id = ? ORDER BY', $sql);
        $stmt = $pdo->prepare($sql);
        $params[] = $tenant_id;
    }
    $stmt->execute($params);
    
    $clients = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $client = [
            'id' => $row['id'],
            'client_type' => $row['client_type']
        ];
        
        if ($row['client_type'] === 'company') {
            $client['name'] = $row['company_name'];
            $client['id_number'] = $row['tax_id'];
        } else {
            $client['name'] = $row['first_name'];
            $client['id_number'] = $row['id_number'];
        }
        
        $client['phone'] = $row['phone'];
        $client['email'] = $row['email'];
        
        $clients[] = $client;
    }
    
    echo json_encode(['clients' => $clients]);
    
} catch (PDOException $e) {
    error_log("Error en búsqueda de clientes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>
