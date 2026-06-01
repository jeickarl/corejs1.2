<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$page_title = 'Diagnóstico del Sistema';
$sa_active = 'health';

// --- LÓGICA DE DIAGNÓSTICO ---

function hc_dbname(PDO $pdo): string
{
    try {
        $v = $pdo->query('SELECT DATABASE()')->fetchColumn();
        return is_string($v) ? $v : '';
    } catch (Throwable $e) {
        return '';
    }
}

function hc_show_tables(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        return array_values(array_unique(array_map('strval', $rows)));
    } catch (Throwable $e) {
        return [];
    }
}

$perDatabaseMode = function_exists('isPerDatabaseMode') ? (bool)isPerDatabaseMode() : false;
$saasModeEnv = getenv('SAAS_DB_MODE');
$saasModeEnv = is_string($saasModeEnv) ? strtolower(trim($saasModeEnv)) : '';
$saasModeLabel = $perDatabaseMode ? 'per_database' : ($saasModeEnv !== '' ? $saasModeEnv : 'single_db');

$current_db_name = hc_dbname($pdo);
$existing_tables = hc_show_tables($pdo);
$missing_tables = [];
$expected_tables = [];
$db_error = null;

$db_master = null;
$master_db_name = '';
$master_existing_tables = [];
$master_expected_tables = [];
$master_missing_tables = [];

$tenant_expected_tables = [];
$tenant_checks = [];
$tenant_issues_count = 0;
$tenant_total_count = 0;

if ($perDatabaseMode) {
    require_once __DIR__ . '/../config/database_manager.php';
    try {
        $db_master = DatabaseManager::master();
        $master_db_name = hc_dbname($db_master);
        $master_existing_tables = hc_show_tables($db_master);
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }

    $master_expected_tables = [
        'empresas',
        'usuarios_master',
        'licencias',
        'tenant_db_pool',
        'saas_super_admins'
    ];
    $master_missing_tables = array_values(array_diff($master_expected_tables, $master_existing_tables));

    $tenant_expected_tables = [
        'users',
        'clients',
        'work_orders',
        'invoices',
        'invoice_items',
        'invoice_payments',
        'system_config',
        'inventory_products',
        'inventory_movements',
        'suppliers',
        'supplier_payments'
    ];

    $empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
    $only_issues = isset($_GET['only_issues']) ? (int)$_GET['only_issues'] : 1;
    $max_empresas = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;

    if ($db_master && empty($db_error)) {
        $baseSql = "SELECT id, nombre, estado, db_host, db_port, db_name, schema_version, updated_at FROM empresas";
        $params = [];
        if ($empresa_id > 0) {
            $baseSql .= " WHERE id = ?";
            $params[] = $empresa_id;
        }
        $baseSql .= " ORDER BY id ASC";
        if ($empresa_id <= 0) {
            $baseSql .= " LIMIT " . (int)$max_empresas;
        }
        try {
            $st = $db_master->prepare($baseSql);
            $st->execute($params);
            $empresas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $empresas = [];
            $db_error = $db_error ?: $e->getMessage();
        }

        $tenant_total_count = count($empresas);
        foreach ($empresas as $emp) {
            $eid = (int)($emp['id'] ?? 0);
            if ($eid <= 0) { continue; }
            $t = [
                'id' => $eid,
                'nombre' => (string)($emp['nombre'] ?? ''),
                'estado' => (string)($emp['estado'] ?? ''),
                'db_host' => (string)($emp['db_host'] ?? ''),
                'db_port' => (int)($emp['db_port'] ?? 0),
                'db_name' => (string)($emp['db_name'] ?? ''),
                'schema_version' => (int)($emp['schema_version'] ?? 0),
                'updated_at' => (string)($emp['updated_at'] ?? ''),
                'connect_ok' => false,
                'tenant_db' => '',
                'tables_total' => 0,
                'missing_tables' => [],
                'error' => ''
            ];
            try {
                $tpdo = DatabaseManager::tenant($eid);
                $t['connect_ok'] = true;
                $t['tenant_db'] = hc_dbname($tpdo);
                $tables = hc_show_tables($tpdo);
                $t['tables_total'] = count($tables);
                $t['missing_tables'] = array_values(array_diff($tenant_expected_tables, $tables));
            } catch (Throwable $e) {
                $t['error'] = $e->getMessage();
            }

            $hasIssue = (!$t['connect_ok']) || !empty($t['missing_tables']);
            if ($hasIssue) { $tenant_issues_count++; }
            if ($only_issues === 1 && $empresa_id <= 0 && !$hasIssue) {
                continue;
            }
            $tenant_checks[] = $t;
        }
    }
} else {
    $expected_tables = [
        'users', 'work_orders', 'clients', 'inventory_products', 'inventory_movements',
        'suppliers', 'supplier_payments', 'system_config', 'saas_super_admins', 'invoices'
    ];

    try {
        $existing_tables = hc_show_tables($pdo);
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }

    $missing_tables = array_values(array_diff($expected_tables, $existing_tables));
}

