<?php
// Obtener configuración actual (aislada por tenant)
$whatsapp_config = [];
try {
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    $sql = "SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'whatsapp_template_%'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $whatsapp_config[$row['config_key']] = $row['config_value'];
    }
} catch (Throwable $e) {
    $whatsapp_config = [];
}

// Valores por defecto si no existen
// Defaults con emojis seguros (ASCII → JSON → PHP) para evitar problemas de codificación del archivo
$defaults_json = '{
  "whatsapp_template_reception": "\\uD83D\\uDCDD Recepción de equipo\\n\\uD83D\\uDC64 {{cliente}} | \\u260E\\uFE0F {{cliente_tel}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F Marca: {{marca}} | Modelo: {{modelo}}\\n\\uD83D\\uDD22 SN/IMEI: {{sn}}\\n\\u26A0\\uFE0F Problema reportado: {{falla}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDCB5 Abono: {{abono}}\\n\\uD83D\\uDCB0 Costo aproximado: {{valor}}\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\uD83D\\uDD17 Seguimiento: {{url_seguimiento}}\\n\\uD83C\\uDFE2 {{taller_nombre}} | \\uD83D\\uDCDE {{taller_tel}}",
  "whatsapp_template_ready": "\\u2705 Equipo listo\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F {{marca}} {{modelo}} (SN {{sn}})\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\u26A0\\uFE0F Problema: {{falla}}\\n\\uD83D\\uDD2C Diagnóstico: {{diagnostico}}\\n\\uD83D\\uDEE0\\uFE0F Solución: {{solucion}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDCB0 Total: {{total}} | \\uD83D\\uDCB3 Saldo: {{saldo}}\\n\\uD83D\\uDCCD Retiro: {{fecha_entrega}}\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}",
  "whatsapp_template_delivery": "\\uD83D\\uDCE6 Entrega realizada\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDCF1 Tipo: {{tipo}}\\n\\uD83C\\uDFD7\\uFE0F {{marca}} {{modelo}} (SN {{sn}})\\n\\uD83E\\uDDFE Orden #{{orden}}\\n\\uD83D\\uDD2C Diagnóstico: {{diagnostico}}\\n\\uD83D\\uDEE0\\uFE0F Solución: {{solucion}}\\n\\uD83C\\uDF92 Accesorios: {{accesorios}}\\n\\uD83D\\uDE4F Gracias por confiar en nosotros\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}",
  "whatsapp_template_sale": "\\uD83E\\uDDFE Comprobante de Venta #{{factura}}\\n\\uD83D\\uDC64 {{cliente}}\\n\\uD83D\\uDECD\\uFE0F Detalles: {{detalles}}\\n\\uD83D\\uDCB0 Total: {{total}} | \\uD83D\\uDCB3 Saldo: {{saldo}}\\n\\uD83D\\uDE4F ¡Gracias por tu compra!\\n\\u260E\\uFE0F {{taller_nombre}} | {{taller_tel}}"
}';
$defaults = json_decode($defaults_json, true);

// Sanitizar valores corruptos provenientes de BD (reemplazar por defaults si contienen � o "????")
foreach (['whatsapp_template_reception','whatsapp_template_ready','whatsapp_template_delivery','whatsapp_template_sale'] as $key) {
    if (isset($whatsapp_config[$key])) {
        $val = (string)$whatsapp_config[$key];
        if (strpos($val, "\u{FFFD}") !== false || strpos($val, "????") !== false) {
            $whatsapp_config[$key] = $defaults[$key];
        }
    } else {
        $whatsapp_config[$key] = $defaults[$key];
    }
}

foreach ($defaults as $key => $value) {
    if (!isset($whatsapp_config[$key])) {
        $whatsapp_config[$key] = $value;
    }
}
?>

