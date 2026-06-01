<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

requireAuth();

// Verificar permisos de administrador
if (!isAdminSession()) {
    header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren permisos de administrador.'));
    exit();
}

$errors = [];
$cliente = null;
$ordenes_count = 0;
$cliente_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que se proporcionó un ID válido
if ($cliente_id <= 0) {
    header('Location: index.php?error=' . urlencode('ID de cliente no válido.'));
    exit();
}

// Obtener datos del cliente
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$cliente_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$cliente_id, $tenant_id]);
    }
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        header('Location: index.php?error=' . urlencode('Cliente no encontrado.'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar el cliente.'));
    exit();
}

// Verificar si el cliente tiene órdenes asociadas
try {
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM work_orders WHERE client_id = ?");
        $stmt->execute([$cliente_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM work_orders WHERE client_id = ? AND tenant_id = ?");
        $stmt->execute([$cliente_id, $tenant_id]);
    }
    $ordenes_count = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $ordenes_count = 0;
}

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Procesar eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Error de seguridad (CSRF). Por favor, vuelva a cargar la página e intente nuevamente.';
    } else {
        $password_confirmacion = $_POST['password_confirmacion'] ?? '';
        $confirmar_eliminacion = isset($_POST['confirmar_eliminacion']);
    
    // Validaciones
    if (!$confirmar_eliminacion) {
        $errors[] = 'Debes confirmar que deseas eliminar el cliente.';
    }
    
    if (empty($password_confirmacion)) {
        $errors[] = 'Debes ingresar tu contraseña para confirmar la eliminación.';
    } else {
        // Verificar la contraseña del usuario actual
        try {
            $hasTenantCol = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
                $hasTenantCol = ($c && $c->rowCount() > 0);
            } catch (Throwable $e) {}
            if ($hasTenantCol && !$perDatabase) {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$_SESSION['user_id'], $tenant_id]);
            } else {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            }
            $usuario = $stmt->fetch();
            
            if (!$usuario || !password_verify($password_confirmacion, $usuario['password'])) {
                $errors[] = 'La contraseña ingresada es incorrecta.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar la contraseña.';
        }
    }
    
    // Verificar nuevamente si hay órdenes (por seguridad)
    if ($ordenes_count > 0) {
        $errors[] = 'No se puede eliminar el cliente porque tiene órdenes asociadas.';
    }
    
    // Si no hay errores, eliminar el cliente
    if (empty($errors)) {
        try {
            if ($perDatabase) {
                $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                $stmt->execute([$cliente_id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$cliente_id, $tenant_id]);
            }
            
            header('Location: index.php?success=' . urlencode('Cliente eliminado exitosamente.'));
            exit();
        } catch (PDOException $e) {
            $errors[] = 'Error al eliminar el cliente: ' . $e->getMessage();
        }
    }
    } // Cierra verificacion CSRF
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Cliente - <?php echo htmlspecialchars($cliente['first_name']); ?></title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Header de la página -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-user-times me-2 text-danger"></i>Eliminar Cliente</h2>
                    <p class="text-muted mb-0">
                        Eliminando: <strong><?php echo htmlspecialchars($cliente['first_name']); ?></strong>
                    </p>
                </div>
                <div class="btn-group">
                    <a href="view.php?id=<?php echo $cliente_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-eye me-2"></i>Ver Cliente
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Volver a la Lista
                    </a>
                </div>
            </div>

            <!-- Mensajes de error -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Se encontraron los siguientes errores:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <!-- Información del Cliente -->
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Información del Cliente a Eliminar</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <?php $clientNumber = $cliente['client_number'] ?? null; ?>
                                    <p><strong>ID:</strong> <?php echo str_pad($clientNumber ?: $cliente['id'], 2, '0', STR_PAD_LEFT); ?></p>
                                    <p><strong>Nombre:</strong> <?php echo htmlspecialchars($cliente['first_name']); ?></p>
                                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($cliente['phone']); ?></p>
                                    <?php if ($cliente['email']): ?>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($cliente['email']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <?php if ($cliente['id_number']): ?>
                                    <p><strong>Cédula:</strong> <?php echo htmlspecialchars($cliente['id_number']); ?></p>
                                    <?php endif; ?>
                                    <p><strong>Registrado:</strong> <?php echo date('d/m/Y', strtotime($cliente['created_at'])); ?></p>
                                    <?php if ($cliente['updated_at']): ?>
                                    <p><strong>Última actualización:</strong> <?php echo date('d/m/Y', strtotime($cliente['updated_at'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($cliente['address']): ?>
                            <p><strong>Dirección:</strong> <?php echo nl2br(htmlspecialchars($cliente['address'])); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($cliente['notes']): ?>
                            <p><strong>Notas:</strong> <?php echo nl2br(htmlspecialchars($cliente['notes'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Verificación de Órdenes -->
                    <?php if ($ordenes_count > 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>¡Atención!</strong> Este cliente tiene <strong><?php echo $ordenes_count; ?></strong> orden(es) asociada(s).
                        No se puede eliminar un cliente que tiene órdenes registradas.
                        <br><br>
                        <a href="view.php?id=<?php echo $cliente_id; ?>" class="btn btn-sm btn-warning">
                            <i class="fas fa-eye me-2"></i>Ver Órdenes del Cliente
                        </a>
                    </div>
                    <?php else: ?>
                    <!-- Formulario de Confirmación -->
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Confirmación de Eliminación</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>¡ADVERTENCIA!</strong> Esta acción no se puede deshacer.
                                Al eliminar este cliente se perderá toda su información de forma permanente.
                            </div>
                            
                            <form method="POST" id="deleteForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="mb-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="confirmar_eliminacion" name="confirmar_eliminacion" required>
                                        <label class="form-check-label" for="confirmar_eliminacion">
                                            <strong>Confirmo que deseo eliminar permanentemente este cliente y toda su información.</strong>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="password_confirmacion" class="form-label">
                                        <strong>Confirma tu contraseña para proceder:</strong>
                                    </label>
                                    <input type="password" class="form-control" id="password_confirmacion" name="password_confirmacion" 
                                           required placeholder="Ingresa tu contraseña actual">
                                    <div class="form-text">Por seguridad, debes confirmar tu identidad antes de eliminar el cliente.</div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <a href="view.php?id=<?php echo $cliente_id; ?>" class="btn btn-outline-secondary me-2">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </a>
                                        <a href="edit.php?id=<?php echo $cliente_id; ?>" class="btn btn-outline-primary">
                                            <i class="fas fa-edit me-2"></i>Editar en su lugar
                                        </a>
                                    </div>
                                    <button type="submit" class="btn btn-danger" id="deleteButton" disabled>
                                        <i class="fas fa-trash me-2"></i>Eliminar Cliente Permanentemente
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('deleteForm');
            const checkbox = document.getElementById('confirmar_eliminacion');
            const passwordInput = document.getElementById('password_confirmacion');
            const deleteButton = document.getElementById('deleteButton');
            
            if (form) {
                function updateDeleteButton() {
                    const isChecked = checkbox.checked;
                    const hasPassword = passwordInput.value.trim().length > 0;
                    deleteButton.disabled = !(isChecked && hasPassword);
                }
                
                checkbox.addEventListener('change', updateDeleteButton);
                passwordInput.addEventListener('input', updateDeleteButton);
                
                // Confirmación adicional antes del envío
                form.addEventListener('submit', function(e) {
                    const clienteName = '<?php echo addslashes($cliente['client_type'] === 'company' ? $cliente['company_name'] : $cliente['first_name']); ?>';
                    const confirmMessage = `¿Estás completamente seguro de que deseas eliminar al cliente "${clienteName}"?\n\nEsta acción NO se puede deshacer.`;
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return false;
                    }
                    
                    // Deshabilitar el botón para evitar envíos múltiples
                    deleteButton.disabled = true;
                    deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Eliminando...';
                });
            }
        });
    </script>
</body>
</html>