$db_has_issues = (!empty($db_error))
    || (!$perDatabaseMode && !empty($missing_tables))
    || ($perDatabaseMode && (!empty($master_missing_tables) || $tenant_issues_count > 0));

// 2. Verificación de Directorios (Permisos de Escritura)
$directories = [
    '../uploads' => 'Carga de Archivos',
    '../backup' => 'Copias de Seguridad',
    '../config' => 'Configuración (Debe ser protegido)'
];

$dir_status = [];
foreach ($directories as $path => $name) {
    $real_path = realpath(__DIR__ . '/' . $path);
    $exists = file_exists($real_path);
    $writable = $exists && is_writable($real_path);
    
    // Regla especial para config: idealmente NO debería ser escribible por seguridad, 
    // pero en este sistema parece que se edita dinámicamente.
    // Asumiremos que uploads y backup SÍ deben ser escribibles.
    
    $dir_status[$path] = [
        'name' => $name,
        'path' => $real_path,
        'exists' => $exists,
        'writable' => $writable
    ];
}

// 3. Verificación de Seguridad (Archivos expuestos)
$dangerous_files = [
    '../test_email.php',
    '../reset_2fa_emergency.php',
    '../backup/install_task.bat'
];

$security_issues = [];
foreach ($dangerous_files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        $security_issues[] = basename($file);
    }
}

// 4. Verificación de PHP
$php_version = phpversion();
$required_extensions = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'gd', 'openssl'];
$missing_extensions = [];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

