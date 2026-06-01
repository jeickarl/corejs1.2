<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';

// Obtener plantillas de WhatsApp
$wa_templates = [];
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$company_name = 'CORE';
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%'");
        $stmt->execute([]);
    } else {
        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%' AND tenant_id = ?");
        $stmt->execute([$tenant_id]);
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wa_templates[$row['config_key']] = $row['config_value'];
    }
}
catch (Exception $e) {
// Silencioso
}

// Verificar autenticación
requireAuth();

try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT company_name, company_phone FROM company_config LIMIT 1");
        $stmt->execute([]);
    } else {
        $stmt = $pdo->prepare("SELECT company_name, company_phone FROM company_config WHERE tenant_id = ? LIMIT 1");
        $stmt->execute([$tenant_id]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['company_name'])) $company_name = $row['company_name'];
        if (!empty($row['company_phone'])) $company_phone = $row['company_phone'];
    }
}
catch (Exception $e) {
}

logActivity($_SESSION['user_id'], 'VIEW_INVOICES', 'invoices', null);

// Disparar sincronización automáticamente si hay órdenes completadas sin factura
if (hasRole('admin') && !isset($_GET['sync_completed_orders'])) {
    try {
        $has_order_id_col = hasColumnCached($pdo, 'invoices', 'order_id');

        $needSync = false;
        if ($has_order_id_col) {
            // Detectar al menos una orden completada sin factura activa
            if ($perDatabase) {
                $chk = $pdo->prepare("
                    SELECT wo.id 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed' 
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i 
                        WHERE i.order_id = wo.id AND i.status <> 'cancelled'
                    )
                    LIMIT 1
                ");
                $chk->execute([]);
            } else {
                $chk = $pdo->prepare("
                    SELECT wo.id 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed' 
                    AND wo.tenant_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i 
                        WHERE i.order_id = wo.id AND i.status <> 'cancelled' AND i.tenant_id = ?
                    )
                    LIMIT 1
                ");
                $chk->execute([$tenant_id, $tenant_id]);
            }
            $needSync = $chk && ($chk->fetch(PDO::FETCH_ASSOC) ? true : false);
        }
        else {
            // Fallback por notas/items (más costoso, limitar a existencia)
            if ($perDatabase) {
                $chk = $pdo->prepare("
                    SELECT wo.id 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed' 
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM invoices i 
                        LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
                        WHERE i.status <> 'cancelled'
                        AND (i.notes LIKE CONCAT('%Orden #', wo.id, '%') OR ii.description LIKE CONCAT('%Orden #', wo.id, '%'))
                    )
                    LIMIT 1
                ");
                $chk->execute([]);
            } else {
                $chk = $pdo->prepare("
                    SELECT wo.id 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed' 
                    AND wo.tenant_id = ?
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM invoices i 
                        LEFT JOIN invoice_items ii ON ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id
                        WHERE i.status <> 'cancelled' AND i.tenant_id = ?
                        AND (i.notes LIKE CONCAT('%Orden #', wo.id, '%') OR ii.description LIKE CONCAT('%Orden #', wo.id, '%'))
                    )
                    LIMIT 1
                ");
                $chk->execute([$tenant_id, $tenant_id]);
            }
            $needSync = $chk && ($chk->fetch(PDO::FETCH_ASSOC) ? true : false);
        }
        if ($needSync) {
            header('Location: index.php?sync_completed_orders=1');
            exit();
        }
    }
    catch (Throwable $e) {
    // Silencioso: si falla la detección, no auto-sincronizar
    }
}


