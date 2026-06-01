<?php
if (!isset($tenant_id)) {
    $tenant_id = getCurrentTenantId();
}
$csrf = $_SESSION['csrf_token'] ?? '';
?>
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
            <div class="card-header bg-light d-flex align-items-center" style="border-radius: 1rem 1rem 0 0;">
                <h6 class="mb-0 text-dark">
                    <i class="fas fa-id-badge me-2"></i>Portal de Clientes
                    <span class="badge bg-info text-white no-theme ms-2">Configuración</span>
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cp_lookup_id">
                            <label class="form-check-label" for="cp_lookup_id">Permitir búsqueda por Documento/ID</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cp_show_timeline" checked>
                            <label class="form-check-label" for="cp_show_timeline">Mostrar línea de tiempo de estados</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cp_allow_approval" checked>
                            <label class="form-check-label" for="cp_allow_approval">Permitir aprobación desde el portal</label>
                        </div>
                    </div>
                </div>
                <hr class="my-4">
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold text-primary no-theme"><i class="fas fa-house me-2 text-primary no-theme"></i>Portada y Sobre la empresa</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Título principal</label>
                                <input type="text" class="form-control" id="cp_home_title" placeholder="Bienvenido">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Subtítulo</label>
                                <input type="text" class="form-control" id="cp_home_subtitle" placeholder="Conoce nuestros servicios y consulta tu orden">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Imagen Hero (URL)</label>
                                <input type="text" class="form-control" id="cp_hero_image" placeholder="https://.../hero.jpg">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Enlace WhatsApp (CTA)</label>
                                <input type="text" class="form-control" id="cp_whatsapp_link" placeholder="Se toma del número de empresa" disabled>
                                <small class="text-muted">Se usará el número de WhatsApp configurado en identidad de empresa.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sobre la empresa (texto)</label>
                                <textarea class="form-control" id="cp_about_text" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Imagen Sobre la empresa (URL)</label>
                                <input type="text" class="form-control" id="cp_about_image" placeholder="https://.../about.jpg">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold text-primary no-theme"><i class="fas fa-star me-2 text-primary no-theme"></i>Servicios destacados</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Servicios destacados</label>
                                <div id="cp_services_list" class="row g-2"></div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cp_add_service"><i class="fas fa-plus me-1"></i>Agregar servicio</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="cp_services_template"><i class="fas fa-magic me-1"></i>Usar ejemplo</button>
                                </div>
                                <div class="mt-3">
                                    <label class="form-label">Vista previa de Servicios</label>
                                    <div id="cp_services_preview" class="row g-3"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold"><i class="fab fa-hashtag me-2 text-primary"></i>Redes Sociales</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">YouTube</label>
                                <input type="text" class="form-control" id="cp_social_youtube" placeholder="https://youtube.com/...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instagram</label>
                                <input type="text" class="form-control" id="cp_social_instagram" placeholder="https://instagram.com/...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Facebook</label>
                                <input type="text" class="form-control" id="cp_social_facebook" placeholder="https://facebook.com/...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TikTok</label>
                                <input type="text" class="form-control" id="cp_social_tiktok" placeholder="https://tiktok.com/...">
                            </div>
                            <div class="col-12">
                                <div class="mt-2">
                                    <label class="form-label">Vista previa de Redes</label>
                                    <div id="cp_social_preview" class="d-flex flex-wrap gap-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold text-primary no-theme"><i class="fas fa-video me-2 text-primary no-theme"></i>Video Destacado</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Video destacado (URL)</label>
                                <input type="text" class="form-control" id="cp_featured_video_url" placeholder="https://www.facebook.com/... | https://www.youtube.com/watch?v=... | https://www.tiktok.com/@... | https://www.instagram.com/p/...">
                                <small class="text-muted">Se detecta automáticamente la orientación vertical/horizontal</small>
                                <div class="mt-2">
                                    <label class="form-label">Vista previa de Video</label>
                                    <div id="cp_video_preview"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-cards-blank me-2 text-primary"></i>Beneficios</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Beneficios (3 tarjetas)</label>
                                <div id="cp_benefits_list" class="row g-2"></div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cp_add_benefit"><i class="fas fa-plus me-1"></i>Agregar beneficio</button>
                                    <small class="text-muted ms-2">Icono puede ser clase FontAwesome (fa-solid fa-bolt) o emoji</small>
                                </div>
                                <div class="mt-3">
                                    <label class="form-label">Vista previa de Beneficios</label>
                                    <div id="cp_benefits_preview" class="row g-3"></div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="cp_benefits_template"><i class="fas fa-magic me-1"></i>Usar ejemplo</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold text-primary no-theme"><i class="fas fa-images me-2 text-primary no-theme"></i>Galería</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Galería de imágenes</label>
                                <div id="cp_gallery_list" class="row g-2"></div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cp_add_gallery"><i class="fas fa-plus me-1"></i>Agregar imagen</button>
                                    <small class="text-muted ms-2">Usa URLs públicas (JPG/PNG)</small>
                                </div>
                                <div class="mt-3">
                                    <label class="form-label">Vista previa de Galería</label>
                                    <div id="cp_gallery_preview" class="row g-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-4 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom border-light py-3">
                        <h5 class="mb-0 fw-bold text-primary no-theme"><i class="fas fa-map-location-dot me-2 text-primary no-theme"></i>Ubicación</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Mapa (Google Maps)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="cp_map_embed_url" placeholder="https://maps.google.com/maps?q=lat,lng&z=16&output=embed">
                                    <button type="button" class="btn btn-outline-primary" id="cp_open_map_picker"><i class="fas fa-map-marker-alt me-1"></i>Seleccionar en mapa</button>
                                </div>
                                <div class="mt-2 ratio ratio-16x9 rounded-4 overflow-hidden border bg-light">
                                    <iframe id="cp_map_preview_iframe" title="Vista previa de mapa" src="about:blank" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                                </div>
                                <div class="small text-muted mt-1" id="cp_map_preview_hint"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Dirección</label>
                                <input type="text" class="form-control" id="cp_address_text" placeholder="Se toma de identidad de empresa" disabled>
                                <small class="text-muted">La dirección proviene de la configuración de identidad de empresa.</small>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Horarios</label>
                                <textarea class="form-control" id="cp_hours_text" rows="2" placeholder="Lun–Vie 9:00–18:00; Sáb 10:00–14:00"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-4">
                    <a class="btn btn-outline-primary" target="_blank" href="../portal/index.php?t=<?php echo urlencode((string)$tenant_id); ?>">
                        <i class="fas fa-external-link-alt me-2"></i>Abrir Portal
                    </a>
                    <button type="button" class="btn btn-dark" id="cp_save_btn">
                        <i class="fas fa-save me-2"></i>Guardar Configuración
                    </button>
                </div>
                <div class="mt-2 small text-muted">El portal usa tu identidad de empresa y permite a los clientes consultar estado de órdenes y aprobar presupuestos con código.</div>
            </div>
        </div>
    </div>
