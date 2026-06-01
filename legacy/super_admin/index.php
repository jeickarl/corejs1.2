<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

// --- Logic for Dashboard ---
$tenants = [];
$isPerDatabaseMode = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($isPerDatabaseMode) {
    require_once __DIR__ . '/../config/database_manager.php';
}
try {
    if ($isPerDatabaseMode) {
        $master = DatabaseManager::master();
        $stmt = $master->query("SELECT id, nombre, estado, db_name, created_at FROM empresas WHERE estado <> 'deleted' ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $tenants[] = [
                'id' => (int)($r['id'] ?? 0),
                'company_name' => (string)($r['nombre'] ?? ''),
                'status' => ((string)($r['estado'] ?? 'active')) === 'active' ? 'active' : 'suspended',
                'slug' => 'empresa-' . (int)($r['id'] ?? 0),
                'db_name' => (string)($r['db_name'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
            ];
        }
    } else {
        // Usar nueva tabla 'tenants' en lugar de 'saas_tenants'
        $stmt = $pdo->query("SELECT *, slug as db_name FROM tenants ORDER BY created_at DESC");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
catch (Exception $e) {
// Table might not exist yet if setup wasn't run
}

// Calculate DB Sizes (Simplified for Single DB)
// En Single DB, todos comparten la misma base de datos, así que el tamaño individual es más complejo de calcular.
// Por ahora, mostraremos el tamaño total de la base de datos compartida para todos.
$sharedDbSize = 0;
try {
    if ($isPerDatabaseMode) {
        $master = DatabaseManager::master();
        $stmtSize = $master->prepare("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = ?
        ");
        foreach ($tenants as &$tenant) {
            $dbName = (string)($tenant['db_name'] ?? '');
            if ($dbName === '') {
                $tenant['size_mb'] = 0;
                continue;
            }
            $stmtSize->execute([$dbName]);
            $tenant['size_mb'] = (float)($stmtSize->fetchColumn() ?: 0);
        }
        unset($tenant);
    } else {
        $stmtSize = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb' FROM information_schema.TABLES WHERE table_schema = DATABASE()");
        $sharedDbSize = $stmtSize->fetchColumn();
    }
}
catch (Exception $e) {
    $sharedDbSize = 0;
}

if (!$isPerDatabaseMode) {
    foreach ($tenants as &$tenant) {
        $tenant['size_mb'] = $sharedDbSize; // Placeholder: mostrar tamaño total compartido
    }
    unset($tenant);
}

$activeTenantsCount = 0;
foreach ($tenants as $tenantRow) {
    if (($tenantRow['status'] ?? '') === 'active') {
        $activeTenantsCount++;
    }
}

// Ghost Databases logic removed as it's not relevant for Single DB architecture
$ghostDBs = [];

// Check for unlicensed tenants (optional logic)
$unlicensedTenants = [];

// Calculate Total Uploads Size
function getFolderSize($dir)
{
    $size = 0;
    $flags = FilesystemIterator::SKIP_DOTS;
    if (file_exists($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, $flags));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}

$uploadsDir = __DIR__ . '/../uploads';
$uploadsSize = getFolderSize($uploadsDir);
$uploadsSizeMB = round($uploadsSize / 1024 / 1024, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
    <title>Dashboard Maestro - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Modern UI -->
        <link rel="stylesheet" href="../assets/css/super_admin.css">
    
    <style>
        .card-header-actions { display: flex; gap: 10px; }
        .db-badge { font-family: monospace; font-size: 0.85em; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #475569; }
        .stat-card-icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    </style>
</head>
<body class="bg-light">

    <?php $sa_active = 'dashboard';
include __DIR__ . '/sidebar_common.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <?php $sa_title = 'Panel de Control General';
include __DIR__ . '/header_common.php'; ?>

        <!-- Dashboard Content -->
        <div class="container-fluid p-4">
            
            <!-- Alerts -->
            <?php if (!empty($ghostDBs)): ?>
            <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                <div>
                    <strong>¡Atención!</strong> Se detectaron <?php echo count($ghostDBs); ?> bases de datos "fantasmas" (sin empresa asignada).
                    <div class="mt-2">
                        <?php foreach ($ghostDBs as $gdb): ?>
                            <span class="badge bg-warning text-dark me-1"><?php echo htmlspecialchars($gdb); ?></span>
                            <a href="actions.php?action=delete_ghost&db=<?php echo $gdb; ?>" class="btn btn-sm btn-outline-dark py-0 px-1 ms-1" onclick="return confirm('¿Eliminar DEFINITIVAMENTE esta base de datos?');" style="font-size: 0.7rem;">Eliminar</a>
                        <?php
    endforeach; ?>
                    </div>
                </div>
            </div>
            <?php
endif; ?>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] == 'password_reset'): ?>
                    <div class="alert alert-success border-0 shadow-sm mb-4">
                        <i class="fas fa-check-circle me-2"></i> Contraseña actualizada correctamente.
                    </div>
                <?php
    elseif ($_GET['msg'] == 'tenant_deleted'): ?>
                    <div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center">
                        <i class="fas fa-trash-alt me-2"></i>
                        <div>
                            Empresa eliminada. 
                            <?php if (isset($_GET['files']) || isset($_GET['dirs'])): ?>
                                <span class="ms-2">Archivos: <?php echo (int)($_GET['files'] ?? 0); ?> · Directorios: <?php echo (int)($_GET['dirs'] ?? 0); ?></span>
                            <?php
        endif; ?>
                            <?php if (isset($_GET['fallback']) && (int)$_GET['fallback'] === 1): ?>
                                <span class="badge bg-warning text-dark ms-2">Fallback de limpieza aplicado</span>
                            <?php
        endif; ?>
                        </div>
                    </div>
                <?php
    endif; ?>
            <?php
endif; ?>

            <!-- Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 text-uppercase small fw-bold">Empresas Activas</p>
                                    <h2 class="fw-bold text-dark mb-0"><?php echo (int)$activeTenantsCount; ?></h2>
                                </div>
                                <div class="stat-card-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="fas fa-building fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 text-uppercase small fw-bold">Total Bases de Datos</p>
                                    <h2 class="fw-bold text-dark mb-0">
                                        <?php
$totalSize = array_sum(array_column($tenants, 'size_mb'));
echo $totalSize < 1024 ? $totalSize . ' MB' : round($totalSize / 1024, 2) . ' GB';
?>
                                    </h2>
                                </div>
                                <div class="stat-card-icon bg-info bg-opacity-10 text-info">
                                    <i class="fas fa-database fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 text-uppercase small fw-bold">Total Archivos</p>
                                    <h2 class="fw-bold text-dark mb-0">
                                        <?php echo $uploadsSizeMB < 1024 ? $uploadsSizeMB . ' MB' : round($uploadsSizeMB / 1024, 2) . ' GB'; ?>
                                    </h2>
                                </div>
                                <div class="stat-card-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="fas fa-folder-open fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 text-uppercase small fw-bold">DBs Fantasmas</p>
                                    <h2 class="fw-bold <?php echo count($ghostDBs) > 0 ? 'text-danger' : 'text-success'; ?> mb-0"><?php echo count($ghostDBs); ?></h2>
                                </div>
                                <div class="stat-card-icon <?php echo count($ghostDBs) > 0 ? 'bg-danger text-danger' : 'bg-success text-success'; ?> bg-opacity-10">
                                    <i class="fas fa-ghost fa-lg"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tenants Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom-0">
                    <h5 class="mb-0 fw-bold text-secondary">Gestión de Empresas</h5>
                    <div class="card-header-actions d-flex align-items-center gap-3">
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchTenant" class="form-control" placeholder="Buscar empresa...">
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTenantModal">
                            <i class="fas fa-plus me-2"></i> Nueva Empresa
                        </button>
                    </div>
                </div>
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0" id="tenantsTable">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th class="ps-4">Empresa</th>
                                <th>Dominio / URL</th>
                                <th>Base de Datos</th>
                                <th>Registro</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tenants)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
                                        <p>No hay empresas registradas.</p>
                                    </td>
                                </tr>
                            <?php
else: ?>
                                <?php foreach ($tenants as $t): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-light text-primary rounded-circle me-3 d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px;">
                                                <?php echo strtoupper(substr($t['company_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($t['company_name']); ?></div>
                                                <div class="small text-muted">ID: <?php echo $t['id']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="../login/index.php" target="_blank" class="text-decoration-none">
                                            <i class="fas fa-external-link-alt small me-1"></i> /login
                                        </a>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="db-badge me-2"><?php echo htmlspecialchars($t['db_name']); ?></span>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border"><?php echo $t['size_mb']; ?> MB</span>
                                        </div>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo date('d/m/Y', strtotime($t['created_at'])); ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border-0" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v text-muted"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                                <li>
                                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#resetPassModal" 
                                                       data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['company_name']); ?>">
                                                        <i class="fas fa-key me-2 text-warning"></i> Resetear Admin
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#renameTenantModal" 
                                                       data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['company_name']); ?>">
                                                        <i class="fas fa-pen me-2"></i> Renombrar Empresa
                                                    </a>
                                                </li>
                                                <li>
                                                        <a class="dropdown-item" href="tenant_users.php?id=<?php echo $t['id']; ?>">
                                                            <i class="fas fa-users me-2"></i> Usuarios
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="#" onclick="auditTenant(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['company_name'])); ?>'); return false;">
                                                            <i class="fas fa-shield-halved me-2 text-info"></i> Auditar Residuos
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#" onclick="deleteTenant(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['company_name'])); ?>'); return false;">
                                                        <i class="fas fa-trash-alt me-2"></i> Eliminar Empresa
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php
    endforeach; ?>
                            <?php
endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Vista Móvil (Tarjetas) -->
                <div class="d-block d-lg-none mt-3 px-3 pb-3">
                    <div class="row g-3">
                        <?php if (empty($tenants)): ?>
                            <div class="col-12 text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
                                <p>No hay empresas registradas.</p>
                            </div>
                        <?php
else: ?>
                            <?php foreach ($tenants as $t): ?>
                            <div class="col-12 tenant-card-mob" data-search="<?php echo strtolower(htmlspecialchars($t['company_name'] . ' ' . $t['slug'] . ' ' . $t['db_name'])); ?>">
                                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="avatar-sm bg-light text-primary rounded-circle me-3 d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 40px; height: 40px; font-size: 1.2rem;">
                                                <?php echo strtoupper(substr($t['company_name'], 0, 1)); ?>
                                            </div>
                                            <div class="overflow-hidden">
                                                <div class="fw-bold text-dark text-truncate fs-5 mb-1"><?php echo htmlspecialchars($t['company_name']); ?></div>
                                                <div class="small text-muted">ID: <?php echo $t['id']; ?> | Reg: <?php echo date('d/m/Y', strtotime($t['created_at'])); ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="bg-light rounded-3 p-2 mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="small text-muted">URL:</span>
                                                <a href="../login/index.php" target="_blank" class="text-decoration-none small text-truncate" style="max-width: 70%;">
                                                    /login
                                                </a>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="small text-muted">Base Datos:</span>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="db-badge text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars($t['db_name']); ?></span>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border"><?php echo $t['size_mb']; ?> MB</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="d-flex flex-nowrap gap-2 overflow-auto pb-1" style="-webkit-overflow-scrolling: touch;">
                                            <a href="#" class="btn btn-sm btn-light text-warning flex-shrink-0" data-bs-toggle="modal" data-bs-target="#resetPassModal" data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['company_name']); ?>" title="Resetear Admin" style="width: 36px; height: 36px;">
                                                <i class="fas fa-key"></i>
                                            </a>
                                            <a href="#" class="btn btn-sm btn-light text-secondary flex-shrink-0" data-bs-toggle="modal" data-bs-target="#renameTenantModal" data-id="<?php echo $t['id']; ?>" data-name="<?php echo htmlspecialchars($t['company_name']); ?>" title="Renombrar Empresa" style="width: 36px; height: 36px;">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <a href="tenant_users.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-light text-primary flex-shrink-0" title="Usuarios" style="width: 36px; height: 36px;">
                                                <i class="fas fa-users"></i>
                                            </a>
                                            <a href="#" class="btn btn-sm btn-light text-info flex-shrink-0" onclick="auditTenant(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['company_name'])); ?>'); return false;" title="Auditar Residuos" style="width: 36px; height: 36px;">
                                                <i class="fas fa-shield-halved"></i>
                                            </a>
                                            <a href="#" class="btn btn-sm btn-light text-danger flex-shrink-0 ms-auto" onclick="deleteTenant(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['company_name'])); ?>'); return false;" title="Eliminar Empresa" style="width: 36px; height: 36px;">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
    endforeach; ?>
                        <?php
endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="renameTenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form action="actions.php" method="POST">
                    <input type="hidden" name="action" value="rename_tenant">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                    <input type="hidden" name="tenant_id" id="renameTenantId">
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold">Renombrar Empresa</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nuevo Nombre</label>
                            <input type="text" name="company_name" id="renameTenantName" class="form-control" required minlength="2" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Tenant Modal -->
    <div class="modal fade" id="createTenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form action="actions.php" method="POST">
                    <input type="hidden" name="action" value="create_tenant_admin">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold">Nueva Empresa</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la Empresa</label>
                            <input type="text" name="company_name" class="form-control" required placeholder="Ej: Mi Empresa S.A.">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nombre del Admin</label>
                            <input type="text" name="admin_name" class="form-control" required placeholder="Ej: Administrador Principal">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Admin</label>
                            <input type="email" name="admin_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña Admin</label>
                            <input type="password" name="admin_password" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear Empresa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form action="actions.php" method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                    <input type="hidden" name="tenant_id" id="resetTenantId">
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold">Resetear Contraseña Admin</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Estás cambiando la contraseña del usuario ID 1 (Admin) para la empresa: <strong id="resetTenantName"></strong></p>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña</label>
                            <input type="text" name="new_password" class="form-control" required minlength="6" placeholder="Mínimo 6 caracteres">
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning text-white">Cambiar Contraseña</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tenantAuditModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold">Auditoría de Residuos de Tenant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="tenantAuditBody">
                        <div class="text-center py-4 text-muted">
                            <span class="spinner-border spinner-border-sm me-2"></span>Cargando auditoría...
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-warning" id="btnCleanupTenantResidue" onclick="cleanupTenantResidue()">
                        <i class="fas fa-broom me-1"></i>Limpiar Residuos
                    </button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentAuditTenantId = 0;
        let currentAuditTenantName = '';
        const csrfSa = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function postSaAction(action, fields) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'actions.php';
            const a = document.createElement('input');
            a.type = 'hidden';
            a.name = 'action';
            a.value = action;
            form.appendChild(a);

            const t = document.createElement('input');
            t.type = 'hidden';
            t.name = 'csrf_token';
            t.value = csrfSa;
            form.appendChild(t);

            Object.keys(fields || {}).forEach(k => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = k;
                inp.value = String(fields[k]);
                form.appendChild(inp);
            });
            document.body.appendChild(form);
            form.submit();
        }

        function escapeHtml(s) {
            return String(s || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function fmtBytes(bytes) {
            const n = Number(bytes || 0);
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(2) + ' KB';
            if (n < 1024 * 1024 * 1024) return (n / 1024 / 1024).toFixed(2) + ' MB';
            return (n / 1024 / 1024 / 1024).toFixed(2) + ' GB';
        }

        function auditTenant(id, name) {
            const modalEl = document.getElementById('tenantAuditModal');
            const bodyEl = document.getElementById('tenantAuditBody');
            if (!modalEl || !bodyEl) return;
            currentAuditTenantId = Number(id || 0);
            currentAuditTenantName = String(name || '');

            bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando auditoría...</div>';
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            fetch('actions.php?action=audit_tenant&id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(resp => {
                    if (!resp || !resp.success) {
                        bodyEl.innerHTML = '<div class="alert alert-danger mb-0">No se pudo auditar: ' + escapeHtml(resp && resp.message ? resp.message : 'Error desconocido') + '</div>';
                        return;
                    }

                    const db = resp.database || {};
                    const fs = resp.filesystem || {};
                    const rows = Array.isArray(db.tables_with_rows) ? db.tables_with_rows : [];

                    const rowsHtml = rows.length
                        ? rows.map(r => '<tr><td><code>' + escapeHtml(r.table) + '</code></td><td class="text-end">' + Number(r.rows || 0).toLocaleString() + '</td></tr>').join('')
                        : '<tr><td colspan="2" class="text-center text-muted py-3">Sin registros tenant-scoped</td></tr>';

                    bodyEl.innerHTML = `
                        <div class="mb-3">
                            <div class="small text-muted">Empresa</div>
                            <div class="fw-bold">${escapeHtml(name)} (ID ${Number(id)})</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">Filas tenant-scoped</div>
                                    <div class="h5 mb-0">${Number(db.total_rows || 0).toLocaleString()}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">Lookup usuarios</div>
                                    <div class="h5 mb-0">${Number(db.saas_users_lookup_rows || 0).toLocaleString()}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">Login attempts</div>
                                    <div class="h5 mb-0">${Number(db.login_attempts_rows || 0).toLocaleString()}</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <h6 class="fw-bold mb-2">Detalle Base de Datos</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Tabla</th><th class="text-end">Filas</th></tr></thead>
                                    <tbody>${rowsHtml}</tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h6 class="fw-bold mb-2">Detalle FileSystem</h6>
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><code>${escapeHtml((fs.uploads && fs.uploads.path) ? fs.uploads.path : ('uploads/' + Number(id)))}</code></span>
                                    <span class="small text-muted">existe: ${fs.uploads && fs.uploads.exists ? 'sí' : 'no'} · archivos: ${Number(fs.uploads && fs.uploads.files || 0)} · dirs: ${Number(fs.uploads && fs.uploads.dirs || 0)} · tamaño: ${fmtBytes(fs.uploads && fs.uploads.bytes || 0)}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><code>${escapeHtml((fs.storage && fs.storage.path) ? fs.storage.path : ('storage/tenants/' + Number(id)))}</code></span>
                                    <span class="small text-muted">existe: ${fs.storage && fs.storage.exists ? 'sí' : 'no'} · archivos: ${Number(fs.storage && fs.storage.files || 0)} · dirs: ${Number(fs.storage && fs.storage.dirs || 0)} · tamaño: ${fmtBytes(fs.storage && fs.storage.bytes || 0)}</span>
                                </li>
                            </ul>
                        </div>
                    `;
                })
                .catch(() => {
                    bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Error de conexión al auditar.</div>';
                });
        }

        function cleanupTenantResidue() {
            const id = Number(currentAuditTenantId || 0);
            if (!id) return;
            const bodyEl = document.getElementById('tenantAuditBody');
            const btn = document.getElementById('btnCleanupTenantResidue');
            const msg = 'Esto limpiará residuos operativos del tenant sin eliminar la empresa. Se conservarán usuarios y configuración base. ¿Continuar?';
            if (!confirm(msg)) return;

            const fd = new FormData();
            fd.append('action', 'cleanup_tenant_residue');
            fd.append('tenant_id', String(id));
            fd.append('csrf_token', csrfSa);

            if (btn) btn.disabled = true;
            if (bodyEl) bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Limpiando residuos...</div>';

            fetch('actions.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(resp => {
                    if (!resp || !resp.success) {
                        if (bodyEl) bodyEl.innerHTML = '<div class="alert alert-danger mb-0">No se pudo limpiar: ' + escapeHtml(resp && resp.message ? resp.message : 'Error desconocido') + '</div>';
                        return;
                    }
                    const rows = Number(resp.deleted_rows_total || 0).toLocaleString();
                    alert('Limpieza completada. Filas eliminadas: ' + rows);
                    auditTenant(id, currentAuditTenantName);
                })
                .catch(() => {
                    if (bodyEl) bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Error de conexión al limpiar residuos.</div>';
                })
                .finally(() => {
                    if (btn) btn.disabled = false;
                });
        }

        function deleteTenant(id, name) {
            const msg = '¿ESTÁ SEGURO? Esto eliminará la empresa "' + String(name || '') + '" y todos sus datos. No hay vuelta atrás.';
            if (!confirm(msg)) return;
            postSaAction('delete_tenant', { id: Number(id || 0) });
        }

        // Search Functionality
        document.getElementById('searchTenant').addEventListener('keyup', function() {
            let searchText = this.value.toLowerCase();
            
            // For Desktop Table
            let tableRows = document.querySelectorAll('#tenantsTable tbody tr');
            tableRows.forEach(row => {
                if(row.classList.contains('text-center') && row.children.length === 1) return; // Skip empty row message
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
            
            // For Mobile Cards
            let cards = document.querySelectorAll('.tenant-card-mob');
            cards.forEach(card => {
                let searchData = card.getAttribute('data-search') || '';
                card.style.display = searchData.includes(searchText) ? '' : 'none';
            });
        });

        // Pass Data to Reset Modal
        const resetModal = document.getElementById('resetPassModal');
        if (resetModal) {
            resetModal.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                document.getElementById('resetTenantId').value = id;
                document.getElementById('resetTenantName').textContent = name;
            });
        }
        const renameModal = document.getElementById('renameTenantModal');
        if (renameModal) {
            renameModal.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                document.getElementById('renameTenantId').value = id;
                document.getElementById('renameTenantName').value = name;
            });
        }
    </script>
</body>
</html>