// Sincronizar facturas para órdenes completadas sin factura
if (isset($_GET['sync_completed_orders']) && hasRole('admin')) {
    try {
        $created = 0;
        $has_order_id_col = hasColumnCached($pdo, 'invoices', 'order_id');
        $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
        $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
        $hasTenantPayments = hasTenantColumnCached($pdo, 'invoice_payments');
        $tenantValue = $perDatabase ? 1 : $tenant_id;

        // Obtener órdenes completadas
        if ($has_order_id_col) {
            if ($perDatabase) {
                $ordersStmt = $pdo->prepare("
                    SELECT wo.id, wo.client_id, wo.reported_issue, wo.diagnosis, wo.solution, wo.final_cost, wo.estimated_cost 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed'
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i 
                        WHERE i.order_id = wo.id AND i.status <> 'cancelled'
                    )
                ");
                $ordersStmt->execute([]);
            } else {
                $ordersStmt = $pdo->prepare("
                    SELECT wo.id, wo.client_id, wo.reported_issue, wo.diagnosis, wo.solution, wo.final_cost, wo.estimated_cost 
                    FROM work_orders wo 
                    WHERE wo.status = 'completed' AND wo.tenant_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i 
                        WHERE i.order_id = wo.id AND i.status <> 'cancelled' AND i.tenant_id = ?
                    )
                ");
                $ordersStmt->execute([$tenant_id, $tenant_id]);
            }
        } else {
            if ($perDatabase) {
                $ordersStmt = $pdo->prepare("SELECT id, client_id, reported_issue, diagnosis, solution, final_cost, estimated_cost FROM work_orders WHERE status = 'completed'");
                $ordersStmt->execute([]);
            } else {
                $ordersStmt = $pdo->prepare("SELECT id, client_id, reported_issue, diagnosis, solution, final_cost, estimated_cost FROM work_orders WHERE status = 'completed' AND tenant_id = ?");
                $ordersStmt->execute([$tenant_id]);
            }
        }
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as $wo) {
            $orderId = (int)$wo['id'];
            // Verificar si ya existe factura vinculada (Skip query if already filtered)
            $exists = null;
            if (!$has_order_id_col) {
                try {
                    $like = '%Orden #' . $orderId . '%';
                    if ($perDatabase) {
                        $chk = $pdo->prepare("SELECT i.id FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id = i.id WHERE i.status != 'cancelled' AND (i.notes LIKE ? OR ii.description LIKE ?) LIMIT 1");
                        $chk->execute([$like, $like]);
                    } else {
                        $chk = $pdo->prepare("SELECT i.id FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id WHERE i.status != 'cancelled' AND i.tenant_id = ? AND (i.notes LIKE ? OR ii.description LIKE ?) LIMIT 1");
                        $chk->execute([$tenant_id, $like, $like]);
                    }
                    $exists = $chk->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }

            if ($exists) {
                continue;
            }

            $clientId = (int)$wo['client_id'];
            if (!$clientId) {
                continue;
            }
            $descParts = ['Orden #' . $orderId];
            if (!empty($wo['solution'])) {
                $descParts[] = $wo['solution'];
            }
            elseif (!empty($wo['diagnosis'])) {
                $descParts[] = $wo['diagnosis'];
            }
            elseif (!empty($wo['reported_issue'])) {
                $descParts[] = $wo['reported_issue'];
            }
            $desc = implode(' - ', $descParts);
            $price = 0.0;
            if ($wo['final_cost'] !== null && $wo['final_cost'] !== '') {
                $price = parseCurrency($wo['final_cost']);
            }
            elseif ($wo['estimated_cost'] !== null && $wo['estimated_cost'] !== '') {
                $price = parseCurrency($wo['estimated_cost']);
            }

            // Obtener configuración de impuestos
            $taxConfig = CompanySettings::getTaxConfig();
            $taxPercent = $taxConfig['enabled'] ? $taxConfig['rate'] : 0;

            $qty = 1;
            $unit = $price;
            $lineSub = $qty * $unit;
            $lineTax = $lineSub * ($taxPercent / 100);
            $subtotal = $lineSub;
            $taxAmount = $lineTax;
            $totalAmount = $subtotal + $taxAmount;

            // Calcular pagos previos (abonos)
            $advancePayment = 0.0;
            $paymentMethod = 'Efectivo';
            try {
                if ($perDatabase) {
                    $stmtWo = $pdo->prepare("SELECT advance_payment, payment_method FROM work_orders WHERE id = ? LIMIT 1");
                    $stmtWo->execute([$orderId]);
                } else {
                    $stmtWo = $pdo->prepare("SELECT advance_payment, payment_method FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $stmtWo->execute([$orderId, $tenant_id]);
                }
                $woData = $stmtWo->fetch(PDO::FETCH_ASSOC);
                if ($woData) {
                    $advancePayment = floatval($woData['advance_payment']);
                    if (!empty($woData['payment_method'])) {
                        $paymentMethod = $woData['payment_method'];
                    }
                }
            }
            catch (Throwable $e) {
            }

            $paidAmount = 0.0;
            $pendingAmount = $totalAmount;
            $paymentStatus = 'pending';
            $statusInv = 'draft';

            if ($advancePayment > 0) {
                $paidAmount = min($advancePayment, $totalAmount); // No pagar más del total
                $pendingAmount = $totalAmount - $paidAmount;
                $paymentStatus = ($pendingAmount <= 0.01) ? 'paid' : 'partial';
                // Si está pagada, marcar como sent/paid en lugar de draft
                if ($paymentStatus === 'paid') {
                    $statusInv = 'paid';
                }
            }

            $invoice_number = generateNextInvoiceNumber($pdo);
            $invoice_date = date('Y-m-d');
            $due_date = null;
            $document_type = 'service';

            $notesInv = 'Origen: Orden #' . $orderId;
            $terms = '';

            if ($has_order_id_col) {
                if ($hasTenantInvoices && !$perDatabase) {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id'], $orderId, $tenantValue]);
                } elseif ($hasTenantInvoices && $perDatabase) {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, 1)");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id'], $orderId]);
                } else {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, order_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id'], $orderId]);
                }
            }
            else {
                if ($hasTenantInvoices && !$perDatabase) {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id'], $tenantValue]);
                } elseif ($hasTenantInvoices && $perDatabase) {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id']]);
                } else {
                    $stmtCreate = $pdo->prepare("INSERT INTO invoices (invoice_number, client_id, document_type, invoice_date, due_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, pending_amount, payment_status, status, notes, terms_conditions, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmtCreate->execute([$invoice_number, $clientId, $document_type, $invoice_date, $due_date, $subtotal, $taxAmount, $totalAmount, $paidAmount, $pendingAmount, $paymentStatus, $statusInv, $notesInv, $terms, $_SESSION['user_id']]);
                }
            }
            $newInvoiceId = (int)$pdo->lastInsertId();

            // Registrar el pago si existe abono
            if ($advancePayment > 0) {
                try {
                    $stmtPay = $hasTenantPayments
                        ? $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, created_by, created_at, tenant_id) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW(), ?)")
                        : $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, created_by, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW())");
                    $ref = 'Abono Orden #' . $orderId;
                    $note = 'Transferido desde Orden #' . $orderId;
                    $params = [$newInvoiceId, $paidAmount, $paymentMethod, $ref, $note, $_SESSION['user_id']];
                    if ($hasTenantPayments) { $params[] = $tenantValue; }
                    $stmtPay->execute($params);
                }
                catch (Throwable $e) {
                }
            }

            $itemStmt = $hasTenantItems
                ? $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)")
                : $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $params = [$newInvoiceId, 'service', $desc, $qty, $unit, $lineSub + $lineTax];
            if ($hasTenantItems) { $params[] = $tenantValue; }
            $itemStmt->execute($params);
            $created++;
        }

        header('Location: index.php?success=' . urlencode('Se sincronizaron ' . $created . ' factura(s) de órdenes completadas'));
        exit();
    }
    catch (Throwable $e) {
        error_log('Error en sincronización de facturas: ' . $e->getMessage());
        header('Location: index.php?error=Error%20al%20sincronizar%20facturas%20de%20ordenes');
        exit();
    }
}

