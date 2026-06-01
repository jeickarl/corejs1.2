<?php
// Endpoint para operaciones del Checklist de Accesorios
// Proporciona acciones: get_items, create_item, get_item, update_item, delete_item

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_enhancements.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (in_array($action, ['create_item', 'update_item', 'delete_item'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
if (in_array($action, ['create_item', 'update_item', 'delete_item']) && !isAdminSession()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
    exit;
}

try {
    switch ($action) {
        case 'get_items':
            getItems($pdo);
            break;
        case 'create_item':
            createItem($pdo);
            break;
        case 'get_item':
            getItem($pdo);
            break;
        case 'update_item':
            updateItem($pdo);
            break;
        case 'delete_item':
            deleteItem($pdo);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function ensureTableExists($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'accessories_checklist'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS accessories_checklist (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL UNIQUE,
                description TEXT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error verificando/creando tabla: '.$e->getMessage()]);
        exit;
    }
}

function getItems($pdo) {
    ensureTableExists($pdo);
    $stmt = $pdo->query("SELECT id, name, description, sort_order AS display_order, is_active FROM accessories_checklist ORDER BY sort_order ASC, name ASC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
}

function createItem($pdo) {
    ensureTableExists($pdo);

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $display_order = (int)($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El nombre del item es requerido']);
        return;
    }

    // Evitar duplicados por nombre
    $stmt = $pdo->prepare('SELECT id FROM accessories_checklist WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Ya existe un item con ese nombre']);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO accessories_checklist (name, description, sort_order, is_active) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $description, $display_order, $is_active]);

    echo json_encode(['success' => true, 'message' => 'Item creado exitosamente', 'id' => $pdo->lastInsertId()]);
}

function getItem($pdo) {
    ensureTableExists($pdo);

    $item_id = (int)($_GET['item_id'] ?? 0);
    if ($item_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de item inválido']);
        return;
    }

    $stmt = $pdo->prepare('SELECT id, name, description, sort_order AS display_order, is_active FROM accessories_checklist WHERE id = ?');
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item no encontrado']);
        return;
    }

    echo json_encode(['success' => true, 'item' => $item]);
}

function updateItem($pdo) {
    ensureTableExists($pdo);

    $item_id = (int)($_POST['item_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $display_order = (int)($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($item_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de item inválido']);
        return;
    }

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El nombre del item es requerido']);
        return;
    }

    // Verificar nombre duplicado en otro item
    $stmt = $pdo->prepare('SELECT id FROM accessories_checklist WHERE name = ? AND id != ?');
    $stmt->execute([$name, $item_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Ya existe otro item con ese nombre']);
        return;
    }

    $stmt = $pdo->prepare('UPDATE accessories_checklist SET name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?');
    $stmt->execute([$name, $description, $display_order, $is_active, $item_id]);

    echo json_encode(['success' => true, 'message' => 'Item actualizado exitosamente']);
}

function deleteItem($pdo) {
    ensureTableExists($pdo);

    $item_id = (int)($_POST['item_id'] ?? 0);
    if ($item_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de item inválido']);
        return;
    }

    // Evitar eliminación si existe relación en order_accessories
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_accessories WHERE accessory_id = ?');
        $stmt->execute([$item_id]);
        $usage_count = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Si la tabla no existe, permitir eliminar
        $usage_count = 0;
    }

    if ($usage_count > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No se puede eliminar el item porque está siendo usado en ' . $usage_count . ' orden(es)']);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM accessories_checklist WHERE id = ?');
    $stmt->execute([$item_id]);

    echo json_encode(['success' => true, 'message' => 'Item eliminado exitosamente']);
}
