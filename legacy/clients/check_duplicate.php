<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
header('Content-Type: application/json');

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}


// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

try {
    $id_number = trim($_GET['id_number'] ?? '');
    $client_type = $_GET['client_type'] ?? 'individual';
    
    if (empty($id_number)) {
        echo json_encode(['success' => true, 'exists' => false]);
        exit();
    }
    
    // Para empresas, verificar en tax_id, para individuos en id_number
    if ($client_type === 'company') {
        $stmt = $perDatabase
            ? $pdo->prepare("SELECT id, company_name FROM clients WHERE tax_id = ?")
            : $pdo->prepare("SELECT id, company_name FROM clients WHERE tax_id = ? AND tenant_id = ?");
    } else {
        $stmt = $perDatabase
            ? $pdo->prepare("SELECT id, first_name as full_name FROM clients WHERE id_number = ?")
            : $pdo->prepare("SELECT id, first_name as full_name FROM clients WHERE id_number = ? AND tenant_id = ?");
    }
    
    $params = [$id_number];
    if (!$perDatabase) {
        $params[] = $tenant_id;
    }
    $stmt->execute($params);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        $client_name = $client_type === 'company' ? $client['company_name'] : $client['full_name'];
        $message = $client_type === 'company' 
            ? 'Ya existe una empresa con este NIT/RUC: ' . $client_name
            : 'Ya existe un cliente con este número de identificación: ' . $client_name;
            
        echo json_encode([
            'success' => true, 
            'exists' => true,
            'message' => $message
        ]);
    } else {
        echo json_encode(['success' => true, 'exists' => false]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al verificar duplicidad: ' . $e->getMessage()
    ]);
}
?>
