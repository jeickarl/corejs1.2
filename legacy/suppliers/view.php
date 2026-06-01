<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';

// Verificar autenticación
requireAuth();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantSuppliers = hasTenantColumnCached($pdo, 'suppliers');

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($supplier_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de proveedor no válido.'));
    exit();
}

// Obtener información del proveedor
$supplier = null;
try {
    $sql = "SELECT * FROM suppliers WHERE id = ?" . (($hasTenantSuppliers && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSuppliers && !$perDatabase) ? [$supplier_id, $tenantValue] : [$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        header('Location: index.php?error=' . urlencode('Proveedor no encontrado.'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar el proveedor.'));
    exit();
}

// Obtener estadísticas del proveedor
$stats = [
    'total_purchases' => 0,
    'pending_payments' => 0,
    'last_purchase_date' => null,
    'average_purchase' => 0
];

// Mensajes de éxito/error
$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = $_GET['success'];
    $tipo_mensaje = 'success';
} elseif (isset($_GET['error'])) {
    $mensaje = $_GET['error'];
    $tipo_mensaje = 'danger';
}
?>

<?php
$page_title = 'Ver Proveedor';
ob_start();
?>

<?php include __DIR__ . '/_suppliers_styles.php'; ?>

<div class="suppliers-page">
<div class="container-fluid p-3" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4">
            <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 gap-3 flex-wrap">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-truck me-2 text-primary no-theme"></i><?php echo htmlspecialchars($supplier['company_name']); ?></h4>
                    <div class="text-muted small">Información detallada del proveedor</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-warning rounded-pill px-4">
                        <i class="fas fa-edit me-2"></i>Editar
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fas fa-arrow-left me-2"></i>Volver
                    </a>
                </div>
            </div>

            <!-- Mensajes -->
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
                    <?php if ($tipo_mensaje == 'success'): ?>
                        <i class="fas fa-check-circle me-2 fa-lg"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Información Básica -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-info-circle me-2 text-primary"></i>Información Básica
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Código del Proveedor</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-hashtag me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($supplier['supplier_code']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Tipo de Proveedor</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-user-tag me-2 text-muted"></i>
                                            <?php 
                                            $types = [
                                                'company' => 'Empresa',
                                                'individual' => 'Persona Natural'
                                            ];
                                            echo $types[$supplier['supplier_type']] ?? $supplier['supplier_type'];
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Nombre de la Empresa</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-building me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($supplier['company_name']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">NIT/RUT</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-id-card me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($supplier['tax_id']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Nombre del Contacto</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-user me-2 text-muted"></i>
                                    <?php echo htmlspecialchars($supplier['contact_name']); ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Estado</label>
                                <div>
                                    <span class="badge rounded-pill <?php echo $supplier['is_active'] ? 'bg-success' : 'bg-danger'; ?> px-3 py-2">
                                        <i class="fas <?php echo $supplier['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?> me-1"></i>
                                        <?php echo $supplier['is_active'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Información de Contacto -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-address-book me-2 text-primary"></i>Información de Contacto
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Teléfono</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <?php if ($supplier['phone']): ?>
                                                <i class="fas fa-phone me-2 text-muted"></i><?php echo htmlspecialchars($supplier['phone']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-phone me-2 text-muted"></i>No especificado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Celular</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <?php if ($supplier['mobile']): ?>
                                                <i class="fas fa-mobile-alt me-2 text-muted"></i><?php echo htmlspecialchars($supplier['mobile']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-mobile-alt me-2 text-muted"></i>No especificado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Email</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <?php if ($supplier['email']): ?>
                                                <i class="fas fa-envelope me-2 text-muted"></i>
                                                <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($supplier['email']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-envelope me-2 text-muted"></i>No especificado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Sitio Web</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <?php if ($supplier['website']): ?>
                                                <i class="fas fa-globe me-2 text-muted"></i>
                                                <a href="<?php echo htmlspecialchars($supplier['website']); ?>" target="_blank" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($supplier['website']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-globe me-2 text-muted"></i>No especificado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($supplier['address']): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted">Dirección</label>
                                    <div class="p-2 bg-light rounded-3 border">
                                        <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                                        <?php echo nl2br(htmlspecialchars($supplier['address'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <?php if ($supplier['city']): ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold text-muted">Ciudad</label>
                                            <div class="p-2 bg-light rounded-3 border">
                                                <i class="fas fa-city me-2 text-muted"></i>
                                                <?php echo htmlspecialchars($supplier['city']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($supplier['state']): ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold text-muted">Estado/Departamento</label>
                                            <div class="p-2 bg-light rounded-3 border">
                                                <i class="fas fa-map me-2 text-muted"></i>
                                                <?php echo htmlspecialchars($supplier['state']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($supplier['country']): ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold text-muted">País</label>
                                            <div class="p-2 bg-light rounded-3 border">
                                                <i class="fas fa-flag me-2 text-muted"></i>
                                                <?php echo htmlspecialchars($supplier['country']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($supplier['postal_code']): ?>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Código Postal</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-mail-bulk me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($supplier['postal_code']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Información Financiera -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-dollar-sign me-2 text-primary"></i>Información Financiera
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Términos de Pago</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-clock me-2 text-muted"></i>
                                            <?php 
                                            $payment_terms = [
                                                'immediate' => 'Inmediato',
                                                '15_days' => '15 días',
                                                '30_days' => '30 días',
                                                '45_days' => '45 días',
                                                '60_days' => '60 días'
                                            ];
                                            echo $supplier['payment_terms'] ? ($payment_terms[$supplier['payment_terms']] ?? $supplier['payment_terms']) : '<span class="text-muted">No especificado</span>';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Límite de Crédito</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-credit-card me-2 text-muted"></i>
                                            <?php if ($supplier['credit_limit'] > 0): ?>
                                                <span class="text-success fw-bold">$<?php echo number_format($supplier['credit_limit'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin límite establecido</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Descuento</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <i class="fas fa-percentage me-2 text-muted"></i>
                                            <?php if ($supplier['discount_percentage'] > 0): ?>
                                                <span class="text-info fw-bold"><?php echo $supplier['discount_percentage']; ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin descuento</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted">Calificación</label>
                                        <div class="p-2 bg-light rounded-3 border">
                                            <?php 
                                            $ratings = [
                                                'excellent' => '<span class="badge bg-success rounded-pill"><i class="fas fa-star me-1"></i>Excelente</span>',
                                                'good' => '<span class="badge bg-primary rounded-pill"><i class="fas fa-thumbs-up me-1"></i>Bueno</span>',
                                                'regular' => '<span class="badge bg-warning rounded-pill"><i class="fas fa-minus me-1"></i>Regular</span>',
                                                'poor' => '<span class="badge bg-danger rounded-pill"><i class="fas fa-thumbs-down me-1"></i>Malo</span>'
                                            ];
                                            echo $supplier['rating'] ? ($ratings[$supplier['rating']] ?? $supplier['rating']) : '<span class="text-muted"><i class="fas fa-star-half-alt me-2 text-muted"></i>Sin calificar</span>';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($supplier['bank_name'] || $supplier['account_number']): ?>
                                <div class="row">
                                    <?php if ($supplier['bank_name']): ?>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold text-muted">Banco</label>
                                                <div class="p-2 bg-light rounded-3 border">
                                                    <i class="fas fa-university me-2 text-muted"></i>
                                                    <?php echo htmlspecialchars($supplier['bank_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($supplier['account_type']): ?>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold text-muted">Tipo de Cuenta</label>
                                                <div class="p-2 bg-light rounded-3 border">
                                                    <i class="fas fa-piggy-bank me-2 text-muted"></i>
                                                    <?php 
                                                    $account_types = [
                                                        'savings' => 'Ahorros',
                                                        'checking' => 'Corriente'
                                                    ];
                                                    echo $account_types[$supplier['account_type']] ?? $supplier['account_type'];
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($supplier['account_number']): ?>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold text-muted">Número de Cuenta</label>
                                                <div class="p-2 bg-light rounded-3 border">
                                                    <i class="fas fa-hashtag me-2 text-muted"></i>
                                                    <?php echo htmlspecialchars($supplier['account_number']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notas -->
                    <?php if ($supplier['notes']): ?>
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-sticky-note me-2 text-primary"></i>Notas
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="p-3 bg-light rounded-3 border">
                                    <?php echo nl2br(htmlspecialchars($supplier['notes'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-4">
                    <!-- Estadísticas -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-chart-bar me-2 text-primary"></i>Estadísticas
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h4 class="text-primary mb-1 fw-bold"><?php echo $stats['total_purchases']; ?></h4>
                                        <small class="text-muted fw-bold">Total Compras</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-warning mb-1 fw-bold">$<?php echo number_format($stats['pending_payments'], 2); ?></h4>
                                    <small class="text-muted fw-bold">Pagos Pendientes</small>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="row text-center">
                                <div class="col-12">
                                    <h5 class="text-info mb-1 fw-bold">$<?php echo number_format($stats['average_purchase'], 2); ?></h5>
                                    <small class="text-muted fw-bold">Promedio por Compra</small>
                                </div>
                            </div>
                            
                            <?php if ($stats['last_purchase_date']): ?>
                                <hr class="my-4">
                                <div class="text-center">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        Última compra: <?php echo date('d/m/Y', strtotime($stats['last_purchase_date'])); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Información del Sistema -->
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-server me-2 text-primary"></i>Información del Sistema
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">ID del Sistema</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-fingerprint me-2 text-muted"></i>
                                    <?php echo $supplier['id']; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">Fecha de Creación</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-calendar-plus me-2 text-muted"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($supplier['created_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="mb-0">
                                <label class="form-label fw-bold text-muted">Última Actualización</label>
                                <div class="p-2 bg-light rounded-3 border">
                                    <i class="fas fa-calendar-check me-2 text-muted"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($supplier['updated_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Acciones Rápidas -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="fas fa-bolt me-2 text-primary"></i>Acciones Rápidas
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="d-grid gap-2">
                                <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-warning rounded-pill">
                                    <i class="fas fa-edit me-2"></i>Editar Proveedor
                                </a>
                                
                                <button type="button" class="btn btn-info rounded-pill text-white" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Imprimir Ficha
                                </button>
                                
                                <?php if ($supplier['email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="btn btn-outline-primary rounded-pill">
                                        <i class="fas fa-envelope me-2"></i>Enviar Email
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($supplier['phone']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="btn btn-outline-success rounded-pill">
                                        <i class="fas fa-phone me-2"></i>Llamar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<style>
@media print {
    .top-header,
    .sidebar-modern,
    .sidebar-overlay,
    #sidebarToggle,
    .btn {
        display: none !important;
    }
    .card {
        border: 1px solid #000 !important;
        margin-bottom: 20px !important;
        box-shadow: none !important;
    }
}
</style>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