<div class="card border-0 shadow-sm" style="border-radius: 1rem; margin-top: 1rem !important; margin-bottom: 1rem !important;">
    <div class="card-body p-4" style="padding-top: 1.5rem !important;">
        <!-- Header Section -->
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 gap-3">
            <div>
                <h5 class="mb-1 text-dark fw-bold"><i class="fab fa-whatsapp me-2"></i>Editor de Plantillas WhatsApp</h5>
                <p class="fs-6 text-muted mb-0">Personaliza los mensajes automáticos enviados a tus clientes.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                <button type="button" class="btn btn-warning rounded-pill px-4" onclick="restoreDefaults()">
                    <i class="fas fa-undo me-2"></i>Restaurar
                </button>
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveWhatsappTemplates()">
                    <i class="fas fa-save me-2"></i>Guardar Cambios
                </button>
            </div>
        </div>

        <!-- Navigation Pills -->
        <ul class="nav nav-pills nav-fill mb-4 p-2 bg-light rounded-pill" id="whatsappTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill" id="service-tab" data-bs-toggle="tab" data-bs-target="#service" type="button" role="tab" aria-controls="service" aria-selected="true">
                    <i class="fas fa-tools me-2"></i>Servicio Técnico
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales" type="button" role="tab" aria-controls="sales" aria-selected="false">
                    <i class="fas fa-shopping-cart me-2"></i>Ventas
                </button>
            </li>
        </ul>

        <div class="row">
            <!-- Editor Column -->
            <div class="col-lg-7">
                <form id="whatsappForm" accept-charset="UTF-8">
                    <div class="tab-content" id="whatsappTabsContent">
                        
                        <!-- Servicio Técnico Tab -->
                        <div class="tab-pane fade show active" id="service" role="tabpanel" aria-labelledby="service-tab">
                            <div class="mb-4 p-3 border rounded-3 bg-white shadow-sm">
                                <label class="form-label fw-bold text-primary no-theme mb-3"><i class="fas fa-inbox me-2 text-primary no-theme"></i>Recepción de Equipo</label>
                                <!-- Toolbar -->
                                <div class="btn-group btn-group-sm mb-2 w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_reception', '**')"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_reception', '_')"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_reception', '~')"><i class="fas fa-strikethrough"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="showEmojiPanel('whatsapp_template_reception', this)">😀</button>
                                </div>
                                <div class="variable-chips mb-2">
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{cliente}}')">{{cliente}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{cliente_tel}}')">{{cliente_tel}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{tipo}}')">{{tipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{equipo}}')">{{equipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{marca}}')">{{marca}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{modelo}}')">{{modelo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{sn}}')">{{sn}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{orden}}')">{{orden}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{falla}}')">{{falla}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{estado}}')">{{estado}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{valor}}')">{{valor}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{abono}}')">{{abono}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{fecha_entrega}}')">{{fecha_entrega}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{url_seguimiento}}')">{{url_seguimiento}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{taller_nombre}}')">{{taller_nombre}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_reception', '{{taller_tel}}')">{{taller_tel}}</span>
                                </div>
                                <textarea class="form-control" id="whatsapp_template_reception" name="whatsapp_template_reception" rows="4" onkeyup="updatePreview(this)" onclick="updatePreview(this)"><?php echo htmlspecialchars($whatsapp_config['whatsapp_template_reception'] ?? $defaults['whatsapp_template_reception'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="form-text text-muted">Mensaje enviado al recibir un equipo.</div>
                            </div>

                            <div class="mb-4 p-3 border rounded-3 bg-white shadow-sm">
                                <label class="form-label fw-bold text-success mb-3"><i class="fas fa-check-circle me-2"></i>Equipo Listo</label>
                                <!-- Toolbar -->
                                <div class="btn-group btn-group-sm mb-2 w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_ready', '**')"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_ready', '_')"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_ready', '~')"><i class="fas fa-strikethrough"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="showEmojiPanel('whatsapp_template_ready', this)">😀</button>
                                </div>
                                <div class="variable-chips mb-2">
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{cliente}}')">{{cliente}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{cliente_tel}}')">{{cliente_tel}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{tipo}}')">{{tipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{equipo}}')">{{equipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{marca}}')">{{marca}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{modelo}}')">{{modelo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{sn}}')">{{sn}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{accesorios}}')">{{accesorios}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{orden}}')">{{orden}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{diagnostico}}')">{{diagnostico}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{solucion}}')">{{solucion}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{total}}')">{{total}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{saldo}}')">{{saldo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{fecha_entrega}}')">{{fecha_entrega}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_ready', '{{taller_nombre}}')">{{taller_nombre}}</span>
                                </div>
                                <textarea class="form-control" id="whatsapp_template_ready" name="whatsapp_template_ready" rows="4" onkeyup="updatePreview(this)" onclick="updatePreview(this)"><?php echo htmlspecialchars($whatsapp_config['whatsapp_template_ready'] ?? $defaults['whatsapp_template_ready'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="form-text text-muted">Mensaje enviado cuando el equipo está reparado.</div>
                            </div>

                            <div class="mb-4 p-3 border rounded-3 bg-white shadow-sm">
                                <label class="form-label fw-bold text-info no-theme mb-3"><i class="fas fa-hand-holding me-2 text-info no-theme"></i>Entrega de Equipo</label>
                                <!-- Toolbar -->
                                <div class="btn-group btn-group-sm mb-2 w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_delivery', '**')"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_delivery', '_')"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_delivery', '~')"><i class="fas fa-strikethrough"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="showEmojiPanel('whatsapp_template_delivery', this)">😀</button>
                                </div>
                                <div class="variable-chips mb-2">
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{cliente}}')">{{cliente}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{cliente_tel}}')">{{cliente_tel}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{tipo}}')">{{tipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{equipo}}')">{{equipo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{marca}}')">{{marca}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{modelo}}')">{{modelo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{sn}}')">{{sn}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{diagnostico}}')">{{diagnostico}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{solucion}}')">{{solucion}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{accesorios}}')">{{accesorios}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{orden}}')">{{orden}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{abono}}')">{{abono}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_delivery', '{{taller_nombre}}')">{{taller_nombre}}</span>
                                </div>
                                <textarea class="form-control" id="whatsapp_template_delivery" name="whatsapp_template_delivery" rows="4" onkeyup="updatePreview(this)" onclick="updatePreview(this)"><?php echo htmlspecialchars($whatsapp_config['whatsapp_template_delivery'] ?? $defaults['whatsapp_template_delivery'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="form-text text-muted">Mensaje enviado al entregar el equipo al cliente.</div>
                            </div>
                        </div>

                        <!-- Ventas Tab -->
                        <div class="tab-pane fade" id="sales" role="tabpanel" aria-labelledby="sales-tab">
                            <div class="mb-4 p-3 border rounded-3 bg-white shadow-sm">
                                <label class="form-label fw-bold text-primary mb-3"><i class="fas fa-receipt me-2"></i>Comprobante de Venta</label>
                                <!-- Toolbar -->
                                <div class="btn-group btn-group-sm mb-2 w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_sale', '**')"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_sale', '_')"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="applyFormat('whatsapp_template_sale', '~')"><i class="fas fa-strikethrough"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="showEmojiPanel('whatsapp_template_sale', this)">😀</button>
                                </div>
                                <div class="variable-chips mb-2">
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{cliente}}')">{{cliente}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{factura}}')">{{factura}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{detalles}}')">{{detalles}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{abono}}')">{{abono}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{total}}')">{{total}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{saldo}}')">{{saldo}}</span>
                                    <span class="badge bg-light text-dark border pointer" onclick="insertVar('whatsapp_template_sale', '{{taller_nombre}}')">{{taller_nombre}}</span>
                                </div>
                                <textarea class="form-control" id="whatsapp_template_sale" name="whatsapp_template_sale" rows="6" onkeyup="updatePreview(this)" onclick="updatePreview(this)"><?php echo htmlspecialchars($whatsapp_config['whatsapp_template_sale'] ?? $defaults['whatsapp_template_sale'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="form-text text-muted">Mensaje enviado al realizar una venta o abono.</div>
                            </div>
                        </div>


                    </div>
                </form>
            </div>

            <!-- Preview Column -->
            <div class="col-lg-5">
                <div class="sticky-top" style="top: 20px;">
                    
                    <!-- Test Sender -->
                    <div class="card mb-3 border-0 shadow-sm bg-light">
                        <div class="card-body p-3">
                            <label class="small text-muted fw-bold mb-2"><i class="fab fa-whatsapp me-1"></i>Probar en tu celular:</label>
                            <div class="input-group input-group-sm">
                                <input type="tel" id="test-phone" class="form-control" placeholder="Ej: 521234567890">
                                <button class="btn btn-success" type="button" onclick="sendTest()">
                                    Enviar
                                </button>
                            </div>
                        </div>
                    </div>
                    

                    <div class="phone-mockup" id="phoneMockup">
                        <!-- Status Bar -->
                        <div class="phone-status-bar">
                            <span class="time">10:30</span>
                            <div class="icons">
                                <i class="fas fa-signal me-1"></i>
                                <i class="fas fa-wifi me-1"></i>
                                <i class="fas fa-battery-full"></i>
                            </div>
                        </div>
                        <div class="phone-header">
                            <div class="d-flex align-items-center w-100 justify-content-between">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-arrow-left text-white me-3 pointer"></i>
                                    <div class="avatar d-flex align-items-center justify-content-center me-2">
                                        <i class="fas fa-user text-secondary"></i>
                                    </div>
                                    <div class="text-white">
                                        <div class="fw-bold name-text">Cliente</div>
                                        <div class="status-text">en línea</div>
                                    </div>
                                </div>
                                <!-- Dark Mode Toggle -->
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="darkModeToggle" onchange="toggleDarkMode()">
                                </div>
                            </div>
                        </div>
                        <div class="phone-body">
                            <div class="message-bubble received">
                                <div id="preview-content">Selecciona un campo para ver la vista previa...</div>
                                <div class="message-info">
                                    <span class="message-time" id="preview-time">10:30 AM</span>
                                    <span class="double-check"><i class="fas fa-check-double text-primary"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="phone-footer">
                            <i class="fas fa-plus text-muted me-3 fs-5"></i>
                            <div class="input-fake"></div>
                            <div class="send-icon"><i class="fas fa-paper-plane"></i></div>
                        </div>
                        <div class="home-indicator"></div>
                    </div>
                    <div class="text-center mt-3 text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        La vista previa se actualiza al escribir.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.pointer { cursor: pointer; user-select: none; transition: all 0.2s; }
.pointer:hover { transform: translateY(-2px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.variable-chips .badge { margin-right: 5px; margin-bottom: 5px; font-size: 0.85rem; padding: 6px 10px; }

/* Phone Mockup */
.phone-mockup {
    background-color: #fff;
    border-radius: 40px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2), 0 0 0 12px #1f2c34;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    max-width: 350px;
    margin: 0 auto;
    position: relative;
    transition: all 0.3s ease;
}

/* Status Bar */
.phone-status-bar {
    background-color: #075e54;
    color: #fff;
    padding: 8px 25px 5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.phone-header {
    background-color: #075e54;
    padding: 5px 15px 10px;
    height: 60px;
    display: flex;
    align-items: center;
    color: white;
    transition: all 0.3s ease;
}

.avatar {
    width: 40px;
    height: 40px;
    background-color: #dcf8c6;
    border-radius: 50%;
    color: white;
}
.name-text { font-size: 16px; line-height: 1.2; }
.status-text { font-size: 12px; opacity: 0.9; }

.phone-body {
    height: 450px;
    padding: 20px;
    overflow-y: auto;
    background-color: #e5ddd5;
    background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');
    background-size: contain;
    display: flex;
    flex-direction: column;
    transition: all 0.3s ease;
}

.message-bubble {
    background-color: #fff;
    border-radius: 7.5px;
    padding: 6px 7px 8px 9px;
    max-width: 80%;
    position: relative;
    box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
    margin-bottom: 10px;
    font-size: 14.2px;
    line-height: 19px;
    color: #111;
    align-self: flex-start;
    border-top-left-radius: 0;
    transition: all 0.3s ease;
}

/* Tail for received message */
.message-bubble.received::before {
    content: "";
    position: absolute;
    top: 0;
    left: -8px;
    width: 0;
    height: 0;
    border-top: 8px solid #fff;
    border-left: 8px solid transparent;
    transition: all 0.3s ease;
}

.message-info {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-top: 2px;
    gap: 4px;
    float: right;
    margin-left: 10px;
    padding-top: 4px;
}

.message-time {
    font-size: 11px;
    color: #667781;
}

.double-check {
    font-size: 11px;
}

.phone-footer {
    background-color: #f0f2f5;
    padding: 10px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 60px;
    transition: all 0.3s ease;
}

.input-fake {
    background-color: #fff;
    height: 40px;
    border-radius: 20px;
    flex-grow: 1;
    transition: all 0.3s ease;
}

.send-icon {
    background-color: #00897b;
    color: white;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
}

.home-indicator {
    position: absolute;
    bottom: 8px;
    left: 50%;
    transform: translateX(-50%);
    width: 120px;
    height: 4px;
    background-color: rgba(0,0,0,0.2);
    border-radius: 10px;
}

/* Dark Mode Styles */
.phone-mockup.dark-mode {
    box-shadow: 0 20px 40px rgba(0,0,0,0.5), 0 0 0 12px #000;
}
.phone-mockup.dark-mode .phone-status-bar,
.phone-mockup.dark-mode .phone-header {
    background-color: #1f2c34;
    color: #e9edef;
}
.phone-mockup.dark-mode .phone-body {
    background-color: #0b141a;
    background-blend-mode: overlay;
}
.phone-mockup.dark-mode .message-bubble {
    background-color: #1f2c34;
    color: #e9edef;
    box-shadow: none;
}
.phone-mockup.dark-mode .message-bubble.received::before {
    border-top: 8px solid #1f2c34;
}
.phone-mockup.dark-mode .message-time {
    color: #8696a0;
}
.phone-mockup.dark-mode .phone-footer {
    background-color: #1f2c34;
}
.phone-mockup.dark-mode .input-fake {
    background-color: #2a3942;
}
.phone-mockup.dark-mode .send-icon {
    background-color: #00a884;
}
.phone-mockup.dark-mode .home-indicator {
    background-color: rgba(255,255,255,0.2);
}
</style>

<script>
// Variables Globales
let currentActiveInput = null;
let emojiPanelEl = null;
const EMOJIS = ["😀","😁","😂","🤣","😊","🙂","😉","😍","😘","😎","🤩","🥳","😇","😅","🙃","🥰","🤗","👍","👎","🙏","👏","💪","✌️","🤝","👉","👋","✍️","✅","❌","⚠️","ℹ️","❗","❕","❓","❔","💵","💶","💷","💴","💳","🏦","🧾","📦","🛍️","🛒","📄","🧮","📈","📉","⏰","⏳","🗓️","📆","🕒","📱","💻","🖨️","🖱️","⌨️","🔌","🔋","🛠️","🔧","⚙️","🧰","🧲","📍","📌","🧭","🗺️","📞","☎️","✉️","📧","🔗","📝","📎","🖇️","🔖","🚚","🚛","🚗","🛵","🚲","⭐","✨","🎉","🎊","🔥","🚀","🎁","🔒","🔓","🛡️","🏷️","🏅","🎖️","🔢"];
const placeholderSamples = {
    cliente: "Juan Pérez",
    cliente_tel: "+57 3100000000",
    equipo: "iPhone 13 Pro",
    tipo: "Smartphone",
    marca: "Apple",
    modelo: "13 Pro",
    sn: "SN1234567890",
    orden: "12345",
    falla: "Pantalla rota",
    diagnostico: "Pantalla con grietas y daño en módulo",
    solucion: "Reemplazo de pantalla original",
    accesorios: "Funda, cargador",
    estado: "En Revisión",
    valor: "$150.00",
    total: "$150.00",
    abono: "$100.00",
    saldo: "$50.00",
    factura: "INV-001",
    fecha_entrega: "2026-02-04",
    url_seguimiento: "https://mi.taller.com/orden/12345",
    taller_nombre: "Nombre Taller",
    taller_tel: "+57 3000000000",
    detalles: "2 protectores + funda antigolpes"
};

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    // Actualizar hora del preview
    updateTime();
    setInterval(updateTime, 60000);

    // Inicializar inputs para tracking
    document.querySelectorAll('textarea').forEach(input => {
        input.addEventListener('focus', () => { currentActiveInput = input; updatePreview(input); });
        input.addEventListener('click', () => { currentActiveInput = input; updatePreview(input); });
    });

    // Preview inicial
    const firstTextarea = document.querySelector('.tab-pane.active textarea');
    if(firstTextarea) {
        currentActiveInput = firstTextarea;
        updatePreview(firstTextarea);
    }
    
    // Actualizar vista previa al cambiar de tab
    const tabs = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabs.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (event) {
            const targetId = event.target.getAttribute('data-bs-target');
            const textarea = document.querySelector(targetId + ' textarea');
            if(textarea) {
                currentActiveInput = textarea;
                updatePreview(textarea);
            }
        });
    });
});

