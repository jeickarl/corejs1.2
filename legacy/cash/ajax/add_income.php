<?php
require_once '../../config/session.php';
require_once '../../config/functions.php';
require_once '../../config/database.php';

// Configurar respuesta JSON
header('Content-Type: application/json');

// Verificar autenticación (responder JSON en vez de redirigir)
if (!isValidSession()) {
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

try {
    // Verificar que sea una petición POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    // Verificar que hay una sesión de caja abierta
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : $tenant_id;
    $hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
    $hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
    $hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');

    $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
    $current_session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_session) {
        throw new Exception('No hay una sesión de caja abierta');
    }

    // Validar datos requeridos
    $amount = parseCurrency($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $concept = trim($_POST['concept'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $reference_number = trim($_POST['reference_number'] ?? '');

    if (!$amount || $amount <= 0) {
        throw new Exception('El monto debe ser mayor a 0');
    }

    if (empty($payment_method)) {
        throw new Exception('Debe seleccionar un método de pago');
    }

    if (empty($concept)) {
        throw new Exception('Debe especificar un concepto');
    }

    // Validar método de pago (soporta métodos creados por el tenant)
    $valid_methods = ['Efectivo', 'Transferencia', 'Tarjeta', 'Otros'];
    try {
        $hasStatus = false;
        $hasIsActive = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
            $hasStatus = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        try {
            $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
            $hasIsActive = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        $sqlPm = "SELECT name FROM payment_methods";
        $paramsPm = [];
        if ($hasTenantPaymentMethods && !$perDatabase) {
            $sqlPm .= " WHERE tenant_id = ?";
            $paramsPm[] = $tenantValue;
        } else {
            $sqlPm .= " WHERE 1=1";
        }
        if ($hasStatus) {
            $sqlPm .= " AND status = 'active'";
        } elseif ($hasIsActive) {
            $sqlPm .= " AND is_active = 1";
        }
        $sqlPm .= " ORDER BY name ASC";
        $stmtPm = $pdo->prepare($sqlPm);
        $stmtPm->execute($paramsPm);
        foreach (($stmtPm->fetchAll(PDO::FETCH_COLUMN) ?: []) as $pmName) {
            $n = trim((string)$pmName);
            if ($n !== '' && !in_array($n, $valid_methods, true)) {
                $valid_methods[] = $n;
            }
        }
    } catch (Throwable $e) {}
    if (!in_array($payment_method, $valid_methods, true)) {
        throw new Exception('Método de pago no válido');
    }

    // Insertar el ingreso
    if ($hasTenantCashIncome) {
        $stmt = $pdo->prepare("
            INSERT INTO cash_income (cash_session_id, tenant_id, amount, payment_method, concept, notes, category_id, reference_number, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $current_session['id'],
            $tenantValue,
            $amount,
            $payment_method,
            $concept,
            $notes,
            $category_id,
            $reference_number,
            $_SESSION['user_id']
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO cash_income (cash_session_id, amount, payment_method, concept, notes, category_id, reference_number, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $current_session['id'],
            $amount,
            $payment_method,
            $concept,
            $notes,
            $category_id,
            $reference_number,
            $_SESSION['user_id']
        ]);
    }

    // Registrar actividad
    logActivity($_SESSION['user_id'], 'CREATE_INCOME', 'cash_income', $pdo->lastInsertId());

    echo json_encode([
        'success' => true,
        'message' => 'Ingreso registrado correctamente'
    ]);

} catch (Exception $e) {
    error_log("Error al registrar ingreso: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
