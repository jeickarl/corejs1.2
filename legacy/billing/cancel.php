<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Verificar permisos de administrador
if (!hasRole('admin')) {
    header('Location: index.php?error=' . urlencode('Acceso denegado'));
    exit();
}

// Obtener ID de la factura
$invoice_id = $_GET['id'] ?? '';
if (empty($invoice_id)) {
    header('Location: index.php');
    exit();
}

// Obtener información de la factura
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$invoice = null;
$items = [];
$payments = [];

try {
    // Obtener factura
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
                   c.email as client_email,
                   c.phone as client_phone,
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
                   c.email as client_email,
                   c.phone as client_phone,
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
        header('Location: index.php');
        exit();
    }
    
    // Obtener items de la factura
    $hasTenantItems = hasTenantColumnCached($pdo, 'invoice_items');
    $sql = "SELECT * FROM invoice_items WHERE invoice_id = ?" . (($hasTenantItems && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantItems && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener pagos de la factura
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
    $stmt->execute(($hasTenantPayments && !$perDatabase) ? [$invoice_id, $tenantValue] : [$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error al obtener datos de la factura: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_INVOICE_CANCELLATION', 'invoices', $invoice_id);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'cancel_invoice') {
        $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');
        $confirm_cancellation = $_POST['confirm_cancellation'] ?? '';
        
        // Validaciones
        if (empty($cancellation_reason)) {
            $errors[] = "Debe proporcionar una razón para la anulación.";
        }
        
        if ($confirm_cancellation !== 'CONFIRMAR') {
            $errors[] = "Debe escribir 'CONFIRMAR' para proceder con la anulación.";
        }
        
        if ($invoice['status'] === 'cancelled') {
            $errors[] = "Esta factura ya está anulada.";
        }
        
        // Si no hay errores, proceder con la anulación
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Actualizar factura
                $hasTenantInvoices = hasTenantColumnCached($pdo, 'invoices');
                $sqlUpd = "
                    UPDATE invoices 
                    SET status = 'cancelled',
                        cancelled_by = ?,
                        cancelled_at = NOW(),
                        cancellation_reason = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "") . "
                ";
                $stmt = $pdo->prepare($sqlUpd);
                $params = [$_SESSION['user_id'], $cancellation_reason, $invoice_id];
                if (!$perDatabase && $hasTenantInvoices) { $params[] = $tenantValue; }
                $stmt->execute($params);
                
                // Si hay pagos, crear reversos en caja
                if (!empty($payments)) {
                    $hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');
                    foreach ($payments as $payment) {
                        // Crear egreso en caja por el pago anulado
                        if ($hasTenantCashExpenses) {
                            $stmt = $pdo->prepare("
                                INSERT INTO cash_expenses (
                                    cash_session_id, expense_type, concept_id, amount, payment_method,
                                    reference_number, description, created_by, tenant_id
                                ) VALUES (?, 'other', 9, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $payment['cash_session_id'], 
                                $payment['payment_amount'], 
                                $payment['payment_method'],
                                "REV-" . $payment['reference_number'],
                                "Reverso de pago anulado - Factura {$invoice['invoice_number']}",
                                $_SESSION['user_id'],
                                $tenantValue
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO cash_expenses (
                                    cash_session_id, expense_type, concept_id, amount, payment_method,
                                    reference_number, description, created_by
                                ) VALUES (?, 'other', 9, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $payment['cash_session_id'], 
                                $payment['payment_amount'], 
                                $payment['payment_method'],
                                "REV-" . $payment['reference_number'],
                                "Reverso de pago anulado - Factura {$invoice['invoice_number']}",
                                $_SESSION['user_id']
                            ]);
                        }
                    }
                }
                
                // Registrar actividad
                logActivity($_SESSION['user_id'], 'CANCEL_INVOICE', 'invoices', $invoice_id);
                
                $pdo->commit();
                $success = true;
                
                // Recargar datos
                if ($perDatabase) {
                    $stmt = $pdo->prepare("
                        SELECT i.*, 
                               CASE 
                                   WHEN c.client_type = 'company' THEN c.company_name
                                   ELSE c.first_name
                               END as client_name,
                               c.email as client_email,
                               c.phone as client_phone,
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
                               c.email as client_email,
                               c.phone as client_phone,
                               u1.name as created_by_name,
                               u2.name as cancelled_by_name
                        FROM invoices i
                        {$joinClients}
                        {$joinU1}
                        {$joinU2}
                        WHERE i.id = ?" . ($hasTenantInvoices ? " AND i.tenant_id = ?" : "") . "
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($hasTenantInvoices ? [$invoice_id, $tenant_id] : [$invoice_id]);
                }
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Error al anular factura: " . $e->getMessage());
                $errors[] = "Error al anular la factura: " . $e->getMessage();
            }
        }
    }
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-ban me-2"></i>Anular Factura</h1>
            <p class="text-muted mb-0">Anular la factura <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
        </div>
        <div class="btn-group">
            <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-eye me-2"></i>Ver Factura
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>Factura anulada exitosamente
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Error:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Información de la Factura -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-invoice me-2"></i>Información de la Factura
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong>Número:</strong><br>
                                <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Cliente:</strong><br>
                                <?php echo htmlspecialchars($invoice['client_name']); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Fecha:</strong><br>
                                <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Creada por:</strong><br>
                                <?php echo htmlspecialchars($invoice['created_by_name']); ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong>Total:</strong><br>
                                <span class="h5 text-primary">$<?php echo number_format($invoice['total_amount'], 0, ',', '.'); ?></span>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Pagado:</strong><br>
                                <span class="h5 text-success">$<?php echo number_format($invoice['paid_amount'], 0, ',', '.'); ?></span>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Pendiente:</strong><br>
                                <span class="h5 text-warning">$<?php echo number_format($invoice['pending_amount'], 0, ',', '.'); ?></span>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Estado:</strong><br>
                                <?php
                                $status_class = '';
                                $status_text = '';
                                switch ($invoice['status']) {
                                    case 'draft':
                                        $status_class = 'bg-secondary';
                                        $status_text = 'Borrador';
                                        break;
                                    case 'sent':
                                        $status_class = 'bg-info';
                                        $status_text = 'Completada';
                                        break;
                                    case 'paid':
                                        $status_class = 'bg-success';
                                        $status_text = 'Pagada';
                                        break;
                                    case 'cancelled':
                                        $status_class = 'bg-danger';
                                        $status_text = 'Anulada';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($invoice['status'] === 'cancelled'): ?>
                        <div class="alert alert-danger mt-3">
                            <h6><i class="fas fa-ban me-2"></i>Factura Anulada</h6>
                            <p class="mb-1"><strong>Anulada por:</strong> <?php echo htmlspecialchars($invoice['cancelled_by_name']); ?></p>
                            <p class="mb-1"><strong>Fecha de anulación:</strong> <?php echo date('d/m/Y H:i', strtotime($invoice['cancelled_at'])); ?></p>
                            <p class="mb-0"><strong>Razón:</strong> <?php echo htmlspecialchars($invoice['cancellation_reason']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Items de la Factura -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>Items de la Factura
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Descripción</th>
                                    <th>Cant.</th>
                                    <th>Precio</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['description']); ?></strong>
                                            <br><small class="text-muted"><?php echo ucfirst($item['item_type']); ?></small>
                                        </td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Formulario de Anulación -->
        <div class="col-lg-6">
            <?php if ($invoice['status'] !== 'cancelled'): ?>
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>Anular Factura
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-warning me-2"></i>Advertencia</h6>
                            <p class="mb-0">
                                Esta acción es <strong>irreversible</strong>. Al anular la factura:
                            </p>
                            <ul class="mb-0 mt-2">
                                <li>Se marcará como anulada en el sistema</li>
                                <li>Se crearán reversos automáticos en caja por los pagos recibidos</li>
                                <li>Se registrará la razón de anulación para auditoría</li>
                                <li>No se podrá editar ni usar esta factura</li>
                            </ul>
                        </div>
                        
                        <form method="POST" id="cancellationForm">
                            <input type="hidden" name="action" value="cancel_invoice">
                            
                            <div class="mb-3">
                                <label for="cancellation_reason" class="form-label">Razón de Anulación *</label>
                                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" 
                                          rows="4" placeholder="Describa detalladamente la razón de la anulación..." required></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_cancellation" class="form-label">
                                    Confirmar Anulación *
                                </label>
                                <input type="text" class="form-control" id="confirm_cancellation" name="confirm_cancellation" 
                                       placeholder="Escriba 'CONFIRMAR' para proceder" required>
                                <div class="form-text">Debe escribir exactamente "CONFIRMAR" para proceder</div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger" id="cancelBtn" disabled>
                                    <i class="fas fa-ban me-2"></i>Anular Factura
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-ban text-danger" style="font-size: 4rem;"></i>
                        <h5 class="mt-3 text-danger">Factura Anulada</h5>
                        <p class="text-muted">Esta factura ya ha sido anulada y no se puede modificar.</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Historial de Pagos -->
            <?php if (!empty($payments)): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-money-bill me-2"></i>Pagos Recibidos
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Monto</th>
                                        <th>Método</th>
                                        <th>Registrado por</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                            <td>
                                                <strong class="text-success">
                                                    $<?php echo number_format($payment['payment_amount'], 0, ',', '.'); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['created_by_name']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($invoice['status'] !== 'cancelled'): ?>
                            <div class="alert alert-info mt-3">
                                <small>
                                    <i class="fas fa-info-circle me-1"></i>
                                    <strong>Nota:</strong> Si anula esta factura, se crearán reversos automáticos en caja por todos los pagos recibidos.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const confirmInput = document.getElementById('confirm_cancellation');
    const cancelBtn = document.getElementById('cancelBtn');
    
    if (confirmInput && cancelBtn) {
        confirmInput.addEventListener('input', function() {
            if (this.value === 'CONFIRMAR') {
                cancelBtn.disabled = false;
                cancelBtn.classList.remove('btn-secondary');
                cancelBtn.classList.add('btn-danger');
            } else {
                cancelBtn.disabled = true;
                cancelBtn.classList.remove('btn-danger');
                cancelBtn.classList.add('btn-secondary');
            }
        });
        
        // Validación adicional del formulario
        document.getElementById('cancellationForm').addEventListener('submit', function(e) {
            if (confirmInput.value !== 'CONFIRMAR') {
                e.preventDefault();
                showWarningAlert('Debe escribir "CONFIRMAR" para proceder con la anulación', 'Confirmación requerida');
                return;
            }
            
            if (!confirm('¿Está seguro de que desea anular esta factura? Esta acción es irreversible.')) {
                e.preventDefault();
                return;
            }
        });
    }
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
