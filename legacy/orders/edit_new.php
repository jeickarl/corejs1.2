<?php
// Versión completamente nueva de orders/edit.php con manejo simple de fotos

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/session.php';
require_once '../config/performance_optimizer.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth('../login/index.php');

$pdo = db();
$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header('Location: index.php');
    exit;
}

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();
// Mantener compatibilidad con sesiones antiguas si es necesario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

// Obtener datos de la orden
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$sql = "SELECT wo.*, c.name as client_name, c.id_number as client_id_number, 
               dt.name as device_type_name
        FROM work_orders wo
        " . ($perDatabase ? "LEFT JOIN clients c ON wo.client_id = c.id" : "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id") . "
        " . ($perDatabase ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id") . "
        WHERE wo.id = ?" . ($perDatabase ? "" : " AND wo.tenant_id = ?");
$stmt = $pdo->prepare($sql);
$stmt->execute($perDatabase ? [$order_id] : [$order_id, $tenant_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: index.php');
    exit;
}

// Obtener estados dinámicos por tenant
$statuses = [];
try {
    if ($perDatabase) {
        $statuses_stmt = $pdo->prepare("
            SELECT id, name, slug, emoji, color, is_default, sort_order
            FROM order_statuses
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $statuses_stmt->execute([]);
    } else {
        $statuses_stmt = $pdo->prepare("
            SELECT id, name, slug, emoji, color, is_default, sort_order
            FROM order_statuses
            WHERE is_active = 1 AND tenant_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $statuses_stmt->execute([$tenant_id]);
    }
    $rows = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    $syn = ['esperando aprobacion','esperando-aprobacion','esperando_aprobación','esperando aprobación','esperandoaprobacion','esperando_aprovacion','waiting_authorization','waiting approval'];
    foreach ($rows as $row) {
        $slug = trim((string)($row['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $key = strtolower($slug);
        if (in_array($key, $syn, true)) {
            $slug = 'esperando_aprobacion';
            $key = 'esperando_aprobacion';
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'slug' => $slug,
                'name' => (string)($row['name'] ?? ''),
                'emoji' => (string)($row['emoji'] ?? ''),
                'color' => (string)($row['color'] ?? ''),
                'is_default' => (int)($row['is_default'] ?? 0),
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'id' => (int)($row['id'] ?? 0),
            ];
            continue;
        }
        if ((int)($row['is_default'] ?? 0) === 1) {
            $groups[$key]['is_default'] = 1;
        }
    }
    $statuses = array_values($groups);
    usort($statuses, function ($a, $b) {
        $ao = (int)($a['sort_order'] ?? 0);
        $bo = (int)($b['sort_order'] ?? 0);
        if ($ao === 0 && $bo !== 0) { return 1; }
        if ($bo === 0 && $ao !== 0) { return -1; }
        if ($ao === $bo) { return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')); }
        return $ao <=> $bo;
    });
} catch (PDOException $e) {
    $statuses = [];
}
if (!$statuses || count($statuses) === 0) {
    $statuses = [
        ['slug' => 'pendiente', 'name' => 'Pendiente', 'emoji' => '⏳', 'color' => '#ffc107', 'sort_order' => 1],
        ['slug' => 'asignado', 'name' => 'Asignado', 'emoji' => '📥', 'color' => '#6cc4ea', 'sort_order' => 2],
        ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'emoji' => '🔍', 'color' => '#fd7e14', 'sort_order' => 3],
        ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'emoji' => '⏸️', 'color' => '#6f42c1', 'sort_order' => 4],
        ['slug' => 'reparando', 'name' => 'Reparando', 'emoji' => '🔧', 'color' => '#007bff', 'sort_order' => 5],
        ['slug' => 'testeando', 'name' => 'Testeando', 'emoji' => '🧪', 'color' => '#17a2b8', 'sort_order' => 6],
        ['slug' => 'completado', 'name' => 'Completado', 'emoji' => '✅', 'color' => '#28a745', 'sort_order' => 7],
        ['slug' => 'entregado', 'name' => 'Entregado', 'emoji' => '🚚', 'color' => '#6c757d', 'sort_order' => 8],
        ['slug' => 'cancelado', 'name' => 'Cancelado', 'emoji' => '❌', 'color' => '#dc3545', 'sort_order' => 9],
    ];
}
$existingSlugs = [];
foreach ($statuses as $s) { $existingSlugs[(string)($s['slug'] ?? '')] = true; }
$currentSlug = getEffectiveStatusSlug(($order['status'] ?? ''), ($order['approval_status'] ?? ''));
if ($currentSlug && !isset($existingSlugs[$currentSlug])) {
    $statuses[] = [
        'slug' => (string)$currentSlug,
        'name' => (string)$currentSlug,
        'emoji' => '',
        'color' => '#ffc107',
        'sort_order' => 0
    ];
}
usort($statuses, function ($a, $b) {
    $ao = (int)($a['sort_order'] ?? 0);
    $bo = (int)($b['sort_order'] ?? 0);
    if ($ao === 0 && $bo !== 0) { return 1; }
    if ($bo === 0 && $ao !== 0) { return -1; }
    if ($ao === $bo) { return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')); }
    return $ao <=> $bo;
});

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        die('Token de seguridad inválido o expirado. Por favor, intente de nuevo.');
    }
    $errors = [];
    
    // Validar datos básicos
    $client_id = $_POST['client_id'] ?? null;
    $device_type_id = $_POST['device_type_id'] ?? null;
    $device_brand = $_POST['device_brand'] ?? '';
    $device_model = $_POST['device_model'] ?? '';
    $serial_number = $_POST['serial_number'] ?? '';
    $reported_issue = $_POST['reported_issue'] ?? '';
    $diagnosis = $_POST['diagnosis'] ?? '';
    $solution = $_POST['solution'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';
    $status = $_POST['status'] ?? $order['status'];
    $estimated_cost = $_POST['estimated_cost'] ?? null;
    $final_cost = $_POST['final_cost'] ?? null;
    $estimated_completion = $_POST['estimated_completion'] ?? null;
    $technician_notes = $_POST['technician_notes'] ?? '';
    
    // Validaciones básicas
    if (empty($client_id)) $errors[] = 'Cliente es requerido';
    if (empty($device_type_id)) $errors[] = 'Tipo de dispositivo es requerido';
    if (empty($reported_issue)) $errors[] = 'Problema reportado es requerido';
    
    if (empty($errors)) {
        // ===== MANEJO DE FOTOS SIMPLE =====
        error_log("=== INICIO MANEJO DE FOTOS ===");
        
        // 1. Cargar fotos existentes
        $existing_photos = [];
        if (!empty($order['device_photo'])) {
            $existing_photos = json_decode($order['device_photo'], true) ?: [];
            error_log("Fotos existentes cargadas: " . count($existing_photos) . " fotos");
        }
        
        // 2. Procesar eliminaciones
        $photos_to_remove = $_POST['photos_to_remove'] ?? '';
        if (!empty($photos_to_remove) && $photos_to_remove !== '[]' && $photos_to_remove !== 'null') {
            $photos_to_remove_array = json_decode($photos_to_remove, true) ?: [];
            error_log("Eliminando " . count($photos_to_remove_array) . " fotos");
            
            foreach ($photos_to_remove_array as $photo_to_remove) {
                $baseUploads = '../uploads/';
                $photo_path = getTenantUploadDir($baseUploads) . 'orders/' . $order_id . '/' . $photo_to_remove;
                if (file_exists($photo_path)) {
                    unlink($photo_path);
                    error_log("Archivo eliminado: " . $photo_to_remove);
                }
            }
            
            $existing_photos = array_diff($existing_photos, $photos_to_remove_array);
            error_log("Fotos existentes después de eliminar: " . count($existing_photos));
        }
        
        // 3. Agregar fotos nuevas (solo archivos subidos)
        $new_photos = [];
        if (!empty($_FILES['device_photo']['name'])) {
            error_log("Procesando archivos subidos");
            
            $baseUploads = '../uploads/';
            $upload_dir = getTenantUploadDir($baseUploads) . 'orders/' . $order_id . '/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
                if (!is_dir($upload_dir)) {
                    throw new Exception('No se pudo crear el directorio de subida');
                }
            }
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
                    $photo_filename = 'device_photo_' . $order_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $mimeToExt[$mime];
                    $photo_path = $upload_dir . $photo_filename;
                    
                    if (move_uploaded_file($tmp, $photo_path)) {
                        $extLower = strtolower(pathinfo($photo_filename, PATHINFO_EXTENSION));
                        if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                            PerformanceOptimizer::optimizeImage($photo_path, $photo_path, 85);
                        }
                        $new_photos[] = $photo_filename;
                        error_log("Archivo subido: " . $photo_filename);
                    }
                }
            }
        }
        
        // 4. Combinar todo
        $all_photos = array_merge($existing_photos, $new_photos);
        error_log("RESULTADO FINAL: " . count($all_photos) . " fotos totales");
        error_log("  - Existentes: " . count($existing_photos));
        error_log("  - Nuevas: " . count($new_photos));
        error_log("=== FIN MANEJO DE FOTOS ===");
        
        // Determinar si debemos reiniciar aprobación (esperar firma)
        $prevEst = isset($order['estimated_cost']) ? (float)$order['estimated_cost'] : null;
        $prevAp = strtolower((string)($order['approval_status'] ?? 'none'));
        $oldFmt = $prevEst !== null ? number_format((float)$prevEst, 2, '.', '') : null;
        $newFmt = ($estimated_cost !== null && $estimated_cost !== '') ? number_format((float)$estimated_cost, 2, '.', '') : null;
        $needsResetApproval = false;
        if ($oldFmt !== null && $newFmt !== null && $oldFmt !== $newFmt && in_array($prevAp, ['approved','rejected','aprobado','rechazado'], true)) {
            $needsResetApproval = true;
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
            }
        }
        $ns = strtolower(trim((string)$status));
        if (in_array($ns, ['esperando_aprobacion','esperando aprobacion','esperando-aprobacion','esperando_aprovacion','waiting_authorization','waiting approval'], true)) {
            $needsResetApproval = true;
        }
        if ($needsResetApproval) {
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

        // Actualizar orden
        $photos_json = !empty($all_photos) ? json_encode($all_photos) : null;
        
        // Detectar si existe approved_quote_amount para reiniciarlo
        $hasApprovedAmountCol = hasColumnCached($pdo, 'work_orders', 'approved_quote_amount');

        $sql = "UPDATE work_orders SET 
                    client_id = ?, device_type_id = ?, device_brand = ?, device_model = ?, 
                    serial_number = ?, reported_issue = ?, diagnosis = ?, solution = ?,
                    priority = ?, status = ?, estimated_cost = ?, final_cost = ?,
                    estimated_completion = ?, technician_notes = ?, device_photo = ?, updated_at = CURRENT_TIMESTAMP";
        if ($needsResetApproval) {
            $sql .= ", approval_status = 'pending', approved_at = NULL, approval_signature_path = NULL, approval_comment = NULL";
            if ($hasApprovedAmountCol) { $sql .= ", approved_quote_amount = NULL"; }
        }
        $sql .= "
                WHERE id = ?" . ($perDatabase ? "" : " AND tenant_id = ?");
        
        $stmt = $pdo->prepare($sql);
        $params = [
            $client_id, $device_type_id, $device_brand, $device_model,
            $serial_number, $reported_issue, $diagnosis, $solution,
            $priority, $status, $estimated_cost, $final_cost,
            $estimated_completion, $technician_notes, $photos_json, $order_id
        ];
        if (!$perDatabase) { $params[] = $tenant_id; }
        $result = $stmt->execute($params);
        
        if ($result) {
            $_SESSION['success'] = 'Orden actualizada exitosamente';
            header('Location: view.php?id=' . $order_id);
            exit;
        } else {
            $errors[] = 'Error al actualizar la orden';
        }
    }
}

