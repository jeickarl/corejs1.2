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
$errors = [];

// Si se envió el formulario, crear la factura y sus ítems
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === '') { $action = 'save_pending'; }
    $client_id = $_POST['client_id'] ?? '';
    $invoice_type = $_POST['invoice_type'] ?? 'service';
    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $order_id = $_POST['order_id'] ?? null;
    $items = $_POST['items'] ?? [];
    $payments_post = $_POST['payments'] ?? [];
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_amount = parseCurrency($_POST['payment_amount'] ?? 0);
    $transfer_platform = trim($_POST['transfer_platform'] ?? '');
    $transfer_account = trim($_POST['transfer_account'] ?? '');
    $reference_number = trim($_POST['reference_number'] ?? '');

    // Unificar pagos si viene del formulario simple
    if (empty($payments_post) && $payment_amount > 0) {
        $payments_post[] = [
            'amount' => $payment_amount,
            'method' => $payment_method,
            'reference' => $reference_number
        ];
    }

    if ($action === 'save_pending') {
        $payments_post = [];
        $payment_method = '';
        $payment_amount = 0;
    }

    if ($action === 'save') {
        foreach ($payments_post as $idx => $p) {
            $amt = parseCurrency($p['amount'] ?? 0);
            $m = trim((string)($p['method'] ?? ''));
            if ($amt > 0 && $m === '') {
                $payments_post[$idx]['method'] = 'Efectivo';
            }
        }
    }

    // Calcular total de pagos
    $total_payment_amount = 0;
    foreach ($payments_post as $p) {
        $total_payment_amount += parseCurrency($p['amount'] ?? 0);
    }

    if ($action === 'save_pending' && (empty($due_date) || trim((string)$due_date) === '')) {
        $days = (int)cfg_get('invoice_due_days_default', 7);
        if ($days < 0) { $days = 7; }
        try {
            $d = DateTime::createFromFormat('Y-m-d', (string)$invoice_date) ?: new DateTime();
        } catch (Throwable $e) {
            $d = new DateTime();
        }
        $d->modify('+' . $days . ' days');
        $due_date = $d->format('Y-m-d');
    }

    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Error de seguridad (CSRF): Ficha de validación faltante o inválida.";
    }
    if (empty($client_id)) { $errors[] = 'Debe seleccionar un cliente.'; }
    if (!is_array($items) || count($items) === 0) { $errors[] = 'Debe agregar al menos un item.'; }

    if ($action === 'save' && $total_payment_amount <= 0) {
        $errors[] = 'Debe registrar un pago para cobrar ahora o usar Guardar pendiente.';
    }
    foreach ($payments_post as $p) {
        $amt = parseCurrency($p['amount'] ?? 0);
        $m = trim((string)($p['method'] ?? ''));
        if ($amt > 0 && $m === '') {
            $errors[] = 'Debe seleccionar un método de pago.';
            break;
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Calcular totales
            $subtotal = 0.0; 
            $tax_amount = 0.0; 
            $total_amount = 0.0;
            $valid_items = [];
            $taxCfg = CompanySettings::getTaxConfig();
            foreach ($items as $it) {
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
                        'item_type' => ($it['selected_type'] ?? $it['type'] ?? 'manual'),
                        'description' => $desc,
                        'quantity' => $qty,
                        'unit_price' => $unit,
                        'tax_percent' => $tax,
                        'total_price' => $line_subtotal + $line_tax
                    ];
                }
            }
            $total_amount = $subtotal + $tax_amount;

            // Generar número de factura único
            $invoice_number = generateNextInvoiceNumber($pdo);

            // Notas: anexar origen de orden si aplica
            if (!empty($order_id)) {
                $origin_note = 'Origen: Orden #' . intval($order_id);
                $notes = $notes ? ($notes . "\n" . $origin_note) : $origin_note;
            }

            // Estado por defecto: Completada (internamente 'sent')
            $status = 'sent';

            // Insertar factura (intentar incluir order_id si existe columna)
            $has_order_id_col = hasColumnCached($pdo, 'invoices', 'order_id');
            $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');

            if ($has_order_id_col) {
                if ($hasTenantInvoices && $perDatabase) {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW(), ?, 1)");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id'], intval($order_id)]);
                } elseif ($hasTenantInvoices && !$perDatabase) {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW(), ?, ?)");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id'], intval($order_id), $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW(), ?)");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id'], intval($order_id)]);
                }
            } else {
                if ($hasTenantInvoices && $perDatabase) {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW(), 1)");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id']]);
                } elseif ($hasTenantInvoices && !$perDatabase) {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW(), ?)");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id'], $tenantValue]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$invoice_number, $client_id, $invoice_type, $invoice_date, ($due_date ?: null), $subtotal, $tax_amount, $total_amount, $total_amount, $status, $notes, $terms_conditions, $_SESSION['user_id']]);
                }
            }

            $invoice_id = $pdo->lastInsertId();
            try {
                if (preg_match('/^([^\d]*)(\d+)$/', $invoice_number, $m)) {
                    cfg_set('invoice_next_number', (string)$m[2]);
                } elseif (ctype_digit($invoice_number)) {
                    cfg_set('invoice_next_number', (string)$invoice_number);
                }
            } catch (Throwable $e) {}

            // Insertar items
            $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
            $itemStmt = $hasTenantItems
                ? $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)")
                : $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            foreach ($valid_items as $vi) {
                $params = [$invoice_id, $vi['item_type'], $vi['description'], $vi['quantity'], $vi['unit_price'], $vi['total_price']];
                if ($hasTenantItems) { $params[] = $tenantValue; }
                $itemStmt->execute($params);
            }

            // Registrar pagos si corresponde
            $new_paid = 0;

            // Procesar Abono de Orden (si existe y es una factura nueva desde orden)
            if (!empty($order_id)) {
                try {
                    if ($perDatabase) {
                        $stmtOrdPay = $pdo->prepare("SELECT advance_payment, payment_method, created_at FROM work_orders WHERE id = ? LIMIT 1");
                        $stmtOrdPay->execute([$order_id]);
                    } else {
                        $stmtOrdPay = $pdo->prepare("SELECT advance_payment, payment_method, created_at FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                        $stmtOrdPay->execute([$order_id, $tenantValue]);
                    }
                    $ordPay = $stmtOrdPay->fetch(PDO::FETCH_ASSOC);

                    if ($ordPay && $ordPay['advance_payment'] > 0) {
                        $abono = $ordPay['advance_payment'];
                        $method = $ordPay['payment_method'] ?? 'Efectivo';
                        $date = $ordPay['created_at'];
                        
                        // Insertar como pago de factura (SIN generar movimiento de caja, ya que se hizo en la orden)
                        $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
                        $stmtPay = $hasTenantPayments
                            ? $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, created_by, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
                            : $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $params = [
                            $invoice_id,
                            $abono,
                            $method,
                            $date,
                            'Abono Orden #' . $order_id,
                            'Abono trasladado desde Orden de Trabajo',
                            $_SESSION['user_id']
                        ];
                        if ($hasTenantPayments) { $params[] = $tenantValue; }
                        $stmtPay->execute($params);
                        
                        $new_paid += $abono;
                    }
                } catch (Exception $e) {
                    error_log("Error trasladando abono a factura: " . $e->getMessage());
                }
            }
            
            if ($total_payment_amount > 0) {
                // Verificar caja abierta (solo una vez)
                $hasTenantCash = hasTenantColumnCached($pdo, 'cash_sessions');
                $sqlCs = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCash && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
                $stmtCs = $pdo->prepare($sqlCs);
                $stmtCs->execute(($hasTenantCash && !$perDatabase) ? [$tenantValue] : []);
                $cash_session = $stmtCs->fetch(PDO::FETCH_ASSOC);
                if (!$cash_session) {
                    throw new Exception('No hay una sesión de caja abierta. Debe abrir caja antes de registrar pagos.');
                }

                $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
                $hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
                $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');

                // Procesar cada pago
                foreach ($payments_post as $pay) {
                    $payment_amount = parseCurrency($pay['amount'] ?? 0);
                    $payment_method = trim($pay['method'] ?? '');
                    $reference_number = trim($pay['reference'] ?? '');
                    
                    if ($payment_amount <= 0) continue;

                    $ref = $reference_number;
                    $notesPay = ($payment_method ? ('Pago ' . $payment_method) : null);

                    // Determinar cuenta predeterminada del método (si existe) y registrar pago
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
                    try { $pdo->query("SHOW COLUMNS FROM invoice_payments LIKE 'pm_account_id'"); } catch (Exception $e) {}
                    $stmtPay = $hasTenantPayments
                        ? $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, pm_account_id, created_by, created_at, tenant_id) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW(), ?)")
                        : $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, pm_account_id, created_by, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())");
                    $params = [$invoice_id, $payment_amount, $payment_method, $ref, $notesPay, $cash_session['id'], $pm_account_id?:null, $_SESSION['user_id']];
                    if ($hasTenantPayments) { $params[] = $tenantValue; }
                    $stmtPay->execute($params);

                    $concept = 'Pago de factura ' . $invoice_number;
                    $desc = 'Cliente: ' . $client_id;
                    $notesCash = $notesPay ?: ('Pago ' . $payment_method);
                    try { $pdo->query("SHOW COLUMNS FROM cash_income LIKE 'payment_account_id'"); } catch (Exception $e) {}
                    $stmtIncome = $hasTenantCashIncome
                        ? $pdo->prepare("INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, description, notes, amount, payment_method, payment_account_id, created_by, created_at, tenant_id) VALUES (?, 'manual', NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
                        : $pdo->prepare("INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, description, notes, amount, payment_method, payment_account_id, created_by, created_at) VALUES (?, 'manual', NULL, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $params = [$cash_session['id'], $concept, $desc, $notesCash, $payment_amount, $payment_method, $pm_account_id?:null, $_SESSION['user_id']];
                    if ($hasTenantCashIncome) { $params[] = $tenantValue; }
                    $stmtIncome->execute($params);
                    
                    $new_paid += $payment_amount;
                }

                // Actualizar montos en factura
                $new_pending = max(0, $total_amount - $new_paid);
                $new_status = ($new_paid >= $total_amount) ? 'paid' : (($new_paid > 0) ? 'partial' : 'pending');
                $sqlUpd = "UPDATE invoices SET paid_amount = ?, pending_amount = ?, payment_status = ? WHERE id = ?";
                $params = [$new_paid, $new_pending, $new_status, $invoice_id];
                if (!$perDatabase && $hasTenantInvoices) {
                    $sqlUpd .= " AND tenant_id = ?";
                    $params[] = $tenantValue;
                }
                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute($params);
            }

            // Registrar actividad
            logActivity($_SESSION['user_id'], 'CREATE_INVOICE_SAVED', 'invoices', $invoice_id);

            if ($pdo->inTransaction()) { $pdo->commit(); }

            if ($action === 'save_and_whatsapp') {
                header('Location: index.php?open_modal=' . $invoice_id . '&share=whatsapp&success=' . urlencode("Factura creada exitosamente."));
            } elseif ($action === 'save_pending') {
                header('Location: index.php?open_modal=' . $invoice_id . '&success=' . urlencode("Venta guardada como pendiente."));
            } else {
                header('Location: index.php?open_modal=' . $invoice_id . '&success=' . urlencode("Factura creada exitosamente."));
            }
            exit();
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Error al crear la factura: ' . $e->getMessage());
            $errors[] = 'Error al crear la factura. Verifica los datos e intenta de nuevo.';
        }
    }
}

