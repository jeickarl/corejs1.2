<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/functions.php';

$sa_title = 'Editar Empresa';
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

$tenantId = (int)($_GET['id'] ?? 0);
if ($tenantId <= 0) {
    header('Location: tenants.php');
    exit;
}

$message = '';
$messageType = 'info';

try {
    $master = DatabaseManager::master();
    $stmt = $master->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        header('Location: tenants.php');
        exit;
    }
} catch (Throwable $e) {
    header('Location: tenants.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!saCsrfOk()) {
        $message = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            $master = DatabaseManager::master();
            if ($action === 'update_tenant') {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $estado = (string)($_POST['estado'] ?? 'active');
                $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
                $dbPort = (int)($_POST['db_port'] ?? 3306);
                $dbName = trim((string)($_POST['db_name'] ?? ''));
                $dbUser = trim((string)($_POST['db_user'] ?? ''));
                $newPass = (string)($_POST['db_pass'] ?? '');

                if ($nombre === '' || $dbName === '' || $dbUser === '') {
                    throw new RuntimeException('Completa nombre, db_name y db_user.');
                }
                if (!in_array($estado, ['active', 'suspended', 'provisioning'], true)) {
                    $estado = 'active';
                }
                if ($dbPort <= 0) {
                    $dbPort = 3306;
                }

                $enc = null;
                if ($newPass !== '') {
                    $enc = Crypto::encrypt($newPass);
                }

                if ($enc) {
                    $upd = $master->prepare("
                        UPDATE empresas
                        SET nombre = ?, estado = ?, db_host = ?, db_port = ?, db_name = ?, db_user = ?,
                            db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW()
                        WHERE id = ? LIMIT 1
                    ");
                    $upd->execute([
                        $nombre,
                        $estado,
                        $dbHost !== '' ? $dbHost : 'localhost',
                        $dbPort,
                        $dbName,
                        $dbUser,
                        $enc['enc'],
                        $enc['iv'],
                        $enc['tag'],
                        $tenantId
                    ]);
                } else {
                    $upd = $master->prepare("
                        UPDATE empresas
                        SET nombre = ?, estado = ?, db_host = ?, db_port = ?, db_name = ?, db_user = ?, updated_at = NOW()
                        WHERE id = ? LIMIT 1
                    ");
                    $upd->execute([
                        $nombre,
                        $estado,
                        $dbHost !== '' ? $dbHost : 'localhost',
                        $dbPort,
                        $dbName,
                        $dbUser,
                        $tenantId
                    ]);
                }

                $message = 'Empresa actualizada.';
                $messageType = 'success';
            } elseif ($action === 'assign_pool') {
                $poolId = (int)($_POST['pool_id'] ?? 0);
                if ($poolId <= 0) {
                    throw new RuntimeException('Selecciona una base del pool.');
                }
                $master->beginTransaction();
                $row = $master->prepare("SELECT * FROM tenant_db_pool WHERE id = ? FOR UPDATE");
                $row->execute([$poolId]);
                $pool = $row->fetch(PDO::FETCH_ASSOC);
                if (!$pool) {
                    throw new RuntimeException('Base no encontrada en pool.');
                }
                $status = (string)($pool['status'] ?? '');
                $poolEmpresa = (int)($pool['empresa_id'] ?? 0);
                if (!in_array($status, ['available', 'reserved'], true)) {
                    throw new RuntimeException('Esta base del pool no está disponible.');
                }
                if ($status === 'reserved' && $poolEmpresa !== $tenantId) {
                    throw new RuntimeException('Esta base está reservada para otra empresa.');
                }

                $pass = Crypto::decrypt((string)$pool['db_password_enc'], (string)$pool['db_password_iv'], (string)$pool['db_password_tag']);
                $enc = Crypto::encrypt($pass);

                $upd = $master->prepare("
                    UPDATE empresas
                    SET db_host = ?, db_port = ?, db_name = ?, db_user = ?,
                        db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW()
                    WHERE id = ? LIMIT 1
                ");
                $upd->execute([
                    (string)($pool['db_host'] ?? 'localhost'),
                    (int)($pool['db_port'] ?? 3306),
                    (string)($pool['db_name'] ?? ''),
                    (string)($pool['db_user'] ?? ''),
                    $enc['enc'],
                    $enc['iv'],
                    $enc['tag'],
                    $tenantId
                ]);

                $mark = $master->prepare("UPDATE tenant_db_pool SET status = 'used', empresa_id = ?, used_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1");
                $mark->execute([$tenantId, $poolId]);
                $master->commit();

                $message = 'Base del pool asignada a la empresa.';
                $messageType = 'success';
            } elseif ($action === 'generate_assign_license') {
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
            } elseif ($action === 'test_db') {
                $dbHost = trim((string)($_POST['db_host'] ?? (string)($tenant['db_host'] ?? 'localhost')));
                $dbPort = (int)($_POST['db_port'] ?? (int)($tenant['db_port'] ?? 3306));
                $dbName = trim((string)($_POST['db_name'] ?? (string)($tenant['db_name'] ?? '')));
                $dbUser = trim((string)($_POST['db_user'] ?? (string)($tenant['db_user'] ?? '')));
                $newPass = (string)($_POST['db_pass'] ?? '');
                $pass = $newPass !== '' ? $newPass : Crypto::decrypt((string)$tenant['db_password_enc'], (string)$tenant['db_password_iv'], (string)$tenant['db_password_tag']);
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdoTest = new PDO($dsn, $dbUser, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $pdoTest->query('SELECT 1');
                $message = 'Conexión OK.';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            try {
                $m = DatabaseManager::master();
                if ($m->inTransaction()) {
                    $m->rollBack();
                }
            } catch (Throwable $ignored) {
            }
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la acción.';
            $messageType = 'danger';
        }

        try {
            $master = DatabaseManager::master();
            $stmt = $master->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
            $stmt->execute([$tenantId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tenant;
        } catch (Throwable $e) {
        }
    }
}

$licenseRows = [];
$availableLicenses = [];
try {
    $master = DatabaseManager::master();
    $stmt = $master->prepare("SELECT id, codigo, plan, estado, used_at, created_at FROM licencias WHERE empresa_id = ? ORDER BY used_at DESC, id DESC");
    $stmt->execute([$tenantId]);
    $licenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availableLicenses = $master->query("SELECT codigo FROM licencias WHERE estado = 'disponible' AND empresa_id IS NULL ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}

$poolItems = [];
try {
    $master = DatabaseManager::master();
    $stmt = $master->prepare("SELECT id, db_host, db_port, db_name, db_user, status, empresa_id FROM tenant_db_pool WHERE status IN ('available','reserved') ORDER BY id DESC LIMIT 200");
    $stmt->execute();
    $poolItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    <title>Editar Empresa - CORE</title>
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
                    <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars((string)($tenant['nombre'] ?? '')); ?></h5>
                    <p class="text-muted small mb-0">ID <?php echo (int)$tenantId; ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary rounded-pill" href="tenants.php"><i class="fas fa-arrow-left me-2"></i>Volver</a>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0 py-3">
                            <h6 class="mb-0 fw-bold">Datos y Conexión de Base</h6>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                <input type="hidden" name="action" value="update_tenant">
                                <div class="col-md-7">
                                    <label class="form-label">Nombre</label>
                                    <input type="text" name="nombre" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['nombre'] ?? '')); ?>" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Estado</label>
                                    <select name="estado" class="form-select bg-light">
                                        <?php $estado = (string)($tenant['estado'] ?? 'active'); ?>
                                        <option value="active" <?php echo $estado==='active'?'selected':''; ?>>active</option>
                                        <option value="suspended" <?php echo $estado==='suspended'?'selected':''; ?>>suspended</option>
                                        <option value="provisioning" <?php echo $estado==='provisioning'?'selected':''; ?>>provisioning</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">DB Host</label>
                                    <input type="text" name="db_host" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_host'] ?? 'localhost')); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">DB Port</label>
                                    <input type="number" name="db_port" class="form-control bg-light" value="<?php echo (int)($tenant['db_port'] ?? 3306); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB Name (Hostinger)</label>
                                    <input type="text" name="db_name" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_name'] ?? '')); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB User</label>
                                    <input type="text" name="db_user" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_user'] ?? '')); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB Pass (solo si deseas cambiarla)</label>
                                    <input type="password" name="db_pass" class="form-control bg-light" value="">
                                </div>
                                <div class="col-12 d-flex justify-content-end gap-2">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-2"></i>Guardar</button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                <input type="hidden" name="action" value="test_db">
                                <div class="col-md-4">
                                    <label class="form-label">Host</label>
                                    <input type="text" name="db_host" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_host'] ?? 'localhost')); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Port</label>
                                    <input type="number" name="db_port" class="form-control bg-light" value="<?php echo (int)($tenant['db_port'] ?? 3306); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB</label>
                                    <input type="text" name="db_name" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_name'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">User</label>
                                    <input type="text" name="db_user" class="form-control bg-light" value="<?php echo htmlspecialchars((string)($tenant['db_user'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Pass (opcional)</label>
                                    <input type="password" name="db_pass" class="form-control bg-light" placeholder="Si se deja vacío usa la guardada">
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-outline-dark rounded-pill px-4"><i class="fas fa-plug me-2"></i>Probar conexión</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white border-0 py-3">
                            <h6 class="mb-0 fw-bold">Asignar Base desde Pool</h6>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                <input type="hidden" name="action" value="assign_pool">
                                <div class="col-md-10">
                                    <label class="form-label">Pool</label>
                                    <select name="pool_id" class="form-select bg-light">
                                        <option value="">-- Selecciona --</option>
                                        <?php foreach ($poolItems as $p): ?>
                                            <?php
                                                $pid = (int)($p['id'] ?? 0);
                                                $st = (string)($p['status'] ?? '');
                                                $pidEmpresa = (int)($p['empresa_id'] ?? 0);
                                                $disabled = ($st === 'reserved' && $pidEmpresa !== $tenantId) ? 'disabled' : '';
                                                $label = (string)($p['db_name'] ?? '') . ' · ' . (string)($p['db_user'] ?? '') . ' · ' . (string)($p['db_host'] ?? '') . ':' . (int)($p['db_port'] ?? 3306) . " · {$st}";
                                                if ($st === 'reserved' && $pidEmpresa === $tenantId) {
                                                    $label .= ' (reservada para esta empresa)';
                                                }
                                            ?>
                                            <option value="<?php echo $pid; ?>" <?php echo $disabled; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-outline-primary w-100">Asignar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0 py-3">
                            <h6 class="mb-0 fw-bold">Licencias</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex gap-2 mb-3">
                                <form method="post">
                                    <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="generate_assign_license">
                                    <button type="submit" class="btn btn-dark rounded-pill">Generar y asignar</button>
                                </form>
                                <?php if (!empty($availableLicenses)): ?>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                        <input type="hidden" name="action" value="assign_license">
                                        <select name="license_code" class="form-select bg-light">
                                            <?php foreach ($availableLicenses as $lc): ?>
                                                <option value="<?php echo htmlspecialchars((string)$lc); ?>"><?php echo htmlspecialchars((string)$lc); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-primary rounded-pill">Asignar</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Código</th>
                                            <th>Plan</th>
                                            <th>Estado</th>
                                            <th>Usada</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($licenseRows as $lr): ?>
                                            <tr>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($lr['codigo'] ?? '')); ?></td>
                                                <td class="text-muted"><?php echo htmlspecialchars((string)($lr['plan'] ?? 'standard')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($lr['estado'] ?? '')); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($lr['used_at'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($licenseRows)): ?>
                                            <tr>
                                                <td colspan="4" class="text-muted text-center py-4">Sin licencias asignadas.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
