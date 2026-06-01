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

if (!isset($_SESSION['SAAS_SUPERADMIN_SETUP_ID'])) {
    header("Location: login.php");
    exit;
}

$error = '';
$secret = '';

// Check if secret already in session to persist across reloads
if (isset($_SESSION['SAAS_SUPERADMIN_NEW_SECRET'])) {
    $secret = $_SESSION['SAAS_SUPERADMIN_NEW_SECRET'];
} else {
    $secret = generateBase32Secret(32);
    $_SESSION['SAAS_SUPERADMIN_NEW_SECRET'] = $secret;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regen_secret'])) {
    $secret = generateBase32Secret(32);
    $_SESSION['SAAS_SUPERADMIN_NEW_SECRET'] = $secret;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['regen_secret'])) {
    $method = $_POST['method'] ?? 'totp';
    if ($method === 'email') {
        $id = (int)$_SESSION['SAAS_SUPERADMIN_SETUP_ID'];
        $stmt = $pdo->prepare("UPDATE saas_super_admins SET twofa_secret = NULL, mfa_preference = 'email' WHERE id = ?");
        $stmt->execute([$id]);
        unset($_SESSION['SAAS_SUPERADMIN_SETUP_ID']);
        unset($_SESSION['SAAS_SUPERADMIN_NEW_SECRET']);
        $_SESSION['SESSION_SAAS_SUPERADMIN'] = true;
        header("Location: index.php");
        exit;
    } elseif ($method === 'pin') {
        $newPin = trim($_POST['new_pin'] ?? '');
        if ($newPin !== '' && ctype_digit($newPin) && strlen($newPin) >= 4 && strlen($newPin) <= 8) {
            $id = (int)$_SESSION['SAAS_SUPERADMIN_SETUP_ID'];
            $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE saas_super_admins SET backup_pin = ?, twofa_secret = NULL, mfa_preference = 'pin' WHERE id = ?");
            $stmt->execute([$pinHash, $id]);
            unset($_SESSION['SAAS_SUPERADMIN_SETUP_ID']);
            unset($_SESSION['SAAS_SUPERADMIN_NEW_SECRET']);
            $_SESSION['SESSION_SAAS_SUPERADMIN'] = true;
            header("Location: index.php");
            exit;
        } else {
            $error = "La Clave Maestra debe tener 4 a 8 dígitos.";
        }
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code !== '') {
            if (verifyTOTP($code, $secret)) {
                $id = (int)$_SESSION['SAAS_SUPERADMIN_SETUP_ID'];
                $stmt = $pdo->prepare("UPDATE saas_super_admins SET twofa_secret = ?, mfa_preference = 'totp' WHERE id = ?");
                $stmt->execute([$secret, $id]);
                unset($_SESSION['SAAS_SUPERADMIN_SETUP_ID']);
                unset($_SESSION['SAAS_SUPERADMIN_NEW_SECRET']);
                $_SESSION['SESSION_SAAS_SUPERADMIN'] = true;
                header("Location: index.php");
                exit;
            } else {
                $error = "Código incorrecto. Inténtalo de nuevo.";
            }
        } else {
            $error = "Ingresa el código de 6 dígitos.";
        }
    }
}

