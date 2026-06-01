<?php
require_once '../config/session.php';
requireAuth('../login/index.php');
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';

$pdo = db();
// Obtener ID de la orden
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if (!$order_id) {
    header('Location: index.php?error=' . urlencode('ID de orden no válido'));
    exit();
}

// Obtener datos de la orden
try {
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasOrderNumber = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'order_number') : false;
    $hasWoTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
    $hasClientTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
    $hasDeviceTypeTenant = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
    $sql = "SELECT wo.*, 
                   c.first_name, c.company_name, c.client_type,
                   dt.name as device_type_name
            FROM work_orders wo
            " . ((!$perDatabase && $hasClientTenant && $hasWoTenant) ? "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id" : "LEFT JOIN clients c ON wo.client_id = c.id") . "
            " . ((!$perDatabase && $hasDeviceTypeTenant && $hasWoTenant) ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id") . "
            WHERE wo.id = ?" . ((!$perDatabase && $hasWoTenant) ? " AND wo.tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute((!$perDatabase && $hasWoTenant) ? [$order_id, $tenantValue] : [$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: index.php?error=' . urlencode('Orden no encontrada'));
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Error al cargar la orden: ' . $e->getMessage()));
    exit();
}

// Generar token CSRF para el formulario
$csrf_token = SecurityEnhancements::generateCSRFToken();
// Mantener compatibilidad con sesiones antiguas si es necesario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($_POST['csrf_token'])) {
        die('Error de validación CSRF');
    }

    $title = trim($_POST['report_title']);
    $diagnosis = trim($_POST['diagnosis']);
    $procedure = trim($_POST['procedure_performed']);
    $observations = trim($_POST['observations']);
    $conclusions = trim($_POST['conclusions']);
    $selected_photos = isset($_POST['selected_photos']) ? $_POST['selected_photos'] : [];
    
    // Procesar fotos seleccionadas (array de nombres de archivo)
    // También podríamos capturar descripciones si las implementamos
    $photos_data = [];
    if (!empty($selected_photos)) {
        foreach ($selected_photos as $photo) {
            // Verificar si hay una descripción para esta foto
            // Reemplazamos puntos y barras por guiones bajos para coincidir con el nombre del input
            $desc_key = 'desc_' . str_replace(['.', '/'], '_', $photo); 
            $description = isset($_POST[$desc_key]) ? trim($_POST[$desc_key]) : '';
            $photos_data[] = [
                'filename' => $photo,
                'description' => $description
            ];
        }
    }
    
    $photos_json = json_encode($photos_data);
    $created_by = $_SESSION['user_id']; // Store User ID

    try {
        $hasTenantCol = hasTenantColumnCached($pdo, 'technical_reports');
        if ($hasTenantCol) {
            $stmt = $pdo->prepare("INSERT INTO technical_reports 
                (tenant_id, order_id, report_title, diagnosis, procedure_taken, introduction, conclusion, photos_json, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $tenantValue,
                $order_id,
                $title,
                $diagnosis,
                $procedure,
                $observations,
                $conclusions,
                $photos_json,
                $created_by
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO technical_reports 
                (order_id, report_title, diagnosis, procedure_taken, introduction, conclusion, photos_json, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $order_id,
                $title,
                $diagnosis,
                $procedure,
                $observations,
                $conclusions,
                $photos_json,
                $created_by
            ]);
        }
        
        header('Location: order_reports.php?id=' . $order_id . '&success=' . urlencode('Informe técnico creado exitosamente'));
        exit();
    } catch (PDOException $e) {
        $error = "Error al guardar el informe: " . $e->getMessage();
    }
}

// Generar token CSRF
$csrf_token = SecurityEnhancements::generateCSRFToken();

// Decodificar fotos de la orden
$order_photos = [];
if (!empty($order['device_photo'])) {
    $decoded = json_decode($order['device_photo'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $order_photos = $decoded;
    } else {
        // Fallback para formato antiguo o string simple
        $order_photos = [$order['device_photo']];
    }
}
?>

<?php
$num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
$prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
$disp = $prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);

