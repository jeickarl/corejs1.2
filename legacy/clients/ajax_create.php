<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

header('Content-Type: application/json');

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

if (empty($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit();
}

try {
    // Obtener datos del formulario
    $client_type = $_POST['client_type'] ?? 'individual';
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Campos específicos según tipo
    $first_name = trim($_POST['first_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $legal_representative = trim($_POST['legal_representative'] ?? '');

    // Validaciones
    $errors = [];
    
    if (empty($id_number)) {
        $errors[] = 'El documento de identidad es obligatorio.';
    }
    
    if (empty($phone)) {
        $errors[] = 'El teléfono es obligatorio.';
    } elseif (!preg_match('/^[0-9+\-\s()]+$/', $phone)) {
        $errors[] = 'El formato del teléfono no es válido.';
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El formato del email no es válido.';
    }
    
    // Validaciones específicas según tipo de cliente
    if ($client_type === 'individual') {
        if (empty($first_name)) {
            $errors[] = 'El nombre es obligatorio para personas naturales.';
        }
    } elseif ($client_type === 'company') {
        if (empty($company_name)) {
            $errors[] = 'La razón social es obligatoria para empresas.';
        }
        if (empty($tax_id)) {
            $errors[] = 'El NIT/RUC es obligatorio para empresas.';
        }
    }
    
    // Normalizar identificaciones a solo dígitos
    $id_number = preg_replace('/\D/', '', $id_number);
    $tax_id = preg_replace('/\D/', '', $tax_id);

    // Verificar que el documento de identidad no esté duplicado
    if (!empty($id_number)) {
        if ($perDatabase) {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? LIMIT 1");
            $stmt->execute([$id_number]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id_number, $tenant_id]);
        }
        if ($stmt->fetch()) {
            $errors[] = 'Ya existe un cliente con este documento de identidad.';
        }
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit();
    }
    
    // Verificar y reparar estructura AUTO_INCREMENT si fuese necesario
    fixTableAutoIncrement($pdo, 'clients');
    
    // Insertar el cliente
    if ($perDatabase) {
        $sql = "INSERT INTO clients (client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $client_type,
            $first_name ?: null,
            $company_name ?: null,
            $tax_id ?: null,
            $legal_representative ?: null,
            $phone,
            $email ?: null,
            $id_number,
            $address ?: null,
            $notes ?: null
        ]);
    } else {
        $sql = "INSERT INTO clients (tenant_id, client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $tenant_id,
            $client_type,
            $first_name ?: null,
            $company_name ?: null,
            $tax_id ?: null,
            $legal_representative ?: null,
            $phone,
            $email ?: null,
            $id_number,
            $address ?: null,
            $notes ?: null
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
        if ($perDatabase) {
            $stmtMax = $pdo->prepare("SELECT MAX(client_number) FROM clients");
            $stmtMax->execute([]);
        } else {
            $stmtMax = $pdo->prepare("SELECT MAX(client_number) FROM clients WHERE tenant_id = ?");
            $stmtMax->execute([$tenant_id]);
        }
        $maxDb = (int)($stmtMax->fetchColumn() ?: 0);
        $cfgVal = (int)cfg_get('client_next_number', 0);
        $startAt = max($maxDb, $cfgVal) + 1;
        if ($perDatabase) {
            $nextCode = $startAt;
            $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ?")->execute([$nextCode, $client_id]);
        } else {
            $nextCode = getNextTenantSequence($pdo, $tenant_id, 'clients', $startAt);
            $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ? AND tenant_id = ?")->execute([$nextCode, $client_id, $tenant_id]);
        }
    } catch (Throwable $__) {}
    
    // Preparar respuesta
    if ($client_type === 'company') {
        $client_name = $company_name;
    } else {
        // Construir nombre completo
        $client_name = $first_name;
    }
    
    echo json_encode([
        'success' => true,
        'client_id' => $client_id,
        'client_code' => isset($nextCode) ? $nextCode : null,
        'client_name' => trim($client_name),
        'client_phone' => $phone,
        'message' => 'Cliente creado exitosamente'
    ]);
    
} catch (PDOException $e) {
    error_log('Error al crear cliente: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al crear el cliente: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error general: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor: ' . $e->getMessage()]);
}
?>
