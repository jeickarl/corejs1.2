<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$message = '';
$type = 'info';

// Obtener ID del Super Admin actual
$adminId = $_SESSION['SAAS_SUPERADMIN_ID'] ?? null;

if (!$adminId) {
    // Fallback: intentar obtener por username 'master'
    $stmt = $pdo->prepare("SELECT id FROM saas_super_admins WHERE username = ?");
    $stmt->execute(['master']);
    $adminId = $stmt->fetchColumn();
    
    // Fallback final: primer admin
    if (!$adminId) {
        $adminId = $pdo->query("SELECT id FROM saas_super_admins LIMIT 1")->fetchColumn();
    }
}

if (!$adminId) {
    die("Error crítico: No se encontró usuario admin.");
}

// Guardar en sesión para la próxima
$_SESSION['SAAS_SUPERADMIN_ID'] = $adminId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Guardar Preferencia MFA
    if (isset($_POST['mfa_preference'])) {
        $pref = $_POST['mfa_preference'];
        if (in_array($pref, ['totp', 'email', 'pin'])) {
            $upd = $pdo->prepare("UPDATE saas_super_admins SET mfa_preference = ? WHERE id = ?");
            $upd->execute([$pref, $adminId]);
            $message = 'Preferencia de seguridad actualizada.';
            $type = 'success';
        }
    }

    // 2. Configurar PIN de Respaldo
    if (isset($_POST['setup_pin']) && !empty($_POST['new_pin'])) {
        $pin = trim($_POST['new_pin']);
        if (strlen($pin) >= 4 && strlen($pin) <= 8 && ctype_digit($pin)) {
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE saas_super_admins SET backup_pin = ? WHERE id = ?");
            $upd->execute([$pinHash, $adminId]);
            $message = 'PIN de respaldo configurado correctamente.';
            $type = 'success';
        } else {
            $message = 'El PIN debe tener entre 4 y 8 dígitos numéricos.';
            $type = 'danger';
        }
    }

    // 3. Configurar IP Whitelist (IP de Confianza)
    if (isset($_POST['add_current_ip'])) {
        $currentIp = $_SERVER['REMOTE_ADDR'];
        // Obtener IPs actuales
        $stmt = $pdo->prepare("SELECT trusted_ips FROM saas_super_admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $existing = $stmt->fetchColumn();
        $ips = $existing ? explode(',', $existing) : [];
        
        if (!in_array($currentIp, $ips)) {
            $ips[] = $currentIp;
            $newIps = implode(',', $ips);
            $upd = $pdo->prepare("UPDATE saas_super_admins SET trusted_ips = ? WHERE id = ?");
            $upd->execute([$newIps, $adminId]);
            $message = "IP actual ($currentIp) agregada a la lista de confianza.";
            $type = 'success';
        }
    }

    if (isset($_POST['clear_ips'])) {
        $upd = $pdo->prepare("UPDATE saas_super_admins SET trusted_ips = NULL WHERE id = ?");
        $upd->execute([$adminId]);
        $message = 'Lista de IPs de confianza borrada.';
        $type = 'warning';
    }

    // 4. Resetear 2FA (forzar nueva configuración)
    if (isset($_POST['reset_2fa'])) {
        $upd = $pdo->prepare("UPDATE saas_super_admins SET twofa_secret = NULL WHERE id = ?");
        $upd->execute([$adminId]);
        $message = '2FA reiniciado. Al próximo inicio de sesión se pedirá configurar nuevamente.';
        $type = 'success';
    }
}