function updateTime() {
    const now = new Date();
    let hours = now.getHours();
    let minutes = now.getMinutes();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; 
    minutes = minutes < 10 ? '0'+minutes : minutes;
    const strTime = hours + ':' + minutes + ' ' + ampm;
    const timeEl = document.getElementById('preview-time');
    if(timeEl) timeEl.innerText = strTime;
}

function toggleDarkMode() {
    const mockup = document.getElementById('phoneMockup');
    mockup.classList.toggle('dark-mode');
}

function insertVar(inputId, variable) {
    const input = document.getElementById(inputId);
    insertAtCursor(input, variable);
}

function applyFormat(inputId, tag) {
    const input = document.getElementById(inputId);
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const text = input.value;
    
    if (start === end) {
        // Nada seleccionado, insertar tag vacío o alrededor del cursor? 
        // Mejor insertar tag vacio y poner cursor en medio: **|**
        const inserted = tag + tag;
        input.value = text.substring(0, start) + inserted + text.substring(end);
        input.selectionStart = input.selectionEnd = start + tag.length;
    } else {
        // Envolver selección
        const selectedText = text.substring(start, end);
        const before = text.substring(0, start);
        const after = text.substring(end);
        input.value = before + tag + selectedText + tag + after;
        input.selectionStart = start;
        input.selectionEnd = end + (tag.length * 2);
    }
    input.focus();
    updatePreview(input);
}

