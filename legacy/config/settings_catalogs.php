<?php
require_once __DIR__ . '/app_config.php';
$pathBase = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/core';
$base = rtrim($pathBase, '/');
?>
<!-- TAB: Dispositivos (#catalogs) -->
<div class="tab-pane" id="catalogs" role="tabpanel" style="padding-top:.5rem; margin-top:1rem">
    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
        <div class="card-body p-4">
            <h5 class="mb-1 text-dark"><i class="fas fa-laptop me-2"></i>Información de Dispositivos</h5>
            <p class="fs-6 text-muted mb-4">Configura la información de dispositivos: marcas, modelos y tipos de equipo.</p>
            
            <ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-pill" id="catalogTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-pill" id="brands-tab" data-bs-toggle="tab" data-bs-target="#brands" type="button" role="tab">
                        <i class="fas fa-copyright me-2"></i>Marcas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill" id="models-tab" data-bs-toggle="tab" data-bs-target="#models" type="button" role="tab">
                        <i class="fas fa-mobile-alt me-2"></i>Modelos
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill" id="device-types-tab" data-bs-toggle="tab" data-bs-target="#device-types" type="button" role="tab">
                        <i class="fas fa-laptop me-2"></i>Tipos de Equipo
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="catalogTabsContent">
                <!-- Gestión de Marcas -->
                <div class="tab-pane active" id="brands" role="tabpanel">
                    <iframe src="<?php echo htmlspecialchars($base . '/catalogs/brands.php'); ?>" width="100%" frameborder="0" scrolling="no" onload="resizeIframe(this)" style="min-height: 800px;"></iframe>
                </div>
                
                <!-- Gestión de Modelos -->
                <div class="tab-pane" id="models" role="tabpanel">
                    <iframe src="<?php echo htmlspecialchars($base . '/catalogs/models.php'); ?>" width="100%" frameborder="0" scrolling="no" onload="resizeIframe(this)" style="min-height: 800px;"></iframe>
                </div>
                
                <!-- Gestión de Tipos de Equipo -->
                <div class="tab-pane" id="device-types" role="tabpanel">
                    <iframe src="<?php echo htmlspecialchars($base . '/catalogs/device_types.php'); ?>" width="100%" frameborder="0" scrolling="no" onload="resizeIframe(this)" style="min-height: 800px;"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>
