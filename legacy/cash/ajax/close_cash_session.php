<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once '../../config/security_enhancements.php';

// Validar Token CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!SecurityEnhancements::verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Error de seguridad: Token no válido']);
    exit();
}

$physical_cash = parseCurrency($_POST['physical_cash'] ?? 0);
$physical_transfer = parseCurrency($_POST['physical_transfer'] ?? 0);
$physical_card = parseCurrency($_POST['physical_card'] ?? 0);
$physical_other = parseCurrency($_POST['physical_other'] ?? 0);
$closing_notes = trim((string)($_POST['closing_observations'] ?? ($_POST['closing_notes'] ?? '')));

try {
    // Obtener sesión actual
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : $tenant_id;
    $hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
    $hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
    $hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');

    $sql = "SELECT * FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'No hay una sesión de caja abierta']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    // Calcular totales del sistema
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%efectivo%' THEN amount ELSE 0 END) as cash_total,
            SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%transfer%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%banco%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%nequi%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%daviplata%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%pse%' 
                THEN amount ELSE 0 END) as transfer_total,
            SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%tarjeta%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%visa%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%master%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%credito%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%crédito%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%debito%' 
                      OR LOWER(TRIM(payment_method)) LIKE '%débito%' 
                THEN amount ELSE 0 END) as card_total,
            SUM(CASE WHEN LOWER(TRIM(payment_method)) NOT LIKE '%efectivo%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%transfer%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%banco%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%nequi%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%daviplata%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%pse%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%tarjeta%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%visa%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%master%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%credito%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%crédito%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%debito%' 
                      AND LOWER(TRIM(payment_method)) NOT LIKE '%débito%' 
                THEN amount ELSE 0 END) as other_total,
            SUM(amount) as total_income
        FROM cash_income 
        WHERE cash_session_id = ? " . (($hasTenantCashIncome && !$perDatabase) ? "AND tenant_id = ?" : "") . "
    ");
    $stmt->execute(($hasTenantCashIncome && !$perDatabase) ? [$session['id'], $tenantValue] : [$session['id']]);
    $income_totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hasExpensePaymentMethod = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM cash_expenses LIKE 'payment_method'");
        $hasExpensePaymentMethod = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}
    if ($hasExpensePaymentMethod) {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%efectivo%' OR payment_method IS NULL OR payment_method = '' THEN amount ELSE 0 END) as cash_expenses,
                SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%transfer%'
                          OR LOWER(TRIM(payment_method)) LIKE '%banco%'
                          OR LOWER(TRIM(payment_method)) LIKE '%nequi%'
                          OR LOWER(TRIM(payment_method)) LIKE '%daviplata%'
                          OR LOWER(TRIM(payment_method)) LIKE '%pse%'
                    THEN amount ELSE 0 END) as transfer_expenses,
                SUM(CASE WHEN LOWER(TRIM(payment_method)) LIKE '%tarjeta%'
                          OR LOWER(TRIM(payment_method)) LIKE '%visa%'
                          OR LOWER(TRIM(payment_method)) LIKE '%master%'
                          OR LOWER(TRIM(payment_method)) LIKE '%credito%'
                          OR LOWER(TRIM(payment_method)) LIKE '%crédito%'
                          OR LOWER(TRIM(payment_method)) LIKE '%debito%'
                          OR LOWER(TRIM(payment_method)) LIKE '%débito%'
                    THEN amount ELSE 0 END) as card_expenses,
                SUM(CASE WHEN LOWER(TRIM(payment_method)) NOT LIKE '%efectivo%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%transfer%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%banco%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%nequi%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%daviplata%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%pse%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%tarjeta%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%visa%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%master%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%credito%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%crédito%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%debito%'
                          AND LOWER(TRIM(payment_method)) NOT LIKE '%débito%'
                    THEN amount ELSE 0 END) as other_expenses,
                SUM(amount) as total_expenses
            FROM cash_expenses
            WHERE cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ");
        $stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$session['id'], $tenantValue] : [$session['id']]);
        $expense_totals = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT SUM(amount) as total_expenses
            FROM cash_expenses 
            WHERE cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND tenant_id = ?" : "") . "
        ");
        $stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$session['id'], $tenantValue] : [$session['id']]);
        $expense_totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $expense_totals['cash_expenses'] = $expense_totals['total_expenses'] ?? 0;
        $expense_totals['transfer_expenses'] = 0;
        $expense_totals['card_expenses'] = 0;
        $expense_totals['other_expenses'] = 0;
    }
    
    $income_cash = $income_totals['cash_total'] ?? 0;
    $income_transfer = $income_totals['transfer_total'] ?? 0;
    $income_card = $income_totals['card_total'] ?? 0;
    $income_other = $income_totals['other_total'] ?? 0;
    $system_total = $income_totals['total_income'] ?? 0;
    $expense_cash = $expense_totals['cash_expenses'] ?? 0;
    $expense_transfer = $expense_totals['transfer_expenses'] ?? 0;
    $expense_card = $expense_totals['card_expenses'] ?? 0;
    $expense_other = $expense_totals['other_expenses'] ?? 0;
    $total_expenses = $expense_totals['total_expenses'] ?? 0;

    $system_cash = $income_cash - $expense_cash;
    $system_transfer = $income_transfer - $expense_transfer;
    $system_card = $income_card - $expense_card;
    $system_other = $income_other - $expense_other;
    $system_net = $system_total - $total_expenses;
    
    // Calcular diferencias
    $cash_difference = $physical_cash - $system_cash;
    $transfer_difference = $physical_transfer - $system_transfer;
    $card_difference = $physical_card - $system_card;
    $other_difference = $physical_other - $system_other;
    $total_difference = ($physical_cash + $physical_transfer + $physical_card + $physical_other) - $system_net;
    
    // Si hay diferencias significativas, requerir observaciones
    if (abs($total_difference) > 0.01 && empty($closing_notes)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Hay diferencias en el conteo. Debes agregar observaciones explicando la diferencia.'
        ]);
        exit();
    }
    
    // Actualizar sesión de caja
    $stmt = $pdo->prepare("
        UPDATE cash_sessions SET 
            closed_by = ?,
            closing_date = NOW(),
            final_amount = ?,
            total_cash = ?,
            total_transfer = ?,
            total_card = ?,
            total_other = ?,
            system_total = ?,
            physical_count = ?,
            difference = ?,
            observations = ?,
            status = 'closed'
        WHERE id = ? " . (($hasTenantCashSessions && !$perDatabase) ? "AND tenant_id = ?" : "") . "
    ");
    $params = [
        $_SESSION['user_id'],
        $system_net,
        $system_cash,
        $system_transfer,
        $system_card,
        $system_other,
        $system_total,
        $physical_cash + $physical_transfer + $physical_card + $physical_other,
        $total_difference,
        $closing_notes,
        $session['id']
    ];
    if ($hasTenantCashSessions && !$perDatabase) { $params[] = $tenantValue; }
    $stmt->execute($params);
    
    // Guardar denominaciones
    if (isset($_POST['denominations'])) {
        $denominations = json_decode($_POST['denominations'], true);
        if (is_array($denominations)) {
            $hasTenantInDenoms = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM cash_closing_denominations LIKE 'tenant_id'");
                $hasTenantInDenoms = $chk && $chk->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $hasTenantInDenoms = false;
            }
            if ($hasTenantInDenoms) {
                $stmt = $pdo->prepare("INSERT INTO cash_closing_denominations (cash_session_id, tenant_id, denomination_value, quantity, subtotal) VALUES (?, ?, ?, ?, ?)");
            } else {
                $stmt = $pdo->prepare("INSERT INTO cash_closing_denominations (cash_session_id, denomination_value, quantity, subtotal) VALUES (?, ?, ?, ?)");
            }
            foreach ($denominations as $denom) {
                $value = floatval($denom['value']);
                $qty = intval($denom['quantity']);
                $subtotal = $value * $qty;
                if ($hasTenantInDenoms) {
                    $stmt->execute([$session['id'], $tenantValue, $value, $qty, $subtotal]);
                } else {
                    $stmt->execute([$session['id'], $value, $qty, $subtotal]);
                }
            }
        }
    }

    // Registrar actividad
    logActivity($_SESSION['user_id'], 'CLOSE_CASH_SESSION', 'cash_sessions', $session['id']);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Caja cerrada exitosamente',
        'session_number' => $session['session_number'],
        'differences' => [
            'cash' => $cash_difference,
            'transfer' => $transfer_difference,
            'card' => $card_difference,
            'other' => $other_difference,
            'total' => $total_difference
        ]
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error al cerrar sesión de caja: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cerrar la caja: ' . $e->getMessage()]);
}
?>