// Verificar estado de caja
$cash_session_open = isCashSessionOpen($pdo);

// Obtener parámetros de búsqueda y filtros
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';
$order_id = $_GET['order_id'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$where_conditions = [];
$params = [];
if (!$perDatabase) {
    $where_conditions[] = "i.tenant_id = ?";
    $params[] = $tenant_id;
}

if (!empty($search)) {
    $where_conditions[] = "(i.invoice_number LIKE ? OR c.first_name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($payment_status_filter)) {
    $where_conditions[] = "i.payment_status = ?";
    $params[] = $payment_status_filter;
}

// Filtro por Orden vinculada
$has_order_id_col = hasColumnCached($pdo, 'invoices', 'order_id');

if (!empty($order_id)) {
    if ($has_order_id_col) {
        $where_conditions[] = "i.order_id = ?";
        $params[] = intval($order_id);
        if (!$perDatabase) {
            $where_conditions[] = "i.tenant_id = ?";
            $params[] = $tenant_id;
        }
    }
    else {
        $like = '%Orden #' . $order_id . '%';
        if ($perDatabase) {
            $where_conditions[] = "(i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id AND ii.description LIKE ?))";
            $params[] = $like;
            $params[] = $like;
        } else {
            $where_conditions[] = "((i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id AND ii.tenant_id = i.tenant_id AND ii.description LIKE ?)) AND i.tenant_id = ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $tenant_id;
        }
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Consulta principal con paginación
$query = "
    SELECT i.*, 
           CASE 
               WHEN c.client_type = 'company' THEN c.company_name
               ELSE c.first_name
           END as client_name,
           c.email as client_email, 
           c.phone as client_phone,
           u.name as created_by_name
    FROM invoices i
    " . ($perDatabase ? "LEFT JOIN clients c ON i.client_id = c.id" : "LEFT JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id") . "
    " . ($perDatabase ? "LEFT JOIN users u ON i.created_by = u.id" : "LEFT JOIN users u ON i.created_by = u.id AND u.tenant_id = i.tenant_id") . "
    $where_clause
    ORDER BY i.created_at DESC
    LIMIT ? OFFSET ?
";

// Agregar parámetros de paginación
$params[] = $per_page;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total de registros para paginación
$count_params = array_slice($params, 0, -2);
$count_query = "
    SELECT COUNT(*) as total
    FROM invoices i
    " . ($perDatabase ? "LEFT JOIN clients c ON i.client_id = c.id" : "LEFT JOIN clients c ON i.client_id = c.id AND c.tenant_id = i.tenant_id") . "
    $where_clause
";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Obtener estadísticas
$stats_query = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(total_amount) as total_amount,
        SUM(paid_amount) as total_paid,
        SUM(pending_amount) as total_pending,
        COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN payment_status = 'partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_count
    FROM invoices
    WHERE status != 'cancelled'
";
$stats_params = [];
try {
    $hasTenantInvoicesStats = hasTenantColumnCached($pdo, 'invoices');
    if (!$perDatabase && $hasTenantInvoicesStats) {
        $stats_query .= " AND tenant_id = ?";
        $stats_params[] = $tenant_id;
    }
} catch (Throwable $__) {
}
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Manejo de mensajes
$mensaje = '';
$tipo_mensaje = '';

if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
}
elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}

// Configuración del template
$page_title = 'Facturación';

$additional_js = ['../assets/js/billing.js'];

// Capturar el contenido de la página
ob_start();
?>

<!-- Header de la página -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-file-invoice me-2 text-primary no-theme"></i>Facturación</h2>
        <p class="text-muted mb-0">Gestiona todas las facturas del sistema</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (hasRole('admin')): ?>
        <a href="index.php?sync_completed_orders=1" class="btn btn-warning rounded-pill px-3 shadow-sm text-white" title="Sincronizar Órdenes">
            <i class="fas fa-sync"></i>
        </a>
        <?php
endif; ?>
        <a href="reports.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
            <i class="fas fa-chart-bar me-2"></i>Reportes
        </a>
        <a href="new.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-plus me-2"></i>Nueva Factura
        </a>
    </div>
</div>

<!-- Mensajes de estado -->
<?php if ($mensaje): ?>
    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($mensaje); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php
endif; ?>

<!-- Estado de Caja -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-<?php echo $cash_session_open ? 'success' : 'warning'; ?> shadow-sm rounded-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5 class="card-title mb-1">
                            <i class="fas fa-<?php echo $cash_session_open ? 'unlock' : 'lock'; ?> me-2"></i>
                            Estado de Caja
                        </h5>
                        <?php if ($cash_session_open): ?>
                            <?php
    // Obtener información de la sesión actual
    $current_session = null;
    try {
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantCash = hasTenantColumnCached($pdo, 'cash_sessions');
        $hasTenantUsers = hasTenantColumnCached($pdo, 'users');
        $joinUsers = (!$perDatabase && $hasTenantUsers && $hasTenantCash)
            ? "LEFT JOIN users u ON cs.opened_by = u.id AND u.tenant_id = cs.tenant_id"
            : "LEFT JOIN users u ON cs.opened_by = u.id";
        $sql = "
            SELECT cs.*, u.name as opened_by_name
            FROM cash_sessions cs
            {$joinUsers}
            WHERE cs.status = 'open'" . (($hasTenantCash && !$perDatabase) ? " AND cs.tenant_id = ?" : "") . "
            ORDER BY cs.opening_date DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantCash && !$perDatabase) ? [$tenantValue] : []);
        $current_session = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e) {
        error_log("Error al obtener sesión de caja: " . $e->getMessage());
    }
