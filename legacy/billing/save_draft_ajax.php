<?php
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

$action = $_POST['action'] ?? '';

if ($action !== 'save_draft') {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

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
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit();
}

try {
    $client_id = $_POST['client_id'] ?? '';
    $invoice_type = $_POST['invoice_type'] ?? 'service';
    $invoice_date = $_POST['invoice_date'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $items = $_POST['items'] ?? [];
    $discount_percent = floatval($_POST['discount_percent'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $tax_rate = floatval($_POST['tax_rate'] ?? 19);
    $notes = trim($_POST['notes'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    
    // Validar items
    $valid_items = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!empty($item['description']) && floatval($item['quantity']) > 0) {
                $valid_items[] = [
                    'type' => $item['type'] ?? 'manual',
                    'description' => trim($item['description']),
                    'quantity' => floatval($item['quantity']),
                    'unit_price' => floatval($item['unit_price'] ?? 0),
                    'discount' => floatval($item['discount'] ?? 0),
                    'tax' => floatval($item['tax'] ?? 19)
                ];
            }
        }
    }
    
    // Preparar datos del borrador
    $draft_data = [
        'client_id' => $client_id,
        'invoice_type' => $invoice_type,
        'invoice_date' => $invoice_date,
        'due_date' => $due_date,
        'items' => $valid_items,
        'discount_percent' => $discount_percent,
        'discount_amount' => $discount_amount,
        'tax_rate' => $tax_rate,
        'notes' => $notes,
        'terms_conditions' => $terms_conditions,
        'payment_method' => $payment_method,
        'payment_amount' => $payment_amount,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Verificar si ya existe un borrador para este usuario
    $stmt = $pdo->prepare("SELECT id FROM invoice_drafts WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $existing_draft = $stmt->fetch();
    
    if ($existing_draft) {
        // Actualizar borrador existente
        $stmt = $pdo->prepare("
            UPDATE invoice_drafts 
            SET draft_data = ?, updated_at = NOW() 
            WHERE user_id = ?
        ");
        $stmt->execute([json_encode($draft_data), $_SESSION['user_id']]);
    } else {
        // Crear nuevo borrador
        $stmt = $pdo->prepare("
            INSERT INTO invoice_drafts (user_id, draft_data, created_at, updated_at) 
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], json_encode($draft_data)]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Borrador guardado exitosamente'
    ]);
    
} catch (Exception $e) {
    error_log("Error al guardar borrador: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al guardar borrador',
        'message' => $e->getMessage()
    ]);
}
?>
