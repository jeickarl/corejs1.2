<?php
require_once '../config/database.php';
require_once '../config/functions.php';
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    require_once '../config/database_manager.php';
    $pdo = DatabaseManager::master();
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$email = '';
$tenantId = null;

if (!$token) {
    die("Token inválido.");
}

// Verify Token
    try {
        $stmt = $pdo->prepare("SELECT email, tenant_id FROM saas_password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $email = $row['email']; $tenantId = (int)$row['tenant_id']; }

        if (!$email) {
            $error = "El enlace ha expirado o no es válido.";
        }
    } catch (Exception $e) {
        $error = "Error de sistema.";
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $email) {
    $pass1 = $_POST['password'];
    $pass2 = $_POST['confirm_password'];
    
    if ($pass1 !== $pass2) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($pass1) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        try {
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $updated = false;

            if ($perDatabase) {
                $stmt = $pdo->prepare("UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE email = ? AND empresa_id = ?");
                $stmt->execute([$hash, $email, $tenantId]);
                if ($stmt->rowCount() > 0) $updated = true;
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ? AND tenant_id = ?");
                $stmt->execute([$hash, $email, $tenantId]);
                if ($stmt->rowCount() > 0) $updated = true;
            }
            
            // Eliminar token usado
            $stmt = $pdo->prepare("DELETE FROM saas_password_resets WHERE email = ? AND tenant_id = ?");
            $stmt->execute([$email, $tenantId]);
            
            $success = "Contraseña actualizada correctamente.";
        } catch (Exception $e) {
            $error = "Error al actualizar: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Contraseña - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .msg { padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; }
        .msg.success { background: #d1e7dd; color: #0f5132; }
        .msg.error { background: #f8d7da; color: #842029; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="../assets/img/system_logo.png" alt="CORE Logo" class="logo">
            <h1>CORE</h1>
        </div>

        <h2>Nueva Contraseña</h2>

        <?php if ($error): ?>
            <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="msg success"><?php echo $success; ?></div>
            <div class="forgot">
                <a href="index.php" class="btn-link">Iniciar Sesión</a>
            </div>
        <?php elseif ($email): ?>
            <form method="POST">
                <div class="input-group">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password" placeholder="Nueva contraseña" required minlength="6">
                </div>
                <div class="input-group">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="confirm_password" placeholder="Confirmar contraseña" required minlength="6">
                </div>

                <button type="submit">Guardar Cambios</button>
            </form>
        <?php endif; ?>

        <div class="footer-login">
            <p>© <?php echo date("Y"); ?> CORE. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>
