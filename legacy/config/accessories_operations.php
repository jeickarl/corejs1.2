<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';



// Verificar autenticación
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if (in_array($action, ['create_accessory', 'update_accessory', 'delete_accessory', 'save_order_accessories'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        $csrfOk = false;
        if ($csrf !== '') {
            if (class_exists('SecurityEnhancements') && SecurityEnhancements::verifyCSRFToken($csrf)) {
                $csrfOk = true;
            } else {
                $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
                if ($sessionCsrf !== '' && hash_equals($sessionCsrf, (string)$csrf)) {
                    $csrfOk = true;
                }
            }
        }
        if (!$csrfOk) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
            exit;
        }
    }
    
    // Verificar permisos de administrador para acciones de configuración global
    $admin_actions = ['create_accessory', 'update_accessory', 'delete_accessory'];
    if (in_array($action, $admin_actions) && !isAdminSession()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
        exit;
    }

    switch ($action) {
        case 'get_accessories':
            getAccessories();
            break;
            
        case 'create_accessory':
            createAccessory();
            break;
            
        case 'get_accessory':
            getAccessory();
            break;
            
        case 'update_accessory':
            updateAccessory();
            break;
            
        case 'delete_accessory':
            deleteAccessory();
            break;
            
        case 'get_order_accessories':
            getOrderAccessories();
            break;
            
        case 'save_order_accessories':
            saveOrderAccessories();
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getAccessories() {
    global $pdo;
    
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantAC = hasTenantColumnCached($pdo, 'accessories_checklist');
    $sql = "SELECT id, name, description, category, sort_order, is_active, created_at FROM accessories_checklist WHERE is_active = 1" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY sort_order ASC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAC && !$perDatabase) ? [$tenantValue] : []);
    $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'accessories' => $accessories
    ]);
}

function createAccessory() {
    global $pdo;
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'general');
    $sort_order = (int)($_POST['sort_order'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        throw new Exception('El nombre del accesorio es requerido');
    }
    
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantAC = hasTenantColumnCached($pdo, 'accessories_checklist');
    $sql = "SELECT id FROM accessories_checklist WHERE name = ?" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAC && !$perDatabase) ? [$name, $tenantValue] : [$name]);
    
    if ($stmt->fetch()) {
        throw new Exception('Ya existe un accesorio con ese nombre');
    }
    
    if ($hasTenantAC) {
        $stmt = $pdo->prepare("INSERT INTO accessories_checklist (tenant_id, name, description, category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenantValue, $name, $description, $category, $sort_order, $is_active]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO accessories_checklist (name, description, category, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $category, $sort_order, $is_active]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Accesorio creado exitosamente',
        'accessory_id' => $pdo->lastInsertId()
    ]);
}

function getAccessory() {
    global $pdo;
    
    $accessory_id = (int)($_GET['accessory_id'] ?? 0);
    
    if (!$accessory_id) {
        throw new Exception('ID de accesorio no válido');
    }
    
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantAC = hasTenantColumnCached($pdo, 'accessories_checklist');
    $sql = "SELECT id, name, description, category, sort_order, is_active FROM accessories_checklist WHERE id = ?" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAC && !$perDatabase) ? [$accessory_id, $tenantValue] : [$accessory_id]);
    $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$accessory) {
        throw new Exception('Accesorio no encontrado');
    }
    
    echo json_encode([
        'success' => true,
        'accessory' => $accessory
    ]);
}

function updateAccessory() {
    global $pdo;
    
    $accessory_id = (int)($_POST['accessory_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'general');
    $sort_order = (int)($_POST['sort_order'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!$accessory_id) {
        throw new Exception('ID de accesorio no válido');
    }
    
    if (empty($name)) {
        throw new Exception('El nombre del accesorio es requerido');
    }
    
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantAC = hasTenantColumnCached($pdo, 'accessories_checklist');
    $sql = "SELECT id FROM accessories_checklist WHERE name = ? AND id != ?" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAC && !$perDatabase) ? [$name, $accessory_id, $tenantValue] : [$name, $accessory_id]);
    
    if ($stmt->fetch()) {
        throw new Exception('Ya existe otro accesorio con ese nombre');
    }
    
    $sql = "UPDATE accessories_checklist SET name = ?, description = ?, category = ?, sort_order = ?, is_active = ? WHERE id = ?" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $params = [$name, $description, $category, $sort_order, $is_active, $accessory_id];
    if ($hasTenantAC && !$perDatabase) { $params[] = $tenantValue; }
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Accesorio actualizado exitosamente'
    ]);
}

