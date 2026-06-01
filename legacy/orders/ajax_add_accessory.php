<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

header('Content-Type: application/json');

$pdo = db();
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido o expirado']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'El nombre del accesorio es obligatorio']);
    exit;
}

try {
    // Verificar si ya existe
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT id FROM equipment_accessories WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM equipment_accessories WHERE name = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$name, $tenant_id]);
    }
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ya existe un accesorio con este nombre']);
        exit;
    }

    // Insertar nuevo accesorio
    // Asumimos sort_order 0 por defecto y activo
    $hasTenantCol = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'equipment_accessories') : false;
    if ($hasTenantCol && !$perDatabase) {
        $stmt = $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, is_active, sort_order, category) VALUES (?, ?, 1, 0, 'general')");
        $stmt->execute([$tenant_id, $name]);
    } elseif ($hasTenantCol && $perDatabase) {
        $stmt = $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, is_active, sort_order, category) VALUES (1, ?, 1, 0, 'general')");
        $stmt->execute([$name]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO equipment_accessories (name, is_active, sort_order, category) VALUES (?, 1, 0, 'general')");
        $stmt->execute([$name]);
    }
    $id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true, 
        'accessory' => [
            'id' => $id,
            'name' => $name
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error al agregar accesorio: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al guardar el accesorio']);
}
?>
