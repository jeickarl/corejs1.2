<?php
/**
 * Script CLI para respaldo automático de todos los inquilinos (Tenants)
 * Ejecutar mediante Tarea Programada (Windows Task Scheduler)
 * php C:\xampp\htdocs\core\backup\cli\backup_all_tenants.php
 */

// Asegurar que solo corre en CLI
if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ejecutarse desde la línea de comandos.");
}

// Configuración básica
date_default_timezone_set('America/Bogota');
define('BASE_PATH', dirname(__DIR__, 2)); // c:\xampp\htdocs\core
define('BACKUP_DIR', BASE_PATH . '/uploads/backups_automated');
define('LOG_FILE', BASE_PATH . '/backup/cli/backup.log');

// Asegurar directorios
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0777, true);
}

// Logger simple
function logMsg($msg) {
    $entry = date('Y-m-d H:i:s') . " - " . $msg . PHP_EOL;
    echo $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $entry, FILE_APPEND);
}

logMsg("=== Iniciando proceso de respaldo automático ===");

try {
    // 1. Conexión a Base de Datos Maestra (Core)
    $dbConfig = [
        'host' => 'localhost',
        'dbname' => 'core_db',
        'user' => 'root',
        'pass' => ''
    ];
    
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // 2. Obtener Configuración del Sistema
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config");
    $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Configuración de rutas (Ajustar según el entorno)
    $mysqldumpPath = 'c:\\xampp\\mysql\\bin\\mysqldump.exe'; // Ruta absoluta a mysqldump en XAMPP Windows
    $zipPath = 'zip'; // Se asume que zip está en el PATH o se usará la clase ZipArchive de PHP
    
    // Configuración de Nube (Google Drive)
    $cloudEnabled = ($config['cloud_backup_enabled'] ?? '0') === '1';
    $drive = null;
    
    if ($cloudEnabled) {
        require_once BASE_PATH . '/backup/cloud/GoogleDrive.php';
        $clientId = $config['gdrive_client_id'] ?? '';
        $clientSecret = $config['gdrive_client_secret'] ?? '';
        $refreshToken = $config['gdrive_refresh_token'] ?? '';
        
        if ($clientId && $clientSecret && $refreshToken) {
            try {
                $drive = new GoogleDrive($clientId, $clientSecret, $refreshToken);
                logMsg("Conexión a Google Drive preparada.");
            } catch (Exception $e) {
                logMsg("Error configurando Google Drive: " . $e->getMessage());
                $cloudEnabled = false;
            }
        } else {
            logMsg("Credenciales de Google Drive incompletas. Respaldo en nube omitido.");
            $cloudEnabled = false;
        }
    }
    
    // 3. Obtener Lista de Tenants Activos
    $stmt = $pdo->query("SELECT id, company_name, db_name FROM saas_tenants WHERE status = 'active'");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar el sistema Core a la lista (opcional, pero recomendado)
    $tenants[] = [
        'id' => 'core',
        'company_name' => 'SYSTEM CORE',
        'db_name' => 'core_db'
    ];
    
    logMsg("Se encontraron " . count($tenants) . " bases de datos para respaldar.");
    
    // 4. Procesar cada Tenant
    foreach ($tenants as $tenant) {
        $tenantId = $tenant['id'];
        $dbName = $tenant['db_name'];
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenant['company_name']);
        
        logMsg("Procesando: $name (ID: $tenantId, DB: $dbName)...");
        
        $date = date('Ymd_His');
        $baseFilename = "backup_{$tenantId}_{$name}_{$date}";
        $sqlFile = BACKUP_DIR . "/{$baseFilename}.sql";
        $zipFile = BACKUP_DIR . "/{$baseFilename}.zip";
        
        // A. Generar Dump SQL
        $cmd = "\"$mysqldumpPath\" --host={$dbConfig['host']} --user={$dbConfig['user']} --password={$dbConfig['pass']} --single-transaction --routines --events \"$dbName\" > \"$sqlFile\"";
        
        // Ejecutar mysqldump
        system($cmd, $retval);
        
        if ($retval !== 0 || !file_exists($sqlFile) || filesize($sqlFile) === 0) {
            logMsg("ERROR: Falló mysqldump para $dbName. Código: $retval");
            continue;
        }
        
        // B. Crear ZIP (SQL + Archivos)
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
            // Agregar SQL
            $zip->addFile($sqlFile, basename($sqlFile));
            
            // Agregar archivos subidos (Solo para tenants reales, no core)
            if ($tenantId !== 'core') {
                $uploadSource = BASE_PATH . "/uploads/{$tenantId}";
                if (is_dir($uploadSource)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($uploadSource),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    
                    foreach ($files as $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = 'uploads/' . $tenantId . '/' . substr($filePath, strlen($uploadSource) + 1);
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                }
            } else {
                // Para Core, podríamos respaldar uploads/company_logos o similares si no están en tenant folders
                // Por ahora solo SQL para core
            }
            
            $zip->close();
            
            // Borrar SQL original para ahorrar espacio
            unlink($sqlFile);
            
            logMsg("ZIP creado exitosamente: " . basename($zipFile));
            
            // C. Subir a Nube
            if ($cloudEnabled && $drive) {
                try {
                    // Opcional: Crear carpeta por fecha o por tenant
                    // Por simplicidad, subimos a raíz o carpeta configurada
                    $drive->uploadFile($zipFile, "Automated Backup for $name ($date)");
                    logMsg("Subido a Google Drive correctamente.");
                } catch (Exception $e) {
                    logMsg("ERROR subiendo a Drive: " . $e->getMessage());
                }
            }
            
        } else {
            logMsg("ERROR: No se pudo crear el archivo ZIP.");
        }
    }
    
    // Limpieza de backups antiguos (local)
    // Mantener últimos 5 días
    $files = glob(BACKUP_DIR . "/*.zip");
    $now = time();
    $retention = 5 * 24 * 60 * 60; // 5 días
    
    foreach ($files as $file) {
        if ($now - filemtime($file) > $retention) {
            unlink($file);
            logMsg("Eliminado backup antiguo: " . basename($file));
        }
    }
    
    logMsg("=== Proceso finalizado ===");

} catch (Exception $e) {
    logMsg("ERROR FATAL: " . $e->getMessage());
}
