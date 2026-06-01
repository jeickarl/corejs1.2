<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

// Verificar permisos de administrador
if (!hasRole('admin')) {
    header('Location: ../dashboard.php');
    exit();
}

// Tenant actual
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantPm = hasTenantColumnCached($pdo, 'payment_methods');

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_PAYMENT_METHODS', 'payment_methods', null);

// Detectar columnas de estado
$hasStatus = false;
$hasIsActive = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
    $hasStatus = $c && $c->rowCount() > 0;
} catch (Exception $e) {}
try {
    $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
    $hasIsActive = $c && $c->rowCount() > 0;
} catch (Exception $e) {}

// Crear tabla si no existe (por seguridad)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(50) NOT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {}


// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_payment_methods') {
        $submitted_methods = $_POST['payment_methods'] ?? [];
        $submitted_methods = array_map('trim', $submitted_methods);
        $submitted_methods = array_filter($submitted_methods); // Remover vacíos
        $submitted_methods = array_unique($submitted_methods); // Remover duplicados
        
        if (empty($submitted_methods)) {
            $error_message = "Debe configurar al menos un método de pago.";
        } else {
            try {
                $pdo->beginTransaction();

                // Obtener métodos existentes
                $sql = "SELECT id, name FROM payment_methods" . (($hasTenantPm && !$perDatabase) ? " WHERE tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
                $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_map = []; // name (lowercase) -> id
                foreach ($existing as $row) {
                    $existing_map[mb_strtolower($row['name'])] = $row['id'];
                }

                $processed_ids = [];

                foreach ($submitted_methods as $method_name) {
                    $lower_name = mb_strtolower($method_name);
                    
                    if (isset($existing_map[$lower_name])) {
                        // Actualizar existente (reactivar si estaba inactivo)
                        $id = $existing_map[$lower_name];
                        if ($hasStatus) {
                            $sql = "UPDATE payment_methods SET status = 'active', name = ? WHERE id = ?" . (($hasTenantPm && !$perDatabase) ? " AND tenant_id = ?" : "");
                            $upd = $pdo->prepare($sql);
                            $params = [$method_name, $id];
                            if ($hasTenantPm && !$perDatabase) { $params[] = $tenantValue; }
                            $upd->execute($params);
                        } elseif ($hasIsActive) {
                            $sql = "UPDATE payment_methods SET is_active = 1, name = ? WHERE id = ?" . (($hasTenantPm && !$perDatabase) ? " AND tenant_id = ?" : "");
                            $upd = $pdo->prepare($sql);
                            $params = [$method_name, $id];
                            if ($hasTenantPm && !$perDatabase) { $params[] = $tenantValue; }
                            $upd->execute($params);
                        } else {
                            // Fallback
                            $sql = "UPDATE payment_methods SET name = ? WHERE id = ?" . (($hasTenantPm && !$perDatabase) ? " AND tenant_id = ?" : "");
                            $upd = $pdo->prepare($sql);
                            $params = [$method_name, $id];
                            if ($hasTenantPm && !$perDatabase) { $params[] = $tenantValue; }
                            $upd->execute($params);
                        }
                        $processed_ids[] = $id;
                    } else {
                        // Insertar nuevo
                        if ($hasTenantPm) {
                            if ($hasStatus) {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (tenant_id, name, status) VALUES (?, ?, 'active')");
                            } elseif ($hasIsActive) {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (tenant_id, name, is_active) VALUES (?, ?, 1)");
                            } else {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (tenant_id, name) VALUES (?, ?)");
                            }
                            $ins->execute([$tenantValue, $method_name]);
                        } else {
                            if ($hasStatus) {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (name, status) VALUES (?, 'active')");
                            } elseif ($hasIsActive) {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (name, is_active) VALUES (?, 1)");
                            } else {
                                $ins = $pdo->prepare("INSERT INTO payment_methods (name) VALUES (?)");
                            }
                            $ins->execute([$method_name]);
                        }
                        $processed_ids[] = $pdo->lastInsertId();
                    }
                }

                // Desactivar los que no vinieron en la lista
                if (!empty($processed_ids) && $hasTenantPm && !$perDatabase) {
                    $placeholders = implode(',', array_fill(0, count($processed_ids), '?'));
                    if ($hasStatus) {
                        $sql = "UPDATE payment_methods SET status = 'inactive' WHERE tenant_id = ? AND id NOT IN ($placeholders)";
                        $pdo->prepare($sql)->execute(array_merge([$tenantValue], $processed_ids));
                    } elseif ($hasIsActive) {
                        $sql = "UPDATE payment_methods SET is_active = 0 WHERE tenant_id = ? AND id NOT IN ($placeholders)";
                        $pdo->prepare($sql)->execute(array_merge([$tenantValue], $processed_ids));
                    }
                }

                $pdo->commit();
                $success_message = "Métodos de pago actualizados exitosamente.";
                logActivity($_SESSION['user_id'], 'UPDATE_PAYMENT_METHODS', 'payment_methods', null);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error al actualizar métodos de pago: " . $e->getMessage());
                $error_message = "Error al guardar cambios: " . $e->getMessage();
            }
        }
    }
}

// Obtener métodos activos para mostrar
$payment_methods = [];
$status_cond = "1=1";
if ($hasStatus) $status_cond = "status = 'active'";
elseif ($hasIsActive) $status_cond = "is_active = 1";

