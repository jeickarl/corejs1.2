<?php
require_once __DIR__ . '/app_config.php';
$pathBase = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/core';
$base = rtrim($pathBase, '/');
?>
<div class="tab-pane" id="equipment-accessories" role="tabpanel" style="padding-top:.5rem; margin-top:1rem">
    <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
        <div class="card-body p-4">
            <h5 class="mb-1 text-dark"><i class="fas fa-box-open me-2"></i>Accesorios del Equipo</h5>
            <p class="fs-6 text-muted mb-4">Gestiona los accesorios que pueden asociarse a los equipos.</p>
            <div class="tab-content">
                <iframe src="<?php echo htmlspecialchars($base . '/config/accessories_manager.php'); ?>" width="100%" frameborder="0" scrolling="no" onload="resizeIframe(this)" style="min-height: 700px;"></iframe>
            </div>
        </div>
    </div>
</div>
