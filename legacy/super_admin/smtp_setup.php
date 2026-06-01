<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$message = '';
$type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['test_email'])) {
        $to = trim($_POST['test_email_to'] ?? '');
        if ($to === '') $to = cfg_get('smtp_user', cfg_get('smtp_from_email', ''));
        $host = trim($_POST['smtp_host'] ?? cfg_get('smtp_host', ''));
        $port = (int)($_POST['smtp_port'] ?? cfg_get('smtp_port', 587));
        $enc  = trim($_POST['smtp_encryption'] ?? cfg_get('smtp_encryption', 'tls'));
        $user = trim($_POST['smtp_user'] ?? cfg_get('smtp_user', ''));
        $pass = trim($_POST['smtp_pass'] ?? cfg_get('smtp_pass', ''));
        $fromEmail = trim($_POST['smtp_from_email'] ?? cfg_get('smtp_from_email', ''));
        $fromName  = trim($_POST['smtp_from_name'] ?? cfg_get('smtp_from_name', 'CORE'));
        $debugOn   = isset($_POST['smtp_debug']) ? '1' : '0';
        if ($port === 465 && strtolower($enc) === 'tls') { $enc = 'ssl'; }
        if ($host !== '') cfg_set('smtp_host', $host);
        if ($port > 0) cfg_set('smtp_port', (string)$port);
        if ($enc !== '') cfg_set('smtp_encryption', $enc);
        if ($user !== '') cfg_set('smtp_user', $user);
        if ($pass !== '') cfg_set('smtp_pass', $pass);
        if ($fromEmail !== '') cfg_set('smtp_from_email', $fromEmail);
        if ($fromName !== '') cfg_set('smtp_from_name', $fromName);
        cfg_set('smtp_debug', $debugOn);
        if ($to === '') {
            $message = 'Define un correo destino para la prueba.';
            $type = 'danger';
        } else {
            $sent = sendSystemEmail($to, 'Prueba SMTP CORE', '<p>Este es un correo de prueba enviado desde CORE.</p><p>Fecha: ' . date('Y-m-d H:i:s') . '</p>');
            if ($sent) {
                $message = 'Correo de prueba enviado a ' . htmlspecialchars($to);
                $type = 'success';
            } else {
                $message = 'Fallo al enviar la prueba. Revisa la configuración SMTP.';
                $type = 'danger';
            }
        }
    } else {
        $host = trim($_POST['smtp_host'] ?? '');
        $port = (int)($_POST['smtp_port'] ?? 587);
        $enc  = trim($_POST['smtp_encryption'] ?? 'tls');
        $user = trim($_POST['smtp_user'] ?? '');
        $pass = trim($_POST['smtp_pass'] ?? '');
        $fromEmail = trim($_POST['smtp_from_email'] ?? '');
        $fromName  = trim($_POST['smtp_from_name'] ?? 'CORE');
        $debugOn   = isset($_POST['smtp_debug']) ? '1' : '0';
        if ($host === '' || $user === '' || $fromEmail === '') {
            $message = 'Host, usuario y correo remitente son requeridos.';
            $type = 'danger';
        } else {
            cfg_set('smtp_host', $host);
            cfg_set('smtp_port', (string)$port);
            cfg_set('smtp_encryption', $enc);
            cfg_set('smtp_user', $user);
            if ($pass !== '') cfg_set('smtp_pass', $pass);
            cfg_set('smtp_from_email', $fromEmail);
            cfg_set('smtp_from_name', $fromName);
            cfg_set('smtp_debug', $debugOn);
            $message = 'Configuración SMTP guardada.';
            $type = 'success';
        }
    }
}