try {
    $sql = "SELECT name FROM payment_methods WHERE $status_cond" . (($hasTenantPm && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantPm && !$perDatabase) ? [$tenantValue] : []);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $payment_methods[] = $r['name'];
    }
} catch (Exception $e) {
    // Si falla, array vacío
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-credit-card me-2"></i>Métodos de Pago</h1>
            <p class="text-muted mb-0">Configura los métodos de pago disponibles en el sistema</p>
        </div>
        <div class="btn-group">
            <a href="../config/settings.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Configuración
            </a>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4" style="border-radius: 1rem 1rem 0 0;">
                    <h5 class="card-title mb-0 fw-bold text-dark">
                        <i class="fas fa-list me-2"></i>Métodos de Pago Configurados
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" id="paymentMethodsForm">
                        <input type="hidden" name="action" value="update_payment_methods">
                        
                        <div id="paymentMethodsContainer">
                            <?php foreach ($payment_methods as $method): ?>
                                <?php
                                $icon = 'fa-money-bill-wave';
                                $lowerName = mb_strtolower($method, 'UTF-8');
                                if (strpos($lowerName, 'tarjeta') !== false || strpos($lowerName, 'visa') !== false || strpos($lowerName, 'master') !== false) { $icon = 'fa-credit-card'; }
                                elseif (strpos($lowerName, 'banco') !== false || strpos($lowerName, 'transferencia') !== false) { $icon = 'fa-university'; }
                                elseif (strpos($lowerName, 'efectivo') !== false || strpos($lowerName, 'cash') !== false) { $icon = 'fa-coins'; }
                                elseif (strpos($lowerName, 'nequi') !== false || strpos($lowerName, 'daviplata') !== false || strpos($lowerName, 'movil') !== false) { $icon = 'fa-mobile-alt'; }
                                elseif (strpos($lowerName, 'cheque') !== false) { $icon = 'fa-money-check-alt'; }
                                ?>
                                <div class="row mb-3 payment-method-row align-items-center">
                                    <div class="col-md-10">
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0">
                                                <i class="fas <?php echo $icon; ?> text-dark"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" name="payment_methods[]" 
                                                   value="<?php echo htmlspecialchars($method); ?>" 
                                                   placeholder="Nombre del método de pago" style="border-radius: 0 0.5rem 0.5rem 0;">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-method shadow-sm" style="border-radius: 0.5rem;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($payment_methods)): ?>
                                <div class="row mb-3 payment-method-row align-items-center">
                                    <div class="col-md-10">
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0">
                                                <i class="fas fa-coins text-dark"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" name="payment_methods[]" 
                                                   value="Efectivo" placeholder="Nombre del método de pago" style="border-radius: 0 0.5rem 0.5rem 0;">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-method shadow-sm" style="border-radius: 0.5rem;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-dark rounded-pill shadow-sm" id="addMethodBtn">
                                <i class="fas fa-plus me-2"></i>Agregar Método
                            </button>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-success rounded-pill shadow-sm px-4">
                                <i class="fas fa-save me-2"></i>Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4" style="border-radius: 1rem 1rem 0 0;">
                    <h5 class="card-title mb-0 fw-bold text-dark">
                        <i class="fas fa-info-circle me-2"></i>Información
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted">
                        Los métodos de pago configurados aquí estarán disponibles en:
                    </p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>Creación de facturas</li>
                        <li><i class="fas fa-check text-success me-2"></i>Registro de pagos</li>
                        <li><i class="fas fa-check text-success me-2"></i>Ingresos de caja</li>
                        <li><i class="fas fa-check text-success me-2"></i>Egresos de caja</li>
                        <li><i class="fas fa-check text-success me-2"></i>Reportes financieros</li>
                    </ul>
                    
                    <div class="alert alert-info mt-3">
                        <small>
                            <i class="fas fa-lightbulb me-1"></i>
                            <strong>Tip:</strong> Los métodos más comunes son: Efectivo, Transferencia, Tarjeta de Crédito, Tarjeta de Débito, Cheque.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Agregar nuevo método de pago
    document.getElementById('addMethodBtn').addEventListener('click', function() {
        const container = document.getElementById('paymentMethodsContainer');
        const newRow = document.createElement('div');
        newRow.className = 'row mb-3 payment-method-row align-items-center';
        newRow.innerHTML = `
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="fas fa-money-bill-wave text-dark"></i>
                    </span>
                    <input type="text" class="form-control border-start-0 ps-0" name="payment_methods[]" 
                           placeholder="Nombre del método de pago" style="border-radius: 0 0.5rem 0.5rem 0;">
                </div>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger btn-sm remove-method shadow-sm" style="border-radius: 0.5rem;">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(newRow);
        
        // Enfocar el nuevo input
        newRow.querySelector('input').focus();
    });
    
    // Remover método de pago
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-method')) {
            const row = e.target.closest('.payment-method-row');
            const container = document.getElementById('paymentMethodsContainer');
            
            // Permitir eliminar visualmente incluso si es el último (la validación del servidor y submit lo manejarán)
            // Pero es mejor UX advertir
            if (container.querySelectorAll('.payment-method-row').length > 1) {
                row.remove();
            } else {
                alert('Debe mantener al menos un método de pago.');
            }
        }
    });
    
    // Validación del formulario
    document.getElementById('paymentMethodsForm').addEventListener('submit', function(e) {
        const inputs = document.querySelectorAll('input[name="payment_methods[]"]');
        const values = Array.from(inputs).map(input => input.value.trim()).filter(value => value !== '');
        
        if (values.length === 0) {
            e.preventDefault();
            alert('Debe configurar al menos un método de pago');
            return;
        }
        
        // Verificar duplicados
        const uniqueValues = [...new Set(values.map(v => v.toLowerCase()))];
        if (uniqueValues.length !== values.length) {
            e.preventDefault();
            alert('No se permiten métodos de pago duplicados');
            return;
        }
    });
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
