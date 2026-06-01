<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_enhancements.php';

header('Content-Type: application/json; charset=UTF-8');

// Verificar que el usuario esté logueado
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (in_array($action, ['create_state', 'update_state', 'delete_state'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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

// Verificar permisos de administrador para acciones de modificación
if (in_array($action, ['create_state', 'update_state', 'delete_state'])) {
    if (!isAdminSession()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
        exit;
    }
}

switch ($action) {
    case 'get_states':
        getStates();
        break;
    case 'get_state':
        getState();
        break;
    case 'create_state':
        createState();
        break;
    case 'update_state':
        updateState();
        break;
    case 'delete_state':
        deleteState();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

function getStates() {
    global $pdo;
    
    try {
        // Crear estados por defecto si no existen
        createDefaultStates();
        
        $stmt = $pdo->prepare("SELECT * FROM order_states ORDER BY sort_order ASC, created_at ASC");
        $stmt->execute();
        $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'states' => $states]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener estados: ' . $e->getMessage()]);
    }
}

function getState() {
    global $pdo;
    $id = $_GET['id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM order_states WHERE id = ?");
        $stmt->execute([$id]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($state) {
            echo json_encode(['success' => true, 'state' => $state]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Estado no encontrado']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener estado: ' . $e->getMessage()]);
    }
}

function createState() {
    global $pdo;
    
    $state_key = trim($_POST['state_key'] ?? '');
    $state_name = trim($_POST['state_name'] ?? '');
    $state_color = trim($_POST['state_color'] ?? '#007bff');
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (empty($state_key) || empty($state_name)) {
        echo json_encode(['success' => false, 'message' => 'La clave y el nombre del estado son obligatorios']);
        return;
    }
    
    try {
        // Verificar que la clave no exista
        $stmt = $pdo->prepare("SELECT id FROM order_states WHERE state_key = ?");
        $stmt->execute([$state_key]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Ya existe un estado con esa clave']);
            return;
        }
        
        // Obtener el siguiente sort_order
        $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM order_states");
        $stmt->execute();
        $max_order = $stmt->fetch(PDO::FETCH_ASSOC)['max_order'] ?? 0;
        $sort_order = $max_order + 1;
        
        $stmt = $pdo->prepare("INSERT INTO order_states (state_key, state_name, state_color, active, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$state_key, $state_name, $state_color, $active, $sort_order]);
        
        echo json_encode(['success' => true, 'message' => 'Estado creado exitosamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al crear estado: ' . $e->getMessage()]);
    }
}

function updateState() {
    global $pdo;
    
    $id = $_POST['id'] ?? 0;
    $state_key = trim($_POST['state_key'] ?? '');
    $state_name = trim($_POST['state_name'] ?? '');
    $state_color = trim($_POST['state_color'] ?? '#007bff');
    $active = isset($_POST['active']) ? 1 : 0;
    
    if (empty($state_key) || empty($state_name)) {
        echo json_encode(['success' => false, 'message' => 'La clave y el nombre del estado son obligatorios']);
        return;
    }
    
    try {
        // Verificar que la clave no exista en otro registro
        $stmt = $pdo->prepare("SELECT id FROM order_states WHERE state_key = ? AND id != ?");
        $stmt->execute([$state_key, $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Ya existe un estado con esa clave']);
            return;
        }
        
        $stmt = $pdo->prepare("UPDATE order_states SET state_key = ?, state_name = ?, state_color = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$state_key, $state_name, $state_color, $active, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Estado actualizado exitosamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar estado: ' . $e->getMessage()]);
    }
}

function deleteState() {
    global $pdo;
    $id = $_POST['id'] ?? 0;
    
    try {
        // Verificar si el estado está siendo usado en órdenes
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_orders WHERE status = (SELECT state_key FROM order_states WHERE id = ?)");
        $stmt->execute([$id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'No se puede eliminar el estado porque está siendo usado en ' . $count . ' órdenes']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM order_states WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Estado eliminado exitosamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar estado: ' . $e->getMessage()]);
    }
}

function createDefaultStates() {
    global $pdo;
    
    try {
        // Verificar si ya existen estados
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM order_states");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count == 0) {
            $default_states = [
                ['received', 'Recibido', '#6c757d', 1, 1],
                ['diagnosing', 'Diagnosticando', '#ffc107', 1, 2],
                ['waiting_parts', 'Esperando Repuestos', '#fd7e14', 1, 3],
                ['repairing', 'Reparando', '#17a2b8', 1, 4],
                ['testing', 'Probando', '#20c997', 1, 5],
                ['completed', 'Completado', '#28a745', 1, 6],
                ['delivered', 'Entregado', '#007bff', 1, 7],
                ['canceled', 'Cancelado', '#dc3545', 1, 8]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO order_states (state_key, state_name, state_color, active, sort_order) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($default_states as $state) {
                $stmt->execute($state);
            }
        }
    } catch (Exception $e) {
        // Silenciar errores en caso de que la tabla no exista aún
    }
}
?>
