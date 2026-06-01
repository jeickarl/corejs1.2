<?php
// Configurar headers antes de session_start
header('Content-Type: application/json');

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';

// Verificar autenticación
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Obtener parámetros
$action = $_POST['action'] ?? '';
$search = trim($_POST['search'] ?? '');

if (empty($search)) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
    $hasTenantModels = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'models') : false;
    $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
    switch ($action) {
        case 'search_brands':
            // Buscar marcas
            $hasActive = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            $searchParam = '%' . $search . '%';
            $where = ["name LIKE ?"];
            $params = [$searchParam];
            if ($hasTenantBrands && !$perDatabase) {
                $where[] = "(tenant_id IN (?, 1, 0) OR tenant_id IS NULL)";
                $params[] = $tenantValue;
            }
            if ($hasActive) {
                $where[] = "is_active = 1";
            }
            $sql = "SELECT DISTINCT name FROM brands WHERE " . implode(" AND ", $where) . " ORDER BY name LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'value' => $row['name'],
                    'label' => $row['name']
                ];
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'search_models':
            $brand_id = $_POST['brand_id'] ?? null;
            
            if ($brand_id) {
                // Buscar modelos por marca específica
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM models LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $searchParam = '%' . $search . '%';
                $sql = "SELECT DISTINCT m.name 
                        FROM models m 
                        JOIN brands b ON m.brand_id = b.id
                        WHERE b.id = ? 
                        AND m.name LIKE ?" .
                        (($hasTenantModels && !$perDatabase) ? " AND (m.tenant_id IN (?, 1, 0) OR m.tenant_id IS NULL)" : "") .
                        (($hasTenantBrands && !$perDatabase) ? " AND (b.tenant_id IN (?, 1, 0) OR b.tenant_id IS NULL)" : "") .
                        ($hasActive ? " AND m.is_active = 1" : "") . "
                        ORDER BY m.name LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $params = [$brand_id, $searchParam];
                if ($hasTenantModels && !$perDatabase) { $params[] = $tenantValue; }
                if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
            } else {
                // Buscar todos los modelos
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM models LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $searchParam = '%' . $search . '%';
                $sql = "SELECT DISTINCT m.name, b.name as brand_name 
                        FROM models m 
                        JOIN brands b ON m.brand_id = b.id
                        WHERE m.name LIKE ?" .
                        (($hasTenantModels && !$perDatabase) ? " AND (m.tenant_id IN (?, 1, 0) OR m.tenant_id IS NULL)" : "") .
                        (($hasTenantBrands && !$perDatabase) ? " AND (b.tenant_id IN (?, 1, 0) OR b.tenant_id IS NULL)" : "") .
                        ($hasActive ? " AND m.is_active = 1" : "") . "
                        ORDER BY m.name LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $params = [$searchParam];
                if ($hasTenantModels && !$perDatabase) { $params[] = $tenantValue; }
                if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
            }
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $label = $row['name'];
                if (isset($row['brand_name'])) {
                    $label .= ' (' . $row['brand_name'] . ')';
                }
                
                $results[] = [
                    'value' => $row['name'],
                    'label' => $label
                ];
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'search_device_types':
            // Buscar tipos de dispositivo
            $hasActive = false; $hasVisible = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_visible'"); $hasVisible = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            $searchParam = '%' . $search . '%';
            $where = ["name LIKE ?"];
            $params = [$searchParam];
            if ($hasTenantDeviceTypes && !$perDatabase) {
                $where[] = "(tenant_id IN (?, 1, 0) OR tenant_id IS NULL)";
                $params[] = $tenantValue;
            }
            if ($hasActive) { $where[] = "is_active = 1"; }
            if ($hasVisible) { $where[] = "is_visible = 1"; }
            $sql = "SELECT DISTINCT name FROM device_types WHERE " . implode(" AND ", $where) . " ORDER BY name LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'value' => $row['name'],
                    'label' => $row['name']
                ];
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'get_brand_id':
            // Obtener ID de marca por nombre
            $brand_name = $_POST['brand_name'] ?? '';
            if (empty($brand_name)) {
                echo json_encode(['brand_id' => null]);
                exit;
            }
            
            $hasActive = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            $sql = "SELECT id FROM brands WHERE name = ?" . (($hasTenantBrands && !$perDatabase) ? " AND (tenant_id IN (?, 1, 0) OR tenant_id IS NULL)" : "") . ($hasActive ? " AND is_active = 1" : "") . " ORDER BY id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $params = [$brand_name];
            if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            $brand = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['brand_id' => $brand ? $brand['id'] : null]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Error en búsqueda de dispositivos: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>
