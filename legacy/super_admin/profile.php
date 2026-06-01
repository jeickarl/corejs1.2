<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$message = '';
$type = 'info';

// Obtener ID del Super Admin actual
$adminId = $_SESSION['SAAS_SUPERADMIN_ID'] ?? null;

if ($adminId) {
    $stmt = $pdo->prepare("SELECT id, username, email FROM saas_super_admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Fallback para sesiones antiguas o si no está definido
    $stmt = $pdo->prepare("SELECT id, username, email FROM saas_super_admins WHERE username = ?");
    $stmt->execute(['master']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no encuentra 'master' (quizás se cambió el nombre), tomar el primer admin
    if (!$admin) {
        $stmt = $pdo->query("SELECT id, username, email FROM saas_super_admins LIMIT 1");
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$admin) {
    die("Error crítico: No se encontró ningún usuario Super Admin. Por favor contacta soporte.");
}

$adminId = $admin['id'];
// Actualizar sesión por si acaso
$_SESSION['SAAS_SUPERADMIN_ID'] = $adminId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Actualizar Perfil (Nombre/Email)
    if (isset($_POST['update_profile'])) {
        $newUsername = trim($_POST['username']);
        $newEmail = trim($_POST['email']);
        
        if (!empty($newUsername) && !empty($newEmail)) {
            // Validar unicidad (excepto si es el mismo)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM saas_super_admins WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$newUsername, $newEmail, $adminId]);
            if ($stmt->fetchColumn() > 0) {
                $message = 'El usuario o email ya están en uso.';
                $type = 'danger';
            } else {
                $upd = $pdo->prepare("UPDATE saas_super_admins SET username = ?, email = ? WHERE id = ?");
                $upd->execute([$newUsername, $newEmail, $adminId]);
                $message = 'Perfil actualizado correctamente.';
                $type = 'success';
                // Actualizar datos locales
                $admin['username'] = $newUsername;
                $admin['email'] = $newEmail;
            }
        } else {
            $message = 'Usuario y Email son requeridos.';
            $type = 'danger';
        }
    }

    // 2. Cambiar Contraseña
    if (isset($_POST['change_password'])) {
        $currentPass = $_POST['current_password'];
        $newPass = $_POST['new_password'];
        $confirmPass = $_POST['confirm_password'];

        // Verificar contraseña actual
        $stmt = $pdo->prepare("SELECT password FROM saas_super_admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $hash = $stmt->fetchColumn();

        if (password_verify($currentPass, $hash)) {
            if ($newPass === $confirmPass) {
                if (strlen($newPass) >= 8) {
                    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE saas_super_admins SET password = ? WHERE id = ?");
                    $upd->execute([$newHash, $adminId]);
                    $message = 'Contraseña actualizada con éxito.';
                    $type = 'success';
                } else {
                    $message = 'La nueva contraseña debe tener al menos 8 caracteres.';
                    $type = 'danger';
                }
            } else {
                $message = 'Las nuevas contraseñas no coinciden.';
                $type = 'danger';
            }
        } else {
            $message = 'La contraseña actual es incorrecta.';
            $type = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Super Admin</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">
    <?php $sa_active = 'profile'; include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = 'Mi Perfil'; include __DIR__ . '/header_common.php'; ?>
        
        <main class="container-fluid p-4">
            <div class="container" style="max-width: 900px;">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Datos del Perfil -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h5 class="mb-0"><i class="fas fa-user-edit me-2 text-primary"></i>Datos Personales</h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <div class="mb-3">
                                        <label class="form-label">Nombre de Usuario</label>
                                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Correo Electrónico</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                    </div>
                                    <button type="submit" name="update_profile" class="btn btn-primary w-100">
                                        Guardar Cambios
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Cambio de Contraseña -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h5 class="mb-0"><i class="fas fa-lock me-2 text-warning"></i>Cambiar Contraseña</h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <div class="mb-3">
                                        <label class="form-label">Contraseña Actual</label>
                                        <div class="input-group">
                                            <input type="password" name="current_password" id="current_password" class="form-control" required>
                                            <span class="input-group-text bg-white" style="cursor: pointer;" onclick="toggleProfilePassword('current_password', this)">
                                                <i class="fas fa-eye text-muted"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nueva Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" name="new_password" id="new_password" class="form-control" minlength="8" required>
                                            <span class="input-group-text bg-white" style="cursor: pointer;" onclick="toggleProfilePassword('new_password', this)">
                                                <i class="fas fa-eye text-muted"></i>
                                            </span>
                                        </div>
                                        <div class="form-text">Mínimo 8 caracteres.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Confirmar Nueva Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                            <span class="input-group-text bg-white" style="cursor: pointer;" onclick="toggleProfilePassword('confirm_password', this)">
                                                <i class="fas fa-eye text-muted"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-warning w-100 text-dark fw-bold">
                                        Actualizar Contraseña
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleProfilePassword(fieldId, element) {
        const input = document.getElementById(fieldId);
        const icon = element.querySelector('i');
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }
    </script>
</body>
</html>
