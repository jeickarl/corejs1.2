<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../includes/print_system.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
if (!isValidSession()) {
    http_response_code(401);
    exit('No autorizado');
}

$invoice_id = $_GET['id'] ?? '';
$modal_type = $_GET['type'] ?? '';

if (empty($invoice_id) || empty($modal_type)) {
    http_response_code(400);
    exit('Parámetros faltantes');
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

try {
    // Obtener factura con todos los detalles necesarios
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $hasTenantClients = hasTenantColumnCached($pdo, 'clients');
    $hasTenantUsers = hasTenantColumnCached($pdo, 'users');
    if ($perDatabase) {
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.client_type, c.email as client_email, c.phone as client_phone,
                   c.address as client_address,
                   c.id_number,
                   u1.name as created_by_name,
                   u2.name as cancelled_by_name
            FROM invoices i
            JOIN clients c ON i.client_id = c.id
            LEFT JOIN users u1 ON i.created_by = u1.id
            LEFT JOIN users u2 ON i.cancelled_by = u2.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
    } else {
        $joinClients = $hasTenantClients ? "JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id" : "JOIN clients c ON i.client_id = c.id";
        $joinU1 = $hasTenantUsers ? "LEFT JOIN users u1 ON i.created_by = u1.id AND u1.tenant_id = i.tenant_id" : "LEFT JOIN users u1 ON i.created_by = u1.id";
        $joinU2 = $hasTenantUsers ? "LEFT JOIN users u2 ON i.cancelled_by = u2.id AND u2.tenant_id = i.tenant_id" : "LEFT JOIN users u2 ON i.cancelled_by = u2.id";
        $sql = "
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.client_type, c.email as client_email, c.phone as client_phone,
                   c.address as client_address,
                   c.id_number,
                   u1.name as created_by_name,
                   u2.name as cancelled_by_name
            FROM invoices i
            {$joinClients}
            {$joinU1}
            {$joinU2}
            WHERE i.id = ?" . ($hasTenantInvoices ? " AND i.tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute((!$perDatabase && $hasTenantInvoices) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    }
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        http_response_code(404);
        exit('Factura no encontrada o acceso denegado');
    }

    // Obtener items
    $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
    $sql = "SELECT * FROM invoice_items WHERE invoice_id = ?" . (($hasTenantItems && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantItems && !$perDatabase) ? [$invoice_id, $tenant_id] : [$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener pagos
    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    $joinPayUsers = ($hasTenantUsers && !$perDatabase) ? "LEFT JOIN users u ON ip.created_by = u.id AND u.tenant_id = ip.tenant_id" : "LEFT JOIN users u ON ip.created_by = u.id";
    $sql = "
        SELECT ip.*, u.name as created_by_name
        FROM invoice_payments ip
        {$joinPayUsers}
        WHERE ip.invoice_id = ?" . (($hasTenantPayments && !$perDatabase) ? " AND ip.tenant_id = ?" : "") . "
        ORDER BY ip.payment_date DESC, ip.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPayments && !$perDatabase) ? [$invoice_id, $tenant_id] : [$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Verificar sesión de caja para pagos
    $cash_session_open = false;
    if ($modal_type === 'payment') {
        try {
            $hasTenantCash = hasTenantColumnCached($pdo, 'cash_sessions');
            $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCash && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCash && !$perDatabase) ? [$tenantValue] : []);
            $cash_session_open = $stmt->fetch() !== false;
        } catch (PDOException $e) {}
    }

    // Obtener métodos de pago para pagos
    $payment_methods = [];
    if ($modal_type === 'payment') {
        $pm_has_status = false; $pm_has_is_active = false;
        try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'"); $pm_has_status = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
        try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'"); $pm_has_is_active = $c && $c->rowCount() > 0; } catch (PDOException $e) {}
        
        try {
            $cols = "id, name";
            if ($pm_has_status) { $cols .= ", status"; }
            elseif ($pm_has_is_active) { $cols .= ", is_active"; }
            $hasTenantPm = hasTenantColumnCached($pdo, 'payment_methods');
            $sql = "SELECT $cols FROM payment_methods" . (($hasTenantPm && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $r) {
                $active = true;
                if ($pm_has_status) { $active = (($r['status'] ?? '') === 'active'); }
                elseif ($pm_has_is_active) { $active = (intval($r['is_active'] ?? 1) === 1); }
                
                if ($active) { 
                    $payment_methods[] = ['id' => intval($r['id']), 'name' => $r['name']]; 
                }
            }
        } catch (PDOException $e) { 
            // Fallback empty
        }
        
        // Asegurar 'Efectivo'
        $has_cash = false;
        foreach($payment_methods as $pm) {
            if(strcasecmp($pm['name'], 'Efectivo') === 0) { $has_cash = true; break; }
        }
        if (!$has_cash) {
            $payment_methods[] = ['id' => 0, 'name' => 'Efectivo'];
        }
    }

} catch (PDOException $e) {
    http_response_code(500);
    exit('Error de base de datos: ' . $e->getMessage());
}

// Renderizar contenido según el tipo de modal
if ($modal_type === 'view') {
    // Registrar actividad
    logActivity($_SESSION['user_id'], 'VIEW_INVOICE', 'invoices', $invoice_id);
    require 'modal_content_view.php';
} elseif ($modal_type === 'payment') {
    require 'modal_content_payment.php';
} elseif ($modal_type === 'cancel') {
    include 'modal_content_cancel.php';
} else {
    echo 'Tipo de modal no válido';
}
?>
