<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

header('Content-Type: application/json; charset=UTF-8');

// Verificar autenticación
requireAuth();

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Verificar que el usuario esté autenticado (cualquier usuario puede eliminar borradores)

// Obtener datos del JSON
$input = json_decode(file_get_contents('php://input'), true);
$invoice_id = $input['invoice_id'] ?? null;
$csrf = $input['csrf_token'] ?? '';
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
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit;
}

// Validar ID de factura
if (!$invoice_id || !is_numeric($invoice_id)) {
    echo json_encode(['success' => false, 'message' => 'ID de factura inválido']);
    exit;
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

try {
    // Iniciar transacción
    $pdo->beginTransaction();
    
    // Verificar que la factura existe y es un borrador
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $sql = "SELECT id, status, invoice_number FROM invoices WHERE id = ?" . (($hasTenantInvoices && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantInvoices && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('La factura no existe o acceso denegado');
    }
    
    if ($invoice['status'] !== 'draft' && !hasRole('admin')) {
        throw new Exception('Solo se pueden eliminar facturas en estado borrador. Contacte a un administrador.');
    }
    
    // Eliminar items de la factura
    $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
    $sql = "DELETE FROM invoice_items WHERE invoice_id = ?" . (($hasTenantItems && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantItems && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    
    // Eliminar pagos de la factura
    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    $sql = "DELETE FROM invoice_payments WHERE invoice_id = ?" . (($hasTenantPayments && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPayments && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    
    // Eliminar la factura
    $sql = "DELETE FROM invoices WHERE id = ?" . (($hasTenantInvoices && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantInvoices && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    
    // Confirmar transacción
    $pdo->commit();
    
    // Registrar actividad
    logActivity($_SESSION['user_id'], 'DELETE_INVOICE', 'invoices', $invoice_id, [
        'invoice_number' => $invoice['invoice_number']
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Factura eliminada exitosamente'
    ]);
    
} catch (Exception $e) {
    // Revertir transacción en caso de error
    $pdo->rollBack();
    
    error_log("Error al eliminar factura: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error al eliminar la factura: ' . $e->getMessage()
    ]);
}
?>
