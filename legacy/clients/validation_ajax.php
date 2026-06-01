<?php
header('Content-Type: application/json');

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

// Obtener la acción a realizar
$action = $_POST['action'] ?? '';
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

try {
    switch ($action) {
        case 'check_id_number':
            $idNumber = trim($_POST['id_number'] ?? '');
            $excludeClientId = $_POST['exclude_client_id'] ?? null;
            $tenant_id = getCurrentTenantId();
            
            if (empty($idNumber)) {
                echo json_encode(['exists' => false]);
                exit;
            }
            
            // Preparar consulta SQL
            if ($excludeClientId) {
                // Excluir el cliente actual (para edición)
                $sql = "SELECT id, client_type, first_name, company_name 
                        FROM clients 
                        WHERE id_number = ? AND id != ?";
                if (!$perDatabase) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $pdo->prepare($sql);
                $params = [$idNumber, $excludeClientId];
                if (!$perDatabase) { $params[] = $tenant_id; }
                $stmt->execute($params);
            } else {
                // Búsqueda normal (para creación)
                $sql = "SELECT id, client_type, first_name, company_name 
                        FROM clients 
                        WHERE id_number = ?";
                if (!$perDatabase) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $pdo->prepare($sql);
                $params = [$idNumber];
                if (!$perDatabase) { $params[] = $tenant_id; }
                $stmt->execute($params);
            }
            
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                // Determinar el nombre del cliente
                $clientName = '';
                if ($client['client_type'] === 'company') {
                    $clientName = $client['company_name'];
                } else {
                    $clientName = trim($client['first_name']);
                }
                
                echo json_encode([
                    'exists' => true,
                    'client_id' => $client['id'],
                    'client_name' => $clientName,
                    'client_type' => $client['client_type']
                ]);
            } else {
                echo json_encode(['exists' => false]);
            }
            break;
            
        case 'check_nit':
            $nit = trim($_POST['nit'] ?? '');
            $excludeClientId = $_POST['exclude_client_id'] ?? null;
            $tenant_id = getCurrentTenantId();
            
            if (empty($nit)) {
                echo json_encode(['exists' => false]);
                exit;
            }
            
            // Preparar consulta SQL
            if ($excludeClientId) {
                // Excluir el cliente actual (para edición)
                $sql = "SELECT id, company_name 
                        FROM clients 
                        WHERE tax_id = ? AND id != ? AND client_type = 'company'";
                if (!$perDatabase) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $pdo->prepare($sql);
                $params = [$nit, $excludeClientId];
                if (!$perDatabase) { $params[] = $tenant_id; }
                $stmt->execute($params);
            } else {
                // Búsqueda normal (para creación)
                $sql = "SELECT id, company_name 
                        FROM clients 
                        WHERE tax_id = ? AND client_type = 'company'";
                if (!$perDatabase) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $pdo->prepare($sql);
                $params = [$nit];
                if (!$perDatabase) { $params[] = $tenant_id; }
                $stmt->execute($params);
            }
            
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                echo json_encode([
                    'exists' => true,
                    'client_id' => $client['id'],
                    'client_name' => $client['company_name'],
                    'client_type' => 'company'
                ]);
            } else {
                echo json_encode(['exists' => false]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Error en validación: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>
