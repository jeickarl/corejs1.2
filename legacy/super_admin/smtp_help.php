<?php
require_once 'auth.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Guía SMTP (Gmail) Paso a Paso</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/css/super_admin.css?v=<?php echo time(); ?>">
</head>
<body class="bg-light">
    <?php $sa_active = 'help'; include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = 'Guía SMTP'; include __DIR__ . '/header_common.php'; ?>
        <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="mb-3">Guía para activar SMTP con Gmail</h2>
                        <p class="text-muted">Sigue estos pasos para poder enviar correos desde CORE.</p>
                        <ol class="list-group list-group-numbered mb-4">
                            <li class="list-group-item">
                                Abre tu cuenta de Google en <a href="https://myaccount.google.com/" target="_blank">myaccount.google.com</a>.
                            </li>
                            <li class="list-group-item">
                                Activa la Verificación en 2 pasos:
                                <div class="mt-2">
                                    <div>1) En el menú lateral, entra a <strong>Seguridad</strong>.</div>
                                    <div>2) En â??Acceso de Googleâ?, haz clic en <strong>Verificación en dos pasos</strong>.</div>
                                    <div>3) Pulsa <strong>Empezar</strong> y confirma tu contraseña.</div>
                                    <div>4) Elige un método (recomendado: <strong>Mensajes de Google</strong> en tu teléfono o <strong>Google Authenticator</strong>).</div>
                                    <div>5) Sigue las instrucciones en pantalla y pulsa <strong>Activar</strong>.</div>
                                </div>
                            </li>
                            <li class="list-group-item">
                                Genera una Contraseña de aplicaciones:
                                <div class="mt-2">
                                    <div>1) Vuelve a <strong>Seguridad</strong> y entra en <a href="https://myaccount.google.com/apppasswords" target="_blank">Contraseñas de aplicaciones</a>.</div>
                                    <div>2) Si no ves esta opción: confirma que la verificación en 2 pasos esté <strong>activada</strong>; en cuentas de empresa (Google Workspace) el administrador puede <strong>bloquear</strong> las contraseñas de aplicaciones.</div>
                                    <div>3) En â??Seleccionar aplicaciónâ?, elige <strong>Correo</strong>.</div>
                                    <div>4) En â??Seleccionar dispositivoâ?, elige <strong>Otro (Nombre personalizado)</strong> y escribe <strong>CORE</strong>.</div>
                                    <div>5) Haz clic en <strong>Generar</strong> y copia la contraseña de <strong>16 caracteres</strong> que aparece.</div>
                                </div>
                            </li>
                            <li class="list-group-item">
                                Copia la contraseña de aplicación de 16 caracteres.
                            </li>
                            <li class="list-group-item">
                                Abre la página de configuración SMTP en CORE: <a href="smtp_setup.php">smtp_setup.php</a>.
                            </li>
                            <li class="list-group-item">
                                Completa los campos:
                                <div class="mt-2">
                                    <div>Host: <code>smtp.gmail.com</code></div>
                                    <div>Puerto: <code>587</code></div>
                                    <div>Cifrado: <code>tls</code></div>
                                    <div>Usuario: tu correo Gmail</div>
                                    <div>Contraseña: tu contraseña de aplicación</div>
                                    <div>Remitente email: tu correo Gmail</div>
                                    <div>Remitente nombre: por ejemplo, <code>CORE Super Admin</code></div>
                                </div>
                            </li>
                            <li class="list-group-item">
                                Guarda la configuración y prueba â??Olvidé mi contraseñaâ? en <code>super_admin/forgot.php</code>.
                            </li>
                        </ol>
                        <h5 class="mt-4">Problemas frecuentes</h5>
                        <ul class="list-group mb-3">
                            <li class="list-group-item">
                                Error 535: verifica que usas contraseña de aplicación y el usuario sea tu correo.
                            </li>
                            <li class="list-group-item">
                                Tiempo de espera: revisa firewall/antivirus y que el puerto 587 esté permitido.
                            </li>
                            <li class="list-group-item">
                                Remitente rechazado: usa el mismo correo en Usuario y Remitente.
                            </li>
                            <li class="list-group-item">
                                No aparece â??Contraseñas de aplicacionesâ?: asegúrate de tener la verificación en 2 pasos activada; en cuentas de empresa, consulta al administrador de Google Workspace para habilitarlo.
                            </li>
                        </ul>
                        <div class="d-flex gap-2">
                            <a class="btn btn-dark" href="smtp_setup.php">Ir a configuración SMTP</a>
                            <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script></script>
</body>
<html>

