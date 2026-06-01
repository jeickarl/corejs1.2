<?php
ob_start();
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';

// Limpiar cualquier salida previa antes de enviar JSON
function sendJson($data) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Verificar si el usuario está logueado
if (!isValidSession()) {
    sendJson(['success' => false, 'message' => 'No autorizado']);
}
// Verificar tenant en sesión (SaaS)
if (getCurrentTenantId() === null) {
    sendJson(['success' => false, 'message' => 'No autorizado (tenant requerido)']);
}
// Obtener tenant actual
$current_tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

// Validar token CSRF para todas las operaciones
if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    sendJson(['success' => false, 'message' => 'Token de seguridad inválido o expirado. Por favor recargue la página.']);
}

try {
    if ($perDatabase && function_exists('hasColumnCached') && !hasColumnCached($GLOBALS['pdo'], 'work_orders', 'approval_status')) {
        $GLOBALS['pdo']->exec("ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none'");
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
            $_SESSION['schema_cache_cols']['work_orders_approval_status'] = true;
        }
    }
} catch (Throwable $__) {
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'change_status':
        changeOrderStatus();
        break;
    case 'update_details':
        updateOrderDetails();
        break;
    case 'add_note':
        addTechnicianNote();
        break;
    case 'delete_order':
        deleteOrder();
        break;
    default:
        sendJson(['success' => false, 'message' => 'Acción no válida']);
        break;
}

function updateOrderDetails() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $order_id = $_POST['order_id'] ?? 0;
    $diagnosis = $_POST['diagnosis'] ?? '';
    $solution = $_POST['solution'] ?? '';
    $technician_notes = $_POST['technician_notes'] ?? '';
    if (!$order_id) {
        sendJson(['success' => false, 'message' => 'ID de orden inválido']);
        return;
    }
    try {
        $sql = "UPDATE work_orders SET diagnosis = ?, solution = ?, technician_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $params = [$diagnosis, $solution, $technician_notes, $order_id];
        if (!$perDatabase) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        sendJson(['success' => true, 'message' => 'Detalles actualizados']);
    } catch (PDOException $e) {
        sendJson(['success' => false, 'message' => 'Error al actualizar detalles: ' . $e->getMessage()]);
    }
}

