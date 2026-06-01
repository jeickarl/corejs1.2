<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
$pdo = db();

// Autorización: sesión o token de API
$authorized = false;
try {
    $tokenHeader = $_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '';
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($tokenHeader !== '' && $isPost) {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'backup_api_token'");
        $stmt->execute();
        $stored = $stmt->fetchColumn();
        if ($stored && hash_equals($stored, $tokenHeader)) {
            $authorized = true;
        }
    }
} catch (Throwable $e) {}
if (!$authorized && !isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
if (!$authorized && !isAdminSession()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permisos insuficientes para generar respaldos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!$authorized) {
    $csrf = $_POST['csrf_token'] ?? '';
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
        exit;
    }
}

try {
    $fullBackup = (isset($_POST['full_backup']) && $_POST['full_backup'] == '1');
    $fileName = trim($_POST['file_name'] ?? '');

    $tableHasTenant = function($table) use ($pdo) {
        static $cache = [];
        $t = (string)$table;
        if (isset($cache[$t])) return $cache[$t];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) return $cache[$t] = false;
        try {
            $q = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'tenant_id'");
            $cache[$t] = ($q && $q->rowCount() > 0);
            return $cache[$t];
        } catch (Throwable $e) {
            return $cache[$t] = false;
        }
    };
    $excludedTables = [
        'tenants',
        'saas_users_lookup',
        'login_attempts',
        'security_logs',
        'notifications',
        'user_notifications'
    ];
    $isSaas = false;
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'tenants'");
        $isSaas = ($q && $q->rowCount() > 0);
    } catch (Throwable $e) { $isSaas = false; }

    $moduleTables = [
        'orders' => ['work_orders','order_accessories','order_equipment_accessories','order_checklist','checklist_items','order_statuses','order_states','technical_reports'],
        'cash' => ['cash_sessions','cash_income','cash_expenses'],
        'sales' => ['invoices','invoice_items','invoice_payments','invoice_drafts'],
        'inventory' => ['product_categories','products','inventory_movements','stock_alerts','warehouse_locations','product_locations'],
        'suppliers' => ['suppliers','supplier_contacts','supplier_products','purchase_orders','purchase_order_items','purchase_receipts','purchase_receipt_items','supplier_evaluations'],
        'clients' => ['clients'],
        'config' => [
            'system_config','company_config','order_statuses','payment_methods',
            'brands','device_types','models','problem_types','document_templates',
            'equipment_accessories','accessories','users','clients'
        ]
    ];

    $selectedModules = !$fullBackup ? ($_POST['modules'] ?? []) : [];
    $selectedModules = array_filter(array_map('strval', $selectedModules));

    $tenant_id = getCurrentTenantId();
    if (!$tenant_id || (int)$tenant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tenant inválido para generar respaldo.']);
        exit;
    }

    // Directorio de salida (filesystem)
    $backupDir = ensureTenantSubdirFs((int)$tenant_id, 'backups');
    if (!is_dir($backupDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo crear el directorio de respaldos. Verifica permisos en uploads.']);
        exit;
    }
    if (!is_writable($backupDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'El directorio de respaldos no tiene permisos de escritura: ' . $backupDir]);
        exit;
    }

    $date = date('Ymd_His');
    $baseName = $fileName !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fileName) : 'backup';
    $suffix = $fullBackup ? 'full' : (count($selectedModules) ? implode('-', $selectedModules) : 'custom');
    $outFile = $backupDir . DIRECTORY_SEPARATOR . $baseName . '_' . $suffix . '_' . $date . '.sql';
    $fpCheck = @fopen($outFile, 'wb');
    if (!$fpCheck) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo crear el archivo de respaldo en: ' . $outFile . ' (permisos de escritura)']);
        exit;
    }
    fclose($fpCheck);
    @unlink($outFile);

    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    $getConfig = function($key, $default = null) use ($pdo, $tenantValue, $perDatabase, $hasTenantSystem) {
        try {
            if ($hasTenantSystem && !$perDatabase) {
                $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = ?");
                $stmt->execute([$tenantValue, $key]);
            } else {
                $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
                $stmt->execute([$key]);
            }
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Throwable $e) { return $default; }
    };
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $defaultDump = $isWin ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : (file_exists('/usr/bin/mysqldump') ? '/usr/bin/mysqldump' : '/usr/local/bin/mysqldump');
    $mysqldump = $getConfig('backup_mysqldump_path', $defaultDump);
    if (!is_string($mysqldump) || $mysqldump === '' || preg_match('/[\\r\\n\\0&|;<>]/', $mysqldump) || strpos($mysqldump, '"') !== false || strpos($mysqldump, "'") !== false || !file_exists($mysqldump)) {
        $mysqldump = '';
    }

    $host = $db_config['host'];
    $db = $db_config['dbname'];
    $user = $db_config['username'];
    $pass = $db_config['password'];

    // Opciones configurables
    $includeTriggers = $getConfig('backup_include_triggers', '0') === '1';
    $includeRoutines = $getConfig('backup_include_routines', '0') === '1';
    $includeEvents = $getConfig('backup_include_events', '0') === '1';
    $dumpOptions = [
        $includeTriggers ? '' : '--skip-triggers',
        '--skip-add-locks',
        '--no-tablespaces',
        '--set-charset',
        '--default-character-set=utf8mb4',
        '--single-transaction',
        $includeRoutines ? '--routines' : '',
        $includeEvents ? '--events' : ''
    ];
    $dumpOptions = array_filter($dumpOptions, function($o){ return $o !== ''; });

    $command = '';
    if (!$isSaas && $mysqldump && file_exists($mysqldump)) {
        $command = '"' . $mysqldump . '" --host=' . escapeshellarg($host) . ' --user=' . escapeshellarg($user);
        if ($pass !== '') {
            $command .= ' --password=' . escapeshellarg($pass);
        }
        foreach ($dumpOptions as $opt) {
            $command .= ' ' . $opt;
        }
    }

    $tablesToDump = [];

    if ($fullBackup) {
        $tablesListAll = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tablesList = [];
        foreach ($tablesListAll as $t) {
            if (in_array($t, $excludedTables, true)) continue;
            if ($tableHasTenant($t)) $tablesList[] = $t;
        }
        if (empty($tablesList)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se encontraron tablas con tenant_id para respaldar en este sistema SaaS.']);
            exit;
        }
        if ($command) {
            $command .= ' ' . escapeshellarg($db);
        }
    } else {
        // Respaldo selectivo por tablas
        $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip($existingTables);
        foreach ($selectedModules as $mod) {
            if (isset($moduleTables[$mod])) {
                foreach ($moduleTables[$mod] as $t) {
                    if (isset($existingSet[$t])) {
                        $tablesToDump[$t] = true;
                    }
                }
            }
        }
        // Incluir tablas esenciales para configuración (plantillas WhatsApp y datos empresa)
        $essentials = ['system_config','company_config'];
        foreach ($essentials as $t) {
            if (isset($existingSet[$t])) {
                $tablesToDump[$t] = true;
            }
        }
        $tablesList = array_keys($tablesToDump);
        $tablesList = array_values(array_filter($tablesList, function($t) use ($tableHasTenant, $excludedTables) {
            if (in_array($t, $excludedTables, true)) return false;
            return $tableHasTenant($t);
        }));
        if (empty($tablesList)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se seleccionaron tablas válidas para este tenant (tablas deben tener tenant_id).']);
            exit;
        }
        if ($command) {
            $command .= ' ' . escapeshellarg($db) . ' ' . implode(' ', array_map('escapeshellarg', $tablesList));
        }
    }

    // Output file o Fallback PHP
    $output = '';
    if ($command) {
        $command .= ' --result-file=' . escapeshellarg($outFile);
        $output = shell_exec($command . ' 2>&1');
    }
    if (!file_exists($outFile) || filesize($outFile) === 0) {
        // Fallback: exportar vía PHP
        $fp = fopen($outFile, 'w');
        if (!$fp) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo crear archivo de respaldo.']);
            exit;
        }
        fwrite($fp, "-- Backup generado via PHP\n");
        fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $write = function($s) use ($fp){ fwrite($fp, $s); };
        $escape = function($v) use ($pdo){
            if ($v === null) return 'NULL';
            return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
        };
        foreach ($tablesList as $table) {
            $row = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
            $create = $row ? array_values($row)[1] : '';
            if (!preg_match('/^CREATE TABLE /i', $create)) { continue; }
            $createSafe = preg_replace('/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $create, 1);
            $write("\n{$createSafe};\n");
            if ($tableHasTenant($table) && !$perDatabase) {
                $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE tenant_id = ?');
                $stmt->execute([(int)$tenantValue]);
            } else {
                $stmt = $pdo->prepare('SELECT * FROM `' . $table . '`');
                $stmt->execute();
            }
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_keys($r);
                $vals = array_map(function($v) use ($escape){ return $escape($v); }, array_values($r));
                $write("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n");
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);
    }

    // --- MEJORA DE RESPALDO: COMPRESIÓN ZIP CON IMÁGENES ---
    $zipCreated = false;
    $zipFile = str_replace('.sql', '.zip', $outFile);

    // Intento 1: ZipArchive (Nativo PHP)
    try {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();

            if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
                // 1. Agregar el archivo SQL
                $zip->addFile($outFile, basename($outFile));

                // 2. Agregar carpeta uploads (imágenes y archivos) si es respaldo completo
                if ($fullBackup) {
                    $uploadsDir = realpath(getTenantUploadsFsById((int)$tenant_id));
                    $zipPrefix = 'uploads/' . (int)$tenant_id . '/';
                    
                    $backupDirReal = realpath($backupDir);
                    
                    if ($uploadsDir && is_dir($uploadsDir)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($uploadsDir),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );

                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                
                                // Importante: Excluir la carpeta de backups para evitar recursividad infinita
                                // (Aunque ahora backups está fuera de la ruta escaneada si está aislada, 
                                // pero si uploadsDir es la raíz uploads, sigue siendo necesario)
                                if ($backupDirReal && strpos($filePath, $backupDirReal) === 0) {
                                    continue;
                                }

                                // Ruta relativa dentro del ZIP
                                // Si uploadsDir es .../uploads/5, filePath es .../uploads/5/file.jpg
                                // substr devuelve file.jpg
                                // zipPrefix es uploads/5/
                                // Resultado: uploads/5/file.jpg
                                $relativePath = $zipPrefix . substr($filePath, strlen($uploadsDir) + 1);
                                $zip->addFile($filePath, $relativePath);
                            }
                        }
                    }
                }

                $zip->close();
                $zipCreated = true;
            }
        }
    } catch (Exception $e) {
        error_log("ZipArchive falló: " . $e->getMessage());
    }

    if ($zipCreated) {
        // Eliminar el archivo SQL original para ahorrar espacio, ya está en el ZIP
        if (file_exists($outFile)) {
            unlink($outFile);
        }

        // Manifest dentro del ZIP
        try {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($zipFile) === TRUE) {
                    $manifest = [
                        'type' => 'zip',
                        'scope' => 'tenant',
                        'tenant_id' => (int)$tenant_id,
                        'created_at' => time(),
                        'database' => $db,
                        'tables' => $tablesList,
                        'options' => ['triggers'=>$includeTriggers,'routines'=>$includeRoutines,'events'=>$includeEvents],
                    ];
                    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
                    $zip->close();
                }
            }
        } catch (Throwable $e) {}
        
        // Retención de ZIP
        $keepZip = (int)$getConfig('backup_retention_zip_count', '10');
        $files = array_filter(scandir($backupDir), function($f) use ($backupDir){ return is_file($backupDir . DIRECTORY_SEPARATOR . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'zip'; });
        usort($files, function($a,$b) use ($backupDir){ return filemtime($backupDir.DIRECTORY_SEPARATOR.$b) - filemtime($backupDir.DIRECTORY_SEPARATOR.$a); });
        foreach (array_slice($files, $keepZip) as $del) { @unlink($backupDir . DIRECTORY_SEPARATOR . $del); }

        // Subida a la nube (Google Drive) si está habilitado
        $cloudNote = '';
        try {
            $cloudEnabled = $getConfig('cloud_backup_enabled', '0') === '1';
            $cloudProvider = $getConfig('cloud_provider', '');
            if ($cloudEnabled && $cloudProvider === 'google_drive') {
                $uploadTarget = $zipFile;
                $encEnabled = $getConfig('cloud_encrypt_enabled', '0') === '1';
                $encKey = $getConfig('cloud_encrypt_key', '');
                if ($encEnabled && $encKey !== '') {
                    $data = file_get_contents($zipFile);
                    $iv = openssl_random_pseudo_bytes(16);
                    $cipher = openssl_encrypt($data, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
                    $hmac = hash_hmac('sha256', $iv . $cipher, $encKey, true);
                    $encPath = $zipFile . '.enc';
                    file_put_contents($encPath, $iv . $cipher . $hmac);
                    $uploadTarget = $encPath;
                }
                $clientId = $getConfig('gdrive_client_id');
                $clientSecret = $getConfig('gdrive_client_secret');
                $refreshToken = $getConfig('gdrive_refresh_token');
                if ($clientId && $clientSecret && $refreshToken) {
                    require_once __DIR__ . '/../cloud/GoogleDrive.php';
                    $drive = new GoogleDrive($clientId, $clientSecret, $refreshToken);
                    $drive->setVerifySSL($getConfig('cloud_verify_ssl', '1') === '1');
                    $parentId = $getConfig('gdrive_folder_id');
                    if ($parentId) { $drive->setParentId($parentId); }
                    $info = $drive->uploadFile($uploadTarget, 'Respaldo generado ' . date('Y-m-d H:i:s'));
                    if (isset($info['id'])) {
                        $cloudNote = ' | Subido a Google Drive (ID: ' . $info['id'] . ')';
                    } else {
                        $cloudNote = ' | Subida a Google Drive finalizada sin ID.';
                    }
                    if (isset($encPath) && file_exists($encPath)) { @unlink($encPath); }
                } else {
                    $cloudNote = ' | Nube habilitada, pero falta configuración de Google Drive.';
                }
            }
        } catch (Throwable $e) {
            $cloudNote = ' | Error al subir a la nube: ' . $e->getMessage();
        }

        $publicPath = '../backup/ajax/download_backup.php?filename=' . rawurlencode(basename($zipFile));
        echo json_encode([
            'success' => true,
            'message' => 'Respaldo completo (DB + Archivos) generado: ' . basename($zipFile) . $cloudNote,
            'file_url' => $publicPath,
        ]);
        exit;
    }
    // -------------------------------------------------------

    // Manifest para SQL
    try {
        $manifestPath = str_replace('.sql', '.manifest.json', $outFile);
        $manifest = [
            'type' => 'sql',
            'scope' => 'tenant',
            'tenant_id' => (int)$tenant_id,
            'created_at' => time(),
            'database' => $db,
            'tables' => $tablesList,
            'options' => ['triggers'=>$includeTriggers,'routines'=>$includeRoutines,'events'=>$includeEvents],
            'sql_file' => basename($outFile),
            'sql_sha256' => hash_file('sha256', $outFile)
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {}
    
    // Retención de SQL
    $keepSql = (int)$getConfig('backup_retention_sql_count', '5');
    $filesSql = array_filter(scandir($backupDir), function($f) use ($backupDir){ return is_file($backupDir . DIRECTORY_SEPARATOR . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'sql'; });
    usort($filesSql, function($a,$b) use ($backupDir){ return filemtime($backupDir.DIRECTORY_SEPARATOR.$b) - filemtime($backupDir.DIRECTORY_SEPARATOR.$a); });
    foreach (array_slice($filesSql, $keepSql) as $del) { @unlink($backupDir . DIRECTORY_SEPARATOR . $del); }

    // Subida a la nube para archivo SQL (por si no hubo ZIP)
    $cloudNote = '';
    try {
        $cloudEnabled = $getConfig('cloud_backup_enabled', '0') === '1';
        $cloudProvider = $getConfig('cloud_provider', '');
        if ($cloudEnabled && $cloudProvider === 'google_drive') {
            $uploadTarget = $outFile;
            $encEnabled = $getConfig('cloud_encrypt_enabled', '0') === '1';
            $encKey = $getConfig('cloud_encrypt_key', '');
            if ($encEnabled && $encKey !== '') {
                $data = file_get_contents($outFile);
                $iv = openssl_random_pseudo_bytes(16);
                $cipher = openssl_encrypt($data, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
                $hmac = hash_hmac('sha256', $iv . $cipher, $encKey, true);
                $encPath = $outFile . '.enc';
                file_put_contents($encPath, $iv . $cipher . $hmac);
                $uploadTarget = $encPath;
            }
            $clientId = $getConfig('gdrive_client_id');
            $clientSecret = $getConfig('gdrive_client_secret');
            $refreshToken = $getConfig('gdrive_refresh_token');
            if ($clientId && $clientSecret && $refreshToken) {
                require_once __DIR__ . '/../cloud/GoogleDrive.php';
                $drive = new GoogleDrive($clientId, $clientSecret, $refreshToken);
                $drive->setVerifySSL($getConfig('cloud_verify_ssl', '1') === '1');
                $parentId = $getConfig('gdrive_folder_id');
                if ($parentId) { $drive->setParentId($parentId); }
                $info = $drive->uploadFile($uploadTarget, 'Respaldo SQL generado ' . date('Y-m-d H:i:s'));
                if (isset($info['id'])) {
                    $cloudNote = ' | Subido a Google Drive (ID: ' . $info['id'] . ')';
                } else {
                    $cloudNote = ' | Subida a Google Drive finalizada sin ID.';
                }
                if (isset($encPath) && file_exists($encPath)) { @unlink($encPath); }
            } else {
                $cloudNote = ' | Nube habilitada, pero falta configuración de Google Drive.';
            }
        }
    } catch (Throwable $e) {
        $cloudNote = ' | Error al subir a la nube: ' . $e->getMessage();
    }

    $publicPath = '../backup/ajax/download_backup.php?filename=' . rawurlencode(basename($outFile));
    echo json_encode([
        'success' => true,
        'message' => 'Respaldo de base de datos generado: ' . basename($outFile) . $cloudNote,
        'file_url' => $publicPath,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Excepción al generar respaldo: ' . $e->getMessage()]);
}