$next_report_no = 1;
try {
    $hasTenantCol = hasTenantColumnCached($pdo, 'technical_reports');
    if ($hasTenantCol) {
        if (!$perDatabase) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM technical_reports WHERE order_id = ? AND tenant_id = ?");
            $st->execute([$order_id, $tenantValue]);
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM technical_reports WHERE order_id = ?");
            $st->execute([$order_id]);
        }
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM technical_reports WHERE order_id = ?");
        $st->execute([$order_id]);
    }
    $next_report_no = (int)$st->fetchColumn() + 1;
} catch (Throwable $__) {
    $next_report_no = 1;
}
$default_report_title = 'Informe Técnico #' . $next_report_no . ' - ' . $disp;
$page_title = 'Crear Informe Técnico - ' . $disp;

ob_start();
?>

<style>
    .photo-select-card {
        cursor: pointer;
        transition: all 0.2s ease-in-out;
        border: 2px solid transparent;
    }
    .photo-select-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
    }
    .photo-select-card.selected {
        border-color: #0d6efd;
        background-color: #f0f8ff;
    }
    .photo-check {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 10;
        transform: scale(1.2);
    }
    .btn-action-hover {
        transition: all 0.2s;
        background: rgba(255,255,255,0.9);
        border: none;
    }
    .btn-action-hover:hover {
        transform: scale(1.15);
        background: #fff;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2) !important;
    }
    textarea { resize: none; }