function changeOrderStatus() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    
    $order_id = $_POST['order_id'] ?? 0;
    $new_status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'];
    $final_cost = isset($_POST['final_cost']) ? trim($_POST['final_cost']) : '';
    $delivery_payment = isset($_POST['delivery_payment']) ? trim($_POST['delivery_payment']) : '';
    $hasApprovalCols = false;
    
    // Validar datos
    if (!$order_id || !$new_status) {
        sendJson(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }
    
    $ns = strtolower(trim($new_status));
    if (in_array($ns, ['esperando_aprobacion','esperando aprobacion','esperando-aprobacion','esperando_aprovacion','waiting_authorization','waiting approval'], true)) {
        try {
            $pendingSlug = 'pendiente';
            try {
                $colTypeStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                $colTypeStmt->execute();
                $colType = (string)$colTypeStmt->fetchColumn();
                if ($colType && stripos($colType, 'enum(') === 0) {
                    $enumVals = [];
                    if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                        $raw = $m[1];
                        $parts = array_map('trim', explode(',', $raw));
                        $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                    }
                    if (in_array('pending', $enumVals, true)) { $pendingSlug = 'pending'; }
                    elseif (in_array('pendiente', $enumVals, true)) { $pendingSlug = 'pendiente'; }
                    elseif (!empty($enumVals)) { $pendingSlug = $enumVals[0]; }
                }
            } catch (Throwable $__) {}
            $sql = "UPDATE work_orders SET status = ?, approval_status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $params = [$pendingSlug, $order_id];
            if (!$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenant_id; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $__) {}
            $hasTenantHistory = hasTenantColumnCached($pdo, 'order_status_history');
            if ($hasTenantHistory) {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, 'pending', 'Esperando aprobación (forzado)', ?, ?)");
                $hist->execute([$order_id, $user_id, $tenant_id]);
            } else {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'pending', 'Esperando aprobación (forzado)', ?)");
                $hist->execute([$order_id, $user_id]);
            }
            sendJson(['success' => true, 'message' => 'Estado cambiado a Esperando Aprobación']);
            return;
        } catch (Throwable $e) {
            sendJson(['success' => false, 'message' => 'Error al poner en espera de aprobación: ' . $e->getMessage()]);
            return;
        }
    }
    if (in_array($ns, ['approved','aprobado'], true)) {
        try {
            $chkSql = "SELECT id FROM work_orders WHERE id = ?";
            $chkParams = [$order_id];
            if (!$perDatabase) { $chkSql .= " AND tenant_id = ?"; $chkParams[] = $tenant_id; }
            $chk = $pdo->prepare($chkSql);
            $chk->execute($chkParams);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                sendJson(['success' => false, 'message' => 'Orden no encontrada para este tenant']);
                return;
            }
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
            
            $approvedSlug = 'aprobado';
            try {
                $colTypeStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                $colTypeStmt->execute();
                $colType = (string)$colTypeStmt->fetchColumn();
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
            try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $__) {}
            $pdo->beginTransaction();
            if ($approvedSlug !== null) {
                $sql = "UPDATE work_orders SET status = ?, approval_status = 'approved', approved_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params = [$approvedSlug, $order_id];
                if (!$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenant_id; }
                $up = $pdo->prepare($sql);
                $up->execute($params);
            } else {
                $sql = "UPDATE work_orders SET approval_status = 'approved', approved_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params = [$order_id];
                if (!$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenant_id; }
                $up = $pdo->prepare($sql);
                $up->execute($params);
            }
            $hasTenantHistory = hasTenantColumnCached($pdo, 'order_status_history');
            if ($hasTenantHistory) {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, 'approved', ?, ?, ?)");
                $hist->execute([$order_id, ($notes ?: 'Aprobado por usuario interno'), $user_id, $tenant_id]);
            } else {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'approved', ?, ?)");
                $hist->execute([$order_id, ($notes ?: 'Aprobado por usuario interno'), $user_id]);
            }
            $pdo->commit();
            sendJson(['success' => true, 'message' => 'Orden aprobada']);
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sendJson(['success' => false, 'message' => 'Error al aprobar la orden: ' . $e->getMessage()]);
            return;
        }
    }
    if (in_array($ns, ['rejected','rechazado'], true)) {
        try {
            // Verificar orden del tenant
            $chkSql = "SELECT id FROM work_orders WHERE id = ?";
            $chkParams = [$order_id];
            if (!$perDatabase) { $chkSql .= " AND tenant_id = ?"; $chkParams[] = $tenant_id; }
            $chk = $pdo->prepare($chkSql);
            $chk->execute($chkParams);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                sendJson(['success' => false, 'message' => 'Orden no encontrada para este tenant']);
                return;
            }
            // Determinar slug para status 'rechazado'
            $rejectedSlug = 'rechazado';
            try {
                $colTypeStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
                $colTypeStmt->execute();
                $colType = (string)$colTypeStmt->fetchColumn();
                if ($colType && stripos($colType, 'enum(') === 0) {
                    $enumVals = [];
                    if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
                        $raw = $m[1];
                        $parts = array_map('trim', explode(',', $raw));
                        $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                    }
                    if (!in_array('rechazado', $enumVals, true)) {
                        if (in_array('rejected', $enumVals, true)) {
                            $rejectedSlug = 'rejected';
                        } else {
                            $rejectedSlug = null;
                        }
                    }
                }
            } catch (Throwable $__) {}
            try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $__) {}
            $pdo->beginTransaction();
            if ($rejectedSlug !== null) {
                $sql = "UPDATE work_orders SET status = ?, approval_status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params = [$rejectedSlug, $order_id];
                if (!$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenant_id; }
                $up = $pdo->prepare($sql);
                $up->execute($params);
            } else {
                $sql = "UPDATE work_orders SET approval_status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params = [$order_id];
                if (!$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenant_id; }
                $up = $pdo->prepare($sql);
                $up->execute($params);
            }
            $hasTenantHistory = hasTenantColumnCached($pdo, 'order_status_history');
            if ($hasTenantHistory) {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, 'rejected', ?, ?, ?)");
                $hist->execute([$order_id, ($notes ?: 'Rechazado por usuario interno'), $user_id, $tenant_id]);
            } else {
                $hist = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'rejected', ?, ?)");
                $hist->execute([$order_id, ($notes ?: 'Rechazado por usuario interno'), $user_id]);
            }
            $pdo->commit();
            sendJson(['success' => true, 'message' => 'Orden rechazada']);
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sendJson(['success' => false, 'message' => 'Error al rechazar la orden: ' . $e->getMessage()]);
            return;
        }
    }
    
    // Validar que el estado sea válido (dinámico por tenant + mapeo bilingüe)
    try {
        $hasTenantCol = hasTenantColumnCached($pdo, 'order_statuses');
        if ($hasTenantCol) {
            $st = $pdo->prepare("SELECT slug FROM order_statuses WHERE is_active = 1 AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
            $st->execute([$tenant_id]);
        } else {
            $st = $pdo->prepare("SELECT slug FROM order_statuses WHERE is_active = 1");
            $st->execute();
        }
        $valid_statuses = array_map(function($r){ return $r['slug']; }, $st->fetchAll(PDO::FETCH_ASSOC));
        $valid_statuses = array_values(array_unique($valid_statuses));
        if (empty($valid_statuses)) {
            $valid_statuses = [
                'pending','received','diagnosing','waiting_parts','repairing','testing','completed','delivered','cancelled',
                'pendiente','asignado','diagnosticando','esperando_repuestos','reparando','testeando','completado','entregado','cancelado'
            ];
        }
        // Extender set válido con sinónimos bilingües
        $mapEsEn = [
            'pendiente' => 'pending',
            'asignado' => 'received',
            'diagnosticando' => 'diagnosing',
            'esperando_repuestos' => 'waiting_parts',
            'reparando' => 'repairing',
            'testeando' => 'testing',
            'completado' => 'completed',
            'entregado' => 'delivered',
            'cancelado' => 'cancelled',
        ];
        $mapEnEs = array_flip($mapEsEn);
        $extended = $valid_statuses;
        foreach ($valid_statuses as $s) {
            if (isset($mapEsEn[$s])) {
                $extended[] = $mapEsEn[$s];
            }
            if (isset($mapEnEs[$s])) {
                $extended[] = $mapEnEs[$s];
            }
        }
        $extended = array_values(array_unique($extended));
        if (!in_array($new_status, $extended, true)) {
            sendJson(['success' => false, 'message' => 'Estado no válido para este tenant']);
            return;
        }
    } catch (Throwable $e) {
        // Si falla la verificación, permitir estados conocidos
        $fallback = [
            'pending','received','diagnosing','waiting_parts','repairing','testing','completed','delivered','cancelled',
            'pendiente','asignado','diagnosticando','esperando_repuestos','reparando','testeando','completado','entregado','cancelado'
        ];
        if (!in_array($new_status, $fallback, true)) {
            sendJson(['success' => false, 'message' => 'Estado no válido']);
            return;
        }
    }
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');
        $hasTenantHistory = hasTenantColumnCached($pdo, 'order_status_history');
        $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
        $hasTenantInvoiceItems = hasTenantColumnCached($pdo, 'invoice_items');
        // Validar que la orden pertenece al tenant actual
        if (!$perDatabase && $hasTenantWorkOrders) {
            $chk = $pdo->prepare("SELECT id FROM work_orders WHERE id = ? AND tenant_id = ?");
            $chk->execute([$order_id, $tenantValue]);
        } else {
            $chk = $pdo->prepare("SELECT id FROM work_orders WHERE id = ?");
            $chk->execute([$order_id]);
        }
        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
            sendJson(['success' => false, 'message' => 'Orden no encontrada para este tenant']);
            return;
        }
        $c = hasColumnCached($pdo, 'work_orders', 'delivery_payment');
        if (!$c) {
            $pdo->exec("ALTER TABLE work_orders ADD COLUMN delivery_payment DECIMAL(10,2) NULL DEFAULT 0");
        }
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
            $hasApprovalCols = true;
        } catch (Throwable $__) {
            $hasApprovalCols = hasColumnCached($pdo, 'work_orders', 'approval_status') && hasColumnCached($pdo, 'work_orders', 'approved_at');
        }
        try { ensureOrderStatusHistorySchema($pdo); } catch (Throwable $__) {}
        // Asegurar esquema de configuración antes de la transacción para evitar DDL implícito
        ensureSystemConfigSchema();
        // Iniciar transacción
        $pdo->beginTransaction();
        try {
            $colStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
            $colStmt->execute();
            $colType = (string)$colStmt->fetchColumn();
            $enumVals = [];
            if ($colType && stripos($colType, 'enum(') === 0) {
                if (preg_match("/^enum\\((.+)\\)$/i", $colType, $m)) {
                    $raw = $m[1];
                    $parts = array_map('trim', explode(',', $raw));
                    $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
                }
            }
            if (!empty($enumVals)) {
                if (!in_array($new_status, $enumVals, true)) {
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
                    $candidate = null;
                    if (isset($mapEsEn[$new_status]) && in_array($mapEsEn[$new_status], $enumVals, true)) {
                        $candidate = $mapEsEn[$new_status];
                    } elseif (isset($mapEnEs[$new_status]) && in_array($mapEnEs[$new_status], $enumVals, true)) {
                        $candidate = $mapEnEs[$new_status];
                    }
                    if ($candidate !== null) {
                        $new_status = $candidate;
                    } elseif (!in_array($new_status, $enumVals, true)) {
                        if (in_array('pending', $enumVals, true)) {
                            $new_status = 'pending';
                        } elseif (in_array('pendiente', $enumVals, true)) {
                            $new_status = 'pendiente';
                        } else {
                            $new_status = $enumVals[0];
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
        
        $curStmt = $pdo->prepare("SELECT status, approval_status FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $curStmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenantValue] : [$order_id]);
        $curRow = $curStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currentStatus = strtolower(trim((string)($curRow['status'] ?? '')));
        $currentApproval = strtolower(trim((string)($curRow['approval_status'] ?? 'none')));
        
        $mapEsEnAuto = [
            'pendiente' => 'pending',
            'asignado' => 'received',
            'diagnosticando' => 'diagnosing',
            'esperando_repuestos' => 'waiting_parts',
            'reparando' => 'repairing',
            'testeando' => 'testing',
            'completado' => 'completed',
            'entregado' => 'delivered',
            'cancelado' => 'cancelled',
        ];
        $currentCanonical = $mapEsEnAuto[$currentStatus] ?? $currentStatus;
        $targetCanonical = $mapEsEnAuto[strtolower(trim((string)$new_status))] ?? strtolower(trim((string)$new_status));
        $autoFlowToCompleted = ($targetCanonical === 'completed' && $currentCanonical !== 'completed');
        $isCompleted = ($targetCanonical === 'completed');
        $isDelivered = ($targetCanonical === 'delivered');
        
        if ($autoFlowToCompleted) {
            $flow = ['pending','received','diagnosing','waiting_parts','repairing','testing','completed'];
            $completedIdx = array_search('completed', $flow, true);
            $curIdx = array_search($currentCanonical, $flow, true);
            if ($curIdx === false) { $curIdx = -1; }
            
            $notesAuto = $notes !== '' ? $notes : 'Auto por marcado en Completado';
            
            if ($hasTenantHistory) {
                $history_sql = "INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, ?, ?, ?, ?)";
                $history_stmt = $pdo->prepare($history_sql);
            } else {
                $history_sql = "INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, ?, ?, ?)";
                $history_stmt = $pdo->prepare($history_sql);
            }
            
            $needsAutoApprove = ($currentApproval !== 'approved');
            $approvedInserted = false;
            $diagnosingIdx = array_search('diagnosing', $flow, true);
            
            for ($i = $curIdx + 1; $i < $completedIdx; $i++) {
                if ($needsAutoApprove && !$approvedInserted && $i > $diagnosingIdx) {
                    if ($hasTenantHistory) {
                        $history_stmt->execute([$order_id, 'approved', 'Auto-aprobado por completar', $user_id, $tenantValue]);
                    } else {
                        $history_stmt->execute([$order_id, 'approved', 'Auto-aprobado por completar', $user_id]);
                    }
                    $approvedInserted = true;
                }
                
                $step = $flow[$i];
                if ($hasTenantHistory) {
                    $history_stmt->execute([$order_id, $step, $notesAuto, $user_id, $tenantValue]);
                } else {
                    $history_stmt->execute([$order_id, $step, $notesAuto, $user_id]);
                }
            }
            
            if ($needsAutoApprove && !$approvedInserted && $curIdx >= $diagnosingIdx) {
                if ($hasTenantHistory) {
                    $history_stmt->execute([$order_id, 'approved', 'Auto-aprobado por completar', $user_id, $tenantValue]);
                } else {
                    $history_stmt->execute([$order_id, 'approved', 'Auto-aprobado por completar', $user_id]);
                }
                $approvedInserted = true;
            }
        }
        
        // Actualizar el estado en la orden
        $update_sql = "UPDATE work_orders SET status = ?, updated_at = CURRENT_TIMESTAMP";
        $params = [$new_status];
        
        // Si el estado es 'completed', actualizar la fecha de completado y auto-aprobar
        if ($isCompleted) {
            $update_sql .= ", completed_date = CURRENT_TIMESTAMP";
            $update_sql .= ", received_date = COALESCE(received_date, CURRENT_TIMESTAMP)";
            if ($hasApprovalCols) {
                $update_sql .= ", approval_status = 'approved', approved_at = COALESCE(approved_at, NOW())";
            }
        }
        // Si el estado es 'delivered', actualizar la fecha de entrega
        elseif ($isDelivered) {
            $update_sql .= ", delivered_date = CURRENT_TIMESTAMP";
            if ($final_cost !== '') {
                $update_sql .= ", final_cost = ?";
                $params[] = is_numeric($final_cost) ? number_format((float)$final_cost, 2, '.', '') : null;
            }
            if ($delivery_payment !== '') {
                $update_sql .= ", delivery_payment = ?";
                $params[] = is_numeric($delivery_payment) ? number_format((float)$delivery_payment, 2, '.', '') : null;
            }
        }
        if (!$isCompleted) {
            // Si venía desde aprobación pendiente, limpiar para que el portal no muestre 'Esperando Aprobación'
            $update_sql .= ", approval_status = CASE WHEN approval_status = 'pending' THEN 'none' ELSE approval_status END";
        }
        
        $update_sql .= " WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "");
        $params[] = $order_id;
        if (!$perDatabase && $hasTenantWorkOrders) { $params[] = $tenantValue; }
        
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $curStmt = $pdo->prepare("SELECT status FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
            $curStmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenantValue] : [$order_id]);
            $currentDbStatus = strtolower(trim((string)($curStmt->fetchColumn() ?: '')));
            $targetStatus = strtolower(trim($new_status));
            if ($currentDbStatus !== $targetStatus) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                sendJson(['success' => false, 'message' => 'No se actualizó el estado (orden/tenant no coinciden o valor idéntico)']);
            }
            // Si el valor ya coincide, continuar como éxito (idempotente)
        }
        
        // Insertar en el historial de estados
        if ($hasTenantHistory) {
            $history_sql = "INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, ?, ?, ?, ?)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([$order_id, $new_status, $notes, $user_id, $tenantValue]);
        } else {
            $history_sql = "INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, ?, ?, ?)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([$order_id, $new_status, $notes, $user_id]);
        }
        
        if ($isCompleted) {
            try {
                $taxConfig = CompanySettings::getTaxConfig();
                $order_stmt = $pdo->prepare("SELECT client_id, reported_issue, diagnosis, solution, final_cost, estimated_cost, advance_payment, payment_method, payment_reference FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : ""));
                $order_stmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenantValue] : [$order_id]);
                $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
                if ($order) {
                    $client_id = $order['client_id'];
                    $advance_payment = floatval($order['advance_payment'] ?? 0);
                    $payment_method = $order['payment_method'] ?? 'Efectivo';
                    $payment_reference = $order['payment_reference'] ?? '';
                    
                    $desc_parts = [];
                    $desc_parts[] = 'Orden #' . $order_id;
                    if (!empty($order['solution'])) { $desc_parts[] = $order['solution']; }
                    elseif (!empty($order['diagnosis'])) { $desc_parts[] = $order['diagnosis']; }
                    elseif (!empty($order['reported_issue'])) { $desc_parts[] = $order['reported_issue']; }
                    $item_description = implode(' - ', $desc_parts);
                    $price = 0.0;
                    if ($order['final_cost'] !== null && $order['final_cost'] !== '') { $price = floatval($order['final_cost']); }
                    elseif ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') { $price = floatval($order['estimated_cost']); }
                    $draft_data = [
                        'client_id' => $client_id,
                        'invoice_type' => 'service',
                        'invoice_date' => date('Y-m-d'),
                        'due_date' => '',
                        'order_id' => $order_id,
                        'items' => [
                            [
                                'type' => 'manual',
                                'description' => $item_description,
                                'quantity' => 1,
                                'unit_price' => $price,
                                'discount' => 0,
                                'tax' => $taxConfig['enabled'] ? $taxConfig['rate'] : 0
                            ]
                        ],
                        'discount_percent' => 0,
                        'discount_amount' => 0,
                        'tax_rate' => $taxConfig['enabled'] ? $taxConfig['rate'] : 0,
                        'notes' => '',
                        'terms_conditions' => '',
                        'payment_method' => $advance_payment > 0 ? $payment_method : '',
                        'payment_amount' => $advance_payment,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    $billingDraftCreated = false;
                    try {
                        $tblDrafts = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_drafts'");
                        $tblDrafts->execute();
                        $hasDrafts = (intval($tblDrafts->fetchColumn() ?? 0) > 0);
                    } catch (Throwable $e) { $hasDrafts = false; }
                    if ($hasDrafts) {
                        $draft_check = $pdo->prepare("SELECT id FROM invoice_drafts WHERE user_id = ?");
                        $draft_check->execute([$user_id]);
                        $existing_draft = $draft_check->fetch();
                        if ($existing_draft) {
                            $draft_update = $pdo->prepare("UPDATE invoice_drafts SET draft_data = ?, updated_at = NOW() WHERE user_id = ?");
                            $draft_update->execute([json_encode($draft_data), $user_id]);
                            $billingDraftCreated = true;
                        } else {
                            $draft_insert = $pdo->prepare("INSERT INTO invoice_drafts (user_id, draft_data, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                            $draft_insert->execute([$user_id, json_encode($draft_data)]);
                            $billingDraftCreated = true;
                        }
                    }
    
                    // Crear factura borrador automáticamente si no existe aún
                    $hasInvoicesTable = false;
                    $has_order_id_col = false;
                    try {
                        $tblInv = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'");
                        $tblInv->execute();
                        $hasInvoicesTable = (intval($tblInv->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0);
                        $colStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'order_id'");
                        $colStmt->execute();
                        $has_order_id_col = (intval($colStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0);
                    } catch (Throwable $e) {}
                    
                    $linkedInvoice = null;
                    try {
                        if ($hasInvoicesTable && $has_order_id_col) {
                            $stmtInv = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? " . ((!$perDatabase && $hasTenantInvoices) ? "AND tenant_id = ? " : "") . "AND status != 'cancelled' LIMIT 1");
                            $stmtInv->execute((!$perDatabase && $hasTenantInvoices) ? [$order_id, $tenantValue] : [$order_id]);
                            $linkedInvoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
                        } elseif ($hasInvoicesTable) {
                            $like = '%Orden #' . $order_id . '%';
                            $joinIi = (!$perDatabase && $hasTenantInvoices && $hasTenantInvoiceItems) ? "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id" : "LEFT JOIN invoice_items ii ON ii.invoice_id = i.id";
                            $stmtInv = $pdo->prepare("SELECT i.id FROM invoices i {$joinIi} WHERE " . ((!$perDatabase && $hasTenantInvoices) ? "i.tenant_id = ? AND " : "") . "i.status != 'cancelled' AND (i.notes LIKE ? OR ii.description LIKE ?) LIMIT 1");
                            $paramsInv = [];
                            if (!$perDatabase && $hasTenantInvoices) { $paramsInv[] = $tenantValue; }
                            $paramsInv[] = $like; $paramsInv[] = $like;
                            $stmtInv->execute($paramsInv);
                            $linkedInvoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
                        }
                    } catch (Throwable $e) {}
    
                    if ($hasInvoicesTable && !$linkedInvoice) {
                        // Calcular totales simples
                        $qty = 1;
                        $unit = $price;
                        $taxPercent = $taxConfig['enabled'] ? $taxConfig['rate'] : 0;
                        $lineSub = $qty * $unit;
                        $lineTax = $lineSub * ($taxPercent / 100);
                        $subtotal = $lineSub;
                        $taxAmount = $lineTax;
                        $totalAmount = $subtotal + $taxAmount;
    
                        // Calcular pagos (abonos)
                        $paidAmount = 0.0;
                        $pendingAmount = $totalAmount;
                        $paymentStatus = 'pending';
                        $statusInv = 'draft';
    
                        if ($advance_payment > 0) {
                            $paidAmount = min($advance_payment, $totalAmount);
                            $pendingAmount = $totalAmount - $paidAmount;
                            $paymentStatus = ($pendingAmount <= 0.01) ? 'paid' : 'partial';
                            if ($paymentStatus === 'paid') {
                                $statusInv = 'paid';
                            }
                        }
    
                        $invoice_number = generateNextInvoiceNumber($pdo);
                        $invoice_date = date('Y-m-d');
                        $due_date = null;
                        $document_type = 'service';
                        $notesInv = 'Origen: Orden #' . $order_id;
                        $terms = '';
    
                        if ($has_order_id_col) {
                            $stmtCreate = $pdo->prepare("INSERT INTO invoices (tenant_id, invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
                            $stmtCreate->execute([$tenant_id, $invoice_number, $client_id, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $user_id, $order_id]);
                        } else {
                            $stmtCreate = $pdo->prepare("INSERT INTO invoices (tenant_id, invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                            $stmtCreate->execute([$tenant_id, $invoice_number, $client_id, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $user_id]);
                        }
                        $newInvoiceId = (int)$pdo->lastInsertId();
                        try {
                            if (preg_match('/^([^\d]*)(\d+)$/', $invoice_number, $m)) {
                                cfg_set('invoice_next_number', (string)$m[2]);
                            } elseif (ctype_digit($invoice_number)) {
                                cfg_set('invoice_next_number', (string)$invoice_number);
                            }
                        } catch (Throwable $e) {}
    
                        // Insertar item
                        try {
                            $tblItems = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_items'");
                            $tblItems->execute();
                            $hasItems = (intval($tblItems->fetchColumn() ?? 0) > 0);
                        } catch (Throwable $e) { $hasItems = false; }
                        if ($hasItems) {
                            $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
                            $itemStmt->execute([$newInvoiceId, 'service', $item_description, $qty, $unit, $lineSub + $lineTax, $tenant_id]);
                        }
    
                        // Registrar pago asociado al abono (si existe)
                        if ($advance_payment > 0) {
                            try {
                                $tblPay = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_payments'");
                                $tblPay->execute();
                                $hasPay = (intval($tblPay->fetchColumn() ?? 0) > 0);
                                if ($hasPay) {
                                    $stmtPay = $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, created_by, created_at, tenant_id) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW(), ?)");
                                    $refNum = 'Abono Orden #' . $order_id;
                                    if (!empty($payment_reference)) {
                                        $refNum .= ' - Ref: ' . $payment_reference;
                                    }
                                    $stmtPay->execute([$newInvoiceId, $paidAmount, $payment_method ?: 'Efectivo', $refNum, 'Abono previo', $user_id, $tenant_id]);
                                }
                            } catch (Throwable $e) {}
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('Order status completion billing error: ' . $e->getMessage());
            }
        }
        
        $pdo->commit();
        
        sendJson([
            'success' => true,
            'message' => 'Estado actualizado correctamente',
            'new_status' => $new_status,
            'status_text' => getStatusText($new_status),
            'status_class' => getStatusClass($new_status),
            'billing_draft_created' => isset($billingDraftCreated) ? (bool)$billingDraftCreated : false
        ]);
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = $e->getMessage();
        if (strpos($msg, 'active transaction') !== false) {
            // Este error suele ser interno (commit fallido por DDL previo), pero la operación puede haber tenido éxito parcialmente.
            // Sin embargo, para seguridad, reportamos error genérico.
            $msg = 'Error de consistencia en la base de datos. Por favor verifique si el estado cambió.';
        }
        sendJson(['success' => false, 'message' => 'Error al actualizar el estado: ' . $msg]);
    }
}

function addTechnicianNote() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    
    $order_id = $_POST['order_id'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    
    if (!$order_id || !$notes) {
        sendJson(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }
    
    try {
        $sql = "UPDATE work_orders SET technician_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $params = [$notes, $order_id];
        if (!$perDatabase) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        sendJson(['success' => true, 'message' => 'Notas actualizadas correctamente']);
        
    } catch (PDOException $e) {
        sendJson(['success' => false, 'message' => 'Error al actualizar las notas: ' . $e->getMessage()]);
    }
}

function deleteOrder() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    
    $order_id = $_POST['order_id'] ?? 0;
    
    if (!$order_id) {
        sendJson(['success' => false, 'message' => 'ID de orden no válido']);
        return;
    }
    
    try {
        // Verificar que la orden existe
        $check_sql = "SELECT id FROM work_orders WHERE id = ?";
        $check_params = [$order_id];
        if (!$perDatabase) {
            $check_sql .= " AND tenant_id = ?";
            $check_params[] = $tenant_id;
        }
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute($check_params);
        
        if (!$check_stmt->fetch()) {
            sendJson(['success' => false, 'message' => 'Orden no encontrada']);
            return;
        }
        
        // Eliminar la orden (el historial se eliminará automáticamente por CASCADE)
        $delete_sql = "DELETE FROM work_orders WHERE id = ?";
        $delete_params = [$order_id];
        if (!$perDatabase) {
            $delete_sql .= " AND tenant_id = ?";
            $delete_params[] = $tenant_id;
        }
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute($delete_params);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Orden eliminada correctamente']);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error al eliminar la orden: ' . $e->getMessage()]);
    }
}

?>
