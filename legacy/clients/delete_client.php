<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

// Establecer cabecera JSON
header('Content-Type: application/json');

// Tenant actual
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenant_id = ($perDatabase || !function_exists('getCurrentTenantId')) ? null : getCurrentTenantId();

// Verificar autenticación
if (!isValidSession()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

// Obtener datos
$data = json_decode(file_get_contents('php://input'), true);
$client_id = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$csrf_token = isset($data['csrf_token']) ? $data['csrf_token'] : '';

// Verificar CSRF
if (!isset($csrf_token) || !SecurityEnhancements::verifyCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido o expirado. Por favor, recargue la página.']);
    exit();
}

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de cliente inválido']);
    exit();
}

try {
    // 1. Verificar si el cliente existe
    if ($tenant_id !== null) {
        $stmt = $pdo->prepare("SELECT id, first_name, company_name, client_type FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$client_id, $tenant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, company_name, client_type FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
    }
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        echo json_encode(['success' => false, 'message' => 'Cliente no encontrado']);
        exit();
    }

    // 2. Verificar si tiene órdenes asociadas
    if ($tenant_id !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM work_orders WHERE client_id = ? AND tenant_id = ?");
        $stmt->execute([$client_id, $tenant_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM work_orders WHERE client_id = ?");
        $stmt->execute([$client_id]);
    }
    $orders_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    if ($orders_count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "No se puede eliminar el cliente porque tiene $orders_count orden(es) asociada(s). Elimine las órdenes primero."
        ]);
        exit();
    }

    // 3. Proceder a eliminar
    if ($tenant_id !== null) {
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$client_id, $tenant_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
    }

    // Registrar actividad
    logActivity(
        $_SESSION['user_id'], 
        'DELETE_CLIENT', 
        'clients', 
        $client_id, 
        "Cliente eliminado: " . ($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name'])
    );

    echo json_encode(['success' => true, 'message' => 'Cliente eliminado correctamente']);

} catch (PDOException $e) {
    // Log error real internamente si es posible
    echo json_encode(['success' => false, 'message' => 'Error de base de datos al eliminar el cliente']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
}
?>
