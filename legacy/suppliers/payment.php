<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
SecurityEnhancements::setSecurityHeaders();

// Verificar autenticación
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');
$hasTenantPurchaseOrders = hasTenantColumnCached($pdo, 'purchase_orders');
$hasTenantSupplierPayments = hasTenantColumnCached($pdo, 'supplier_payments');
$hasTenantPaymentMethods = hasTenantColumnCached($pdo, 'payment_methods');
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');

// Verificar permisos
if (!hasRole(['admin', 'inventory'])) {
    header('Location: ../dashboard.php');
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Obtener proveedor preseleccionado
$preselected_supplier_id = intval($_GET['supplier_id'] ?? 0);
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Generar/recuperar clave de idempotencia de sesión
$idempotency_key = $_SESSION['supplier_payment_idem'] ?? '';
if (!$idempotency_key) {
    try {
        $idempotency_key = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $idempotency_key = bin2hex(openssl_random_pseudo_bytes(16));
    }
    $_SESSION['supplier_payment_idem'] = $idempotency_key;
}

// Detectar si existe columna status en supplier_payments (para excluir pagos anulados)
$hasSpStatusColumn = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'status'");
    $hasSpStatusColumn = $colCheck && $colCheck->rowCount() > 0;
} catch (PDOException $e) {
    $hasSpStatusColumn = false;
}