$curr = [
    'smtp_host' => cfg_get('smtp_host', 'smtp.hostinger.com'),
    'smtp_port' => cfg_get('smtp_port', '587'),
    'smtp_encryption' => cfg_get('smtp_encryption', 'tls'),
    'smtp_user' => cfg_get('smtp_user', ''),
    'smtp_pass' => cfg_get('smtp_pass', ''),
    'smtp_from_email' => cfg_get('smtp_from_email', ''),
    'smtp_from_name' => cfg_get('smtp_from_name', 'CORE'),
    'smtp_debug' => cfg_get('smtp_debug', '0'),
];
$defaultEmail = 'no-reply@mycore.com.co';
if ($curr['smtp_user'] === '') { $curr['smtp_user'] = $defaultEmail; }
if ($curr['smtp_from_email'] === '') { $curr['smtp_from_email'] = $defaultEmail; }
$tab = ($_GET['tab'] ?? 'config') === 'help' ? 'help' : 'config';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Configuración SMTP</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/css/super_admin.css?v=<?php echo time(); ?>">
</head>
<body class="bg-light">
    <?php $sa_active = 'smtp'; include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = 'Configuración SMTP'; include __DIR__ . '/header_common.php'; ?>
        <main class="container-fluid p-4">
            <div class="container" style="max-width: 960px;">
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab==='config'?'active':''; ?>" href="?tab=config">Configuración</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab==='help'?'active':''; ?>" href="?tab=help">Guía</a>
                    </li>
                </ul>
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $type; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if ($tab === 'config'): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 class="mb-0">Configuración SMTP (Hostinger)</h3>
                                <a href="?tab=help" class="btn btn-outline-secondary btn-sm">Ver guía</a>
                            </div>
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Proveedor</label>
                                        <select id="smtp_provider" class="form-select">
                                            <?php
                                                $provider = (stripos($curr['smtp_host'], 'gmail') !== false) ? 'gmail' : 'hostinger';
                                            ?>
                                            <option value="hostinger" <?php echo $provider==='hostinger'?'selected':''; ?>>Hostinger</option>
                                            <option value="gmail" <?php echo $provider==='gmail'?'selected':''; ?>>Gmail</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Host</label>
                                        <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_host']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Puerto</label>
                                        <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_port']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Cifrado</label>
                                        <select name="smtp_encryption" class="form-select">
                                            <option value="tls" <?php echo $curr['smtp_encryption']==='tls'?'selected':''; ?>>tls</option>
                                            <option value="ssl" <?php echo $curr['smtp_encryption']==='ssl'?'selected':''; ?>>ssl</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Usuario (correo)</label>
                                        <input type="email" name="smtp_user" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_user']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label id="smtp_pass_label" class="form-label">Contraseña del correo</label>
                                        <div class="input-group">
                                            <input type="password" name="smtp_pass" id="smtp_pass" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_pass']); ?>" placeholder="â?¢â?¢â?¢â?¢â?¢â?¢â?¢â?¢" autocomplete="off">
                                            <span class="input-group-text bg-white" style="cursor: pointer;" onclick="toggleSmtpPassword()">
                                                <i class="fas fa-eye text-muted" id="smtp_eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Remitente (email)</label>
                                        <input type="email" name="smtp_from_email" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_from_email']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Remitente (nombre)</label>
                                        <input type="text" name="smtp_from_name" class="form-control" value="<?php echo htmlspecialchars($curr['smtp_from_name']); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-check form-switch mt-2">
                                            <input type="checkbox" class="form-check-input" id="smtp_debug" name="smtp_debug" <?php echo ($curr['smtp_debug'] === '1') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="smtp_debug">Depuración SMTP (mostrar trazas debajo)</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 d-flex gap-2">
                                    <button type="submit" class="btn btn-dark">Guardar</button>
                                    <a href="index.php" class="btn btn-outline-secondary">Volver</a>
                                    <div class="ms-auto d-flex align-items-center gap-2">
                                        <input type="email" name="test_email_to" class="form-control form-control-sm" placeholder="Destino prueba (opcional)" style="max-width: 240px;">
                                        <button type="submit" name="test_email" value="1" class="btn btn-light">Enviar prueba</button>
                                    </div>
                                </div>
                            </form>
                            <hr class="my-4">
                            <p class="text-muted small">
                                Host: smtp.hostinger.com · Puerto: 587 (tls) o 465 (ssl) · Usuario y Remitente: tu correo completo (ej. no-reply@mycore.com.co).
                            </p>
                            <?php
                                $lastErr = function_exists('getLastEmailError') ? getLastEmailError() : '';
                                $lastDbg = function_exists('getLastEmailDebug') ? getLastEmailDebug() : '';
                                if ($lastErr !== '' || $lastDbg !== ''):
                            ?>
                            <div class="card border-0 shadow-sm mt-3">
                                <div class="card-body">
                                    <h6 class="fw-bold mb-2">Detalles de envío</h6>
                                    <?php if ($lastErr !== ''): ?>
                                        <div class="alert alert-danger small mb-2"><?php echo htmlspecialchars($lastErr); ?></div>
                                    <?php endif; ?>
                                    <?php if ($lastDbg !== ''): ?>
                                        <pre class="small bg-light p-3 rounded-3" style="white-space: pre-wrap; max-height: 280px; overflow:auto;"><?php echo htmlspecialchars($lastDbg); ?></pre>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h3 class="mb-3">Guía rápida: SMTP Hostinger</h3>
                            <p class="text-muted">Configura tu buzón de Hostinger para enviar correos desde CORE.</p>
                            <ol class="list-group list-group-numbered mb-4">
                                <li class="list-group-item">Host: <strong>smtp.hostinger.com</strong></li>
                                <li class="list-group-item">Puerto: <strong>587 (tls)</strong> o <strong>465 (ssl)</strong></li>
                                <li class="list-group-item">Usuario: tu correo completo (ej. <strong>no-reply@mycore.com.co</strong>)</li>
                                <li class="list-group-item">Contraseña: la del buzón en Hostinger</li>
                                <li class="list-group-item">Remitente: usa el mismo correo del usuario</li>
                            </ol>
                            <h5 class="mt-4">Problemas frecuentes</h5>
                            <ul class="list-group mb-3">
                                <li class="list-group-item">Tiempo de espera: revisa que el puerto esté permitido por el hosting</li>
                                <li class="list-group-item">Autenticación fallida: verifica usuario/contraseña del buzón</li>
                                <li class="list-group-item">Remitente rechazado: usa el mismo correo en Usuario y Remitente</li>
                            </ul>
                            <div class="d-flex gap-2">
                                <a class="btn btn-dark" href="?tab=config">Ir a configuración SMTP</a>
                                <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleSmtpPassword() {
        const input = document.getElementById('smtp_pass');
        const icon = document.getElementById('smtp_eye');
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
    // Presets para proveedor
    (function(){
        const providerSel = document.getElementById('smtp_provider');
        const hostInp = document.querySelector('input[name="smtp_host"]');
        const portInp = document.querySelector('input[name="smtp_port"]');
        const encSel = document.querySelector('select[name="smtp_encryption"]');
        const userInp = document.querySelector('input[name="smtp_user"]');
        const fromEmailInp = document.querySelector('input[name="smtp_from_email"]');
        const passLabel = document.getElementById('smtp_pass_label');
        function applyPreset(p){
            if (p === 'gmail') {
                hostInp.value = 'smtp.gmail.com';
                portInp.value = 587;
                encSel.value = 'tls';
                passLabel.textContent = 'Contraseña de aplicación';
                if (!userInp.value || userInp.value.indexOf('@') === -1) userInp.value = 'tu-correo@gmail.com';
                if (!fromEmailInp.value || fromEmailInp.value.indexOf('@') === -1) fromEmailInp.value = userInp.value;
            } else {
                hostInp.value = 'smtp.hostinger.com';
                portInp.value = 587;
                encSel.value = 'tls';
                passLabel.textContent = 'Contraseña del correo';
                if (!userInp.value || userInp.value.indexOf('@') === -1) userInp.value = 'no-reply@mycore.com.co';
                if (!fromEmailInp.value || fromEmailInp.value.indexOf('@') === -1) fromEmailInp.value = userInp.value;
            }
        }
        providerSel.addEventListener('change', function(){
            applyPreset(this.value);
        });
    })();
    </script>
</body>
</html>