$label = rawurlencode('CORE:SuperAdmin');
$issuer = 'CORE';
$qrData = "otpauth://totp/$label?secret=$secret&issuer=$issuer&digits=6&period=30&algorithm=SHA1";
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=320x320&ecc=H&margin=2&color=000000&bgcolor=FFFFFF&data=" . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar 2FA - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:40px; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,0.15); width:400px; text-align:center; }
        .qr-code { margin: 12px auto; border: 1px solid #e5e5e5; padding: 8px; border-radius: 12px; overflow: hidden; background:#fff; }
        .qr-code img, .qr-code canvas { width: 100%; height: 100%; display: block; }
        .secret-key { font-family: monospace; background: #eee; padding: 5px 10px; border-radius: 4px; font-size: 14px; letter-spacing: 1px; }
        .btn-dark { width:100%; padding:12px; border-radius:8px; font-weight:600; }
    </style>
</head>
<body>
    <div class="box">
        <h4 class="mb-3">Seguridad Requerida</h4>
        <p class="text-muted small mb-3">Elige cómo quieres proteger tu acceso.</p>
        
        <div class="mb-3">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-secondary" id="optApp">App (QR)</button>
                <button type="button" class="btn btn-outline-secondary" id="optEmail">Correo</button>
                <button type="button" class="btn btn-outline-secondary" id="optPin">Clave Maestra</button>
            </div>
        </div>
        
        <div id="sectionApp">
            <p class="text-muted small mb-3">Escanea este código QR con tu app Authenticator y luego ingresa el código de 6 dígitos.</p>
            <div id="qrcode" class="qr-code" style="width:220px;height:220px;margin:0 auto 16px;"></div>
        </div>
        
        <div class="mb-4" id="manualBlock" style="margin-top:12px">
            <small class="text-muted d-block mb-1">O ingresa manualmente:</small>
            <span class="secret-key"><?php echo $secret; ?></span>
            <div class="mt-2 d-flex gap-2 justify-content-center">
                <button type="button" id="copySecret" class="btn btn-outline-secondary btn-sm">Copiar secreto</button>
                <a href="<?php echo htmlspecialchars($qrData, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary btn-sm">Abrir en app</a>
                <form method="post" class="d-inline">
                    <input type="hidden" name="regen_secret" value="1">
                    <button type="submit" class="btn btn-outline-warning btn-sm">Regenerar secreto</button>
                </form>
            </div>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-center gap-3">
                <div class="badge bg-light text-dark px-3 py-2">Hora del servidor: <span id="serverClock">--:--:--</span></div>
                <div class="badge bg-dark px-3 py-2">Cambio en: <span id="totpCountdown">--</span>s</div>
            </div>
        </div>

        

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="post" id="formApp">
            <input type="hidden" name="method" value="totp">
            <div class="mb-3">
                <input type="text" name="code" class="form-control text-center" placeholder="Ingresa el código de 6 dígitos" maxlength="6" inputmode="numeric" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn-dark">Verificar y Activar</button>
        </form>
        
        <form method="post" id="formEmail" style="display:none">
            <input type="hidden" name="method" value="email">
            <div class="mb-3">
                <div class="alert alert-light border small">Se enviará un código a tu correo cuando inicies sesión.</div>
            </div>
            <button type="submit" class="btn btn-dark">Usar Correo</button>
        </form>
        
        <form method="post" id="formPin" style="display:none">
            <input type="hidden" name="method" value="pin">
            <div class="mb-3">
                <input type="password" name="new_pin" class="form-control text-center" placeholder="Clave Maestra (4-8 dígitos)" maxlength="8" inputmode="numeric" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-dark">Usar Clave Maestra</button>
        </form>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        (function(){
            var data = "<?php echo htmlspecialchars($qrData, ENT_QUOTES, 'UTF-8'); ?>";
            try {
                var el = document.getElementById('qrcode');
                new QRCode(el, {
                    text: data,
                    width: 220,
                    height: 220,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
            } catch (e) {
                document.getElementById('qrcode').innerHTML = '<div class="alert alert-warning py-2 small">No se pudo generar el QR. Usa el secreto manual.</div>';
            }
        })();
        (function(){
            var btn = document.getElementById('copySecret');
            if (btn) {
                btn.addEventListener('click', function(){
                    var s = "<?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?>";
                    navigator.clipboard.writeText(s).then(function(){}, function(){});
                });
            }
        })();
        
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
                var sec = Math.floor(nowMs/1000);
                var left = 30 - (sec % 30);
                if (left === 30) left = 0;
                if (cdEl) cdEl.textContent = left;
            }
            tick();
            setInterval(tick, 250);
        })();
        (function(){
            var optApp = document.getElementById('optApp');
            var optEmail = document.getElementById('optEmail');
            var optPin = document.getElementById('optPin');
            var sectionApp = document.getElementById('sectionApp');
            var formApp = document.getElementById('formApp');
            var formEmail = document.getElementById('formEmail');
            var formPin = document.getElementById('formPin');
            function show(mode){
                sectionApp.style.display = (mode==='totp')?'block':'none';
                formApp.style.display = (mode==='totp')?'block':'none';
                formEmail.style.display = (mode==='email')?'block':'none';
                formPin.style.display = (mode==='pin')?'block':'none';
            }
            show('totp');
            optApp.addEventListener('click', function(){ show('totp'); });
            optEmail.addEventListener('click', function(){ show('email'); });
            optPin.addEventListener('click', function(){ show('pin'); });
        })();
    </script>
</body>
</html>
