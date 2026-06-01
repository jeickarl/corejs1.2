<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$licenses = [];
if (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) {
    require_once __DIR__ . '/../config/database_manager.php';
    $master = DatabaseManager::master();
    $stmt = $master->query("
        SELECT
            l.id,
            l.codigo AS license_code,
            CASE
                WHEN l.estado = 'disponible' THEN 'active'
                WHEN l.estado = 'usada' THEN 'used'
                ELSE 'expired'
            END AS status,
            l.plan AS license_type,
            l.empresa_id AS tenant_id,
            l.created_at,
            l.used_at,
            NULL AS expires_at,
            e.nombre AS company_name
        FROM licencias l
        LEFT JOIN empresas e ON e.id = l.empresa_id
        ORDER BY l.created_at DESC
    ");
    $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_licenses (
            id INT NOT NULL AUTO_INCREMENT,
            license_code VARCHAR(50) NOT NULL UNIQUE,
            status ENUM('active','used','expired') DEFAULT 'active',
            license_type ENUM('standard','trial') NOT NULL DEFAULT 'standard',
            tenant_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL DEFAULT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY tenant_id (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
    } catch (Exception $e) {}

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM saas_licenses")->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('license_type', array_map('strtolower', $cols))) {
            $pdo->exec("ALTER TABLE saas_licenses ADD COLUMN license_type ENUM('standard','trial') NOT NULL DEFAULT 'standard' AFTER status");
        }
        if ($cols && !in_array('expires_at', array_map('strtolower', $cols))) {
            $pdo->exec("ALTER TABLE saas_licenses ADD COLUMN expires_at DATETIME NULL DEFAULT NULL AFTER used_at");
        }
    } catch (Exception $e) {}

    $stmt = $pdo->query("SELECT l.*, t.company_name 
                         FROM saas_licenses l 
                         LEFT JOIN tenants t ON l.tenant_id = t.id 
                         ORDER BY l.created_at DESC");
    $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check messages
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
    <title>Gestión de Licencias - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Modern UI -->
        <link rel="stylesheet" href="../assets/css/super_admin.css?v=<?php echo time(); ?>">

    <style>
    </style>
</head>
<body class="bg-light">

    <?php $sa_active = 'licenses'; include __DIR__ . '/sidebar_common.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <?php $sa_title = 'Gestión de Licencias'; include __DIR__ . '/header_common.php'; ?>

        <!-- Content -->
        <div class="container-fluid p-4">

            <?php if ($msg === 'created'): ?>
                <div class="alert alert-success border-0 shadow-sm mb-4"><i class="fas fa-check-circle me-2"></i>Nueva licencia generada exitosamente.</div>
            <?php elseif ($msg === 'deleted'): ?>
                <div class="alert alert-success border-0 shadow-sm mb-4"><i class="fas fa-check-circle me-2"></i>Licencia eliminada.</div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="fw-bold text-dark mb-1">Licencias del Sistema</h5>
                    <p class="text-muted small mb-0">Administra las llaves de activación para las empresas.</p>
                </div>
                <div class="d-flex gap-2">
                    <form action="actions.php" method="POST">
                        <input type="hidden" name="action" value="create_license">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                        <button type="submit" class="btn btn-dark shadow-sm px-4">
                            <i class="fas fa-plus me-2"></i>Generar Nueva
                        </button>
                    </form>
                    <form action="actions.php" method="POST">
                        <input type="hidden" name="action" value="create_trial_license">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                        <button type="submit" class="btn btn-outline-primary shadow-sm px-4">
                            <i class="fas fa-clock me-2"></i>Generar Trial 7 días
                        </button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">Código de Licencia</th>
                                    <th>Estado</th>
                                    <th>Tipo</th>
                                    <th>Expira</th>
                                    <th>Asignada a</th>
                                    <th>Creación</th>
                                    <th class="text-end pe-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licenses as $lic): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-box bg-light text-primary rounded-circle p-2 me-3" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-key fa-sm"></i>
                                            </div>
                                            <code class="fw-bold fs-6 text-dark"><?php echo htmlspecialchars($lic['license_code']); ?></code>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($lic['status'] === 'active'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Disponible</span>
                                        <?php elseif ($lic['status'] === 'used'): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">En Uso</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill"><?php echo $lic['status']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark rounded-pill px-3"><?php echo htmlspecialchars($lic['license_type'] ?? 'standard'); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                            $exp = $lic['expires_at'] ? date('d/m/Y H:i', strtotime($lic['expires_at'])) : '—';
                                            echo $exp;
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($lic['company_name']): ?>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($lic['company_name']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic small">-- Sin asignar --</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($lic['created_at'])); ?></td>
                                    <td class="text-end pe-4">
                                        <?php if ($lic['status'] !== 'used'): ?>
                                        <form action="actions.php" method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="delete_license">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$lic['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('¿Eliminar esta licencia?')" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted small" title="No se puede borrar una licencia en uso"><i class="fas fa-lock"></i></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($licenses)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-key fa-3x mb-3 d-block opacity-25"></i>
                                        No hay licencias generadas.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script></script>
</body>
</html>