// Detectar si existe columna request_id en supplier_payments (para idempotencia)
$hasSpRequestIdColumn = false;
try {
    $colCheck2 = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'request_id'");
    $hasSpRequestIdColumn = $colCheck2 && $colCheck2->rowCount() > 0;
} catch (PDOException $e) {
    $hasSpRequestIdColumn = false;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar CSRF
    if (!SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $mensaje = 'Token de seguridad inválido';
        $tipo_mensaje = 'danger';
    } else {
        try {
            // Saneo y parsing de inputs
            $supplier_id = intval($_POST['supplier_id'] ?? 0);
            $purchase_order_id = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : null;
            $payment_amount_raw = trim($_POST['payment_amount'] ?? '');
            $payment_amount = floatval($payment_amount_raw);
            $payment_method = trim($_POST['payment_method'] ?? '');
            $payment_date = trim($_POST['payment_date'] ?? '');
            $reference_number = preg_replace('/[^A-Za-z0-9#\-\s]/', '', trim($_POST['reference_number'] ?? ''));
            $notes = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
            $request_id = trim($_POST['request_id'] ?? ($_SESSION['supplier_payment_idem'] ?? ''));
            
            // Validaciones base
            if (empty($supplier_id)) {
                throw new Exception('Debe seleccionar un proveedor');
            }
            if ($payment_amount <= 0) {
                throw new Exception('El monto del pago debe ser mayor a 0');
            }
            if (empty($payment_method)) {
                throw new Exception('El método de pago es obligatorio');
            }
            if (empty($payment_date)) {
                throw new Exception('La fecha del pago es obligatoria');
            }
            // Validar formato de monto (máximo dos decimales y límite superior razonable)
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $payment_amount_raw)) {
                throw new Exception('El monto debe tener como máximo dos decimales');
            }
            if ($payment_amount > 100000000) { // 100 millones
                throw new Exception('El monto excede el máximo permitido');
            }
            // Validar longitud de referencia y notas
            if (strlen($reference_number) > 100) {
                throw new Exception('La referencia no debe exceder 100 caracteres');
            }
            if (strlen($notes) > 1000) {
                throw new Exception('Las notas no deben exceder 1000 caracteres');
            }
            // Validar idempotencia si existe columna
            if ($hasSpRequestIdColumn) {
                if (empty($request_id) || strlen($request_id) > 64) {
                    throw new Exception('Idempotencia inválida');
                }
                $sql = "SELECT id FROM supplier_payments WHERE request_id = ?" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
                $dup = $pdo->prepare($sql);
                $dup->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$request_id, $tenantValue] : [$request_id]);
                if ($dup->fetchColumn()) {
                    throw new Exception('Este pago ya fue procesado');
                }
            }
            
            // Validar formato de fecha
            $dt = DateTime::createFromFormat('Y-m-d', $payment_date);
            if (!$dt || $dt->format('Y-m-d') !== $payment_date) {
                throw new Exception('Fecha de pago inválida');
            }
            
            // Validar proveedor existente y activo del tenant
            $sql = "SELECT id FROM suppliers WHERE id = ? AND is_active = TRUE" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_id, $tenantValue] : [$supplier_id]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Proveedor inválido');
            }
            
            // Validar orden de compra si se especificó
            if ($purchase_order_id) {
                $sql = "SELECT id FROM purchase_orders WHERE id = ? AND supplier_id = ? AND payment_status IN ('pending','partially_paid')" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$purchase_order_id, $supplier_id, $tenantValue] : [$purchase_order_id, $supplier_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Orden de compra inválida para el proveedor seleccionado');
                }
            }
            
            // Validar método de pago contra lista activa + fallback
            $valid_methods = [];
            try {
                $sql = "SELECT name FROM payment_methods WHERE is_active = 1" . (($hasTenantPaymentMethods && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantPaymentMethods && !$perDatabase) ? [$tenantValue] : []);
                $valid_methods = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                // Ignorar, usaremos solo fallback
            }
            foreach (['efectivo','transferencia','tarjeta'] as $m) {
                if (!in_array($m, $valid_methods)) { $valid_methods[] = $m; }
            }
            if (!in_array($payment_method, $valid_methods)) {
                throw new Exception('Método de pago inválido');
            }
            
            // Verificar que hay una sesión de caja abierta
            $sql = "SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY opening_date DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
            $cash_session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cash_session) {
                throw new Exception('No hay una sesión de caja abierta. Debe abrir caja antes de registrar pagos.');
            }
            
            $pdo->beginTransaction();
            
            try {
                // Insertar pago (con columnas dinámicas para idempotencia/estado)
                $columns = "supplier_id, purchase_order_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, created_by";
                $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?";
                $params = [
                    $supplier_id, $purchase_order_id, $payment_amount, $payment_method,
                    $payment_date, $reference_number, $notes, $cash_session['id'], $_SESSION['user_id']
                ];
                if ($hasTenantSupplierPayments) {
                    $columns = "tenant_id, " . $columns;
                    $placeholders = "?, " . $placeholders;
                    array_unshift($params, $tenantValue);
                }
                if ($hasSpRequestIdColumn) {
                    $columns .= ", request_id";
                    $placeholders .= ", ?";
                    $params[] = $request_id;
                }
                if ($hasSpStatusColumn) {
                    $columns .= ", status";
                    $placeholders .= ", ?";
                    $params[] = 'active';
                }
                $stmt = $pdo->prepare("INSERT INTO supplier_payments ($columns) VALUES ($placeholders)");
                $stmt->execute($params);
                $payment_id = $pdo->lastInsertId();
                
                // Actualizar estado de pago de la orden si se especificó
                $order_po_number = null;
                if ($purchase_order_id) {
                    // Bloquear la fila de la orden para evitar condiciones de carrera
                    $sql = "SELECT total_amount, po_number FROM purchase_orders WHERE id = ?" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND tenant_id = ?" : "") . " FOR UPDATE";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$purchase_order_id, $tenantValue] : [$purchase_order_id]);
                    $order_row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$order_row) {
                        throw new Exception('Orden de compra no encontrada');
                    }
                    $order_total = $order_row['total_amount'] ?? 0;
                    $order_po_number = $order_row['po_number'] ?? ('#'.$purchase_order_id);
                    
                    // Calcular total pagado para esta orden (excluyendo pagos anulados si aplica)
                    $sumSql = "SELECT COALESCE(SUM(payment_amount), 0) as total_paid FROM supplier_payments WHERE purchase_order_id = ?" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND tenant_id = ?" : "");
                    if ($hasSpStatusColumn) {
                        $sumSql .= " AND status = 'active'";
                    }
                    $stmt = $pdo->prepare($sumSql);
                    $stmt->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$purchase_order_id, $tenantValue] : [$purchase_order_id]);
                    $total_paid = $stmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0;
                    
                    // Determinar nuevo estado de pago
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
                    $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$new_payment_status, $purchase_order_id, $tenantValue] : [$new_payment_status, $purchase_order_id]);
                }
                
                // Registrar egreso en caja
                if ($hasTenantCashExpenses) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO cash_expenses (
                            tenant_id, amount, concept, payment_method, reference_number, notes, cash_session_id, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO cash_expenses (
                            amount, concept, payment_method, reference_number, notes, cash_session_id, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                }
                
                $concept = "Pago a proveedor";
                if ($purchase_order_id) {
                    $concept .= " - Orden " . ($order_po_number ?? ('#'.$purchase_order_id));
                }
                
                $params = [$payment_amount, $concept, $payment_method, $reference_number, $notes, $cash_session['id'], $_SESSION['user_id']];
                if ($hasTenantCashExpenses) { array_unshift($params, $tenantValue); }
                $stmt->execute($params);
                
                $pdo->commit();
                
                // Registrar actividad
                logActivity($_SESSION['user_id'], 'CREATE_SUPPLIER_PAYMENT', 'supplier_payments', $payment_id);
                
                $mensaje = 'Pago registrado exitosamente';
                $tipo_mensaje = 'success';
                
                // Limpiar formulario e idempotencia
                $_POST = [];
                $preselected_supplier_id = 0;
                $_SESSION['supplier_payment_idem'] = '';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            $mensaje = $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Obtener proveedores
$suppliers = [];
try {
    $sql = "SELECT id, company_name FROM suppliers WHERE is_active = TRUE" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY company_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$tenantValue] : []);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener proveedores: " . $e->getMessage());
}

