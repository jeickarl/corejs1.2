<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo = db();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido o expirado']);
    exit;
}

$serial = trim((string)($_POST['serial_number'] ?? ''));
$serial = preg_replace('/\s+/', ' ', $serial);
$serialNorm = strtoupper(str_replace([' ', '-', '_'], '', $serial));

if ($serialNorm === '' || strlen($serialNorm) < 4) {
    echo json_encode(['success' => true, 'found' => false]);
    exit;
}

$tenant_id = function_exists('getCurrentTenantId') ? getCurrentTenantId() : null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

try {
    if ($perDatabase) {
        $sql = "
            SELECT wo.id AS order_id,
                   wo.client_id,
                   wo.serial_number,
                   wo.device_type_id,
                   dt.name AS device_type_name,
                   wo.device_brand,
                   wo.device_model,
                   wo.received_date,
                   wo.completed_date,
                   wo.delivered_date,
                   wo.created_at
            FROM work_orders wo
            LEFT JOIN device_types dt ON dt.id = wo.device_type_id
            WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(wo.serial_number)), ' ', ''), '-', ''), '_', '') = ?
            ORDER BY wo.id DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$serialNorm]);
    } else {
        $sql = "
            SELECT wo.id AS order_id,
                   wo.client_id,
                   wo.serial_number,
                   wo.device_type_id,
                   dt.name AS device_type_name,
                   wo.device_brand,
                   wo.device_model,
                   wo.received_date,
                   wo.completed_date,
                   wo.delivered_date,
                   wo.created_at
            FROM work_orders wo
            LEFT JOIN device_types dt ON dt.id = wo.device_type_id AND dt.tenant_id = wo.tenant_id
            WHERE wo.tenant_id = ?
              AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(wo.serial_number)), ' ', ''), '-', ''), '_', '') = ?
            ORDER BY wo.id DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id, $serialNorm]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => true, 'found' => false]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'found' => true,
        'data' => [
            'order_id' => (int)($row['order_id'] ?? 0),
            'client_id' => (int)($row['client_id'] ?? 0),
            'serial_number' => (string)($row['serial_number'] ?? ''),
            'device_type_id' => (int)($row['device_type_id'] ?? 0),
            'device_type_name' => (string)($row['device_type_name'] ?? ''),
            'device_brand' => (string)($row['device_brand'] ?? ''),
            'device_model' => (string)($row['device_model'] ?? ''),
            'received_date' => $row['received_date'],
            'completed_date' => $row['completed_date'],
            'delivered_date' => $row['delivered_date'],
            'created_at' => $row['created_at'],
        ]
    ]);
} catch (Throwable $e) {
    error_log("ajax_serial_lookup error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al buscar el serial']);
}
