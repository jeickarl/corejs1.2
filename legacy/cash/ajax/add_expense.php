<?php
require_once '../../config/session.php';
require_once '../../config/functions.php';
require_once '../../config/database.php';
require_once '../../config/performance_optimizer.php';

// Configurar respuesta JSON
header('Content-Type: application/json');

// Verificar autenticación (responder JSON en vez de redirigir)
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once '../../config/security_enhancements.php';

// Validar Token CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!SecurityEnhancements::verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Error de seguridad: Token no válido']);
    exit();
}

try {
    // Verificar que sea una petición POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    // Verificar que hay una sesión de caja abierta
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : $tenant_id;
    $hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
    $hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');

    $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $current_session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_session) {
        throw new Exception('No hay una sesión de caja abierta');
    }

    // Validar datos requeridos
    $amount = parseCurrency($_POST['amount'] ?? 0);
    $concept = trim($_POST['concept'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'Efectivo');
    $notes = trim($_POST['notes'] ?? '');
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $reference_number = trim($_POST['reference_number'] ?? '');

    if (!$amount || $amount <= 0) {
        throw new Exception('El monto debe ser mayor a 0');
    }

    if (empty($concept)) {
        throw new Exception('Debe especificar un concepto');
    }

    if ($payment_method === '') {
        throw new Exception('Debe especificar desde qué medio sale el egreso');
    }

    // Compatibilidad de esquema: agregar columna si aún no existe
    $hasPaymentMethod = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM cash_expenses LIKE 'payment_method'");
        $hasPaymentMethod = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}
    if (!$hasPaymentMethod) {
        try {
            $pdo->exec("ALTER TABLE cash_expenses ADD COLUMN payment_method VARCHAR(100) NULL AFTER amount");
            $hasPaymentMethod = true;
        } catch (Throwable $e) {
            $hasPaymentMethod = false;
        }
    }

    // Procesar archivo de evidencia
    $receipt_image = null;
    if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['receipt_image'];
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new Exception('Archivo de evidencia inválido');
        }

        $maxBytes = 5 * 1024 * 1024;
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new Exception('El archivo de evidencia supera el límite permitido (5MB)');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf'
        ];
        if (!isset($allowed[$mime])) {
            throw new Exception('Tipo de archivo no permitido. Solo imágenes y PDF.');
        }

        if ($mime === 'application/pdf') {
            $head = @file_get_contents($file['tmp_name'], false, null, 0, 8);
            if ($head === false || stripos((string)$head, '%PDF-') !== 0) {
                throw new Exception('El PDF no es válido');
            }
        } else {
            $info = @getimagesize($file['tmp_name']);
            if (!$info || empty($info['mime'])) {
                throw new Exception('Imagen inválida');
            }
            $w = (int)($info[0] ?? 0);
            $h = (int)($info[1] ?? 0);
            if ($w <= 0 || $h <= 0 || ($w * $h) > 25_000_000) {
                throw new Exception('Imagen inválida');
            }
        }
        
        // Crear directorio si no existe
        $relative_base = 'uploads/';
        $tenant_base = getTenantUploadDir($relative_base);
        
        // Corregido: apuntas a core/uploads, no htdocs/uploads
        $upload_dir = __DIR__ . '/../../' . $tenant_base . 'expenses/session_' . $current_session['id'] . '/';
        if (!file_exists($upload_dir)) {
            if (!@mkdir($upload_dir, 0755, true)) {
                throw new Exception('No se pudo crear el directorio para evidencia');
            }
        }
        
        $extension = $allowed[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                PerformanceOptimizer::optimizeImage($target_path, $target_path, 85);
            }
            $receipt_image = $tenant_base . 'expenses/session_' . $current_session['id'] . '/' . $filename;
        } else {
            throw new Exception('Error al subir el archivo de evidencia');
        }
    }

    // Insertar el egreso
    if ($hasPaymentMethod) {
        if ($hasTenantCashExpenses) {
            $stmt = $pdo->prepare("
                INSERT INTO cash_expenses (cash_session_id, tenant_id, amount, payment_method, concept, notes, category_id, reference_number, receipt_image, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $current_session['id'],
                $tenantValue,
                $amount,
                $payment_method,
                $concept,
                $notes,
                $category_id,
                $reference_number,
                $receipt_image,
                $_SESSION['user_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_expenses (cash_session_id, amount, payment_method, concept, notes, category_id, reference_number, receipt_image, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $current_session['id'],
                $amount,
                $payment_method,
                $concept,
                $notes,
                $category_id,
                $reference_number,
                $receipt_image,
                $_SESSION['user_id']
            ]);
        }
    } else {
        if ($hasTenantCashExpenses) {
            $stmt = $pdo->prepare("
                INSERT INTO cash_expenses (cash_session_id, tenant_id, amount, concept, notes, category_id, reference_number, receipt_image, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $current_session['id'],
                $tenantValue,
                $amount,
                $concept,
                $notes,
                $category_id,
                $reference_number,
                $receipt_image,
                $_SESSION['user_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_expenses (cash_session_id, amount, concept, notes, category_id, reference_number, receipt_image, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $current_session['id'],
                $amount,
                $concept,
                $notes,
                $category_id,
                $reference_number,
                $receipt_image,
                $_SESSION['user_id']
            ]);
        }
    }

    // Registrar actividad
    logActivity($_SESSION['user_id'], 'CREATE_EXPENSE', 'cash_expenses', $pdo->lastInsertId());

    echo json_encode([
        'success' => true,
        'message' => 'Egreso registrado correctamente'
    ]);

} catch (Exception $e) {
    error_log("Error al registrar egreso: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
