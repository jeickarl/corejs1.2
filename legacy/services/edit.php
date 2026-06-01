<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Obtener configuración de moneda
$currency_config = CompanySettings::getCurrency();

// Verificar autenticación
requireAuth();

// Verificar permisos
if (!hasRole(['admin', 'technician'])) {
    header('Location: ../dashboard.php');
    exit();
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantServices = hasTenantColumnCached($pdo, 'services');
$hasTenantDeviceCategories = hasTenantColumnCached($pdo, 'device_categories');

$service_id = intval($_GET['id'] ?? 0);

if (!$service_id) {
    header('Location: index.php');
    exit();
}

// Obtener información del servicio
$service = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : ""));
    $stmt->execute(($hasTenantServices && !$perDatabase) ? [$service_id, $tenantValue] : [$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error al obtener servicio: " . $e->getMessage());
    header('Location: index.php');
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
        $active = isset($_POST['active']) ? 1 : 0;
        
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
        
        // Verificar que el nombre sea único (excluyendo el registro actual)
        $sql = "SELECT id FROM services WHERE name = ? AND id != ?" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantServices && !$perDatabase) ? [$name, $service_id, $tenantValue] : [$name, $service_id]);
        if ($stmt->fetch()) {
            throw new Exception('Ya existe un servicio con ese nombre');
        }
        
        // Actualizar servicio
        $stmt = $pdo->prepare("
            UPDATE services SET
                name = ?, description = ?, device_category_id = ?, base_price = ?,
                estimated_time = ?, notes = ?, active = ?
            WHERE id = ?" . (($hasTenantServices && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ");
        $params = [
            $name, $description, $device_category_id, $base_price,
            $estimated_time, $notes, $active, $service_id
        ];
        if ($hasTenantServices && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        
        // Registrar actividad
        logActivity($_SESSION['user_id'], 'UPDATE_SERVICE', 'services', $service_id);
        
        $mensaje = 'Servicio actualizado exitosamente';
        $tipo_mensaje = 'success';
        
        // Actualizar datos del servicio
        $service = array_merge($service, [
            'name' => $name,
            'description' => $description,
            'device_category_id' => $device_category_id,
            'base_price' => $base_price,
            'estimated_time' => $estimated_time,
            'notes' => $notes,
            'active' => $active
        ]);
        
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
            <h1><i class="fas fa-edit me-2"></i>Editar Servicio</h1>
            <p class="text-muted mb-0">Modificar información del servicio</p>
        </div>
        <div class="d-flex gap-2">
            <a href="view.php?id=<?php echo $service['id']; ?>" class="btn btn-outline-primary">
                <i class="fas fa-eye me-2"></i>Ver
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
        </div>
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
            <form method="POST">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label for="name" class="form-label">
                                Nombre del Servicio <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   value="<?php echo htmlspecialchars($service['name']); ?>" 
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
                                            <?php echo $service['device_category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="description" class="form-label">Descripción</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($service['description']); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="base_price" class="form-label">Precio Base</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo $currency_config['symbol']; ?></span>
                                <input type="text" class="form-control money-input" id="base_price" name="base_price" 
                                       value="<?php echo number_format($service['base_price'], 0, '.', ','); ?>" 
                                       placeholder="0" oninput="formatCurrencyInput(this)">
                            </div>
                            <div class="form-text">Precio base del servicio</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="estimated_time" class="form-label">Tiempo Estimado (minutos)</label>
                            <input type="number" class="form-control" id="estimated_time" name="estimated_time" 
                                   value="<?php echo $service['estimated_time']; ?>" 
                                   min="0" step="5">
                            <div class="form-text">Duración estimada en minutos</div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="notes" class="form-label">Notas</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($service['notes']); ?></textarea>
                    <div class="form-text">Información adicional sobre el servicio</div>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="active" name="active" 
                               <?php echo $service['active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="active">
                            Servicio activo
                        </label>
                    </div>
                </div>
                
                <div class="d-flex justify-content-end gap-2">
                    <a href="view.php?id=<?php echo $service['id']; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Actualizar Servicio
                    </button>
                </div>
            </form>
        </div>
        
        <div class="col-lg-4">
            <!-- Información Actual -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información Actual
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <td><strong>Estado:</strong></td>
                            <td>
                                <?php if ($service['active']): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactivo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Creado:</strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($service['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Actualizado:</strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($service['updated_at'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Información -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Información
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb me-2"></i>Consejos:</h6>
                        <ul class="mb-0">
                            <li>Mantén la información <strong>actualizada</strong></li>
                            <li>El precio puede <strong>ajustarse</strong> por orden</li>
                            <li>El tiempo estimado ayuda en la <strong>planificación</strong></li>
                            <li>Las notas son útiles para <strong>referencia</strong></li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
                        <p class="mb-0">Si marcas el servicio como <strong>inactivo</strong>, no aparecerá en las listas de selección para nuevas órdenes.</p>
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
