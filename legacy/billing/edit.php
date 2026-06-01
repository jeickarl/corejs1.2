<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';
require_once '../config/security_enhancements.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;

// Verificar estado de caja
if (!isset($cash_session_open)) {
    $cash_session_open = isCashSessionOpen($pdo);
}

$factura = null;
$errors = [];
$success = false;
$factura_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($factura_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de factura no válido.'));
    exit();
}

// Obtener datos de la factura
try {
    $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
    $hasTenantClients = hasTenantColumnCached($pdo, 'clients');
    if ($perDatabase) {
        $stmt = $pdo->prepare("
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.email as client_email, 
                   c.phone as client_phone,
                   c.id_number as client_document,
                   c.client_type as client_type
            FROM invoices i
            JOIN clients c ON i.client_id = c.id
            WHERE i.id = ?
        ");
        $stmt->execute([$factura_id]);
    } else {
        $joinClients = $hasTenantClients ? "JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id" : "JOIN clients c ON i.client_id = c.id";
        $sql = "
            SELECT i.*, 
                   CASE 
                       WHEN c.client_type = 'company' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.email as client_email, 
                   c.phone as client_phone,
                   c.id_number as client_document,
                   c.client_type as client_type
            FROM invoices i
            {$joinClients}
            WHERE i.id = ?" . ($hasTenantInvoices ? " AND i.tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute((!$perDatabase && $hasTenantInvoices) ? [$factura_id, $tenantValue] : [$factura_id]);
    }
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$factura) {
        header('Location: index.php?error=' . urlencode('Factura no encontrada o acceso denegado.'));
        exit();
    }
    $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
    $itemSql = "SELECT id, item_type, description, quantity, unit_price, total_price FROM invoice_items WHERE invoice_id = ?" . (($hasTenantItems && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id";
    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->execute(($hasTenantItems && !$perDatabase) ? [$factura_id, $tenantValue] : [$factura_id]);
    $factura_items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener pagos existentes
    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
    $paySql = "SELECT * FROM invoice_payments WHERE invoice_id = ?" . (($hasTenantPayments && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY payment_date DESC";
    $payStmt = $pdo->prepare($paySql);
    $payStmt->execute(($hasTenantPayments && !$perDatabase) ? [$factura_id, $tenantValue] : [$factura_id]);
    $existing_payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar la factura.'));
    exit();
}

// Procesar formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_id = $_POST['client_id'] ?? '';
    $invoice_type = $_POST['invoice_type'] ?? 'service';
    $invoice_date = $_POST['invoice_date'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $items_post = $_POST['items'] ?? [];
    $subtotal = 0.0; $tax_amount = 0.0; $total_amount = 0.0;
    $valid_items = [];
    
    // Procesar items
    if (is_array($items_post)) {
        $taxCfg = CompanySettings::getTaxConfig();
        foreach ($items_post as $it) {
            $desc = trim($it['description'] ?? '');
            $qty = floatval($it['quantity'] ?? 0);
            $unit = parseCurrency($it['unit_price'] ?? 0);
            $tax = floatval($it['tax'] ?? 0);

            if (!$taxCfg['enabled']) { $tax = 0; }
            
            if ($desc !== '' && $qty > 0) {
                $line_subtotal = $qty * $unit;
                $line_tax = $line_subtotal * ($tax / 100);
                
                $subtotal += $line_subtotal;
                $tax_amount += $line_tax;
                
                $valid_items[] = [
                    'item_type' => ($it['selected_type'] ?? $it['item_type'] ?? 'manual'),
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'tax_percent' => $tax,
                    'total_price' => $line_subtotal + $line_tax
                ];
            }
        }
    }
    
    $total_amount = $subtotal + $tax_amount;
    $status = $_POST['status'] ?? $factura['status']; // Mantener estado anterior o usar POST si existiera campo
    $notes = trim($_POST['notes'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    
    // Datos de pago (opcional en edición)
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_amount = parseCurrency($_POST['payment_amount'] ?? 0);
    $reference_number = trim($_POST['reference_number'] ?? '');
    
    // Validaciones
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Error de seguridad (CSRF): Ficha de validación faltante o inválida.";
    }
    
    if (empty($client_id)) {
        $errors[] = "Debe seleccionar un cliente.";
    }
    
    if (empty($invoice_date)) {
        $errors[] = "La fecha de factura es obligatoria.";
    }
    
    if ($total_amount <= 0) {
        $errors[] = "El monto total debe ser mayor a cero.";
    }
    
    if ($payment_amount > 0 && empty($payment_method) && empty($_POST['payments'])) {
        $errors[] = "Debe seleccionar un método de pago para registrar el abono.";
    }
    
    // Si no hay errores, proceder con la actualización
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Registrar pago si corresponde y recalcular montos
            $new_paid_amount = floatval($factura['paid_amount']);
            
            // Procesar pagos múltiples
            $payments_post = $_POST['payments'] ?? [];
            
            // Compatibilidad con envíos antiguos o fallbacks (si no hay array payments pero hay campos simples)
            if (empty($payments_post) && $payment_amount > 0) {
                $payments_post[] = [
                    'method' => $payment_method,
                    'amount' => $payment_amount,
                    'reference' => $reference_number
                ];
            }
            
            if (!empty($payments_post)) {
                // Verificar caja abierta una sola vez
                $cash_session = null;
                $hasTenantCash = hasTenantColumnCached($pdo, 'cash_sessions');
                $sqlCs = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCash && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
                $stmtCs = $pdo->prepare($sqlCs);
                $stmtCs->execute(($hasTenantCash && !$perDatabase) ? [$tenantValue] : []);
                $cash_session = $stmtCs->fetch(PDO::FETCH_ASSOC);
                
                // Si hay pagos que procesar, exigimos caja abierta
                $has_valid_payments = false;
                foreach ($payments_post as $pay) {
                    if (parseCurrency($pay['amount'] ?? 0) > 0) {
                        $has_valid_payments = true;
                        break;
                    }
                }
                
                if ($has_valid_payments && !$cash_session) {
                    throw new Exception('No hay una sesión de caja abierta. Debe abrir caja antes de registrar pagos.');
                }

                foreach ($payments_post as $pay) {
                    $p_amt = parseCurrency($pay['amount'] ?? 0);
                    $p_method = trim($pay['method'] ?? '');
                    $p_ref = trim($pay['reference'] ?? '');
                    
                    if ($p_amt <= 0) continue;
                    
                    if (empty($p_method)) {
                        throw new Exception('El método de pago es obligatorio para todos los abonos.');
                    }

                    $notesPay = ($p_method ? ('Pago ' . $p_method) : null);

                    // Determinar cuenta predeterminada del método (si existe) y registrar pago
                    $pm_account_id = null;
                    if ($p_method && strtolower($p_method) !== 'efectivo') {
                        try {
                            $has_pm_tenant = false;
                            $has_pma_tenant = false;
                            try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'tenant_id'"); $has_pm_tenant = $c && $c->rowCount() > 0; } catch (Exception $e) {}
                            try { $c = $pdo->query("SHOW COLUMNS FROM payment_method_accounts LIKE 'tenant_id'"); $has_pma_tenant = $c && $c->rowCount() > 0; } catch (Exception $e) {}
                            if ($has_pm_tenant) {
                                $mstmt = $pdo->prepare("SELECT id FROM payment_methods WHERE LOWER(name)=LOWER(?) AND tenant_id = ? LIMIT 1");
                                $mstmt->execute([$p_method, $tenantValue]);
                            } else {
                                $mstmt = $pdo->prepare("SELECT id FROM payment_methods WHERE LOWER(name)=LOWER(?) LIMIT 1");
                                $mstmt->execute([$p_method]);
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
                    
                    try { $pdo->query("SHOW COLUMNS FROM invoice_payments LIKE 'pm_account_id'"); } catch (Exception $e) {}
                    
                    $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
                    $stmtPay = $hasTenantPayments
                        ? $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, pm_account_id, created_by, created_at, tenant_id) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW(), ?)")
                        : $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, pm_account_id, created_by, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())");
                    $params = [$factura_id, $p_amt, $p_method, $p_ref, $notesPay, $cash_session['id'], $pm_account_id?:null, $_SESSION['user_id']];
                    if ($hasTenantPayments) { $params[] = $tenantValue; }
                    $stmtPay->execute($params);

                    // Registrar ingreso en caja
                    $concept = 'Pago de factura ' . $factura['invoice_number'];
                    $desc = 'Cliente: ' . ($factura['client_name'] ?? '');
                    $notesCash = $notesPay ?: ('Pago ' . $p_method);
                    
                    try { $pdo->query("SHOW COLUMNS FROM cash_income LIKE 'payment_account_id'"); } catch (Exception $e) {}
                    
                    $hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
                    $stmtIncome = $hasTenantCashIncome
                        ? $pdo->prepare("INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, description, notes, amount, payment_method, payment_account_id, created_by, created_at, tenant_id) VALUES (?, 'manual', NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
                        : $pdo->prepare("INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, description, notes, amount, payment_method, payment_account_id, created_by, created_at) VALUES (?, 'manual', NULL, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $params = [$cash_session['id'], $concept, $desc, $notesCash, $p_amt, $p_method, $pm_account_id?:null, $_SESSION['user_id']];
                    if ($hasTenantCashIncome) { $params[] = $tenantValue; }
                    $stmtIncome->execute($params);

                    $new_paid_amount += $p_amt;
                }
            }
            
            $pending_amount = $total_amount - $new_paid_amount;
            if ($pending_amount < 0) $pending_amount = 0;
            
            // Determinar estado de pago
            $payment_status = 'pending';
            if ($new_paid_amount >= $total_amount) {
                $payment_status = 'paid';
            } elseif ($new_paid_amount > 0) {
                $payment_status = 'partial';
            }
            
            // Actualizar factura
            $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
            $sqlUpd = "
                UPDATE invoices SET
                    client_id = ?, document_type = ?, invoice_date = ?, due_date = ?,
                    subtotal = ?, discount_amount = ?, tax_amount = ?, total_amount = ?,
                    paid_amount = ?, pending_amount = ?, payment_status = ?, status = ?, 
                    notes = ?, terms_conditions = ?, updated_at = NOW()
                WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "") . "
            ";
            $stmt = $pdo->prepare($sqlUpd);
            
            $params = [
                $client_id,
                $invoice_type,
                $invoice_date,
                $due_date ?: null,
                $subtotal,
                0, // discount_amount
                $tax_amount,
                $total_amount,
                $new_paid_amount,
                $pending_amount,
                $payment_status,
                $status, // Mantener estado 'sent' o 'draft'
                $notes,
                $terms_conditions,
                $factura_id
            ];
            if (!$perDatabase && $hasTenantInvoices) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            // Actualizar items
            $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
            if ($hasTenantItems && !$perDatabase) {
                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ? AND tenant_id = ?")->execute([$factura_id, $tenantValue]);
            } else {
                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$factura_id]);
            }
            
            if (!empty($valid_items)) {
                $insItem = $hasTenantItems
                    ? $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)")
                    : $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                foreach ($valid_items as $vi) {
                    $params = [$factura_id, $vi['item_type'], $vi['description'], $vi['quantity'], $vi['unit_price'], $vi['total_price']];
                    if ($hasTenantItems) { $params[] = $tenantValue; }
                    $insItem->execute($params);
                }
            }

            // Registrar actividad
            logActivity($_SESSION['user_id'], 'UPDATE_INVOICE', 'invoices', $factura_id);
            
            $pdo->commit();
            
            if ($action === 'save_and_whatsapp') {
                header('Location: index.php?open_modal=' . $factura_id . '&share=whatsapp&success=' . urlencode("Factura actualizada exitosamente."));
            } else {
                header('Location: index.php?open_modal=' . $factura_id . '&success=' . urlencode("Factura actualizada exitosamente."));
            }
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error al actualizar la factura: " . $e->getMessage();
        }
    }
}

// Configuración y datos de soporte
$currency_config = CompanySettings::getCurrency();
$system_config_js = [
    'currency' => $currency_config,
    'tax' => CompanySettings::getTaxConfig()
];

// Obtener métodos de pago desde configuración
$payment_methods = [];
try {
    // Verificar columnas disponibles
    $has_status = false; $has_is_active = false;
    try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'"); $has_status = $c && $c->rowCount() > 0; } catch (Exception $e) {}
    try { $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'"); $has_is_active = $c && $c->rowCount() > 0; } catch (Exception $e) {}

    $cols = "id, name";
    if ($has_status) $cols .= ", status";
    if ($has_is_active) $cols .= ", is_active";
    
    $hasTenantPm = hasTenantColumnCached($pdo, 'payment_methods');
    $sql = "SELECT $cols FROM payment_methods" . (($hasTenantPm && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active = true;
        if ($has_status && ($row['status'] ?? '') === 'inactive') $active = false;
        elseif ($has_is_active && isset($row['is_active']) && $row['is_active'] == 0) $active = false;
        
        if ($active) $payment_methods[] = ['id' => $row['id'], 'name' => $row['name']];
    }
} catch (Exception $e) { error_log("Error cargando métodos: " . $e->getMessage()); }

// Asegurar Efectivo
$has_efectivo = false;
foreach ($payment_methods as $pm) { if (strcasecmp($pm['name'], 'Efectivo') === 0) { $has_efectivo = true; break; } }
if (!$has_efectivo) array_unshift($payment_methods, ['id' => 0, 'name' => 'Efectivo']);

// Preparar datos para la plantilla
$pageTitle = 'Editar Factura ' . $factura['invoice_number'];
$pageDescription = 'Modifique los detalles de la factura existente.';
$isEditing = true;

// Configuración del layout
$page_title = $pageTitle;


// Inicializar formData desde DB
$formData = [
    'client_id' => $factura['client_id'],
    'client_name' => $factura['client_name'],
    'client_document' => $factura['client_document'],
    'client_phone' => $factura['client_phone'],
    'client_type' => $factura['client_type'],
    'invoice_type' => $factura['document_type'],
    'invoice_date' => !empty($factura['invoice_date']) ? date('Y-m-d', strtotime($factura['invoice_date'])) : date('Y-m-d'),
    'due_date' => !empty($factura['due_date']) ? date('Y-m-d', strtotime($factura['due_date'])) : '',
    'notes' => $factura['notes'],
    'terms_conditions' => $factura['terms_conditions'],
    'payment_method' => '', // Resetear para que el usuario elija si quiere agregar un nuevo pago
    'payment_amount' => 0,
    'reference_number' => '',
    'items' => []
];

// Mapear items de DB a estructura de plantilla
foreach ($factura_items as $item) {
    $qty = floatval($item['quantity']);
    $price = floatval($item['unit_price']);
    $total = floatval($item['total_price']);
    
    // Calcular impuesto aproximado
    $tax = 0;
    if ($qty > 0 && $price > 0) {
        $subtotal = $qty * $price;
        if ($subtotal > 0) {
            $tax_val = (($total / $subtotal) - 1) * 100;
            // Redondear a tasas comunes
            $diffs = [
                0 => abs($tax_val - 0),
                5 => abs($tax_val - 5),
                8 => abs($tax_val - 8),
                19 => abs($tax_val - 19)
            ];
            asort($diffs);
            $tax = key($diffs);
        }
    }

    $formData['items'][] = [
        'code' => '', // No disponible en DB invoice_items estándar
        'description' => $item['description'],
        'quantity' => $qty,
        'unit_price' => $price,
        'tax' => $tax,
        'item_id' => '', // No disponible
        'selected_type' => $item['item_type'],
        'total_price' => $total
    ];
}

// Si hubo POST y error, sobrescribir con datos del formulario para no perder cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formData['client_id'] = $_POST['client_id'] ?? $formData['client_id'];
    // Actualizar info cliente si es posible (aunque en POST solo viene ID, el JS se encarga de mostrar info si ID persiste)
    // El template usa client_name para mostrar, si falló POST, tal vez perdamos el nombre visual si no recargamos ajax, 
    // pero el ID se mantiene.
    
    $formData['invoice_type'] = $_POST['invoice_type'] ?? $formData['invoice_type'];
    $formData['invoice_date'] = $_POST['invoice_date'] ?? $formData['invoice_date'];
    $formData['due_date'] = $_POST['due_date'] ?? $formData['due_date'];
    $formData['notes'] = $_POST['notes'] ?? $formData['notes'];
    $formData['terms_conditions'] = $_POST['terms_conditions'] ?? $formData['terms_conditions'];
    $formData['payment_method'] = $_POST['payment_method'] ?? '';
    $formData['payment_amount'] = $_POST['payment_amount'] ?? 0;
    $formData['reference_number'] = $_POST['reference_number'] ?? '';
    
    // Items del POST
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $formData['items'] = []; // Reiniciar items
        foreach ($_POST['items'] as $it) {
            $formData['items'][] = [
                'code' => $it['code'] ?? '',
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 1,
                'unit_price' => $it['unit_price'] ?? 0,
                'tax' => $it['tax'] ?? 0,
                'item_id' => $it['item_id'] ?? '',
                'selected_type' => $it['selected_type'] ?? '',
                'total_price' => 0 // Se recalculará en JS
            ];
        }
    }
}

// Iniciar captura de contenido
ob_start();

// Incluir plantilla
require_once 'form_invoice_template.php';
?>

<!-- Scripts adicionales -->
<script src="invoice_form.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Scripts específicos para edición si son necesarios
        // Por ejemplo, deshabilitar cambio de cliente si no se desea permitir
        
        // Recalcular totales iniciales
        if (typeof calculateTotals === 'function') {
            calculateTotals();
        }
    });
</script>

<?php
// Finalizar captura y cargar layout
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
