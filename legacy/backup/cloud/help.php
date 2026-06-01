<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app_config.php';
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base = rtrim((string)($APP_CONFIG['cookie_path'] ?? '/core'), '/');
$redirectUri = $proto . '://' . $host . $base . '/backup/cloud/callback.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guía Maestra: Respaldo en Google Drive</title>
    <link rel="icon" type="image/png" href="../../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .hero-header { background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%); color: white; padding: 2rem 0; margin-bottom: 2rem; border-radius: 0 0 1rem 1rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .step-number { width: 32px; height: 32px; background-color: #0d6efd; color: white; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px; flex-shrink: 0; }
        .step-card { border: none; border-radius: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: transform 0.2s; margin-bottom: 1.5rem; }
        .step-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .code-box { background-color: #212529; color: #00ff9d; padding: 10px 15px; border-radius: 6px; font-family: 'Consolas', monospace; font-size: 0.9rem; word-break: break-all; position: relative; }
        .important-note { border-left: 4px solid #ffc107; background-color: #fff3cd; padding: 1rem; border-radius: 0 0.5rem 0.5rem 0; }
        .google-btn { background-color: white; color: #444; border: 1px solid #ddd; font-weight: 500; }
        .google-btn:hover { background-color: #f8f9fa; color: #222; }
        .img-placeholder { background-color: #e9ecef; border: 2px dashed #ced4da; border-radius: 8px; padding: 20px; text-align: center; color: #6c757d; margin: 10px 0; }
        a.external-link { text-decoration: none; color: #0d6efd; font-weight: 600; }
        a.external-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="hero-header text-center">
    <div class="container">
        <h1 class="fw-bold"><i class="fab fa-google-drive me-3"></i>Configuración de Respaldo en Nube</h1>
        <p class="lead mb-0">Guía paso a paso para conectar tu sistema con Google Drive</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <!-- Introducción -->
            <div class="card step-card">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold text-primary"><i class="fas fa-info-circle me-2"></i>¿Qué vamos a hacer?</h5>
                    <p class="card-text">
                        Vamos a crear una "aplicación" privada dentro de tu cuenta de Google (Google Cloud Console) para permitir que este sistema suba archivos a tu Google Drive de forma segura. 
                        Necesitarás obtener dos claves secretas: <strong>ID de cliente</strong> y <strong>Secreto de cliente</strong>.
                    </p>
                    <div class="alert alert-light border border-info d-flex align-items-center">
                        <i class="fas fa-clock text-info me-3 fa-2x"></i>
                        <div>
                            <strong>Tiempo estimado:</strong> 5 - 10 minutos.<br>
                            <strong>Requisitos:</strong> Una cuenta de Google (Gmail o Workspace) activa.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Paso 1: Crear Proyecto -->
            <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">1</span>
                        <h5 class="mb-0 fw-bold">Crear Proyecto en Google Cloud</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <ol class="list-group list-group-flush list-group-numbered">
                        <li class="list-group-item border-0 px-0">
                            Abre <a href="https://console.cloud.google.com/" target="_blank" class="external-link">Google Cloud Console <i class="fas fa-external-link-alt small"></i></a> e inicia sesión.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            En la barra superior, haz clic en el selector de proyectos (generalmente dice "Selecciona un proyecto" o el nombre de un proyecto actual).
                        </li>
                        <li class="list-group-item border-0 px-0">
                            En la ventana emergente, haz clic en <strong>"PROYECTO NUEVO"</strong> (New Project).
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Ponle un nombre como <code>RespaldoSistemaVentas</code> y pulsa <strong>CREAR</strong>. Espera unos segundos a que se cree y selecciónalo (asegúrate de que aparezca su nombre arriba).
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Paso 2: Habilitar API -->
            <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">2</span>
                        <h5 class="mb-0 fw-bold">Habilitar Google Drive API</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <p>Necesitamos decirle a Google que este proyecto usará Drive.</p>
                    <ol class="list-group list-group-flush list-group-numbered">
                        <li class="list-group-item border-0 px-0">
                            En el menú lateral (o buscador superior), ve a <strong>"API y servicios"</strong> > <strong>"Biblioteca"</strong> (Library).
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Escribe y busca <code>Google Drive API</code>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Haz clic en el resultado y luego en el botón azul <strong>HABILITAR</strong> (Enable).
                        </li>
                    </ol>
                    <div class="text-center mt-2">
                        <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-link me-1"></i>Ir directo a la Biblioteca
                        </a>
                    </div>
                </div>
            </div>

            <!-- Paso 3: Pantalla de Consentimiento -->
            <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">3</span>
                        <h5 class="mb-0 fw-bold">Configurar Pantalla de Consentimiento</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <p>Configura quién puede usar esta aplicación (solo tú).</p>
                    <ol class="list-group list-group-flush list-group-numbered">
                        <li class="list-group-item border-0 px-0">
                            Ve a <strong>"API y servicios"</strong> > <strong>"Pantalla de consentimiento de OAuth"</strong> (OAuth consent screen).
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Selecciona <strong>Externos</strong> (External) y pulsa <strong>CREAR</strong>.
                            <div class="text-muted small ms-3">Nota: Si tienes Google Workspace, puedes elegir "Internos", pero "Externos" funciona para todos (incluyendo @gmail.com).</div>
                        </li>
                        <li class="list-group-item border-0 px-0">
                            <strong>Información de la aplicación:</strong>
                            <ul>
                                <li>Nombre de la aplicación: <code>Sistema Respaldo</code></li>
                                <li>Correo electrónico de asistencia al usuario: Tu correo.</li>
                                <li>Información de contacto del desarrollador: Tu correo.</li>
                            </ul>
                            Pulsa <strong>GUARDAR Y CONTINUAR</strong>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            <strong>Alcances (Scopes):</strong> No es necesario agregar nada aquí. Pulsa <strong>GUARDAR Y CONTINUAR</strong>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            <strong>Usuarios de prueba (Test Users):</strong>
                            <div class="alert alert-warning py-2 small mt-1">
                                <strong>¡MUY IMPORTANTE!</strong> Como la app está en modo "Prueba", SOLO los usuarios que agregues aquí podrán conectarse.
                            </div>
                            <p class="mb-1 small">
                                Ruta: <strong>Google Auth Platform</strong> > <strong>Público (Audience)</strong> > <strong>Usuarios de prueba (Test users)</strong>.
                            </p>
                            Haz clic en <strong>ADD USERS</strong> (o AÑADIR USUARIOS) y escribe tu propia dirección de correo (la que usarás para guardar los respaldos). Pulsa <strong>AÑADIR</strong> y luego <strong>GUARDAR Y CONTINUAR</strong>.
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Paso 4: Crear Credenciales -->
            <div class="card step-card border-primary">
                <div class="card-header bg-primary text-white pt-3 px-4 rounded-top">
                    <div class="d-flex align-items-center">
                        <span class="step-number bg-white text-primary">4</span>
                        <h5 class="mb-0 fw-bold">Crear Credenciales (ID de Cliente y Secreto)</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <p class="mt-3">Aquí obtendremos las claves para conectar el sistema.</p>
                    <ol class="list-group list-group-flush list-group-numbered">
                        <li class="list-group-item border-0 px-0">
                            Ve a <strong>"API y servicios"</strong> > <strong>"Credenciales"</strong> (Credentials).
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Haz clic en <strong>+ CREAR CREDENCIALES</strong> (arriba) y elige <strong>ID de cliente de OAuth</strong>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            <strong>Tipo de aplicación:</strong> Selecciona <strong>Aplicación web</strong>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            <strong>Nombre:</strong> <code>Cliente Web Sistema</code> (o lo que quieras).
                        </li>
                        <li class="list-group-item border-0 px-0 bg-light p-3 rounded mt-2">
                            <strong>URI de redireccionamiento autorizados:</strong>
                            <br>
                            Este paso es crítico. Haz clic en <strong>AGREGAR URI</strong> y pega <strong>exactamente</strong> la siguiente URL:
                            <div class="input-group mt-2">
                                <input type="text" class="form-control font-monospace" value="<?php echo htmlspecialchars($redirectUri); ?>" id="redirectUriInput" readonly>
                                <button class="btn btn-dark" type="button" onclick="copyToClipboard()">
                                    <i class="fas fa-copy"></i> Copiar
                                </button>
                            </div>
                            <small class="text-danger mt-1 d-block"><i class="fas fa-exclamation-triangle"></i> Si esta URL no coincide exactamente (http vs https, barra final, etc.), Google rechazará la conexión con "Error 400: redirect_uri_mismatch".</small>
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Pulsa <strong>CREAR</strong>.
                        </li>
                        <li class="list-group-item border-0 px-0">
                            Aparecerá una ventana con tu <strong>ID de cliente</strong> y <strong>Secreto de cliente</strong>.
                            <br>
                            <span class="badge bg-success">¡Cópialos!</span> Los necesitarás en el siguiente paso.
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Paso 5: Conectar en el Sistema -->
            <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">5</span>
                        <h5 class="mb-0 fw-bold">Conectar en el Sistema</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <ol>
                        <li>Vuelve a la pestaña de configuración de este sistema (donde estabas antes).</li>
                        <li>Pega el <strong>ID de cliente</strong> (Client ID) y el <strong>Secreto de cliente</strong> (Client Secret) en los campos correspondientes.</li>
                        <li>Haz clic en el botón <strong>"Conectar Cuenta Google"</strong>.</li>
                        <li>Se abrirá una ventana de Google. Selecciona tu cuenta (la que agregaste en "Usuarios de prueba").</li>
                        <li>
                            <strong>Posibles resultados:</strong>
                            <ul class="mt-2">
                                <li class="mb-3">
                                    <span class="text-danger fw-bold"><i class="fas fa-times-circle"></i> Error "Acceso bloqueado" (Error 403: access_denied):</span>
                                    <br>
                                    Significa que <strong>NO agregaste tu correo</strong> a la lista de "Usuarios de prueba" en el Paso 3.
                                    <br>
                                    <span class="badge bg-warning text-dark">Solución:</span> Vuelve a la consola de Google > Pantalla de consentimiento de OAuth > Usuarios de prueba > Añadir usuarios > Escribe tu correo exacto.
                                </li>
                                <li class="mb-3">
                                    <span class="text-success fw-bold"><i class="fas fa-key"></i> ¿Para qué sirve y dónde pongo el "Código de Autorización"?</span>
                                    <br>
                                    El código que empieza por <code>4/...</code> es la <strong>llave maestra</strong>. Sirve para que el sistema obtenga un "Permiso Permanente" (Refresh Token) y pueda subir respaldos en el futuro sin volver a preguntarte.
                                    <br>
                                    <ul>
                                        <li><strong>Automático:</strong> Generalmente el sistema lo captura solo y cierra la ventana. Si ves "Cuenta Conectada", ¡listo!</li>
                                        <li><strong>Manual:</strong> Si la ventana no se cierra, copia ese código.
                                            <br>En el sistema aparecerá un campo verde llamado <strong>"Código de Autorización"</strong> (debajo del botón "Conectar"). Pégalo ahí y pulsa "Verificar".
                                        </li>
                                    </ul>
                                </li>
                                <li>
                                    <span class="text-warning fw-bold"><i class="fas fa-exclamation-triangle"></i> Advertencia "Google no ha verificado esta app":</span>
                                    <br>
                                    Esto es <strong>NORMAL</strong> y es lo que queremos ver.
                                    <br>
                                    Haz clic en <strong>Configuración avanzada</strong> (o Advanced) y luego en el enlace inferior <strong>Ir a Sistema Respaldo (no seguro)</strong>.
                                </li>
                            </ul>
                        </li>
                        <li>Marca las casillas para permitir ver y administrar archivos de Google Drive y pulsa <strong>Continuar</strong>.</li>
                        <li>Si todo sale bien, verás el mensaje de "Cuenta Conectada" en verde.</li>
                    </ol>
                </div>
            </div>

            <!-- Paso 6: ID de Carpeta y Cifrado -->
            <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">6</span>
                        <h5 class="mb-0 fw-bold">Configuración Avanzada (Carpeta y Cifrado)</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <h6 class="fw-bold"><i class="fas fa-folder text-warning me-2"></i>¿Para qué sirve el ID de Carpeta?</h6>
                    <p>
                        Este campo es <strong>OPCIONAL</strong>. Sirve para decirle al sistema en qué carpeta específica de tu Google Drive quieres guardar las copias.
                    </p>
                    <ul class="mb-3">
                        <li><strong>Si lo dejas vacío:</strong> Los archivos se guardarán sueltos en "Mi unidad" (la raíz de tu Drive).</li>
                        <li><strong>Si pones un ID:</strong> Los archivos se guardarán ordenadamente dentro de esa carpeta.</li>
                    </ul>
                    
                    <h6 class="fw-bold text-primary small">¿Cómo obtengo ese ID?</h6>
                    <ol class="small">
                        <li>Ve a tu Google Drive y abre (o crea) la carpeta deseada.</li>
                        <li>Mira la URL en la barra de direcciones del navegador. Será algo así:
                            <br><code>https://drive.google.com/drive/folders/<strong>1a2b3c4d5e6f7g8h9i0j</strong></code>
                        </li>
                        <li>La parte final (letras y números aleatorios) es el ID. Cópialo y pégalo en el campo <strong>ID Carpeta</strong>.</li>
                        <li class="mt-2 text-danger">
                            <strong>¡Cuidado!</strong> No pegues el "Código de autorización" (el que empieza por <code>4/...</code>) aquí.
                            Si ves un error <code>File not found: 4/...</code>, es porque pegaste el código incorrecto. Deja este campo vacío si no estás seguro.
                        </li>
                    </ol>
                    <hr>
                    <h6 class="fw-bold"><i class="fas fa-lock text-danger me-2"></i>Cifrado de Seguridad</h6>
                    <p>Si activas "Cifrar respaldo", el sistema encriptará el archivo ZIP usando AES-256 antes de subirlo.</p>
                    <ul>
                        <li>Define una <strong>Clave de cifrado</strong> (contraseña) y guárdala muy bien.</li>
                        <li>El archivo en Drive tendrá extensión <code>.enc</code>.</li>
                        <li>Para restaurarlo, necesitarás esa misma clave. Sin ella, los datos son irrecuperables.</li>
                    </ul>
                </div>
            </div>

             <!-- Paso 7: Programación -->
             <div class="card step-card">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <div class="d-flex align-items-center">
                        <span class="step-number">7</span>
                        <h5 class="mb-0 fw-bold">Programación Automática (Windows)</h5>
                    </div>
                </div>
                <div class="card-body px-4 pb-4">
                    <p>
                        El sistema usa el "Programador de Tareas de Windows" para hacer copias automáticas aunque no tengas la web abierta.
                    </p>
                    <ol class="small">
                        <li><strong>Token de API:</strong> En el campo "Token de API", inventa una contraseña segura (ej: <code>MiSuperClave2024</code>) y guarda.
                            <br><em>Esto evita que cualquiera pueda ejecutar la copia, solo Windows podrá.</em>
                        </li>
                        <li>Elige la frecuencia (Diario/Semanal) y la hora.</li>
                        <li>Haz clic en <strong>"Crear tarea programada"</strong>.</li>
                        <li>El sistema abrirá una ventana negra (PowerShell) y configurará todo solo. Si te pide permisos, di que sí.</li>
                    </ol>
                    <div class="alert alert-light border small mt-2">
                        <strong>¿Cómo subir manualmente?</strong>
                        <br>
                        No necesitas un botón especial. Ve a la pestaña <strong>"Sistema"</strong> y pulsa <strong>"Generar Copia de Seguridad"</strong>.
                        <br>
                        <span class="text-success"><i class="fas fa-check"></i> Si la nube está activada, el sistema subirá el archivo automáticamente al terminar de generarlo.</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    function copyToClipboard() {
        var copyText = document.getElementById("redirectUriInput");
        copyText.select();
        copyText.setSelectionRange(0, 99999); 
        navigator.clipboard.writeText(copyText.value).then(function() {
            alert("URL copiada al portapapeles: " + copyText.value);
        }, function(err) {
            alert("No se pudo copiar automáticamente. Por favor selecciónala y cópiala manualmente.");
        });
    }
</script>
</body>
</html>
