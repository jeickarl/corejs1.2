<?php
require_once '../config/database.php';
require_once '../config/functions.php';
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    require_once '../config/database_manager.php';
    $pdo = DatabaseManager::master();
}

// --- Lazy Init Table ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        tenant_id INT NOT NULL DEFAULT 1,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email),
        INDEX idx_tenant_id (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
catch (Exception $e) {
}

$ensureLookup = false;
try {
    $pdo->query("SELECT 1 FROM saas_users_lookup LIMIT 1");
    $ensureLookup = true;
}
catch (Exception $e) {
}
if (!$ensureLookup) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_users_lookup (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            tenant_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tenant_id (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
    }
    catch (Exception $e) {
    }
}

$hasTenantCol = false;
try {
    $q = $pdo->query("SHOW COLUMNS FROM saas_password_resets LIKE 'tenant_id'");
    if ($q && $q->rowCount() > 0) {
        $hasTenantCol = true;
    }
}
catch (Exception $e) {
}
if (!$hasTenantCol) {
    try {
        $pdo->exec("ALTER TABLE saas_password_resets ADD COLUMN tenant_id INT NOT NULL DEFAULT 1");
        $pdo->exec("CREATE INDEX idx_tenant_id ON saas_password_resets(tenant_id)");
    }
    catch (Exception $e) {
    }
}

$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $emailNorm = strtolower($email);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $found = false;
        $tenantId = null;
        $isActive = 1;
        try {
            if ($perDatabase) {
                $stmt = $pdo->prepare("SELECT empresa_id, activo FROM usuarios_master WHERE email = ? LIMIT 1");
                $stmt->execute([$emailNorm]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $tenantId = (int)($row['empresa_id'] ?? 0);
                    $isActive = (int)($row['activo'] ?? 0);
                    $found = $tenantId > 0;
                }
            } else {
                $stmt = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
                $stmt->execute([$emailNorm]);
                $tenantId = $stmt->fetchColumn();
                if ($tenantId) {
                    $chk = $pdo->prepare("SELECT id, active FROM users WHERE email = ? AND tenant_id = ? LIMIT 1");
                    $chk->execute([$emailNorm, $tenantId]);
                    $row = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $found = true;
                        $isActive = (int)$row['active'];
                    }
                }
                else {
                    // Fallback: buscar directamente en users si no existe en lookup
                    $usr = $pdo->prepare("SELECT tenant_id, active FROM users WHERE email = ? LIMIT 1");
                    $usr->execute([$emailNorm]);
                    $urow = $usr->fetch(PDO::FETCH_ASSOC);
                    if ($urow) {
                        $tenantId = (int)$urow['tenant_id'];
                        $isActive = (int)$urow['active'];
                        $found = true;
                        // Guardar relación en lookup para futuras consultas
                        try {
                            $ins = $pdo->prepare("INSERT IGNORE INTO saas_users_lookup (email, tenant_id) VALUES (?, ?)");
                            $ins->execute([$emailNorm, $tenantId]);
                        }
                        catch (Exception $e) {
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $found = false;
        }

        if ($found && $isActive === 1) {
            try {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Delete old tokens
                $stmt = $pdo->prepare("DELETE FROM saas_password_resets WHERE email = ? AND tenant_id = ?");
                $stmt->execute([$emailNorm, $tenantId]);

                // Insert new token
                $stmt = $pdo->prepare("INSERT INTO saas_password_resets (email, tenant_id, token, expires_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$emailNorm, $tenantId, $token, $expires]);

                // Prepare Email
                $resetLink = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/core/login/reset_password.php?token=$token";
                $subject = "Recuperación de contraseña - CORE";

                // Simple HTML Body
                $body = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Recuperación de Contraseña</h2>
                    </div>
                    <p style="color: #555;">Hola,</p>
                    <p style="color: #555;">Hemos recibido una solicitud para restablecer tu contraseña en CORE.</p>
                    <p style="color: #555;">Para continuar, haz clic en el siguiente botón:</p>
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . $resetLink . '" style="background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">Restablecer Contraseña</a>
                    </div>
                    <p style="color: #777; font-size: 12px;">Si no solicitaste este cambio, puedes ignorar este correo. El enlace expirará en 1 hora.</p>
                    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                    <p style="color: #999; font-size: 12px; text-align: center;">CORE System</p>
                </div>';

                $smtpHost = cfg_get('smtp_host', '');
                $smtpUser = cfg_get('smtp_user', '');
                $sent = false;
                if ($smtpHost !== '' && $smtpUser !== '') {
                    $sent = sendSystemEmail($email, $subject, $body, true);
                } else {
                    $sent = sendSystemEmail($email, $subject, $body, true);
                }
                if ($sent) {
                    $message = "Si tu correo está registrado, te enviaremos un enlace de recuperación.";
                    $type = "success";
                } else {
                    $message = "Correo no configurado. Copia este enlace para restablecer: " . $resetLink;
                    $type = "error";
                }
            }
            catch (Exception $e) {
                $message = "Ocurrió un error inesperado.";
                $type = "error";
            }
        }
        elseif ($found && $isActive !== 1) {
            $message = "La cuenta está inactiva.";
            $type = "error";
        }
        else {
            $message = "El correo no está registrado.";
            $type = "error";
        }
    }
    else {
        $message = "Por favor ingresa un correo válido.";
        $type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/modern-ui-enhancements.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light login-page-bg">
    <div class="container d-flex align-items-center justify-content-center min-vh-100">
        <div class="card shadow-lg border-0 rounded-4 w-100" style="max-width: 400px;">
            <div class="card-body p-4 p-md-5 text-center">
                <!-- Logo -->
                <div class="d-flex align-items-center justify-content-center mb-4">
                    <img src="../assets/img/system_logo.png" alt="CORE Logo" height="50" class="me-2">
                    <h1 class="fw-bold mb-0 text-dark" style="font-size: 2rem; letter-spacing: -1px;">CORE</h1>
                </div>

                <h4 class="mb-4 text-secondary fw-semibold">Recuperar Contraseña</h4>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $type === 'success' ? 'success' : 'danger'; ?> p-2 small mb-4" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php
endif; ?>

                <form method="POST">
                    <div class="input-group mb-4 login-input-group rounded-3 overflow-hidden border">
                        <span class="input-group-text bg-white border-0 text-muted"><i class="fa fa-envelope"></i></span>
                        <input type="email" class="form-control border-0 px-2 py-3" name="email" placeholder="Correo electrónico" required>
                    </div>

                    <button type="submit" class="no-theme btn-dark w-100 py-2 fs-6 fw-bold rounded-3 mb-3 hover-shadow-lg transition-all">Enviar Enlace</button>
                    
                    <div>
                        <a href="index.php" class="text-decoration-none small text-muted hover-primary">Volver al inicio de sesión</a>
                    </div>
                </form>
            </div>
            
            <div class="card-footer bg-white border-0 text-center py-3">
                <p class="small text-muted mb-0">© <?php echo date("Y"); ?> CORE. Todos los derechos reservados.</p>
            </div>
        </div>
    </div>
</body>
</html>
