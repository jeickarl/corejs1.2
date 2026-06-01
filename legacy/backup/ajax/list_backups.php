<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
if (!isAdminSession()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
    exit;
}

try {
    $tenant_id = getCurrentTenantId();
    if (!$tenant_id || (int)$tenant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tenant inválido']);
        exit;
    }
    $backupDir = ensureTenantSubdirFs((int)$tenant_id, 'backups');
    if (!is_dir($backupDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo acceder al directorio de respaldos. Verifica permisos en uploads.']);
        exit;
    }

    $files = [];
    $scannedFiles = scandir($backupDir);

    foreach ($scannedFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (is_file($filePath)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['sql', 'zip'])) {
                $files[] = [
                    'name' => $file,
                    'size' => filesize($filePath),
                    'date' => filemtime($filePath),
                    'type' => $ext === 'zip' ? 'Completo (DB + Archivos)' : 'Base de Datos',
                    'url' => '../backup/ajax/download_backup.php?filename=' . rawurlencode($file)
                ];
            }
        }
    }

    // Ordenar por fecha descendente
    usort($files, function($a, $b) {
        return $b['date'] - $a['date'];
    });

    echo json_encode(['success' => true, 'files' => $files]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