</div>
<style>
.preview-card{background:linear-gradient(180deg,#ffffff,#f8fafc);border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.06),0 2px 4px -2px rgb(0 0 0 / 0.04);padding:16px;text-align:center;}
.preview-card .icon{font-size:2rem;margin-bottom:10px;}
.preview-service{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 2px 6px rgb(0 0 0 / 0.08);}
.preview-chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #e5e7eb;border-radius:999px;padding:6px 12px;background:#fff;font-size:.9rem;}
.preview-chip.disabled{opacity:.6;}
.preview-gallery img{width:100%;height:140px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb;}
.preview-video-box{width:100%;max-width:420px;margin:0;border:1px dashed #cbd5e1;border-radius:12px;background:#f9fafb;display:flex;align-items:center;justify-content:center;color:#64748b;font-weight:600;}
.ratio-16-9{aspect-ratio:16/9;}
.ratio-9-16{aspect-ratio:9/16;}
.config-section{background:#fff;border:0;border-radius:1rem;box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.06),0 2px 4px -2px rgb(0 0 0 / 0.04);padding:16px;margin-bottom:1rem;}
.config-section>h6{margin:0 0 12px 0;padding-bottom:12px;border-bottom:1px solid #eef;font-weight:700;}
.map-picker-container{height:360px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.map-search{position:relative}
.map-search .fa-search{cursor:pointer}
</style>
<div class="modal fade" id="mapPickerModal" tabindex="-1" aria-labelledby="mapPickerLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="mapPickerLabel"><i class="fas fa-map-marked-alt me-2 text-primary"></i>Seleccionar ubicación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="position-relative mb-2">
            <div class="input-group map-search">
              <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search" id="map_search_icon"></i></span>
              <input type="text" class="form-control border-start-0" id="map_search_input" placeholder="Buscar ciudad, dirección o lugar..." autocomplete="off">
              <button class="btn btn-outline-primary" type="button" id="map_search_btn">Buscar</button>
            </div>
            <div id="map_search_autocomplete" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1050; display: none; max-height: 220px; overflow-y: auto;"></div>
        </div>
        <div id="map_search_status" class="small text-muted mb-2"></div>
        <div id="map_picker" class="map-picker-container"></div>
        <div class="mt-2 small text-muted">Arrastra el marcador para ajustar la posición exacta. Se guardará un embed de Google Maps con las coordenadas seleccionadas.</div>
      </div>
      <div class="modal-footer">
        <div class="me-auto text-muted small" id="map_coords_display">Lat: -, Lng: -</div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-outline-primary" id="map_geolocate"><i class="fas fa-location-crosshairs me-1"></i>Mi ubicación</button>
        <button type="button" class="btn btn-primary" id="map_use_location"><i class="fas fa-check me-1"></i>Usar esta ubicación</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    const csrf = <?php echo json_encode($csrf); ?>;
    function parseJsonResponse(r){ return r.text().then(t => { try { return JSON.parse(t); } catch(e){ return { success:false, message:'Respuesta inválida' }; } }); }
    function showSuccess(msg){ try{ if(typeof Swal!=='undefined'){ Swal.fire({icon:'success', title:'Guardado', text:msg, timer:1500, showConfirmButton:false}); } else { console.log(msg); } }catch(e){} }
    function showError(msg){ try{ if(typeof Swal!=='undefined'){ Swal.fire({icon:'error', title:'Error', text:msg}); } else { alert(msg); } }catch(e){} }
    function isValidUrl(u){ 
        try { 
            if (!u) return true; 
            var v = u.trim().toLowerCase();
            if (v.indexOf('<iframe') !== -1) return true;
            if (v.indexOf('http') === 0) return true;
            if (v.length > 3) return true; 
            return false; 
        } catch(e){ return false; } 
    }
    function validateUrlField(el){ try { var v=el.value||''; if (!v) { el.classList.remove('is-invalid'); return; } if (isValidUrl(v)) { el.classList.remove('is-invalid'); } else { el.classList.add('is-invalid'); } } catch(e){} }
    function normalizeMapUrl(u){
        try{
            var v=(u||'').trim(); if(v==='') return '';
            var lower = v.toLowerCase();
            if (lower.indexOf('<iframe') !== -1) {
                var mSrc = v.match(/src\s*=\s*["']([^"']+)["']/i);
                if (mSrc && mSrc[1]) v = String(mSrc[1]).trim();
            }
            if (v.indexOf('http://') !== 0 && v.indexOf('https://') !== 0) {
                return 'https://maps.google.com/maps?q=' + encodeURIComponent(v) + '&z=16&output=embed';
            }
            var a=document.createElement('a'); a.href=v;
            var h=(a.hostname||'').toLowerCase(); var p=(a.pathname||'').toLowerCase(); var q=a.search?a.search.substring(1):'';
            var isGoogle=(h.indexOf('google.com')!==-1)||(h.indexOf('maps.google.com')!==-1);
            var isShort=(h.indexOf('maps.app.goo.gl')!==-1)||(h.indexOf('goo.gl')!==-1);
            var def='https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3284.016887889476!2d-58.3815704!3d-34.6037389!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x95bccac683e02371%3A0x24a8a30f3a93343!2sObelisco!5e0!3m2!1ses!2sar!4v1678886';
            if(isShort) return def;
            if(isGoogle){
                if(p.indexOf('/maps/embed')!==-1){
                    if(q.toLowerCase().indexOf('output=embed')===-1){ return v + (v.indexOf('?')!==-1?'&':'?') + 'output=embed'; }
                    return v;
                }
                if(p.indexOf('/maps')!==-1){
                    if (q) {
                        var base='https://www.google.com/maps/embed?' + q;
                        if(q.toLowerCase().indexOf('output=embed')===-1){ base += (q ? '&' : '') + 'output=embed'; }
                        return base;
                    }
                    return 'https://maps.google.com/maps?q=' + encodeURIComponent(v) + '&z=16&output=embed';
                }
                return def;
            }
            return v;
        }catch(e){ return u; }
    }
    function addBenefitRow(val){
        const list = document.getElementById('cp_benefits_list');
        const div = document.createElement('div'); div.className='col-md-4';
        div.innerHTML = '<div class="border rounded p-2">'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Título" data-f="title" value="'+(val?.title||'')+'"></div>'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Icono/Emoji" data-f="icon" value="'+(val?.icon||'')+'"></div>'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Descripción corta" data-f="desc" value="'+(val?.desc||'')+'"></div>'
            + '<div class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Eliminar</button></div>'
            + '</div>';
        list.appendChild(div);
        div.querySelector('[data-act="del"]').addEventListener('click', function(){ div.remove(); renderBenefitsPreview(); });
        div.querySelectorAll('input').forEach(function(inp){ inp.addEventListener('input', renderBenefitsPreview); });
        renderBenefitsPreview();
    }
    function addGalleryRow(val){
        const list = document.getElementById('cp_gallery_list');
        const div = document.createElement('div'); div.className='col-md-6';
        div.innerHTML = '<div class="border rounded p-2">'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="URL de imagen" data-f="url" value="'+(val||'')+'"></div>'
            + '<div class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Eliminar</button></div>'
            + '</div>';
        list.appendChild(div);
        div.querySelector('[data-act="del"]').addEventListener('click', function(){ div.remove(); renderGalleryPreview(); });
        div.querySelectorAll('input').forEach(function(inp){ 
            inp.addEventListener('input', function(){ renderGalleryPreview(); validateUrlField(inp); }); 
            validateUrlField(inp);
        });
        renderGalleryPreview();
    }
    function addServiceRow(val){
        const list = document.getElementById('cp_services_list');
        const div = document.createElement('div'); div.className='col-md-6';
        div.innerHTML = '<div class="border rounded p-2">'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Nombre" data-f="name" value="'+(val?.name||'')+'"></div>'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Icono/Emoji (opcional)" data-f="icon" value="'+(val?.icon||'')+'"></div>'
            + '<div class="mb-2"><input type="text" class="form-control" placeholder="Descripción corta" data-f="desc" value="'+(val?.desc||'')+'"></div>'
            + '<div class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Eliminar</button></div>'
            + '</div>';
        list.appendChild(div);
        div.querySelector('[data-act="del"]').addEventListener('click', function(){ div.remove(); renderServicesPreview(); });
        div.querySelectorAll('input').forEach(function(inp){ inp.addEventListener('input', renderServicesPreview); });
        renderServicesPreview();
    }
    function useServicesTemplate(){
        const list = document.getElementById('cp_services_list'); if (!list) return;
        list.innerHTML = '';
        addServiceRow({ name:'Cambio de Pantalla', icon:'fa-solid fa-mobile-screen', desc:'Reemplazo de pantallas rotas o dañadas' });
        addServiceRow({ name:'Batería', icon:'fa-solid fa-battery-half', desc:'Diagnóstico y cambio de batería' });
        addServiceRow({ name:'Mantenimiento', icon:'fa-solid fa-screwdriver-wrench', desc:'Limpieza interna y optimización del sistema' });
        renderServicesPreview();
    }
    function renderBenefitsPreview(){
        const wrap = document.getElementById('cp_benefits_preview'); if (!wrap) return;
        wrap.innerHTML = '';
        const rows = document.querySelectorAll('#cp_benefits_list .col-md-4');
        rows.forEach(function(el){
            const title = el.querySelector('[data-f="title"]')?.value||'';
            const icon = el.querySelector('[data-f="icon"]')?.value||'';
            const desc = el.querySelector('[data-f="desc"]')?.value||'';
            const col = document.createElement('div'); col.className='col-md-4';
            const isFa = icon.indexOf('fa-') !== -1;
            col.innerHTML = '<div class="preview-card">'
                + '<div class="icon">'+(isFa?('<i class="'+icon+'"></i>'):icon)+'</div>'
                + '<h6 class="fw-semibold">'+(title||'Título')+'</h6>'
                + '<div class="text-muted small">'+(desc||'Descripción breve')+'</div>'
                + '</div>';
            wrap.appendChild(col);
        });
    }
    function renderServicesPreview(){
        const wrap = document.getElementById('cp_services_preview'); if (!wrap) return;
        wrap.innerHTML = '';
        const rows = document.querySelectorAll('#cp_services_list .col-md-6');
        rows.forEach(function(el){
            const name = el.querySelector('[data-f="name"]')?.value||'';
            const icon = el.querySelector('[data-f="icon"]')?.value||'fa-solid fa-gear';
            const desc = el.querySelector('[data-f="desc"]')?.value||'';
            const col = document.createElement('div'); col.className='col-md-4';
            col.innerHTML = '<div class="preview-service">'
                + '<div class="icon"><i class="'+icon+'"></i></div>'
                + '<h6 class="fw-semibold mb-1">'+(name||'Servicio')+'</h6>'
                + '<div class="text-muted small">'+(desc||'Descripción breve')+'</div>'
                + '</div>';
            wrap.appendChild(col);
        });
    }
    function renderGalleryPreview(){
        const wrap = document.getElementById('cp_gallery_preview'); if (!wrap) return;
        wrap.className = 'row g-2 preview-gallery';
        wrap.innerHTML = '';
        const rows = document.querySelectorAll('#cp_gallery_list .col-md-6');
        rows.forEach(function(el){
            const url = el.querySelector('[data-f="url"]')?.value||'';
            const col = document.createElement('div'); col.className='col-md-4';
            col.innerHTML = '<img src="'+(url||'https://picsum.photos/seed/preview/600/400')+'" alt="preview">';
            wrap.appendChild(col);
        });
    }
    function renderSocialPreview(){
        const wrap = document.getElementById('cp_social_preview'); if (!wrap) return;
        wrap.innerHTML = '';
        const entries = [
            {label:'YouTube', icon:'fab fa-youtube', val: document.getElementById('cp_social_youtube').value||''},
            {label:'Instagram', icon:'fab fa-instagram', val: document.getElementById('cp_social_instagram').value||''},
            {label:'Facebook', icon:'fab fa-facebook', val: document.getElementById('cp_social_facebook').value||''},
            {label:'TikTok', icon:'fab fa-tiktok', val: document.getElementById('cp_social_tiktok').value||''}
        ];
        entries.forEach(function(e){
            const chip = document.createElement('div'); chip.className='preview-chip'+(e.val?'':' disabled');
            chip.innerHTML = '<i class="'+e.icon+'"></i><span>'+e.label+'</span>';
            wrap.appendChild(chip);
        });
    }
    function renderVideoPreview(){
        const el = document.getElementById('cp_video_preview'); if (!el) return;
        const url = document.getElementById('cp_featured_video_url').value||'';
        const lower = url.toLowerCase();
        var orientation = '16-9';
        if (lower.indexOf('shorts')!==-1 || lower.indexOf('tiktok.com')!==-1 || lower.indexOf('instagram.com')!==-1 || lower.indexOf('facebook.com')!==-1) { orientation = '9-16'; }
        el.className = 'preview-video-box ratio-'+orientation;
        el.textContent = orientation === '16-9' ? '16:9' : '9:16';
    }
    var mapObj=null, mapMarker=null;
    function ensureLeaflet(callback){
        if (window.L && typeof L.map==='function') { callback(); return; }
        var css=document.createElement('link'); css.rel='stylesheet'; css.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        var js=document.createElement('script'); js.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        js.onload=function(){ callback(); };
        document.head.appendChild(css); document.head.appendChild(js);
    }
    function initMapPicker(){
        var el=document.getElementById('map_picker'); if (!el) return;
        var currentEmbed=document.getElementById('cp_map_embed_url').value||'';
        var lat=4.7110, lng=-74.0721, zoom=12;
        var m=currentEmbed.match(/q=([0-9\.\-]+),([0-9\.\-]+)/); if (m){ lat=parseFloat(m[1]); lng=parseFloat(m[2]); zoom=15; }
        
        if (mapObj) {
            mapObj.setView([lat, lng], zoom);
            if (mapMarker) mapMarker.setLatLng([lat, lng]);
            setTimeout(function() { mapObj.invalidateSize(); }, 200);
            return;
        }

        mapObj=L.map('map_picker').setView([lat,lng], zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(mapObj);
        mapMarker=L.marker([lat,lng], { draggable:true }).addTo(mapObj);
        mapMarker.on('dragend', function(){ var p=mapMarker.getLatLng(); updateCoordsDisplay(p.lat, p.lng); setSearchStatus('Ubicación ajustada (arrastra/selecciona y luego “Usar esta ubicación”).', 'ok'); });
        if (!mapObj.__pickClickBound) {
            mapObj.__pickClickBound = true;
            mapObj.on('click', function(e){
                if (!mapMarker) return;
                mapMarker.setLatLng(e.latlng);
                updateCoordsDisplay(e.latlng.lat, e.latlng.lng);
                setSearchStatus('Ubicación seleccionada en el mapa.', 'ok');
            });
        }
        updateCoordsDisplay(lat, lng);
        setTimeout(function() { mapObj.invalidateSize(); }, 200);
    }
    function updateCoordsDisplay(lat,lng){
        var el=document.getElementById('map_coords_display'); if (el) el.textContent='Lat: '+lat.toFixed(6)+', Lng: '+lng.toFixed(6);
    }
    function setSearchStatus(msg, type){
        var el=document.getElementById('map_search_status'); if (!el) return;
        el.textContent=msg||'';
        el.className='small mb-2 ' + (type==='error' ? 'text-danger' : type==='ok' ? 'text-success' : 'text-muted');
    }
    
    var searchTimeout = null;
    function setupAutocomplete() {
        var input = document.getElementById('map_search_input');
        var autoList = document.getElementById('map_search_autocomplete');
        if (!input || !autoList) return;

        input.addEventListener('input', function(e) {
            var q = e.target.value.trim();
            if (q.length < 3) {
                autoList.style.display = 'none';
                return;
            }
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                var btnStatus = document.getElementById('map_search_icon');
                if(btnStatus) { btnStatus.className = 'fas fa-spinner fa-spin text-primary'; }
                
                fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=' + encodeURIComponent(q))
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if(btnStatus) { btnStatus.className = 'fas fa-search'; }
                        autoList.innerHTML = '';
                        if (data && data.length > 0) {
                            data.forEach(function(item) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'list-group-item list-group-item-action text-start small text-truncate';
                                btn.innerHTML = '<i class="fas fa-map-marker-alt text-muted me-2"></i>' + item.display_name;
                                btn.onclick = function() {
                                    input.value = item.display_name;
                                    autoList.style.display = 'none';
                                    
                                    var lat = parseFloat(item.lat);
                                    var lng = parseFloat(item.lon);
                                    
                                    if (mapObj && mapMarker) {
                                        mapObj.setView([lat, lng], 16);
                                        mapMarker.setLatLng([lat, lng]);
                                        setTimeout(function() { mapObj.invalidateSize(); }, 300);
                                    }
                                    updateCoordsDisplay(lat, lng);
                                    
                                    var embed = buildGoogleEmbedFromCoords(lat, lng);
                                    var mu = document.getElementById('cp_map_embed_url'); 
                                    if (mu) { mu.value = embed; validateUrlField(mu); }
                                    syncMapPreviewFromField();
                                    
                                    setSearchStatus('Ubicación seleccionada: ' + item.display_name, 'ok');
                                };
                                autoList.appendChild(btn);
                            });
                            autoList.style.display = 'block';
                        } else {
                            autoList.style.display = 'none';
                        }
                    })
                    .catch(function(e) {
                        if(btnStatus) { btnStatus.className = 'fas fa-search'; }
                        console.error(e);
                    });
            }, 500);
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !autoList.contains(e.target)) {
                autoList.style.display = 'none';
            }
        });
    }

    function searchPlace(q){
        if (!q) return;
        setSearchStatus('Buscando...', 'info');
        
        var autoList = document.getElementById('map_search_autocomplete');
        if (autoList) autoList.style.display = 'none';
        
        var icon = document.getElementById('map_search_icon');
        if (icon) icon.className = 'fas fa-spinner fa-spin text-primary';

        fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q))
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (icon) icon.className = 'fas fa-search';
                if (data && data.length > 0) {
                    var lat = parseFloat(data[0].lat);
                    var lng = parseFloat(data[0].lon);
                    
                    if (mapObj && mapMarker) {
                        mapObj.setView([lat, lng], 16);
                        mapMarker.setLatLng([lat, lng]);
                        setTimeout(function(){ mapObj.invalidateSize(); }, 300);
                    }
                    updateCoordsDisplay(lat, lng);
                    
                    var embed = buildGoogleEmbedFromCoords(lat, lng);
                    var mu = document.getElementById('cp_map_embed_url'); 
                    if (mu) { 
                        mu.value = embed; 
                        validateUrlField(mu); 
                    }
                    syncMapPreviewFromField();
                    
                    setSearchStatus('Ubicación encontrada: ' + data[0].display_name, 'ok');
                } else {
                    setSearchStatus('No se encontraron resultados para esta búsqueda.', 'error');
                }
            })
            .catch(function(e) {
                if (icon) icon.className = 'fas fa-search';
                console.error(e);
                setSearchStatus('Error de conexión al buscar.', 'error');
            });
    }
    function buildGoogleEmbedFromCoords(lat,lng){
        return 'https://maps.google.com/maps?q='+lat+','+lng+'&z=16&output=embed';
    }
    function setMapPreview(url){
        var iframe = document.getElementById('cp_map_preview_iframe');
        var hint = document.getElementById('cp_map_preview_hint');
        if (!iframe) return;
        var v = (url || '').trim();
        if (v === '') {
            iframe.src = 'about:blank';
            if (hint) hint.textContent = 'Sin mapa configurado';
            return;
        }
        iframe.src = v;
        if (hint) {
            var m = v.match(/q=([0-9\.\-]+),([0-9\.\-]+)/);
            hint.textContent = m ? ('Coordenadas: ' + m[1] + ', ' + m[2]) : 'Mapa configurado';
        }
    }
    function syncMapPreviewFromField(){
        var mu = document.getElementById('cp_map_embed_url');
        if (!mu) return;
        var nv = normalizeMapUrl(mu.value || '');
        if (nv !== mu.value) mu.value = nv;
        validateUrlField(mu);
        setMapPreview(nv);
    }
    function loadCfg(){
        fetch('order_statuses_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_client_portal_config&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(parseJsonResponse)
        .then(d => {
            if (d && d.success && d.data) {
                document.getElementById('cp_lookup_id').checked = (String(d.data.client_portal_enable_lookup_by_id||'0') === '1');
                document.getElementById('cp_show_timeline').checked = (String(d.data.client_portal_show_timeline||'1') === '1');
                document.getElementById('cp_allow_approval').checked = (String(d.data.client_portal_allow_approval||'1') === '1');
                document.getElementById('cp_home_title').value = d.data.client_portal_home_title||'';
                document.getElementById('cp_home_subtitle').value = d.data.client_portal_home_subtitle||'';
                var heroEl = document.getElementById('cp_hero_image'); heroEl.value = d.data.client_portal_hero_image||''; validateUrlField(heroEl);
                document.getElementById('cp_whatsapp_link').value = d.data.client_portal_whatsapp_link||'';
                document.getElementById('cp_about_text').value = d.data.client_portal_about_text||'';
                var aboutImgEl = document.getElementById('cp_about_image'); aboutImgEl.value = d.data.client_portal_about_image||''; validateUrlField(aboutImgEl);
                var fvEl = document.getElementById('cp_featured_video_url'); fvEl.value = d.data.client_portal_featured_video_url||''; validateUrlField(fvEl);
                try {
                    const svcs = JSON.parse(d.data.client_portal_services||'[]'); 
                    (Array.isArray(svcs)?svcs:[]).forEach(addServiceRow);
                } catch(e){}
                try {
                    const s = JSON.parse(d.data.client_portal_social_links||'{}');
                    var sy=document.getElementById('cp_social_youtube'); sy.value = s.youtube||''; validateUrlField(sy);
                    var si=document.getElementById('cp_social_instagram'); si.value = s.instagram||''; validateUrlField(si);
                    var sf=document.getElementById('cp_social_facebook'); sf.value = s.facebook||''; validateUrlField(sf);
                    var st=document.getElementById('cp_social_tiktok'); st.value = s.tiktok||''; validateUrlField(st);
                } catch(e){}
                try {
                    const b = JSON.parse(d.data.client_portal_benefits||'[]');
                    (Array.isArray(b)?b:[]).forEach(addBenefitRow);
                } catch(e){}
                try {
                    const g = JSON.parse(d.data.client_portal_gallery_images||'[]');
                    (Array.isArray(g)?g:[]).forEach(addGalleryRow);
                } catch(e){}
                var mu=document.getElementById('cp_map_embed_url'); mu.value = d.data.client_portal_map_embed_url||''; validateUrlField(mu);
                document.getElementById('cp_address_text').value = d.data.client_portal_address_text||'';
                document.getElementById('cp_hours_text').value = d.data.client_portal_hours_text||'';
                renderSocialPreview();
                renderVideoPreview();
                syncMapPreviewFromField();
            }
        });
    }
    function saveCfg(){
        const fd = new URLSearchParams();
        fd.append('action','save_client_portal_config');
        fd.append('csrf_token', csrf);
        fd.append('enable_lookup_by_id', document.getElementById('cp_lookup_id').checked ? '1':'0');
        fd.append('show_timeline', document.getElementById('cp_show_timeline').checked ? '1':'0');
        fd.append('allow_approval', document.getElementById('cp_allow_approval').checked ? '1':'0');
        fd.append('home_title', document.getElementById('cp_home_title').value||'');
        fd.append('home_subtitle', document.getElementById('cp_home_subtitle').value||'');
        fd.append('hero_image', document.getElementById('cp_hero_image').value||'');
        fd.append('whatsapp_link', document.getElementById('cp_whatsapp_link').value||'');
        fd.append('about_text', document.getElementById('cp_about_text').value||'');
        fd.append('about_image', document.getElementById('cp_about_image').value||'');
        fd.append('featured_video_url', document.getElementById('cp_featured_video_url').value||'');
        const svcs = [];
        document.querySelectorAll('#cp_services_list [data-f="name"]').forEach(function(_,i){});
        document.querySelectorAll('#cp_services_list .col-md-6').forEach(function(el){
            const name = el.querySelector('[data-f="name"]')?.value||'';
            const icon = el.querySelector('[data-f="icon"]')?.value||'';
            const desc = el.querySelector('[data-f="desc"]')?.value||'';
            if (name.trim()!=='' || desc.trim()!==''){ svcs.push({name, icon, desc}); }
        });
        fd.append('services_json', JSON.stringify(svcs));
        const benefits = [];
        document.querySelectorAll('#cp_benefits_list .col-md-4').forEach(function(el){
            const title = el.querySelector('[data-f="title"]')?.value||'';
            const icon = el.querySelector('[data-f="icon"]')?.value||'';
            const desc = el.querySelector('[data-f="desc"]')?.value||'';
            if (title.trim()!=='' || desc.trim()!==''){ benefits.push({title, icon, desc}); }
        });
        fd.append('benefits_json', JSON.stringify(benefits));
        const gallery = [];
        document.querySelectorAll('#cp_gallery_list .col-md-6').forEach(function(el){
            const url = el.querySelector('[data-f="url"]')?.value||'';
            if (url.trim()!==''){ gallery.push(url); }
        });
        fd.append('gallery_json', JSON.stringify(gallery));
        const social = {
            youtube: document.getElementById('cp_social_youtube').value||'',
            instagram: document.getElementById('cp_social_instagram').value||'',
            facebook: document.getElementById('cp_social_facebook').value||'',
            tiktok: document.getElementById('cp_social_tiktok').value||''
        };
        fd.append('social_json', JSON.stringify(social));
        var muVal = document.getElementById('cp_map_embed_url').value||'';
        muVal = normalizeMapUrl(muVal);
        fd.append('map_embed_url', muVal);
        fd.append('address_text', document.getElementById('cp_address_text').value||'');
        fd.append('hours_text', document.getElementById('cp_hours_text').value||'');
        fetch('order_statuses_ajax.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: fd.toString() })
        .then(parseJsonResponse)
        .then(resp => { if (resp && resp.success){ showSuccess('Configuración guardada'); } else { showError(resp && resp.message ? resp.message : 'No se pudo guardar'); } })
        .catch(() => showError('Error de red'));
    }
    document.addEventListener('DOMContentLoaded', function(){
        loadCfg();
        const btn = document.getElementById('cp_save_btn');
        if (btn) btn.addEventListener('click', saveCfg);
        const addBtn = document.getElementById('cp_add_service');
        if (addBtn) addBtn.addEventListener('click', function(){ addServiceRow({}); });
        const servicesTplBtn = document.getElementById('cp_services_template');
        if (servicesTplBtn) servicesTplBtn.addEventListener('click', useServicesTemplate);
        const addBenefitBtn = document.getElementById('cp_add_benefit');
        if (addBenefitBtn) addBenefitBtn.addEventListener('click', function(){ addBenefitRow({}); });
        const addGalleryBtn = document.getElementById('cp_add_gallery');
        if (addGalleryBtn) addGalleryBtn.addEventListener('click', function(){ addGalleryRow(''); });
        const tplBtn = document.getElementById('cp_benefits_template');
        if (tplBtn) {
            tplBtn.addEventListener('click', function(){
                const list = document.getElementById('cp_benefits_list'); if (!list) return;
                list.innerHTML = '';
                addBenefitRow({ title:'Diagnóstico Rápido', icon:'fa-solid fa-bolt', desc:'Evaluación inicial en minutos.' });
                addBenefitRow({ title:'Reparación Especializada', icon:'fa-solid fa-microchip', desc:'Equipamiento profesional y técnicos expertos.' });
                addBenefitRow({ title:'Garantía de Servicio', icon:'fa-solid fa-shield-heart', desc:'Confianza y respaldo en cada reparación.' });
                renderBenefitsPreview();
            });
        }
        ['cp_social_youtube','cp_social_instagram','cp_social_facebook','cp_social_tiktok'].forEach(function(id){
            var el = document.getElementById(id); 
            if (el) {
                el.addEventListener('input', function(){ renderSocialPreview(); validateUrlField(el); });
                validateUrlField(el);
            }
        });
        var fv = document.getElementById('cp_featured_video_url'); 
        if (fv) {
            fv.addEventListener('input', function(){ renderVideoPreview(); validateUrlField(fv); });
            validateUrlField(fv);
        }
        var hi = document.getElementById('cp_hero_image'); if (hi) hi.addEventListener('input', function(){ validateUrlField(hi); });
        var ai = document.getElementById('cp_about_image'); if (ai) ai.addEventListener('input', function(){ validateUrlField(ai); });
        var mu = document.getElementById('cp_map_embed_url'); if (mu) mu.addEventListener('input', function(){ validateUrlField(mu); });
        if (mu) mu.addEventListener('input', syncMapPreviewFromField);
        if (mu) mu.addEventListener('blur', syncMapPreviewFromField);
        var openPicker=document.getElementById('cp_open_map_picker');
        if (openPicker){
            openPicker.addEventListener('click', function(){
                var modalEl=document.getElementById('mapPickerModal');
                try {
                    if (modalEl && modalEl.parentElement !== document.body) {
                        document.body.appendChild(modalEl);
                    }
                    modalEl.style.zIndex = '1065';
                    var modal=new bootstrap.Modal(modalEl);
                    modalEl.addEventListener('shown.bs.modal', function(){
                        ensureLeaflet(function(){
                            setTimeout(initMapPicker, 100);
                        });
                    });
                    
                    if (!modalEl.hasAttribute('data-init')) {
                        modalEl.setAttribute('data-init', '1');
                        var icon=document.getElementById('map_search_icon');
                        var input=document.getElementById('map_search_input');
                        var btn=document.getElementById('map_search_btn');
                        if (icon && input){
                            icon.addEventListener('click', function(){ searchPlace(input.value); });
                        }
                        if (btn && input){
                            btn.addEventListener('click', function(){ searchPlace(input.value); });
                        }
                        if (input){
                            input.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); searchPlace(input.value); } });
                        }
                        setupAutocomplete();
                    }
                    
                    modal.show();
                } catch(e){
                    alert('No se pudo abrir el selector de mapa. ' + (e && e.message ? e.message : ''));
                }
            });
        }
        var geoBtn = document.getElementById('map_geolocate');
        if (geoBtn) {
            geoBtn.addEventListener('click', function(){
                if (!navigator.geolocation) {
                    setSearchStatus('Tu navegador no soporta geolocalización.', 'error');
                    return;
                }
                setSearchStatus('Obteniendo tu ubicación...', 'info');
                navigator.geolocation.getCurrentPosition(function(pos){
                    var lat = pos.coords.latitude;
                    var lng = pos.coords.longitude;
                    if (mapObj && mapMarker) {
                        mapObj.setView([lat, lng], 16);
                        mapMarker.setLatLng([lat, lng]);
                        setTimeout(function(){ mapObj.invalidateSize(); }, 300);
                    }
                    updateCoordsDisplay(lat, lng);
                    setSearchStatus('Ubicación actual detectada.', 'ok');
                }, function(){
                    setSearchStatus('No se pudo obtener tu ubicación.', 'error');
                }, { enableHighAccuracy: true, timeout: 8000 });
            });
        }
        var useBtn=document.getElementById('map_use_location');
        if (useBtn){
            useBtn.addEventListener('click', function(){
                if (mapMarker){
                    var p=mapMarker.getLatLng();
                    var url=buildGoogleEmbedFromCoords(p.lat, p.lng);
                    var mu=document.getElementById('cp_map_embed_url'); mu.value=url; validateUrlField(mu);
                    syncMapPreviewFromField();
                    var modalEl=document.getElementById('mapPickerModal'); var modal=bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            });
        }
    });
})();
</script>
