<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/crypto.php';

$pdo = function_exists('db') ? db() : $pdo;

$sa_title = 'Bolsa de Bases (Pool)';
$sa_active = 'db_pool';

$message = '';
$messageType = 'info';

function saCsrfOk(): bool
{
    $posted = $_POST['csrf_token_sa'] ?? '';
    $session = $_SESSION['csrf_token_sa'] ?? '';
    return is_string($posted) && is_string($session) && $posted !== '' && $session !== '' && hash_equals($session, $posted);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!saCsrfOk()) {
        $message = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'sync_from_empresas') {
            try {
                $existing = [];
                try {
                    $rows = $pdo->query("SELECT db_name FROM tenant_db_pool")->fetchAll(PDO::FETCH_COLUMN);
                    foreach (($rows ?: []) as $n) {
                        $n = (string)$n;
                        if ($n !== '') {
                            $existing[$n] = true;
                        }
                    }
                } catch (Throwable $e) {
                }

                $empRows = $pdo->query("
                    SELECT
                        id AS empresa_id,
                        db_host,
                        db_port,
                        db_name,
                        db_user,
                        db_password_enc,
                        db_password_iv,
                        db_password_tag,
                        created_at
                    FROM empresas
                    WHERE estado <> 'deleted'
                    ORDER BY id ASC
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $added = 0;
                $skipped = 0;
                $stmt = $pdo->prepare("
                    INSERT INTO tenant_db_pool
                        (db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, status, empresa_id, reserved_at, used_at, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, 'used', ?, NULL, NOW(), NOW(), NOW())
                ");
                foreach ($empRows as $r) {
                    $dbName = (string)($r['db_name'] ?? '');
                    $dbUser = (string)($r['db_user'] ?? '');
                    if ($dbName === '' || $dbUser === '') {
                        $skipped++;
                        continue;
                    }
                    if (isset($existing[$dbName])) {
                        $skipped++;
                        continue;
                    }
                    $stmt->execute([
                        (string)($r['db_host'] ?? 'localhost'),
                        (int)($r['db_port'] ?? 3306),
                        $dbName,
                        $dbUser,
                        (string)($r['db_password_enc'] ?? ''),
                        (string)($r['db_password_iv'] ?? ''),
                        (string)($r['db_password_tag'] ?? ''),
                        (int)($r['empresa_id'] ?? 0),
                    ]);
                    $existing[$dbName] = true;
                    $added++;
                }
                $message = "Sincronización completa. Agregadas: {$added}. Omitidas: {$skipped}.";
                $messageType = $added > 0 ? 'success' : 'info';
            } catch (Throwable $e) {
                $message = 'No se pudo sincronizar desde empresas.';
                $messageType = 'danger';
            }
        } elseif ($action === 'add') {
            $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
            $dbPort = (int)($_POST['db_port'] ?? 3306);
            $dbName = trim((string)($_POST['db_name'] ?? ''));
            $dbUser = trim((string)($_POST['db_user'] ?? ''));
            $dbPass = (string)($_POST['db_pass'] ?? '');

            if ($dbName === '' || $dbUser === '' || $dbPass === '') {
                $message = 'Completa db_name, db_user y db_pass.';
                $messageType = 'danger';
            } else {
                try {
                    $enc = Crypto::encrypt($dbPass);
                    $stmt = $pdo->prepare("
                        INSERT INTO tenant_db_pool (db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'available', NOW(), NOW())
                    ");
                    $stmt->execute([
                        $dbHost !== '' ? $dbHost : 'localhost',
                        $dbPort > 0 ? $dbPort : 3306,
                        $dbName,
                        $dbUser,
                        $enc['enc'],
                        $enc['iv'],
                        $enc['tag']
                    ]);
                    $message = 'Base agregada al pool.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $message = 'No se pudo agregar. Verifica que la base no exista ya en el pool.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'mark_available') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE tenant_db_pool SET status = 'available', empresa_id = NULL, reserved_at = NULL, used_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ? AND status <> 'used'");
                    $stmt->execute([$id]);
                    $message = 'Marcado como disponible.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $message = 'No se pudo actualizar.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$stats = [];
try {
    $rows = $pdo->query("SELECT status, COUNT(*) as c FROM tenant_db_pool GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $stats[(string)($r['status'] ?? '')] = (int)($r['c'] ?? 0);
    }
} catch (Throwable $e) {
}

$empresasCount = 0;
try {
    $empresasCount = (int)($pdo->query("SELECT COUNT(*) FROM empresas WHERE estado <> 'deleted'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
}

$items = [];
try {
    $items = $pdo->query("
        SELECT
            p.id,
            p.db_host,
            p.db_port,
            p.db_name,
            p.db_user,
            p.status,
            p.empresa_id,
            e.nombre AS empresa_nombre,
            p.reserved_at,
            p.used_at,
            p.created_at,
            p.last_error
        FROM tenant_db_pool p
        LEFT JOIN empresas e ON e.id = p.empresa_id
        ORDER BY p.id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Pool de Bases - CORE</title>
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

            <?php if ($empresasCount > 0 && empty($items)): ?>
                <div class="alert alert-warning border-0 shadow-sm mb-4">
                    El pool está vacío, pero existen <?php echo (int)$empresasCount; ?> empresas. Puedes sincronizar las bases asignadas a empresas para que se vean aquí como <strong>used</strong>.
                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                        <input type="hidden" name="action" value="sync_from_empresas">
                        <button type="submit" class="btn btn-dark rounded-pill px-4">
                            <i class="fas fa-rotate me-2"></i>Sincronizar desde empresas
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase">Disponibles</div>
                                    <div class="fs-3 fw-bold"><?php echo (int)($stats['available'] ?? 0); ?></div>
                                </div>
                                <div class="text-success"><i class="fas fa-circle-check fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase">Reservadas</div>
                                    <div class="fs-3 fw-bold"><?php echo (int)($stats['reserved'] ?? 0); ?></div>
                                </div>
                                <div class="text-warning"><i class="fas fa-hourglass-half fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase">Usadas</div>
                                    <div class="fs-3 fw-bold"><?php echo (int)($stats['used'] ?? 0); ?></div>
                                </div>
                                <div class="text-primary"><i class="fas fa-database fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase">Error</div>
                                    <div class="fs-3 fw-bold"><?php echo (int)($stats['error'] ?? 0); ?></div>
                                </div>
                                <div class="text-danger"><i class="fas fa-triangle-exclamation fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Agregar Base al Pool</h5>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                        <input type="hidden" name="action" value="add">
                        <div class="col-md-3">
                            <label class="form-label">Host</label>
                            <input type="text" name="db_host" class="form-control bg-light" value="localhost">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="db_port" class="form-control bg-light" value="3306">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">DB Name (Hostinger)</label>
                            <input type="text" name="db_name" class="form-control bg-light" placeholder="u123456_tenant01" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">DB User</label>
                            <input type="text" name="db_user" class="form-control bg-light" placeholder="u123456_user" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">DB Pass</label>
                            <input type="password" name="db_pass" class="form-control bg-light" required>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary rounded-pill px-4">
                                <i class="fas fa-plus me-2"></i>Agregar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold">Bases en Pool</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>DB</th>
                                    <th>Usuario</th>
                                    <th>Host</th>
                                    <th>Status</th>
                                    <th>Empresa</th>
                                    <th>Reservada</th>
                                    <th>Usada</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <?php
                                        $st = (string)($it['status'] ?? '');
                                        $badge = 'secondary';
                                        if ($st === 'available') $badge = 'success';
                                        elseif ($st === 'reserved') $badge = 'warning';
                                        elseif ($st === 'used') $badge = 'primary';
                                        elseif ($st === 'error') $badge = 'danger';
                                    ?>
                                    <tr>
                                        <td><?php echo (int)($it['id'] ?? 0); ?></td>
                                        <td><span class="font-monospace"><?php echo htmlspecialchars((string)($it['db_name'] ?? '')); ?></span></td>
                                        <td><span class="font-monospace"><?php echo htmlspecialchars((string)($it['db_user'] ?? '')); ?></span></td>
                                        <td><span class="font-monospace"><?php echo htmlspecialchars((string)($it['db_host'] ?? '')); ?>:<?php echo (int)($it['db_port'] ?? 3306); ?></span></td>
                                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                                        <td>
                                            <?php
                                                $eid = (int)($it['empresa_id'] ?? 0);
                                                $enm = (string)($it['empresa_nombre'] ?? '');
                                                echo $eid > 0 ? htmlspecialchars($enm !== '' ? ($enm . " (ID {$eid})") : "ID {$eid}") : '—';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)($it['reserved_at'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($it['used_at'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <?php if ($st !== 'used'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="mark_available">
                                                    <input type="hidden" name="id" value="<?php echo (int)($it['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">Disponible</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($it['last_error'])): ?>
                                        <tr class="table-light">
                                            <td colspan="9" class="small text-danger px-4 py-2">
                                                <?php echo htmlspecialchars((string)$it['last_error']); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!$items): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No hay registros.</td>
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