// Obtener órdenes de compra pendientes
$pending_orders = [];
try {
$pendingOrdersSql = "
        SELECT po.id, po.po_number, po.total_amount, s.company_name as supplier_name,
               (po.total_amount - COALESCE(SUM(sp.payment_amount), 0)) as pending_amount
        FROM purchase_orders po
        INNER JOIN suppliers s ON po.supplier_id = s.id" . (($hasTenantSuppliers && $hasTenantPurchaseOrders && !$perDatabase) ? " AND s.tenant_id = po.tenant_id" : "") . "
        LEFT JOIN supplier_payments sp ON po.id = sp.purchase_order_id" . (($hasTenantSupplierPayments && $hasTenantPurchaseOrders && !$perDatabase) ? " AND sp.tenant_id = po.tenant_id" : "");
    if ($hasSpStatusColumn) {
        $pendingOrdersSql .= " AND sp.status = 'active'";
    }
    $pendingOrdersSql .= "
        WHERE po.payment_status IN ('pending', 'partially_paid')" . (($hasTenantPurchaseOrders && !$perDatabase) ? " AND po.tenant_id = ?" : "") . "
        GROUP BY po.id
        HAVING pending_amount > 0
        ORDER BY po.order_date ASC";
    $stmt = $pdo->prepare($pendingOrdersSql);
    $stmt->execute(($hasTenantPurchaseOrders && !$perDatabase) ? [$tenantValue] : []);
    $pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener órdenes pendientes: " . $e->getMessage());
}

// Obtener métodos de pago
$payment_methods = [];
try {
    $sql = "SELECT name FROM payment_methods WHERE is_active = 1" . (($hasTenantPaymentMethods && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPaymentMethods && !$perDatabase) ? [$tenantValue] : []);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error al obtener métodos de pago: " . $e->getMessage());
}