?>
                            <?php if ($current_session): ?>
                                <p class="text-success mb-0">
                                    <strong>Caja Abierta</strong> desde <?php echo date('d/m/Y H:i', strtotime($current_session['opening_date'])); ?>
                                    <br>
                                    <small>Sesión: <?php echo htmlspecialchars($current_session['session_number']); ?> | Abierta por: <?php echo htmlspecialchars($current_session['opened_by_name']); ?></small>
                                </p>
                            <?php
    else: ?>
                                <p class="text-success mb-0">
                                    <strong>Caja Abierta</strong>
                                </p>
                            <?php
    endif; ?>
                        <?php
else: ?>
                            <p class="text-warning mb-0">
                                <strong>Caja Cerrada</strong> - No hay sesión activa
                            </p>
                        <?php
endif; ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <?php if ($cash_session_open): ?>
                            <div class="btn-group">
                                <a href="../cash/index.php" class="btn btn-outline-info">
                                    <i class="fas fa-cash-register me-2"></i>Gestionar Caja
                                </a>
                            </div>
                        <?php
else: ?>
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#unifiedOpenCashModal">
                                    <i class="fas fa-unlock me-2"></i>Abrir Caja
                                </button>
                            </div>
                        <?php
endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" id="viewModalContent" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Detalles de Factura</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" id="paymentModalContent" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cash-register me-2"></i>Registrar Pago</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" id="cancelModalContent" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Anular Factura</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Estadísticas -->
<div class="row mb-4 g-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-file-invoice fa-2x text-primary no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['total_invoices']); ?></h5>
                    <small class="text-muted">Total Facturas</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                    <i class="fas fa-dollar-sign fa-2x text-success"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo formatCurrency($stats['total_amount']); ?></h5>
                    <small class="text-muted">Monto Total</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
                    <i class="fas fa-check-circle fa-2x text-info no-theme"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo formatCurrency($stats['total_paid']); ?></h5>
                    <small class="text-muted">Total Pagado</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                    <i class="fas fa-clock fa-2x text-warning"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?php echo formatCurrency($stats['total_pending']); ?></h5>
                    <small class="text-muted">Pendiente</small>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- Main Card -->
    <div class="card shadow-sm rounded-4 border-0 overflow-hidden">
        <!-- Filter Header -->
        <div class="card-header bg-white py-3 border-bottom border-light">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="input-group rounded-pill border border-light overflow-hidden">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-0" name="search" 
                               placeholder="Buscar por número, cliente..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select bg-light border-light rounded-pill" name="payment_status" onchange="this.form.submit()">
                        <option value="">Todos los pagos</option>
                        <option value="paid" <?php echo $payment_status_filter === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                        <option value="partial" <?php echo $payment_status_filter === 'partial' ? 'selected' : ''; ?>>Parcial</option>
                        <option value="pending" <?php echo $payment_status_filter === 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="number" class="form-control bg-light border-light rounded-pill" name="order_id" min="1" step="1"
                           placeholder="Orden ID"
                           value="<?php echo htmlspecialchars($order_id); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm">
                        <i class="fas fa-filter me-2"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Table Content -->
        <div class="card-body p-0">
                <?php if (empty($invoices)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No se encontraron facturas</h5>
                        <p class="text-muted">No hay facturas que coincidan con los filtros aplicados.</p>
                        <a href="new.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Crear Primera Factura
                        </a>
                    </div>
                <?php
else: ?>
                    <div class="table-responsive d-none d-lg-block">
                        <table class="table table-hover align-middle mb-0 text-nowrap">
                            <thead class="bg-light text-muted">
                                <tr>
                                    <th>Número</th>
                                    <th>Cliente</th>
                                    <th>Fecha</th>
                                    <th>Total</th>
                                    <th>Pagado</th>
                                    <th>Pendiente</th>
                                    <th>Pago</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <?php
        $linked_order_id = null;
        if (isset($invoice['order_id']) && $invoice['order_id']) {
            $linked_order_id = (int)$invoice['order_id'];
        }
        elseif (!empty($invoice['notes']) && preg_match('/Orden\s*#(\d+)/i', $invoice['notes'], $m)) {
            $linked_order_id = (int)$m[1];
        }
?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
                                        <?php if ($linked_order_id):
            try {
                if (!$perDatabase && hasTenantColumnCached($pdo, 'work_orders')) {
                    $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $ordStmt->execute([$linked_order_id, $tenantValue]);
                } else {
                    $ordStmt = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? LIMIT 1");
                    $ordStmt->execute([$linked_order_id]);
                }
                $ordNum = (int)($ordStmt->fetchColumn() ?: 0);
            }
            catch (Throwable $__) {
                $ordNum = 0;
            }
            $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
            $displayNum = $ordNum > 0 ? $ordNum : $linked_order_id;
            $displayText = htmlspecialchars($prefix) . '-' . str_pad($displayNum, 4, '0', STR_PAD_LEFT);
?>
                                            <br><small class="badge bg-secondary"><?php echo $displayText; ?></small>
                                        <?php
        endif; ?>
                                        <?php if ($invoice['status'] === 'cancelled'): ?>
                                            <br><small class="badge bg-danger">ANULADA</small>
                                        <?php
        endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($invoice['client_name']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($invoice['client_email']); ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></td>
                                    <td>
                                        <strong><?php echo formatCurrency($invoice['total_amount']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-success"><?php echo formatCurrency($invoice['paid_amount']); ?></span>
                                    </td>
                                    <td>
                                        <span class="text-warning"><?php echo formatCurrency($invoice['pending_amount']); ?></span>
                                    </td>
                                    <td>
                                        <?php
        $payment_colors = [
            'paid' => 'success',
            'partial' => 'warning',
            'pending' => 'danger'
        ];
        $payment_texts = [
            'paid' => 'Pagada',
            'partial' => 'Parcial',
            'pending' => 'Pendiente'
        ];
?>
                                        <span class="badge bg-<?php echo $payment_colors[$invoice['payment_status']] ?? 'secondary'; ?>">
                                            <?php echo $payment_texts[$invoice['payment_status']] ?? ucfirst($invoice['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 justify-content-start">
                                            <a href="#" onclick="openViewModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-light text-primary no-theme shadow-sm" title="Ver Detalles">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <a href="edit.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <button type="button" 
                                                    class="btn btn-sm btn-light text-dark shadow-sm" 
                                                    title="Imprimir" 
                                                    onclick="quickPrint(<?php echo $invoice['id']; ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>

                                            <button type="button" 
                                                    class="btn btn-sm btn-light text-danger shadow-sm" 
                                                    title="Descargar PDF" 
                                                    onclick="quickPdf(<?php echo $invoice['id']; ?>)">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                            
                                            <button type="button" 
                                                    class="btn btn-sm btn-light text-success shadow-sm" 
                                                    title="WhatsApp"
                                                    data-invoice="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                                    data-client="<?php echo htmlspecialchars($invoice['client_name']); ?>"
                                                    data-total="<?php echo $invoice['total_amount']; ?>"
                                                    data-paid="<?php echo $invoice['paid_amount']; ?>"
                                                    data-phone="<?php echo htmlspecialchars($invoice['client_phone'] ?? ''); ?>"
                                                    onclick="openWhatsAppModal(this.dataset.invoice, this.dataset.client, this.dataset.total, this.dataset.paid, this.dataset.phone)">
                                                <i class="fab fa-whatsapp"></i>
                                            </button>
                                            
                                            <?php if ($invoice['status'] !== 'cancelled' && $invoice['payment_status'] !== 'paid'): ?>
                                            <a href="#" onclick="openPaymentModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-light text-success shadow-sm" title="Registrar Pago">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <?php
        endif; ?>
                                            
                                            <?php if ($invoice['status'] !== 'cancelled' && hasRole('admin')): ?>
                                            <a href="#" onclick="openCancelModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-light text-danger shadow-sm" title="Anular Factura">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                            <?php
        endif; ?>
                                            
                                            <?php if ($invoice['status'] === 'draft' || hasRole('admin')): ?>
                                            <a href="#" onclick="confirmDelete(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')" class="btn btn-sm btn-light text-danger shadow-sm" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php
        endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php
    endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Vista Móvil (Tarjetas) -->
                    <div class="d-block d-lg-none mt-3 px-3 pb-3">
                        <div class="row g-3">
                            <?php foreach ($invoices as $invoice):
        $linked_order_id = null;
        if (isset($invoice['order_id']) && $invoice['order_id']) {
            $linked_order_id = (int)$invoice['order_id'];
        }
        elseif (!empty($invoice['notes']) && preg_match('/Orden\s*#(\d+)/i', $invoice['notes'], $m)) {
            $linked_order_id = (int)$m[1];
        }

        $payment_colors_mob = [
            'paid' => 'success',
            'partial' => 'warning',
            'pending' => 'danger'
        ];
        $payment_texts_mob = [
            'paid' => 'Pagada',
            'partial' => 'Parcial',
            'pending' => 'Pendiente'
        ];
?>
                            <div class="col-12">
                                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                    <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="fw-bold fs-5"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                            <?php if ($linked_order_id):
            try {
                if (!$perDatabase && hasTenantColumnCached($pdo, 'work_orders')) {
                    $ordStmtMob = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $ordStmtMob->execute([$linked_order_id, $tenantValue]);
                } else {
                    $ordStmtMob = $pdo->prepare("SELECT order_number FROM work_orders WHERE id = ? LIMIT 1");
                    $ordStmtMob->execute([$linked_order_id]);
                }
                $ordNumMob = (int)($ordStmtMob->fetchColumn() ?: 0);
            }
            catch (Throwable $__) {
                $ordNumMob = 0;
            }
            $prefixMob = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
            $displayNumMob = $ordNumMob > 0 ? $ordNumMob : $linked_order_id;
            $displayTextMob = htmlspecialchars($prefixMob) . '-' . str_pad($displayNumMob, 4, '0', STR_PAD_LEFT);
?>
                                                <span class="badge bg-secondary ms-2"><?php echo $displayTextMob; ?></span>
                                            <?php
        endif; ?>
                                        </div>
                                        <div>
                                            <span class="badge rounded-pill bg-<?php echo $payment_colors_mob[$invoice['payment_status']] ?? 'secondary'; ?> bg-opacity-10 text-<?php echo $payment_colors_mob[$invoice['payment_status']] ?? 'secondary'; ?>">
                                                <?php echo $payment_texts_mob[$invoice['payment_status']] ?? ucfirst($invoice['payment_status']); ?>
                                            </span>
                                            <?php if ($invoice['status'] === 'cancelled'): ?>
                                                <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger ms-1">Anulada</span>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body py-2">
                                        <div class="d-flex mb-3">
                                            <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h6 class="fw-bold mb-1 text-dark text-truncate">
                                                    <?php echo htmlspecialchars($invoice['client_name']); ?>
                                                </h6>
                                                <small class="text-muted d-block text-truncate">
                                                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($invoice['client_email']); ?>
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between p-3 bg-light rounded-3 mb-3 border border-light">
                                            <div class="text-center">
                                                <div class="small text-muted mb-1">Total</div>
                                                <div class="fw-bold text-dark"><?php echo formatCurrency($invoice['total_amount']); ?></div>
                                            </div>
                                            <div class="text-center border-start ps-2 pe-2">
                                                <div class="small text-muted mb-1">Pagado</div>
                                                <div class="fw-bold text-success"><?php echo formatCurrency($invoice['paid_amount']); ?></div>
                                            </div>
                                            <div class="text-center border-start ps-2">
                                                <div class="small text-muted mb-1">Pendiente</div>
                                                <div class="fw-bold text-warning"><?php echo formatCurrency($invoice['pending_amount']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                                        <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                            <span><i class="far fa-calendar-alt me-1"></i><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></span>
                                        </div>
                                        
                                        <!-- Acciones primarias -->
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <a href="#" onclick="openViewModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-outline-primary w-100 shadow-sm" title="Ver Detalles">
                                                    <i class="fas fa-eye me-1"></i> Ver
                                                </a>
                                            </div>
                                            <?php if ($invoice['status'] !== 'cancelled' && $invoice['payment_status'] !== 'paid'): ?>
                                            <div class="col-6">
                                                <a href="#" onclick="openPaymentModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-outline-success w-100 shadow-sm" title="Registrar Pago">
                                                    <i class="fas fa-money-bill-wave me-1"></i> Pago
                                                </a>
                                            </div>
                                            <?php
        endif; ?>
                                        </div>
                                        
                                        <!-- Otras acciones (Scrollable si son muchas) -->
                                        <div class="d-flex flex-wrap gap-2 pb-1 justify-content-center justify-content-sm-start">
                                            <a href="edit.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-light text-warning shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Editar"><i class="fas fa-edit"></i></a>
                                            
                                            <button type="button" class="btn btn-sm btn-light text-dark shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Imprimir" onclick="quickPrint(<?php echo $invoice['id']; ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>

                                            <button type="button" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Descargar PDF" onclick="quickPdf(<?php echo $invoice['id']; ?>)">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                            
                                            <button type="button" class="btn btn-sm btn-light text-success shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="WhatsApp"
                                                    data-invoice="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                                    data-client="<?php echo htmlspecialchars($invoice['client_name']); ?>"
                                                    data-total="<?php echo $invoice['total_amount']; ?>"
                                                    data-paid="<?php echo $invoice['paid_amount']; ?>"
                                                    data-phone="<?php echo htmlspecialchars($invoice['client_phone'] ?? ''); ?>"
                                                    onclick="openWhatsAppModal(this.dataset.invoice, this.dataset.client, this.dataset.total, this.dataset.paid, this.dataset.phone)">
                                                <i class="fab fa-whatsapp"></i>
                                            </button>
                                            
                                            <?php if ($invoice['status'] !== 'cancelled' && hasRole('admin')): ?>
                                            <a href="#" onclick="openCancelModal(<?php echo $invoice['id']; ?>); return false;" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Anular Factura">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                            <?php
        endif; ?>
                                            
                                            <?php if ($invoice['status'] === 'draft' || hasRole('admin')): ?>
                                            <a href="#" onclick="confirmDelete(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')" class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php
        endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
    endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Paginación -->
                    <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white border-top border-light py-3 mt-4">
                            <nav aria-label="Paginación de facturas">
                                <ul class="pagination justify-content-center mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link border-0 text-muted" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-chevron-left me-1"></i> Anterior
                                            </a>
                                        </li>
                                    <?php
        endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <li class="page-item">
                                            <a class="page-link border-0 rounded-circle <?php echo $i == $page ? 'bg-primary text-white shadow-sm' : 'text-muted'; ?> mx-1 d-flex align-items-center justify-content-center" 
                                               style="width: 35px; height: 35px;"
                                               href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php
        endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link border-0 text-muted" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                Siguiente <i class="fas fa-chevron-right ms-1"></i>
                                            </a>
                                        </li>
                                    <?php
        endif; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php
    endif; ?>
                <?php
endif; ?>
            </div>
        </div>

<?php

// Configurar auto-show para el modal unificado si la caja está cerrada
$autoShowModal = !$cash_session_open;
?>

<!-- Modal de Confirmación de Eliminación -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirmar Eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-trash-alt text-danger" style="font-size: 3rem;"></i>
                </div>
                <h6 class="text-center mb-3">¿Estás seguro de que quieres eliminar esta factura?</h6>
                <div class="alert alert-warning">
                    <strong>Factura:</strong> <span id="invoiceNumber"></span>
                    <br>
                    <small>Esta acción no se puede deshacer. Se eliminarán todos los datos relacionados con esta factura.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancelar
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i>Eliminar Definitivamente
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales para la eliminación
let invoiceToDelete = null;

// Función para confirmar eliminación
function confirmDelete(invoiceId, invoiceNumber) {
    invoiceToDelete = invoiceId;
    document.getElementById('invoiceNumber').textContent = invoiceNumber;
    
    // Mostrar modal
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

// Funciones para Modales de Acciones

function openViewModal(id) {
    const modalElement = document.getElementById('viewModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    
    // Cargar contenido
    fetch(`get_modal_content.php?id=${id}&type=view`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('viewModalContent').innerHTML = html;
            const mb = modalElement.querySelector('.modal-body');
            if (mb) mb.scrollTop = 0;
        })
        .catch(error => {
            document.getElementById('viewModalContent').innerHTML = '<div class="modal-body"><div class="alert alert-danger">Error al cargar datos</div></div>';
        });
}

function openPaymentModal(id) {
    const modalElement = document.getElementById('paymentModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    
    // Cargar contenido
    fetch(`get_modal_content.php?id=${id}&type=payment`, {
        headers: { 'Accept': 'text/html' }
    })
        .then(async (response) => {
            const text = await response.text();
            if (!response.ok) {
                throw new Error(`Error ${response.status}: ${text.slice(0, 200)}`);
            }
            return text;
        })
        .then(html => {
            document.getElementById('paymentModalContent').innerHTML = html;
            const mb = modalElement.querySelector('.modal-body');
            if (mb) mb.scrollTop = 0;
        })
        .catch(error => {
            document.getElementById('paymentModalContent').innerHTML = '<div class="modal-body"><div class="alert alert-danger">Error al cargar el formulario de pago</div></div>';
        });
}

function openCancelModal(id) {
    const modalElement = document.getElementById('cancelModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    
    // Cargar contenido
    fetch(`get_modal_content.php?id=${id}&type=cancel`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('cancelModalContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('cancelModalContent').innerHTML = '<div class="modal-body"><div class="alert alert-danger">Error al cargar datos</div></div>';
        });
}

function submitPayment(event) {
    event.preventDefault();
    const form = event.target;
    let btn = event.submitter;
    if (!btn) {
        const modalElement = document.getElementById('paymentModal');
        btn = (modalElement && modalElement.querySelector(`button[type="submit"][form="${form.id}"]`)) 
              || document.querySelector(`button[type="submit"][form="${form.id}"]`);
    }
    const originalText = (btn && typeof btn.innerHTML === 'string') ? btn.innerHTML : '';
    
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...'; }
    
    const formData = new FormData(form);
    
    fetch('process_payment.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            const modalElement = document.getElementById('paymentModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();
            showAlert('success', 'Éxito', data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            // Mostrar error en alerta dentro del modal si es posible, o alert normal
            alert(data.message);
            if (btn) { btn.disabled = false; btn.innerHTML = originalText || '<i class="fas fa-save me-2"></i>Guardar Pago'; }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al procesar el pago');
        if (btn) { btn.disabled = false; btn.innerHTML = originalText || '<i class="fas fa-save me-2"></i>Guardar Pago'; }
    });
}

function submitCancel(event) {
    event.preventDefault();
    const form = event.target;
    
    // Intentar obtener el botón de varias formas
    let btn = event.submitter;
    if (!btn) {
        btn = form.querySelector('button[type="submit"]');
    }
    if (!btn && form.id) {
        btn = document.querySelector(`button[form="${form.id}"]`);
    }

    const originalText = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
    }
    
    const formData = new FormData(form);
    
    fetch('process_cancel.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            const modalElement = document.getElementById('cancelModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();
            showAlert('success', 'Éxito', data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            alert(data.message);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al anular la factura');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });
}

// Asegurar que los modales no hereden márgenes del layout moviéndolos al <body>
document.addEventListener('DOMContentLoaded', function () {
    ['viewModal', 'paymentModal', 'cancelModal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
});

// Manejar confirmación de eliminación
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (invoiceToDelete) {
        // Deshabilitar botón y mostrar loading
        const btn = this;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando...';
        
        // Realizar petición de eliminación
        fetch('delete_invoice_ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                invoice_id: invoiceToDelete,
                csrf_token: '<?php echo SecurityEnhancements::generateCSRFToken(); ?>'
            })
        })
        .then(window.parseJsonResponse)
        .then(data => {
            if (data.success) {
                // Mostrar mensaje de éxito
                showAlert('success', 'Factura eliminada', 'La factura ha sido eliminada exitosamente.');
                
                // Cerrar modal y recargar página
                setTimeout(() => {
                    const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                    deleteModal.hide();
                    window.location.reload();
                }, 1500);
            } else {
                // Mostrar error
                showAlert('danger', 'Error al eliminar', data.message || 'No se pudo eliminar la factura.');
                
                // Restaurar botón
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('danger', 'Error de conexión', 'No se pudo conectar con el servidor.');
            
            // Restaurar botón
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }
});

// Función para mostrar alertas
function showAlert(type, title, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <strong>${title}</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Insertar alerta al inicio del contenido principal
    const mainContent = document.querySelector('.container-fluid');
    if (mainContent) {
        mainContent.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            const alert = mainContent.querySelector('.alert');
            if (alert) {
                alert.remove();
            }
        }, 5000);
    }
}



// Inicializar dropdowns de Bootstrap y formateo de dinero
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar todos los dropdowns
    var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
    var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
        return new bootstrap.Dropdown(dropdownToggleEl);
    });
    
    // Debug: verificar que los dropdowns se inicializaron
    console.log('Dropdowns inicializados:', dropdownList.length);
    


    // Verificar si hay que abrir modal automáticamente
    const urlParams = new URLSearchParams(window.location.search);
    const openModalId = urlParams.get('open_modal');
    const shareWhatsapp = urlParams.get('share');
    
    if (openModalId) {
        openViewModal(openModalId);
        
        // Si hay que compartir por WhatsApp, esperar a que el modal cargue
        if (shareWhatsapp === 'whatsapp') {
            const observer = new MutationObserver(function(mutations) {
                const whatsappBtn = document.querySelector('#viewModalContent button[aria-label="WhatsApp"]');
                if (whatsappBtn) {
                    observer.disconnect();
                    // Simular clic para abrir el modal de WhatsApp
                    whatsappBtn.click();
                }
            });
            
            observer.observe(document.getElementById('viewModalContent'), { childList: true, subtree: true });
        }
        
        // Limpiar URL para no reabrir al recargar
        const newUrl = window.location.pathname + (urlParams.has('success') ? '?success=' + urlParams.get('success') : '');
        window.history.replaceState({}, document.title, newUrl);
    }
});

// Funciones de Impresión Rápida y PDF (Globales)
function quickPrint(id) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.src = 'print.php?id=' + id;
    document.body.appendChild(iframe);
    
    iframe.onload = function() {
        try {
            setTimeout(function() {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 1500);
            }, 500);
        } catch (e) {
            console.error('Error al imprimir:', e);
            alert('Error al intentar imprimir automáticamente. Por favor abra la factura manualmente.');
        }
    };
}

function quickPdf(id) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.left = '-9999px';
    iframe.style.top = '0';
    iframe.style.width = '1200px';
    iframe.style.height = '1600px';
    iframe.style.border = 'none';
    iframe.src = 'print.php?id=' + id;
    
    document.body.appendChild(iframe);
    
    iframe.onload = function() {
        setTimeout(function() {
            try {
                if (iframe.contentWindow.downloadInvoicePDF) {
                    iframe.contentWindow.downloadInvoicePDF().then(() => {
                        setTimeout(() => document.body.removeChild(iframe), 2000);
                    });
                } else {
                    console.error('Función downloadInvoicePDF no encontrada en el iframe');
                }
            } catch (e) {
                console.error(e);
                document.body.removeChild(iframe);
            }
        }, 1500);
    };
}
</script>

