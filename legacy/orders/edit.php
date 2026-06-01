<?php
require_once '../config/session.php';
requireAuth('../login/index.php');

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';
require_once '../config/performance_optimizer.php';

$pdo = db();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

// Obtener configuración de moneda
$currency_config = CompanySettings::getCurrency();
$system_config_js = [
    'currency' => $currency_config
];

// Obtener ID de la orden
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: index.php?error=' . urlencode('ID de orden no válido'));
    exit();
}

// Verificar si la orden tiene una factura asociada
$has_linked_invoice = false;
try {
    if ($perDatabase) {
        $stmtInvoiceCheck = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? LIMIT 1");
        $stmtInvoiceCheck->execute([$order_id]);
    } else {
        $stmtInvoiceCheck = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND tenant_id = ? LIMIT 1");
        $stmtInvoiceCheck->execute([$order_id, $tenant_id]);
    }
    if ($stmtInvoiceCheck->fetch()) {
        $has_linked_invoice = true;
    }
} catch (PDOException $e) {
    // Si hay error en la consulta (ej. tabla no existe), asumimos false
    error_log("Error verificando factura vinculada: " . $e->getMessage());
}

if (function_exists('normalizeCatalogsToTenant')) { normalizeCatalogsToTenant($pdo, $tenant_id); }
if (function_exists('normalizeModelRelationsTenant')) { normalizeModelRelationsTenant($pdo, $tenant_id); }

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();
// Mantener compatibilidad con sesiones antiguas si es necesario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        header('Location: edit.php?id=' . $order_id . '&error=' . urlencode('Token de seguridad inválido o expirado. Por favor, intente de nuevo.'));
        exit();
    }
    
    try {
        $client_id = (int)$_POST['client_id'];
        $device_type_id = (int)$_POST['device_type_id'];
        $device_brand = trim($_POST['device_brand']);
        $device_model = trim($_POST['device_model']);
        $device_password = trim($_POST['device_password']);
        $serial_number = trim($_POST['serial_number']);
        $reported_issue = trim($_POST['reported_issue']);
        $client_observations = trim($_POST['client_observations'] ?? '');
        $diagnosis = trim($_POST['diagnosis']);
        $solution = trim($_POST['solution']);
        $priority = $_POST['priority'];
        $status = $_POST['status'];
        $estimated_cost = !empty($_POST['estimated_cost']) ? parseCurrency($_POST['estimated_cost']) : null;
    $final_cost = !empty($_POST['final_cost']) ? parseCurrency($_POST['final_cost']) : null;
    
    // Manejo de abonos
    // Si hay factura vinculada, no permitir nuevos abonos
    $new_abono = 0;
    if (!$has_linked_invoice) {
        $new_abono = !empty($_POST['new_abono']) ? parseCurrency($_POST['new_abono']) : 0;
    }
    
    $payment_method = !empty($_POST['payment_method']) ? trim($_POST['payment_method']) : null;
    $payment_reference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : null;
    
    // El abono total será el actual (de la BD) + el nuevo (si hay)
    // Se calculará al momento de guardar para asegurar consistencia
    
    $estimated_completion = !empty($_POST['estimated_completion']) ? $_POST['estimated_completion'] : null;
    $technician_notes = trim($_POST['technician_notes']);
        
        // Procesar accesorios del equipo
        $accessories_data = $_POST['accessories'] ?? [];
        
        // Validaciones
        $errors = [];
        if (empty($client_id)) $errors[] = 'Debe seleccionar un cliente';
        if (empty($serial_number)) $errors[] = 'El número de serie es obligatorio';
        if (empty($reported_issue)) $errors[] = 'La falla reportada es requerida';
        
        // Validar nuevo abono
    if ($new_abono > 0 && empty($payment_method)) {
        $errors[] = "Debe seleccionar un método de pago para el nuevo abono.";
    }

        if (empty($errors)) {
            // Manejo de Fotos (Lógica existente)
            $existing_photos = [];
            if ($perDatabase) {
                $stmt = $pdo->prepare("SELECT device_photo FROM work_orders WHERE id = ?");
                $stmt->execute([$order_id]);
            } else {
                $stmt = $pdo->prepare("SELECT device_photo FROM work_orders WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$order_id, $tenant_id]);
            }
            $current_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($current_order['device_photo'])) {
                $existing_photos = json_decode($current_order['device_photo'], true) ?: [];
            }
            
            $new_photos = [];
            $captured_photos_data = $_POST['captured_photos_data'] ?? '';
            
            if (!empty($captured_photos_data) && $captured_photos_data !== '[]') {
                try {
                    $photos_data = json_decode($captured_photos_data, true);
                    if (is_array($photos_data) && count($photos_data) > 0) {
                        $allowed_galleries = ['entry','diagnosis','delivery'];
                        $maxPhotos = 10;
                        $maxBytes = 5 * 1024 * 1024;
                        $maxPixels = 25_000_000;
                        $mimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp'
                        ];
                        
                        foreach ($photos_data as $index => $photo_data) {
                            if (count($new_photos) >= $maxPhotos) {
                                break;
                            }
                            if (isset($photo_data['data']) && strpos($photo_data['data'], 'data:image/') === 0) {
                                // Determinar categoría por foto
                                $gallery = $photo_data['category'] ?? ($_POST['photo_gallery'] ?? 'entry');
                                if (!in_array($gallery, $allowed_galleries, true)) {
                                    $gallery = 'entry';
                                }

                                $upload_dir = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/' . $gallery . '/';
                                if (!is_dir($upload_dir)) {
                                    if (!@mkdir($upload_dir, 0755, true)) {
                                        continue;
                                    }
                                }

                                $imageRaw = (string)$photo_data['data'];
                                if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#', $imageRaw)) {
                                    continue;
                                }
                                $pos = strpos($imageRaw, ',');
                                if ($pos === false) {
                                    continue;
                                }
                                $b64 = substr($imageRaw, $pos + 1);
                                $b64 = str_replace(' ', '+', $b64);
                                $image_decoded = base64_decode($b64, true);
                                if ($image_decoded === false) {
                                    continue;
                                }
                                if (strlen($image_decoded) <= 0 || strlen($image_decoded) > $maxBytes) {
                                    continue;
                                }
                                $info = @getimagesizefromstring($image_decoded);
                                if (!$info || empty($info['mime'])) {
                                    continue;
                                }
                                $mime = (string)$info['mime'];
                                if (!isset($mimeToExt[$mime])) {
                                    continue;
                                }
                                $w = (int)($info[0] ?? 0);
                                $h = (int)($info[1] ?? 0);
                                if ($w <= 0 || $h <= 0 || ($w * $h) > $maxPixels) {
                                    continue;
                                }
                                $photo_filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$mime];
                                $photo_path = $upload_dir . $photo_filename;
                                if (file_put_contents($photo_path, $image_decoded, LOCK_EX) !== false) {
                                    if (file_exists($photo_path) && filesize($photo_path) > 0) {
                                        $extLower = strtolower(pathinfo($photo_filename, PATHINFO_EXTENSION));
                                        $q = isset($_POST['upload_quality']) ? max(50, min(95, (int)$_POST['upload_quality'])) : 85;
                                        if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                                            PerformanceOptimizer::optimizeImage($photo_path, $photo_path, $q);
                                        }
                                        $new_photos[] = $gallery . '/' . $photo_filename;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error al procesar fotos capturadas: " . $e->getMessage());
                }
            }
            
            if (!empty($_FILES['device_photo']['name'])) {
                $first_name = is_array($_FILES['device_photo']['name']) ? $_FILES['device_photo']['name'][0] : $_FILES['device_photo']['name'];
                if (!empty($first_name)) {
                    try {
                        $allowed_galleries = ['entry','diagnosis','delivery'];
                        $gallery = $_POST['photo_gallery'] ?? 'entry';
                        if (!in_array($gallery, $allowed_galleries, true)) {
                            $gallery = 'entry';
                        }
                        $upload_dir = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/' . $gallery . '/';
                        if (!is_dir($upload_dir)) {
                            if (!@mkdir($upload_dir, 0755, true)) {
                                throw new Exception('No se pudo crear el directorio de subida');
                            }
                        }
                        $maxPhotos = 10;
                        $maxBytes = 5 * 1024 * 1024;
                        $mimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp'
                        ];
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        
                        $uploaded_files = $_FILES['device_photo'];
                        if (!is_array($uploaded_files['name'])) {
                            $uploaded_files = [
                                'name' => [$uploaded_files['name']],
                                'type' => [$uploaded_files['type']],
                                'tmp_name' => [$uploaded_files['tmp_name']],
                                'error' => [$uploaded_files['error']],
                                'size' => [$uploaded_files['size']]
                            ];
                        }
                        
                        foreach ($uploaded_files['name'] as $index => $filename) {
                            if (count($new_photos) >= $maxPhotos) {
                                break;
                            }
                            if ($uploaded_files['error'][$index] === UPLOAD_ERR_OK) {
                                $tmp = $uploaded_files['tmp_name'][$index] ?? '';
                                if ($tmp === '' || !is_uploaded_file($tmp)) {
                                    continue;
                                }
                                $size = (int)($uploaded_files['size'][$index] ?? 0);
                                if ($size <= 0 || $size > $maxBytes) {
                                    continue;
                                }
                                $mime = $finfo->file($tmp) ?: '';
                                if (!isset($mimeToExt[$mime])) {
                                    continue;
                                }
                                $photo_filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$mime];
                                $photo_path = $upload_dir . $photo_filename;
                                
                                if (move_uploaded_file($tmp, $photo_path)) {
                                    $extLower = strtolower(pathinfo($photo_filename, PATHINFO_EXTENSION));
                                    $q = isset($_POST['upload_quality']) ? max(50, min(95, (int)$_POST['upload_quality'])) : 85;
                                    if (in_array($extLower, ['jpg','jpeg','png','gif'])) {
                                        PerformanceOptimizer::optimizeImage($photo_path, $photo_path, $q);
                                    }
                                    $new_photos[] = $gallery . '/' . $photo_filename;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error al procesar archivos subidos: " . $e->getMessage());
                    }
                }
            }
            
            $photos_to_remove = $_POST['photos_to_remove'] ?? '';
            if (!empty($photos_to_remove) && $photos_to_remove !== '[]' && $photos_to_remove !== 'null') {
                $photos_to_remove_array = json_decode($photos_to_remove, true) ?: [];
                foreach ($photos_to_remove_array as $photo_to_remove) {
                    $baseUploads = '../uploads/';
                    $photo_path = getTenantUploadDir($baseUploads) . 'orders/' . $order_id . '/' . $photo_to_remove;
                    if (file_exists($photo_path)) {
                        unlink($photo_path);
                    }
                }
                $existing_photos = array_diff($existing_photos, $photos_to_remove_array);
            }
            
            $all_photos = $existing_photos;
            if (count($new_photos) > 0) {
                $all_photos = array_merge($existing_photos, $new_photos);
            }
            
            // Migración: mover fotos antiguas en 'other/' a 'entry/' respetando tenant
            try {
                $tenant_base = getTenantUploadDir('../uploads/') . 'orders/' . $order_id . '/';
                $other_dir = $tenant_base . 'other/';
                $entry_dir = $tenant_base . 'entry/';
                if (is_dir($other_dir)) {
                    if (!is_dir($entry_dir)) {
                        mkdir($entry_dir, 0755, true);
                    }
                    $items = @scandir($other_dir) ?: [];
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $src = $other_dir . $item;
                        if (!is_file($src)) continue;
                        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) continue;
                        $dest_name = $item;
                        if (file_exists($entry_dir . $dest_name)) {
                            $base = pathinfo($item, PATHINFO_FILENAME);
                            $dest_name = $base . '_' . uniqid() . '.' . $ext;
                        }
                        $dest = $entry_dir . $dest_name;
                        if (@rename($src, $dest)) {
                            $all_photos[] = 'entry/' . $dest_name;
                        } else if (@copy($src, $dest)) {
                            @unlink($src);
                            $all_photos[] = 'entry/' . $dest_name;
                        }
                    }
                    $all_photos = array_values(array_unique(array_filter(array_map(function($p) {
                        return preg_replace('#^other/#', 'entry/', $p);
                    }, $all_photos))));
                    @rmdir($other_dir);
                }
            } catch (Exception $e) {
                error_log("Migración de fotos 'other' a 'entry' falló: " . $e->getMessage());
            }
            
            // Calcular nuevo total de abono
            // Primero obtenemos el valor actual de la base de datos para asegurar que no se pierda
            if ($perDatabase) {
                $stmtCurrent = $pdo->prepare("SELECT advance_payment FROM work_orders WHERE id = ?");
                $stmtCurrent->execute([$order_id]);
            } else {
                $stmtCurrent = $pdo->prepare("SELECT advance_payment FROM work_orders WHERE id = ? AND tenant_id = ?");
                $stmtCurrent->execute([$order_id, $tenant_id]);
            }
            $current_advance = $stmtCurrent->fetchColumn() ?: 0;
            
            $total_advance = $current_advance + $new_abono;

            if ($perDatabase) {
                $existingPayStmt = $pdo->prepare("SELECT payment_method, payment_reference FROM work_orders WHERE id = ? LIMIT 1");
                $existingPayStmt->execute([$order_id]);
            } else {
                $existingPayStmt = $pdo->prepare("SELECT payment_method, payment_reference FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                $existingPayStmt->execute([$order_id, $tenant_id]);
            }
            $existingPay = $existingPayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $existing_method = $existingPay['payment_method'] ?? null;
            $existing_reference = $existingPay['payment_reference'] ?? null;
            $final_payment_method = ($payment_method !== null && $payment_method !== '') ? $payment_method : $existing_method;
            $final_payment_reference = ($payment_reference !== null && $payment_reference !== '') ? $payment_reference : $existing_reference;

            // Registrar ingreso en caja si hay NUEVO abono
            if ($new_abono > 0) {
                try {
                    // Verificar sesión de caja abierta
                    $hasTenantCashSessions = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_sessions') : false;
                    $sqlSess = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id DESC LIMIT 1";
                    $stmtSession = $pdo->prepare($sqlSess);
                    $stmtSession->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenant_id] : []);
                    $cash_session = $stmtSession->fetch(PDO::FETCH_ASSOC);

                    if ($cash_session) {
                        // Obtener referencia si existe
                        $payment_reference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : null;

                        // Insertar ingreso
                        $desc = "Abono adicional Orden #$order_id";
                        if (!empty($payment_reference)) {
                            $desc .= " - Ref: $payment_reference";
                        }
                        
                        $hasTenantIncome = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_income') : false;
                        if ($hasTenantIncome && !$perDatabase) {
                            $stmtIncome = $pdo->prepare("
                                INSERT INTO cash_income (
                                    tenant_id, cash_session_id, income_type, concept_id, concept, amount, 
                                    payment_method, reference_number, description, notes, created_by, created_at
                                ) VALUES (?, ?, 'service', 1, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmtIncome->execute([
                                $tenant_id,
                                $cash_session['id'],
                                "Abono Orden #$order_id",
                                $new_abono,
                                $payment_method,
                                $payment_reference,
                                $desc,
                                $desc,
                                $_SESSION['user_id']
                            ]);
                        } elseif ($hasTenantIncome && $perDatabase) {
                            $stmtIncome = $pdo->prepare("
                                INSERT INTO cash_income (
                                    tenant_id, cash_session_id, income_type, concept_id, concept, amount, 
                                    payment_method, reference_number, description, notes, created_by, created_at
                                ) VALUES (1, ?, 'service', 1, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmtIncome->execute([
                                $cash_session['id'],
                                "Abono Orden #$order_id",
                                $new_abono,
                                $payment_method,
                                $payment_reference,
                                $desc,
                                $desc,
                                $_SESSION['user_id']
                            ]);
                        } else {
                            $stmtIncome = $pdo->prepare("
                                INSERT INTO cash_income (
                                    cash_session_id, income_type, concept_id, concept, amount, 
                                    payment_method, reference_number, description, notes, created_by, created_at
                                ) VALUES (?, 'service', 1, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmtIncome->execute([
                                $cash_session['id'],
                                "Abono Orden #$order_id",
                                $new_abono,
                                $payment_method,
                                $payment_reference,
                                $desc,
                                $desc,
                                $_SESSION['user_id']
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error registrando abono en caja: " . $e->getMessage());
                    // No detenemos el proceso si falla el registro en caja, pero lo logueamos
                }
            }

            // Detectar cambio de costo con orden previamente aprobada/rechazada para requerir nueva aprobación
            if ($perDatabase) {
                $prevStmtEarly = $pdo->prepare("SELECT estimated_cost, approval_status, status FROM work_orders WHERE id = ? LIMIT 1");
                $prevStmtEarly->execute([$order_id]);
            } else {
                $prevStmtEarly = $pdo->prepare("SELECT estimated_cost, approval_status, status FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                $prevStmtEarly->execute([$order_id, $tenant_id]);
            }
            $prevRowEarly = $prevStmtEarly->fetch(PDO::FETCH_ASSOC) ?: ['estimated_cost' => null, 'approval_status' => 'none', 'status' => null];
            $prevEstEarly = isset($prevRowEarly['estimated_cost']) ? (float)$prevRowEarly['estimated_cost'] : null;
            $prevApEarly = strtolower((string)($prevRowEarly['approval_status'] ?? 'none'));
            $needsResetApprovalEarly = false;
            $oldFmtEarly = $prevEstEarly !== null ? number_format((float)$prevEstEarly, 2, '.', '') : null;
            $newFmtEarly = $estimated_cost !== null ? number_format((float)$estimated_cost, 2, '.', '') : null;
            if ($oldFmtEarly !== null && $newFmtEarly !== null && $oldFmtEarly !== $newFmtEarly && in_array($prevApEarly, ['approved','rejected'], true)) {
                $needsResetApprovalEarly = true;
            }
            // Evitar que una orden con precio cambiado y antes aprobada se vuelva a aprobar automáticamente
            $ns = strtolower(trim($status));
            $requestedWaitingApproval = in_array($ns, ['esperando_aprobacion','esperando aprobacion','esperando-aprobacion','esperando_aprovacion','waiting_authorization','waiting approval'], true);
            if ($requestedWaitingApproval) {
                $needsResetApprovalEarly = true;
            }
            if ($needsResetApprovalEarly && in_array($ns, ['aprobado','approved'], true)) {
                $status = 'pendiente';
                $ns = 'pendiente';
            }

            // Manejo especial: 'aprobado'/'approved' se registra en approval_status, no en status
            if (!$needsResetApprovalEarly && in_array($ns, ['aprobado','approved'], true)) {
                try {
                    // Asegurar columnas de aprobación
                    try {
                        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
                        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders'");
                        $colStmt->execute([$dbName]);
                        $cols = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                        if (!in_array('approval_status', $cols, true)) {
                            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER status");
                        }
                        if (!in_array('approved_at', $cols, true)) {
                            $pdo->exec("ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_status");
                        }
                    } catch (Throwable $__) {}
                    // Determinar slug para status 'aprobado' compatible con la columna
                    $approvedSlug = 'aprobado';
                    try {
                        $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                        $ctStmt->execute();
                        $colType = (string)$ctStmt->fetchColumn();
                        if ($colType && stripos($colType, 'enum(') === 0) {
                            $enumVals = [];
                            if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                                $raw = $m[1];
                                $parts = array_map('trim', explode(',', $raw));
                                $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                            }
                            if (!in_array('aprobado', $enumVals, true)) {
                                if (in_array('approved', $enumVals, true)) {
                                    $approvedSlug = 'approved';
                                } else {
                                    $approvedSlug = null;
                                }
                            }
                        }
                    } catch (Throwable $__) {}
                    // Actualizar aprobación y (si posible) mover status a 'aprobado'
                    $hasClientObsCol = hasColumnCached($pdo, 'work_orders', 'client_observations');
                    try {
                        if (!$hasClientObsCol) {
                            $pdo->exec("ALTER TABLE work_orders ADD COLUMN client_observations TEXT NULL AFTER reported_issue");
                            if (session_status() === PHP_SESSION_ACTIVE) {
                                if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
                                $_SESSION['schema_cache_cols']['work_orders_client_observations'] = true;
                            }
                            $hasClientObsCol = true;
                        }
                    } catch (Throwable $__) {}
                    $sqlUp = "UPDATE work_orders SET 
                        client_id = ?, device_type_id = ?, device_brand = ?, device_model = ?, device_password = ?,
                        serial_number = ?, reported_issue = ?," . ($hasClientObsCol ? " client_observations = ?," : "") . " diagnosis = ?, solution = ?,
                        priority = ?, " . ($approvedSlug !== null ? "status = ?" : "status = status") . ", estimated_cost = ?, final_cost = ?,
                        estimated_completion = ?, technician_notes = ?, device_photo = ?, 
                        advance_payment = ?, payment_method = ?, payment_reference = ?, 
                        approval_status = 'approved', approved_at = NOW(), updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?";
                    $params = [
                        $client_id, $device_type_id, $device_brand, $device_model, $device_password,
                        $serial_number, $reported_issue
                    ];
                    if ($hasClientObsCol) {
                        $params[] = $client_observations !== '' ? $client_observations : null;
                    }
                    $params = array_merge($params, [$diagnosis, $solution, $priority]);
                    if ($approvedSlug !== null) { $params[] = $approvedSlug; }
                    $params[] = $estimated_cost;
                    $params[] = $final_cost;
                    $params = array_merge($params, [
                        $estimated_completion, $technician_notes, $photos_json,
                        $total_advance, $final_payment_method, $final_payment_reference, $order_id
                    ]);
                    if (!$perDatabase) {
                        $sqlUp .= " AND tenant_id = ?";
                        $params[] = $tenant_id;
                    }
                    $up = $pdo->prepare($sqlUp);
                    $params = [
                        $client_id, $device_type_id, $device_brand, $device_model, $device_password,
                        $serial_number, $reported_issue
                    ];
                    if ($hasClientObsCol) {
                        $params[] = $client_observations !== '' ? $client_observations : null;
                    }
                    $params = array_merge($params, [$diagnosis, $solution, $priority]);
                    if ($approvedSlug !== null) { $params[] = $approvedSlug; }
                    $params[] = $estimated_cost;
                    $params[] = $final_cost;
                    $params = array_merge($params, [
                        $estimated_completion, $technician_notes, $photos_json, 
                        $total_advance, $final_payment_method, $final_payment_reference, $order_id
                    ]);
                    if (!$perDatabase) { $params[] = $tenant_id; }
                    $up->execute($params);
                    try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $__) {}
                    $hasTenantHistory = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_status_history') : false;
                    $history_sql = $hasTenantHistory
                        ? "INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id, created_at) VALUES (?, 'approved', ?, ?, ?, CURRENT_TIMESTAMP)"
                        : "INSERT INTO order_status_history (order_id, status, notes, changed_by, created_at) VALUES (?, 'approved', ?, ?, CURRENT_TIMESTAMP)";
                    $history_notes = "Aprobado por usuario interno";
                    $history_stmt = $pdo->prepare($history_sql);
                    $history_params = [$order_id, $history_notes, $_SESSION['user_id']];
                    if ($hasTenantHistory) { $history_params[] = $tenant_id; }
                    $history_stmt->execute($history_params);
                    header('Location: view.php?id=' . $order_id . '&success=' . urlencode('Orden aprobada'));
                    exit();
                } catch (Throwable $e) {
                    $errors[] = 'Error al aprobar la orden: ' . $e->getMessage();
                }
            }
            
            // Normalizar status a ENUM si aplica
            try {
                $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
                $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                $ctStmt->execute([$dbName]);
                $colType = (string)$ctStmt->fetchColumn();
                if ($colType && stripos($colType, 'enum(') === 0) {
                    if ($old_status === null) { $old_status = ''; }
                    $enumVals = [];
                    if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                        $raw = $m[1];
                        $parts = array_map('trim', explode(',', $raw));
                        $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                    }
                    if (!empty($enumVals) && !in_array($status, $enumVals, true)) {
                        $mapEsEn = [
                            'pendiente' => 'pending',
                            'asignado' => 'received',
                            'diagnosticando' => 'diagnosing',
                            'esperando_repuestos' => 'waiting_parts',
                            'reparando' => 'repairing',
                            'testeando' => 'testing',
                            'completado' => 'completed',
                            'entregado' => 'delivered',
                            'cancelado' => 'cancelled'
                        ];
                        $mapEnEs = array_flip($mapEsEn);
                        if (isset($mapEsEn[$status]) && in_array($mapEsEn[$status], $enumVals, true)) {
                            $status = $mapEsEn[$status];
                        } elseif (isset($mapEnEs[$status]) && in_array($mapEnEs[$status], $enumVals, true)) {
                            $status = $mapEnEs[$status];
                        } elseif (in_array('pending', $enumVals, true)) {
                            $status = 'pending';
                        } elseif (in_array('pendiente', $enumVals, true)) {
                            $status = 'pendiente';
                        } else {
                            $status = $enumVals[0];
                        }
                    }
                }
            } catch (Throwable $__) {}
            
            // Actualizar orden
            if ($perDatabase) {
                $prevStmt = $pdo->prepare("SELECT estimated_cost, approval_status, status FROM work_orders WHERE id = ? LIMIT 1");
                $prevStmt->execute([$order_id]);
            } else {
                $prevStmt = $pdo->prepare("SELECT estimated_cost, approval_status, status FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                $prevStmt->execute([$order_id, $tenant_id]);
            }
            $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['estimated_cost' => null, 'approval_status' => 'none', 'status' => null];
            $prevEst = isset($prevRow['estimated_cost']) ? (float)$prevRow['estimated_cost'] : null;
            $prevAp = strtolower((string)($prevRow['approval_status'] ?? 'none'));
            $needsResetApproval = false;
            $oldFmt = $prevEst !== null ? number_format((float)$prevEst, 2, '.', '') : null;
            $newFmt = $estimated_cost !== null ? number_format((float)$estimated_cost, 2, '.', '') : null;
            if ($oldFmt !== null && $newFmt !== null && $oldFmt !== $newFmt && in_array($prevAp, ['approved','rejected','aprobado','rechazado'], true)) {
                $needsResetApproval = true;
                // Forzar status a 'pendiente' o 'pending' según ENUM para que la lista use el estado efectivo de aprobación
                try {
                    $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                    $ctStmt->execute();
                    $colType = (string)$ctStmt->fetchColumn();
                    $enumVals = [];
                    if ($colType && stripos($colType, 'enum(') === 0) {
                        if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                            $raw = $m[1];
                            $parts = array_map('trim', explode(',', $raw));
                            $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                        }
                    }
                    if (!empty($enumVals)) {
                        if (in_array('pending', $enumVals, true)) {
                            $status = 'pending';
                        } elseif (in_array('pendiente', $enumVals, true)) {
                            $status = 'pendiente';
                        } else {
                            $status = $enumVals[0];
                        }
                    } else {
                        $status = 'pending';
                    }
                } catch (Throwable $__) {
                    $status = 'pending';
                }
            }
            if (!$needsResetApproval && isset($requestedWaitingApproval) && $requestedWaitingApproval) {
                $needsResetApproval = true;
                try {
                    $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                    $ctStmt->execute();
                    $colType = (string)$ctStmt->fetchColumn();
                    $enumVals = [];
                    if ($colType && stripos($colType, 'enum(') === 0) {
                        if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                            $raw = $m[1];
                            $parts = array_map('trim', explode(',', $raw));
                            $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                        }
                    }
                    if (!empty($enumVals)) {
                        if (in_array('pending', $enumVals, true)) {
                            $status = 'pending';
                        } elseif (in_array('pendiente', $enumVals, true)) {
                            $status = 'pendiente';
                        } else {
                            $status = $enumVals[0];
                        }
                    } else {
                        $status = 'pending';
                    }
                } catch (Throwable $__) {
                    $status = 'pending';
                }
            }
            if (!$needsResetApproval) {
                if (
                    ($prevAp === 'none' || $prevAp === '' || $prevAp === 'pending' || $prevAp === 'pendiente') &&
                    (
                        ($oldFmt === null && $newFmt !== null) ||
                        ($oldFmt !== null && $newFmt !== null && $oldFmt !== $newFmt)
                    )
                ) {
                    $needsResetApproval = true;
                    try {
                        $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                        $ctStmt->execute();
                        $colType = (string)$ctStmt->fetchColumn();
                        $enumVals = [];
                        if ($colType && stripos($colType, 'enum(') === 0) {
                            if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                                $raw = $m[1];
                                $parts = array_map('trim', explode(',', $raw));
                                $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                            }
                        }
                        if (!empty($enumVals)) {
                            if (in_array('pending', $enumVals, true)) {
                                $status = 'pending';
                            } elseif (in_array('pendiente', $enumVals, true)) {
                                $status = 'pendiente';
                            } else {
                                $status = $enumVals[0];
                            }
                        } else {
                            $status = 'pending';
                        }
                    } catch (Throwable $__) {
                        $status = 'pending';
                    }
                }
            }

            // Verificar si existe la columna approved_quote_amount para reiniciarla cuando aplique
            $hasApprovedAmountCol = hasColumnCached($pdo, 'work_orders', 'approved_quote_amount');
            try {
                if ($perDatabase && !hasColumnCached($pdo, 'work_orders', 'approval_status')) {
                    $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER status");
                }
            } catch (Throwable $__) {}
            try {
                if (!hasColumnCached($pdo, 'work_orders', 'client_observations')) {
                    $pdo->exec("ALTER TABLE work_orders ADD COLUMN client_observations TEXT NULL AFTER reported_issue");
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
                        $_SESSION['schema_cache_cols']['work_orders_client_observations'] = true;
                    }
                }
            } catch (Throwable $__) {}

            $hasClientObsCol = hasColumnCached($pdo, 'work_orders', 'client_observations');
            $sql = "UPDATE work_orders SET 
                        client_id = ?, device_type_id = ?, device_brand = ?, device_model = ?, device_password = ?,
                        serial_number = ?, reported_issue = ?," . ($hasClientObsCol ? " client_observations = ?," : "") . " diagnosis = ?, solution = ?,
                        priority = ?, status = ?, estimated_cost = ?, final_cost = ?,
                        estimated_completion = ?, technician_notes = ?, device_photo = ?, 
                        advance_payment = ?, payment_method = ?, payment_reference = ?, updated_at = CURRENT_TIMESTAMP";
            if ($needsResetApproval) {
                $sql .= ", approval_status = 'pending', approved_at = NULL, approval_signature_path = NULL, approval_comment = NULL";
                if ($hasApprovedAmountCol) {
                    $sql .= ", approved_quote_amount = NULL";
                }
            }
            $sql .= $perDatabase ? " WHERE id = ?" : " WHERE id = ? AND tenant_id = ?";
            
            $photos_json = !empty($all_photos) ? json_encode($all_photos) : null;
            
            // Obtener el estado anterior ANTES de actualizar
            $old_status_sql = $perDatabase
                ? "SELECT status FROM work_orders WHERE id = ?"
                : "SELECT status FROM work_orders WHERE id = ? AND tenant_id = ?";
            $old_status_stmt = $pdo->prepare($old_status_sql);
            $old_status_stmt->execute($perDatabase ? [$order_id] : [$order_id, $tenant_id]);
            $old_status = $old_status_stmt->fetchColumn();
            
            $stmt = $pdo->prepare($sql);
            $params = [
                $client_id, $device_type_id, $device_brand, $device_model, $device_password,
                $serial_number, $reported_issue
            ];
            if ($hasClientObsCol) {
                $params[] = $client_observations !== '' ? $client_observations : null;
            }
            $params = array_merge($params, [
                $diagnosis, $solution,
                $priority, $status, $estimated_cost, $final_cost,
                $estimated_completion, $technician_notes, $photos_json,
                $total_advance, $final_payment_method, $final_payment_reference, $order_id
            ]);
            if (!$perDatabase) { $params[] = $tenant_id; }
            $stmt->execute($params);
            
            // Actualizar historial de estados si el estado cambió
            if ($old_status !== $status) {
                try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $e) {}
                $hasTenantHistory = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_status_history') : false;
                $history_sql = $hasTenantHistory
                    ? "INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
                    : "INSERT INTO order_status_history (order_id, status, notes, changed_by, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
                $history_notes = "Estado cambiado de '" . getStatusText($old_status) . "' a '" . getStatusText($status) . "'";
                $history_stmt = $pdo->prepare($history_sql);
                $history_params = [$order_id, $status, $history_notes, $_SESSION['user_id']];
                if ($hasTenantHistory) { $history_params[] = $tenant_id; }
                $history_stmt->execute($history_params);
            }
            
            // Guardar accesorios del equipo
            try {
                $hasTenantCol = hasTenantColumnCached($pdo, 'order_equipment_accessories');
                if ($hasTenantCol) {
                    $delete_stmt = $pdo->prepare("DELETE FROM order_equipment_accessories WHERE order_id = ? AND tenant_id = ?");
                    $delete_stmt->execute([$order_id, $tenant_id]);
                } else {
                    $delete_stmt = $pdo->prepare("DELETE FROM order_equipment_accessories WHERE order_id = ?");
                    $delete_stmt->execute([$order_id]);
                }
                
                if (!empty($accessories_data)) {
                    if ($hasTenantCol) {
                        $insert_stmt = $pdo->prepare("INSERT INTO order_equipment_accessories (tenant_id, order_id, accessory_id, is_included, condition_notes) VALUES (?, ?, ?, ?, ?)");
                        foreach ($accessories_data as $accessory_id => $accessory_info) {
                            $is_included = isset($accessory_info['is_included']) ? 1 : 0;
                            $condition_notes = ''; 
                            $insert_stmt->execute([$tenant_id, $order_id, $accessory_id, $is_included, $condition_notes]);
                        }
                    } else {
                        $insert_stmt = $pdo->prepare("INSERT INTO order_equipment_accessories (order_id, accessory_id, is_included, condition_notes) VALUES (?, ?, ?, ?)");
                        foreach ($accessories_data as $accessory_id => $accessory_info) {
                            $is_included = isset($accessory_info['is_included']) ? 1 : 0;
                            $condition_notes = ''; 
                            $insert_stmt->execute([$order_id, $accessory_id, $is_included, $condition_notes]);
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error al guardar accesorios: " . $e->getMessage());
            }
            
            header('Location: view.php?id=' . $order_id . '&success=' . urlencode('Orden actualizada exitosamente'));
            exit();
        }
    } catch (PDOException $e) {
        $errors[] = 'Error al actualizar la orden: ' . $e->getMessage();
    }
}

// Obtener datos (Consultas SQL existentes)
try {
    if ($perDatabase) {
        $sql = "SELECT wo.*, 
                       c.client_type, c.first_name, c.company_name, 
                       c.phone, c.email, c.address, c.id_number,
                       dt.name as device_type_name
                FROM work_orders wo
                LEFT JOIN clients c ON wo.client_id = c.id
                LEFT JOIN device_types dt ON wo.device_type_id = dt.id
                WHERE wo.id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
    } else {
        $sql = "SELECT wo.*, 
                       c.client_type, c.first_name, c.company_name, 
                       c.phone, c.email, c.address, c.id_number,
                       dt.name as device_type_name
                FROM work_orders wo
                LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id
                LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id
                WHERE wo.id = ? AND wo.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id, $tenant_id]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: index.php?error=' . urlencode('Orden no encontrada'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar la orden: ' . $e->getMessage()));
    exit();
}

// Número de orden formateado con prefijo
$order_num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
$order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
$order_display = $order_prefix . '-' . str_pad($order_num, 4, '0', STR_PAD_LEFT);
$page_title = $order_display;

// Obtener listas para selects
// Inicializar variables para evitar undefined variable
    $clients = [];
    $device_types = [];
    $statuses = [];
    $brands = [];
    $models = [];
    $payment_methods = [];
    $equipment_accessories = [];

    // Obtener listas para selects - Bloques separados para aislar errores

    // Clientes
    try {
        if ($perDatabase) {
            $clients_stmt = $pdo->prepare("SELECT id, client_type, first_name, company_name, phone FROM clients ORDER BY CASE WHEN client_type = 'company' THEN company_name ELSE first_name END");
            $clients_stmt->execute([]);
        } else {
            $clients_stmt = $pdo->prepare("SELECT id, client_type, first_name, company_name, phone FROM clients WHERE tenant_id = ? ORDER BY CASE WHEN client_type = 'company' THEN company_name ELSE first_name END");
            $clients_stmt->execute([$tenant_id]);
        }
        $clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando clientes: " . $e->getMessage());
    }
        
    // Tipos de dispositivos
    try {
        $hasActive = hasColumnCached($pdo, 'device_types', 'is_active');
        $hasVisible = hasColumnCached($pdo, 'device_types', 'is_visible');
        $hasSortOrder = hasColumnCached($pdo, 'device_types', 'sort_order');
        $where = [];
        if ($hasActive) { $where[] = "is_active = 1"; }
        if ($hasVisible) { $where[] = "is_visible = 1"; }
        if (!$perDatabase) {
            $where[] = "tenant_id = ?";
        }
        $orderBy = $hasSortOrder ? "ORDER BY sort_order, name" : "ORDER BY name";
        $device_types_stmt = $pdo->prepare("SELECT id, name FROM device_types WHERE " . implode(" AND ", $where) . " $orderBy");
        $device_types_stmt->execute(!$perDatabase ? [$tenant_id] : []);
        $device_types = $device_types_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando tipos de dispositivos: " . $e->getMessage());
    }
        
    // Estados
    try {
        if (hasTenantColumnCached($pdo, 'order_statuses')) {
            $sql = "SELECT slug, name, emoji, color, is_default, sort_order 
                    FROM order_statuses 
                    WHERE is_active = 1
                    ORDER BY sort_order, name";
            if (!$perDatabase) {
                $sql = "SELECT slug, name, emoji, color, is_default, sort_order 
                        FROM order_statuses 
                        WHERE is_active = 1 AND tenant_id = ?
                        ORDER BY sort_order, name";
            }
            $statuses_stmt = $pdo->prepare($sql);
            $statuses_stmt->execute($perDatabase ? [] : [$tenant_id]);
            $statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $statuses_stmt = $pdo->query("SELECT slug, name, emoji, color, sort_order FROM order_statuses WHERE is_active = 1 ORDER BY sort_order, name");
            $statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error cargando estados: " . $e->getMessage());
    }
        
    if (empty($statuses)) {
            $statuses = [
            ['slug' => 'pendiente', 'name' => 'Pendiente', 'color' => '#ffc107', 'emoji' => '⏳'],
            ['slug' => 'asignado', 'name' => 'Asignado', 'color' => '#6cc4ea', 'emoji' => '📥'],
            ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'color' => '#fd7e14', 'emoji' => '🔍'],
            ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'color' => '#6f42c1', 'emoji' => '⏸️'],
            ['slug' => 'reparando', 'name' => 'Reparando', 'color' => '#007bff', 'emoji' => '🔧'],
            ['slug' => 'testeando', 'name' => 'Testeando', 'color' => '#17a2b8', 'emoji' => '🧪'],
            ['slug' => 'completado', 'name' => 'Completado', 'color' => '#28a745', 'emoji' => '✅'],
            ['slug' => 'entregado', 'name' => 'Entregado', 'color' => '#6c757d', 'emoji' => '🚚'],
            ['slug' => 'cancelado', 'name' => 'Cancelado', 'color' => '#dc3545', 'emoji' => '❌']
        ];
    }
    try {
        if ($perDatabase) {
            $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_statuses_template' LIMIT 1");
            $tplStmt->execute([]);
        } else {
            $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_statuses_template' LIMIT 1");
            $tplStmt->execute([$tenant_id]);
        }
        $tplJson = (string)$tplStmt->fetchColumn();
        if ($tplJson) {
            $tplArr = json_decode($tplJson, true);
            if (is_array($tplArr) && count($tplArr) > 0) {
                $pos = [];
                foreach ($tplArr as $i => $row) {
                    $slug = strtolower(trim((string)($row['slug'] ?? '')));
                    if ($slug !== '') { $pos[$slug] = $i; }
                }
                usort($statuses, function($a, $b) use ($pos) {
                    $sa = strtolower(trim($a['slug'] ?? ''));
                    $sb = strtolower(trim($b['slug'] ?? ''));
                    $pa = array_key_exists($sa, $pos) ? $pos[$sa] : PHP_INT_MAX;
                    $pb = array_key_exists($sb, $pos) ? $pos[$sb] : PHP_INT_MAX;
                    if ($pa === $pb) {
                        $oa = (int)($a['sort_order'] ?? 0);
                        $ob = (int)($b['sort_order'] ?? 0);
                        if ($oa === $ob) return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                        return $oa <=> $ob;
                    }
                    return $pa <=> $pb;
                });
            }
        }
    } catch (Throwable $e) {}

    // Marcas
    try {
        $hasActive = hasColumnCached($pdo, 'brands', 'is_active');
        if ($perDatabase) {
            $brands_stmt = $pdo->prepare("SELECT id, name FROM brands WHERE " . ($hasActive ? "is_active = 1" : "1=1") . " ORDER BY name");
            $brands_stmt->execute([]);
        } else {
            $brands_stmt = $pdo->prepare("SELECT id, name FROM brands WHERE " . ($hasActive ? "is_active = 1 AND " : "") . "tenant_id = ? ORDER BY name");
            $brands_stmt->execute([$tenant_id]);
        }
        $brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando marcas: " . $e->getMessage());
    }
    if (empty($brands)) {
        try {
            if ($perDatabase) {
                $brands_stmt = $pdo->prepare("SELECT id, name FROM brands ORDER BY name");
                $brands_stmt->execute([]);
            } else {
                $brands_stmt = $pdo->prepare("SELECT id, name FROM brands WHERE tenant_id = ? ORDER BY name");
                $brands_stmt->execute([$tenant_id]);
            }
            $brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
        
    // Métodos de pago
    try {
        if ($perDatabase) {
            $payment_methods_stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE status = 'active' ORDER BY name");
            $payment_methods_stmt->execute([]);
        } else {
            $payment_methods_stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE status = 'active' AND tenant_id = ? ORDER BY name");
            $payment_methods_stmt->execute([$tenant_id]);
        }
        $payment_methods = $payment_methods_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando métodos de pago: " . $e->getMessage());
    }

    // Asegurar efectivo
    $has_efectivo = false;
    foreach ($payment_methods as $pm) {
        if (strcasecmp($pm['name'], 'Efectivo') === 0) { $has_efectivo = true; break; }
    }
    if (!$has_efectivo) {
        array_unshift($payment_methods, ['id' => 0, 'name' => 'Efectivo']);
    }

    // Modelos
    try {
        $hasActive = hasColumnCached($pdo, 'models', 'is_active');
        if ($perDatabase) {
            $models_stmt = $pdo->prepare("SELECT id, name, brand_id FROM models WHERE " . ($hasActive ? "is_active = 1" : "1=1") . " ORDER BY name");
            $models_stmt->execute([]);
        } else {
            $models_stmt = $pdo->prepare("SELECT id, name, brand_id FROM models WHERE " . ($hasActive ? "is_active = 1 AND " : "") . "tenant_id = ? ORDER BY name");
            $models_stmt->execute([$tenant_id]);
        }
        $models = $models_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando modelos: " . $e->getMessage());
    }
        
    // Accesorios
    // Asegurar esquema de accesorios con tenant_id
    if (function_exists('ensureAccessoriesTenant')) {
        ensureAccessoriesTenant($pdo, $tenant_id);
    }
    if (function_exists('normalizeAccessoriesTenants')) {
        normalizeAccessoriesTenants($pdo, $tenant_id);
    }
    $fn = 'ensureDeviceTypesTenant'; if (function_exists($fn)) { $fn($pdo, $tenant_id); }
    $fn = 'ensureBrandsTenant'; if (function_exists($fn)) { $fn($pdo, $tenant_id); }
    $fn = 'ensureModelsTenant'; if (function_exists($fn)) { $fn($pdo, $tenant_id); }
    $hasTenantOEA = hasTenantColumnCached($pdo, 'order_equipment_accessories');
    $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
    $accessories_sql = "SELECT ea.id, ea.name, ea.description, ea.category, ea.sort_order, COALESCE(oea.is_included, 0) as is_included, oea.condition_notes FROM equipment_accessories ea LEFT JOIN order_equipment_accessories oea ON ea.id = oea.accessory_id AND oea.order_id = ?";
    $params = [$order_id];
    if (!$perDatabase) {
        if ($hasTenantOEA) { $accessories_sql .= " AND oea.tenant_id = ?"; $params[] = $tenant_id; }
    }
    $accessories_sql .= " WHERE ea.is_active = 1";
    if (!$perDatabase) {
        if ($hasTenantEA) { $accessories_sql .= " AND ea.tenant_id = ?"; $params[] = $tenant_id; }
    }
    $accessories_sql .= " ORDER BY ea.sort_order ASC, ea.name ASC";
    try {
        $stmtAcc = $pdo->prepare($accessories_sql);
        $stmtAcc->execute($params);
        $equipment_accessories = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando accesorios: " . $e->getMessage());
    }
    if (empty($equipment_accessories) && function_exists('ensureDefaultAccessories')) {
        ensureDefaultAccessories($pdo, $tenant_id);
        try {
            $stmtAcc2 = $pdo->prepare($accessories_sql);
            $stmtAcc2->execute($params);
            $equipment_accessories = $stmtAcc2->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error reconsultando accesorios: " . $e->getMessage());
        }
    }

    // Historial de Pagos
    $order_payments = [];
    try {
        // Buscar pagos que contengan "Orden #ID" en la descripción
        // Esto cubrirá "Abono inicial Orden #ID" y "Abono adicional Orden #ID"
        if ($perDatabase) {
            $stmtPayments = $pdo->prepare("SELECT * FROM cash_income WHERE (description LIKE ? OR description LIKE ?) ORDER BY created_at DESC");
            $stmtPayments->execute(['%Orden #' . $order_id . '%', '%' . $order_display . '%']);
        } else {
            $stmtPayments = $pdo->prepare("SELECT * FROM cash_income WHERE (description LIKE ? OR description LIKE ?) AND tenant_id = ? ORDER BY created_at DESC");
            $stmtPayments->execute(['%Orden #' . $order_id . '%', '%' . $order_display . '%', $tenant_id]);
        }
        $order_payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando historial de pagos: " . $e->getMessage());
    }

$page_title = 'Editar ' . htmlspecialchars($order_display);
$additional_css = [];
$additional_js = [];
ob_start();
?>
<style>
        .photo-card {
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }
        .photo-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
        }
        .photo-card img {
            height: 140px;
            object-fit: contain;
            width: 100%;
            background: #000;
            transition: transform 0.3s;
        }
        .photo-card:hover img {
            transform: scale(1.05);
        }
        .photo-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 10;
        }
        .photo-card:hover .photo-actions {
            opacity: 1;
        }
    </style>
        <div class="">
            <!-- Formulario principal que envuelve todo -->
            <form method="POST" id="orderForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="captured_photos_data" name="captured_photos_data">
                <input type="hidden" id="photos_to_remove" name="photos_to_remove">

                <!-- Encabezado de página -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
                    <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="fas fa-edit me-2 text-primary no-theme"></i>Editar <?php echo htmlspecialchars($order_display); ?></h2>
                        <p class="text-muted mb-0">Actualizar información de la orden de servicio</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="view.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-dark rounded-pill shadow-sm">
                            <i class="fas fa-eye me-2"></i>Ver Orden
                        </a>
                        <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4">
                            <i class="fas fa-save me-2"></i>Guardar Cambios
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill shadow-sm">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </a>
                    </div>
                </div>

               

                <!-- Mostrar errores -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-4 border-0 mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <strong>Error:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Columna Principal (Izquierda) -->
                    <div class="col-lg-8">
                        
                        <!-- Estado y Prioridad -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Estado y Prioridad</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <label for="status" class="form-label fw-bold small text-uppercase text-muted">Estado</label>
                                        <select class="form-select rounded-pill" id="status" name="status" required>
                                            <?php
                                                $currentRaw = strtolower(trim((string)($order['status'] ?? '')));
                                                $apSlug = strtolower(trim((string)($order['approval_status'] ?? '')));
                                                $aliases = [
                                                    'pending' => 'pendiente',
                                                    'received' => 'asignado',
                                                    'diagnosing' => 'diagnosticando',
                                                    'waiting_parts' => 'esperando_repuestos',
                                                    'repairing' => 'reparando',
                                                    'testing' => 'testeando',
                                                    'completed' => 'completado',
                                                    'delivered' => 'entregado',
                                                    'cancelled' => 'cancelado',
                                                    'canceled' => 'cancelado'
                                                ];
                                                $currentSlug = $aliases[$currentRaw] ?? $currentRaw;
                                                if ($currentSlug === '' || $currentSlug === null) { $currentSlug = 'pendiente'; }
                                                if ($currentSlug === 'pendiente' && in_array($apSlug, ['approved','aprobado'], true)) {
                                                    $currentSlug = 'aprobado';
                                                }
                                            ?>
                                            <?php foreach ($statuses as $status_item): ?>
                                            <?php
                                                $raw = trim($status_item['emoji'] ?? '');
                                                $useDefault = ($raw === '' || preg_match('/^\?+$/', $raw));
                                                $map = [
                                                    'pendiente' => '⏳',
                                                    'asignado' => '📥',
                                                    'diagnosticando' => '🔍',
                                                    'aprobado' => '✍️',
                                                    'esperando_repuestos' => '⏸️',
                                                    'reparando' => '🔧',
                                                    'testeando' => '🧪',
                                                    'completado' => '✅',
                                                    'entregado' => '🚚',
                                                    'cancelado' => '❌',
                                                    'devolucion' => '↩️'
                                                ];
                                                $displayEmoji = $useDefault ? ($map[$status_item['slug']] ?? '❓') : $raw;
                                            ?>
                                            <option value="<?php echo $status_item['slug']; ?>" <?php echo $currentSlug == $status_item['slug'] ? 'selected' : ''; ?>>
                                                <?php echo $displayEmoji . ' ' . $status_item['name']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="priority" class="form-label fw-bold small text-uppercase text-muted">Prioridad</label>
                                        <select class="form-select rounded-pill" id="priority" name="priority" required>
                                            <option value="low" <?php echo $order['priority'] == 'low' ? 'selected' : ''; ?>>Baja</option>
                                            <option value="normal" <?php echo $order['priority'] == 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo $order['priority'] == 'high' ? 'selected' : ''; ?>>Alta</option>
                                            <option value="urgent" <?php echo $order['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgente</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Información del Dispositivo -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-mobile-alt me-2 text-primary no-theme"></i>Información del Dispositivo</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="device_type_search" class="form-label">Tipo de Dispositivo <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="text" class="form-control rounded-pill" id="device_type_search" placeholder="Buscar tipo de dispositivo..." autocomplete="off" required value="<?php echo htmlspecialchars($order['device_type_name']); ?>">
                                            <input type="hidden" id="device_type_id" name="device_type_id" value="<?php echo $order['device_type_id']; ?>" required>
                                            <div class="position-absolute end-0 top-50 translate-middle-y pe-3" style="cursor: pointer;">
                                                <i class="fas fa-chevron-down text-muted"></i>
                                            </div>
                                            <div id="device_type_dropdown" class="dropdown-menu w-100" style="max-height: 200px; overflow-y: auto; display: none; position: absolute; z-index: 1050; background-color: white; border: 1px solid #dee2e6; border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);"></div>
                                        </div>
                                        <?php if (!$perDatabase && empty($device_types)): ?>
                                        <div class="alert alert-warning mt-2 p-2">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Catálogo de tipos vacío en este tenant.</span>
                                                <a href="../catalogs/device_types.php" class="btn btn-sm btn-primary">Crear tipos</a>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="device_brand_search" class="form-label">Marca <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="text" class="form-control rounded-pill" id="device_brand_search" placeholder="Buscar o crear marca..." autocomplete="off" value="<?php echo htmlspecialchars($order['device_brand']); ?>">
                                            <input type="hidden" id="device_brand" name="device_brand" value="<?php echo htmlspecialchars($order['device_brand']); ?>" required>
                                            <div id="brand_dropdown" class="dropdown-menu w-100" style="max-height: 200px; overflow-y: auto; display: none; position: absolute; z-index: 1050; background-color: white; border: 1px solid #dee2e6; border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);"></div>
                                        </div>
                                        <?php if (!$perDatabase && empty($brands ?? [])): ?>
                                        <div class="alert alert-warning mt-2 p-2">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>No hay marcas en este tenant.</span>
                                                <a href="../catalogs/brands.php" class="btn btn-sm btn-primary">Crear marcas</a>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="device_model_search" class="form-label">Modelo <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="text" class="form-control rounded-pill" id="device_model_search" placeholder="Buscar o crear modelo..." autocomplete="off" value="<?php echo htmlspecialchars($order['device_model']); ?>">
                                            <input type="hidden" id="device_model" name="device_model" value="<?php echo htmlspecialchars($order['device_model']); ?>" required>
                                            <div id="model_dropdown" class="dropdown-menu w-100" style="max-height: 200px; overflow-y: auto; display: none; position: absolute; z-index: 1050; background-color: white; border: 1px solid #dee2e6; border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);"></div>
                                        </div>
                                        <?php if (!$perDatabase && empty($models ?? [])): ?>
                                        <div class="alert alert-warning mt-2 p-2">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>No hay modelos en este tenant.</span>
                                                <a href="../catalogs/models.php" class="btn btn-sm btn-primary">Crear modelos</a>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="serial_number" class="form-label">N° de Serie / IMEI / TAG <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control rounded-pill" id="serial_number" name="serial_number" required placeholder="S/N, IMEI o Service Tag" value="<?php echo htmlspecialchars($order['serial_number']); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label for="device_password" class="form-label">Clave de Acceso (PIN, Patrón o Contraseña)</label>
                                        <input type="text" class="form-control rounded-pill" id="device_password" name="device_password" placeholder="Opcional" value="<?php echo htmlspecialchars($order['device_password']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Detalles del Problema -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-exclamation-triangle me-2 text-primary no-theme"></i>Detalles del Servicio</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="reported_issue" class="form-label">Falla Reportada <span class="text-danger">*</span></label>
                                    <textarea class="form-control rounded-3" id="reported_issue" name="reported_issue" rows="3" required><?php echo htmlspecialchars($order['reported_issue']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="client_observations" class="form-label">Observaciones</label>
                                    <textarea class="form-control rounded-3" id="client_observations" name="client_observations" rows="2" placeholder="Observaciones para el cliente."><?php echo htmlspecialchars($order['client_observations'] ?? ''); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="diagnosis" class="form-label">Diagnóstico Técnico</label>
                                    <textarea class="form-control rounded-3" id="diagnosis" name="diagnosis" rows="3"><?php echo htmlspecialchars($order['diagnosis']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="solution" class="form-label">Solución Realizada</label>
                                    <textarea class="form-control rounded-3" id="solution" name="solution" rows="3"><?php echo htmlspecialchars($order['solution']); ?></textarea>
                                </div>
                                <div class="mb-0">
                                    <label for="technician_notes" class="form-label">Notas Internas</label>
                                    <textarea class="form-control rounded-3" id="technician_notes" name="technician_notes" rows="2" placeholder="Notas solo visibles para técnicos"><?php echo htmlspecialchars($order['technician_notes']); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Fotos -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3 d-flex justify-content-between align-items-center flex-wrap">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-camera me-2 text-primary no-theme"></i>Fotos del Dispositivo</h5>
                                <div class="d-flex align-items-center gap-2 flex-nowrap">
                                    <button type="button" class="btn btn-dark btn-sm rounded-pill" id="start-camera">
                                        <i class="fas fa-camera me-1"></i>Activar Cámara
                                    </button>
                                    <select class="form-select form-select-sm rounded-pill" id="upload-quality" name="upload_quality" style="width:auto; max-width: 140px;">
                                        <option value="85" selected>Calidad: Equilibrado</option>
                                        <option value="95">Calidad: Alta</option>
                                        <option value="75">Calidad: Ahorro</option>
                                    </select>
                                    <label class="btn btn-outline-dark btn-sm rounded-pill mb-0" for="device_photo">
                                        <i class="fas fa-upload me-1"></i>Subir
                                    </label>
                                    <input type="file" class="d-none" id="device_photo" name="device_photo[]" accept="image/*" multiple>
                                    <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Galería de fotos">
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_entry" value="entry" autocomplete="off" checked>
                                        <label class="btn btn-outline-dark rounded-pill" for="gal_entry">Entrada</label>
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_diag" value="diagnosis" autocomplete="off">
                                        <label class="btn btn-outline-dark rounded-pill" for="gal_diag">Diagnóstico</label>
                                        <input type="radio" class="btn-check" name="photo_gallery" id="gal_delivery" value="delivery" autocomplete="off">
                                        <label class="btn btn-outline-dark rounded-pill" for="gal_delivery">Entrega</label>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Área de cámara -->
                                <div id="camera-container" class="mb-3 d-none">
                                    <div class="position-relative bg-dark rounded-4 overflow-hidden shadow" style="width: 100%; max-height: 70vh;">
                                        <video id="camera-feed" autoplay playsinline class="w-100" style="object-fit: contain; background:#000; height: auto;"></video>
                                    <div class="position-absolute bottom-0 start-0 w-100 p-3">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <div class="d-flex align-items-center gap-4">
                                                <button type="button" title="Cambiar cámara" class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center shadow-sm d-none" id="switch-camera" style="width: 45px; height: 45px;">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                                <button type="button" title="Capturar" class="btn btn-light rounded-circle d-flex align-items-center justify-content-center shadow-lg" id="capture-btn" style="width: 75px; height: 75px; padding: 0;">
                                                    <div class="rounded-circle border border-dark border-2" style="width: 60px; height: 60px;"></div>
                                                </button>
                                                <button type="button" title="Cerrar cámara" class="btn btn-danger rounded-circle d-flex align-items-center justify-content-center shadow-sm" id="close-camera" style="width: 45px; height: 45px;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="position-absolute top-0 start-0 w-100 p-3">
                                        <style>
                                            .overlay-select {
                                                background: rgba(0,0,0,0.45) !important;
                                                color: #fff !important;
                                                border: 1px solid rgba(255,255,255,0.55) !important;
                                                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18), 0 2px 10px rgba(0,0,0,0.35) !important;
                                                outline: none !important;
                                            }
                                            .overlay-select:hover,
                                            .overlay-select:focus {
                                                background: rgba(0,0,0,0.65) !important;
                                                border-color: rgba(255,255,255,0.75) !important;
                                            }
                                            .overlay-select option {
                                                background-color: #222;
                                                color: #fff;
                                            }
                                        </style>
                                        <div class="d-flex justify-content-end align-items-center gap-2">
                                            <select class="form-select form-select-sm rounded-pill overlay-select" id="camera-resolution-overlay" style="width:auto; min-width: 160px">
                                                <option value="">Auto</option>
                                                <option value="640x480">SD (480p)</option>
                                                <option value="1280x720">HD (720p)</option>
                                                <option value="1920x1080" selected>FullHD (1080p)</option>
                                                <option value="3840x2160">4K (UltraHD)</option>
                                            </select>
                                            <select class="form-select form-select-sm rounded-pill overlay-select" id="camera-quality-overlay" style="width:auto; min-width: 180px">
                                                <option value="0.7">Calidad: Baja</option>
                                                <option value="0.85" selected>Calidad: Alta</option>
                                                <option value="0.95">Calidad: Full</option>
                                            </select>
                                        </div>
                                    </div>
                                    </div>
                                </div>

                                <!-- Drop Zone y Galería -->
                                <div id="photo-drop-zone" class="photo-upload-zone p-4 text-center cursor-pointer position-relative">
                                    <div id="drop-zone-prompt" class="<?php echo !empty($order['device_photo']) ? 'd-none' : ''; ?>">
                                        <div class="mb-3">
                                            <div class="rounded-circle bg-primary bg-opacity-10 no-theme d-inline-flex p-3">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-primary no-theme"></i>
                                            </div>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-1">Arrastra y suelta fotos aquí</h6>
                                        <p class="text-muted small mb-0">Soporta JPG, PNG, GIF</p>
                                    </div>
                                    
                                    <div id="photos-preview" class="row g-3 text-start w-100 m-0 <?php echo !empty($order['device_photo']) ? '' : 'd-none'; ?>">
                                    <?php
                                    // Fotos existentes
                                    if (!empty($order['device_photo'])) {
                                        $photos = json_decode($order['device_photo'], true);
                                        if (is_array($photos)) {
                                            $photos_by_cat = [
                                                'entry' => [], 
                                                'diagnosis' => [], 
                                                'delivery' => [], 
                                                'other' => []
                                            ];
                                            
                                            foreach ($photos as $photo) {
                                                if (strpos($photo, 'entry/') === 0) $photos_by_cat['entry'][] = $photo;
                                                elseif (strpos($photo, 'diagnosis/') === 0) $photos_by_cat['diagnosis'][] = $photo;
                                                elseif (strpos($photo, 'delivery/') === 0) $photos_by_cat['delivery'][] = $photo;
                                                else $photos_by_cat['other'][] = $photo;
                                            }

                                            foreach ($photos_by_cat as $cat => $cat_photos) {
                                                if (empty($cat_photos)) continue;
                                                
                                                $cat_label = match($cat) {
                                                    'entry' => '<i class="fas fa-sign-in-alt me-1"></i>Ingreso',
                                                    'diagnosis' => '<i class="fas fa-stethoscope me-1"></i>Diagnóstico',
                                                    'delivery' => '<i class="fas fa-check-circle me-1"></i>Entrega',
                                                    'other' => '<i class="fas fa-images me-1"></i>Otras'
                                                };

                                                $badge_text = match($cat) {
                                                    'entry' => 'INGRESO',
                                                    'diagnosis' => 'DIAGNÓSTICO',
                                                    'delivery' => 'ENTREGA',
                                                    'other' => 'OTRAS'
                                                };

                                                $badge_class = match($cat) {
                                                    'entry' => 'bg-primary text-primary border-primary',
                                                    'diagnosis' => 'bg-info text-info border-info',
                                                    'delivery' => 'bg-success text-success border-success',
                                                    'other' => 'bg-secondary text-secondary border-secondary'
                                                };

                                                echo '<div class="col-12 mt-2 mb-0 photo-category-header">
                                                        <h6 class="fw-bold text-muted small text-uppercase border-bottom pb-2">'.$cat_label.'</h6>
                                                      </div>';

                                                foreach ($cat_photos as $photo) {
                                                    $photoUrl = resolveOrderPhotoWebUrl((int)$order_id, $photo, '../uploads/');
                                                    echo '<div class="col-6 col-sm-4 col-md-3 existing-photo-item" data-filename="'.htmlspecialchars($photo).'">
                                                            <div class="photo-card shadow-sm rounded-3 bg-white h-100 border">
                                                                <div class="position-relative">
                                                                    <img src="'.htmlspecialchars($photoUrl).'" class="d-block rounded-top-3" alt="Foto" onerror="this.src=\'../assets/img/no-image.png\'">
                                                                    <div class="photo-actions">
                                                                        <button type="button" class="btn btn-danger btn-sm rounded-circle shadow-sm p-0 d-flex align-items-center justify-content-center remove-existing-photo" style="width: 28px; height: 28px;" title="Eliminar">
                                                                            <i class="fas fa-trash-alt fa-xs"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="p-2 border-top bg-light rounded-bottom-3">
                                                                         <span class="badge '.$badge_class.' no-theme bg-opacity-10 border border-opacity-25 w-100 d-block text-truncate" style="font-size: 0.65rem;">'.$badge_text.'</span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                          </div>';
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                    </div>
                                </div>
                            </div>
                        </div>



                    </div>

                    <!-- Columna Lateral (Derecha) -->
                    <div class="col-lg-4">
                        
                        <!-- Información del Cliente -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-user me-2 text-primary no-theme"></i>Cliente</h5>
                            </div>
                            <div class="card-body">
                                <!-- Buscador -->
                                <div class="mb-3">
                                    <div class="position-relative">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0 text-muted rounded-start-pill"><i class="fas fa-search"></i></span>
                                            <input type="text" 
                                                   class="form-control border-start-0 ps-0 rounded-end-pill bg-light" 
                                                   id="client_search" 
                                                   placeholder="Buscar cliente..." 
                                                   autocomplete="off"
                                                   value="<?php 
                                                       foreach ($clients as $client) {
                                                           if ($client['id'] == $order['client_id']) {
                                                               echo htmlspecialchars($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name']);
                                                               break;
                                                           }
                                                       }
                                                   ?>"
                                                   required>
                                        </div>
                                        <input type="hidden" id="client_id" name="client_id" value="<?php echo $order['client_id']; ?>" required>
                                        <div id="client_dropdown" class="dropdown-menu w-100 shadow rounded-3 border-0 mt-1" style="max-height: 200px; overflow-y: auto; display: none;"></div>
                                    </div>
                                    <div class="mt-2 text-end">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-medium" data-bs-toggle="modal" data-bs-target="#newClientModal">
                                            <i class="fas fa-plus me-1"></i>Nuevo Cliente
                                        </button>
                                    </div>
                                </div>

                                <!-- Info Cliente Seleccionado -->
                                <div id="client-info-section">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                                            <i class="fas fa-user fa-2x text-primary no-theme"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-0" id="selected-client-name">
                                                <?php 
                                                foreach ($clients as $client) {
                                                    if ($client['id'] == $order['client_id']) {
                                                        echo htmlspecialchars($client['client_type'] === 'company' ? $client['company_name'] : $client['first_name']);
                                                        break;
                                                    }
                                                }
                                                ?>
                                            </h6>
                                            <span class="badge bg-light text-dark border" id="selected-client-type">
                                                <?php echo $order['client_type'] === 'company' ? 'Empresa' : 'Persona Natural'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Teléfono</small>
                                        <span id="selected-client-phone" class="fw-medium">
                                            <?php echo $order['phone'] ? getCompanyFullPhone($order['phone']) : 'No especificado'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Identificación</small>
                                        <span id="selected-client-id-number" class="fw-medium">
                                            <?php echo htmlspecialchars($order['id_number'] ?: 'No especificado'); ?>
                                        </span>
                                    </div>

                                    <!-- Campos ocultos requeridos por JS existente -->
                                    <div id="client-info" class="d-none">
                                        <span id="client-phone"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Costos y Tiempos -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-dollar-sign me-2 text-primary no-theme"></i>Costos y Tiempos</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="estimated_cost" class="form-label">Costo Estimado</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0 rounded-start-pill"><?php echo $currency_config['symbol']; ?></span>
                                            <input type="text" class="form-control rounded-end-pill currency-input" id="estimated_cost" name="estimated_cost" value="<?php echo $order['estimated_cost'] ? formatCurrency($order['estimated_cost'], false) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="final_cost" class="form-label">Costo Final</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0 rounded-start-pill"><?php echo $currency_config['symbol']; ?></span>
                                            <input type="text" class="form-control rounded-end-pill currency-input" id="final_cost" name="final_cost" value="<?php echo $order['final_cost'] ? formatCurrency($order['final_cost'], false) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <label for="estimated_completion" class="form-label">Fecha Estimada de Entrega</label>
                                        <input type="datetime-local" class="form-control rounded-pill" id="estimated_completion" name="estimated_completion" value="<?php echo $order['estimated_completion'] ? date('Y-m-d\TH:i', strtotime($order['estimated_completion'])) : ''; ?>">
                                    </div>
                                </div>
                                
                                <hr class="my-4 border-light">
                                
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold mb-0 small text-uppercase text-muted"><i class="fas fa-list-check me-2"></i>Accesorios Recibidos</h6>
                                    <button type="button" class="btn btn-sm btn-dark no-theme rounded-pill shadow-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#newAccessoryModal" title="Agregar nuevo accesorio">
                                        <i class="fas fa-plus fa-xs text-white"></i>
                                    </button>
                                </div>
                                <div class="row g-2" id="accessories-container">
                                    <?php if (!empty($equipment_accessories)): ?>
                                        <?php foreach ($equipment_accessories as $accessory): ?>
                                            <?php 
                                            $is_checked = false;
                                            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                                // Si hubo un envío (error), usar datos del formulario
                                                $is_checked = isset($_POST['accessories'][$accessory['id']]['is_included']);
                                            } else {
                                                // Si es carga inicial, usar datos de BD
                                                $is_checked = !empty($accessory['is_included']);
                                            }
                                            ?>
                                            <div class="col-6 col-sm-4 col-md-6 col-lg-4">
                                                <div class="form-check">
                                                    <input class="form-check-input accessory-checkbox" type="checkbox" 
                                                           name="accessories[<?php echo $accessory['id']; ?>][is_included]" 
                                                           value="1" 
                                                           id="accessory_<?php echo $accessory['id']; ?>"
                                                           <?php echo $is_checked ? 'checked' : ''; ?>>
                                                    <label class="form-check-label cursor-pointer text-truncate w-100" for="accessory_<?php echo $accessory['id']; ?>" title="<?php echo htmlspecialchars($accessory['name']); ?>">
                                                        <small><?php echo htmlspecialchars($accessory['name']); ?></small>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="col-12">
                                            <div class="text-muted small fst-italic">
                                                No hay accesorios.
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Información de Anticipo -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white border-bottom border-light py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-wallet me-2 text-primary no-theme"></i>Abono / Anticipo</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($has_linked_invoice): ?>
                                <div class="alert alert-warning border-0 bg-warning-subtle text-warning-emphasis mb-4">
                                    <div class="d-flex">
                                        <i class="fas fa-lock fa-2x me-3"></i>
                                        <div>
                                            <h6 class="fw-bold mb-1">Abonos Bloqueados</h6>
                                            <p class="mb-0 small">Esta orden está vinculada a una factura emitida. Para modificar pagos o abonos, debe gestionar la factura correspondiente.</p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="row">
                                    <!-- Total Abonado (Read Only) -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label text-muted">Total Abonado</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0 rounded-start-pill"><?php echo $currency_config['symbol']; ?></span>
                                            <input type="text" class="form-control rounded-end-pill bg-light" 
                                                   value="<?php echo formatCurrency($order['advance_payment'], false); ?>" readonly>
                                            <input type="hidden" id="current_advance_payment" value="<?php echo $order['advance_payment']; ?>">
                                        </div>
                                        <?php if (!empty($order['payment_reference'])): ?>
                                            <div class="form-text text-muted small mt-1">
                                                <i class="fas fa-hashtag me-1"></i>Ref. Inicial: <?php echo htmlspecialchars($order['payment_reference']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Nuevo Abono -->
                                    <div class="col-md-4 mb-3">
                                        <label for="new_abono" class="form-label fw-bold text-success">Agregar Nuevo Abono</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-success text-success border-end-0 rounded-start-pill"><i class="fas fa-plus"></i></span>
                                            <input type="text" class="form-control rounded-end-pill border-success currency-input" id="new_abono" name="new_abono" 
                                                   placeholder="0" oninput="formatCurrencyInput(this); calculateBalance();"
                                                   <?php echo $has_linked_invoice ? 'disabled' : ''; ?>>
                                        </div>
                                    </div>

                                    <!-- Método de Pago -->
                                    <div class="col-md-4 mb-3">
                                        <label for="payment_method" class="form-label">Método de Pago</label>
                                        <?php if (empty($payment_methods)): ?>
                                        <div class="alert alert-warning" role="alert">
                                            No hay métodos de pago configurados para este tenant. 
                                            <a href="../billing/payment_methods.php" target="_blank" class="alert-link">Configurar métodos</a>
                                        </div>
                                        <?php endif; ?>
                                        <select class="form-select" id="payment_method" name="payment_method" <?php echo $has_linked_invoice ? 'disabled' : ''; ?>>
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($payment_methods as $pm): ?>
                                                <option value="<?php echo htmlspecialchars($pm['name']); ?>"
                                                        <?php 
                                                        $is_selected = false;
                                                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                                            $is_selected = (isset($_POST['payment_method']) && $_POST['payment_method'] === $pm['name']);
                                                        } else {
                                                            $is_selected = ($pm['name'] === 'Efectivo');
                                                        }
                                                        echo $is_selected ? 'selected' : ''; 
                                                        ?>>
                                                    <?php echo htmlspecialchars($pm['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Para el nuevo abono</div>
                                    </div>

                                    <!-- Referencia de Pago (Nuevo) -->
                                    <div class="col-md-4 mb-3">
                                        <label for="payment_reference" class="form-label">N° de Referencia / Comprobante <small class="text-muted">(Opcional)</small></label>
                                        <input type="text" class="form-control" id="payment_reference" name="payment_reference" placeholder="Ej: 123456789" <?php echo $has_linked_invoice ? 'disabled' : ''; ?>>
                                    </div>
                                    
                                    <!-- Saldo Pendiente (Calculated) -->
                                    <div class="col-12 mt-2">
                                         <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                                            <span><i class="fas fa-calculator me-2"></i>Saldo Pendiente:</span>
                                            <span class="fw-bold fs-5" id="remaining_balance">
                                                <?php 
                                                $cost = $order['final_cost'] ?: $order['estimated_cost'] ?: 0;
                                                echo formatCurrency(max(0, $cost - $order['advance_payment'])); 
                                                ?>
                                            </span>
                                         </div>
                                    </div>

                                    <!-- Historial de Abonos -->
                                    <div class="col-12 mt-4">
                                        <h6 class="fw-bold mb-3"><i class="fas fa-history me-2"></i>Historial de Pagos</h6>
                                        <?php if (empty($order_payments)): ?>
                                            <p class="text-muted small fst-italic">No hay pagos registrados en caja para esta orden.</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle">
                                                    <thead class="table-light text-secondary text-uppercase small">
                                                        <tr>
                                                            <th class="border-0">Fecha</th>
                                                            <th class="border-0">Método</th>
                                                            <th class="border-0 text-end">Monto</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="border-top-0">
                                                        <?php foreach ($order_payments as $payment): ?>
                                                            <tr>
                                                                <td class="text-muted small">
                                                                    <i class="far fa-calendar-alt me-1"></i>
                                                                    <?php echo date('d/m/Y H:i', strtotime($payment['created_at'])); ?>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-light text-dark border fw-normal">
                                                                        <?php 
                                                                        $method_icon = 'fas fa-money-bill';
                                                                        if (stripos($payment['payment_method'], 'tarjeta') !== false) $method_icon = 'fas fa-credit-card';
                                                                        elseif (stripos($payment['payment_method'], 'transfer') !== false || stripos($payment['payment_method'], 'banc') !== false) $method_icon = 'fas fa-university';
                                                                        elseif (stripos($payment['payment_method'], 'nequi') !== false || stripos($payment['payment_method'], 'davi') !== false) $method_icon = 'fas fa-mobile-alt';
                                                                        ?>
                                                                        <i class="<?php echo $method_icon; ?> me-1"></i>
                                                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                                    </span>
                                                                    <?php if (!empty($payment['reference_number'])): ?>
                                                                        <div class="small text-muted mt-1 ms-1">
                                                                            <i class="fas fa-hashtag me-1" style="font-size: 0.8em;"></i><?php echo htmlspecialchars($payment['reference_number']); ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="fw-bold text-end text-success"><?php echo formatCurrency($payment['amount']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="border-top fw-bold bg-light">
                                                        <tr>
                                                            <td colspan="2" class="text-end text-secondary text-uppercase small py-3">Total Registrado:</td>
                                                            <td class="text-end text-success fs-6 py-3">
                                                                <?php 
                                                                $total_registered = array_sum(array_column($order_payments, 'amount'));
                                                                echo formatCurrency($total_registered); 
                                                                ?>
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="card mb-4 border-0 shadow-sm rounded-4 bg-light">
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary rounded-pill shadow-sm py-2">
                                        <i class="fas fa-save me-2"></i>Guardar Cambios
                                    </button>
                                    <a href="index.php" class="btn btn-outline-secondary rounded-pill border-0">
                                        Cancelar
                                    </a>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Nuevo Cliente -->
<?php include __DIR__ . '/../clients/modal_new_client.php'; ?>

<!-- Modal Nuevo Accesorio -->
<div class="modal fade" id="newAccessoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Nuevo Accesorio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <form id="newAccessoryForm">
                    <div class="mb-3">
                        <label for="new_accessory_name" class="form-label small text-muted text-uppercase fw-bold">Nombre del Accesorio</label>
                        <input type="text" class="form-control rounded-pill" id="new_accessory_name" required placeholder="Ej. Cargador, Funda...">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary rounded-pill">
                            <i class="fas fa-plus me-2"></i>Agregar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const newAccessoryForm = document.getElementById('newAccessoryForm');
    
    if(newAccessoryForm) {
        const newAccessoryModal = new bootstrap.Modal(document.getElementById('newAccessoryModal'));
        
        newAccessoryForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const nameInput = document.getElementById('new_accessory_name');
            const name = nameInput.value.trim();
            
            if(!name) return;
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...';
            
            const formData = new FormData();
            formData.append('name', name);
            const csrfTokenInput = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrf_token');
            if (csrfTokenInput) {
                formData.append('csrf_token', csrfTokenInput.value);
            }
            
            fetch('ajax_add_accessory.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if(data.success) {
                    // Agregar el nuevo checkbox
                    const container = document.getElementById('accessories-container');
                    const col = document.createElement('div');
                    col.className = 'col-6 col-sm-4 col-md-6 col-lg-4';
                    col.innerHTML = `
                        <div class="form-check">
                            <input class="form-check-input accessory-checkbox" type="checkbox" 
                                   name="accessories[${data.accessory.id}][is_included]" 
                                   value="1" 
                                   id="accessory_${data.accessory.id}"
                                   checked>
                            <label class="form-check-label cursor-pointer text-truncate w-100" for="accessory_${data.accessory.id}" title="${data.accessory.name}">
                                <small>${data.accessory.name}</small>
                            </label>
                        </div>
                    `;
                    
                    // Si había un mensaje de "No hay accesorios", quitarlo
                    const emptyMsg = container.querySelector('.text-muted.small.fst-italic');
                    if(emptyMsg && emptyMsg.closest('.col-12')) {
                        emptyMsg.closest('.col-12').remove();
                    }
                    
                    container.appendChild(col);
                    
                    // Limpiar y cerrar modal
                    nameInput.value = '';
                    newAccessoryModal.hide();
                    
                    // Forzar limpieza de backdrop
                    setTimeout(() => {
                        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }, 150);
                } else {
                    if (typeof showError === 'function') showError(data.message || 'Error al agregar accesorio');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showError === 'function') showError('Error al guardar: ' + error.message);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    }

    // Calculadora de Saldo Pendiente
    window.calculateBalance = function() {
        const estimatedCostInput = document.getElementById('estimated_cost');
        const finalCostInput = document.getElementById('final_cost');
        const currentAdvanceInput = document.getElementById('current_advance_payment');
        const newAbonoInput = document.getElementById('new_abono');
        const remainingBalanceSpan = document.getElementById('remaining_balance');
        const currencySymbol = '<?php echo $currency_config['symbol']; ?>';
        
        // Obtener costo (final tiene prioridad sobre estimado)
        let finalCostVal = finalCostInput ? finalCostInput.value.replace(/[^0-9.]/g, '') : '0';
        let estCostVal = estimatedCostInput ? estimatedCostInput.value.replace(/[^0-9.]/g, '') : '0';
        let newAbonoVal = newAbonoInput ? newAbonoInput.value.replace(/[^0-9.]/g, '') : '0';
        
        let finalCost = parseFloat(finalCostVal) || 0;
        let estCost = parseFloat(estCostVal) || 0;
        
        let cost = finalCost > 0 ? finalCost : estCost;
        
        let currentAdvance = parseFloat(currentAdvanceInput ? currentAdvanceInput.value : 0) || 0;
        let newAbono = parseFloat(newAbonoVal) || 0;
        
        let totalPaid = currentAdvance + newAbono;
        let balance = Math.max(0, cost - totalPaid);
        
        // Formatear salida
        if(remainingBalanceSpan) {
            remainingBalanceSpan.textContent = currencySymbol + balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    };

    // Agregar listeners a los campos de costo para recalcular
    const estCostInput = document.getElementById('estimated_cost');
    const finCostInput = document.getElementById('final_cost');
    const newAbonoIn = document.getElementById('new_abono');
    
    if(estCostInput) estCostInput.addEventListener('input', calculateBalance);
    if(finCostInput) finCostInput.addEventListener('input', calculateBalance);
    if(newAbonoIn) newAbonoIn.addEventListener('input', calculateBalance);
});
</script>

    <?php include '../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/modal-handlers.js"></script>
    <script src="../assets/js/device-autocomplete-advanced.js"></script>
    <script>
        (function(){
            var debug = <?php echo (isset($_GET['debug']) && $_GET['debug'] === '1') ? 'true' : 'false'; ?>;
            window.DEBUG = debug;
            if (!debug) {
                ['log','info','debug'].forEach(function(m){ try { console[m] = function(){}; } catch(e){} });
            }
        })();
    </script>
    <script>
        // Inicialización de Autocomplete
        document.addEventListener('DOMContentLoaded', function() {
            // Cliente Search (Existing Logic Wrapper)
            const clientSearchInput = document.getElementById('client_search');
            const clientIdInput = document.getElementById('client_id');
            const clientDropdown = document.getElementById('client_dropdown');
            let searchTimeout;

            function searchClients(searchTerm) {
                if (searchTerm.length < 2) {
                    clientDropdown.style.display = 'none';
                    return;
                }
                const formData = new FormData();
                formData.append('search', searchTerm);
                fetch('../clients/search_ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if(data.clients) displayClientResults(data.clients);
                    else clientDropdown.style.display = 'none';
                });
            }

            function displayClientResults(clients) {
                clientDropdown.innerHTML = '';
                if(clients.length === 0) {
                    clientDropdown.innerHTML = '<div class="p-2 text-muted">No encontrado</div>';
                } else {
                    clients.forEach(client => {
                        const item = document.createElement('a');
                        item.className = 'dropdown-item p-2 border-bottom';
                        item.href = '#';
                        item.innerHTML = `
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong class="text-dark">${client.id_number || 'Sin identificación'}</strong>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>${client.name} &bull; <i class="fas fa-phone me-1"></i>
                                        <br>
                                        ${client.phone || ''}
                                    </small>
                                </div>
                                <small class="badge bg-secondary text-uppercase">${client.client_type === 'company' ? 'Empresa' : 'Persona Natural'}</small>
                            </div>
                        `;
                        item.onclick = (e) => {
                            e.preventDefault();
                            selectClient(client);
                        };
                        clientDropdown.appendChild(item);
                    });
                }
                clientDropdown.style.display = 'block';
            }

            function selectClient(client) {
                clientSearchInput.value = client.name;
                clientIdInput.value = client.id;
                clientDropdown.style.display = 'none';
                
                // Actualizar info visual
                document.getElementById('selected-client-name').textContent = client.name;
                document.getElementById('selected-client-type').textContent = client.client_type === 'company' ? 'Empresa' : 'Persona Natural';
                document.getElementById('selected-client-phone').textContent = client.phone || 'No especificado';
                document.getElementById('selected-client-id-number').textContent = client.id_number || 'No especificado';
            }

            clientSearchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchClients(this.value), 300);
            });

            document.addEventListener('click', (e) => {
                if(!clientSearchInput.contains(e.target) && !clientDropdown.contains(e.target)) {
                    clientDropdown.style.display = 'none';
                }
            });

            // Device Autocomplete
            const deviceAutocomplete = new DeviceAutocomplete();

            // Camera & Photos Logic
            const startCameraBtn = document.getElementById('start-camera');
            const cameraContainer = document.getElementById('camera-container');
            const video = document.getElementById('camera-feed');
            const captureBtn = document.getElementById('capture-btn');
            const closeCameraBtn = document.getElementById('close-camera');
            const switchCameraBtn = document.getElementById('switch-camera');
            const photosPreview = document.getElementById('photos-preview');
            const dropZonePrompt = document.getElementById('drop-zone-prompt');
            const photoDropZone = document.getElementById('photo-drop-zone');
            const capturedPhotosInput = document.getElementById('captured_photos_data');
            const photosToRemoveInput = document.getElementById('photos_to_remove');
            const devicePhotoInput = document.getElementById('device_photo');
            
            let stream = null;
            let capturedPhotos = [];
            let existingPhotosToRemove = [];
            let videoDevices = [];
            let currentDeviceIndex = 0;
            let currentFacingMode = 'environment';

            function updateDropZoneState() {
                const hasPhotos = photosPreview.children.length > 0;
                if (hasPhotos) {
                    dropZonePrompt.classList.add('d-none');
                    photosPreview.classList.remove('d-none');
                } else {
                    dropZonePrompt.classList.remove('d-none');
                    photosPreview.classList.add('d-none');
                }
            }

            // Drag and Drop Events
            if (photoDropZone) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    photoDropZone.addEventListener(eventName, preventDefaults, false);
                });

                // Selector de calidad de subida (dropdown)
                (function() {
                    const menu = document.getElementById('uploadQualityMenu');
                    const btn = document.getElementById('uploadQualityDropdown');
                    const hidden = document.getElementById('upload-quality');
                    if (menu && btn && hidden) {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const open = !menu.classList.contains('show');
                            if (open) {
                                menu.classList.add('show');
                                menu.style.display = 'block';
                                btn.setAttribute('aria-expanded', 'true');
                            } else {
                                menu.classList.remove('show');
                                menu.style.display = 'none';
                                btn.setAttribute('aria-expanded', 'false');
                            }
                        });
                        document.addEventListener('click', function(e) {
                            if (!btn.contains(e.target) && !menu.contains(e.target)) {
                                menu.classList.remove('show');
                                menu.style.display = 'none';
                                btn.setAttribute('aria-expanded', 'false');
                            }
                        });
                        menu.querySelectorAll('[data-q]').forEach(item => {
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                const val = parseInt(this.dataset.q, 10);
                                hidden.value = isNaN(val) ? 85 : Math.max(50, Math.min(95, val));
                                const text = this.textContent.trim().split('(')[0].trim();
                                btn.textContent = 'Calidad: ' + text;
                                menu.classList.remove('show');
                                menu.style.display = 'none';
                                btn.setAttribute('aria-expanded', 'false');
                            });
                        });
                    }
                })();
                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    photoDropZone.addEventListener(eventName, highlight, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    photoDropZone.addEventListener(eventName, unhighlight, false);
                });

                function highlight(e) {
                    photoDropZone.classList.add('dragover');
                }

                function unhighlight(e) {
                    photoDropZone.classList.remove('dragover');
                }

                photoDropZone.addEventListener('drop', handleDrop, false);

                function handleDrop(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    handleFiles(files);
                }
            }

            function handleFiles(files) {
                const currentCategory = document.querySelector('input[name="photo_gallery"]:checked').value;
                const categoryLabels = {
                    'entry': 'INGRESO',
                    'diagnosis': 'DIAGNÓSTICO',
                    'delivery': 'ENTREGA'
                };
                const label = categoryLabels[currentCategory] || 'SUBIDA';

                ([...files]).forEach(file => {
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const dataUrl = e.target.result;
                            capturedPhotos.push({ data: dataUrl, category: currentCategory });
                            capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                            addPhotoPreview(dataUrl, true, label); 
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }

            async function initCameraByDeviceId(id) {
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                }
                const resSel = document.getElementById('camera-resolution-overlay') || document.getElementById('camera-resolution');
                let w = null, h = null;
                if (resSel && resSel.value && resSel.value.includes('x')) {
                    const parts = resSel.value.split('x');
                    w = parseInt(parts[0], 10);
                    h = parseInt(parts[1], 10);
                }
                const baseVideo = id ? { deviceId: { exact: id } } : { facingMode: 'environment' };
                if (w && h) {
                    baseVideo.width = { ideal: w };
                    baseVideo.height = { ideal: h };
                }
                const constraints = { video: baseVideo };
                stream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = stream;
            }

            if(startCameraBtn) {
                startCameraBtn.addEventListener('click', async () => {
                    try {
                        await initCameraByDeviceId(null);
                        const devices = await navigator.mediaDevices.enumerateDevices();
                        videoDevices = devices.filter(d => d.kind === 'videoinput');
                        currentDeviceIndex = 0;
                        
                        // Asegurar que el botón de cambio de cámara esté visible si hay dispositivos o como fallback
                        if (switchCameraBtn) {
                            switchCameraBtn.classList.remove('d-none');
                        }
                        
                        cameraContainer.classList.remove('d-none');
                        document.addEventListener('keydown', function(e) {
                            if (!cameraContainer.classList.contains('d-none')) {
                                if (e.code === 'Space') {
                                    e.preventDefault();
                                    if (captureBtn) captureBtn.click();
                                } else if (e.code === 'Escape') {
                                    e.preventDefault();
                                    if (closeCameraBtn) closeCameraBtn.click();
                                }
                            }
                        });
                    } catch (err) {
                        if (typeof showError === 'function') showError('No se pudo acceder a la cámara: ' + err.message);
                    }
                });
            }

            if(closeCameraBtn) {
                closeCameraBtn.addEventListener('click', () => {
                    if(stream) {
                        stream.getTracks().forEach(track => track.stop());
                        stream = null;
                    }
                    cameraContainer.classList.add('d-none');
                });
            }

            const resSelectorOverlay = document.getElementById('camera-resolution-overlay');
            if (resSelectorOverlay) {
                resSelectorOverlay.addEventListener('change', async () => {
                    if (stream) {
                        const currentDeviceId = videoDevices.length > 0 ? videoDevices[currentDeviceIndex].deviceId : null;
                        try {
                            await initCameraByDeviceId(currentDeviceId);
                        } catch (err) {
                            if (typeof showError === 'function') showError('No se pudo aplicar la resolución: ' + err.message);
                        }
                    }
                });
            }

            if (switchCameraBtn) {
                switchCameraBtn.addEventListener('click', async () => {
                    if (videoDevices.length > 1) {
                        currentDeviceIndex = (currentDeviceIndex + 1) % videoDevices.length;
                        const nextId = videoDevices[currentDeviceIndex].deviceId;
                        try {
                            await initCameraByDeviceId(nextId);
                        } catch (err) {
                            if (typeof showError === 'function') showError('No se pudo cambiar de cámara: ' + err.message);
                        }
                    } else {
                        // Fallback: Intentar cambiar modo (frontal/trasera) si no detectamos IDs múltiples
                        currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
                        if(stream) {
                            stream.getTracks().forEach(track => track.stop());
                            stream = null;
                        }
                        try {
                            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: currentFacingMode } });
                            video.srcObject = stream;
                        } catch (err) {
                            console.error(err);
                            if (typeof showError === 'function') showError('No se pudo cambiar el modo de cámara. Verifica permisos.');
                        }
                    }
                });
            }

            if(captureBtn) {
                captureBtn.addEventListener('click', () => {
                    const currentCategory = document.querySelector('input[name="photo_gallery"]:checked').value;
                    const categoryLabels = {
                        'entry': 'INGRESO',
                        'diagnosis': 'DIAGNÓSTICO',
                        'delivery': 'ENTREGA'
                    };
                    const label = categoryLabels[currentCategory] || 'CAPTURADA';

                    const vw = video.videoWidth;
                    const vh = video.videoHeight;
                    const canvas = document.createElement('canvas');
                    canvas.width = vw;
                    canvas.height = vh;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, vw, vh);
                    const qs = document.getElementById('camera-quality');
                    const q = qs && parseFloat(qs.value) ? parseFloat(qs.value) : 0.85;
                    const dataUrl = canvas.toDataURL('image/jpeg', q);
                    capturedPhotos.push({ data: dataUrl, category: currentCategory });
                    capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                    addPhotoPreview(dataUrl, true, label);
                });
            }

            devicePhotoInput.addEventListener('change', function(e) {
                if (this.files && this.files.length > 0) {
                    const currentCategory = document.querySelector('input[name="photo_gallery"]:checked').value;
                    const categoryLabels = {
                        'entry': 'INGRESO',
                        'diagnosis': 'DIAGNÓSTICO',
                        'delivery': 'ENTREGA'
                    };
                    const label = categoryLabels[currentCategory] || 'SUBIDA';

                    Array.from(this.files).forEach(file => {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const dataUrl = e.target.result;
                            capturedPhotos.push({ data: dataUrl, category: currentCategory });
                            capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                            addPhotoPreview(dataUrl, true, label); 
                        }
                        reader.readAsDataURL(file);
                    });
                    
                    // Reset input to allow more uploads or same file again
                    this.value = '';
                }
            });

            function addPhotoPreview(src, isBase64Stored, label) {
                const col = document.createElement('div');
                col.className = 'col-6 col-sm-4 col-md-3 photo-item';
                
                const l = (label || '').toLowerCase();
                let badgeClass = 'bg-secondary text-secondary border-secondary';
                if (l.includes('ingreso')) {
                    badgeClass = 'bg-primary text-primary border-primary';
                } else if (l.includes('diagnóstico') || l.includes('diagnostico')) {
                    badgeClass = 'bg-info text-info border-info';
                } else if (l.includes('entrega')) {
                    badgeClass = 'bg-success text-success border-success';
                }

                col.innerHTML = `
                    <div class="photo-card shadow-sm rounded-3 bg-white h-100 border">
                        <div class="position-relative">
                            <img src="${src}" class="d-block rounded-top-3" alt="Foto">
                            <div class="photo-actions">
                                <button type="button" class="btn btn-danger btn-sm rounded-circle shadow-sm p-0 d-flex align-items-center justify-content-center remove-photo" style="width: 28px; height: 28px;" title="Eliminar">
                                    <i class="fas fa-trash-alt fa-xs"></i>
                                </button>
                            </div>
                            <div class="p-2 border-top bg-light rounded-bottom-3">
                                 <span class="badge ${badgeClass} bg-opacity-10 border border-opacity-25 w-100 d-block text-truncate" style="font-size: 0.65rem;">${label}</span>
                            </div>
                        </div>
                    </div>
                `;
                
                // Logic to remove
                col.querySelector('.remove-photo').onclick = function() {
                    if(isBase64Stored) {
                        const index = capturedPhotos.findIndex(p => p.data === src);
                        if(index > -1) {
                            capturedPhotos.splice(index, 1);
                            capturedPhotosInput.value = JSON.stringify(capturedPhotos);
                        }
                    } else {
                         // It's from the file input. 
                         // Resetting file input is tricky for single files in multi-select.
                         // For now, we just remove the preview. The user might need to re-select if they messed up one file.
                         // A more robust solution would involve a DataTransfer object to reconstruct the file list.
                         // For this MVP, we accept this limitation or clear the input if all are removed.
                         if(photosPreview.querySelectorAll('.photo-item').length === 1) {
                             devicePhotoInput.value = '';
                         }
                    }
                    col.remove();
                    updateDropZoneState();
                };
                
                photosPreview.appendChild(col);
                updateDropZoneState();
            }

            // Remove Existing Photos
            document.querySelectorAll('.remove-existing-photo').forEach(btn => {
                btn.addEventListener('click', function() {
                    const item = this.closest('.existing-photo-item');
                    const filename = item.dataset.filename;
                    existingPhotosToRemove.push(filename);
                    photosToRemoveInput.value = JSON.stringify(existingPhotosToRemove);
                    item.remove();
                    updateDropZoneState();
                });
            });

        });

        function toggleClientFields() {
            const type = document.querySelector('input[name="modal_client_type"]:checked').value;
            if(type === 'company') {
                document.getElementById('modal-company-fields').style.display = 'block';
                document.getElementById('modal-individual-fields').style.display = 'none';
            } else {
                document.getElementById('modal-company-fields').style.display = 'none';
                document.getElementById('modal-individual-fields').style.display = 'block';
            }
        }
    </script>
<?php
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
