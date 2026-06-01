<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
require_once '../config/performance_optimizer.php';

header('Content-Type: application/json');

$pdo = db();
if (!isValidSession()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido o expirado']);
    exit;
}

if (!isset($data['image']) || !isset($data['filename']) || !isset($data['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

$order_id = (int)$data['order_id'];
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;

// Verify that the order exists and belongs to the current tenant (IDOR prevention)
if (!$perDatabase && $hasTenantWorkOrders) {
    $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$order_id, $tenantValue]);
} else {
    $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE id = ?");
    $stmt->execute([$order_id]);
}
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para modificar esta orden']);
    exit;
}

$original_filename = basename($data['filename']); // Prevent directory traversal
$image_data = $data['image'];

if (!is_string($image_data)) {
    echo json_encode(['success' => false, 'error' => 'Formato de imagen inválido']);
    exit;
}

// Directory path
$upload_dir = getTenantUploadDir('../uploads/') . "orders/" . $order_id . "/";
if (!is_dir($upload_dir)) {
    // Should exist if we are editing an existing photo
    @mkdir($upload_dir, 0755, true);
    if (!is_dir($upload_dir)) {
        echo json_encode(['success' => false, 'error' => 'Directorio de orden no encontrado']);
        exit;
    }
}

$original_relative_path = $data['filename'];
$original_relative_path = str_replace('\\', '/', (string)$original_relative_path);
$original_relative_path = ltrim($original_relative_path, '/');
$original_relative_path = preg_replace('#/+#', '/', $original_relative_path);

$allowed_galleries = ['entry', 'diagnosis', 'delivery'];
$subdir = '';
$base = $original_filename;
if (preg_match('#^(entry|diagnosis|delivery)/([^/]+)$#', $original_relative_path, $m)) {
    $subdir = $m[1];
    $base = $m[2];
}
if ($subdir === '' || !in_array($subdir, $allowed_galleries, true)) {
    echo json_encode(['success' => false, 'error' => 'Ruta de imagen inválida']);
    exit;
}
$base = basename((string)$base);
if (!preg_match('/^[A-Za-z0-9._-]{1,120}\.(png|jpg|jpeg)$/i', $base)) {
    echo json_encode(['success' => false, 'error' => 'Nombre de archivo inválido']);
    exit;
}

if (!preg_match('#^data:image/(png|jpeg);base64,#', $image_data)) {
    echo json_encode(['success' => false, 'error' => 'Formato de imagen inválido']);
    exit;
}
$pos = strpos($image_data, ',');
if ($pos === false) {
    echo json_encode(['success' => false, 'error' => 'Formato de imagen inválido']);
    exit;
}
$b64 = substr($image_data, $pos + 1);
$b64 = str_replace(' ', '+', $b64);
$decoded = base64_decode($b64, true);
if ($decoded === false) {
    echo json_encode(['success' => false, 'error' => 'Imagen inválida']);
    exit;
}
$maxBytes = 5 * 1024 * 1024;
if (strlen($decoded) <= 0 || strlen($decoded) > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'Imagen demasiado grande']);
    exit;
}
$info = @getimagesizefromstring($decoded);
if (!$info || empty($info['mime'])) {
    echo json_encode(['success' => false, 'error' => 'Imagen inválida']);
    exit;
}
$mime = (string)$info['mime'];
$mimeToExt = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
if (!isset($mimeToExt[$mime])) {
    echo json_encode(['success' => false, 'error' => 'Formato de imagen inválido']);
    exit;
}
$w = (int)($info[0] ?? 0);
$h = (int)($info[1] ?? 0);
if ($w <= 0 || $h <= 0 || ($w * $h) > 25_000_000) {
    echo json_encode(['success' => false, 'error' => 'Imagen inválida']);
    exit;
}

$subdirPath = $upload_dir . $subdir . '/';
if (!is_dir($subdirPath)) {
    @mkdir($subdirPath, 0755, true);
}
if (!is_dir($subdirPath)) {
    echo json_encode(['success' => false, 'error' => 'Directorio de destino inválido']);
    exit;
}

$filename_no_ext = preg_replace('/\.[^.]+$/', '', $base);
$filename_no_ext = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$filename_no_ext);
$new_filename = $subdir . '/' . $filename_no_ext . '_annotated_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $mimeToExt[$mime];
$target_path = $upload_dir . $new_filename;

if (file_put_contents($target_path, $decoded, LOCK_EX)) {
    $extLower = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
    if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        PerformanceOptimizer::optimizeImage($target_path, $target_path, 85);
    }
    echo json_encode([
        'success' => true,
        'new_filename' => $new_filename,
        'new_src' => getTenantUploadDir('../uploads/') . "orders/" . $order_id . "/" . $new_filename
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Error al guardar el archivo']);
}
