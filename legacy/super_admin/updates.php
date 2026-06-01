<?php
require_once 'auth.php';

$message = '';
$type = 'info';
$results = [];
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

function saCsrfOk(): bool
{
    $posted = $_POST['csrf_token_sa'] ?? '';
    $session = $_SESSION['csrf_token_sa'] ?? '';
    return is_string($posted) && is_string($session) && $posted !== '' && $session !== '' && hash_equals($session, $posted);
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql_command'])) {
    if (!saCsrfOk()) {
        $message = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
        $type = 'danger';
    } else {
    $sql = trim($_POST['sql_command']);
    $action = $_POST['action'] ?? 'simulate'; // simulate | execute

    if (empty($sql)) {
        $message = "El comando SQL no puede estar vacío.";
        $type = 'danger';
    } else {
        // Validación básica de seguridad (muy permisiva porque es Super Admin)
        // Bloquear DROP DATABASE o comandos extremadamente destructivos si se desea, 
        // pero el Super Admin debería tener poder total.
        
        try {
            if ($perDatabase) {
                require_once __DIR__ . '/../config/env_loader.php';
                require_once __DIR__ . '/../config/database_manager.php';

                $master = DatabaseManager::master();
                $empresas = $master->query("SELECT id, nombre, estado, db_name FROM empresas WHERE estado <> 'deleted' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (empty($empresas)) {
                    $message = "No hay empresas registradas para actualizar.";
                    $type = 'warning';
                } else {
                    $successCount = 0;
                    $failCount = 0;
                    foreach ($empresas as $emp) {
                        $eid = (int)($emp['id'] ?? 0);
                        $res = [
                            'company' => (string)($emp['nombre'] ?? ''),
                            'db' => (string)($emp['db_name'] ?? ''),
                            'status' => 'info',
                            'msg' => 'Simulación: listo para ejecutar.'
                        ];
                        try {
                            $tpdo = DatabaseManager::tenant($eid);
                            if ($action === 'execute') {
                                $tpdo->exec($sql);
                                $res['status'] = 'success';
                                $res['msg'] = 'Ejecutado.';
                                $successCount++;
                            } else {
                                $res['status'] = 'info';
                                $res['msg'] = 'Simulación: OK.';
                            }
                        } catch (Throwable $e) {
                            $failCount++;
                            $res['status'] = 'danger';
                            $res['msg'] = 'Error: ' . $e->getMessage();
                        }
                        $results[] = $res;
                    }

                    if ($action === 'execute') {
                        $message = "Actualización aplicada. OK={$successCount}, fallas={$failCount}.";
                        $type = ($failCount > 0) ? 'warning' : 'success';
                    } else {
                        $message = "Simulación completada. Empresas evaluadas=" . count($results) . ".";
                        $type = 'info';
                    }
                }
            } else {
                $stmt = $pdo->query("SELECT id, company_name, slug AS db_name FROM tenants");
                $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($tenants)) {
                    $message = "No hay empresas registradas para actualizar.";
                    $type = 'warning';
                } else {
                    if ($action === 'execute') {
                        try {
                            $pdo->exec($sql);
                            $message = "Actualización aplicada en base compartida.";
                            $type = 'success';
                        } catch (PDOException $e) {
                            $message = "Error al ejecutar en base compartida: " . $e->getMessage();
                            $type = 'danger';
                        }
                    } else {
                        $message = "Simulación completada. Listo para ejecutar en base compartida.";
                        $type = 'info';
                    }

                    foreach ($tenants as $tenant) {
                        $res = [
                            'company' => $tenant['company_name'],
                            'db' => $tenant['db_name'],
                            'status' => ($action === 'execute' ? 'success' : 'info'),
                            'msg' => ($action === 'execute' ? 'Ejecutado en base compartida.' : 'Simulación: Base compartida, listo para ejecutar.')
                        ];
                        $results[] = $res;
                    }
                }
            }
        } catch (Exception $e) {
            $message = "Error general: " . $e->getMessage();
            $type = 'danger';
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualizaciones DB - Super Admin</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Modern UI -->
        <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">

    <?php $sa_active = 'updates'; include 'sidebar_common.php'; ?>

    <div class="main-content">
        <?php $sa_title = 'Actualizaciones del Sistema (SQL Masivo)'; include 'header_common.php'; ?>

        <div class="container-fluid p-4">
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <?php if($type == 'success'): ?><i class="fas fa-check-circle me-2"></i><?php endif; ?>
                    <?php if($type == 'danger'): ?><i class="fas fa-exclamation-circle me-2"></i><?php endif; ?>
                    <?php if($type == 'warning'): ?><i class="fas fa-exclamation-triangle me-2"></i><?php endif; ?>
                    <?php if($type == 'info'): ?><i class="fas fa-info-circle me-2"></i><?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Formulario -->
                <div class="col-lg-5 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold"><i class="fas fa-code me-2 text-primary"></i>Ejecutar SQL</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Utilice esta herramienta para aplicar cambios de estructura (nuevas columnas, tablas) a todos los inquilinos simultáneamente.
                            </p>
                            
                            <form method="POST" action="" id="sqlForm">
                                <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                                <div class="mb-3">
                                    <label for="sql_command" class="form-label fw-medium">Comando SQL</label>
                                    <textarea class="form-control font-monospace bg-light" id="sql_command" name="sql_command" rows="8" required placeholder="Ej: ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL;"><?php echo isset($_POST['sql_command']) ? htmlspecialchars($_POST['sql_command']) : ''; ?></textarea>
                                    <div class="form-text text-danger"><i class="fas fa-exclamation-triangle me-1"></i>PRECAUCIÓN: Este comando se ejecutará en TODAS las bases de datos.</div>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" name="action" value="simulate" class="btn btn-info text-white fw-medium">
                                        <i class="fas fa-vial me-2"></i>Simular / Probar Conexión
                                    </button>
                                    <button type="button" class="btn btn-danger fw-medium" onclick="confirmExecution()">
                                        <i class="fas fa-play me-2"></i>EJECUTAR EN PRODUCCIÓN
                                    </button>
                                    <input type="hidden" name="action" id="actionInput" value="simulate">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Resultados -->
                <div class="col-lg-7 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold"><i class="fas fa-list-alt me-2 text-secondary"></i>Resultados</h5>
                            <?php if(!empty($results)): ?>
                                <span class="badge bg-secondary"><?php echo count($results); ?> Empresas</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($results)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-terminal fa-3x mb-3 opacity-50"></i>
                                    <p>Los resultados de la ejecución aparecerán aquí.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                    <table class="table table-hover table-striped mb-0 align-middle">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th class="ps-4">Empresa</th>
                                                <th>Base de Datos</th>
                                                <th>Estado</th>
                                                <th>Mensaje</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($results as $res): ?>
                                                <tr>
                                                    <td class="ps-4 fw-medium"><?php echo htmlspecialchars($res['company']); ?></td>
                                                    <td class="small text-muted font-monospace"><?php echo htmlspecialchars($res['db']); ?></td>
                                                    <td>
                                                        <?php if ($res['status'] === 'success'): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Exitoso</span>
                                                        <?php elseif ($res['status'] === 'error'): ?>
                                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Error</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">Info</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="small text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($res['msg']); ?>">
                                                        <?php echo htmlspecialchars($res['msg']); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function confirmExecution() {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "Estás a punto de ejecutar este comando SQL en TODAS las bases de datos de clientes. ¡Esta acción no se puede deshacer!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, ejecutar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('actionInput').value = 'execute';
                    document.getElementById('sqlForm').submit();
                }
            })
        }
    </script>
</body>
</html>