// Obtener datos para formularios (tenant-aware)
// Clientes por tenant
$clients_stmt = $perDatabase
    ? $pdo->prepare("SELECT id, name, id_number FROM clients ORDER BY name")
    : $pdo->prepare("SELECT id, name, id_number FROM clients WHERE tenant_id = ? ORDER BY name");
$clients_stmt->execute($perDatabase ? [] : [$tenant_id]);
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Tipos de dispositivo por tenant con columnas opcionales
$hasActive = hasColumnCached($pdo, 'device_types', 'is_active');
$hasVisible = hasColumnCached($pdo, 'device_types', 'is_visible');
$hasSortOrder = hasColumnCached($pdo, 'device_types', 'sort_order');
$where = [];
if (!$perDatabase) { $where[] = "tenant_id = ?"; }
if ($hasActive) { $where[] = "is_active = 1"; }
if ($hasVisible) { $where[] = "is_visible = 1"; }
$orderBy = $hasSortOrder ? "ORDER BY sort_order, name" : "ORDER BY name";
$device_types_sql = "SELECT id, name FROM device_types WHERE " . implode(" AND ", $where) . " $orderBy";
$device_types_stmt = $pdo->prepare($device_types_sql);
$device_types_stmt->execute($perDatabase ? [] : [$tenant_id]);
$device_types = $device_types_stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h2>Editar Orden de Trabajo</h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="orderForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="photos_to_remove" name="photos_to_remove">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="client_id" class="form-label">Cliente</label>
                            <select class="form-select" id="client_id" name="client_id" required>
                                <option value="">Seleccionar cliente</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" 
                                            <?php echo $client['id'] == $order['client_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['name'] . ' (' . $client['id_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="device_type_id" class="form-label">Tipo de Dispositivo</label>
                            <select class="form-select" id="device_type_id" name="device_type_id" required>
                                <option value="">Seleccionar tipo</option>
                                <?php foreach ($device_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" 
                                            <?php echo $type['id'] == $order['device_type_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($device_types)): ?>
                            <div class="alert alert-warning mt-2 p-2">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Catálogo de tipos vacío en este tenant.</span>
                                    <a href="../catalogs/device_types.php" class="btn btn-sm btn-primary">Crear tipos</a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="device_brand" class="form-label">Marca</label>
                            <input type="text" class="form-control" id="device_brand" name="device_brand" 
                                   value="<?php echo htmlspecialchars($order['device_brand']); ?>">
                            <?php if (!$perDatabase && empty($brands ?? [])): ?>
                            <div class="alert alert-warning mt-2 p-2">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>No hay marcas en este tenant.</span>
                                    <a href="../catalogs/brands.php" class="btn btn-sm btn-primary">Crear marcas</a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="device_model" class="form-label">Modelo</label>
                            <input type="text" class="form-control" id="device_model" name="device_model" 
                                   value="<?php echo htmlspecialchars($order['device_model']); ?>">
                            <?php if (!$perDatabase && empty($models ?? [])): ?>
                            <div class="alert alert-warning mt-2 p-2">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>No hay modelos en este tenant.</span>
                                    <a href="../catalogs/models.php" class="btn btn-sm btn-primary">Crear modelos</a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="serial_number" class="form-label">Número de Serie</label>
                            <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                   value="<?php echo htmlspecialchars($order['serial_number']); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="reported_issue" class="form-label">Problema Reportado</label>
                            <textarea class="form-control" id="reported_issue" name="reported_issue" rows="3" required><?php echo htmlspecialchars($order['reported_issue']); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="diagnosis" class="form-label">Diagnóstico</label>
                            <textarea class="form-control" id="diagnosis" name="diagnosis" rows="3"><?php echo htmlspecialchars($order['diagnosis']); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="solution" class="form-label">Solución</label>
                            <textarea class="form-control" id="solution" name="solution" rows="3"><?php echo htmlspecialchars($order['solution']); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="priority" class="form-label">Prioridad</label>
                            <select class="form-select" id="priority" name="priority">
                                <option value="low" <?php echo $order['priority'] == 'low' ? 'selected' : ''; ?>>Baja</option>
                                <option value="medium" <?php echo $order['priority'] == 'medium' ? 'selected' : ''; ?>>Media</option>
                                <option value="high" <?php echo $order['priority'] == 'high' ? 'selected' : ''; ?>>Alta</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Estado</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statuses as $s): 
                                    $currentSlug = getEffectiveStatusSlug(($order['status'] ?? ''), ($order['approval_status'] ?? ''));
                                    $active = ($currentSlug === $s['slug']);
                                    $raw = trim($s['emoji'] ?? '');
                                    $useDefault = ($raw === '' || preg_match('/^\?+$/', $raw));
                                    $map = [
                                        'pendiente' => '⏳',
                                        'esperando_aprobacion' => '✍️',
                                        'asignado' => '📥',
                                        'diagnosticando' => '🔍',
                                        'esperando_repuestos' => '⏸️',
                                        'reparando' => '🔧',
                                        'testeando' => '🧪',
                                        'completado' => '✅',
                                        'entregado' => '🚚',
                                        'cancelado' => '❌',
                                        'pending' => '⏳',
                                        'received' => '📦',
                                        'diagnosing' => '🔍',
                                        'waiting_parts' => '⏸️',
                                        'repairing' => '🔧',
                                        'testing' => '🧪',
                                        'completed' => '✅',
                                        'delivered' => '🚚',
                                        'cancelled' => '❌'
                                    ];
                                    $displayEmoji = $useDefault ? ($map[$s['slug']] ?? '❓') : $raw;
                                    $text = $displayEmoji . ' ' . ($s['name'] ?? ucfirst($s['slug']));
                                ?>
                                <option value="<?php echo htmlspecialchars($s['slug']); ?>" <?php echo $active ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($text); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="estimated_cost" class="form-label">Costo Estimado</label>
                            <input type="number" class="form-control" id="estimated_cost" name="estimated_cost" 
                                   value="<?php echo $order['estimated_cost']; ?>" step="0.01">
                        </div>
                        
                        <div class="mb-3">
                            <label for="final_cost" class="form-label">Costo Final</label>
                            <input type="number" class="form-control" id="final_cost" name="final_cost" 
                                   value="<?php echo $order['final_cost']; ?>" step="0.01">
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="estimated_completion" class="form-label">Fecha Estimada de Completado</label>
                            <input type="date" class="form-control" id="estimated_completion" name="estimated_completion" 
                                   value="<?php echo $order['estimated_completion']; ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="technician_notes" class="form-label">Notas del Técnico</label>
                            <textarea class="form-control" id="technician_notes" name="technician_notes" rows="3"><?php echo htmlspecialchars($order['technician_notes']); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Sección de Fotos -->
                <div class="row">
                    <div class="col-md-12">
                        <h4>Fotos del Dispositivo</h4>
                        
                        <!-- Fotos existentes -->
                        <?php if (!empty($order['device_photo'])): ?>
                            <?php $existing_photos = json_decode($order['device_photo'], true) ?: []; ?>
                            <?php if (!empty($existing_photos)): ?>
                                <div class="mb-3">
                                    <label class="form-label">Fotos Existentes</label>
                                    <div class="row" id="existing-photos">
                                        <?php foreach ($existing_photos as $photo): ?>
                                            <?php $photoUrl = resolveOrderPhotoWebUrl((int)$order_id, $photo, '../uploads/'); ?>
                                            <div class="col-md-3 mb-2">
                                                <div class="card">
                                                    <img src="<?php echo htmlspecialchars($photoUrl); ?>" 
                                                         class="card-img-top" style="height: 150px; object-fit: cover;">
                                                    <div class="card-body p-2">
                                                        <button type="button" class="btn btn-sm btn-danger" 
                                                                onclick="removeExistingPhoto('<?php echo htmlspecialchars($photo); ?>')">
                                                            Eliminar
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Subir nuevas fotos -->
                        <div class="mb-3">
                            <label for="device_photo" class="form-label">Agregar Fotos</label>
                            <input type="file" class="form-control" id="device_photo" name="device_photo[]" 
                                   multiple accept="image/*">
                            <div class="form-text">Puedes seleccionar múltiples archivos. Máximo 5MB por archivo.</div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">Actualizar Orden</button>
                    <a href="view.php?id=<?php echo $order_id; ?>" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let existingPhotosToRemove = [];

function removeExistingPhoto(photoFilename) {
    if (typeof showConfirm === 'function') {
        showConfirm('¿Estás seguro de que quieres eliminar esta foto?', function(){
            existingPhotosToRemove.push(photoFilename);
            document.getElementById('photos_to_remove').value = JSON.stringify(existingPhotosToRemove);
            const photoElement = (event && event.target) ? event.target.closest('.col-md-3') : null;
            if (photoElement) photoElement.remove();
        });
    }
}

document.getElementById('orderForm').addEventListener('submit', function(e) {
    console.log('Formulario enviándose...');
    console.log('Fotos marcadas para eliminar:', existingPhotosToRemove.length);
    console.log('Valor de photos_to_remove:', document.getElementById('photos_to_remove').value);
});
</script>

<?php include '../includes/footer.php'; ?>
