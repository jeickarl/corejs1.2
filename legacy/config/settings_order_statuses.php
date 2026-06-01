<style>
/* Diseño Responsivo para Tabla de Estados de Órdenes */
@media (max-width: 767.98px) {
    #order-statuses-table thead { display: none; }
    #order-statuses-table, #order-statuses-table tbody, #order-statuses-table tr, #order-statuses-table td { display: block; width: 100%; }
    #order-statuses-table tr { margin-bottom: 1rem; background-color: #fff; border: 1px solid rgba(0,0,0,0.1); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); position: relative; }
    #order-statuses-table td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 0.75rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.05); }
    #order-statuses-table td::before { content: attr(data-label); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; width: 35%; flex-shrink: 0; margin-right: 1rem; text-align: left; }
    #order-statuses-table td:last-child { border-bottom: none; background-color: #f8f9fa; font-weight: bold; border-radius: 0 0 0.75rem 0.75rem; }
    .drag-handle { position: absolute; right: 10px; top: 10px; padding: 10px; z-index: 10; font-size: 1.2rem; }
}
</style>
<div class="tab-pane" id="order-statuses" role="tabpanel">
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 text-center text-sm-start" style="border-radius: 1rem 1rem 0 0;">
                <h5 class="mb-0 text-dark w-100 w-sm-auto">
                    <i class="fas fa-list-alt me-2"></i>Estados Configurados
                </h5>
                <div class="d-flex gap-2 w-100 w-sm-auto flex-column flex-sm-row align-items-end justify-content-sm-end text-end">
                    <button class="btn btn-primary rounded-pill shadow-sm" onclick="openCreateStatusModal()">
                        <i class="fas fa-plus me-2"></i>Nuevo Estado
                    </button>
                </div>
            </div>
            <div class="card-body p-4">
                <div id="order-statuses-content">
                        <!-- El contenido se cargará dinámicamente -->
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="mt-3 text-muted">Cargando estados...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Función para cargar estados de órdenes
    function loadOrderStatuses() {
        fetch('order_statuses_ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: 'action=get_all&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
        })
        .then(parseJsonResponse)
        .then(data => {
            if (data.success) {
                // Verificar que el elemento existe antes de modificarlo
                const content = document.getElementById('order-statuses-content');
                if (!content) return;
                
                if (data.data.length === 0) {
                    content.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No hay estados configurados</h5>
                            <p class="text-muted mb-4">Agrega el primer estado para comenzar.</p>
                            <div class="d-flex justify-content-end">
                                <button class="btn btn-primary rounded-pill" onclick="openCreateStatusModal()">
                                    <i class="fas fa-plus me-2"></i>Nuevo Estado
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    let html = `
                        <div class="d-flex justify-content-between align-items-center mb-2 sticky-top bg-white py-2" style="z-index:10;">
                            <div>
                                <div class="small text-muted">Arrastra el ícono para reordenar los estados. Luego guarda.</div>
                                <div class="small text-info">Aviso: este orden afecta todo el sistema (Editar Orden, Portal, Listado). Las tarjetas del dashboard se configuran aparte y su orden no cambia aquí.</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button id="applyStatusStyle" class="btn btn-sm btn-outline-primary rounded-pill"><i class="fas fa-magic me-1"></i>Emojis/Colores</button>
                                <button id="saveSortOrder" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-save me-1"></i>Guardar orden</button>
                            </div>
                        </div>
                        <div id="order-statuses-table-wrapper" class="table-responsive">
                            <table id="order-statuses-table" class="table table-hover align-middle" data-source="ajax">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px;" class="text-center"><i class="fas fa-arrows-alt-v"></i></th>
                                        <th>Estado</th>
                                        <th style="width:80px;">Orden</th>
                                        <th>Color</th>
                                        <th>Descripción</th>
                                        <th>Por Defecto</th>
                                        <th>Activo</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>`;
                    // Mapa de emojis por slug (igual a órdenes)
                    // Emojis con escapes Unicode para evitar problemas de codificación del archivo
                    const emojiBySlug = {
                        pending: '\u23F3',
                        received: '\uD83D\uDCE6',
                        diagnosing: '\uD83D\uDD0D',
                        esperando_aprobacion: '\u270D\uFE0F',
                        waiting_parts: '\u23F8\uFE0F',
                        repairing: '\uD83D\uDD27',
                        testing: '\uD83E\uDDEA',
                        completed: '\u2705',
                        delivered: '\uD83D\uDE9A',
                        cancelled: '\u274C',
                        devolucion: '\u21A9\uFE0F',
                        cancelado: '\u274C',
                        entregado: '\uD83D\uDE9A'
                    };
                    let posCounter = 1;
                    data.data.forEach(status => {
                        const rawEmoji = String(status.emoji || '').trim();
                        const emoji = (rawEmoji && !/^\?+$/.test(rawEmoji)) ? rawEmoji : (emojiBySlug[String(status.slug||'').trim()] || '❓');
                        const iconHtml = `<span class="me-1">${emoji}</span>`;
                        html += `
                            <tr draggable="true" data-id="${status.id}">
                                <td class="text-center" data-label="Mover">
                                    <span class="drag-handle" title="Arrastrar para reordenar" style="cursor: grab;">
                                        <i class="fas fa-grip-lines-vertical text-secondary fs-5"></i>
                                    </span>
                                </td>
                                <td data-label="Estado" class="text-end text-md-start">
                                    <span class="badge rounded-pill" style="background-color: ${status.color}; color: white; font-size: 0.9em; padding: 8px 12px;">
                                        ${iconHtml} <strong class="me-1">${posCounter}.</strong> ${status.name}
                                    </span>
                                </td>
                                <td data-label="Orden" class="text-end text-md-center">
                                    <code class="text-secondary">${parseInt(status.sort_order ?? 0, 10)}</code>
                                </td>
                                <td data-label="Color" class="text-end text-md-start">
                                    <div class="d-flex align-items-center justify-content-end justify-content-md-start">
                                        <div class="color-preview me-2 shadow-sm" style="width: 24px; height: 24px; background-color: ${status.color}; border-radius: 50%; border: 2px solid #fff;"></div>
                                        <code class="text-muted">${status.color}</code>
                                    </div>
                                </td>
                                <td data-label="Descripción" class="text-muted text-end text-md-start">${status.description || '-'}</td>
                                <td data-label="Por Defecto" class="text-end text-md-center">${status.is_default ? '<i class="fas fa-check-circle text-success fa-lg"></i>' : '<i class="fas fa-circle text-light"></i>'}</td>
                                <td data-label="Activo" class="text-end text-md-start">${status.is_active ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Activo</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3">Inactivo</span>'}</td>
                                <td data-label="Acciones" class="text-end text-md-start">
                                    <div class="btn-group w-100 w-md-auto justify-content-end" role="group">
                                        <button 
                                            class="btn btn-sm btn-outline-primary rounded-pill me-1" 
                                            onclick="editOrderStatus(this, ${status.id})" 
                                            title="Editar"
                                            data-id="${status.id}"
                                            data-slug="${String(status.slug||'').replace(/\"/g,'&quot;')}"
                                            data-name="${String(status.name||'').replace(/\"/g,'&quot;')}"
                                            data-emoji="${String(status.emoji||'').replace(/\"/g,'&quot;')}"
                                            data-color="${String(status.color||'').replace(/\"/g,'&quot;')}"
                                            data-description="${String(status.description||'').replace(/\"/g,'&quot;')}"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger rounded-pill" onclick="deleteOrderStatus(${status.id}, '${status.name}')" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `;
                        posCounter++;
                    });
                    html += '</tbody></table></div>';
                    html += '<div id="order-summary" class="mt-3"></div>';
                    content.innerHTML = html;
                    // Drag & Drop por filas usando el ícono como handle
                    const tbody = content.querySelector('tbody');
                    let draggingRow = null;
                    tbody.addEventListener('dragstart', function(e) {
                        const row = e.target.closest('tr[draggable="true"]');
                        if (!row) return;
                        if (!e.target.closest('.drag-handle')) {
                            e.preventDefault();
                            return;
                        }
                        draggingRow = row;
                        row.classList.add('opacity-50');
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    tbody.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        const row = e.target.closest('tr[draggable="true"]');
                        if (!row || row === draggingRow) return;
                        const rect = row.getBoundingClientRect();
                        const after = (e.clientY - rect.top) > rect.height / 2;
                        tbody.insertBefore(draggingRow, after ? row.nextSibling : row);
                    });
                    ['drop','dragend'].forEach(evt => {
                        tbody.addEventListener(evt, function() {
                            if (draggingRow) draggingRow.classList.remove('opacity-50');
                            draggingRow = null;
                        });
                    });
                    const saveBtn = document.getElementById('saveSortOrder');
                    if (saveBtn) {
                        saveBtn.addEventListener('click', function() {
                            const ids = Array.from(tbody.querySelectorAll('tr[draggable="true"]')).map(tr => parseInt(tr.dataset.id, 10)).filter(n => !isNaN(n));
                            fetch('order_statuses_ajax.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                                body: 'action=reorder&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content')) +
                                      '&ids=' + encodeURIComponent(JSON.stringify(ids))
                            })
                            .then(parseJsonResponse)
                            .then(resp => {
                                if (resp.success) {
                                    showSuccess('Orden guardado');
                                    if (typeof reloadDashboardConfig === 'function') {
                                        reloadDashboardConfig();
                                    }
                                    // Mostrar resumen del orden aplicado
                                    try {
                                        const applied = Array.isArray(resp.applied_order) ? resp.applied_order : [];
                                        if (applied.length > 0) {
                                            const box = document.getElementById('order-summary');
                                            if (box) {
                                                const chips = applied.map(r => {
                                                    const name = String(r.name||'').trim();
                                                    const pos = parseInt(r.sort_order||0,10);
                                                    return '<span class="badge bg-light text-dark me-2 mb-2">'+pos+'. '+name+'</span>';
                                                }).join('');
                                                box.innerHTML = '<div class="small text-muted mb-1">Orden aplicado:</div><div>'+chips+'</div>';
                                            }
                                        }
                                    } catch(_){}
                                    loadOrderStatuses();
                                    updateOrderSummary();
                                } else {
                                    showError(resp.message || 'Error al guardar el orden');
                                }
                            })
                            .catch(() => showError('Error de red al guardar el orden'));
                        });
                    }
                    const applyBtn = document.getElementById('applyStatusStyle');
                    if (applyBtn) {
                        applyBtn.addEventListener('click', function() {
                            fetch('order_statuses_ajax.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                                body: 'action=normalize_styles&force=1&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
                            })
                            .then(parseJsonResponse)
                            .then(resp => {
                                if (resp.success) {
                                    showSuccess('Emojis y colores aplicados');
                                    loadOrderStatuses();
                                    if (typeof reloadDashboardConfig === 'function') {
                                        reloadDashboardConfig();
                                    }
                                } else {
                                    showError(resp.message || 'No se pudo aplicar');
                                }
                            })
                            .catch(() => showError('Error al aplicar emojis/colores'));
                        });
                    }
                    if (typeof reloadDashboardConfig === 'function') {
                        reloadDashboardConfig();
                    }
                    updateOrderSummary();
                }
            } else {
                showError('Error al cargar los estados: ' + data.message);
            }
        })
        .catch(error => {
            showError('Error de conexión al cargar los estados');
        });
    }
    function updateOrderSummary(){
        const box = document.getElementById('order-summary');
        if (!box) return;
        fetch('order_statuses_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: 'action=get_template'
        })
        .then(parseJsonResponse)
        .then(resp => {
            if (!resp.success) return;
            const tpl = Array.isArray(resp.template) ? resp.template : [];
            if (tpl.length === 0) {
                box.innerHTML = '<div class="small text-muted">Aún no hay orden guardado.</div>';
                return;
            }
            const chips = tpl.map(r => {
                const name = String(r.name || '').trim();
                const color = String(r.color || '#6c757d').trim();
                return '<span class="badge rounded-pill me-2 mb-2" style="background-color:'+color+';color:#fff">'+name+'</span>';
            }).join('');
            box.innerHTML = '<div class="small text-muted mb-1">Orden guardado:</div><div>'+chips+'</div>';
        })
        .catch(()=>{});
    }
    
    // UI de configuración de tarjetas del dashboard
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('order-statuses-content');
        if (!container) return;
        // Agregar sección de configuración debajo
        const section = document.createElement('div');
        section.className = 'mt-4';
        section.innerHTML = `
            <div class="card border-0 shadow-sm" style="border-radius: 1rem;">
                <div class="card-header bg-white border-bottom-0 pt-3 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                    <h6 class="mb-0 text-dark"><i class="fas fa-layer-group me-2"></i>Tarjetas en Gestión de Órdenes</h6>
                    <span class="badge bg-info text-dark rounded-pill">Solo dashboard</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Estados a mostrar (arrastrar para ordenar)</label>
                            <ul id="cardsList" class="list-group"></ul>
                            <div class="mt-2 small text-muted">Arrastra el ícono para ordenar. Marca/desmarca para mostrar en el dashboard. Este orden solo afecta las tarjetas del dashboard. Máximo 3 tarjetas visibles.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Excluir del “Total Órdenes”</label>
                            <div id="excludedList" class="d-flex flex-wrap gap-2"></div>
                            <div class="mt-2 small text-muted">Estos estados no se suman al total.</div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button id="saveDashboardCfg" class="btn btn-primary rounded-pill px-3"><i class="fas fa-save me-1"></i>Guardar configuración</button>
                    </div>
                </div>
            </div>
        `;
        container.parentElement.appendChild(section);
        
        const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        
        function renderLists(statuses, cfg) {
            const list = document.getElementById('cardsList');
            const excludedBox = document.getElementById('excludedList');
            list.innerHTML = '';
            excludedBox.innerHTML = '';
            const selected = Array.isArray(cfg.cards) ? cfg.cards : [];
            const excluded = Array.isArray(cfg.excluded) ? cfg.excluded : ['delivered','cancelled','devolucion'];
            // Orden: primero seleccionados según su orden, luego el resto
            const bySlug = Object.fromEntries(statuses.map(s => [String(s.slug), s]));
            const orderedSlugs = [...selected, ...statuses.map(s => s.slug).filter(sl => !selected.includes(sl))];
            orderedSlugs.forEach(slug => {
                const s = bySlug[slug];
                if (!s) return;
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex align-items-center justify-content-between';
                li.setAttribute('draggable', 'true');
                li.innerHTML = `
                    <div class="d-flex align-items-center">
                        <span class="drag-handle me-2" style="cursor: grab;"><i class="fas fa-grip-lines"></i></span>
                        <input type="checkbox" class="form-check-input me-2" ${selected.includes(slug) ? 'checked' : ''} data-slug="${slug}">
                        <span class="badge rounded-pill me-2" style="background-color:${s.color};color:#fff">${s.name}</span>
                        <small class="text-muted">${slug}</small>
                    </div>
                `;
                list.appendChild(li);
            });
            statuses.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `btn btn-sm ${excluded.includes(s.slug) ? 'btn-danger' : 'btn-outline-danger'} rounded-pill`;
                btn.textContent = s.name;
                btn.dataset.slug = s.slug;
                btn.addEventListener('click', function() {
                    const idx = excluded.indexOf(s.slug);
                    if (idx >= 0) excluded.splice(idx, 1);
                    else excluded.push(s.slug);
                    btn.className = `btn btn-sm ${excluded.includes(s.slug) ? 'btn-danger' : 'btn-outline-danger'} rounded-pill`;
                });
                excludedBox.appendChild(btn);
            });
            // Reordenar por arrastre (solo iniciando desde el ícono de agarre)
            let dragging = null;
            let dragFromHandle = false;
            list.addEventListener('mousedown', function(e) {
                dragFromHandle = !!e.target.closest('.drag-handle');
            });
            list.addEventListener('dragstart', function(e) {
                const li = e.target.closest('li');
                if (!li) return;
                if (!dragFromHandle) {
                    e.preventDefault();
                    return;
                }
                dragging = li;
                li.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });
            list.addEventListener('dragover', function(e) {
                e.preventDefault();
                const li = e.target.closest('li');
                if (!li || li === dragging) return;
                const rect = li.getBoundingClientRect();
                const after = (e.clientY - rect.top) > rect.height / 2;
                list.insertBefore(dragging, after ? li.nextSibling : li);
            });
            list.addEventListener('drop', function(e) {
                e.preventDefault();
                if (dragging) dragging.classList.remove('opacity-50');
                dragging = null;
                dragFromHandle = false;
            });
            list.addEventListener('dragend', function() {
                if (dragging) dragging.classList.remove('opacity-50');
                dragging = null;
                dragFromHandle = false;
            });
            // Guardar
            document.getElementById('saveDashboardCfg').onclick = function() {
                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const items = Array.from(list.querySelectorAll('li'));
                const orderedSelected = items
                    .map(li => ({ slug: li.querySelector('input[type=checkbox]').dataset.slug, checked: li.querySelector('input[type=checkbox]').checked }))
                    .filter(x => x.checked)
                    .map(x => x.slug);
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    body: 'action=save_dashboard_config&csrf_token=' + encodeURIComponent(csrf) +
                          '&cards=' + encodeURIComponent(JSON.stringify(orderedSelected)) +
                          '&excluded=' + encodeURIComponent(JSON.stringify(excluded))
                })
                .then(parseJsonResponse)
                .then(resp => {
                    if (resp.success) showSuccess('Configuración guardada');
                    else showError(resp.message || 'Error al guardar');
                })
                .catch(() => showError('Error de red al guardar'));
            };
        }
        
        // Función para recargar configuración del dashboard
        window.reloadDashboardConfig = function() {
            Promise.all([
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    body: 'action=get_all&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
                }).then(parseJsonResponse),
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    body: 'action=get_dashboard_config&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
                }).then(parseJsonResponse)
            ]).then(([statesResp, cfgResp]) => {
                if (statesResp.success) {
                    const statuses = statesResp.data.filter(s => s.is_active == 1);
                    renderLists(statuses, cfgResp.success ? { cards: cfgResp.cards, excluded: cfgResp.excluded } : { cards: [], excluded: [] });
                }
            }).catch(() => {});
        };
        // Cargar inicialmente
        reloadDashboardConfig();
    });
    
    // Funciones auxiliares para estados
    function updateStatusPreview(mode) {
        const nameInput = document.getElementById(mode === 'create' ? 'name' : 'edit_name');
        const emojiInput = document.getElementById(mode === 'create' ? 'emoji' : 'edit_emoji');
        const colorInput = document.getElementById(mode === 'create' ? 'color' : 'edit_color');
        
        const previewBadge = document.getElementById(mode + '_preview_badge');
        const previewName = document.getElementById(mode + '_preview_name');
        const previewIcon = document.getElementById(mode + '_preview_icon');
        
        if (previewBadge && previewName && previewIcon) {
            previewName.textContent = nameInput.value || 'Nombre del Estado';
            // Si el input tiene clase fa-, usarla como icono, sino asumir emoji texto (o mejorar lógica)
            // Aquí asumimos que el usuario ingresa una clase de FontAwesome (ej: fas fa-check)
            // Si ingresa emoji directo, esto podría no verse bien si esperamos clase.
            // Ajuste: si empieza con 'fa', es clase. Si no, es texto/emoji.
            const val = emojiInput.value || '';
            if(val.includes('fa-')) {
                previewIcon.className = val + ' me-1';
                previewIcon.textContent = '';
            } else {
                previewIcon.className = 'me-1';
                previewIcon.textContent = val;
            }
            previewBadge.style.backgroundColor = colorInput.value;
        }
    }
    
    function setQuickColor(mode, color) {
        const colorInput = document.getElementById(mode === 'create' ? 'color' : 'edit_color');
        if (colorInput) {
            colorInput.value = color;
            updateStatusPreview(mode);
        }
    }

    // Función para abrir modal de crear estado
    function openCreateStatusModal() {
        // Limpiar formulario
        document.getElementById('create-status-form').reset();
        // Inicializar vista previa
        updateStatusPreview('create');
        // Abrir modal
        new bootstrap.Modal(document.getElementById('createStatusModal')).show();
    }
    
    // Función para editar estado
    function editOrderStatus(el, id) {
        // Buscar el estado en los datos cargados
        fetch('order_statuses_ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: 'action=get_all&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
        })
        .then(parseJsonResponse)
        .then(data => {
            if (data.success) {
                const status = data.data.find(s => s.id == id);
                if (status) {
                    document.getElementById('edit_id').value = status.id;
                    const nameBySlug = {
                        pending: 'Pendiente',
                        received: 'Recibido',
                        diagnosing: 'Diagnosticando',
                        esperando_aprobacion: 'Esperando Aprobación',
                        waiting_parts: 'Esperando Repuestos',
                        repairing: 'Reparando',
                        testing: 'Pruebas',
                        completed: 'Completado',
                        delivered: 'Entregado',
                        cancelled: 'Cancelado',
                        devolucion: 'Devolución',
                        cancelado: 'Cancelado',
                        entregado: 'Entregado'
                    };
                    const emojiBySlug = {
                        pending: '\u23F3',
                        received: '\uD83D\uDCE6',
                        diagnosing: '\uD83D\uDD0D',
                        esperando_aprobacion: '\u270D\uFE0F',
                        waiting_parts: '\u23F8\uFE0F',
                        repairing: '\uD83D\uDD27',
                        testing: '\uD83E\uDDEA',
                        completed: '\u2705',
                        delivered: '\uD83D\uDE9A',
                        cancelled: '\u274C',
                        devolucion: '\u21A9\uFE0F',
                        cancelado: '\u274C',
                        entregado: '\uD83D\uDE9A'
                    };
                    const defaultsDesc = {
                        pending: 'Orden creada y pendiente de revisión',
                        received: 'Orden recibida en el taller',
                        diagnosing: 'Equipo en diagnóstico técnico',
                        esperando_aprobacion: 'Orden esperando aprobación del cliente',
                        waiting_parts: 'Orden en espera de repuestos',
                        repairing: 'Equipo en reparación',
                        testing: 'Equipo en pruebas de funcionamiento',
                        completed: 'Trabajo completado, listo para entrega',
                        delivered: 'Orden entregada al cliente',
                        cancelled: 'Orden cancelada',
                        devolucion: 'Orden devuelta por el cliente'
                    };
                    const slug = String(status.slug || '').trim();
                    const rawName = String(status.name || '').trim();
                    const rawEmoji = String(status.emoji || '').trim();
                    const rawDesc = String(status.description || '').trim();
                    const btnData = (el && el.dataset) ? el.dataset : {};
                    const btnSlug = String(btnData.slug || '').trim();
                    const btnName = String(btnData.name || '').trim();
                    const btnEmoji = String(btnData.emoji || '').trim();
                    const btnDesc = String(btnData.description || '').trim();
                    const decodedName = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawName) : rawName);
                    const decodedEmoji = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawEmoji) : rawEmoji);
                    const decodedDesc = (window.decodeHtmlEntities ? window.decodeHtmlEntities(rawDesc) : rawDesc);
                    const nameInvalid = (decodedName === '' || /^\?+$/.test(decodedName));
                    const emojiInvalid = (decodedEmoji === '' || /^\?+$/.test(decodedEmoji));
                    const descInvalid = (decodedDesc === '' || /\?{2,}/.test(decodedDesc));
                    const finalSlug = slug || btnSlug;
                    const finalNameSource = nameInvalid ? (btnName || '') : decodedName;
                    document.getElementById('edit_name').value = (finalNameSource === '' || /^\?+$/.test(finalNameSource))
                        ? (nameBySlug[finalSlug] || finalNameSource)
                        : finalNameSource;
                    const finalEmojiSource = emojiInvalid ? (btnEmoji || '') : decodedEmoji;
                    document.getElementById('edit_emoji').value = (finalEmojiSource === '' || /^\?+$/.test(finalEmojiSource))
                        ? (emojiBySlug[finalSlug] || '')
                        : (window.ensureEmojiPresentation ? window.ensureEmojiPresentation(finalEmojiSource) : finalEmojiSource);
                    document.getElementById('edit_color').value = status.color;
                    const finalDescSource = descInvalid ? (btnDesc || '') : decodedDesc;
                    document.getElementById('edit_description').value = (finalDescSource === '' || /\?{2,}/.test(finalDescSource))
                        ? (defaultsDesc[finalSlug] || finalDescSource)
                        : finalDescSource;
                    document.getElementById('edit_is_default').checked = status.is_default == 1;
                    document.getElementById('edit_is_active').checked = status.is_active == 1;
                    updateStatusPreview('edit');
                    new bootstrap.Modal(document.getElementById('editStatusModal')).show();
                } else {
                        showError('Error: Estado no encontrado');
                }
            } else {
                showError('Error al cargar datos: ' + (data.message || 'Desconocido'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Error de conexión al cargar el estado');
        });
    }
    
    // Función para eliminar estado
    function deleteOrderStatus(id, name) {
        showConfirm(`¿Estás seguro de que deseas eliminar el estado "${name}"?`, function() {
            fetch('order_statuses_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))
            })
            .then(async (response) => {
                const ct = response.headers.get('content-type') || '';
                const text = await response.text();
                if (!ct.includes('application/json')) {
                    throw new Error('Respuesta no JSON: ' + text.slice(0, 200));
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('JSON inválido: ' + text.slice(0, 200));
                }
                return data;
            })
            .then(data => {
                if (data.success) {
                    loadOrderStatuses(); // Recargar la lista
                    showSuccess('Estado eliminado exitosamente');
                } else {
                    showError('Error al eliminar el estado: ' + data.message);
                }
            })
            .catch(error => {
                showError('Error de conexión al eliminar el estado');
            });
        });
    }
    
    // Cargar estadísticas al cargar la página
    document.addEventListener('DOMContentLoaded', function() {
        // Cargar estados de órdenes cuando se active la pestaña
        const tabEl = document.getElementById('order-statuses-tab');
        if (tabEl) {
            tabEl.addEventListener('click', function() {
                loadOrderStatuses();
            });
        }
        
        // Event listener para formulario de crear estado (evitar doble binding)
        const createForm = document.getElementById('create-status-form');
        if (createForm && !window.__orderStatusCreateBound) {
            window.__orderStatusCreateBound = true;
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'create');
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                // Evitar doble envío
                const submitBtn = this.querySelector('[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('createStatusModal')).hide();
                        loadOrderStatuses();
                        showSuccess('Estado creado correctamente');
                    } else {
                        showError('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error al crear el estado');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                });
            });
        }

        // Event listener para formulario de editar estado
        const editForm = document.getElementById('edit-status-form');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'update');
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                
                fetch('order_statuses_ajax.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('editStatusModal')).hide();
                        loadOrderStatuses();
                        showSuccess('Estado actualizado correctamente');
                    } else {
                        showError('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Error al actualizar el estado');
                });
            });
        }
    });
</script>
