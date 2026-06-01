<?php
// Asegurar respuesta JSON desde el inicio
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
if (!isValidSession()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Error de seguridad (CSRF)']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? '';
if (empty($invoice_id)) {
    echo json_encode(['success' => false, 'message' => 'ID de factura faltante']);
    exit;
}

// Verificar sesión de caja abierta
$cash_session_open = false;
$current_session_id = null;
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

try {
    $hasTenantCash = hasTenantColumnCached($pdo, 'cash_sessions');
    $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCash && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCash && !$perDatabase) ? [$tenantValue] : []);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($session) {
        $cash_session_open = true;
        $current_session_id = $session['id'];
    }
} catch (PDOException $e) {}

if (!$cash_session_open) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No hay sesión de caja abierta']);
    exit;
}

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
        echo json_encode(['success' => false, 'message' => 'No se puede registrar pago en una factura anulada']);
        exit;
    }

$payment_amount = parseCurrency($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_date = $_POST['payment_date'] ?? '';
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validaciones
    if ($payment_amount <= 0) {
        throw new Exception("El monto debe ser mayor a cero");
    }
    if (empty($payment_method)) {
        throw new Exception("Seleccione un método de pago");
    }
    if (empty($payment_date)) {
        throw new Exception("La fecha es obligatoria");
    }
    if ($payment_amount > $invoice['pending_amount']) {
        throw new Exception("El monto excede el saldo pendiente");
    }

    $pdo->beginTransaction();

    // Determinar cuenta predeterminada del método (si existe)
    $pm_account_id = null;
    if ($payment_method && strtolower($payment_method) !== 'efectivo') {
        try {
            $has_pm_tenant = false;
            $has_pma_tenant = false;
            try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'tenant_id'"); $has_pm_tenant = $c && $c->rowCount() > 0; } catch (Exception $e) {}
            try { $c = $pdo->query("SHOW COLUMNS FROM payment_method_accounts LIKE 'tenant_id'"); $has_pma_tenant = $c && $c->rowCount() > 0; } catch (Exception $e) {}
            if ($has_pm_tenant) {
                $mstmt = $pdo->prepare("SELECT id FROM payment_methods WHERE LOWER(name)=LOWER(?) AND tenant_id = ? LIMIT 1");
                $mstmt->execute([$payment_method, $tenantValue]);
            } else {
                $mstmt = $pdo->prepare("SELECT id FROM payment_methods WHERE LOWER(name)=LOWER(?) LIMIT 1");
                $mstmt->execute([$payment_method]);
            }
            $midRow = $mstmt->fetch(PDO::FETCH_ASSOC);
            $mid = intval($midRow['id'] ?? 0);
            if ($mid > 0) {
                if ($has_pma_tenant) {
                    $dstmt = $pdo->prepare("SELECT id FROM payment_method_accounts WHERE method_id=? AND tenant_id = ? AND is_active=1 AND is_default=1 LIMIT 1");
                    $dstmt->execute([$mid, $tenantValue]);
                } else {
                    $dstmt = $pdo->prepare("SELECT id FROM payment_method_accounts WHERE method_id=? AND is_active=1 AND is_default=1 LIMIT 1");
                    $dstmt->execute([$mid]);
                }
                $accRow = $dstmt->fetch(PDO::FETCH_ASSOC);
                $pm_account_id = intval($accRow['id'] ?? 0) ?: null;
            }
        } catch (Exception $e) {}
    }

    // Insertar pago
    // Verificar si existe la columna pm_account_id
    $has_pm_account_id = false;
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM invoice_payments LIKE 'pm_account_id'");
        $has_pm_account_id = ($stmtCol && $stmtCol->rowCount() > 0);
    } catch (Exception $e) {}

    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    if ($has_pm_account_id) {
        $stmt = $pdo->prepare("
            INSERT INTO invoice_payments (
                invoice_id, payment_amount, payment_method, payment_date,
                reference_number, notes, cash_session_id, pm_account_id, created_by" . ($hasTenantPayments ? ", tenant_id" : "") . "
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?" . ($hasTenantPayments ? ", ?" : "") . ")
        ");
        $params = [
            $invoice_id, $payment_amount, $payment_method, $payment_date,
            $reference_number, $notes, $current_session_id, $pm_account_id, $_SESSION['user_id']
        ];
        if ($hasTenantPayments) { $params[] = $tenantValue; }
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO invoice_payments (
                invoice_id, payment_amount, payment_method, payment_date,
                reference_number, notes, cash_session_id, created_by" . ($hasTenantPayments ? ", tenant_id" : "") . "
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($hasTenantPayments ? ", ?" : "") . ")
        ");
        $params = [
            $invoice_id, $payment_amount, $payment_method, $payment_date,
            $reference_number, $notes, $current_session_id, $_SESSION['user_id']
        ];
        if ($hasTenantPayments) { $params[] = $tenantValue; }
        $stmt->execute($params);
    }

    // Actualizar factura
    $new_paid_amount = $invoice['paid_amount'] + $payment_amount;
    $new_pending_amount = $invoice['total_amount'] - $new_paid_amount;
    $new_payment_status = $new_pending_amount <= 0.01 ? 'paid' : 'partial';

    // Si se completa el pago, actualizar también el estado general a 'paid'
    $status_update_sql = "";
    $params = [$new_paid_amount, $new_pending_amount, $new_payment_status];
    
    if ($new_payment_status === 'paid' && $invoice['status'] !== 'cancelled') {
        $status_update_sql = ", status = 'paid'";
    }
    
    $params[] = $invoice_id;
    if (!$perDatabase && $hasTenantInvoices) {
        $params[] = $tenant_id;
    }

    $stmt = $pdo->prepare("
        UPDATE invoices 
        SET paid_amount = ?, pending_amount = ?, payment_status = ? $status_update_sql, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "") . "
    ");
    $stmt->execute($params);

    // Registrar ingreso en caja
    $concept_text = "Pago de factura " . $invoice['invoice_number'];
    $description_text = "Pago recibido. Ref: " . $reference_number;
    if (!empty($notes)) {
        $description_text .= ". " . $notes;
    }

    // Verificar columnas disponibles en cash_income para adaptar el insert
    $has_invoice_id = false;
    $has_concept_id = false;
    $has_income_type = false;
    $has_tenant_col = false;
    
    try {
        $colStmt = $pdo->prepare("SHOW COLUMNS FROM cash_income");
        $colStmt->execute();
        $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $has_invoice_id = in_array('invoice_id', $columns);
        $has_concept_id = in_array('concept_id', $columns);
        $has_income_type = in_array('income_type', $columns);
        $has_reference = in_array('reference_number', $columns);
        $has_description = in_array('description', $columns);
        $has_notes = in_array('notes', $columns);
        $has_concept = in_array('concept', $columns);
        $has_payment_account_id = in_array('payment_account_id', $columns);
        $has_tenant_col = in_array('tenant_id', $columns);
    } catch (Exception $e) {}

    if ($has_invoice_id && $has_income_type && $has_concept_id) {
        // Estructura completa
        if ($has_payment_account_id) {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, income_type, concept_id, concept, amount, payment_method,
                    reference_number, invoice_id, description, notes, payment_account_id, created_by" . ($has_tenant_col ? ", tenant_id" : "") . "
                ) VALUES (?, 'other', 2, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($has_tenant_col ? ", ?" : "") . ")
            ");
            $params = [
                $current_session_id, 
                "Pago de factura {$invoice['invoice_number']}",
                $payment_amount, $payment_method, $reference_number,
                $invoice_id, 
                "Pago de factura {$invoice['invoice_number']}", 
                $description_text,
                $pm_account_id, $_SESSION['user_id']
            ];
            if ($has_tenant_col) { $params[] = $tenantValue; }
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, income_type, concept_id, concept, amount, payment_method,
                    reference_number, invoice_id, description, notes, created_by" . ($has_tenant_col ? ", tenant_id" : "") . "
                ) VALUES (?, 'other', 2, ?, ?, ?, ?, ?, ?, ?, ?" . ($has_tenant_col ? ", ?" : "") . ")
            ");
            $params = [
                $current_session_id, 
                "Pago de factura {$invoice['invoice_number']}",
                $payment_amount, $payment_method, $reference_number,
                $invoice_id, 
                "Pago de factura {$invoice['invoice_number']}", 
                $description_text,
                $_SESSION['user_id']
            ];
            if ($has_tenant_col) { $params[] = $tenantValue; }
            $stmt->execute($params);
        }
    } else {
        // Estructura simple (basada en add_income.php)
        // Mapear campos disponibles
        $fields = ['cash_session_id', 'amount', 'payment_method', 'created_by', 'created_at'];
        $values = [$current_session_id, $payment_amount, $payment_method, $_SESSION['user_id']];
        $placeholders = ['?', '?', '?', '?', 'NOW()'];
        if (!empty($has_tenant_col)) {
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
            $values[] = $description_text;
            $placeholders[] = '?';
        } elseif ($has_description) {
            $fields[] = 'description';
            $values[] = $description_text;
            $placeholders[] = '?';
        }
        
        if ($has_reference) {
            $fields[] = 'reference_number';
            $values[] = $reference_number;
            $placeholders[] = '?';
        }

        if ($has_payment_account_id) {
            $fields[] = 'payment_account_id';
            $values[] = $pm_account_id;
            $placeholders[] = '?';
        }

        $sql = "INSERT INTO cash_income (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    }

    logActivity($_SESSION['user_id'], 'ADD_INVOICE_PAYMENT', 'invoice_payments', $invoice_id);

    $pdo->commit();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Pago registrado correctamente']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
