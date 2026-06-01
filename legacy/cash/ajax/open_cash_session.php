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

$initial_amount = parseCurrency($_POST['initial_amount'] ?? 0);
$initial_transfer = parseCurrency($_POST['initial_transfer'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($initial_amount < 0) {
    echo json_encode(['success' => false, 'message' => 'El monto inicial no puede ser negativo']);
    exit();
}

try {
    // Verificar si ya hay una sesión abierta
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : $tenant_id;
    $hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
    $hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');

    $sql = "SELECT id, session_number FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $openSession = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($openSession) {
        echo json_encode([
            'success' => false, 
            'message' => 'Ya existe una sesión de caja abierta (#' . $openSession['session_number'] . '). Por favor, cierra la sesión actual antes de abrir una nueva.'
        ]);
        exit();
    }
    
    $pdo->beginTransaction();
    
    // Generar número de sesión
    $session_number = 'SES-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Crear nueva sesión de caja
    if ($hasTenantCashSessions) {
        $stmt = $pdo->prepare("
            INSERT INTO cash_sessions (
                tenant_id, session_number, opened_by, opening_date, initial_amount, 
                observations, status
            ) VALUES (?, ?, ?, NOW(), ?, ?, 'open')
        ");
        $stmt->execute([
            $tenantValue,
            $session_number,
            $_SESSION['user_id'],
            $initial_amount,
            $notes
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO cash_sessions (
                session_number, opened_by, opening_date, initial_amount, 
                observations, status
            ) VALUES (?, ?, NOW(), ?, ?, 'open')
        ");
        $stmt->execute([
            $session_number,
            $_SESSION['user_id'],
            $initial_amount,
            $notes
        ]);
    }
    
    $session_id = $pdo->lastInsertId();
    
    // Si hay monto inicial, registrarlo como ingreso
    if ($initial_amount > 0) {
        if ($hasTenantCashIncome) {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, tenant_id, income_type, concept_id, amount, 
                    payment_method, description, created_by
                ) VALUES (?, ?, 'manual', 5, ?, 'Efectivo', 'Fondo inicial de caja', ?)
            ");
            $stmt->execute([$session_id, $tenantValue, $initial_amount, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, income_type, concept_id, amount, 
                    payment_method, description, created_by
                ) VALUES (?, 'manual', 5, ?, 'Efectivo', 'Fondo inicial de caja', ?)
            ");
            $stmt->execute([$session_id, $initial_amount, $_SESSION['user_id']]);
        }
    }
    
    // Si hay saldo inicial de transferencia, registrarlo como ingreso
    if ($initial_transfer > 0) {
        if ($hasTenantCashIncome) {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, tenant_id, income_type, concept_id, amount, 
                    payment_method, description, created_by
                ) VALUES (?, ?, 'manual', 5, ?, 'Transferencia', 'Saldo anterior (Transferencia)', ?)
            ");
            $stmt->execute([$session_id, $tenantValue, $initial_transfer, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_income (
                    cash_session_id, income_type, concept_id, amount, 
                    payment_method, description, created_by
                ) VALUES (?, 'manual', 5, ?, 'Transferencia', 'Saldo anterior (Transferencia)', ?)
            ");
            $stmt->execute([$session_id, $initial_transfer, $_SESSION['user_id']]);
        }
    }
    
    // Registrar actividad
    logActivity($_SESSION['user_id'], 'OPEN_CASH_SESSION', 'cash_sessions', $session_id);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Caja abierta exitosamente',
        'session_number' => $session_number
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = $e->getMessage();
    if (strpos($msg, 'active transaction') !== false) {
        $msg = 'Error interno de la transacción de caja. Por favor intente de nuevo y verifique si la caja quedó abierta.';
    }
    error_log("Error al abrir sesión de caja: " . $msg);
    echo json_encode(['success' => false, 'message' => 'Error al abrir la caja: ' . $msg]);
}
?>

