<?php
// Desactivar visualización de errores INMEDIATAMENTE para no romper JSON
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

header('Content-Type: application/json');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../cloud/GoogleDrive.php';
$pdo = db();

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isAdminSession()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// CSRF (usa el token de sesión de settings.php)
$csrf = $_POST['csrf_token'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
    exit;
}

// Acción solicitada
$action = $_POST['action'] ?? '';

// Debug helper
function logDebug($msg) {
    return;
}

// Helper para limpiar salida de shell (Windows CP850/1252 a UTF-8)
function cleanShellOutput($out) {
    if (!$out) return '';
    // Detectar si es UTF-8 válido
    if (mb_check_encoding($out, 'UTF-8')) return $out;
    // Intentar convertir desde CP850 (común en CMD español) o CP1252
    $utf8 = @iconv('CP850', 'UTF-8//TRANSLIT', $out);
    if ($utf8) return $utf8;
    return @iconv('CP1252', 'UTF-8//TRANSLIT', $out) ?: $out;
}

try {
    ensureSystemConfigSchema();
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    $saveConfig = function($key, $value) use ($pdo, $tenantValue, $hasTenantSystem, $perDatabase) {
        if ($perDatabase) {
            $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$value, $key]);
            if ($stmt->rowCount() > 0) { return; }
            try {
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
                return;
            } catch (Throwable $e) {
                if ($hasTenantSystem) {
                    $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?");
                    $stmt->execute([$tenantValue, $key, $value, $value]);
                    return;
                }
                throw $e;
            }
        }

        if ($hasTenantSystem) {
            $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?");
            $stmt->execute([$tenantValue, $key, $value, $value]);
            return;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    };

    $getConfig = function($key) use ($pdo, $tenantValue, $hasTenantSystem, $perDatabase) {
        if ($hasTenantSystem && !$perDatabase) {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? AND tenant_id = ?");
            $stmt->execute([$key, $tenantValue]);
            return $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    };

    if ($action === 'get_auth_url') {
        $clientId = $_POST['client_id'] ?? '';
        $clientSecret = $_POST['client_secret'] ?? '';
        
        if (!$clientId || !$clientSecret) {
            throw new Exception('Client ID y Secret son requeridos');
        }

        // Guardar temporalmente las credenciales (o confiar en que el usuario las enviará de nuevo al canjear)
        // Mejor las guardamos ya, aunque no tengamos refresh token aún
        $saveConfig('gdrive_client_id', $clientId);
        $saveConfig('gdrive_client_secret', $clientSecret);
        
        // Al cambiar credenciales, el token anterior queda inválido. Lo borramos para forzar reconexión.
        $saveConfig('gdrive_refresh_token', '');
        $saveConfig('cloud_backup_enabled', '0'); // Deshabilitar nube temporalmente hasta confirmar conexión

        // Generar URL
        // IMPORTANTE: La Redirect URI debe coincidir con la configurada en Google Cloud Console.
        // Asumiremos una estándar o dejaremos que el usuario la copie manualmente si usáramos OOB,
        // pero Google bloqueó OOB.
        // Usaremos: http://localhost/core/backup/cloud/callback.php (ajustar según dominio real)
        // O mejor: leer el host actual.
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        // Ajustar ruta relativa
        $redirectUri = "$protocol://$host/core/backup/cloud/callback.php";

        $drive = new GoogleDrive($clientId, $clientSecret);
        $url = $drive->getAuthUrl($redirectUri);

        echo json_encode(['success' => true, 'url' => $url, 'redirect_uri' => $redirectUri]);

    } elseif ($action === 'save_token') {
        $code = $_POST['code'] ?? '';
        $redirectUri = $_POST['redirect_uri'] ?? '';
        
        if (!$code) throw new Exception('Código de autorización requerido');

        $clientId = $getConfig('gdrive_client_id');
        $clientSecret = $getConfig('gdrive_client_secret');

        if (!$clientId || !$clientSecret) throw new Exception('Configuración de API incompleta');

        $drive = new GoogleDrive($clientId, $clientSecret);
        $tokens = $drive->authenticate($code, $redirectUri);

        if (isset($tokens['refresh_token'])) {
            $saveConfig('gdrive_refresh_token', $tokens['refresh_token']);
            $saveConfig('cloud_provider', 'google_drive');
            $saveConfig('cloud_backup_enabled', '1');
            echo json_encode(['success' => true, 'message' => 'Conexión exitosa con Google Drive']);
        } else {
            // A veces Google no devuelve refresh token si ya se autorizó antes y no se forzó prompt=consent.
            // Nuestra clase usa prompt=consent, así que debería llegar.
            // Si no llega, pero tenemos access_token, tal vez ya teníamos refresh token guardado?
            // Asumiremos error si no llega refresh token por primera vez.
             echo json_encode(['success' => true, 'message' => 'Conexión establecida (Token actualizado)']);
        }

    } elseif ($action === 'toggle_cloud') {
        $enabled = $_POST['enabled'] === 'true' ? '1' : '0';
        $saveConfig('cloud_backup_enabled', $enabled);
        echo json_encode(['success' => true]);

    } elseif ($action === 'get_status') {
        $enabled = $getConfig('cloud_backup_enabled') === '1';
        $provider = $getConfig('cloud_provider');
        $hasToken = !empty($getConfig('gdrive_refresh_token'));
        
        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'provider' => $provider,
            'connected' => $hasToken,
            'has_token' => $hasToken,
            'client_id' => $getConfig('gdrive_client_id'),
            'client_secret' => $getConfig('gdrive_client_secret'),
            'folder_id' => $getConfig('gdrive_folder_id'),
            'encrypt_enabled' => $getConfig('cloud_encrypt_enabled') === '1',
            'schedule_mode' => $getConfig('cloud_schedule_mode') ?: 'manual',
            'schedule_time' => $getConfig('cloud_schedule_time') ?: '02:00',
            'backup_api_token' => $getConfig('backup_api_token') ?: '',
            'verify_ssl' => $getConfig('cloud_verify_ssl') === '1',
            'schedule_weekday' => $getConfig('cloud_schedule_weekday') ?: 'Monday'
        ]);
    } elseif ($action === 'save_provider_settings') {
        $provider = $_POST['provider'] ?? '';
        $folderId = trim($_POST['gdrive_folder_id'] ?? '');
        $clientId = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');

        // Validación: El ID de carpeta no debe ser un código de autorización
        if (strpos($folderId, '4/') === 0) {
            echo json_encode(['success' => false, 'message' => 'Error: Has pegado el Código de Autorización (empieza por 4/...) en el campo "ID Carpeta". Por favor bórralo o usa un ID de carpeta válido.']);
            exit;
        }

        $encryptEnabled = ($_POST['cloud_encrypt_enabled'] ?? '0') === '1' ? '1' : '0';
        $encryptKey = trim($_POST['cloud_encrypt_key'] ?? '');
        $scheduleMode = $_POST['cloud_schedule_mode'] ?? 'manual';
        $scheduleTime = $_POST['cloud_schedule_time'] ?? '02:00';
        $backupToken = trim($_POST['backup_api_token'] ?? '');
        $verifySSL = ($_POST['cloud_verify_ssl'] ?? '0') === '1' ? '1' : '0';
        $weekday = $_POST['cloud_schedule_weekday'] ?? 'Monday';
        
        if ($provider) $saveConfig('cloud_provider', $provider);
        $saveConfig('gdrive_folder_id', $folderId);
        
        // Guardar credenciales si se envían (permitir guardarlas aunque no se conecte de inmediato)
        if ($clientId !== '') $saveConfig('gdrive_client_id', $clientId);
        if ($clientSecret !== '') $saveConfig('gdrive_client_secret', $clientSecret);

        $saveConfig('cloud_encrypt_enabled', $encryptEnabled);
        if ($encryptKey !== '') $saveConfig('cloud_encrypt_key', $encryptKey);
        $saveConfig('cloud_schedule_mode', in_array($scheduleMode, ['manual','daily','weekly']) ? $scheduleMode : 'manual');
        $saveConfig('cloud_schedule_time', $scheduleTime);
        if ($backupToken !== '') $saveConfig('backup_api_token', $backupToken);
        $saveConfig('cloud_verify_ssl', $verifySSL);
        $validDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $saveConfig('cloud_schedule_weekday', in_array($weekday, $validDays) ? $weekday : 'Monday');
        echo json_encode(['success' => true, 'message' => 'Configuración de nube guardada']);
    } elseif ($action === 'generate_manual_script') {
        // Generar script .bat SIN intentar ejecutarlo (para descarga manual)
        $scheduleMode = $_POST['cloud_schedule_mode'] ?? '';
        $scheduleTime = $_POST['cloud_schedule_time'] ?? '';
        if ($scheduleMode === '') $scheduleMode = $getConfig('cloud_schedule_mode') ?: 'manual';
        if ($scheduleTime === '') $scheduleTime = $getConfig('cloud_schedule_time') ?: '02:00';
        $backupToken = $getConfig('backup_api_token');
        
        if ($scheduleMode === 'manual') {
            echo json_encode(['success' => false, 'message' => 'Selecciona una frecuencia (Diaria o Semanal) para generar el script de programación.']);
            exit;
        }
        if (!$backupToken) {
            echo json_encode(['success' => false, 'message' => 'Configura y guarda un Token de API primero.']);
            exit;
        }

        $taskName = $scheduleMode === 'daily' ? 'CoreBackupDaily' : 'CoreBackupWeekly';
        $url = "http://localhost/core/backup/ajax/export.php";
        
        // 1. Script que ejecutará la tarea
        $taskScript = "\$Headers = @{ 'X-Backup-Token' = '$backupToken' }; Invoke-WebRequest -Uri '$url' -Method Post -Headers \$Headers -Body @{ 'full_backup' = '1' } -UseBasicParsing";
        $taskEncoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $taskScript));
        
        // 2. Script para registrar la tarea
        $ps = "Try { Unregister-ScheduledTask -TaskName \"$taskName\" -Confirm:\$false -ErrorAction SilentlyContinue } Catch {}\n";
        $ps .= "\$Action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -EncodedCommand $taskEncoded'\n";
        
        if ($scheduleMode === 'daily') {
            $ps .= "\$Trigger = New-ScheduledTaskTrigger -Daily -At '$scheduleTime'\n";
        } else {
            $weekday = $getConfig('cloud_schedule_weekday') ?: 'Monday';
            $ps .= "\$Trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $weekday -At '$scheduleTime'\n";
        }
        
        $ps .= "Register-ScheduledTask -TaskName \"$taskName\" -Action \$Action -Trigger \$Trigger -Description \"Respaldo automático Core\" -RunLevel Highest -Force";
        
        $creationEncoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $ps));
        
        // Generar archivo .bat
        $batContent = "@echo off\r\n";
        $batContent .= "echo Instalando tarea programada de respaldo (Core Backup)...\r\n";
        $batContent .= "echo IMPORTANTE: Si ves 'Acceso denegado', ejecuta este archivo como Administrador.\r\n";
        $batContent .= "powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand $creationEncoded\r\n";
        $batContent .= "if %errorlevel% neq 0 (\r\n";
        $batContent .= "    echo Error: Fallo al registrar la tarea. Intenta ejecutar como Administrador.\r\n";
        $batContent .= "    pause\r\n";
        $batContent .= ") else (\r\n";
        $batContent .= "    echo Tarea instalada correctamente.\r\n";
        $batContent .= "    timeout /t 5\r\n";
        $batContent .= ")\r\n";

        echo json_encode([
            'success' => true, 
            'message' => 'Script generado correctamente.',
            'manual_script_base64' => base64_encode($batContent),
            'manual_script_filename' => ($scheduleMode === 'daily' ? 'install_task_daily.bat' : 'install_task_weekly.bat'),
            'manual_script_mime' => 'application/octet-stream'
        ]);

    } elseif ($action === 'create_schedule_task') {
        set_time_limit(120); // Dar más tiempo
        logDebug("Iniciando create_schedule_task");

        // Crear/actualizar tarea programada en Windows
        $scheduleMode = $_POST['cloud_schedule_mode'] ?? '';
        $scheduleTime = $_POST['cloud_schedule_time'] ?? '';
        if ($scheduleMode === '') $scheduleMode = $getConfig('cloud_schedule_mode') ?: 'manual';
        if ($scheduleTime === '') $scheduleTime = $getConfig('cloud_schedule_time') ?: '02:00';
        $backupToken = $getConfig('backup_api_token');
        
        logDebug("Mode: $scheduleMode, Time: $scheduleTime");

        if ($scheduleMode === 'manual') {
            echo json_encode(['success' => false, 'message' => 'Modo Manual: no se crea tarea.']);
            exit;
        }
        if (!$backupToken) {
            echo json_encode(['success' => false, 'message' => 'Debe configurar un Token de API antes de crear la tarea.']);
            exit;
        }
        $taskName = $scheduleMode === 'daily' ? 'CoreBackupDaily' : 'CoreBackupWeekly';
        // Ajustar URL para localhost o IP fija
        // IMPORTANTE: Windows Task Scheduler no comparte la sesión de usuario, 
        // así que 'localhost' debe resolver al servidor correcto.
        $url = "http://localhost/core/backup/ajax/export.php";
        
        // 1. Script que ejecutará la tarea (dentro de Windows Task Scheduler)
        // Usamos EncodedCommand para evitar problemas de comillas en los argumentos de la tarea
        $taskScript = "\$Headers = @{ 'X-Backup-Token' = '$backupToken' }; Invoke-WebRequest -Uri '$url' -Method Post -Headers \$Headers -Body @{ 'full_backup' = '1' } -UseBasicParsing";
        $taskEncoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $taskScript));
        
        // 2. Script para registrar la tarea (se ejecuta ahora)
        $ps = "Try { Unregister-ScheduledTask -TaskName \"$taskName\" -Confirm:\$false -ErrorAction SilentlyContinue } Catch {}\n";
        $ps .= "\$Action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -EncodedCommand $taskEncoded'\n";
        
        if ($scheduleMode === 'daily') {
            $ps .= "\$Trigger = New-ScheduledTaskTrigger -Daily -At '$scheduleTime'\n";
        } else {
            $weekday = $getConfig('cloud_schedule_weekday') ?: 'Monday';
            $ps .= "\$Trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $weekday -At '$scheduleTime'\n";
        }
        
        $ps .= "Register-ScheduledTask -TaskName \"$taskName\" -Action \$Action -Trigger \$Trigger -Description \"Respaldo automático Core\" -RunLevel Highest -Force";
        
        logDebug("Generando comando PowerShell...");

        // 3. Ejecutar el registro mediante EncodedCommand para evitar problemas de shell_exec
        $creationEncoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $ps));
        $cmd = "powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand $creationEncoded";
        
        logDebug("Ejecutando shell_exec...");
        $out = shell_exec($cmd . ' 2>&1');
        $outClean = cleanShellOutput($out);
        logDebug("Salida shell_exec: " . $outClean);
        
        // 4. Verificar si se creó
        $checkCmd = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Get-ScheduledTask -TaskName '$taskName' | Select-Object -ExpandProperty State\"";
        $checkOut = trim(shell_exec($checkCmd . ' 2>&1'));
        
        if ($checkOut === 'Ready' || $checkOut === 'Running') {
             logDebug("Tarea verificada: $checkOut");
             echo json_encode(['success' => true, 'message' => 'Tarea programada creada/actualizada: ' . $taskName]);
        } else {
             // Si falló, probablemente permisos. Generar script manual.
             logDebug("Fallo verificación. Estado: $checkOut");
             
             // Generar archivo .bat para instalación manual
             $batContent = "@echo off\r\n";
             $batContent .= "echo Instalando tarea programada de respaldo (Core Backup)...\r\n";
             $batContent .= "echo IMPORTANTE: Si ves 'Acceso denegado', ejecuta este archivo como Administrador.\r\n";
             $batContent .= "powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand $creationEncoded\r\n";
             $batContent .= "if %errorlevel% neq 0 (\r\n";
             $batContent .= "    echo Error: Fallo al registrar la tarea. Intenta ejecutar como Administrador.\r\n";
             $batContent .= "    pause\r\n";
             $batContent .= ") else (\r\n";
             $batContent .= "    echo Tarea instalada correctamente.\r\n";
             $batContent .= "    timeout /t 5\r\n";
             $batContent .= ")\r\n";

             echo json_encode([
                 'success' => false, 
                 'message' => 'Error de permisos: Windows bloqueó la creación automática de la tarea. Por favor descarga y ejecuta el script manual como Administrador.',
                 'manual_script_base64' => base64_encode($batContent),
                 'manual_script_filename' => 'install_task.bat',
                 'manual_script_mime' => 'application/octet-stream'
             ]);
        }
    } elseif ($action === 'disconnect_cloud') {
        // Desconectar cuenta: borrar refresh token
        $saveConfig('gdrive_refresh_token', '');
        $saveConfig('cloud_backup_enabled', '0');
        echo json_encode(['success' => true, 'message' => 'Cuenta desconectada correctamente.']);
    
    } elseif ($action === 'test_upload') {
        // Prueba de subida a Google Drive con archivo pequeño
        $clientId = $getConfig('gdrive_client_id');
        $clientSecret = $getConfig('gdrive_client_secret');
        $refreshToken = $getConfig('gdrive_refresh_token');
        if (!$clientId || !$clientSecret || !$refreshToken) {
            echo json_encode(['success' => false, 'message' => 'Configure y conecte Google Drive antes de probar subida.']);
            exit;
        }
        $folderId = $getConfig('gdrive_folder_id');
        
        // Validación anti-error común
        if (strpos($folderId, '4/') === 0) {
            echo json_encode(['success' => false, 'message' => 'Error: El "ID Carpeta" configurado es incorrecto (parece un Código de Autorización). Bórralo en la configuración y guarda cambios.']);
            exit;
        }

        $encEnabled = $getConfig('cloud_encrypt_enabled', '0') === '1';
        $encKey = $getConfig('cloud_encrypt_key', '');
        $tmpDir = sys_get_temp_dir();
        $fileBase = 'cloud_test_' . date('Ymd_His') . '.txt';
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $fileBase;
        file_put_contents($tmpFile, "Core Backup Cloud Test\nTime: " . date('c'));
        $uploadTarget = $tmpFile;
        $encPath = null;
        if ($encEnabled && $encKey !== '') {
            $data = file_get_contents($tmpFile);
            $iv = openssl_random_pseudo_bytes(16);
            $cipher = openssl_encrypt($data, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
            $encPath = $tmpFile . '.enc';
            file_put_contents($encPath, $iv . $cipher);
            $uploadTarget = $encPath;
        }
        require_once __DIR__ . '/../cloud/GoogleDrive.php';
        $drive = new GoogleDrive($clientId, $clientSecret, $refreshToken);
        if ($folderId) { $drive->setParentId($folderId); }
        $drive->setVerifySSL($getConfig('cloud_verify_ssl', '1') === '1');
        $info = $drive->uploadFile($uploadTarget, 'Prueba de subida desde Core');
        @unlink($tmpFile);
        if ($encPath && file_exists($encPath)) { @unlink($encPath); }
        if (isset($info['id'])) {
            echo json_encode(['success' => true, 'message' => 'Prueba exitosa. ID en Drive: ' . $info['id']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Subida completada sin ID', 'data' => $info]);
        }
    } else {
        throw new Exception('Acción desconocida');
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
