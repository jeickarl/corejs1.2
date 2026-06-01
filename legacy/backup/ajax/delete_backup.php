<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isAdminSession() && !isset($_SESSION['SAAS_SUPERADMIN_ID'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes para eliminar respaldos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $csrf = $_POST['csrf_token'] ?? '';
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
        exit;
    }

    $filename = $_POST['filename'] ?? '';
    
    // Validación básica de seguridad para evitar path traversal
    if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        throw new Exception('Nombre de archivo inválido');
    }

    $tenant_id = getCurrentTenantId();
    if (!$tenant_id || (int)$tenant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tenant inválido']);
        exit;
    }
    $backupDir = ensureTenantSubdirFs((int)$tenant_id, 'backups');
    $filePath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!file_exists($filePath)) {
        throw new Exception('El archivo no existe');
    }

    if (unlink($filePath)) {
        echo json_encode(['success' => true, 'message' => 'Respaldo eliminado correctamente']);
    } else {
        throw new Exception('No se pudo eliminar el archivo');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
