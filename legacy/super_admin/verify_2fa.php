<?php
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
session_name('CORE_SA_SESSION');
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$appDir = realpath(__DIR__ . '/..');
$basePath = '/';
if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
    $rel = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    $rel = '/' . trim($rel, '/');
    $basePath = ($rel === '' || $rel === '/') ? '/' : $rel;
}
$saPath = rtrim($basePath, '/') . '/super_admin';
session_set_cookie_params([
    'path' => $saPath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
$pdo = db();

if (!isset($_SESSION['SAAS_SUPERADMIN_PENDING_ID'])) {
    header("Location: login.php");
    exit;
}

$adminId = (int)$_SESSION['SAAS_SUPERADMIN_PENDING_ID'];
$error = '';
$message = '';

// Obtener configuración del admin
$stmt = $pdo->prepare("SELECT email, twofa_secret, backup_pin, trusted_ips, mfa_preference FROM saas_super_admins WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$isLocal = (($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost') || (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') || (($_SERVER['REMOTE_ADDR'] ?? '') === '::1');
if ($isLocal && empty($admin['backup_pin'])) {
    $pinHash = password_hash('120212', PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE saas_super_admins SET backup_pin = ?, mfa_preference = 'pin' WHERE id = ?");
    $upd->execute([$pinHash, $adminId]);
    $admin['backup_pin'] = $pinHash;
    $admin['mfa_preference'] = 'pin';
}

// Determinar método activo (por defecto el preferido, o el que el usuario elija en la UI)
$method = $_GET['method'] ?? $admin['mfa_preference'] ?? 'totp';

// Verificar si IP es de confianza (Whitelist)


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $code = trim($_POST['code'] ?? '');
if ($method === 'email' && !isset($_SESSION['email_otp_sent'])) {
    $otp = rand(100000, 999999);
    $_SESSION['email_otp_code'] = (string)$otp;
    $_SESSION['email_otp_expires'] = time() + 300; // 5 minutos
    
    // Enviar correo
    $subject = "Tu código de verificación - CORE Super Admin";
    $body = "<h1>Código: $otp</h1><p>Este código expira en 5 minutos.</p>";
    
    // Enviar correo normalmente
    $sent = sendSystemEmail($admin['email'], $subject, $body);

    if ($sent) {
        $_SESSION['email_otp_sent'] = true;
        $message = "Se ha enviado un código a " . substr($admin['email'], 0, 3) . "***@" . explode('@', $admin['email'])[1];
        // Forzar guardado de sesión antes de cualquier redirección o salida
        session_write_close();
    } else {
        $error = "Error enviando correo. Revisa la configuración SMTP.";
        if (($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost') {
            $message = "Modo local: usa este código directamente: " . $otp;
            $_SESSION['email_otp_sent'] = true;
        }
        // Fallback a TOTP si falla el email
        // $method = 'totp'; // Comentado para que el usuario pueda ver el error en la pantalla de Email
    }
}

    // Configurar PIN en modo local si no existe
    if ($method === 'pin' && empty($admin['backup_pin']) && isset($_POST['setup_pin'])) {
        $newPin = trim($_POST['new_pin'] ?? '');
        $isLocal = (($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost') || (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') || (($_SERVER['REMOTE_ADDR'] ?? '') === '::1');
        if ($isLocal && $newPin !== '' && ctype_digit($newPin) && strlen($newPin) >= 4 && strlen($newPin) <= 8) {
            $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE saas_super_admins SET backup_pin = ?, mfa_preference = 'pin' WHERE id = ?");
            $upd->execute([$pinHash, $adminId]);
            $admin['backup_pin'] = $pinHash;
            $message = "PIN configurado. Ingresa tu PIN para verificar.";
        } else {
            $error = "PIN inválido (4-8 dígitos). Solo permitido en modo local.";
        }
    }

    if ($code !== '') {
        $valid = false;
        
        if ($method === 'totp') {
        if (!empty($admin['twofa_secret']) && verifyTOTP($code, $admin['twofa_secret'])) {
            $valid = true;
        } else {
            $error = "Código Authenticator inválido.";
        }
    } elseif ($method === 'email') {
        if (isset($_SESSION['email_otp_code']) && 
            time() < $_SESSION['email_otp_expires'] && 
            $code === $_SESSION['email_otp_code']) {
            $valid = true;
            unset($_SESSION['email_otp_code']);
            unset($_SESSION['email_otp_sent']);
        } else {
            $error = "Código de email inválido o expirado.";
        }
    } elseif ($method === 'pin') {
        if (!empty($admin['backup_pin']) && password_verify($code, $admin['backup_pin'])) {
            $valid = true;
        } else {
            $error = "PIN de seguridad incorrecto.";
        }
    }

        if ($valid) {
            unset($_SESSION['SAAS_SUPERADMIN_PENDING_ID']);
            $_SESSION['SESSION_SAAS_SUPERADMIN'] = true;
            $_SESSION['SAAS_SUPERADMIN_ID'] = $adminId; // Guardar ID en sesión
            header("Location: index.php");
            exit;
        }
    }
}

// Re-enviar email
if (isset($_GET['resend']) && $method === 'email') {
    // Asegurar que la sesión está activa antes de borrar
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['email_otp_sent']);
    unset($_SESSION['email_otp_code']); // Limpiar código anterior también
    session_write_close(); // Guardar cambios inmediatamente
    
    // Usar JavaScript para redirigir si los headers ya se enviaron
    if (!headers_sent()) {
        header("Location: verify_2fa.php?method=email");
    } else {
        echo '<script>window.location.href="verify_2fa.php?method=email";</script>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Seguridad</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:30px; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,0.15); width:400px; }
        .error { background:#ffe0e0; color:#c0392b; padding:10px; border-radius:6px; font-size:13px; margin-bottom:12px; }
        .success { background:#e0ffe4; color:#2ecc71; padding:10px; border-radius:6px; font-size:13px; margin-bottom:12px; }
        .input { width:100%; padding:12px; border:1px solid #ddd; border-radius:8px; font-size:16px; text-align:center; letter-spacing:6px; }
        .btn-dark { width:100%; padding:12px; border-radius:8px; }
        .method-link { text-decoration: none; font-size: 13px; color: #666; margin: 0 5px; }
        .method-link:hover { color: #000; text-decoration: underline; }
        .method-link.active { font-weight: bold; color: #000; }
    </style>
    </head>
<body>
    <div class="box">
        <div class="text-center mb-3">
            <img src="../assets/img/system_logo.png" alt="Logo" style="width:60px">
            
            <?php if ($method === 'totp'): ?>
                <h5 class="mt-2">Google Authenticator</h5>
                <p class="text-muted mb-0">Ingresa el código de tu app</p>
            <?php elseif ($method === 'email'): ?>
                <h5 class="mt-2">Código por Email</h5>
                <p class="text-muted mb-0">Revisa tu bandeja de entrada</p>
            <?php elseif ($method === 'pin'): ?>
                <h5 class="mt-2">Clave Maestra</h5>
                <p class="text-muted mb-0">Ingresa tu clave de 4-8 dígitos</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="error mb-3">
                <?php echo $error; ?>
                <div class="mt-2 pt-2 border-top border-danger-subtle">
                    <small class="d-block fw-bold text-dark mb-1">¿Problemas?</small>
                    <div class="d-flex justify-content-center gap-2">
                        <?php if ($method !== 'email'): ?>
                            <a href="?method=email" class="btn btn-sm btn-outline-danger">Usar Email</a>
                        <?php endif; ?>
                <?php if ($method !== 'pin' && (!empty($admin['backup_pin']) || $isLocal)): ?>
                            <a href="?method=pin" class="btn btn-sm btn-outline-danger">Usar Clave Maestra</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="post">
            <input class="input mb-3" type="text" name="code" 
                   maxlength="<?php echo $method === 'pin' ? '8' : '6'; ?>" 
                   autocomplete="off" inputmode="numeric" 
                   placeholder="<?php echo $method === 'pin' ? 'PIN Secreto' : '••••••'; ?>" required autofocus>
            
            <button class="btn btn-dark mb-3" type="submit">Verificar</button>

            <div class="d-flex justify-content-center gap-2 mb-3">
                <div class="badge bg-light text-dark px-3 py-2">Hora del servidor: <span id="serverClock">--:--:--</span></div>
                <?php if ($method === 'totp'): ?>
                <div class="badge bg-dark px-3 py-2">Cambio en: <span id="totpCountdown">--</span>s</div>
                <?php endif; ?>
            </div>


            <?php if ($method === 'email'): ?>
                <div class="text-center mb-3">
                    <a href="?method=email&resend=1" class="small text-muted">¿No llegó? Reenviar código</a>
                </div>
            <?php endif; ?>
            <?php if ($method === 'pin' && empty($admin['backup_pin']) && $isLocal): ?>
                <div class="mt-3 p-2 border rounded">
                    <div class="small text-muted mb-2">Configurar Clave Maestra (solo local):</div>
                    <div class="input-group">
                        <input type="password" name="new_pin" class="form-control" placeholder="Nuevo PIN (4-8 dígitos)" maxlength="8" inputmode="numeric">
                        <button class="btn btn-outline-secondary" type="submit" name="setup_pin" value="1">Guardar PIN</button>
                    </div>
                </div>
            <?php endif; ?>
        </form>


        <hr>
        <div class="text-center">
            <p class="small text-muted mb-2">Probar otro método:</p>
            <div class="d-flex justify-content-center">
                <?php if (!empty($admin['twofa_secret'])): ?>
                    <a href="?method=totp" class="method-link <?php echo $method==='totp'?'active':''; ?>"><i class="fas fa-mobile-alt"></i> App</a>
                <?php endif; ?>
                
                <a href="?method=email" class="method-link <?php echo $method==='email'?'active':''; ?>"><i class="fas fa-envelope"></i> Email</a>
                
                <?php if (!empty($admin['backup_pin']) || $isLocal): ?>
                    <a href="?method=pin" class="method-link <?php echo $method==='pin'?'active':''; ?>"><i class="fas fa-th"></i> PIN</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function(){
            var serverMs = <?php echo (int)(time()*1000); ?>;
            var offset = serverMs - Date.now();
            var clockEl = document.getElementById('serverClock');
            var cdEl = document.getElementById('totpCountdown');
            function pad(n){ return n<10 ? '0'+n : ''+n; }
            function tick(){
                var nowMs = Date.now() + offset;
                var d = new Date(nowMs);
                var h = pad(d.getHours()), m = pad(d.getMinutes()), s = pad(d.getSeconds());
                if (clockEl) clockEl.textContent = h+':'+m+':'+s;
                if (cdEl){
                    var sec = Math.floor(nowMs/1000);
                    var left = 30 - (sec % 30);
                    if (left === 30) left = 0;
                    cdEl.textContent = left;
                }
            }
            tick();
            setInterval(tick, 250);
        })();
    </script>
</body>
</html>
