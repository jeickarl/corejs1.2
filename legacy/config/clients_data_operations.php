<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';

require_once '../config/security_enhancements.php';


// Verificar autenticación y permisos de administrador
if (!isValidSession() || !isAdminSession()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$csrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if ($action !== '' && ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf))) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
    exit();
}

switch ($action) {
    case 'export':
        exportClients();
        break;
    case 'import':
        header('Content-Type: application/json');
        importClients();
        break;
    case 'stats':
        header('Content-Type: application/json');
        stats();
        break;
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}

function parseFieldsFromRequest() {
    $fieldsRaw = $_POST['fields'] ?? '[]';
    if (is_string($fieldsRaw)) {
        $decoded = json_decode($fieldsRaw, true);
        $fields = is_array($decoded) ? $decoded : [];
    } elseif (is_array($fieldsRaw)) {
        $fields = $fieldsRaw;
    } else {
        $fields = [];
    }
    // Normalizar a minúsculas
    return array_values(array_unique(array_map(function($f){ return strtolower(trim((string)$f)); }, $fields)));
}

function exportClients() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    
    $format = $_POST['format'] ?? 'csv';
    $fields = parseFieldsFromRequest();
    
    // Construir consulta SQL basada en campos seleccionados
    $selectFields = [];
    $headers = [];
    
    if (in_array('name', $fields, true)) {
        $selectFields[] = "CASE WHEN client_type = 'company' THEN company_name ELSE first_name END as nombre_completo";
        $headers[] = 'Nombre Completo';
    }
    
    if (in_array('phone', $fields, true)) {
        $selectFields[] = 'phone as telefono';
        $headers[] = 'Teléfono';
    }
    
    if (in_array('email', $fields, true)) {
        $selectFields[] = 'email';
        $headers[] = 'Email';
    }
    
    if (in_array('address', $fields, true)) {
        $selectFields[] = 'address as direccion';
        $headers[] = 'Dirección';
    }
    
    if (in_array('identification', $fields, true) || in_array('id_number', $fields, true)) {
        $selectFields[] = 'id_number as numero_identificacion';
        $headers[] = 'Número de Identificación';
    }
    
    if (in_array('dates', $fields, true)) {
        $selectFields[] = 'DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") as fecha_registro';
        $headers[] = 'Fecha de Registro';
    }
    
    if (empty($selectFields)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Debe seleccionar al menos un campo']);
        return;
    }
    
    $sql = "SELECT " . implode(', ', $selectFields) . " FROM clients";
    $params = [];
    if (!$perDatabase) {
        $sql .= " WHERE tenant_id = ?";
        $params[] = $tenant_id;
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        exportToCSV($data, $headers);
    } else {
        exportToExcel($data, $headers);
    }
}

function exportToCSV($data, $headers) {
    $filename = 'clientes_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escribir encabezados
    fputcsv($output, $headers, ';');
    
    // Escribir datos
    foreach ($data as $row) {
        fputcsv($output, array_values($row), ';');
    }
    
    fclose($output);
    exit();
}

function exportToExcel($data, $headers) {
    // Para Excel necesitarías una librería como PhpSpreadsheet
    // Por ahora, exportamos como TSV con extensión .xlsx
    $filename = 'clientes_' . date('Y-m-d_H-i-s') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escribir encabezados
    fputcsv($output, $headers, "\t");
    
    // Escribir datos
    foreach ($data as $row) {
        fputcsv($output, array_values($row), "\t");
    }
    
    fclose($output);
    exit();
}