// --- ACCIONES POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_file'])) {
        $file_to_delete = basename($_POST['delete_file']);
        $target = __DIR__ . '/../' . $file_to_delete;
        // Solo permitir borrar los archivos de la lista de peligrosos
        if (in_array('../' . $file_to_delete, $dangerous_files) && file_exists($target)) {
            unlink($target);
            $success_msg = "Archivo $file_to_delete eliminado correctamente.";
            // Recargar para actualizar lista
            header("Location: health_check.php");
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Super Admin</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/super_admin.css">
    <style>
        .status-card { transition: transform 0.2s; border: none; }
        .status-card:hover { transform: translateY(-5px); }
        .icon-box { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.5rem; }
    </style>
</head>
<body class="bg-light">
    <?php include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = $page_title; include __DIR__ . '/header_common.php'; ?>
        
        <main class="container-fluid p-4">
            
            <?php if (isset($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <!-- Resumen General -->
                <div class="col-12">
                    <div class="card shadow-sm border-0 bg-white">
                        <div class="card-body d-flex align-items-center justify-content-between p-4">
                            <div>
                                <h4 class="mb-1">Estado General del Sistema</h4>
                                <p class="text-muted mb-0">Revisión automática de componentes críticos</p>
                            </div>
                            <?php if (!$db_has_issues && empty($security_issues) && empty($missing_extensions)): ?>
                                <span class="badge bg-success fs-5 px-3 py-2 rounded-pill">
                                    <i class="fas fa-check-circle me-2"></i>Sistema Saludable
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-5 px-3 py-2 rounded-pill">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Atención Requerida
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- 1. Base de Datos -->
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-white py-3 border-bottom-0">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="fas fa-database text-primary me-2"></i> Base de Datos
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($db_error)): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-times-circle me-2"></i>Error de conexión: <?php echo htmlspecialchars($db_error); ?>
                                </div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Modo SaaS
                                        <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($saasModeLabel); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Base de datos actual
                                        <span class="badge bg-dark rounded-pill"><?php echo htmlspecialchars($current_db_name ?: 'N/D'); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Tablas Encontradas
                                        <span class="badge bg-primary rounded-pill"><?php echo count($existing_tables); ?></span>
                                    </li>
                                    <?php if ($perDatabaseMode): ?>
                                        <li class="list-group-item">
                                            <div class="alert alert-info mb-0">
                                                Este servidor está en modo <strong>per_database</strong>. Las tablas críticas se validan por <strong>empresa</strong> (una base por empresa). El bloque “Master DB” es la base maestra del SaaS.
                                            </div>
                                        </li>
                                        <li class="list-group-item">
                                            <div class="fw-bold mb-2"><i class="fas fa-database me-2"></i>Master DB</div>
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <span class="badge bg-dark"><?php echo htmlspecialchars($master_db_name ?: 'N/D'); ?></span>
                                                <span class="badge bg-primary"><?php echo count($master_existing_tables); ?> tablas</span>
                                                <span class="badge <?php echo empty($master_missing_tables) ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo empty($master_missing_tables) ? 'OK' : (count($master_missing_tables) . ' faltantes'); ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($master_missing_tables)): ?>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($master_missing_tables as $table): ?>
                                                        <span class="badge bg-danger"><?php echo htmlspecialchars($table); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </li>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="fw-bold"><i class="fas fa-building me-2"></i>Empresas / Tenants</div>
                                                <span class="badge <?php echo ($tenant_issues_count > 0) ? 'bg-danger' : 'bg-success'; ?>">
                                                    <?php echo (int)$tenant_issues_count; ?> con problemas
                                                </span>
                                            </div>

                                            <?php if (empty($tenant_checks)): ?>
                                                <div class="text-muted">No hay empresas para revisar o no hay resultados.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle mb-0">
                                                        <thead>
                                                            <tr class="text-muted">
                                                                <th>ID</th>
                                                                <th>Empresa</th>
                                                                <th>DB</th>
                                                                <th>Conexión</th>
                                                                <th>Causa probable</th>
                                                                <th>Tablas faltantes</th>
                                                                <th></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($tenant_checks as $t): ?>
                                                                <?php
                                                                    $missingCount = is_array($t['missing_tables']) ? count($t['missing_tables']) : 0;
                                                                    $connOk = !empty($t['connect_ok']);
                                                                    $cause = '';
                                                                    if (!$connOk) {
                                                                        $cause = 'No conecta (credenciales/privilegios/DB inexistente)';
                                                                    } elseif ($missingCount >= 7) {
                                                                        $cause = 'DB incompleta (template no aplicado o creación fallida)';
                                                                    } elseif ($missingCount > 0) {
                                                                        $cause = 'Actualización pendiente / migración incompleta';
                                                                    } else {
                                                                        $cause = 'OK';
                                                                    }
                                                                ?>
                                                                <tr>
                                                                    <td class="text-muted"><?php echo (int)$t['id']; ?></td>
                                                                    <td><?php echo htmlspecialchars((string)$t['nombre']); ?></td>
                                                                    <td class="text-muted font-monospace"><?php echo htmlspecialchars((string)($t['db_name'] ?: $t['tenant_db'])); ?></td>
                                                                    <td>
                                                                        <?php if ($connOk): ?>
                                                                            <span class="badge bg-success">OK</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-danger">Falla</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-muted small"><?php echo htmlspecialchars($cause); ?></td>
                                                                    <td>
                                                                        <?php if (!$connOk): ?>
                                                                            <span class="text-danger">No conecta</span>
                                                                        <?php elseif ($missingCount === 0): ?>
                                                                            <span class="text-success">0</span>
                                                                        <?php else: ?>
                                                                            <span class="text-danger"><?php echo $missingCount; ?></span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-end">
                                                                        <a class="btn btn-sm btn-outline-dark" href="health_check.php?empresa_id=<?php echo (int)$t['id']; ?>">
                                                                            Ver
                                                                        </a>
                                                                    </td>
                                                                </tr>
                                                                <?php if (!$connOk && !empty($t['error'])): ?>
                                                                    <tr>
                                                                        <td colspan="7" class="text-muted small">
                                                                            <?php echo htmlspecialchars((string)$t['error']); ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($connOk && $missingCount > 0): ?>
                                                                    <tr>
                                                                        <td colspan="7">
                                                                            <div class="d-flex flex-wrap gap-2">
                                                                                <?php foreach ($t['missing_tables'] as $mt): ?>
                                                                                    <span class="badge bg-danger"><?php echo htmlspecialchars((string)$mt); ?></span>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                            <div class="mt-2 text-muted small">
                                                                                Reparación recomendada (CLI): <span class="font-monospace">php saas/repair_tenant_tables.php <?php echo (int)$t['id']; ?></span>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </li>
                                    <?php else: ?>
                                        <?php if (empty($missing_tables)): ?>
                                            <li class="list-group-item list-group-item-success d-flex align-items-center">
                                                <i class="fas fa-check-circle me-2"></i> Todas las tablas críticas existen
                                            </li>
                                        <?php else: ?>
                                            <li class="list-group-item list-group-item-danger">
                                                <div class="fw-bold mb-2"><i class="fas fa-exclamation-circle me-2"></i>Tablas Faltantes:</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($missing_tables as $table): ?>
                                                        <span class="badge bg-danger"><?php echo htmlspecialchars($table); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="mt-3">
                                                    <a href="fix_db.php" class="btn btn-sm btn-danger w-100">
                                                        <i class="fas fa-tools me-1"></i> Reparar Base de Datos Ahora
                                                    </a>
                                                </div>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <!-- Sección de Acciones de Base de Datos -->
                        <div class="card-footer bg-white border-top-0 pt-0 pb-3">
                            <h6 class="text-muted text-uppercase fs-7 fw-bold mb-3 mt-2">Acciones</h6>
                            <a href="export_db.php" class="btn btn-outline-primary w-100" target="_blank">
                                <i class="fas fa-file-export me-2"></i> Exportar Base de Datos Completa
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2. Seguridad -->
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-white py-3 border-bottom-0">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="fas fa-shield-alt text-danger me-2"></i> Seguridad y Archivos
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6 class="text-muted text-uppercase fs-7 fw-bold mb-3">Archivos Expuestos</h6>
                            <?php if (empty($security_issues)): ?>
                                <div class="alert alert-success mb-3">
                                    <i class="fas fa-check-circle me-2"></i> No se detectaron archivos peligrosos públicos.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i> Se encontraron archivos de prueba/instalación que deberían borrarse:
                                </div>
                                <ul class="list-group mb-4">
                                    <?php foreach ($security_issues as $file): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center bg-warning bg-opacity-10 border-warning">
                                            <span class="font-monospace text-dark"><?php echo $file; ?></span>
                                            <form method="post" onsubmit="return confirm('¿Seguro que deseas eliminar este archivo permanentemente?');">
                                                <input type="hidden" name="delete_file" value="<?php echo $file; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash-alt"></i> Eliminar
                                                </button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <h6 class="text-muted text-uppercase fs-7 fw-bold mb-3 mt-4">Permisos de Directorios</h6>
                            <ul class="list-group">
                                <?php foreach ($dir_status as $key => $dir): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="d-block fw-medium"><?php echo $dir['name']; ?></span>
                                            <small class="text-muted font-monospace"><?php echo $key; ?></small>
                                        </div>
                                        <?php if (!$dir['exists']): ?>
                                            <span class="badge bg-secondary">No existe</span>
                                        <?php elseif ($dir['writable']): ?>
                                            <span class="badge bg-success">Escribible (OK)</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Solo Lectura</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- 3. Entorno PHP -->
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3 border-bottom-0">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="fab fa-php text-primary me-2"></i> Entorno del Servidor
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-3 text-center border-end">
                                    <div class="display-6 fw-bold text-dark"><?php echo $php_version; ?></div>
                                    <span class="text-muted">Versión PHP</span>
                                </div>
                                <div class="col-md-9 ps-md-4">
                                    <h6 class="mb-3">Extensiones Requeridas:</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($required_extensions as $ext): ?>
                                            <?php if (in_array($ext, $missing_extensions)): ?>
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-times me-1"></i> <?php echo $ext; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success bg-opacity-75">
                                                    <i class="fas fa-check me-1"></i> <?php echo $ext; ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($missing_extensions)): ?>
                                        <div class="alert alert-danger mt-3 mb-0">
                                            Faltan extensiones críticas. Por favor habilítalas en php.ini.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
