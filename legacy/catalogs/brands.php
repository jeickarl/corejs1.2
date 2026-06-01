<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/performance_optimizer.php';

// Verificar autenticación
requireAuth();

$pdo = db();
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantBrands = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'brands') : false;

$message = '';
$error = '';

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar permisos de administrador
    if (!isAdminSession()) {
        $error = 'Acceso denegado: Se requieren permisos de administrador';
    } elseif (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $logo_path = null;
                
                // Procesar carga de logo
                if (isset($_FILES['logo']) && $_FILES['logo']['name'] != '') {
                    // Verificar errores de carga
                    switch ($_FILES['logo']['error']) {
                        case UPLOAD_ERR_OK:
                            break;
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $error = 'El archivo es demasiado grande. Tamaño máximo: 2MB';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $error = 'El archivo se cargó parcialmente. Intente nuevamente.';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $error = 'No se seleccionó ningún archivo.';
                            break;
                        default:
                            $error = 'Error desconocido al cargar el archivo.';
                            break;
                    }
                    
                    if (empty($error)) {
                        $upload_dir = ensureTenantSubdirFs($tenant_id, 'brands');
                        
                        if (empty($error)) {
                            // Verificar tamaño del archivo (2MB máximo)
                            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                                $error = 'El archivo es demasiado grande. Tamaño máximo: 2MB';
                            } else {
                                if (!is_uploaded_file($_FILES['logo']['tmp_name'] ?? '')) {
                                    $error = 'Archivo inválido.';
                                } else {
                                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                                    $mime = $finfo->file($_FILES['logo']['tmp_name']) ?: '';
                                    $allowed_mimes = [
                                        'image/jpeg' => 'jpg',
                                        'image/png' => 'png',
                                        'image/gif' => 'gif'
                                    ];
                                    if (!isset($allowed_mimes[$mime])) {
                                        $error = 'Formato de archivo no permitido. Use JPG, PNG o GIF.';
                                    } else {
                                        $file_extension = $allowed_mimes[$mime];
                                        $clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name));
                                        $filename = sanitizeFileBasename($clean_name . '.' . $file_extension);
                                        $logo_path = 'uploads/' . $tenant_id . '/brands/' . $filename;
                                        if (!moveUploadedFileCross($_FILES['logo']['tmp_name'], $upload_dir . $filename)) {
                                            $error = 'Error al mover el archivo al directorio de destino.';
                                        } else {
                                            $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                            if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                                                PerformanceOptimizer::optimizeImage($upload_dir . $filename, $upload_dir . $filename, 85);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                if (empty($error)) {
                    try {
                        if ($hasTenantBrands) {
                            $stmt = $pdo->prepare("INSERT INTO brands (tenant_id, name, description, logo) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$tenantValue, $name, $description, $logo_path]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO brands (name, description, logo) VALUES (?, ?, ?)");
                            $stmt->execute([$name, $description, $logo_path]);
                        }
                        $message = 'Marca creada exitosamente.';
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) {
                            $error = 'Ya existe una marca con ese nombre.';
                        } else {
                            $error = 'Error al crear la marca: ' . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'update':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                // Obtener logo actual
                $sql = "SELECT logo FROM brands WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                $current_brand = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $logo_path = isset($current_brand['logo']) ? $current_brand['logo'] : null;
                
                // Procesar nueva carga de logo
                if (isset($_FILES['logo']) && $_FILES['logo']['name'] != '') {
                    // Verificar errores de carga
                    switch ($_FILES['logo']['error']) {
                        case UPLOAD_ERR_OK:
                            break;
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $error = 'El archivo es demasiado grande. Tamaño máximo: 2MB';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $error = 'El archivo se cargó parcialmente. Intente nuevamente.';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $error = 'No se seleccionó ningún archivo.';
                            break;
                        default:
                            $error = 'Error desconocido al cargar el archivo.';
                            break;
                    }
                    
                    if (empty($error)) {
                        $upload_dir = ensureTenantSubdirFs($tenant_id, 'brands');
                        
                        if (empty($error)) {
                            // Verificar tamaño del archivo (2MB máximo)
                            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                                $error = 'El archivo es demasiado grande. Tamaño máximo: 2MB';
                            } else {
                                if (!is_uploaded_file($_FILES['logo']['tmp_name'] ?? '')) {
                                    $error = 'Archivo inválido.';
                                } else {
                                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                                    $mime = $finfo->file($_FILES['logo']['tmp_name']) ?: '';
                                    $allowed_mimes = [
                                        'image/jpeg' => 'jpg',
                                        'image/png' => 'png',
                                        'image/gif' => 'gif'
                                    ];
                                    if (!isset($allowed_mimes[$mime])) {
                                        $error = 'Formato de archivo no permitido. Use JPG, PNG o GIF.';
                                    } else {
                                        $file_extension = $allowed_mimes[$mime];
                                        $clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name));
                                        $filename = sanitizeFileBasename($clean_name . '.' . $file_extension);
                                        $new_logo_path = 'uploads/' . $tenant_id . '/brands/' . $filename;
                                        if (moveUploadedFileCross($_FILES['logo']['tmp_name'], $upload_dir . $filename)) {
                                            $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                            if (in_array($extLower, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                                                PerformanceOptimizer::optimizeImage($upload_dir . $filename, $upload_dir . $filename, 85);
                                            }
                                            if ($logo_path && file_exists('../' . $logo_path) && $logo_path !== $new_logo_path) {
                                                unlink('../' . $logo_path);
                                            }
                                            $logo_path = $new_logo_path;
                                        } else {
                                            $error = 'Error al mover el archivo al directorio de destino.';
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                if (empty($error)) {
                    try {
                        $sql = "UPDATE brands SET name = ?, description = ?, logo = ?, is_active = ? WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
                        $stmt = $pdo->prepare($sql);
                        $params = [$name, $description, $logo_path, $is_active, $id];
                        if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
                        $stmt->execute($params);
                        $message = 'Marca actualizada exitosamente.';
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) {
                            $error = 'Ya existe una marca con ese nombre.';
                        } else {
                            $error = 'Error al actualizar la marca: ' . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                try {
                    // Obtener logo para eliminarlo
                    $sql = "SELECT logo FROM brands WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                    $brand = $stmt->fetch(PDO::FETCH_ASSOC);
                    $brand_logo = (is_array($brand) && isset($brand['logo'])) ? $brand['logo'] : null;
                    
                    $sql = "DELETE FROM brands WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(($hasTenantBrands && !$perDatabase) ? [$id, $tenantValue] : [$id]);
                    
                    // Eliminar logo si existe
                    if (!empty($brand_logo) && file_exists('../' . $brand_logo)) {
                        unlink('../' . $brand_logo);
                    }
                    
                    $message = 'Marca eliminada exitosamente.';
                } catch (PDOException $e) {
                    $error = 'Error al eliminar la marca: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Obtener todas las marcas
$sql = "SELECT * FROM brands" . (($hasTenantBrands && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(($hasTenantBrands && !$perDatabase) ? [$tenantValue] : []);
$brands = $stmt->fetchAll();
$baseUploads = __DIR__ . '/../uploads/';
$tenantBrandsDir = getTenantUploadDir($baseUploads) . 'brands/';
if (!is_dir($tenantBrandsDir)) { @mkdir($tenantBrandsDir, 0755, true); }
$allowedExt = ['png','jpg','jpeg','gif'];
foreach ($brands as $idx => $b) {
    if (empty($b['logo'])) {
        $raw = $b['name'] ?? '';
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $raw));
        $forms = [$clean, strtolower($clean), strtoupper($clean), ucfirst(strtolower($clean))];
        $found = null;
        foreach ($forms as $f) {
            foreach ($allowedExt as $ext) {
                $candidate = $f . '.' . $ext;
                $globalPath = __DIR__ . '/../uploads/brands/' . $candidate;
                if (file_exists($globalPath)) { $found = $candidate; break 2; }
            }
        }
        if ($found) {
            $tenantPath = $tenantBrandsDir . $found;
            if (!file_exists($tenantPath)) { @copy(__DIR__ . '/../uploads/brands/' . $found, $tenantPath); }
            $rel = getTenantUploadDir('uploads/') . 'brands/' . $found;
            $sql = "UPDATE brands SET logo = ? WHERE id = ?" . (($hasTenantBrands && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmtU = $pdo->prepare($sql);
            $params = [$rel, $b['id']];
            if ($hasTenantBrands && !$perDatabase) { $params[] = $tenantValue; }
            $stmtU->execute($params);
            $brands[$idx]['logo'] = $rel;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Marcas - Sistema de Órdenes</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/utils.js"></script>
    <style>
        .brand-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 8px;
            margin: 4px;
            background-color: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .logo-preview {
            max-width: 100px;
            max-height: 100px;
            object-fit: contain;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">

                <?php if ($message): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => showSuccess(<?php echo json_encode($message); ?>));</script>
                <?php endif; ?>

                <?php if ($error): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => showError(<?php echo json_encode($error); ?>));</script>
                <?php endif; ?>

                <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                    <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                        <h5 class="mb-0 text-dark fw-bold">
                            <i class="fas fa-tags me-2"></i>Gestión de Marcas
                        </h5>
                        <button type="button" class="btn btn-dark rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#createBrandModal">
                            <i class="fas fa-plus me-2"></i>Nueva Marca
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="border-0 rounded-start">Nombre</th>
                                        <th class="border-0">Logo</th>
                                        <th class="border-0 rounded-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($brands as $brand): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                        <i class="fas fa-tag text-dark"></i>
                                                    </div>
                                                    <strong><?php echo htmlspecialchars($brand['name']); ?></strong>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($brand['logo']): ?>
                                                    <img src="../<?php echo htmlspecialchars($brand['logo']); ?>" 
                                                         alt="Logo <?php echo htmlspecialchars($brand['name']); ?>" 
                                                         class="brand-logo shadow-sm" style="border-radius: 0.5rem;">
                                                <?php else: ?>
                                                    <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                        <i class="fas fa-image text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group shadow-sm" role="group">
                                                    <button type="button" class="btn btn-sm btn-outline-dark rounded-start" 
                                                            onclick='editBrand(<?php echo htmlspecialchars(json_encode($brand), ENT_QUOTES); ?>)' title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-end" 
                                                            onclick='deleteBrand(<?php echo $brand['id']; ?>, <?php echo json_encode($brand['name']); ?>)' title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear Marca -->
    <div class="modal fade" id="createBrandModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus me-2"></i>Nueva Marca</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                            <div class="card-body p-4">
                                <input type="hidden" name="action" value="create">
                                
                                <div class="mb-3">
                                    <label for="name" class="form-label fw-bold text-dark">Nombre <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" required maxlength="100" style="border-radius: 0.5rem;">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="logo" class="form-label fw-bold text-dark">Logo</label>
                                    <input type="file" class="form-control" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif,.svg" style="border-radius: 0.5rem;">
                                    <div class="form-text">Formatos permitidos: JPG, PNG, GIF, SVG. Tamaño máximo: 2MB</div>
                                </div>

                                <div class="text-end mt-4">
                                    <button type="button" class="btn btn-secondary rounded-pill px-4 me-2" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm"><i class="fas fa-save me-2"></i>Crear Marca</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Editar Marca -->
    <div class="modal fade" id="editBrandModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-0 bg-dark text-white" style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Marca</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                        <div class="card-body p-4">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" id="edit_id">
                                
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label fw-bold text-dark">Nombre *</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required maxlength="100" style="border-radius: 0.5rem;">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_description" class="form-label fw-bold text-dark">Descripción</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="3" style="border-radius: 0.5rem;"></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                        <label class="form-check-label fw-bold text-dark" for="edit_is_active">
                                            Marca activa
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-dark">Logo actual</label>
                                    <div id="current_logo" class="p-2 border rounded bg-white text-center"></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_logo" class="form-label fw-bold text-dark">Nuevo logo (opcional)</label>
                                    <input type="file" class="form-control" id="edit_logo" name="logo" accept=".jpg,.jpeg,.png,.gif,.svg" style="border-radius: 0.5rem;">
                                    <div class="form-text">Formatos permitidos: JPG, PNG, GIF, SVG. Tamaño máximo: 2MB</div>
                                </div>

                                <div class="text-end mt-4">
                                    <button type="button" class="btn btn-secondary rounded-pill px-4 me-2" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm"><i class="fas fa-save me-2"></i>Actualizar Marca</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editBrand(brand) {
            document.getElementById('edit_id').value = brand.id;
            document.getElementById('edit_name').value = brand.name;
            document.getElementById('edit_description').value = brand.description || '';
            document.getElementById('edit_is_active').checked = brand.is_active == 1;
            
            const currentLogoDiv = document.getElementById('current_logo');
            if (brand.logo) {
                currentLogoDiv.innerHTML = `<img src="../${brand.logo}" alt="Logo actual" class="logo-preview">`;
            } else {
                currentLogoDiv.innerHTML = '<p class="text-muted">Sin logo</p>';
            }
            
            new bootstrap.Modal(document.getElementById('editBrandModal')).show();
        }
        
        function deleteBrand(id, name) {
            showConfirm(`¿Estás seguro de eliminar la marca "${name}"? Esta acción no se puede deshacer.`, function() {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                fetch('brands.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text()) // brands.php doesn't return JSON for form posts, but for delete it might redirect or refresh. 
                // Wait, brands.php handles POST and sets variables, then renders HTML. 
                // It does NOT handle AJAX requests properly for 'delete' in the current structure.
                // The current structure reloads the page on POST.
                // If I use fetch, I need to reload manually or parse response.
                // Actually, the original code used a form submission inside a modal.
                // Let's stick to form submission for now to avoid refactoring the whole backend to JSON.
                // But wait, showConfirm is async. I can create a hidden form and submit it.
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'brands.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                form.appendChild(actionInput);
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;
                form.appendChild(idInput);
                
                document.body.appendChild(form);
                form.submit();
            });
        }
        
        function closeAlert() {
            const overlay = document.querySelector('.alert-overlay');
            const alert = document.querySelector('.alert-centered');
            if (overlay) overlay.remove();
            if (alert) alert.remove();
        }
        
        // Auto-cerrar la alerta después de 0.5 segundos
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.querySelector('.alert-centered');
            if (alert) {
                setTimeout(closeAlert, 500);
            }
        });
    </script>
</body>
</html>
