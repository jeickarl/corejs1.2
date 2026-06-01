<?php
// Headers y error handling
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

// Verificar token CSRF
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
    echo json_encode(['error' => 'Token CSRF inválido o expirado']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action !== 'create_client') {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

try {
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $pdo->beginTransaction();
    
    $client_type = $_POST['modal_client_type'] ?? 'individual';
    $phone = trim($_POST['modal_phone'] ?? '');
    $email = trim($_POST['modal_email'] ?? '');
    $address = trim($_POST['modal_address'] ?? '');
    
    if ($client_type === 'individual') {
        $first_name = trim($_POST['modal_name'] ?? '');
        $company_name = '';
        $id_number = trim($_POST['modal_identification_number'] ?? '');
        
        if (empty($first_name)) {
            throw new Exception('El nombre es obligatorio');
        }
        if (empty($id_number)) {
            throw new Exception('El número de identificación es obligatorio');
        }
    } else {
        $first_name = '';
        $company_name = trim($_POST['modal_company_name'] ?? '');
        $id_number = trim($_POST['modal_company_nit'] ?? '');
        
        if (empty($company_name)) {
            throw new Exception('La razón social es obligatoria');
        }
        if (empty($id_number)) {
            throw new Exception('El NIT es obligatorio');
        }
    }
    
    $id_number_norm = preg_replace('/\D/', '', $id_number);
    
    // Verificar si ya existe un cliente con el mismo documento
    if (!empty($id_number_norm)) {
        $check_query = "SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?";
        $params = [$id_number_norm];
        if (!$perDatabase) {
            $check_query .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute($params);
        
        if ($check_stmt->fetch()) {
            throw new Exception('Ya existe un cliente con este número de identificación');
        }
    }
    
    // Insertar nuevo cliente
    if ($perDatabase) {
        $insert_query = "
            INSERT INTO clients (
                first_name, company_name, client_type,
                id_number, phone, email, address, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        $stmt = $pdo->prepare($insert_query);
        $stmt->execute([
            $first_name, $company_name, $client_type,
            $id_number_norm, $phone, $email, $address
        ]);
    } else {
        $insert_query = "
            INSERT INTO clients (
                tenant_id, first_name, company_name, client_type,
                id_number, phone, email, address, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        $stmt = $pdo->prepare($insert_query);
        $stmt->execute([
            $tenant_id,
            $first_name, $company_name, $client_type,
            $id_number_norm, $phone, $email, $address
        ]);
    }
    
    $client_id = $pdo->lastInsertId();
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $colStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'client_number'");
        $colStmt->execute([$dbName]);
        if ((int)$colStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN client_number INT(11) NOT NULL DEFAULT 0 AFTER id");
            try { $pdo->exec("ALTER TABLE clients ADD UNIQUE KEY unique_client_tenant (client_number, tenant_id)"); } catch (Throwable $__) {}
        }
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $stmtMax = $perDatabase
            ? $pdo->prepare("SELECT MAX(client_number) FROM clients")
            : $pdo->prepare("SELECT MAX(client_number) FROM clients WHERE tenant_id = ?");
        $stmtMax->execute($perDatabase ? [] : [$tenantValue]);
        $maxDb = (int)($stmtMax->fetchColumn() ?: 0);
        $cfgVal = (int)cfg_get('client_next_number', 0);
        $startAt = max($maxDb, $cfgVal) + 1;
        $nextCode = getNextTenantSequence($pdo, $tenant_id, 'clients', $startAt);
        if ($perDatabase) {
            $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ?")->execute([(int)$nextCode, $client_id]);
        } else {
            $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ? AND tenant_id = ?")->execute([(int)$nextCode, $client_id, $tenantValue]);
        }
    } catch (Throwable $__) {}
    
    // Obtener los datos del cliente creado
    $select_query = "
        SELECT 
            id, first_name, company_name, client_type,
            id_number, phone, email, address
        FROM clients 
        WHERE id = ?
    ";
    $params = [$client_id];
    if (!$perDatabase) {
        $select_query .= " AND tenant_id = ?";
        $params[] = $tenant_id;
    }
    $stmt = $pdo->prepare($select_query);
    $stmt->execute($params);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $pdo->commit();
    
    // Formatear respuesta
    $display_name = $client['company_name'] ?: trim($client['first_name']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Cliente creado exitosamente',
        'client' => [
            'id' => $client['id'],
            'name' => $display_name,
            'phone' => $client['phone'] ?: 'Sin teléfono',
            'id_number' => $client['id_number'] ?: 'Sin documento',
            'type' => $client['client_type']
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error al crear cliente: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al crear cliente',
        'message' => $e->getMessage()
    ]);
}
?>
