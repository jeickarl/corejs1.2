<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/database.php';

$message = '';
$type = 'info';

$known_placeholders = ['reset_link','exp_minutes','support_email','message','brand_name','company_name','admin_email','admin_password','login_url'];
function html_to_text($html) {
    $s = preg_replace_callback('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m){ return $m[2] . ' (' . $m[1] . ')'; }, $html);
    $s = preg_replace('/<(br|br\/|br\s\/)>/i', "\n", $s);
    $s = preg_replace('/<\/p>/i', "\n\n", $s);
    $s = preg_replace('/<script[\s\S]*?<\/script>/i', '', $s);
    $s = preg_replace('/<style[\s\S]*?<\/style>/i', '', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    return trim($s);
}
function detect_placeholders($html) {
    preg_match_all('/\{\{([^}]+)\}\}/', $html, $m);
    $names = array_values(array_unique($m[1] ?? []));
    sort($names);
    return $names;
}

$defaults = [
    'email_tpl_password_reset_subject' => 'Recuperación de contraseña Super Admin',
    'email_tpl_password_reset_preheader' => 'Recuperación de tu cuenta',
    'email_tpl_password_reset_html' =>
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7fafc;padding:20px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<tr><td style="padding:24px;background:#111;">'
        . '<div style="display:flex;align-items:center;gap:10px;">'
        . '<img src="{{brand_logo_url}}" alt="{{brand_name}} logo" width="28" style="display:block;">'
        . '<span style="color:#fff;font-weight:bold;font-size:18px;">{{brand_name}}</span>'
        . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<h2 style="margin:0 0 10px 0;font-size:20px;color:#111;">Recuperación de contraseña</h2>'
        . '<p style="margin:0 0 10px 0;line-height:1.6;color:#333;">Para restablecer tu contraseña, haz clic en el botón.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;"><tr><td>'
        . '<a href="{{reset_link}}" style="display:inline-block;padding:12px 18px;background:#111;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Restablecer contraseña</a>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 6px 0;line-height:1.6;color:#555;">Si el botón no funciona, copia y pega este enlace:</p>'
        . '<a href="{{reset_link}}" style="color:#0d6efd;text-decoration:none;">{{reset_link}}</a>'
        . '<p style="margin:16px 0 0 0;font-size:13px;color:#777;">Este enlace expira en {{exp_minutes}} minutos.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f8fafc;color:#666;font-size:12px;">CORE &middot; Soporte: {{support_email}}</td></tr>'
        . '</table>'
        . '</td></tr></table>',
    'email_tpl_welcome_subject' => 'Bienvenido a CORE - Registro Exitoso',
    'email_tpl_welcome_preheader' => 'Tus credenciales de acceso',
    'email_tpl_welcome_html' =>
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7fafc;padding:20px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<tr><td style="padding:24px;background:#111;">'
        . '<div style="display:flex;align-items:center;gap:10px;">'
        . '<img src="{{brand_logo_url}}" alt="{{brand_name}} logo" width="28" style="display:block;">'
        . '<span style="color:#fff;font-weight:bold;font-size:18px;">{{brand_name}}</span>'
        . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<h2 style="margin:0 0 10px 0;font-size:20px;color:#111;">¡Bienvenido, {{company_name}}!</h2>'
        . '<p style="margin:0 0 10px 0;line-height:1.6;color:#333;">Tu empresa ha sido registrada exitosamente en nuestra plataforma.</p>'
        . '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #111;">'
        . '<p style="margin: 0 0 15px 0; font-size: 14px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Tus credenciales de acceso</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" width="100%">'
        . '<tr>'
        . '<td style="padding-bottom: 8px; color: #666; width: 80px;">Usuario:</td>'
        . '<td style="padding-bottom: 8px; color: #111; font-weight: bold;">{{admin_email}}</td>'
        . '</tr>'
        . '<tr>'
        . '<td style="color: #666;">Contraseña:</td>'
        . '<td style="color: #111; font-weight: bold;">{{admin_password}}</td>'
        . '</tr>'
        . '</table>'
        . '</div>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:25px 0;"><tr><td>'
        . '<a href="{{login_url}}" style="display:inline-block;padding:14px 24px;background:#111;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;">Ingresar al Sistema</a>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 6px 0;line-height:1.6;color:#555;">Si el botón no funciona, copia y pega este enlace:</p>'
        . '<a href="{{login_url}}" style="color:#0d6efd;text-decoration:none;word-break:break-all;">{{login_url}}</a>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f8fafc;color:#666;font-size:12px;">CORE &middot; Soporte: {{support_email}}</td></tr>'
        . '</table>'
        . '</td></tr></table>',
    'email_tpl_notification_subject' => 'Notificación del sistema',
    'email_tpl_notification_preheader' => 'Nuevo mensaje del sistema',
    'email_tpl_notification_html' =>
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7fafc;padding:20px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<tr><td style="padding:24px;background:#111;">'
        . '<div style="display:flex;align-items:center;gap:10px;">'
        . '<img src="{{brand_logo_url}}" alt="{{brand_name}} logo" width="28" style="display:block;">'
        . '<span style="color:#fff;font-weight:bold;font-size:18px;">{{brand_name}}</span>'
        . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<h2 style="margin:0 0 10px 0;font-size:20px;color:#111;">Notificación del sistema</h2>'
        . '<p style="margin:0 0 10px 0;line-height:1.6;color:#333;">{{message}}</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f8fafc;color:#666;font-size:12px;">CORE &middot; Soporte: {{support_email}}</td></tr>'
        . '</table>'
        . '</td></tr></table>',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groups = ['password_reset','welcome','notification'];
    foreach ($groups as $g) {
        $subject = $_POST["{$g}_subject"] ?? '';
        $html = $_POST["{$g}_html"] ?? '';
        $pre = $_POST["{$g}_preheader"] ?? '';
        if ($subject !== '') cfg_set("email_tpl_{$g}_subject", $subject);
        if ($html !== '') cfg_set("email_tpl_{$g}_html", $html);
        cfg_set("email_tpl_{$g}_preheader", $pre);
    }
    // Parámetros de reset
    $exp = trim($_POST['reset_exp_minutes'] ?? '');
    $baseUrl = trim($_POST['system_base_url'] ?? '');
    $logoEnabled = isset($_POST['email_brand_logo_enabled']) ? '1' : '0';
    if ($exp !== '') cfg_set('password_reset_exp_minutes', $exp);
    if ($baseUrl !== '') cfg_set('system_base_url', $baseUrl);
    cfg_set('email_brand_logo_enabled', $logoEnabled);
    $message = 'Plantillas guardadas.';
    $type = 'success';

    if (($_POST['action'] ?? '') === 'send_test') {
        $to = trim($_POST['test_email'] ?? '');
        $tpl = $_POST['template'] ?? 'password_reset';
        $subjects = [
            'password_reset' => cfg_get('email_tpl_password_reset_subject', $defaults['email_tpl_password_reset_subject']),
            'welcome' => cfg_get('email_tpl_welcome_subject', $defaults['email_tpl_welcome_subject']),
            'notification' => cfg_get('email_tpl_notification_subject', $defaults['email_tpl_notification_subject']),
        ];
        $htmls = [
            'password_reset' => cfg_get('email_tpl_password_reset_html', $defaults['email_tpl_password_reset_html']),
            'welcome' => cfg_get('email_tpl_welcome_html', $defaults['email_tpl_welcome_html']),
            'notification' => cfg_get('email_tpl_notification_html', $defaults['email_tpl_notification_html']),
        ];
        $preheaders = [
            'password_reset' => cfg_get('email_tpl_password_reset_preheader', $defaults['email_tpl_password_reset_preheader']),
            'welcome' => cfg_get('email_tpl_welcome_preheader', $defaults['email_tpl_welcome_preheader']),
            'notification' => cfg_get('email_tpl_notification_preheader', $defaults['email_tpl_notification_preheader']),
        ];
        $support = cfg_get('smtp_from_email', '');
        $expM = cfg_get('password_reset_exp_minutes', '30');
        $autoBase = getSystemBaseUrl();
        $resetBase = rtrim(cfg_get('system_base_url', $autoBase), '/');
        $resetLink = $resetBase . '/super_admin/reset.php?token=DEMO';
        $brandName = cfg_get('smtp_from_name', 'Core');
        $logoUrl = $resetBase . '/assets/img/system_logo.png';
        $brandLogoEnabled = cfg_get('email_brand_logo_enabled', '0') === '1';
        $vars = [
            '{{support_email}}' => $support ?: 'soporte@core.local',
            '{{exp_minutes}}' => $expM,
            '{{reset_link}}' => $resetLink,
            '{{message}}' => 'Mensaje de prueba',
            '{{brand_name}}' => $brandName,
            '{{brand_logo_url}}' => $logoUrl,
        ];
        $body = strtr($htmls[$tpl] ?? '', $vars);
        if (!$brandLogoEnabled) {
            $body = preg_replace('/<img[^>]*(brand_logo_url|system_logo\.png)[^>]*>/i', '', $body);
        }
        $pre = trim($preheaders[$tpl] ?? '');
        if ($pre !== '') {
            $hidden = '<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . htmlspecialchars($pre) . '</span>';
            $body = $hidden . $body;
        }
        if ($to !== '') {
            $alt = html_to_text($body);
            // Forzar uso de URL absoluta del logo y NO adjuntar imagen
            $body = str_replace('{{brand_logo_url}}', $logoUrl, $body);
            $sent = sendSystemEmail($to, $subjects[$tpl] ?? 'Prueba', $body, true, $alt);
            $message = $sent ? 'Email de prueba enviado.' : 'No se pudo enviar el email de prueba.';
            $type = $sent ? 'success' : 'danger';
        } else {
            $message = 'Ingresa un correo para la prueba.';
            $type = 'warning';
        }
    }
}

$curr = [
    'password_reset_subject' => cfg_get('email_tpl_password_reset_subject', $defaults['email_tpl_password_reset_subject']),
    'password_reset_html' => cfg_get('email_tpl_password_reset_html', $defaults['email_tpl_password_reset_html']),
    'password_reset_preheader' => cfg_get('email_tpl_password_reset_preheader', $defaults['email_tpl_password_reset_preheader']),
    'welcome_subject' => cfg_get('email_tpl_welcome_subject', $defaults['email_tpl_welcome_subject']),
    'welcome_html' => cfg_get('email_tpl_welcome_html', $defaults['email_tpl_welcome_html']),
    'welcome_preheader' => cfg_get('email_tpl_welcome_preheader', $defaults['email_tpl_welcome_preheader']),
    'notification_subject' => cfg_get('email_tpl_notification_subject', $defaults['email_tpl_notification_subject']),
    'notification_html' => cfg_get('email_tpl_notification_html', $defaults['email_tpl_notification_html']),
    'notification_preheader' => cfg_get('email_tpl_notification_preheader', $defaults['email_tpl_notification_preheader']),
    'reset_exp_minutes' => cfg_get('password_reset_exp_minutes', '30'),
    'system_base_url' => cfg_get('system_base_url', ''),
    'email_brand_logo_enabled' => cfg_get('email_brand_logo_enabled', '0'),
];
// Datos para vista previa en cliente
$brandName = cfg_get('smtp_from_name', 'Core');
$autoBase = getSystemBaseUrl();
$base = $curr['system_base_url'] ?: $autoBase;
$logoUrl = rtrim($base, '/') . '/assets/img/system_logo.png';
$previewResetLink = rtrim($base, '/') . '/super_admin/reset.php?token=DEMO';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Plantillas de Email</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/css/super_admin.css?v=<?php echo time(); ?>">
</head>
<body class="bg-light">
<?php $sa_active = 'templates'; include __DIR__ . '/sidebar_common.php'; ?>
<div class="main-content">
    <?php $sa_title = 'Plantillas de Email'; include __DIR__ . '/header_common.php'; ?>
    <main class="container-fluid p-4">
        <div class="container" style="max-width: 960px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Plantillas de Email (Super Admin)</h3>
                <a href="smtp_setup.php" class="btn btn-outline-secondary btn-sm">Configurar SMTP</a>
            </div>
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $type; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="post" class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Preferencias generales</strong>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">URL base del sistema</label>
                            <input type="text" name="system_base_url" class="form-control" value="<?php echo htmlspecialchars($curr['system_base_url']); ?>" placeholder="https://tu-dominio.com/core">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Expira enlace (minutos)</label>
                            <input type="number" name="reset_exp_minutes" class="form-control" value="<?php echo htmlspecialchars($curr['reset_exp_minutes']); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="email_brand_logo_enabled" name="email_brand_logo_enabled" <?php echo $curr['email_brand_logo_enabled'] === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_brand_logo_enabled">Mostrar logo en emails</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-dark">Guardar preferencias</button>
                </div>
            </form>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Enviar prueba</strong>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="send_test">
                        <div class="col-md-5">
                            <label class="form-label">Correo destino</label>
                            <input type="email" class="form-control" name="test_email" value="<?php echo htmlspecialchars($curr['system_base_url'] ? '' : cfg_get('smtp_from_email','')); ?>" placeholder="tu@correo.com" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plantilla</label>
                            <select name="template" class="form-select">
                                <option value="password_reset">Recuperación de contraseña</option>
                                <option value="welcome">Bienvenida</option>
                                <option value="notification">Notificación</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-dark w-100">Enviar prueba</button>
                        </div>
                    </form>
                </div>
            </div>
            <form method="post">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong>Parámetros de Reset</strong>
                        <a href="smtp_help.php" class="btn btn-outline-secondary btn-sm">Ver ayuda</a>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Expiración (minutos)</label>
                                <input type="number" class="form-control" name="reset_exp_minutes" min="5" max="240" value="<?php echo htmlspecialchars($curr['reset_exp_minutes']); ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Base URL (opcional)</label>
                                <input type="text" class="form-control" name="system_base_url" placeholder="http://localhost/core" value="<?php echo htmlspecialchars($curr['system_base_url']); ?>">
                                <div class="form-text">Si se deja vacío, se detecta automáticamente. Útil cuando el enlace debe apuntar a un dominio público.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <strong>Recuperación de contraseña</strong>
                        <span class="text-muted ms-2 small">Placeholders: {{reset_link}}</span>
                        <div class="float-end d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="previewTemplate('password_reset')">Vista previa</button>
                            <button type="button" class="btn btn-dark btn-sm" onclick="openSendTest('password_reset')">Enviar prueba</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Asunto</label>
                            <input type="text" class="form-control" name="password_reset_subject" value="<?php echo htmlspecialchars($curr['password_reset_subject']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Preheader</label>
                            <input type="text" class="form-control" id="password_reset_preheader" name="password_reset_preheader" value="<?php echo htmlspecialchars($curr['password_reset_preheader']); ?>" placeholder="Resumen corto visible en bandeja">
                            <div class="form-text">Texto oculto al inicio del correo que mejora la apertura.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">HTML</label>
                            <textarea class="form-control" id="password_reset_html" name="password_reset_html" rows="8"><?php echo htmlspecialchars($curr['password_reset_html']); ?></textarea>
                            <?php $ph = detect_placeholders($curr['password_reset_html']); if ($ph): ?>
                                <div class="form-text">Placeholders detectados: <?php echo htmlspecialchars(implode(', ', $ph)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <strong>Ayuda Rápida</strong>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <li>Activa Verificación en 2 pasos en tu cuenta de Google.</li>
                            <li>Genera una Contraseña de aplicación para â??Correoâ? con nombre â??COREâ?.</li>
                            <li>En â??SMTP (Gmail)â?, usa tu Gmail como Usuario y Remitente.</li>
                            <li>La plantilla de reset usa {{reset_link}} para el enlace de restablecimiento.</li>
                        </ul>
                        <div class="mt-2">
                            <a href="smtp_setup.php" class="btn btn-outline-primary btn-sm">Configurar SMTP</a>
                            <a href="smtp_help.php" class="btn btn-outline-secondary btn-sm">Guía completa</a>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <strong>Bienvenida</strong>
                        <div class="float-end d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="previewTemplate('welcome')">Vista previa</button>
                            <button type="button" class="btn btn-dark btn-sm" onclick="openSendTest('welcome')">Enviar prueba</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Asunto</label>
                            <input type="text" class="form-control" name="welcome_subject" value="<?php echo htmlspecialchars($curr['welcome_subject']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Preheader</label>
                            <input type="text" class="form-control" id="welcome_preheader" name="welcome_preheader" value="<?php echo htmlspecialchars($curr['welcome_preheader']); ?>" placeholder="Resumen corto visible en bandeja">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">HTML</label>
                            <textarea class="form-control" id="welcome_html" name="welcome_html" rows="6"><?php echo htmlspecialchars($curr['welcome_html']); ?></textarea>
                            <?php $ph = detect_placeholders($curr['welcome_html']); if ($ph): ?>
                                <div class="form-text">Placeholders detectados: <?php echo htmlspecialchars(implode(', ', $ph)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <strong>Notificación</strong>
                        <span class="text-muted ms-2 small">Placeholders: {{message}}</span>
                        <div class="float-end d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="previewTemplate('notification')">Vista previa</button>
                            <button type="button" class="btn btn-dark btn-sm" onclick="openSendTest('notification')">Enviar prueba</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Asunto</label>
                            <input type="text" class="form-control" name="notification_subject" value="<?php echo htmlspecialchars($curr['notification_subject']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Preheader</label>
                            <input type="text" class="form-control" id="notification_preheader" name="notification_preheader" value="<?php echo htmlspecialchars($curr['notification_preheader']); ?>" placeholder="Resumen corto visible en bandeja">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">HTML</label>
                            <textarea class="form-control" id="notification_html" name="notification_html" rows="6"><?php echo htmlspecialchars($curr['notification_html']); ?></textarea>
                            <?php $ph = detect_placeholders($curr['notification_html']); if ($ph): ?>
                                <div class="form-text">Placeholders detectados: <?php echo htmlspecialchars(implode(', ', $ph)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-dark">Guardar</button>
                    <a href="index.php" class="btn btn-outline-secondary">Volver</a>
                </div>
            </form>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.PREVIEW_VARS = {
  "{{support_email}}": <?php echo json_encode(cfg_get('smtp_from_email','soporte@core.local')); ?>,
  "{{exp_minutes}}": <?php echo json_encode($curr['reset_exp_minutes']); ?>,
  "{{reset_link}}": <?php echo json_encode($previewResetLink); ?>,
  "{{message}}": "Mensaje de prueba",
  "{{brand_name}}": <?php echo json_encode($brandName); ?>,
  "{{brand_logo_url}}": <?php echo json_encode($logoUrl); ?>
};
window.DEFAULT_TEST_EMAIL = <?php echo json_encode(cfg_get('smtp_from_email','')); ?>;
function previewTemplate(base){
    var html = document.getElementById(base + '_html').value;
    var pre = '';
    var preEl = document.getElementById(base + '_preheader');
    if (preEl) { pre = preEl.value.trim(); }
    if (pre) {
        var hidden = '<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' + pre.replace(/[<>&]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}) + '</span>';
        html = hidden + html;
    }
    // Sustituir placeholders conocidos para vista previa
    Object.keys(window.PREVIEW_VARS).forEach(function(k){
      html = html.split(k).join(window.PREVIEW_VARS[k]);
    });
    var modal = document.getElementById('previewModal');
    var iframe = document.getElementById('previewFrame');
    iframe.srcdoc = html;
    var m = new bootstrap.Modal(modal);
    m.show();
}
function openSendTest(tpl){
    var modal = document.getElementById('sendTestModal');
    document.getElementById('sendTestTemplate').value = tpl;
    var emailInput = document.getElementById('sendTestEmail');
    if (!emailInput.value) { emailInput.value = window.DEFAULT_TEST_EMAIL || ''; }
    var m = new bootstrap.Modal(modal);
    m.show();
}
</script>
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Vista previa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="height:70vh;">
        <iframe id="previewFrame" style="width:100%;height:100%;border:1px solid #e5e7eb;border-radius:8px;"></iframe>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="sendTestModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Enviar prueba</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="send_test">
        <input type="hidden" name="template" id="sendTestTemplate" value="password_reset">
        <label class="form-label">Correo destino</label>
        <input type="email" class="form-control" name="test_email" id="sendTestEmail" placeholder="tu@correo.com" required>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-dark">Enviar</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>