function showEmojiPanel(inputId, trigger) {
    if (!emojiPanelEl) {
        emojiPanelEl = document.createElement('div');
        emojiPanelEl.style.position = 'absolute';
        emojiPanelEl.style.background = '#fff';
        emojiPanelEl.style.border = '1px solid #ddd';
        emojiPanelEl.style.borderRadius = '8px';
        emojiPanelEl.style.boxShadow = '0 6px 20px rgba(0,0,0,0.15)';
        emojiPanelEl.style.padding = '8px';
        emojiPanelEl.style.zIndex = '20000';
        emojiPanelEl.style.display = 'none';
        const grid = document.createElement('div');
        grid.style.display = 'grid';
        grid.style.gridTemplateColumns = 'repeat(6, 28px)';
        grid.style.gap = '6px';
        EMOJIS.forEach(e => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = e;
            b.style.width = '28px';
            b.style.height = '28px';
            b.style.lineHeight = '28px';
            b.style.textAlign = 'center';
            b.style.border = 'none';
            b.style.background = 'transparent';
            b.style.cursor = 'pointer';
            b.addEventListener('click', function(){
                const input = document.getElementById(inputId);
                insertAtCursor(input, e);
                hideEmojiPanel();
            });
            grid.appendChild(b);
        });
        emojiPanelEl.appendChild(grid);
        document.body.appendChild(emojiPanelEl);
        document.addEventListener('click', function(ev){
            if (!emojiPanelEl) return;
            if (emojiPanelEl.style.display === 'none') return;
            if (ev.target === trigger) return;
            if (!emojiPanelEl.contains(ev.target)) hideEmojiPanel();
        });
    }
    const rect = trigger.getBoundingClientRect();
    emojiPanelEl.style.left = (window.scrollX + rect.left) + 'px';
    emojiPanelEl.style.top = (window.scrollY + rect.bottom + 6) + 'px';
    emojiPanelEl.style.display = 'block';
}
function hideEmojiPanel(){
    if (emojiPanelEl) emojiPanelEl.style.display = 'none';
}

