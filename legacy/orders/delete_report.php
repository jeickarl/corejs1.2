<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
$pdo = db();

requireAuth();

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

header('Content-Type: application/json');

// Obtener datos del JSON
$input = json_decode(file_get_contents('php://input'), true);

$csrf = $input['csrf_token'] ?? '';
$csrfOk = false;
if ($csrf !== '') {
    if (class_exists('SecurityEnhancements') && SecurityEnhancements::verifyCSRFToken($csrf)) {
        $csrfOk = true;
    } else {
        $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
        if ($sessionCsrf !== '' && hash_equals($sessionCsrf, (string)$csrf)) {
            $csrfOk = true;
        }
    }
}
if (!$csrfOk) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido o expirado']);
    exit();
}

$report_id = isset($input['id']) ? (int)$input['id'] : 0;
$order_id = isset($input['order_id']) ? (int)$input['order_id'] : 0;

if (!$report_id || !$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit();
}

try {
    // Eliminar el informe limitado al tenant actual
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $hasTenantCol = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'technical_reports') : false;
    if ($hasTenantCol && !$perDatabase) {
        $stmt = $pdo->prepare("DELETE FROM technical_reports WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$report_id, $tenant_id]);
    } elseif ($hasTenantCol && $perDatabase) {
        $stmt = $pdo->prepare("DELETE FROM technical_reports WHERE id = ? AND tenant_id = 1");
        $stmt->execute([$report_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM technical_reports WHERE id = ?");
        $stmt->execute([$report_id]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Informe eliminado correctamente']);
    exit();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al eliminar informe: ' . $e->getMessage()]);
    exit();
}
?>
