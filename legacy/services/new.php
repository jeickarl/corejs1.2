<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Verificar autenticación
requireAuth();

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantServices = hasTenantColumnCached($pdo, 'services');
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');

// Verificar permisos
if (!hasRole(['admin', 'technician'])) {
    header('Location: ../dashboard.php');
    exit();
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $device_category_id = intval($_POST['device_category_id']);
        $base_price = floatval($_POST['base_price']);
        $estimated_time = intval($_POST['estimated_time']);
        $notes = trim($_POST['notes']);
        
        // Validaciones
        if (empty($name)) {
            throw new Exception('El nombre del servicio es obligatorio');
        }
        
        if (empty($device_category_id)) {
            throw new Exception('La categoría es obligatoria');
        }
        
        if ($base_price < 0) {
            throw new Exception('El precio base no puede ser negativo');
        }
        
        if ($estimated_time < 0) {
            throw new Exception('El tiempo estimado no puede ser negativo');
        }
        
        // Verificar que el nombre sea único
        $sql = "SELECT id FROM services WHERE name = ?" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantServices && !$perDatabase) ? [$name, $tenantValue] : [$name]);
        if ($stmt->fetch()) {
            throw new Exception('Ya existe un servicio con ese nombre');
        }
        
        // Insertar servicio
        if ($hasTenantServices) {
            $stmt = $pdo->prepare("
                INSERT INTO services (
                    tenant_id, name, description, device_category_id, base_price, 
                    estimated_time, notes, active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $tenantValue, $name, $description, $device_category_id, $base_price,
                $estimated_time, $notes
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO services (
                    name, description, device_category_id, base_price, 
                    estimated_time, notes, active
                ) VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $name, $description, $device_category_id, $base_price,
                $estimated_time, $notes
            ]);
        }
        
        $service_id = $pdo->lastInsertId();
        
        // Registrar actividad
        logActivity($_SESSION['user_id'], 'CREATE_SERVICE', 'services', $service_id);
        
        $mensaje = 'Servicio creado exitosamente';
        $tipo_mensaje = 'success';
        
        // Redirigir después de 2 segundos
        header("refresh:2;url=index.php");
        
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Obtener categorías de dispositivos
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM device_categories WHERE active = 1" . (($hasTenantDeviceCategories && !$perDatabase) ? " AND tenant_id = ?" : "") . " ORDER BY name");
    $stmt->execute(($hasTenantDeviceCategories && !$perDatabase) ? [$tenantValue] : []);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Iniciar buffer de salida
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-plus me-2"></i>Nuevo Servicio</h1>
            <p class="text-muted mb-0">Agregar un nuevo servicio al taller</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Servicios
        </a>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tools me-2"></i>Información del Servicio
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="name" class="form-label">
                                        Nombre del Servicio <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           required maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="device_category_id" class="form-label">
                                        Categoría <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="device_category_id" name="device_category_id" required>
                                        <option value="">Seleccionar categoría</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>" 
                                                    <?php echo ($_POST['device_category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Descripción</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="base_price" class="form-label">Precio Base</label>
                                    <input type="text" class="form-control" id="base_price" name="base_price" 
                                           value="<?php echo $_POST['base_price'] ?? '0'; ?>" 
                                           placeholder="0">
                                    <div class="form-text">Precio base del servicio</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="estimated_time" class="form-label">Tiempo Estimado (minutos)</label>
                                    <input type="number" class="form-control" id="estimated_time" name="estimated_time" 
                                           value="<?php echo $_POST['estimated_time'] ?? '0'; ?>" 
                                           min="0" step="5">
                                    <div class="form-text">Duración estimada en minutos</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notas</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            <div class="form-text">Información adicional sobre el servicio</div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Guardar Servicio
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb me-2"></i>Consejos:</h6>
                        <ul class="mb-0">
                            <li>El <strong>nombre</strong> debe ser claro y descriptivo</li>
                            <li>La <strong>descripción</strong> ayuda a entender el servicio</li>
                            <li>El <strong>precio base</strong> puede ajustarse por orden</li>
                            <li>El <strong>tiempo estimado</strong> ayuda en la planificación</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
                        <p class="mb-0">Una vez creado el servicio, se puede usar en las órdenes de trabajo. El precio puede variar según la complejidad del caso.</p>
                    </div>
                    
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>Campos Obligatorios:</h6>
                        <ul class="mb-0">
                            <li>Nombre del servicio</li>
                            <li>Categoría de dispositivo</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Función para formatear campos de dinero con comas
function formatMoneyInput(input) {
    let value = input.value.replace(/[^\d]/g, ''); // Remover todo excepto números
    
    if (value === '') {
        input.value = '';
        return;
    }
    
    // Formatear con comas
    value = value.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    input.value = value;
    
    // Guardar valor numérico para cálculos
    input.setAttribute('data-numeric-value', value.replace(/,/g, ''));
}

// Función para obtener valor numérico sin formato
function getNumericValue(input) {
    const numericValue = input.getAttribute('data-numeric-value');
    return numericValue ? parseFloat(numericValue) : 0;
}

// Inicializar formateo de dinero
document.addEventListener('DOMContentLoaded', function() {
    const basePriceField = document.getElementById('base_price');
    
    // Aplicar formateo automático
    basePriceField.addEventListener('input', function() {
        formatMoneyInput(this);
    });
    
    // Formatear valor inicial si existe
    if (basePriceField.value && basePriceField.value !== '0') {
        formatMoneyInput(basePriceField);
    }
    
    // Convertir valor formateado a numérico antes de enviar
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            const numericValue = getNumericValue(basePriceField) || parseFloat(basePriceField.value.replace(/,/g, '')) || 0;
            
            // Crear campo oculto con valor numérico
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = 'base_price';
            hiddenField.value = numericValue;
            
            // Remover name del campo visible
            basePriceField.removeAttribute('name');
            
            // Agregar campo oculto
            this.appendChild(hiddenField);
        });
    }
});

// Validación de tiempo estimado
document.getElementById('estimated_time').addEventListener('input', function() {
    let value = parseInt(this.value);
    if (value && value % 5 !== 0) {
        // Redondear a múltiplos de 5
        this.value = Math.round(value / 5) * 5;
    }
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
