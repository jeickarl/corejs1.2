<?php
require_once 'auth.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$tenantId = $_GET['id'];
$tenant = [];
$users = [];
$isPerDatabaseMode = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

try {
    if ($isPerDatabaseMode) {
        require_once __DIR__ . '/../config/database_manager.php';
        $master = DatabaseManager::master();

        $stmt = $master->prepare("SELECT id, nombre, estado, created_at FROM empresas WHERE id = ? LIMIT 1");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $tenant = [
                'id' => (int)($row['id'] ?? 0),
                'company_name' => (string)($row['nombre'] ?? ''),
                'status' => ((string)($row['estado'] ?? '') === 'active') ? 'active' : 'suspended',
                'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ];

            $stmtUsers = $master->prepare("SELECT id, nombre AS name, email, rol AS role, activo AS active, created_at FROM usuarios_master WHERE empresa_id = ? ORDER BY nombre ASC");
            $stmtUsers->execute([$tenantId]);
            $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        } else {
            die("Empresa no encontrada.");
        }
    } else {
        // Get Tenant Info (single-db uses `tenants`)
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tenant) {
            // Fetch Users directly from main DB with tenant_id
            $stmtUsers = $pdo->prepare("SELECT id, name, email, role, active, created_at FROM users WHERE tenant_id = ? ORDER BY name ASC");
            $stmtUsers->execute([$tenantId]);
            $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        } else {
            die("Empresa no encontrada.");
        }
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
    <title>Usuarios - <?php echo htmlspecialchars($tenant['company_name']); ?> - CORE</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Modern UI -->
        <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">

    <?php $sa_active = 'dashboard'; include __DIR__ . '/sidebar_common.php'; ?>

    <div class="main-content">
        <?php $sa_title = 'Usuarios: ' . $tenant['company_name']; include __DIR__ . '/header_common.php'; ?>

        <div class="container-fluid p-4">
            
            <div class="mb-4">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Volver al Dashboard
                </a>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'password_reset'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Contraseña actualizada correctamente.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'user_deleted'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Usuario eliminado correctamente.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'error'): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> Error al realizar la acción.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'email_updated'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Correo actualizado correctamente.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-secondary">Lista de Usuarios</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th class="ps-4">Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">No hay usuarios registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td class="ps-4 fw-medium"><?php echo htmlspecialchars($u['name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                    <td>
                                        <?php if ($u['active']): ?>
                                            <span class="badge bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-primary me-1" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name']); ?>')" title="Cambiar Contraseña">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEmailModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name']); ?>', '<?php echo htmlspecialchars($u['email']); ?>')" title="Editar Correo">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                        <?php if ($u['id'] != 1): // Prevent deleting main admin ?>
                                        <form action="actions.php" method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="delete_tenant_user">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                            <input type="hidden" name="tenant_id" value="<?php echo (int)$tenantId; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Estás seguro de eliminar este usuario? Esta acción es irreversible.')" title="Eliminar Usuario">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form action="actions.php" method="POST">
                    <input type="hidden" name="action" value="reset_tenant_user_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <input type="hidden" name="user_id" id="resetUserId">
                    
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold">Resetear Contraseña</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Estás cambiando la contraseña para el usuario: <strong id="resetUserName"></strong></p>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="new_password" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="generatePassword()">Generar</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Email Modal -->
    <div class="modal fade" id="editEmailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form action="actions.php" method="POST">
                    <input type="hidden" name="action" value="update_tenant_user_email">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <input type="hidden" name="user_id" id="emailUserId">
                    
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold">Actualizar Correo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Usuario: <strong id="emailUserName"></strong></p>
                        <div class="mb-3">
                            <label class="form-label">Correo actual</label>
                            <input type="email" class="form-control" id="emailCurrent" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nuevo correo</label>
                            <input type="email" class="form-control" name="new_email" required>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openResetModal(userId, userName) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUserName').textContent = userName;
            new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
        }

        function generatePassword() {
            const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let pass = "";
            for (let i = 0; i < 12; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.querySelector('input[name="new_password"]').value = pass;
        }
        
        function openEmailModal(userId, userName, currentEmail) {
            document.getElementById('emailUserId').value = userId;
            document.getElementById('emailUserName').textContent = userName;
            document.getElementById('emailCurrent').value = currentEmail;
            new bootstrap.Modal(document.getElementById('editEmailModal')).show();
        }
    </script>
</body>
</html>