function importClients() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    
    // Frontend envía 'file'; retrocompatibilidad con 'clients_file'
    $file = $_FILES['file'] ?? $_FILES['clients_file'] ?? null;
    if (!$file || $file['error'] !== 0) {
        echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
        return;
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Archivo inválido']);
        return;
    }

    $size = (int)($file['size'] ?? 0);
    $maxBytes = 10 * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'El archivo supera el límite permitido (10MB)']);
        return;
    }
    
    $updateExisting = isset($_POST['update_existing']) && $_POST['update_existing'] === '1';
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, ['csv', 'xlsx', 'xls'])) {
        echo json_encode(['success' => false, 'message' => 'Formato de archivo no válido']);
        return;
    }
    
    try {
        if ($fileExtension === 'csv') {
            $result = importFromCSV($file['tmp_name'], $updateExisting, $tenant_id, $perDatabase);
        } else {
            $result = importFromExcel($file['tmp_name'], $updateExisting, $tenant_id, $perDatabase);
        }
        
        echo json_encode([
            'success' => true,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors']
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function importFromCSV($filePath, $updateExisting, $tenant_id, $perDatabase) {
    global $pdo;
    
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $clientSeqStart = 1;
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $colStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'client_number'");
        $colStmt->execute([$dbName]);
        if ((int)$colStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN client_number INT(11) NOT NULL DEFAULT 0 AFTER id");
            try { $pdo->exec("ALTER TABLE clients ADD UNIQUE KEY unique_client_tenant (client_number, tenant_id)"); } catch (Throwable $__) {}
        }
        $stmtMax = $perDatabase
            ? $pdo->prepare("SELECT MAX(client_number) FROM clients")
            : $pdo->prepare("SELECT MAX(client_number) FROM clients WHERE tenant_id = ?");
        $stmtMax->execute($perDatabase ? [] : [$tenantValue]);
        $clientSeqStart = (int)($stmtMax->fetchColumn() ?: 0) + 1;
    } catch (Throwable $__) {}
    
    if (($handle = fopen($filePath, 'r')) !== FALSE) {
        // Leer encabezados
        $headers = fgetcsv($handle, 1000, ';');
        if (!$headers) {
            $headers = fgetcsv($handle, 1000, ',');
        }
        
        // Mapear encabezados a campos de base de datos
        $fieldMap = mapHeaders($headers);
        
        while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
            if (empty($data) || count($data) < 1) {
                continue;
            }
            
            try {
                $clientData = [];
                foreach ($fieldMap as $index => $field) {
                    if (isset($data[$index]) && $field) {
                        $clientData[$field] = trim($data[$index]);
                    }
                }
                
                // Validar datos mínimos (solo email requerido)
                if (empty($clientData['email'])) {
                    $errors++;
                    continue;
                }
                
                // Verificar si el cliente ya existe
                if ($perDatabase) {
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
                    $stmt->execute([$clientData['email']]);
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND tenant_id = ? LIMIT 1");
                    $stmt->execute([$clientData['email'], $tenant_id]);
                }
                $existingClient = $stmt->fetch();
                
                if ($existingClient) {
                    if ($updateExisting) {
                        // Actualizar cliente existente
                        $updateFields = [];
                        $updateValues = [];
                        
                        foreach ($clientData as $field => $value) {
                            if ($field !== 'email') {
                                $updateFields[] = "$field = ?";
                                $updateValues[] = $value;
                            }
                        }
                        
                        if (!empty($updateFields)) {
                            $updateValues[] = $clientData['email'];
                            $sql = "UPDATE clients SET " . implode(', ', $updateFields) . " WHERE email = ?";
                            if (!$perDatabase) {
                                $sql .= " AND tenant_id = ?";
                                $updateValues[] = $tenant_id;
                            }
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($updateValues);
                        }
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // Insertar nuevo cliente
                    $clientData['client_type'] = $clientData['client_type'] ?? 'individual';
                    $clientData['created_at'] = date('Y-m-d H:i:s');
                    if (!$perDatabase) {
                        $clientData['tenant_id'] = $tenant_id;
                    }
                    
                    $fields = implode(', ', array_keys($clientData));
                    $placeholders = ':' . implode(', :', array_keys($clientData));
                    
                    $sql = "INSERT INTO clients ($fields) VALUES ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($clientData);
                    try {
                        $newId = (int)$pdo->lastInsertId();
                        if ($newId > 0) {
                            $cfgVal = (int)cfg_get('client_next_number', 0);
                            $startAt = max($clientSeqStart, $cfgVal);
                            $nextCode = getNextTenantSequence($pdo, $tenant_id, 'clients', $startAt);
                            if ($perDatabase) {
                                $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ?")->execute([(int)$nextCode, $newId]);
                            } else {
                                $pdo->prepare("UPDATE clients SET client_number = ? WHERE id = ? AND tenant_id = ?")->execute([(int)$nextCode, $newId, $tenantValue]);
                            }
                            $clientSeqStart = (int)$nextCode + 1;
                        }
                    } catch (Throwable $__) {}
                    $imported++;
                }
                
            } catch (Exception $e) {
                $errors++;
            }
        }
        
        fclose($handle);
    }
    
    return [
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors
    ];
}

function importFromExcel($filePath, $updateExisting, $tenant_id, $perDatabase) {
    // Para archivos Excel, por simplicidad, intentamos leerlos como CSV
    // En una implementación completa usarías PhpSpreadsheet
    return importFromCSV($filePath, $updateExisting, $tenant_id, $perDatabase);
}

function stats() {
    global $pdo;
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    try {
        $sql = "SELECT COUNT(*) FROM clients";
        $params = [];
        if (!$perDatabase) {
            $sql .= " WHERE tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT COUNT(*) FROM clients WHERE email IS NOT NULL AND email <> ''";
        $params = [];
        if (!$perDatabase) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $withEmail = (int)$stmt->fetchColumn();

        $sql = "SELECT COUNT(*) FROM clients WHERE phone IS NOT NULL AND phone <> ''";
        $params = [];
        if (!$perDatabase) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $withPhone = (int)$stmt->fetchColumn();

        $sql = "SELECT COUNT(*) FROM clients WHERE created_at >= (NOW() - INTERVAL 30 DAY)";
        $params = [];
        if (!$perDatabase) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenant_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $recent = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'stats' => [
            'total' => $total,
            'with_email' => $withEmail,
            'with_phone' => $withPhone,
            'recent' => $recent
        ]]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error al obtener estadísticas']);
    }
}

function mapHeaders($headers) {
    $fieldMap = [];
    
    foreach ($headers as $index => $header) {
        $header = strtolower(trim($header));
        
        switch ($header) {
            case 'nombre':
            case 'first_name':
            case 'primer_nombre':
                $fieldMap[$index] = 'first_name';
                break;

            case 'telefono':
            case 'phone':
            case 'teléfono':
                $fieldMap[$index] = 'phone';
                break;
            case 'email':
            case 'correo':
            case 'correo_electronico':
                $fieldMap[$index] = 'email';
                break;
            case 'direccion':
            case 'address':
            case 'dirección':
                $fieldMap[$index] = 'address';
                break;
            case 'numero_id':
            case 'id_number':
            case 'identificacion':
            case 'identificación':
            case 'numero_identificacion':
            case 'identification':
                $fieldMap[$index] = 'id_number';
                break;
            default:
                $fieldMap[$index] = null;
        }
    }
    
    return $fieldMap;
}
?>