// Obtener pagos recientes
$recent_payments = [];
try {
    $sql = "SELECT sp.id, sp.payment_amount, sp.payment_date, sp.payment_method, sp.reference_number, sp.status, 
                   s.company_name, po.po_number
            FROM supplier_payments sp
            LEFT JOIN suppliers s ON s.id = sp.supplier_id" . (($hasTenantSuppliers && $hasTenantSupplierPayments && !$perDatabase) ? " AND s.tenant_id = sp.tenant_id" : "") . "
            LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id" . (($hasTenantPurchaseOrders && $hasTenantSupplierPayments && !$perDatabase) ? " AND po.tenant_id = sp.tenant_id" : "") . "
            WHERE 1=1" . (($hasTenantSupplierPayments && !$perDatabase) ? " AND sp.tenant_id = ?" : "");
    if ($hasSpStatusColumn) { $sql .= " AND sp.status = 'active'"; }
    $sql .= " ORDER BY sp.created_at DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSupplierPayments && !$perDatabase) ? [$tenantValue] : []);
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error al obtener pagos recientes: ' . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>
<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-money-bill-wave me-2"></i>Registrar Pago a Proveedor</h1>
            <p class="text-muted mb-0">Registrar pagos a proveedores</p>
        </div>
        <div class="btn-group">
            <a href="voided_payments.php" class="btn btn-outline-danger">
                <i class="fas fa-ban me-1"></i>Pagos Anulados
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): 
        $mensaje = $_GET['msg']; 
        $tipo_mensaje = $_GET['type'] ?? 'info'; 
    ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center" role="alert">
            <?php if ($tipo_mensaje === 'success'): ?>
                <i class="fas fa-check-circle me-2 fa-lg"></i>
            <?php elseif ($tipo_mensaje === 'danger'): ?>
                <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
            <?php else: ?>
                <i class="fas fa-info-circle me-2 fa-lg"></i>
            <?php endif; ?>
            <div class="flex-grow-1">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Datos del Pago
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($idempotency_key); ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="supplier_id" class="form-label fw-bold">Proveedor <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-building text-muted"></i>
                                    </span>
                                    <select name="supplier_id" id="supplier_id" class="form-select border-start-0 ps-0" required>
                                        <option value="">Seleccione un proveedor</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php echo ($preselected_supplier_id == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="purchase_order_id" class="form-label fw-bold">Orden de compra</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-file-invoice text-muted"></i>
                                    </span>
                                    <select name="purchase_order_id" id="purchase_order_id" class="form-select border-start-0 ps-0">
                                        <option value="">Sin orden específica</option>
                                        <?php foreach ($pending_orders as $po): ?>
                                            <option value="<?php echo $po['id']; ?>"
                                                    data-total="<?php echo $po['total_amount']; ?>"
                                                    data-pending="<?php echo $po['pending_amount']; ?>">
                                                <?php echo htmlspecialchars($po['po_number'] . ' - Pendiente: $' . number_format($po['pending_amount'], 2)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-text">Al seleccionar una orden se aplicará el pago a su saldo pendiente.</div>
                            </div>

                            <div class="col-md-4">
                                <label for="payment_amount" class="form-label fw-bold">Monto del pago <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-dollar-sign text-muted"></i>
                                    </span>
                                    <input type="number" step="0.01" min="0.01" max="100000000" class="form-control border-start-0 ps-0" id="payment_amount" name="payment_amount" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="payment_method" class="form-label fw-bold">Método de pago <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-credit-card text-muted"></i>
                                    </span>
                                    <select name="payment_method" id="payment_method" class="form-select border-start-0 ps-0" required>
                                        <option value="">Seleccione método</option>
                                        <?php foreach ($payment_methods as $pm): ?>
                                            <option value="<?php echo htmlspecialchars($pm); ?>"><?php echo htmlspecialchars(ucfirst($pm)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="payment_date" class="form-label fw-bold">Fecha del pago <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-calendar-alt text-muted"></i>
                                    </span>
                                    <input type="date" class="form-control border-start-0 ps-0" id="payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="reference_number" class="form-label fw-bold">Referencia</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-hashtag text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="reference_number" name="reference_number" maxlength="100" placeholder="#Ref-123456">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="notes" class="form-label fw-bold">Notas</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-sticky-note text-muted"></i>
                                    </span>
                                    <textarea class="form-control border-start-0 ps-0" id="notes" name="notes" rows="1" maxlength="1000" placeholder="Opcional"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end pt-3">
                            <button type="submit" class="btn btn-primary rounded-pill px-4" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Registrar Pago
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="fas fa-list-alt me-2 text-info"></i>Órdenes Pendientes
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">Seleccione una orden para aplicar el pago y ver el monto pendiente en tiempo real.</p>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="border-0 rounded-start">PO</th>
                                    <th class="border-0">Proveedor</th>
                                    <th class="border-0 rounded-end text-end">Pendiente</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_orders as $po): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($po['po_number']); ?></span></td>
                                        <td class="small text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                        <td class="text-end fw-bold text-danger">$<?php echo number_format($po['pending_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pending_orders)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">No hay órdenes pendientes</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4">
            <h5 class="card-title mb-0 fw-bold">
                <i class="fas fa-history me-2 text-primary"></i>Pagos Recientes
            </h5>
        </div>
        <div class="card-body p-4">
            <p class="text-muted small mb-3">Últimos 20 pagos registrados. Puede anular pagos activos.</p>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 border-0 rounded-start">ID</th>
                            <th class="border-0">Proveedor</th>
                            <th class="border-0">PO</th>
                            <th class="border-0">Método</th>
                            <th class="border-0">Fecha</th>
                            <th class="border-0">Monto</th>
                            <th class="border-0 rounded-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $rp): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-muted">#<?php echo (int)$rp['id']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-initial rounded-circle bg-primary bg-opacity-10 text-primary me-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                            <?php echo strtoupper(substr($rp['company_name'] ?? '?', 0, 1)); ?>
                                        </div>
                                        <?php echo htmlspecialchars($rp['company_name'] ?? ''); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($rp['po_number'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($rp['po_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $method_icons = [
                                            'efectivo' => 'fa-money-bill',
                                            'transferencia' => 'fa-university',
                                            'cheque' => 'fa-money-check',
                                            'tarjeta' => 'fa-credit-card'
                                        ];
                                        $icon = $method_icons[strtolower($rp['payment_method'])] ?? 'fa-money-bill';
                                    ?>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas <?php echo $icon; ?> me-1"></i>
                                        <?php echo htmlspecialchars(ucfirst($rp['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($rp['payment_date'])); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($rp['payment_amount'], 2); ?></td>
                                <td>
                                    <?php if ($hasSpStatusColumn && ($rp['status'] ?? 'active') === 'active'): ?>
                                    <form method="POST" action="void_payment.php" onsubmit="return confirm('¿Seguro que desea anular el pago #<?php echo (int)$rp['id']; ?>?');" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$rp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                            <i class="fas fa-ban me-1"></i>Anular
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">No disponible</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Validación Bootstrap
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()

// Mostrar pendiente en tiempo real al seleccionar la orden
const poSelect = document.getElementById('purchase_order_id');
const amountInput = document.getElementById('payment_amount');
const submitBtn = document.getElementById('submitBtn');

function updatePendingInfo() {
    const option = poSelect.options[poSelect.selectedIndex];
    if (!option) return;
    const pending = parseFloat(option.getAttribute('data-pending')) || 0;
    amountInput.setAttribute('max', Math.max(0.01, pending));
}

poSelect.addEventListener('change', updatePendingInfo);
updatePendingInfo();

// Prevenir envíos duplicados en UI
submitBtn.addEventListener('click', function() {
    submitBtn.disabled = true;
    setTimeout(() => { submitBtn.disabled = false; }, 5000);
});
</script>

</div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