function insertAtCursor(input, textToInsert) {
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const text = input.value;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);
    
    input.value = before + textToInsert + after;
    input.selectionStart = input.selectionEnd = start + textToInsert.length;
    input.focus();
    updatePreview(input);
}

function updatePreview(input) {
    if(!input) return;
    let text = input.value || '';
    
    // Reemplazo conocido
    for (const k in placeholderSamples) {
        text = text.replace(new RegExp('{{\\s*'+k+'\\s*}}','g'), placeholderSamples[k]);
    }
    // Fallback para llaves desconocidas
    text = text.replace(/{{\s*([\w\.]+)\s*}}/g, function(_, key){
        return placeholderSamples[key] || '—';
    });

    text = text.replace(/</g, "&lt;").replace(/>/g, "&gt;");

    text = text.replace(/\n/g, '<br>');
    
    text = text.replace(/\*(.*?)\*/g, '<b>$1</b>'); // Negrita
    text = text.replace(/_(.*?)_/g, '<i>$1</i>');   // Cursiva
    text = text.replace(/~(.*?)~/g, '<strike>$1</strike>'); // Tachado
    
    text = normalizeEmoji(text);
    document.getElementById('preview-content').innerHTML = text;
}

function sendTest() {
    const phone = document.getElementById('test-phone').value.replace(/\D/g,'');
    if(phone.length < 10) {
        Swal.fire('Error', 'Ingresa un número de teléfono válido (con código de país)', 'warning');
        return;
    }
    
    if(!currentActiveInput) {
        const activeTab = document.querySelector('#whatsapp.tab-pane');
        const firstTextarea = activeTab ? activeTab.querySelector('textarea') : document.querySelector('textarea');
        if (firstTextarea) {
            currentActiveInput = firstTextarea;
        } else {
            Swal.fire('Atención', 'Selecciona (haz click) en el mensaje que quieres probar primero.', 'info');
            return;
        }
    }

    let text = currentActiveInput.value;
    for (const k in placeholderSamples) {
        text = text.replace(new RegExp('{{'+k+'}}','g'), placeholderSamples[k]);
    }
    text = normalizeEmoji(text);

    const base = 'https://api.whatsapp.com/send';
    const params = new URLSearchParams();
    params.set('phone', phone);
    params.set('text', text);
    const url = `${base}?${params.toString()}`;
    let win = null;
    try { win = window.open(url, '_blank'); } catch(e) {}
    if (!win) {
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }
    if (!win) {
        Swal.fire({
            icon: 'info',
            title: 'Abrir WhatsApp',
            html: `<a href="${url}" target="_blank" rel="noopener noreferrer">Haz clic aquí si el navegador bloqueó la ventana</a>`
        });
    }
}
function normalizeEmoji(text) {
    if (window.normalizeEmoji && window.normalizeEmoji !== normalizeEmoji) {
        return window.normalizeEmoji(text);
    }
    return text;
}