<!-- Modal WhatsApp -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header bg-success text-white border-0" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                <h5 class="modal-title"><i class="fab fa-whatsapp me-2"></i>Enviar Mensaje WhatsApp</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" id="waInvoiceNumber">
                <input type="hidden" id="waClientName">
                <input type="hidden" id="waTotal">
                <input type="hidden" id="waPaid">
                <input type="hidden" id="waPhone">
                <input type="hidden" id="waDetails">
                
                <div class="mb-3">
                    <label class="form-label">Seleccionar Plantilla</label>
                    <select class="form-select rounded-pill shadow-sm" id="waTemplateSelect" onchange="updateWhatsAppMessage()">
                        <option value="sale">Venta / Factura</option>
                        <option value="custom">Mensaje Personalizado</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Mensaje</label>
                    <textarea class="form-control rounded-3 shadow-sm" id="waMessage" rows="8"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success rounded-pill px-4" onclick="sendWhatsAppFromModal()">
                    <i class="fab fa-whatsapp me-2"></i>Enviar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Plantillas desde PHP
const whatsappTemplates = <?php echo json_encode($wa_templates, JSON_UNESCAPED_UNICODE); ?>;
const companyName = <?php echo json_encode($company_name, JSON_UNESCAPED_UNICODE); ?>;
const companyPhone = <?php echo json_encode($company_phone ?? '', JSON_UNESCAPED_UNICODE); ?>;
const currencySymbol = <?php echo json_encode(CompanySettings::getCurrency()['symbol']); ?>;