function deleteAccessory() {
    global $pdo;
    
    $accessory_id = (int)($_POST['accessory_id'] ?? 0);
    
    if (!$accessory_id) {
        throw new Exception('ID de accesorio no válido');
    }
    
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantAC = hasTenantColumnCached($pdo, 'accessories_checklist');
    $hasOA = false;
    try { $c = $pdo->query("SHOW COLUMNS FROM order_accessories LIKE 'tenant_id'"); $hasOA = $c && $c->rowCount() > 0; } catch (Throwable $__) {}
    $sql = "SELECT COUNT(*) FROM order_accessories WHERE accessory_id = ?";
    $params = [$accessory_id];
    if ($hasOA && !$perDatabase) { $sql .= " AND tenant_id = ?"; $params[] = $tenantValue; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usage_count = $stmt->fetchColumn();
    
    if ($usage_count > 0) {
        throw new Exception('No se puede eliminar el accesorio porque está siendo usado en ' . $usage_count . ' orden(es)');
    }
    
    $sql = "DELETE FROM accessories_checklist WHERE id = ?" . (($hasTenantAC && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantAC && !$perDatabase) ? [$accessory_id, $tenantValue] : [$accessory_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Accesorio eliminado exitosamente'
    ]);
}

function getOrderAccessories() {
    global $pdo;
    
    $order_id = (int)($_GET['order_id'] ?? 0);
    
    if (!$order_id) {
        throw new Exception('ID de orden no válido');
    }
    
    // Obtener todos los accesorios activos
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasOA = false; $hasAC = false;
    try { $c = $pdo->query("SHOW COLUMNS FROM order_accessories LIKE 'tenant_id'"); $hasOA = $c && $c->rowCount() > 0; } catch (Throwable $__) {}
    try { $c2 = $pdo->query("SHOW COLUMNS FROM accessories_checklist LIKE 'tenant_id'"); $hasAC = $c2 && $c2->rowCount() > 0; } catch (Throwable $__) {}
    $sql = "
        SELECT ac.id, ac.name, ac.description, ac.category, ac.sort_order,
               COALESCE(oa.is_included, 0) as is_included,
               oa.condition_notes
        FROM accessories_checklist ac
        LEFT JOIN order_accessories oa ON ac.id = oa.accessory_id AND oa.order_id = ?
    ";
    if ($hasOA && !$perDatabase) { $sql = str_replace("oa.order_id = ?", "oa.order_id = ? AND oa.tenant_id = ?", $sql); }
    $sql .= " WHERE ac.is_active = 1";
    if ($hasAC && !$perDatabase) { $sql .= " AND ac.tenant_id = ?"; }
    $sql .= " ORDER BY ac.sort_order ASC, ac.name ASC";
    $stmt = $pdo->prepare($sql);
    $params = [$order_id];
    if ($hasOA && !$perDatabase) { $params[] = $tenantValue; }
    if ($hasAC && !$perDatabase) { $params[] = $tenantValue; }
    $stmt->execute($params);
    $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'accessories' => $accessories
    ]);
}

function saveOrderAccessories() {
    global $pdo;
    
    $order_id = (int)($_POST['order_id'] ?? 0);
    $accessories_data = $_POST['accessories'] ?? [];
    
    if (!$order_id) {
        throw new Exception('ID de orden no válido');
    }
    
    if (!is_array($accessories_data)) {
        throw new Exception('Datos de accesorios no válidos');
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    try {
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasOA = false;
        try { $c = $pdo->query("SHOW COLUMNS FROM order_accessories LIKE 'tenant_id'"); $hasOA = $c && $c->rowCount() > 0; } catch (Throwable $__) {}
        // Eliminar accesorios existentes de esta orden
        if ($hasOA && !$perDatabase) {
            $stmt = $pdo->prepare("DELETE FROM order_accessories WHERE order_id = ? AND tenant_id = ?");
            $stmt->execute([$order_id, $tenantValue]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM order_accessories WHERE order_id = ?");
            $stmt->execute([$order_id]);
        }
        
        // Insertar nuevos accesorios
        if ($hasOA && !$perDatabase) {
            $stmt = $pdo->prepare("
                INSERT INTO order_accessories (tenant_id, order_id, accessory_id, is_included, condition_notes)
                VALUES (?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO order_accessories (order_id, accessory_id, is_included, condition_notes)
                VALUES (?, ?, ?, ?)
            ");
        }
        
        foreach ($accessories_data as $accessory) {
            $accessory_id = (int)($accessory['id'] ?? 0);
            $is_included = isset($accessory['is_included']) ? 1 : 0;
            $condition_notes = trim($accessory['condition_notes'] ?? '');
            
            if ($accessory_id > 0) {
                if ($hasOA && !$perDatabase) {
                    $stmt->execute([$tenantValue, $order_id, $accessory_id, $is_included, $condition_notes]);
                } else {
                    $stmt->execute([$order_id, $accessory_id, $is_included, $condition_notes]);
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Accesorios guardados exitosamente'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
?>
