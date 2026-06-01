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

$pdo->exec("CREATE TABLE IF NOT EXISTS saas_super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL,
    twofa_secret VARCHAR(255) NULL,
    recovery_token VARCHAR(255) NULL,
    recovery_expires DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $c = $pdo->query("SHOW COLUMNS FROM saas_super_admins LIKE 'email'");
    if (!$c || $c->rowCount() === 0) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN email VARCHAR(255) NULL");
    }
    $c = $pdo->query("SHOW COLUMNS FROM saas_super_admins LIKE 'twofa_secret'");
    if (!$c || $c->rowCount() === 0) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN twofa_secret VARCHAR(255) NULL");
    }
    $c = $pdo->query("SHOW COLUMNS FROM saas_super_admins LIKE 'recovery_token'");
    if (!$c || $c->rowCount() === 0) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN recovery_token VARCHAR(255) NULL");
    }
    $c = $pdo->query("SHOW COLUMNS FROM saas_super_admins LIKE 'recovery_expires'");
    if (!$c || $c->rowCount() === 0) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN recovery_expires DATETIME NULL");
    }
} catch (Exception $e) {}

$count = (int)$pdo->query("SELECT COUNT(*) FROM saas_super_admins")->fetchColumn();
if ($count === 0) {
    $username = 'master';
    $emailSeed = 'jeissonandroi@gmail.com';
    $passwordHash = password_hash('Master2026!', PASSWORD_DEFAULT);
    $secret = generateBase32Secret(20);
    $stmt = $pdo->prepare("INSERT INTO saas_super_admins (username, email, password, twofa_secret) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $emailSeed, $passwordHash, $secret]);
}

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Correo inválido.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM saas_super_admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $token = bin2hex(random_bytes(32));
            $expMin = (int)cfg_get('password_reset_exp_minutes', '30');
            if ($expMin < 5) { $expMin = 30; }
            $expires = date('Y-m-d H:i:s', time() + ($expMin * 60));
            $upd = $pdo->prepare("UPDATE saas_super_admins SET recovery_token = ?, recovery_expires = ? WHERE id = ?");
            $upd->execute([$token, $expires, (int)$admin['id']]);
            $baseOverride = trim(cfg_get('system_base_url', ''));
            if ($baseOverride !== '') {
                $root = rtrim($baseOverride, '/');
            } else {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/core/super_admin')), '/');
                $root = $scheme . '://' . $host . $base;
            }
            $resetLink = $root . '/super_admin/reset.php?token=' . $token;
            // Cargar plantilla desde system_config si existe
            $subject = cfg_get('email_tpl_password_reset_subject', 'Recuperación de contraseña Super Admin');
            $tplHtml = cfg_get('email_tpl_password_reset_html', '');
            if ($tplHtml) {
                $brandName = cfg_get('smtp_from_name', 'Core');
                $support = cfg_get('smtp_from_email', '');
                $brandLogoEnabled = cfg_get('email_brand_logo_enabled', '0') === '1';
                $logoUrl = $root . '/assets/img/system_logo.png';
                $vars = [
                    '{{reset_link}}' => htmlspecialchars($resetLink),
                    '{{exp_minutes}}' => $expMin,
                    '{{support_email}}' => $support ?: 'soporte@core.local',
                    '{{brand_name}}' => $brandName,
                    '{{brand_logo_url}}' => $logoUrl,
                ];
                $bodyHtml = strtr($tplHtml, $vars);
                if (!$brandLogoEnabled) {
                    $bodyHtml = preg_replace('/<img[^>]*(brand_logo_url|system_logo\.png)[^>]*>/i', '', $bodyHtml);
                }
            } else {
                $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;line-height:1.6">'
                          . '<p><strong>Recuperación de contraseña</strong></p>'
                          . '<p>Para restablecer tu contraseña, haz clic en el botón:</p>'
                          . '<p><a href="' . htmlspecialchars($resetLink) . '" style="display:inline-block;padding:10px 16px;background:#111;color:#fff;text-decoration:none;border-radius:8px">Restablecer contraseña</a></p>'
                          . '<p>Si no funciona el botón, copia y pega este enlace en tu navegador:</p>'
                          . '<p><a href="' . htmlspecialchars($resetLink) . '">' . htmlspecialchars($resetLink) . '</a></p>'
                          . '<p>Este enlace expira en ' . $expMin . ' minutos.</p>'
                          . '</div>';
            }
            $altBody = "Para restablecer tu contraseña, visita: " . $resetLink . " (expira en " . $expMin . " minutos)";
            $sent = false;
            try {
                $sent = sendSystemEmail($email, $subject, $bodyHtml, true, $altBody);
            } catch (Exception $e) {
                $sent = false;
            }
            if ($sent) {
                $info = "Si el correo existe, se enviará un enlace de recuperación.";
            } else {
                $info = 'Correo no configurado. Usa este enlace para restablecer tu contraseña (expira en ' . $expMin . ' minutos): '
                      . '<br><a href="' . htmlspecialchars($resetLink) . '">' . htmlspecialchars($resetLink) . '</a>';
            }
        } else {
            $info = "Si el correo existe, se enviará un enlace de recuperación.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:30px; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,0.15); width:380px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="mb-2">
            <a href="../login/index.php" class="text-muted" style="text-decoration:none;font-size:12px;">
                <i class="fas fa-arrow-left"></i> Volver al login normal
            </a>
        </div>
        <h5 class="mb-3">Recuperar Contraseña</h5>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert alert-info"><?php echo $info; ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Correo del Super Admin</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-dark w-100">Enviar enlace</button>
        </form>
        <div class="mt-3 text-center">
            <a href="login.php" class="text-muted">Volver al login</a>
        </div>
    </div>
</body>
</html>
