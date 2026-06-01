<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security_enhancements.php';
require_once __DIR__ . '/functions.php';



if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $inputRaw = file_get_contents('php://input');
    $input = $inputRaw ? json_decode($inputRaw, true) : null;
    if (empty($action) && $input) {
        $action = $input['action'] ?? '';
    }

    $mutating = ['create_accessory','update_accessory','delete_accessory','update_order'];
    if (in_array($action, $mutating, true)) {
        // Verificar permisos de administrador para acciones de modificación
        if (!isAdminSession()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
            exit;
        }
        $token = $_POST['csrf_token'] ?? ($input['csrf_token'] ?? '');
        
        // Verificar contra el token de sesión estándar (usado en settings.php)
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        if (empty($token) || empty($sessionToken) || $token !== $sessionToken) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
            exit;
        }
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
            
        case 'update_order':
            updateAccessoriesOrder();
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
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'equipment_accessories'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS equipment_accessories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT(11) NOT NULL,
                name VARCHAR(150) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_ea_tenant (tenant_id),
                UNIQUE KEY uq_ea_name_tenant (name, tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'sort_order'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'is_active'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM equipment_accessories LIKE 'tenant_id'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE equipment_accessories ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1");
            $pdo->exec("CREATE INDEX idx_ea_tenant ON equipment_accessories(tenant_id)");
        }
        
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $countSql = "SELECT COUNT(*) FROM equipment_accessories" . (($hasTenantEA && !$perDatabase) ? " WHERE tenant_id = ?" : "");
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute(($hasTenantEA && !$perDatabase) ? [$tenantValue] : []);
        $count = $countStmt->fetchColumn();
        
        if ($count === 0) {
            echo json_encode([
                'success' => true,
                'accessories' => []
            ]);
            return;
        }
        
        $sql = "SELECT id, name, created_at, sort_order FROM equipment_accessories WHERE is_active = 1" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY sort_order ASC, name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$tenantValue] : []);
        
        $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'accessories' => $accessories
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al cargar accesorios: ' . $e->getMessage()
        ]);
    }
}

function createAccessory() {
    global $pdo;
    
    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        throw new Exception('El nombre del accesorio es requerido');
    }
    
    try {
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $sql = "SELECT id FROM equipment_accessories WHERE name = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$name, $tenantValue] : [$name]);
        
        if ($stmt->fetch()) {
            throw new Exception('Ya existe un accesorio con ese nombre');
        }
        
        $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM equipment_accessories" . (($hasTenantEA && !$perDatabase) ? " WHERE tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$tenantValue] : []);
        $nextOrder = $stmt->fetchColumn();
        
        if ($hasTenantEA) {
            $stmt = $pdo->prepare("INSERT INTO equipment_accessories (tenant_id, name, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$tenantValue, $name, $nextOrder]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO equipment_accessories (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $nextOrder]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Accesorio creado exitosamente',
            'id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        throw new Exception('Error al crear el accesorio: ' . $e->getMessage());
    }
}

function getAccessory() {
    global $pdo;
    
    $id = (int)($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('ID de accesorio inválido');
    }
    
    try {
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $sql = "SELECT id, name, sort_order FROM equipment_accessories WHERE id = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$id, $tenantValue] : [$id]);
        $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$accessory) {
            throw new Exception('Accesorio no encontrado');
        }
        
        echo json_encode([
            'success' => true,
            'accessory' => $accessory
        ]);
    } catch (PDOException $e) {
        throw new Exception('Error al obtener el accesorio: ' . $e->getMessage());
    }
}

function updateAccessory() {
    global $pdo;
    
    $id = (int)($_POST['accessory_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    
    if ($id <= 0) {
        throw new Exception('ID de accesorio inválido');
    }
    
    if (empty($name)) {
        throw new Exception('El nombre del accesorio es requerido');
    }
    
    try {
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $sql = "SELECT id FROM equipment_accessories WHERE name = ? AND id != ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$name, $id, $tenantValue] : [$name, $id]);
        
        if ($stmt->fetch()) {
            throw new Exception('Ya existe otro accesorio con ese nombre');
        }
        
        $sql = "UPDATE equipment_accessories SET name = ? WHERE id = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$name, $id, $tenantValue] : [$name, $id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Accesorio actualizado exitosamente'
        ]);
    } catch (PDOException $e) {
        throw new Exception('Error al actualizar el accesorio: ' . $e->getMessage());
    }
}

function deleteAccessory() {
    global $pdo;
    
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('ID de accesorio inválido');
    }
    
    try {
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $hasTenantOEA = false;
        try { $c = $pdo->query("SHOW COLUMNS FROM order_equipment_accessories LIKE 'tenant_id'"); $hasTenantOEA = $c && $c->rowCount() > 0; } catch (Exception $e) {}
        $sqlCnt = "SELECT COUNT(*) FROM order_equipment_accessories WHERE accessory_id = ?";
        $paramsCnt = [$id];
        if ($hasTenantOEA && !$perDatabase) { $sqlCnt .= " AND tenant_id = ?"; $paramsCnt[] = $tenantValue; }
        $stmt = $pdo->prepare($sqlCnt);
        $stmt->execute($paramsCnt);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $sql = "UPDATE equipment_accessories SET is_active = 0 WHERE id = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute(($hasTenantEA && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            echo json_encode([
                'success' => true,
                'message' => 'Accesorio desactivado porque está siendo usado en ' . $count . ' orden(es)'
            ]);
            return;
        }
        
        $sql = "DELETE FROM equipment_accessories WHERE id = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantEA && !$perDatabase) ? [$id, $tenantValue] : [$id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Accesorio no encontrado');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Accesorio eliminado exitosamente'
        ]);
    } catch (PDOException $e) {
        throw new Exception('Error al eliminar el accesorio: ' . $e->getMessage());
    }
}

function updateAccessoriesOrder() {
    global $pdo;
    
    // Obtener datos del formulario
    $accessoriesJson = $_POST['accessories'] ?? '';
    
    if (empty($accessoriesJson)) {
        throw new Exception('Datos de orden inválidos');
    }
    
    $accessories = json_decode($accessoriesJson, true);
    
    if (!$accessories || !is_array($accessories)) {
        throw new Exception('Datos de orden inválidos');
    }
    
    try {
        $pdo->beginTransaction();
        
        $tenant_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantEA = hasTenantColumnCached($pdo, 'equipment_accessories');
        $stmt = $pdo->prepare("UPDATE equipment_accessories SET sort_order = ? WHERE id = ?" . (($hasTenantEA && !$perDatabase) ? " AND tenant_id = ?" : ""));
        
        foreach ($accessories as $accessory) {
            if (!isset($accessory['id']) || !isset($accessory['sort_order'])) {
                throw new Exception('Datos de accesorio incompletos');
            }
            
            $params = [(int)$accessory['sort_order'], (int)$accessory['id']];
            if ($hasTenantEA && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Orden actualizado exitosamente'
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw new Exception('Error al actualizar el orden: ' . $e->getMessage());
    }
}
?>