require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/company_settings.php';

// Verificar autenticación
requireAuth();

// Verificar sesión de caja abierta
if (!isset($cash_session_open)) {
    $cash_session_open = isCashSessionOpen($pdo);
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'CREATE_INVOICE', 'invoices', null);

// Obtener configuración de moneda
$currency_config = CompanySettings::getCurrency();

// Obtener métodos de pago desde configuración
$payment_methods = [];
try {
    // Verificar columnas disponibles para filtrar activos
    $has_status = false;
    $has_is_active = false;
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
        $has_status = ($stmt && $stmt->rowCount() > 0);
    } catch (Exception $e) {}
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
        $has_is_active = ($stmt && $stmt->rowCount() > 0);
    } catch (Exception $e) {}

    // Construir consulta
    $cols = "id, name";
    if ($has_status) $cols .= ", status";
    if ($has_is_active) $cols .= ", is_active";
    
    $hasTenantPm = hasTenantColumnCached($pdo, 'payment_methods');
    $sql = "SELECT $cols FROM payment_methods" . (($hasTenantPm && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active = true;
        
        // Verificar estado si existen las columnas
        if ($has_status && ($row['status'] ?? '') === 'inactive') {
            $active = false;
        } elseif ($has_is_active && isset($row['is_active']) && $row['is_active'] == 0) {
            $active = false;
        }
        
        if ($active) {
            $payment_methods[] = [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error cargando métodos de pago: " . $e->getMessage());
}

// Asegurar 'Efectivo' como opción básica si no existe
$has_efectivo = false;
foreach ($payment_methods as $pm) {
    if (strcasecmp($pm['name'], 'Efectivo') === 0) {
        $has_efectivo = true;
        break;
    }
}

if (!$has_efectivo) {
    array_unshift($payment_methods, ['id' => 0, 'name' => 'Efectivo']);
}

try {
    $system_config_js = [
        'currency' => $currency_config,
        'tax' => CompanySettings::getTaxConfig()
    ];
} catch (Exception $e) {
    $system_config_js = [
        'currency' => ['symbol' => '$', 'thousands_separator' => '.', 'decimal_separator' => ',', 'decimals' => 0],
        'tax' => ['enabled' => false, 'name' => 'IVA', 'rate' => 19]
    ];
}

// Preparar datos para la plantilla
$pageTitle = 'Nueva Factura';
$pageDescription = 'Complete el formulario para registrar una nueva factura de venta.';
$isEditing = false;
$formData = $_POST ?? []; // Mantener datos si hay error en POST
$existing_payments = []; // Inicializar pagos existentes

// Cargar datos desde Orden de Trabajo si existe order_id
if (empty($formData) && isset($_GET['order_id'])) {
    $order_id_get = intval($_GET['order_id']);
    try {
        $sql = "
            SELECT wo.*, c.first_name, c.company_name, c.id_number, c.phone, c.client_type, c.email,
                   dt.name as device_name
            FROM work_orders wo
            " . ($perDatabase ? "LEFT JOIN clients c ON wo.client_id = c.id" : "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id") . "
            " . ($perDatabase ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id") . "
            WHERE wo.id = ?" . ($perDatabase ? "" : " AND wo.tenant_id = ?");
        $stmtOrder = $pdo->prepare($sql);
        $stmtOrder->execute($perDatabase ? [$order_id_get] : [$order_id_get, $tenant_id]);
        $orderData = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if ($orderData) {
            $clientName = ($orderData['client_type'] == 'company') 
                ? $orderData['company_name'] 
                : $orderData['first_name'];

            $formData = [
                'client_id' => $orderData['client_id'],
                'client_name' => $clientName,
                'client_document' => $orderData['id_number'],
                'client_phone' => $orderData['phone'],
                'client_type' => $orderData['client_type'],
                'order_id' => $order_id_get,
                'invoice_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d'),
                'notes' => "Generado desde Orden de Trabajo #" . $orderData['id'] . "\nEquipo: " . ($orderData['device_name'] ?? 'N/A') . "\nFalla: " . ($orderData['problem_description'] ?? ''),
                'items' => [],
                'payment_amount' => 0
            ];

            // Intentar cargar items de la orden
            $itemsLoaded = false;
            try {
                // Verificar si existe tabla order_items
                $stmtCheck = $pdo->query("SHOW TABLES LIKE 'order_items'");
                if ($stmtCheck->rowCount() > 0) {
                    $stmtItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $stmtItems->execute([$order_id_get]);
                    $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    
                    if ($orderItems) {
                        foreach ($orderItems as $itm) {
                            $formData['items'][] = [
                                'code' => '',
                                'description' => $itm['description'] ?? 'Servicio',
                                'quantity' => $itm['quantity'] ?? 1,
                                'unit_price' => $itm['unit_price'] ?? 0,
                                'tax' => 0,
                                'selected_type' => 'service'
                            ];
                        }
                        $itemsLoaded = count($formData['items']) > 0;
                    }
                }
            } catch (Exception $e) {}

            // Si no hay items, usar el costo final o estimado
            if (!$itemsLoaded) {
                $cost = $orderData['final_cost'] ?? $orderData['estimated_cost'] ?? 0;
                if ($cost > 0) {
                    $formData['items'][] = [
                        'code' => 'ORD-' . $orderData['id'],
                        'description' => 'Servicio Técnico - Orden #' . $orderData['id'],
                        'quantity' => 1,
                        'unit_price' => $cost,
                        'tax' => 0,
                        'selected_type' => 'service'
                    ];
                }
            }

            // Manejar Abono (Advance Payment)
            if (!empty($orderData['advance_payment']) && $orderData['advance_payment'] > 0) {
                $existing_payments[] = [
                    'payment_date' => $orderData['created_at'],
                    'payment_method' => $orderData['payment_method'] ?? 'Efectivo',
                    'payment_amount' => $orderData['advance_payment'],
                    'reference' => 'Abono Orden #' . $orderData['id']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error cargando orden en facturación: " . $e->getMessage());
    }
}

// Configuración del layout
$page_title = $pageTitle;


// Iniciar captura de contenido
ob_start();

// Incluir plantilla
require_once 'form_invoice_template.php';
?>

<!-- Scripts adicionales -->
<script src="invoice_form.js"></script>
<script>
    // Inicializar lógica específica si es necesario
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar fecha de vencimiento si no está establecida
        if (!document.getElementById('due_date').value) {
            // El usuario puede usar el botón +30 días
        }
        
        // Establecer tipo de factura por defecto
        const invoiceTypeInput = document.createElement('input');
        invoiceTypeInput.type = 'hidden';
        invoiceTypeInput.name = 'invoice_type';
        invoiceTypeInput.value = 'service'; // O 'product', según preferencia por defecto
        document.getElementById('invoiceForm').appendChild(invoiceTypeInput);
    });
</script>

<?php
// Finalizar captura y cargar layout
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