function openWhatsAppModal(invoiceNumber, clientName, total, paid, phone, details) {
    document.getElementById('waInvoiceNumber').value = invoiceNumber;
    document.getElementById('waClientName').value = clientName;
    document.getElementById('waTotal').value = total;
    document.getElementById('waPaid').value = paid;
    document.getElementById('waPhone').value = phone || '';
    let det = typeof details === 'string' ? details : '';
    document.getElementById('waDetails').value = det;
    
    document.getElementById('waTemplateSelect').value = 'sale';
    updateWhatsAppMessage();
    
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

function updateWhatsAppMessage() {
    const type = document.getElementById('waTemplateSelect').value;
    const invoiceNumber = document.getElementById('waInvoiceNumber').value;
    const clientName = document.getElementById('waClientName').value;
    const total = document.getElementById('waTotal').value;
    const paid = document.getElementById('waPaid').value;
    const phone = document.getElementById('waPhone').value;
    const details = (document.getElementById('waDetails').value || '').trim();
    
    if (type === 'custom') return;
    
    let template = whatsappTemplates[`whatsapp_template_${type}`] || '';
    if (!template && type === 'sale') {
        template = "📝 Comprobante de Venta #{{factura}}\n👤 {{cliente}}\n🛍️ Detalles: {{detalles}}\n💰 Total: {{total}} | 💳 Pagado: {{abono}}\n⚖️ Saldo: {{saldo}}\n🙏 ¡Gracias por tu compra!\n📞 {{taller_nombre}} | {{taller_tel}}";
    }
    
    const saldo = parseFloat(total) - parseFloat(paid);
    const formatMoney = (val) => {
        const num = parseFloat(val) || 0;
        return (typeof currencySymbol !== 'undefined' ? currencySymbol : '$') + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    };

    let message = template
        .replace(/{{cliente}}/g, clientName)
        .replace(/{{cliente_tel}}/g, phone)
        .replace(/{{orden}}/g, invoiceNumber)
        .replace(/{{factura}}/g, invoiceNumber)
        .replace(/{{valor}}/g, formatMoney(total))
        .replace(/{{total}}/g, formatMoney(total))
        .replace(/{{abono}}/g, formatMoney(paid))
        .replace(/{{paid}}/g, formatMoney(paid))
        .replace(/{{saldo}}/g, formatMoney(saldo))
        .replace(/{{empresa}}/g, companyName)
        .replace(/{{taller_nombre}}/g, companyName)
        .replace(/{{taller_tel}}/g, companyPhone)
        .replace(/{{detalles}}/g, details);
        
    document.getElementById('waMessage').value = message;
}

function sendWhatsAppFromModal() {
    let message = document.getElementById('waMessage').value;
    if (window.normalizeEmoji && window.normalizeEmoji !== normalizeEmoji) {
        message = window.normalizeEmoji(message);
    }
    const phone = document.getElementById('waPhone').value.replace(/[^0-9]/g, '');
    const base = 'https://api.whatsapp.com/send';
    const params = new URLSearchParams();
    if (phone) params.set('phone', phone);
    params.set('text', message);
    const url = `${base}?${params.toString()}`;
    window.open(url, '_blank');
    const modal = bootstrap.Modal.getInstance(document.getElementById('whatsappModal'));
    modal.hide();
}
function normalizeEmoji(text) {
    return String(text || '').replace(/\uFFFD/g, '');
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
