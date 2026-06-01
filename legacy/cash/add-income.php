<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
$pdo = db();

// Verificar autenticación
requireAuth();

$errors = [];
$success = false;
$csrf_token = SecurityEnhancements::generateCSRFToken();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantCashSessions = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_sessions') : false;
$hasTenantCashIncome = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'cash_income') : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Error de seguridad: Token inválido o expirado. Por favor, intente de nuevo.";
    } else {
        $amount = parseCurrency($_POST['amount'] ?? 0);
        $income_type = $_POST['income_type'] ?? 'manual';
        $concept_id = $_POST['concept_id'] ?? 1;
        $payment_method = $_POST['payment_method'] ?? 'Efectivo';
        $description = trim($_POST['description'] ?? '');
        
        if ($amount <= 0) {
            $errors[] = "El monto debe ser mayor a cero.";
        }
        
        if (empty($description)) {
            $errors[] = "La descripción es obligatoria.";
        }
        
        $stmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE status = 'open'" . (($hasTenantCashSessions && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$tenantValue] : []);
        $cash_session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cash_session) {
            $errors[] = "No hay una sesión de caja abierta.";
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                if ($hasTenantCashIncome) {
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_income (
                            tenant_id, cash_session_id, income_type, concept_id, amount, 
                            payment_method, description, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $tenantValue,
                        $cash_session['id'],
                        $income_type,
                        $concept_id,
                        $amount,
                        $payment_method,
                        $description,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_income (
                            cash_session_id, income_type, concept_id, amount, 
                            payment_method, description, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $cash_session['id'],
                        $income_type,
                        $concept_id,
                        $amount,
                        $payment_method,
                        $description,
                        $_SESSION['user_id']
                    ]);
                }
                
                logActivity($_SESSION['user_id'], 'ADD_CASH_INCOME', 'cash_income', $pdo->lastInsertId());
                
                $pdo->commit();
                
                header("Location: index.php?success=" . urlencode("Ingreso registrado exitosamente."));
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error al registrar el ingreso: " . $e->getMessage();
            }
        }
    }
}

// Configuración del template
$page_title = 'Registrar Ingreso';


// Capturar el contenido de la página
ob_start();
?>

<!-- Header de la página -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1><i class="fas fa-plus-circle me-2"></i>Registrar Ingreso</h1>
        <p class="text-muted mb-0">Agregar dinero a la caja</p>
    </div>
    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left me-2"></i>Volver a Caja
    </a>
</div>

<!-- Mensajes de error -->
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h6 class="alert-heading">Por favor corrige los siguientes errores:</h6>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Formulario -->
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-plus-circle me-2"></i>
                    Información del Ingreso
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="incomeForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Monto <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo CompanySettings::getCurrency()['symbol']; ?></span>
                                    <input type="text" class="form-control money-input" id="amount" name="amount" 
                                           value="<?php echo isset($_POST['amount']) ? $_POST['amount'] : ''; ?>" required oninput="formatCurrencyInput(this)">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="payment_method" class="form-label">Método de Pago</label>
                                <select class="form-select" id="payment_method" name="payment_method">
                                    <option value="Efectivo" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Efectivo') ? 'selected' : ''; ?>>Efectivo</option>
                                    <option value="Transferencia" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Transferencia') ? 'selected' : ''; ?>>Transferencia</option>
                                    <option value="Tarjeta" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Tarjeta') ? 'selected' : ''; ?>>Tarjeta</option>
                                    <option value="Otros" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Otros') ? 'selected' : ''; ?>>Otros</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="income_type" class="form-label">Tipo de Ingreso</label>
                                <select class="form-select" id="income_type" name="income_type">
                                    <option value="manual" <?php echo (isset($_POST['income_type']) && $_POST['income_type'] === 'manual') ? 'selected' : ''; ?>>Manual</option>
                                    <option value="sale" <?php echo (isset($_POST['income_type']) && $_POST['income_type'] === 'sale') ? 'selected' : ''; ?>>Venta</option>
                                    <option value="service" <?php echo (isset($_POST['income_type']) && $_POST['income_type'] === 'service') ? 'selected' : ''; ?>>Servicio</option>
                                    <option value="other" <?php echo (isset($_POST['income_type']) && $_POST['income_type'] === 'other') ? 'selected' : ''; ?>>Otro</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="concept_id" class="form-label">Concepto</label>
                                <select class="form-select" id="concept_id" name="concept_id">
                                    <option value="1" <?php echo (isset($_POST['concept_id']) && $_POST['concept_id'] === '1') ? 'selected' : ''; ?>>Venta de Productos</option>
                                    <option value="2" <?php echo (isset($_POST['concept_id']) && $_POST['concept_id'] === '2') ? 'selected' : ''; ?>>Servicios</option>
                                    <option value="3" <?php echo (isset($_POST['concept_id']) && $_POST['concept_id'] === '3') ? 'selected' : ''; ?>>Reparaciones</option>
                                    <option value="4" <?php echo (isset($_POST['concept_id']) && $_POST['concept_id'] === '4') ? 'selected' : ''; ?>>Otros Ingresos</option>
                                    <option value="5" <?php echo (isset($_POST['concept_id']) && $_POST['concept_id'] === '5') ? 'selected' : ''; ?>>Fondo Inicial</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Descripción <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Descripción detallada del ingreso..." required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Registrar Ingreso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Información Importante
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h6 class="alert-heading">Sesión de Caja</h6>
                    <p class="mb-0">Debe haber una sesión de caja abierta para registrar ingresos.</p>
                </div>
                
                <div class="alert alert-success">
                    <h6 class="alert-heading">Tipos de Ingreso</h6>
                    <ul class="mb-0">
                        <li><strong>Manual:</strong> Ingresos registrados manualmente</li>
                        <li><strong>Venta:</strong> Ingresos por ventas</li>
                        <li><strong>Servicio:</strong> Ingresos por servicios</li>
                        <li><strong>Otro:</strong> Otros tipos de ingresos</li>
                    </ul>
                </div>
                
                <div class="alert alert-warning">
                    <h6 class="alert-heading">Métodos de Pago</h6>
                    <p class="mb-0">Selecciona el método de pago utilizado para este ingreso.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>

