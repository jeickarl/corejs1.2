 <?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

header('Content-Type: application/json');

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;
$hasTenantModels = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'models') : false;
$hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;

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

try {
    switch ($action) {
        case 'search_brands':
            if (empty($search)) {
                echo json_encode(['results' => []]);
                exit;
            }
            
            $hasActive = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            $searchParam = '%' . $search . '%';
            $where = ["name LIKE ?"];
            $params = [$searchParam];
            if ($hasTenantBrands && !$perDatabase) {
                $where[] = "tenant_id = ?";
                $params[] = $tenantValue;
            }
            if ($hasActive) {
                $where[] = "is_active = 1";
            }
            $sql = "SELECT id, name FROM brands WHERE " . implode(" AND ", $where) . " ORDER BY name LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'type' => 'existing'
                ];
            }

            if (empty($results) && $perDatabase) {
                try {
                    $stmt2 = $pdo->prepare("SELECT DISTINCT device_brand AS name FROM work_orders WHERE device_brand LIKE ? AND device_brand IS NOT NULL AND device_brand <> '' ORDER BY device_brand LIMIT 10");
                    $stmt2->execute([$searchParam]);
                    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                        $results[] = [
                            'id' => $r['name'],
                            'name' => $r['name'],
                            'type' => 'existing'
                        ];
                    }
                } catch (Throwable $__) {
                }
            }
            
            // Si no hay resultados exactos, agregar opción para crear
            $exactMatch = false;
            foreach ($results as $result) {
                if (strtolower($result['name']) === strtolower($search)) {
                    $exactMatch = true;
                    break;
                }
            }
            
            if (!$exactMatch && !empty($search)) {
                array_unshift($results, [
                    'id' => 'create_new',
                    'name' => $search,
                    'type' => 'create',
                    'display' => 'Crear nueva marca: "' . $search . '"'
                ]);
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'create_brand':
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('El nombre de la marca es obligatorio');
            }
            
            // Verificar si ya existe
            $checkSql = "SELECT id FROM brands WHERE name = ?";
            $params = [$name];
            if ($hasTenantBrands && !$perDatabase) {
                $checkSql .= " AND tenant_id = ?";
                $params[] = $tenantValue;
            }
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute($params);
            
            if ($checkStmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'brand' => ['id' => $name, 'name' => $name],
                    'message' => 'La marca ya existe'
                ]);
                exit;
            }
            
            // Crear nueva marca
            if ($hasTenantBrands) {
                $insertSql = "INSERT INTO brands (tenant_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$tenantValue, $name]);
            } else {
                $insertSql = "INSERT INTO brands (name, is_active, created_at) VALUES (?, 1, NOW())";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$name]);
            }
            
            echo json_encode([
                'success' => true,
                'brand' => ['id' => $name, 'name' => $name],
                'message' => 'Marca creada exitosamente'
            ]);
            break;
            
        case 'search_models':
            if (empty($search)) {
                echo json_encode(['results' => []]);
                exit;
            }
            
            $brand_name = $_POST['brand_name'] ?? '';
            
            if (!empty($brand_name)) {
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM models LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $sql = "SELECT DISTINCT m.name 
                        FROM models m 
                        JOIN brands b ON m.brand_id = b.id" . (($hasTenantBrands && $hasTenantModels && !$perDatabase) ? " AND b.tenant_id = m.tenant_id" : "") . "
                        WHERE b.name = ? AND m.name LIKE ?" . (($hasTenantModels && !$perDatabase) ? " AND m.tenant_id = ?" : "") . ($hasActive ? " AND m.is_active = 1" : "") . "
                        ORDER BY m.name LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $searchParam = '%' . $search . '%';
                $params = [$brand_name, $searchParam];
                if ($hasTenantModels && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
            } else {
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM models LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $sql = "SELECT DISTINCT m.name, b.name as brand_name 
                        FROM models m 
                        JOIN brands b ON m.brand_id = b.id" . (($hasTenantBrands && $hasTenantModels && !$perDatabase) ? " AND b.tenant_id = m.tenant_id" : "") . "
                        WHERE m.name LIKE ?" . (($hasTenantModels && !$perDatabase) ? " AND m.tenant_id = ?" : "") . ($hasActive ? " AND m.is_active = 1" : "") . "
                        ORDER BY m.name LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $searchParam = '%' . $search . '%';
                $params = [$searchParam];
                if ($hasTenantModels && !$perDatabase) { $params[] = $tenantValue; }
                $stmt->execute($params);
            }
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $display = $row['name'];
                if (isset($row['brand_name'])) {
                    $display .= ' (' . $row['brand_name'] . ')';
                }
                
                $results[] = [
                    'id' => $row['name'],
                    'name' => $row['name'],
                    'type' => 'existing',
                    'display' => $display
                ];
            }

            if (empty($results) && $perDatabase) {
                try {
                    if (!empty($brand_name)) {
                        $stmt2 = $pdo->prepare("SELECT DISTINCT device_model AS name FROM work_orders WHERE device_brand = ? AND device_model LIKE ? AND device_model IS NOT NULL AND device_model <> '' ORDER BY device_model LIMIT 10");
                        $stmt2->execute([$brand_name, $searchParam]);
                        while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                            $results[] = [
                                'id' => $r['name'],
                                'name' => $r['name'],
                                'type' => 'existing',
                                'display' => $r['name']
                            ];
                        }
                    } else {
                        $stmt2 = $pdo->prepare("SELECT DISTINCT device_model AS name, device_brand AS brand_name FROM work_orders WHERE device_model LIKE ? AND device_model IS NOT NULL AND device_model <> '' ORDER BY device_model LIMIT 10");
                        $stmt2->execute([$searchParam]);
                        while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                            $display = $r['name'];
                            if (!empty($r['brand_name'])) {
                                $display .= ' (' . $r['brand_name'] . ')';
                            }
                            $results[] = [
                                'id' => $r['name'],
                                'name' => $r['name'],
                                'type' => 'existing',
                                'display' => $display
                            ];
                        }
                    }
                } catch (Throwable $__) {
                }
            }
            
            // Si no hay resultados exactos, agregar opción para crear
            $exactMatch = false;
            foreach ($results as $result) {
                if (strtolower($result['name']) === strtolower($search)) {
                    $exactMatch = true;
                    break;
                }
            }
            
            if (!$exactMatch && !empty($search)) {
                array_unshift($results, [
                    'id' => 'create_new',
                    'name' => $search,
                    'type' => 'create',
                    'display' => 'Crear nuevo modelo: "' . $search . '"'
                ]);
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'create_model':
            $name = trim($_POST['name'] ?? '');
            $brand_name = trim($_POST['brand_name'] ?? '');
            $device_type_name = trim($_POST['device_type_name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('El nombre del modelo es obligatorio');
            }
            
            // Obtener o crear la marca
            $brand_id = null;
            if (!empty($brand_name)) {
                $brandSql = "SELECT id FROM brands WHERE name = ?";
                $brandParams = [$brand_name];
                if ($hasTenantBrands && !$perDatabase) {
                    $brandSql .= " AND tenant_id = ?";
                    $brandParams[] = $tenantValue;
                }
                $brandStmt = $pdo->prepare($brandSql);
                $brandStmt->execute($brandParams);
                $brand = $brandStmt->fetch();
                
                if ($brand) {
                    $brand_id = $brand['id'];
                } else {
                    if ($hasTenantBrands) {
                        $createBrandSql = "INSERT INTO brands (tenant_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())";
                        $createBrandStmt = $pdo->prepare($createBrandSql);
                        $createBrandStmt->execute([$tenantValue, $brand_name]);
                    } else {
                        $createBrandSql = "INSERT INTO brands (name, is_active, created_at) VALUES (?, 1, NOW())";
                        $createBrandStmt = $pdo->prepare($createBrandSql);
                        $createBrandStmt->execute([$brand_name]);
                    }
                    $brand_id = $pdo->lastInsertId();
                }
            }
            
            // Obtener o crear el tipo de dispositivo
            $device_type_id = null;
            if (!empty($device_type_name)) {
                $typeSql = "SELECT id FROM device_types WHERE name = ?";
                $typeParams = [$device_type_name];
                if ($hasTenantDeviceTypes && !$perDatabase) {
                    $typeSql .= " AND tenant_id = ?";
                    $typeParams[] = $tenantValue;
                }
                $typeStmt = $pdo->prepare($typeSql);
                $typeStmt->execute($typeParams);
                $type = $typeStmt->fetch();
                
                if ($type) {
                    $device_type_id = $type['id'];
                } else {
                    if ($hasTenantDeviceTypes) {
                        $createTypeSql = "INSERT INTO device_types (tenant_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())";
                        $createTypeStmt = $pdo->prepare($createTypeSql);
                        $createTypeStmt->execute([$tenantValue, $device_type_name]);
                    } else {
                        $createTypeSql = "INSERT INTO device_types (name, is_active, created_at) VALUES (?, 1, NOW())";
                        $createTypeStmt = $pdo->prepare($createTypeSql);
                        $createTypeStmt->execute([$device_type_name]);
                    }
                    $device_type_id = $pdo->lastInsertId();
                }
            }
            
            // Verificar si el modelo ya existe
            $checkSql = "SELECT id FROM models WHERE name = ?";
            $checkParams = [$name];
            if ($hasTenantModels && !$perDatabase) {
                $checkSql .= " AND tenant_id = ?";
                $checkParams[] = $tenantValue;
            }
            if ($brand_id) {
                $checkSql .= " AND brand_id = ?";
                $checkStmt = $pdo->prepare($checkSql);
                $checkParams[] = $brand_id;
                $checkStmt->execute($checkParams);
            } else {
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($checkParams);
            }
            
            if ($checkStmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'model' => ['id' => $name, 'name' => $name],
                    'message' => 'El modelo ya existe'
                ]);
                exit;
            }
            
            // Crear nuevo modelo
            if ($brand_id && $device_type_id) {
                if ($hasTenantModels) {
                    $insertSql = "INSERT INTO models (tenant_id, name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$tenantValue, $name, $brand_id, $device_type_id]);
                } else {
                    $insertSql = "INSERT INTO models (name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$name, $brand_id, $device_type_id]);
                }
            } elseif ($brand_id) {
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $defaultTypeSql = "SELECT id FROM device_types WHERE 1=1";
                $defaultParams = [];
                if ($hasTenantDeviceTypes && !$perDatabase) { $defaultTypeSql .= " AND tenant_id = ?"; $defaultParams[] = $tenantValue; }
                if ($hasActive) { $defaultTypeSql .= " AND is_active = 1"; }
                $defaultTypeSql .= " ORDER BY id LIMIT 1";
                $defaultTypeStmt = $pdo->prepare($defaultTypeSql);
                $defaultTypeStmt->execute($defaultParams);
                $defaultType = $defaultTypeStmt->fetch(PDO::FETCH_ASSOC);
                $defaultTypeId = $defaultType ? $defaultType['id'] : 1;
                
                if ($hasTenantModels) {
                    $insertSql = "INSERT INTO models (tenant_id, name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$tenantValue, $name, $brand_id, $defaultTypeId]);
                } else {
                    $insertSql = "INSERT INTO models (name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$name, $brand_id, $defaultTypeId]);
                }
            } elseif ($device_type_id) {
                $hasActive = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $defaultBrandSql = "SELECT id FROM brands WHERE 1=1";
                $defaultParams = [];
                if ($hasTenantBrands && !$perDatabase) { $defaultBrandSql .= " AND tenant_id = ?"; $defaultParams[] = $tenantValue; }
                if ($hasActive) { $defaultBrandSql .= " AND is_active = 1"; }
                $defaultBrandSql .= " ORDER BY id LIMIT 1";
                $defaultBrandStmt = $pdo->prepare($defaultBrandSql);
                $defaultBrandStmt->execute($defaultParams);
                $defaultBrand = $defaultBrandStmt->fetch(PDO::FETCH_ASSOC);
                $defaultBrandId = $defaultBrand ? $defaultBrand['id'] : 1;
                
                if ($hasTenantModels) {
                    $insertSql = "INSERT INTO models (tenant_id, name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$tenantValue, $name, $defaultBrandId, $device_type_id]);
                } else {
                    $insertSql = "INSERT INTO models (name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$name, $defaultBrandId, $device_type_id]);
                }
            } else {
                $hasActiveB = false; $hasActiveT = false;
                try { $c = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $hasActiveB = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'"); $hasActiveT = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
                $defaultBrandSql = "SELECT id FROM brands WHERE 1=1";
                $defaultBrandParams = [];
                if ($hasTenantBrands && !$perDatabase) { $defaultBrandSql .= " AND tenant_id = ?"; $defaultBrandParams[] = $tenantValue; }
                if ($hasActiveB) { $defaultBrandSql .= " AND is_active = 1"; }
                $defaultBrandSql .= " ORDER BY id LIMIT 1";
                $defaultBrandStmt = $pdo->prepare($defaultBrandSql);
                $defaultBrandStmt->execute($defaultBrandParams);
                $defaultBrand = $defaultBrandStmt->fetch(PDO::FETCH_ASSOC);
                $defaultBrandId = $defaultBrand ? $defaultBrand['id'] : 1;
                
                $defaultTypeSql = "SELECT id FROM device_types WHERE 1=1";
                $defaultTypeParams = [];
                if ($hasTenantDeviceTypes && !$perDatabase) { $defaultTypeSql .= " AND tenant_id = ?"; $defaultTypeParams[] = $tenantValue; }
                if ($hasActiveT) { $defaultTypeSql .= " AND is_active = 1"; }
                $defaultTypeSql .= " ORDER BY id LIMIT 1";
                $defaultTypeStmt = $pdo->prepare($defaultTypeSql);
                $defaultTypeStmt->execute($defaultTypeParams);
                $defaultType = $defaultTypeStmt->fetch(PDO::FETCH_ASSOC);
                $defaultTypeId = $defaultType ? $defaultType['id'] : 1;
                
                if ($hasTenantModels) {
                    $insertSql = "INSERT INTO models (tenant_id, name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$tenantValue, $name, $defaultBrandId, $defaultTypeId]);
                } else {
                    $insertSql = "INSERT INTO models (name, brand_id, device_type_id, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([$name, $defaultBrandId, $defaultTypeId]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'model' => ['id' => $name, 'name' => $name],
                'message' => 'Modelo creado exitosamente'
            ]);
            break;
            
        case 'search_device_types':
            if (empty($search)) {
                echo json_encode(['results' => []]);
                exit;
            }
            
            $hasActive = false; $hasVisible = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'"); $hasActive = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            try { $c = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_visible'"); $hasVisible = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
            $where = ["name LIKE ?"];
            if ($hasActive) { $where[] = "is_active = 1"; }
            if ($hasVisible) { $where[] = "is_visible = 1"; }
            $params = [];
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            if ($hasTenantDeviceTypes && !$perDatabase) { $where[] = "tenant_id = ?"; $params[] = $tenantValue; }
            $sql = "SELECT DISTINCT id, name FROM device_types WHERE " . implode(" AND ", $where) . " ORDER BY name LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'type' => 'existing'
                ];
            }
            
            // Si no hay resultados exactos, agregar opción para crear
            $exactMatch = false;
            foreach ($results as $result) {
                if (strtolower($result['name']) === strtolower($search)) {
                    $exactMatch = true;
                    break;
                }
            }
            
            if (!$exactMatch && !empty($search)) {
                array_unshift($results, [
                    'id' => 'create_new',
                    'name' => $search,
                    'type' => 'create',
                    'display' => 'Crear nuevo tipo: "' . $search . '"'
                ]);
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        case 'create_device_type':
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('El nombre del tipo de dispositivo es obligatorio');
            }
            
            // Verificar si ya existe
            $checkSql = "SELECT id FROM device_types WHERE name = ?";
            $params = [$name];
            if ($hasTenantDeviceTypes && !$perDatabase) { $checkSql .= " AND tenant_id = ?"; $params[] = $tenantValue; }
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute($params);
            
            if ($checkStmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'device_type' => ['id' => $name, 'name' => $name],
                    'message' => 'El tipo de dispositivo ya existe'
                ]);
                exit;
            }
            
            // Crear nuevo tipo de dispositivo
            if ($hasTenantDeviceTypes) {
                $insertSql = "INSERT INTO device_types (tenant_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$tenantValue, $name]);
            } else {
                $insertSql = "INSERT INTO device_types (name, is_active, created_at) VALUES (?, 1, NOW())";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$name]);
            }
            
            echo json_encode([
                'success' => true,
                'device_type' => ['id' => $name, 'name' => $name],
                'message' => 'Tipo de dispositivo creado exitosamente'
            ]);
            break;
            
        case 'get_device_type_name':
            $id = $_POST['id'] ?? '';
            
            if (empty($id)) {
                throw new Exception('ID del tipo de dispositivo es obligatorio');
            }
            
            // Buscar el nombre del tipo de dispositivo por ID
            $sql = "SELECT name FROM device_types WHERE id = ?" . (($hasTenantDeviceTypes && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantDeviceTypes && !$perDatabase) ? [$id, $tenantValue] : [$id]);
            $deviceType = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($deviceType) {
                echo json_encode([
                    'success' => true,
                    'name' => $deviceType['name']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Tipo de dispositivo no encontrado'
                ]);
            }
            break;
        
        case 'diagnostics':
            if (!isAdminSession()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
                break;
            }
            $out = ['tenant_id' => $tenant_id, 'tenant_value' => $tenantValue, 'per_database' => $perDatabase];
            $tables = ['device_types','brands','models'];
            foreach ($tables as $t) {
                $hasTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, $t) : false;
                if ($hasTenant) {
                    $stmtA = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE tenant_id = ?");
                    $stmtA->execute([$tenantValue]);
                    $stmtB = $pdo->query("SELECT COUNT(*) FROM `$t` WHERE tenant_id IS NULL");
                    $stmtC = $pdo->query("SELECT COUNT(*) FROM `$t` WHERE tenant_id = 1");
                    $cntA = (int)$stmtA->fetchColumn();
                    $cntB = (int)$stmtB->fetchColumn();
                    $cntC = (int)$stmtC->fetchColumn();
                    $qA = $pdo->prepare("SELECT id, name FROM `$t` WHERE tenant_id = ? ORDER BY id LIMIT 5");
                    $qA->execute([$tenantValue]);
                    $sampleA = $qA->fetchAll(PDO::FETCH_ASSOC);
                    $qB = $pdo->query("SELECT id, name FROM `$t` WHERE tenant_id IS NULL ORDER BY id LIMIT 5");
                    $sampleB = $qB->fetchAll(PDO::FETCH_ASSOC);
                    $out[$t] = [
                        'count_tenant' => $cntA,
                        'count_global' => $cntB,
                        'count_tenant1' => $cntC,
                        'sample_tenant' => $sampleA,
                        'sample_global' => $sampleB
                    ];
                } else {
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                    $q = $pdo->query("SELECT id, name FROM `$t` ORDER BY id LIMIT 5");
                    $sample = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
                    $out[$t] = [
                        'count_total' => $cnt,
                        'sample' => $sample
                    ];
                }
            }
            $colA = $pdo->query("SHOW COLUMNS FROM brands LIKE 'is_active'"); $out['brands_has_active'] = $colA && $colA->rowCount() > 0;
            $colM = $pdo->query("SHOW COLUMNS FROM models LIKE 'is_active'"); $out['models_has_active'] = $colM && $colM->rowCount() > 0;
            $colDTa = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_active'"); $out['device_types_has_active'] = $colDTa && $colDTa->rowCount() > 0;
            $colDTv = $pdo->query("SHOW COLUMNS FROM device_types LIKE 'is_visible'"); $out['device_types_has_visible'] = $colDTv && $colDTv->rowCount() > 0;
            if ($hasTenantModels && $hasTenantBrands) {
                $mismatchBrand = $pdo->prepare("SELECT COUNT(*) FROM models m JOIN brands b ON m.brand_id=b.id WHERE b.tenant_id IS NOT NULL AND m.tenant_id IS NOT NULL AND b.tenant_id <> m.tenant_id");
                $mismatchBrand->execute();
                $out['models_brand_mismatch'] = (int)$mismatchBrand->fetchColumn();
            }
            if ($hasTenantModels && $hasTenantDeviceTypes) {
                $mismatchType = $pdo->prepare("SELECT COUNT(*) FROM models m JOIN device_types dt ON m.device_type_id=dt.id WHERE dt.tenant_id IS NOT NULL AND m.tenant_id IS NOT NULL AND dt.tenant_id <> m.tenant_id");
                $mismatchType->execute();
                $out['models_type_mismatch'] = (int)$mismatchType->fetchColumn();
            }
            echo json_encode(['success' => true, 'data' => $out]);
            break;
            
        case 'get_all_device_types':
            // Obtener todos los tipos de dispositivo activos y visibles
            $sql = "SELECT id, name FROM device_types WHERE is_active = 1 AND is_visible = 1";
            $params = [];
            if ($hasTenantDeviceTypes && !$perDatabase) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantValue;
            }
            $sql .= " ORDER BY sort_order, name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'type' => 'existing'
                ];
            }
            
            echo json_encode(['results' => $results]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Error en autocompletado de dispositivos: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
