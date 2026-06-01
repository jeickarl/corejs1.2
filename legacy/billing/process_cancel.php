<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
if (!isValidSession()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!hasRole('admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error de seguridad (CSRF)']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? '';
if (empty($invoice_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID de factura faltante']);
    exit;
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

try {
    // Obtener factura
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $sql = "SELECT * FROM invoices WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute((!$perDatabase && $hasTenantInvoices) ? [$invoice_id, $tenant_id] : [$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Factura no encontrada']);
        exit;
    }

    if ($invoice['status'] === 'cancelled') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'La factura ya está anulada']);
        exit;
    }

    $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');
    $confirm_cancellation = $_POST['confirm_cancellation'] ?? '';

    if (empty($cancellation_reason)) {
        throw new Exception("Debe proporcionar una razón");
    }
    if ($confirm_cancellation !== 'CONFIRMAR') {
        throw new Exception("Confirmación incorrecta");
    }

    // Obtener pagos para revertir
    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    $sql = "SELECT * FROM invoice_payments WHERE invoice_id = ?" . (($hasTenantPayments && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPayments && !$perDatabase) ? [$invoice_id, $tenant_id] : [$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    // Actualizar factura
    $stmt = $pdo->prepare("
        UPDATE invoices 
        SET status = 'cancelled',
            cancelled_by = ?,
            cancelled_at = NOW(),
            cancellation_reason = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "") . "
    ");
    $params = [$_SESSION['user_id'], $cancellation_reason, $invoice_id];
    if (!$perDatabase && $hasTenantInvoices) { $params[] = $tenant_id; }
    $stmt->execute($params);

    // Revertir pagos en caja
    foreach ($payments as $payment) {
        $concept_text = "Reverso Factura " . $invoice['invoice_number'];
        $notes_text = "Reverso de pago anulado. Ref Original: " . $payment['reference_number'];
        
        // Verificar columnas disponibles en cash_expenses
        $has_expense_type = false;
        $has_concept_id = false;
        $has_payment_method = false;
        $has_reference = false;
        $has_description = false;
        $has_notes = false;
        $has_concept = false;
        $has_tenant_col = false;
        
        try {
            $colStmt = $pdo->prepare("SHOW COLUMNS FROM cash_expenses");
            $colStmt->execute();
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $has_expense_type = in_array('expense_type', $columns);
            $has_concept_id = in_array('concept_id', $columns);
            $has_payment_method = in_array('payment_method', $columns);
            $has_reference = in_array('reference_number', $columns);
            $has_description = in_array('description', $columns);
            $has_notes = in_array('notes', $columns);
            $has_concept = in_array('concept', $columns);
            $has_tenant_col = in_array('tenant_id', $columns);
        } catch (Exception $e) {}
        
        if ($has_expense_type && $has_concept_id && $has_payment_method) {
            // Estructura completa
            $stmt = $pdo->prepare("
                INSERT INTO cash_expenses (
                    cash_session_id, expense_type, concept_id, amount, payment_method,
                    reference_number, description, created_by" . ($has_tenant_col ? ", tenant_id" : "") . "
                ) VALUES (?, 'other', 9, ?, ?, ?, ?, ?" . ($has_tenant_col ? ", ?" : "") . ")
            ");
            $params = [
                $payment['cash_session_id'], 
                $payment['payment_amount'], 
                $payment['payment_method'],
                "REV-" . $payment['reference_number'],
                "Reverso de pago anulado - Factura {$invoice['invoice_number']}",
                $_SESSION['user_id']
            ];
            if ($has_tenant_col) { $params[] = $tenantValue; }
            $stmt->execute($params);
        } else {
            // Estructura simple (basada en add_expense.php)
            $fields = ['cash_session_id', 'amount', 'created_by', 'created_at'];
            $values = [$payment['cash_session_id'], $payment['payment_amount'], $_SESSION['user_id']];
            $placeholders = ['?', '?', '?', 'NOW()'];
            if ($has_tenant_col) {
                $fields[] = 'tenant_id';
                $values[] = $tenantValue;
                $placeholders[] = '?';
            }
            
            if ($has_concept) {
                $fields[] = 'concept';
                $values[] = $concept_text;
                $placeholders[] = '?';
            }
            
            if ($has_notes) {
                $fields[] = 'notes';
                $values[] = $notes_text;
                $placeholders[] = '?';
            } elseif ($has_description) {
                $fields[] = 'description';
                $values[] = $notes_text;
                $placeholders[] = '?';
            }
            
            if ($has_payment_method) {
                $fields[] = 'payment_method';
                $values[] = $payment['payment_method'];
                $placeholders[] = '?';
            }
            
            $sql = "INSERT INTO cash_expenses (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }
    }

    logActivity($_SESSION['user_id'], 'CANCEL_INVOICE', 'invoices', $invoice_id);

    $pdo->commit();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Factura anulada correctamente']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
