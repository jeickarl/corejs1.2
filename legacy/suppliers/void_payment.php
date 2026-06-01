<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
SecurityEnhancements::setSecurityHeaders();

// Autenticación
requireAuth();
// Permisos
if (!hasRole(['admin', 'inventory'])) {
    header('Location: ../dashboard.php');
    exit();
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSupplierPayments = hasTenantColumnCached($pdo, 'supplier_payments');
$hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');
$hasTenantPurchaseOrders = hasTenantColumnCached($pdo, 'purchase_orders');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payment.php');
    exit();
}

// CSRF
if (!SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    header('Location: payment.php?type=danger&msg=' . urlencode('Token inválido'));
    exit();
}

$payment_id = intval($_POST['payment_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($payment_id <= 0) {
    header('Location: payment.php?type=danger&msg=' . urlencode('Pago inválido'));
    exit();
}

// Detectar columna status
$hasSpStatusColumn = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'status'");
    $hasSpStatusColumn = $colCheck && $colCheck->rowCount() > 0;
} catch (PDOException $e) {
    $hasSpStatusColumn = false;
}

if (!$hasSpStatusColumn) {
    header('Location: payment.php?type=danger&msg=' . urlencode('Función no disponible: falta columna status en pagos'));
    exit();
}

try {
    $pdo->beginTransaction();

    // Bloquear el pago
    $sql = "SELECT id, supplier_id, purchase_order_id, payment_amount, status FROM supplier_payments WHERE id = ?" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND tenant_id = ?" : "") . " FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$payment_id, $tenantValue] : [$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new Exception('Pago no encontrado');
    }
    if ($payment['status'] !== 'active') {
        throw new Exception('El pago ya está anulado o no está activo');
    }

    // Cambiar a voided
    $sql = "UPDATE supplier_payments SET status = 'voided' WHERE id = ?" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$payment_id, $tenantValue] : [$payment_id]);
    // Actualizar registro en cash_expenses con el motivo de anulación
    if (!empty($reason)) {
        // Buscar el registro de cash_expenses correspondiente a este pago
        // Usamos el concepto y monto para identificarlo
        $concept_pattern = "Pago a proveedor%";
        $stmt = $pdo->prepare("
            UPDATE cash_expenses 
            SET notes = CASE 
                WHEN notes IS NULL OR notes = '' THEN CONCAT('ANULADO: ', ?)
                ELSE CONCAT(notes, ' | ANULADO: ', ?)
            END
            WHERE amount = ? 
            AND concept LIKE ? 
            " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $params = [$reason, $reason, $payment['payment_amount'], $concept_pattern];
        if ($hasTenantCashExpenses && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
    }

    // Si está ligado a una orden, recalcular estado de pago
    if (!empty($payment['purchase_order_id'])) {
        $po_id = intval($payment['purchase_order_id']);
        // Bloquear orden
        $sql = "SELECT total_amount FROM purchase_orders WHERE id = ?" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND tenant_id = ?" : "") . " FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$po_id, $tenantValue] : [$po_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $order_total = floatval($order['total_amount']);
            $sumSql = "SELECT COALESCE(SUM(payment_amount), 0) AS total_paid FROM supplier_payments WHERE purchase_order_id = ?" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND tenant_id = ?" : "") . " AND status = 'active'";
            $stmt = $pdo->prepare($sumSql);
            $stmt->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$po_id, $tenantValue] : [$po_id]);
            $total_paid = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0);

            // Nuevo estado
            if ($total_paid <= 0) {
                $new_payment_status = 'pending';
            } elseif ($total_paid + 0.00001 < $order_total) {
                $new_payment_status = 'partially_paid';
            } else {
                $new_payment_status = 'paid';
            }

            // Actualizar orden
            $sql = "UPDATE purchase_orders SET payment_status = ? WHERE id = ?" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$new_payment_status, $po_id, $tenantValue] : [$new_payment_status, $po_id]);
        }
    }

    $pdo->commit();

    // Log actividad con metadata detallada
    $metadata = [
        'type' => 'payment_void',
        'payment_id' => $payment_id,
        'supplier_id' => $payment['supplier_id'],
        'purchase_order_id' => $payment['purchase_order_id'],
        'payment_amount' => $payment['payment_amount'],
        'void_reason' => $reason,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $description = "Pago anulado - Monto: $" . number_format($payment['payment_amount'], 2);
    if (!empty($reason)) {
        $description .= " - Motivo: " . substr($reason, 0, 100);
    }
    
    logActivity(
        $_SESSION['user_id'], 
        'VOID_SUPPLIER_PAYMENT', 
        'supplier_payments', 
        $payment_id,
        $description,
        $metadata,
        ['status' => 'active'], // old values
        ['status' => 'voided']  // new values
    );

    $msg = 'Pago anulado correctamente';
    if (!empty($reason)) { $msg .= ' (' . substr($reason, 0, 120) . ')'; }
    header('Location: payment.php?type=success&msg=' . urlencode($msg));
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header('Location: payment.php?type=danger&msg=' . urlencode($e->getMessage()));
    exit();
}