</style>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <div class="card card-modern border-0 shadow-sm overflow-hidden mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-3 mb-4">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-file-medical me-2 text-primary no-theme"></i>Crear Informe Técnico</h4>
                    <div class="text-muted small">
                        <?php echo htmlspecialchars($disp); ?> -
                        <span class="fw-medium text-dark"><?php echo htmlspecialchars(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''))); ?></span>
                    </div>
                </div>
                <a href="order_reports.php?id=<?php echo $order_id; ?>" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="fas fa-arrow-left me-2"></i>Cancelar
                </a>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="reportForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-edit me-2 text-primary no-theme"></i>Contenido del Informe</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="mb-4">
                                    <label for="report_title" class="form-label fw-bold text-dark">Título del Informe</label>
                                    <input type="text" class="form-control form-control-lg bg-light border-0" id="report_title" name="report_title"
                                           value="<?php echo htmlspecialchars($default_report_title); ?>" required>
                                </div>

                                <div class="mb-4">
                                    <label for="diagnosis" class="form-label fw-bold text-dark">Diagnóstico Técnico</label>
                                    <div class="p-3 bg-light rounded-3 mb-2">
                                        <textarea class="form-control border-0 bg-transparent" id="diagnosis" name="diagnosis" rows="5" placeholder="Describa el diagnóstico técnico..."><?php echo htmlspecialchars($order['diagnosis']); ?></textarea>
                                    </div>
                                    <div class="form-text"><i class="fas fa-info-circle me-1"></i>Puede modificar el diagnóstico original de la orden para este informe.</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-4">
                                    <label for="procedure_performed" class="form-label fw-bold text-dark">Procedimiento Realizado</label>
                                    <div class="p-3 bg-light rounded-3">
                                        <textarea class="form-control border-0 bg-transparent" id="procedure_performed" name="procedure_performed" rows="5" placeholder="Describa los procedimientos realizados..."><?php echo htmlspecialchars($order['solution']); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="mb-4">
                                            <label for="observations" class="form-label fw-bold text-dark">Observaciones Adicionales</label>
                                            <div class="p-3 bg-light rounded-3">
                                                <textarea class="form-control border-0 bg-transparent" id="observations" name="observations" rows="3" placeholder="Observaciones adicionales (estado de carcasa, recomendaciones, etc.)"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label for="conclusions" class="form-label fw-bold text-dark">Conclusiones</label>
                                            <div class="p-3 bg-light rounded-3">
                                                <textarea class="form-control border-0 bg-transparent" id="conclusions" name="conclusions" rows="3" placeholder="Conclusiones finales..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-bottom border-light py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-images me-2 text-primary"></i>Selección de Fotos</h5>
                        <span class="badge bg-light text-dark"><?php echo count($order_photos); ?> fotos disponibles</span>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted mb-4">Seleccione las fotos que desea incluir y añada una descripción detallada. Haga clic en la lupa para ver la imagen en tamaño completo.</p>

                        <?php if (empty($order_photos)): ?>
                            <div class="alert alert-light text-center py-5">
                                <i class="fas fa-camera fa-3x text-muted opacity-25 mb-3"></i>
                                <p class="mb-0 text-muted">No hay fotos disponibles en esta orden.</p>
                            </div>
                        <?php else:
                            $photos_by_category = [
                                'entry' => [],
                                'diagnosis' => [],
                                'delivery' => [],
                                'other' => []
                            ];

                            foreach ($order_photos as $photo) {
                                if (strpos($photo, 'entry/') === 0) {
                                    $photos_by_category['entry'][] = $photo;
                                } elseif (strpos($photo, 'diagnosis/') === 0) {
                                    $photos_by_category['diagnosis'][] = $photo;
                                } elseif (strpos($photo, 'delivery/') === 0) {
                                    $photos_by_category['delivery'][] = $photo;
                                } else {
                                    $photos_by_category['other'][] = $photo;
                                }
                            }

                            function renderPhotoCard($photo, $order_id) {
                                $baseDir = getTenantUploadDir('../uploads/');
                                $photoPath = $baseDir . "orders/" . $order_id . "/" . $photo;
                                $inputName = 'desc_' . str_replace(['.', '/'], '_', $photo);

                                if (!file_exists($photoPath)) return '';

                                ob_start();
                                ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="card photo-select-card shadow-sm h-100" onclick="togglePhotoSelection(this)">
                                        <div class="position-relative">
                                            <div class="position-absolute top-0 end-0 m-2 z-2 d-flex gap-2">
                                                <button type="button" class="btn btn-white btn-action-hover rounded-circle shadow-sm d-flex align-items-center justify-content-center"
                                                        style="width: 38px; height: 38px;"
                                                        onclick="event.stopPropagation(); openEditorModal('<?php echo $photoPath; ?>', '<?php echo $photo; ?>', '<?php echo $order_id; ?>')" title="Anotar / Editar">
                                                    <i class="fas fa-pen text-primary"></i>
                                                </button>
                                                <button type="button" class="btn btn-white btn-action-hover rounded-circle shadow-sm d-flex align-items-center justify-content-center"
                                                        style="width: 38px; height: 38px;"
                                                        onclick="event.stopPropagation(); showImageModal('<?php echo $photoPath; ?>')" title="Ver en grande">
                                                    <i class="fas fa-search-plus text-secondary"></i>
                                                </button>
                                            </div>

                                            <img src="<?php echo $photoPath; ?>" class="card-img-top" alt="Foto evidencia" style="height: 250px; object-fit: contain; background-color: #f8f9fa;">

                                            <div class="form-check position-absolute top-0 start-0 m-2 z-1">
                                                <input type="checkbox" class="form-check-input photo-check shadow-sm border-2"
                                                       style="width: 1.5em; height: 1.5em;"
                                                       name="selected_photos[]"
                                                       value="<?php echo htmlspecialchars($photo); ?>"
                                                       onclick="event.stopPropagation()">
                                            </div>
                                        </div>
                                        <div class="card-body p-3">
                                            <label class="form-label small fw-bold text-muted">Descripción de la evidencia:</label>
                                            <textarea class="form-control bg-light"
                                                      name="<?php echo $inputName; ?>"
                                                      placeholder="Describa lo que se observa en esta imagen..."
                                                      rows="2"
                                                      onclick="event.stopPropagation()"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                return ob_get_clean();
                            }
                            ?>
                            <div class="row g-4">
                                <?php if (!empty($photos_by_category['entry'])): ?>
                                    <div class="col-12"><h5 class="fw-bold text-primary no-theme border-bottom pb-2 mb-3"><i class="fas fa-sign-in-alt me-2"></i>Ingreso</h5></div>
                                    <?php foreach ($photos_by_category['entry'] as $photo) echo renderPhotoCard($photo, $order_id); ?>
                                <?php endif; ?>

                                <?php if (!empty($photos_by_category['diagnosis'])): ?>
                                    <div class="col-12 mt-4"><h5 class="fw-bold text-primary no-theme border-bottom pb-2 mb-3"><i class="fas fa-stethoscope me-2"></i>Diagnóstico</h5></div>
                                    <?php foreach ($photos_by_category['diagnosis'] as $photo) echo renderPhotoCard($photo, $order_id); ?>
                                <?php endif; ?>

                                <?php if (!empty($photos_by_category['delivery'])): ?>
                                    <div class="col-12 mt-4"><h5 class="fw-bold text-primary no-theme border-bottom pb-2 mb-3"><i class="fas fa-check-circle me-2"></i>Entrega</h5></div>
                                    <?php foreach ($photos_by_category['delivery'] as $photo) echo renderPhotoCard($photo, $order_id); ?>
                                <?php endif; ?>

                                <?php if (!empty($photos_by_category['other'])): ?>
                                    <div class="col-12 mt-4"><h5 class="fw-bold text-primary no-theme border-bottom pb-2 mb-3"><i class="fas fa-images me-2"></i>Otras</h5></div>
                                    <?php foreach ($photos_by_category['other'] as $photo) echo renderPhotoCard($photo, $order_id); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-grid mb-0">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-lg py-3">
                        <i class="fas fa-save me-2"></i>GUARDAR INFORME TÉCNICO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0 text-center position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <img src="" id="previewImage" class="img-fluid rounded shadow-lg" style="max-height: 90vh;" alt="Vista previa">
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="imageEditorModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 bg-dark text-white p-3">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Anotar Imagen</h5>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveEditedImage()" id="btnSaveEditor">
                        <i class="fas fa-save me-2"></i>Guardar
                    </button>
                </div>
            </div>
            <div class="modal-body p-0 d-flex flex-column align-items-center justify-content-center bg-secondary position-relative" style="overflow: hidden; user-select: none;">
                <div class="editor-toolbar position-absolute top-0 start-50 translate-middle-x mt-4 p-2 bg-dark bg-opacity-75 backdrop-blur rounded-pill shadow-lg d-flex gap-3 z-3 align-items-center border border-secondary" style="max-width: 90vw; overflow-x: auto; scrollbar-width: none;">
                    <div class="d-flex" role="group">
                        <button class="btn btn-outline-light rounded-circle tool-btn active mx-1 flex-shrink-0 d-flex justify-content-center align-items-center" id="tool-pen" title="Lápiz" onclick="setTool('pen')" style="width: 42px; height: 42px;"><i class="fas fa-pencil-alt"></i></button>
                        <button class="btn btn-outline-light rounded-circle tool-btn mx-1 flex-shrink-0 d-flex justify-content-center align-items-center" id="tool-arrow" title="Flecha" onclick="setTool('arrow')" style="width: 42px; height: 42px;"><i class="fas fa-long-arrow-alt-right"></i></button>
                        <button class="btn btn-outline-light rounded-circle tool-btn mx-1 flex-shrink-0 d-flex justify-content-center align-items-center" id="tool-rect" title="Rectángulo" onclick="setTool('rect')" style="width: 42px; height: 42px;"><i class="far fa-square"></i></button>
                        <button class="btn btn-outline-light rounded-circle tool-btn mx-1 flex-shrink-0 d-flex justify-content-center align-items-center" id="tool-circle" title="Círculo" onclick="setTool('circle')" style="width: 42px; height: 42px;"><i class="far fa-circle"></i></button>
                        <button class="btn btn-outline-light rounded-circle tool-btn mx-1 flex-shrink-0 d-flex justify-content-center align-items-center" id="tool-text" title="Texto" onclick="setTool('text')" style="width: 42px; height: 42px;"><i class="fas fa-font"></i></button>
                    </div>

                    <div class="vr bg-light mx-1 opacity-50 flex-shrink-0"></div>

                    <div class="d-flex gap-2">
                        <button class="btn rounded-circle color-btn active shadow-sm flex-shrink-0" id="color-red" onclick="setColor('#ff0000')" style="width: 32px; height: 32px; background-color: #ff0000; border: 2px solid white;" title="Rojo"></button>
                        <button class="btn rounded-circle color-btn shadow-sm flex-shrink-0" id="color-yellow" onclick="setColor('#ffff00')" style="width: 32px; height: 32px; background-color: #ffff00; border: 2px solid transparent;" title="Amarillo"></button>
                        <button class="btn rounded-circle color-btn shadow-sm flex-shrink-0" id="color-green" onclick="setColor('#00ff00')" style="width: 32px; height: 32px; background-color: #00ff00; border: 2px solid transparent;" title="Verde"></button>
                        <button class="btn rounded-circle color-btn shadow-sm flex-shrink-0" id="color-white" onclick="setColor('#ffffff')" style="width: 32px; height: 32px; background-color: #ffffff; border: 2px solid transparent;" title="Blanco"></button>
                    </div>

                    <div class="vr bg-light mx-1 opacity-50 flex-shrink-0"></div>

                    <div class="d-flex gap-1 align-items-center">
                        <button class="btn btn-sm btn-outline-light rounded-circle size-btn flex-shrink-0 d-flex justify-content-center align-items-center" onclick="setLineWidth(3)" title="Fino" style="width: 32px; height: 32px;"><i class="fas fa-circle" style="font-size: 6px;"></i></button>
                        <button class="btn btn-sm btn-outline-light rounded-circle size-btn active flex-shrink-0 d-flex justify-content-center align-items-center" onclick="setLineWidth(5)" title="Medio" style="width: 32px; height: 32px;"><i class="fas fa-circle" style="font-size: 10px;"></i></button>
                        <button class="btn btn-sm btn-outline-light rounded-circle size-btn flex-shrink-0 d-flex justify-content-center align-items-center" onclick="setLineWidth(8)" title="Grueso" style="width: 32px; height: 32px;"><i class="fas fa-circle" style="font-size: 14px;"></i></button>
                    </div>

                    <div class="vr bg-light mx-1 opacity-50 flex-shrink-0"></div>

                    <div class="d-flex gap-1">
                        <button class="btn btn-outline-light rounded-circle flex-shrink-0 d-flex justify-content-center align-items-center" onclick="undoLast()" title="Deshacer" style="width: 42px; height: 42px;"><i class="fas fa-undo"></i></button>
                    </div>
                </div>

                <div id="canvasContainer" class="position-relative shadow-lg" style="max-width: 95%; max-height: 85vh; overflow: auto;">
                    <canvas id="editorCanvas" style="cursor: crosshair;"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="textInputModal" tabindex="-1" aria-hidden="true" style="z-index: 10600;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary p-2">
                <h6 class="modal-title small">Insertar Texto</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <input type="text" id="textToolInput" class="form-control form-control-sm bg-secondary text-white border-0 mb-3" placeholder="Escriba aquí..." autofocus>
                <div class="d-grid">
                    <button type="button" class="btn btn-primary btn-sm rounded-pill" onclick="applyTextTool()">Agregar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let imageModal;
    let previewImage;

    let editorModal;
    let textInputModal;
    let canvas, ctx;
    let isDrawing = false;
    let startX, startY;
    let currentTool = 'pen';
    let currentColor = '#ff0000';
    let currentLineWidth = 5;
    let currentImageSrc = '';
    let currentFilename = '';
    let currentOrderId = '';
    let drawingHistory = [];
    let imgObj = new Image();
    let textPos = {x:0, y:0};

    function showImageModal(src) {
        if (!previewImage) previewImage = document.getElementById('previewImage');
        if (previewImage) previewImage.src = src;
        if (imageModal) imageModal.show();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const imagePreviewModalEl = document.getElementById('imagePreviewModal');
        if (imagePreviewModalEl && window.bootstrap) {
            imageModal = new bootstrap.Modal(imagePreviewModalEl);
        }
        previewImage = document.getElementById('previewImage');

        if (window.bootstrap) {
            const em = document.getElementById('imageEditorModal');
            const tim = document.getElementById('textInputModal');
            if (em) editorModal = new bootstrap.Modal(em);
            if (tim) textInputModal = new bootstrap.Modal(tim);
        }

        canvas = document.getElementById('editorCanvas');
        if (!canvas) return;
        ctx = canvas.getContext('2d');

        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDraw);
        canvas.addEventListener('mouseout', stopDraw);

        canvas.addEventListener('touchstart', function(e) {
            if (e.target === canvas) {
                e.preventDefault();
                startDraw(e.touches[0]);
            }
        }, {passive: false});
        canvas.addEventListener('touchmove', function(e) {
            if (e.target === canvas) {
                e.preventDefault();
                draw(e.touches[0]);
            }
        }, {passive: false});
        canvas.addEventListener('touchend', stopDraw);

        const textInput = document.getElementById('textToolInput');
        if (textInput) {
            textInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyTextTool();
            });
        }

        document.querySelectorAll('.photo-check').forEach(function(cb) {
            cb.addEventListener('change', function() {
                const card = this.closest('.photo-select-card');
                if (!card) return;
                if (this.checked) card.classList.add('selected');
                else card.classList.remove('selected');
            });
        });
    });

        function openEditorModal(src, filename, orderId) {
            currentImageSrc = src;
            currentFilename = filename;
            currentOrderId = orderId;
            drawingHistory = []; // Reset history
            
            // Show loading or something?
            imgObj.onload = function() {
                canvas.width = imgObj.width;
                canvas.height = imgObj.height;
                ctx.drawImage(imgObj, 0, 0);
                saveState(); // Save initial state (clean image)
                editorModal.show();
            }
            // Add random param to avoid cache when reloading same image
            imgObj.src = src + '?t=' + new Date().getTime();
        }

        function setTool(tool) {
            currentTool = tool;
            document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tool-' + tool).classList.add('active');
        }

        function setColor(color) {
            currentColor = color;
            document.querySelectorAll('.color-btn').forEach(b => {
                b.classList.remove('active');
                b.style.border = '2px solid transparent';
            });
            
            let btnId = '';
            if(color === '#ff0000') btnId = 'color-red';
            else if(color === '#ffff00') btnId = 'color-yellow';
            else if(color === '#00ff00') btnId = 'color-green';
            else if(color === '#ffffff') btnId = 'color-white';
            
            if(btnId) {
                let btn = document.getElementById(btnId);
                btn.classList.add('active');
                btn.style.border = '2px solid white';
            }
        }

        function setLineWidth(width) {
            currentLineWidth = width;
            document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
            event.currentTarget.classList.add('active');
        }

        function getMousePos(evt) {
            var rect = canvas.getBoundingClientRect();
            var scaleX = canvas.width / rect.width;
            var scaleY = canvas.height / rect.height;
            return {
                x: (evt.clientX - rect.left) * scaleX,
                y: (evt.clientY - rect.top) * scaleY
            };
        }

        function startDraw(e) {
            isDrawing = true;
            const pos = getMousePos(e);
            startX = pos.x;
            startY = pos.y;

            if (currentTool === 'text') {
                isDrawing = false; // Text is click-only
                textPos = {x: startX, y: startY};
                document.getElementById('textToolInput').value = ''; // Clear previous input
                textInputModal.show();
                // Focus input after modal shown
                setTimeout(() => document.getElementById('textToolInput').focus(), 500);
                return;
            }

            if (currentTool === 'pen') {
                ctx.beginPath();
                ctx.moveTo(startX, startY);
                ctx.strokeStyle = currentColor;
                ctx.lineWidth = currentLineWidth;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
            } else {
                // For shapes (circle, rect, arrow), we need to save temp state to animate
                saveTempState(); 
            }
        }

        function applyTextTool() {
            const text = document.getElementById('textToolInput').value;
            if (text) {
                saveState(); // Save before
                ctx.font = "bold " + (currentLineWidth * 6 + 12) + "px Arial"; // Adjusted size
                ctx.fillStyle = currentColor;
                ctx.fillText(text, textPos.x, textPos.y);
                saveState(); // Save after
            }
            textInputModal.hide();
        }

        let savedImageData;
        function saveTempState() {
            savedImageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        }

        function restoreTempState() {
            if (savedImageData) {
                ctx.putImageData(savedImageData, 0, 0);
            }
        }

        function drawArrow(fromx, fromy, tox, toy) {
            var headlen = currentLineWidth * 4; // length of head in pixels
            var dx = tox - fromx;
            var dy = toy - fromy;
            var angle = Math.atan2(dy, dx);
            
            ctx.beginPath();
            ctx.moveTo(fromx, fromy);
            ctx.lineTo(tox, toy);
            ctx.strokeStyle = currentColor;
            ctx.lineWidth = currentLineWidth;
            ctx.stroke();
            
            ctx.beginPath();
            ctx.moveTo(tox, toy);
            ctx.lineTo(tox - headlen * Math.cos(angle - Math.PI / 6), toy - headlen * Math.sin(angle - Math.PI / 6));
            ctx.lineTo(tox - headlen * Math.cos(angle + Math.PI / 6), toy - headlen * Math.sin(angle + Math.PI / 6));
            ctx.lineTo(tox, toy);
            ctx.lineTo(tox - headlen * Math.cos(angle - Math.PI / 6), toy - headlen * Math.sin(angle - Math.PI / 6));
            ctx.fillStyle = currentColor;
            ctx.fill();
        }

        function draw(e) {
            if (!isDrawing) return;
            const pos = getMousePos(e);

            if (currentTool === 'pen') {
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
            } else {
                restoreTempState(); // Clear previous frame
                
                ctx.strokeStyle = currentColor;
                ctx.lineWidth = currentLineWidth;
                
                if (currentTool === 'circle') {
                    ctx.beginPath();
                    let radius = Math.sqrt(Math.pow(pos.x - startX, 2) + Math.pow(pos.y - startY, 2));
                    ctx.arc(startX, startY, radius, 0, 2 * Math.PI);
                    ctx.stroke();
                } else if (currentTool === 'rect') {
                    ctx.strokeRect(startX, startY, pos.x - startX, pos.y - startY);
                } else if (currentTool === 'arrow') {
                    drawArrow(startX, startY, pos.x, pos.y);
                }
            }
        }

        function stopDraw() {
            if (!isDrawing) return;
            isDrawing = false;
            saveState(); // Push to history
        }

        function saveState() {
            if (drawingHistory.length > 10) drawingHistory.shift(); // Limit history
            drawingHistory.push(ctx.getImageData(0, 0, canvas.width, canvas.height));
        }

        function undoLast() {
            if (drawingHistory.length > 1) {
                drawingHistory.pop(); // Remove current state
                let prevState = drawingHistory[drawingHistory.length - 1];
                ctx.putImageData(prevState, 0, 0);
            } else if (drawingHistory.length === 1) {
                // Initial state
                ctx.putImageData(drawingHistory[0], 0, 0);
            }
        }

        function saveEditedImage() {
            const btn = document.getElementById('btnSaveEditor');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
            btn.disabled = true;

            const dataURL = canvas.toDataURL('image/png');
            
            const csrfTokenInput = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrf_token');
            const csrfToken = csrfTokenInput ? csrfTokenInput.value : '';

            fetch('save_annotated_image.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    image: dataURL,
                    filename: currentFilename,
                    order_id: currentOrderId,
                    csrf_token: csrfToken
                })
            })
            .then(window.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    // Update UI
                    // Find the image element with the original src (without query params)
                    // We need to find the card that corresponds to this photo
                    // Since we have currentFilename, we can use it to find the checkbox
                    const checkbox = document.querySelector(`input[value="${currentFilename}"]`);
                    if (checkbox) {
                        const card = checkbox.closest('.card');
                        const img = card.querySelector('img');
                        // Update img src with timestamp to force reload
                        img.src = data.new_src + '?t=' + new Date().getTime();
                        
                        // Update checkbox value and input name if necessary?
                        // Wait, the new file has a new name.
                        // We must update the checkbox value so the form submits the NEW filename.
                        checkbox.value = data.new_filename; // Relative path as expected by PHP
                        
                        // Also update the hidden textarea name if it depends on filename
                        // The textarea name is generated as: 'desc_' + str_replace(['.', '/'], '_', $photo);
                        // If we change the filename, we should probably update the textarea name too OR handle it in backend.
                        // But changing name attribute is messy.
                        // Better: The user hasn't submitted the form yet.
                        // If we update the checkbox value, the backend will look for 'desc_NEWFILENAME'.
                        // So we must update the textarea name too.
                        const textarea = card.querySelector('textarea');
                        const newDescName = 'desc_' + data.new_filename.replace(/\./g, '_').replace(/\//g, '_');
                        textarea.name = newDescName;
                        
                        // Update the "onclick" of the edit button to point to the new file?
                        // Yes, otherwise if they edit again, they edit the old one.
                        // The edit button is inside the card.
                        // We need to find the edit button.
                        // It calls openEditorModal(path, filename, orderId)
                        const editBtn = card.querySelector('button[onclick*="openEditorModal"]');
                        editBtn.setAttribute('onclick', `event.stopPropagation(); openEditorModal('${data.new_src}', '${data.new_filename}', '${currentOrderId}')`);
                        
                        // Also update zoom button
                        const zoomBtn = card.querySelector('button[onclick*="showImageModal"]');
                        zoomBtn.setAttribute('onclick', `event.stopPropagation(); showImageModal('${data.new_src}')`);
                    }
                    
                    editorModal.hide();
                    if (typeof showSuccess === 'function') showSuccess('Imagen guardada');
                } else {
                    if (typeof showError === 'function') showError('Error al guardar: ' + (data.error || 'Desconocido'));
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof showError === 'function') showError('Error de red al guardar imagen.');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function togglePhotoSelection(card) {
            const checkbox = card.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        }
    </script>

<?php
$page_content = ob_get_clean();
include '../includes/page_template.php';
?>
