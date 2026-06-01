<style>
/* Diseño Responsivo para Tabla de Usuarios */
@media (max-width: 767.98px) {
    #usersTable thead {
        display: none;
    }
    #usersTable, #usersTable tbody, #usersTable tr, #usersTable td {
        display: block;
        width: 100%;
    }
    #usersTable tr {
        margin-bottom: 1rem;
        background-color: #fff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 0.75rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
    }
    #usersTable td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    #usersTable td::before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #6c757d;
        width: 35%;
        flex-shrink: 0;
        margin-right: 1rem;
        text-align: left;
    }
    #usersTable td:last-child {
        border-bottom: none;
        background-color: #f8f9fa;
        font-weight: bold;
    }
}
</style>
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
            <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 text-center text-sm-start" style="border-radius: 1rem 1rem 0 0;">
                <h5 class="mb-0 text-dark w-100 w-sm-auto">
                    <i class="fas fa-users me-2"></i>Gestión de Usuarios
                </h5>
                <div class="d-flex flex-column flex-sm-row gap-2 w-100 w-sm-auto">
                    <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-primary rounded-pill shadow-sm flex-grow-1 flex-sm-grow-0" onclick="openCreateUserModal()">
                        <i class="fas fa-plus me-2"></i>Crear Usuario
                    </button>
                    <?php
endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (!$isAdmin): ?>
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Acceso Limitado:</strong> No tienes permisos de administrador para gestionar usuarios. Tu rol actual es: <code><?php echo htmlspecialchars($userRole ?: 'Sin rol'); ?></code>
                </div>
                <?php
endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="usersTable">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 rounded-start">Nombre</th>
                                <th class="border-0">Email</th>
                                <th class="border-0">Rol</th>
                                <th class="border-0">Estado</th>
                                <th class="border-0">Fecha Creación</th>
                                <?php if ($isAdmin): ?>
                                <th class="border-0 rounded-end">Acciones</th>
                                <?php
endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users_result as $user): ?>
                            <tr>
                                <td data-label="Usuario" class="text-end text-md-start">
                                    <div class="d-flex align-items-center justify-content-end justify-content-md-start">
                                        <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; overflow: hidden;">
                                            <?php
    require_once __DIR__ . '/../config/app_config.php';
    require_once __DIR__ . '/../config/functions.php';
    $baseUploadsFs = __DIR__ . '/../uploads/';
    $tenantUploadsFs = getTenantUploadDir($baseUploadsFs);
    $tenantId = $_SESSION['tenant_id'] ?? null;
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $uploadsScopeId = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)($tenantId ?? 0);
    if ($uploadsScopeId <= 0) { $uploadsScopeId = (int)($tenantId ?? 0); }
    $pathBase = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';
    $photoName = $user['photo'] ?? '';
    $tenantFsPath = rtrim($tenantUploadsFs, '/\\') . '/users/' . $photoName;
    $sharedFsPath = rtrim($baseUploadsFs, '/\\') . '/users/' . $photoName;
    $userPhotoPath = file_exists($tenantFsPath) ? $tenantFsPath : (file_exists($sharedFsPath) ? $sharedFsPath : '');
    $userPhotoUrl = file_exists($tenantFsPath)
        ? (rtrim($pathBase, '/') . '/uploads/' . ($uploadsScopeId ? $uploadsScopeId . '/' : '') . 'users/' . $photoName)
        : (file_exists($sharedFsPath)
        ? (rtrim($pathBase, '/') . '/uploads/users/' . $photoName)
        : '');
?>
                                            <?php if (!empty($photoName) && !empty($userPhotoPath) && !empty($userPhotoUrl)): ?>
                                                <img src="<?php echo htmlspecialchars($userPhotoUrl); ?>?v=<?php echo time(); ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php
    else: ?>
                                                <?php
        $displayName = trim($user['name'] ?? '');
        $initial = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'U';
?>
                                                <div class="d-flex align-items-center justify-content-center rounded-circle bg-secondary text-white fw-bold" style="width: 100%; height: 100%;">
                                                    <?php echo htmlspecialchars($initial); ?>
                                                </div>
                                            <?php
    endif; ?>
                                        </div>
                                        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                    </div>
                                </td>
                                <td data-label="Email" class="text-end text-md-start text-break"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td data-label="Rol" class="text-end text-md-start">
                                    <span class="badge rounded-pill bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'info'; ?> bg-opacity-10 text-<?php echo $user['role'] === 'admin' ? 'danger' : 'info'; ?> px-3 py-2">
                                        <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'crown' : 'user'; ?> me-1"></i>
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td data-label="Estado" class="text-end text-md-start">
                                    <span class="badge rounded-pill bg-<?php echo $user['active'] ? 'success' : 'danger'; ?> bg-opacity-10 text-<?php echo $user['active'] ? 'success' : 'danger'; ?> px-3 py-2">
                                        <i class="fas fa-<?php echo $user['active'] ? 'check' : 'times'; ?> me-1"></i>
                                        <?php echo $user['active'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td data-label="Registro" class="text-muted text-end text-md-start"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                                <?php if ($isAdmin): ?>
                                <td data-label="Acciones" class="text-end text-md-start">
                                    <div class="btn-group w-100 w-md-auto justify-content-end" role="group">
                                        <button class="btn btn-sm btn-outline-primary rounded-start" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo $user['role']; ?>', <?php echo $user['active']; ?>, '<?php echo htmlspecialchars($user['photo'] ?? ''); ?>')" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" onclick="changePassword(<?php echo $user['id']; ?>)" title="Cambiar Contraseña">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-outline-danger rounded-end" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php
        endif; ?>
                                    </div>
                                </td>
                                <?php
    endif; ?>
                            </tr>
                            <?php
endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