function restoreDefaults() {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "Se perderán tus textos personalizados y volverán a los originales.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, restaurar'
    }).then((result) => {
        if (result.isConfirmed) {
            // Recargar la página es la forma más fácil de "cancelar" cambios no guardados, 
            // pero para restaurar valores "de fábrica" necesitamos los valores JS.
            // Por simplicidad, seteamos los valores en los inputs manualmente desde un objeto JS generado por PHP.
            const defaults = <?php echo json_encode($defaults); ?>;
            
            for (const [key, value] of Object.entries(defaults)) {
                const el = document.getElementById(key);
                if(el) {
                    el.value = value;
                    if(el === currentActiveInput) updatePreview(el);
                }
            }
            saveWhatsappTemplates();
            Swal.fire('Restaurado', 'Se aplicaron las plantillas originales y se guardaron.', 'success');
        }
    })
}

function saveWhatsappTemplates() {
    const btn = document.querySelector('.btn-primary');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';

    const formData = new FormData(document.getElementById('whatsappForm'));
    formData.append('action', 'update_whatsapp_templates');
    try {
        const m = document.querySelector('meta[name="csrf-token"]');
        if (m) formData.append('csrf_token', m.getAttribute('content'));
    } catch(e) {}
    document.querySelectorAll('textarea[id^="whatsapp_template_"]').forEach(function(el){
        const key = el.id;
        const sanitized = String(el.value || '').replace(/\uFFFD/g, '');
        formData.set(key, sanitized);
    });

    function parseJsonResponse(response) {
        return response.text().then(function(text){
            const ct = (response.headers && response.headers.get && response.headers.get('content-type')) ? response.headers.get('content-type') : '';
            if (!ct || ct.indexOf('application/json') === -1) {
                return { success:false, message:'Respuesta no JSON', raw:text };
            }
            try { return JSON.parse(text); }
            catch(e){ return { success:false, message:'JSON inválido', raw:text }; }
        });
    }

    fetch('../config/config_operations.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(parseJsonResponse)
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Guardado!',
                text: 'Las plantillas se han actualizado correctamente.',
                timer: 1500,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data && data.message ? data.message : 'Error al guardar'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Ocurrió un error al guardar los cambios.'
        });
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

</script>