// Cargar datos actuales
$stmt = $pdo->prepare("SELECT mfa_preference, backup_pin, trusted_ips FROM saas_super_admins WHERE id = ?");
$stmt->execute([$adminId]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$hasPin = !empty($config['backup_pin']);
$trustedIps = !empty($config['trusted_ips']) ? explode(',', $config['trusted_ips']) : [];
$currentIp = $_SERVER['REMOTE_ADDR'];
$isCurrentIpTrusted = in_array($currentIp, $trustedIps);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seguridad Avanzada</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">
    <?php $sa_active = 'security'; include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = 'Seguridad y Accesos'; include __DIR__ . '/header_common.php'; ?>
        
        <main class="container-fluid p-4">
            <div class="container" style="max-width: 800px;">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- 1. Preferencia de Login -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-shield-alt me-2 text-primary"></i>Método de Verificación Principal</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Elige qué método te pedirá el sistema por defecto al iniciar sesión.</p>
                        <form method="post" class="row align-items-center">
                            <div class="col-md-8">
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="mfa_preference" id="mfa_totp" value="totp" <?php echo $config['mfa_preference'] === 'totp' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary" for="mfa_totp"><i class="fas fa-mobile-alt me-1"></i> App Auth</label>

                                    <input type="radio" class="btn-check" name="mfa_preference" id="mfa_email" value="email" <?php echo $config['mfa_preference'] === 'email' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary" for="mfa_email"><i class="fas fa-envelope me-1"></i> Email</label>

                                    <input type="radio" class="btn-check" name="mfa_preference" id="mfa_pin" value="pin" <?php echo $config['mfa_preference'] === 'pin' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary" for="mfa_pin"><i class="fas fa-th me-1"></i> PIN</label>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <button type="submit" class="btn btn-dark">Guardar Preferencia</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 2. PIN de Respaldo -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-key me-2 text-warning"></i>PIN de Respaldo</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">Estado: 
                                    <?php if ($hasPin): ?>
                                        <span class="badge bg-success">Configurado</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">No Configurado</span>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted">Un código numérico fijo (4-8 dígitos) para emergencias.</small>
                            </div>
                        </div>
                        <form method="post" class="input-group">
                            <input type="password" name="new_pin" class="form-control" placeholder="Nuevo PIN (ej. 123456)" pattern="\d{4,8}" title="4 a 8 números">
                            <button type="submit" name="setup_pin" value="1" class="btn btn-outline-primary">Actualizar PIN</button>
                        </form>
                    </div>
                </div>

                <!-- 3. IP Whitelist -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-network-wired me-2 text-info"></i>IPs de Confianza (Whitelist)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Si agregas tu IP, el sistema podría omitir el 2FA cuando te conectes desde ella (opcional, depende de configuración).</p>
                        
                        <div class="alert alert-light border d-flex justify-content-between align-items-center">
                            <span>Tu IP Actual: <strong><?php echo $currentIp; ?></strong></span>
                            <?php if ($isCurrentIpTrusted): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i> De Confianza</span>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <button type="submit" name="add_current_ip" class="btn btn-sm btn-success">Confiar en esta IP</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($trustedIps)): ?>
                            <h6 class="mt-3">IPs Autorizadas:</h6>
                            <ul class="list-group mb-3">
                                <?php foreach ($trustedIps as $ip): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars($ip); ?>
                                        <?php if ($ip === $currentIp): ?>
                                            <span class="badge bg-primary rounded-pill">Actual</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post">
                                <button type="submit" name="clear_ips" class="btn btn-outline-danger btn-sm" onclick="return confirm('¿Borrar todas las IPs de confianza?')">Limpiar Lista</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. Resetear 2FA -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-sync-alt me-2 text-danger"></i>Reiniciar 2FA</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Si tu app 2FA quedó desincronizada o no acepta el secreto actual, puedes reiniciar 2FA para volver a configurarlo en el próximo inicio de sesión.</p>
                        <form method="post" onsubmit="return confirm('¿Seguro que quieres reiniciar 2FA? Se pedirá nueva configuración en el próximo login.')">
                            <button type="submit" name="reset_2fa" value="1" class="btn btn-outline-danger">Reiniciar 2FA</button>
                        </form>
                    </div>
                </div>

            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

