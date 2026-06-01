<?php
header('Content-Type: application/octet-stream');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isValidSession()) {
    http_response_code(401);
    echo 'No autorizado';
    exit;
}
if (!isAdminSession()) {
    http_response_code(403);
    echo 'Permisos insuficientes';
    exit;
}

$filename = $_GET['filename'] ?? '';
if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    http_response_code(400);
    echo 'Nombre de archivo inválido';
    exit;
}

$tenant_id = getCurrentTenantId();
if (!$tenant_id || (int)$tenant_id <= 0) {
    http_response_code(400);
    echo 'Tenant inválido';
    exit;
}
$backupDir = ensureTenantSubdirFs((int)$tenant_id, 'backups');
$filePath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

if (!is_file($filePath)) {
    http_response_code(404);
    echo 'Archivo no encontrado';
    exit;
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'zip') $mime = 'application/zip';
if ($ext === 'sql') $mime = 'application/sql';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
readfile($filePath);
exit;
