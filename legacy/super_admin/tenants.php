<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$sa_title = 'Empresas';
$sa_active = 'tenants';

$isPerDatabaseMode = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if (!$isPerDatabaseMode) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config/database_manager.php';
require_once __DIR__ . '/../config/crypto.php';

function saCsrfOk(): bool
{
    $posted = $_POST['csrf_token_sa'] ?? '';
    $session = $_SESSION['csrf_token_sa'] ?? '';
    return is_string($posted) && is_string($session) && $posted !== '' && $session !== '' && hash_equals($session, $posted);
}

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!saCsrfOk()) {
        $message = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $message = 'Empresa inválida.';
            $messageType = 'danger';
        } else {
            try {
                $master = DatabaseManager::master();
                if ($action === 'generate_assign_license') {
                    $code = generateSaasLicenseCode();
                    $stmt = $master->prepare("INSERT INTO licencias (codigo, plan, estado, empresa_id, used_at, created_at, updated_at) VALUES (?, 'standard', 'usada', ?, NOW(), NOW(), NOW())");
                    $stmt->execute([$code, $tenantId]);
                    $message = 'Licencia generada y asignada.';
                    $messageType = 'success';
                } elseif ($action === 'assign_license') {
                    $code = strtoupper(trim((string)($_POST['license_code'] ?? '')));
                    if ($code === '') {
                        throw new RuntimeException('Ingresa un código de licencia.');
                    }
                    $stmt = $master->prepare("UPDATE licencias SET estado = 'usada', empresa_id = ?, used_at = NOW(), updated_at = NOW() WHERE codigo = ? AND estado = 'disponible' AND empresa_id IS NULL");
                    $stmt->execute([$tenantId, $code]);
                    if ($stmt->rowCount() <= 0) {
                        throw new RuntimeException('Licencia no disponible o ya usada.');
                    }
                    $message = 'Licencia asignada.';
                    $messageType = 'success';
                } elseif ($action === 'suspend' || $action === 'activate') {
                    $newState = $action === 'activate' ? 'active' : 'suspended';
                    $stmt = $master->prepare("UPDATE empresas SET estado = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                    $stmt->execute([$newState, $tenantId]);
                    $message = $newState === 'active' ? 'Empresa activada.' : 'Empresa suspendida.';
                    $messageType = 'success';
                }
            } catch (Throwable $e) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la acción.';
                $messageType = 'danger';
            }
        }
    }
}

$tenants = [];
try {
    $master = DatabaseManager::master();
    $stmt = $master->query("
        SELECT
            e.id,
            e.nombre,
            e.estado,
            e.db_host,
            e.db_port,
            e.db_name,
            e.db_user,
            e.created_at,
            (
                SELECT COUNT(*)
                FROM licencias l
                WHERE l.empresa_id = e.id AND l.estado = 'usada'
            ) AS license_count,
            (
                SELECT l.codigo
                FROM licencias l
                WHERE l.empresa_id = e.id AND l.estado = 'usada'
                ORDER BY l.used_at DESC, l.id DESC
                LIMIT 1
            ) AS last_license
        FROM empresas e
        WHERE e.estado <> 'deleted'
        ORDER BY e.created_at DESC
    ");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

$availableLicenses = [];
try {
    $master = DatabaseManager::master();
    $availableLicenses = $master->query("SELECT codigo FROM licencias WHERE estado = 'disponible' AND empresa_id IS NULL ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
    <title>Empresas - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/sidebar_common.php'; ?>
    <div class="main-content">
        <?php include __DIR__ . '/header_common.php'; ?>
        <div class="container-fluid p-4">
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm mb-4">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="fw-bold text-dark mb-1">Empresas</h5>
                    <p class="text-muted small mb-0">Licencias y direccionamiento a bases (Hostinger Pool).</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">Empresa</th>
                                    <th>Estado</th>
                                    <th>Licencia</th>
                                    <th>DB</th>
                                    <th>Creación</th>
                                    <th class="text-end pe-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tenants as $t): ?>
                                    <?php
                                        $id = (int)($t['id'] ?? 0);
                                        $estado = (string)($t['estado'] ?? 'active');
                                        $licCount = (int)($t['license_count'] ?? 0);
                                        $licCode = (string)($t['last_license'] ?? '');
                                        $statusBadge = $estado === 'active' ? 'success' : ($estado === 'suspended' ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars((string)($t['nombre'] ?? '')); ?></div>
                                            <div class="text-muted small">ID <?php echo $id; ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $statusBadge; ?> bg-opacity-10 text-<?php echo $statusBadge; ?> rounded-pill px-3">
                                                <?php echo htmlspecialchars($estado); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($licCount > 0): ?>
                                                <div class="fw-bold"><code><?php echo htmlspecialchars($licCode); ?></code></div>
                                                <div class="text-muted small"><?php echo $licCount; ?> asignada(s)</div>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3">Sin licencia</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="text-muted small font-monospace"><?php echo htmlspecialchars((string)($t['db_host'] ?? '')); ?>:<?php echo (int)($t['db_port'] ?? 3306); ?></div>
                                            <div class="font-monospace"><?php echo htmlspecialchars((string)($t['db_name'] ?? '')); ?></div>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($t['created_at'] ?? '')); ?></td>
                                        <td class="text-end pe-4">
                                            <a class="btn btn-sm btn-outline-dark rounded-pill" href="tenant_edit.php?id=<?php echo $id; ?>">
                                                <i class="fas fa-pen me-1"></i>Editar
                                            </a>
                                            <?php if ($estado === 'active'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="suspend">
                                                    <input type="hidden" name="tenant_id" value="<?php echo $id; ?>">
                                                    <button class="btn btn-sm btn-outline-warning rounded-pill" type="submit">Suspender</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="tenant_id" value="<?php echo $id; ?>">
                                                    <button class="btn btn-sm btn-outline-success rounded-pill" type="submit">Activar</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($licCount === 0): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="generate_assign_license">
                                                    <input type="hidden" name="tenant_id" value="<?php echo $id; ?>">
                                                    <button class="btn btn-sm btn-dark rounded-pill" type="submit">Generar licencia</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($licCount === 0 && !empty($availableLicenses)): ?>
                                        <tr class="bg-light">
                                            <td colspan="6" class="px-4 py-3">
                                                <form method="post" class="row g-2 align-items-center">
                                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="assign_license">
                                                    <input type="hidden" name="tenant_id" value="<?php echo $id; ?>">
                                                    <div class="col-auto text-muted small">Asignar licencia:</div>
                                                    <div class="col-auto">
                                                        <select name="license_code" class="form-select form-select-sm">
                                                            <?php foreach ($availableLicenses as $lc): ?>
                                                                <option value="<?php echo htmlspecialchars((string)$lc); ?>"><?php echo htmlspecialchars((string)$lc); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-auto">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Asignar</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (empty($tenants)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-building fa-3x mb-3 d-block opacity-25"></i>
                                            No hay empresas.
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
</body>
</html>
