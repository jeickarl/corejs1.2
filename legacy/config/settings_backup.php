<style>
/* Diseño Responsivo para Tabla de Respaldos */
@media (max-width: 767.98px) {
    #backupsTable thead { display: none; }
    #backupsTable, #backupsTable tbody, #backupsTable tr, #backupsTable td { display: block; width: 100%; }
    #backupsTable tr { margin-bottom: 1rem; background-color: #fff; border: 1px solid rgba(0,0,0,0.1); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); position: relative; }
    #backupsTable td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 0.75rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.05); text-align: right; word-break: break-all; }
    #backupsTable td::before { content: attr(data-label); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; width: 35%; flex-shrink: 0; margin-right: 1rem; text-align: left; word-break: normal; }
    #backupsTable td:last-child { border-bottom: none; background-color: #f8f9fa; font-weight: bold; border-radius: 0 0 0.75rem 0.75rem; justify-content: flex-end; }
}
</style>
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
            <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
                <h5 class="mb-0 text-dark">
                    <i class="fas fa-database me-2"></i>Respaldo y Restauración
                </h5>
                <p class="text-muted small mt-1 mb-0">Gestione copias de seguridad del sistema y datos de clientes.</p>
            </div>
            <div class="card-body p-4">
                <ul class="nav nav-pills mb-4" id="backup-pills-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pills-system-tab" data-bs-toggle="pill" data-bs-target="#pills-system" type="button" role="tab">
                            <i class="fas fa-server me-2"></i>Sistema
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button" role="tab" onclick="loadBackups()">
                            <i class="fas fa-history me-2"></i>Historial
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-tools-tab" data-bs-toggle="pill" data-bs-target="#pills-tools" type="button" role="tab">
                            <i class="fas fa-tools me-2"></i>Herramientas Clientes
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-cloud-tab" data-bs-toggle="pill" data-bs-target="#pills-cloud" type="button" role="tab">
                            <i class="fas fa-cloud me-2"></i>Nube
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="backup-pills-tabContent">
                    <div class="tab-pane fade show active" id="pills-system" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                    <div class="card-body p-4">
                                        <div class="d-flex align-items-center mb-3 justify-content-center">
                                            <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                <i class="fas fa-download text-primary fa-lg no-theme"></i>
                                            </div>
                                            <h6 class="mb-0 text-dark fw-bold">Generar Copia de Seguridad</h6>
                                        </div>
                                    
                                        <form id="backupForm">
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="fullBackup" name="full_backup" value="1">
                                                    <label class="form-check-label fw-bold" for="fullBackup">Respaldo completo</label>
                                                </div>
                                                <small class="text-muted d-block mt-1">Incluye todas las tablas de la base de datos.</small>
                                                <div id="cloudUploadHint" class="alert alert-info py-1 px-2 mt-2 d-none small">
                                                    <i class="fas fa-cloud-upload-alt me-1"></i>
                                                    Si la <strong>Nube</strong> está activa, se subirá automáticamente.
                                                </div>
                                            </div>

                                            <div id="modulesSection" class="mb-3 p-3 bg-light rounded-3 border-0">
                                                <label class="form-label small text-muted fw-bold mb-2">Módulos específicos</label>
                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modOrders" name="modules[]" value="orders" checked>
                                                            <label class="form-check-label small" for="modOrders">Órdenes</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modCash" name="modules[]" value="cash">
                                                            <label class="form-check-label small" for="modCash">Caja</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modSales" name="modules[]" value="sales">
                                                            <label class="form-check-label small" for="modSales">Ventas</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modInventory" name="modules[]" value="inventory">
                                                            <label class="form-check-label small" for="modInventory">Inventario</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modSuppliers" name="modules[]" value="suppliers">
                                                            <label class="form-check-label small" for="modSuppliers">Proveedores</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="modClients" name="modules[]" value="clients" checked>
                                                            <label class="form-check-label small" for="modClients">Clientes</label>
                                                        </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="modConfig" name="modules[]" value="config" checked>
                                                        <label class="form-check-label small" for="modConfig">Configuración</label>
                                                    </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="fileName" class="form-label small text-muted">Nombre de archivo (opcional)</label>
                                                <input type="text" class="form-control form-control-sm" id="fileName" name="file_name" placeholder="ej. respaldo_tienda" style="border-radius: 0.5rem;">
                                            </div>

                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary rounded-pill shadow-sm">
                                                    <i class="fas fa-save me-2"></i>Generar respaldo
                                                </button>
                                            </div>
                                        </form>

                                        <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                                            const cloudForm = document.getElementById('cloudConfigForm');
                                            const cloudEnabled = document.getElementById('cloudEnabled');
                                            const cloudSettings = document.getElementById('cloudSettings');
                                            const btnAuth = document.getElementById('btnGoogleDriveAuth');
                                            const btnVerify = document.getElementById('btnVerifyCode');
                                            const authCodeSection = document.getElementById('authCodeSection');
                                            const statusAlert = document.getElementById('connectionStatus');
                                            const btnSaveCloud = document.createElement('button');
                                            btnSaveCloud.type = 'button';
                                            btnSaveCloud.className = 'btn btn-success mt-3';
                                            btnSaveCloud.id = 'btnSaveCloudSettings';
                                            btnSaveCloud.innerHTML = '<i class="fas fa-save me-2"></i>Guardar configuración';
                                            cloudSettings.appendChild(btnSaveCloud);

                                            const btnManualBat = document.createElement('button');
                                            btnManualBat.type = 'button';
                                            btnManualBat.className = 'btn btn-outline-warning mt-3 ms-2';
                                            btnManualBat.id = 'btnManualBat';
                                            btnManualBat.innerHTML = '<i class="fas fa-file-code me-2"></i>Descargar .bat Manual';
                                            btnManualBat.title = 'Descargar script para instalar la tarea manualmente si falla la automática';
                                            cloudSettings.appendChild(btnManualBat);

                                            const btnTestUpload = document.createElement('button');
                                            btnTestUpload.type = 'button';
                                            btnTestUpload.className = 'btn btn-outline-success mt-2';
                                            btnTestUpload.id = 'btnTestUpload';
                                            btnTestUpload.innerHTML = '<i class="fas fa-cloud-upload-alt me-2"></i>Probar subida';
                                            cloudSettings.appendChild(btnTestUpload);

                                            // Cargar estado inicial
                                            loadCloudStatus();

                                            function downloadBase64File(base64, filename, mime) {
                                                try {
                                                    const binary = atob(base64 || '');
                                                    const bytes = new Uint8Array(binary.length);
                                                    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                                                    const blob = new Blob([bytes], { type: mime || 'application/octet-stream' });
                                                    const url = URL.createObjectURL(blob);
                                                    const a = document.createElement('a');
                                                    a.href = url;
                                                    a.download = filename || 'install_task.bat';
                                                    document.body.appendChild(a);
                                                    a.click();
                                                    a.remove();
                                                    URL.revokeObjectURL(url);
                                                } catch (e) {
                                                    showStatus('No se pudo preparar la descarga del script.', 'danger');
                                                }
                                            }

                                            // Toggle Switch
                                            cloudEnabled.addEventListener('change', function() {
                                                const isEnabled = this.checked;
                                                updateUIState(isEnabled);
                                                
                                                // Guardar preferencia
                                                $.post('../backup/ajax/cloud_config.php', {
                                                    action: 'toggle_cloud',
                                                    enabled: isEnabled,
                                                    csrf_token: csrfToken
                                                });
                                            });

                                            // Botón Autenticación (Conectar/Desconectar)
                                            btnAuth.addEventListener('click', function() {
                                                const isConnected = btnAuth.classList.contains('btn-outline-danger');
                                                
                                                if (isConnected) {
                                                    // Lógica de Desconexión
                                            showConfirm('¿Seguro que deseas desconectar la cuenta de Google Drive? Se detendrán los respaldos automáticos en la nube.', function() {
                                                btnAuth.disabled = true;
                                                btnAuth.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Desconectando...';
                                                
                                                $.post('../backup/ajax/cloud_config.php', {
                                                    action: 'disconnect_cloud',
                                                    csrf_token: csrfToken
                                                }, function(resp) {
                                                    btnAuth.disabled = false;
                                                    if (resp.success) {
                                                        showStatus(resp.message, 'success');
                                                        loadCloudStatus();
                                                    } else {
                                                        showStatus(resp.message || 'Error al desconectar', 'danger');
                                                        loadCloudStatus();
                                                    }
                                                }, 'json').fail(function() {
                                                    btnAuth.disabled = false;
                                                    showStatus('Error de conexión', 'danger');
                                                    loadCloudStatus();
                                                });
                                            });
                                                    
                                                } else {
                                                    // Lógica de Conexión
                                                    const clientId = document.getElementById('gdriveClientId').value.trim();
                                                    const clientSecret = document.getElementById('gdriveClientSecret').value.trim();
    
                                                    if (!clientId || !clientSecret) {
                                                        showStatus('Ingrese Client ID y Client Secret', 'danger');
                                                        return;
                                                    }
    
                                                    btnAuth.disabled = true;
                                                    btnAuth.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Obteniendo URL...';
    
                                                    $.post('../backup/ajax/cloud_config.php', {
                                                        action: 'get_auth_url',
                                                        client_id: clientId,
                                                        client_secret: clientSecret,
                                                        csrf_token: csrfToken
                                                    }, function(resp) {
                                                        btnAuth.disabled = false;
                                                        btnAuth.innerHTML = '<i class="fab fa-google me-2"></i>Conectar Cuenta Google';
    
                                                        if (resp.success) {
                                                            const width = 600;
                                                            const height = 600;
                                                            const left = (screen.width / 2) - (width / 2);
                                                            const top = (screen.height / 2) - (height / 2);
                                                            window.open(resp.url, 'google_auth', `width=${width},height=${height},top=${top},left=${left}`);
                                                            showStatus('Se abrió una ventana para autorizar. Esperando confirmación automática... <br><small><a href="#" onclick="document.getElementById(\'authCodeSection\').classList.remove(\'d-none\');return false;">¿Problemas? Ingresar código manualmente</a></small>', 'info');
                                                        } else {
                                                            showStatus(resp.message || 'Error al obtener URL', 'danger');
                                                        }
                                                    }, 'json').fail(function() {
                                                        btnAuth.disabled = false;
                                                        btnAuth.innerHTML = '<i class="fab fa-google me-2"></i>Conectar Cuenta Google';
                                                        showStatus('Error de conexión', 'danger');
                                                    });
                                                }
                                            });

                                            // Botón Verificar Código Manual
                                            btnVerify.addEventListener('click', function() {
                                                const code = document.getElementById('authCodeInput').value.trim();
                                                if (!code) return;

                                                verifyAuthCode(code);
                                            });
                                            btnSaveCloud.addEventListener('click', function(){
                                                const fd = new FormData();
                                                fd.append('action','save_provider_settings');
                                                if (csrfToken) fd.append('csrf_token', csrfToken);
                                                fd.append('provider', document.getElementById('cloudProvider').value);
                                                fd.append('gdrive_folder_id', document.getElementById('gdriveFolderId').value.trim());
                                                
                                                // Enviar credenciales también
                                                fd.append('client_id', document.getElementById('gdriveClientId').value.trim());
                                                fd.append('client_secret', document.getElementById('gdriveClientSecret').value.trim());
                                                
                                                fd.append('cloud_encrypt_enabled', document.getElementById('cloudEncryptEnabled').checked ? '1' : '0');
                                                const k = document.getElementById('cloudEncryptKey').value.trim();
                                                if (k) fd.append('cloud_encrypt_key', k);
                                                
                                                const mode = document.getElementById('cloudScheduleMode').value;
                                                fd.append('cloud_schedule_mode', mode);
                                                fd.append('cloud_schedule_time', document.getElementById('cloudScheduleTime').value);
                                                fd.append('cloud_schedule_weekday', document.getElementById('cloudScheduleWeekday').value);
                                                fd.append('cloud_verify_ssl', document.getElementById('cloudVerifySSL').checked ? '1' : '0');
                                                const t = document.getElementById('backupApiToken').value.trim();
                                                if (t) fd.append('backup_api_token', t);
                                                
                                                showStatus('Guardando configuración...', 'info');

                                                fetch('../backup/ajax/cloud_config.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                                                    .then(window.parseJsonResponse)
                                                    .then(resp => {
                                                        if (resp && resp.success) {
                                                            // Si no es manual, crear/actualizar tarea automáticamente
                                                            if (mode !== 'manual') {
                                                                showStatus('Configuración guardada. Programando tarea en Windows...', 'info');
                                                                const fdTask = new FormData();
                                                                fdTask.append('action', 'create_schedule_task');
                                                                if (csrfToken) fdTask.append('csrf_token', csrfToken);
                                                                fdTask.append('cloud_schedule_mode', mode);
                                                                fdTask.append('cloud_schedule_time', document.getElementById('cloudScheduleTime').value);
                                                                
                                                                return fetch('../backup/ajax/cloud_config.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fdTask })
                                                                    .then(window.parseJsonResponse)
                                                                    .then(resp2 => {
                                                                        if (resp2 && resp2.success) {
                                                                            showStatus('¡Guardado y tarea programada correctamente!', 'success');
                                                                        } else {
                                                                            if (resp2.manual_script_base64) {
                                                                                downloadBase64File(resp2.manual_script_base64, resp2.manual_script_filename, resp2.manual_script_mime);
                                                                                showStatus(`
                                                                                    <strong>Atención:</strong> ${resp2.message}<br>
                                                                                    <small class="text-muted">Se descargó el script. Ejecútalo como Administrador (clic derecho → "Ejecutar como administrador").</small>
                                                                                `, 'warning');
                                                                            } else {
                                                                                showStatus('Guardado, pero error al programar tarea: ' + (resp2.message || 'Desconocido'), 'warning');
                                                                            }
                                                                        }
                                                                        loadCloudStatus();
                                                                    });
                                                            } else {
                                                                showStatus(resp.message || 'Guardado', 'success');
                                                                loadCloudStatus();
                                                            }
                                                        } else {
                                                            showStatus(resp.message || 'No se pudo guardar', 'danger');
                                                        }
                                                    })
                                                    .catch(() => showStatus('Error de conexión', 'danger'));
                                            });
                                            btnTestUpload.addEventListener('click', function(){
                                                const fd = new FormData();
                                                fd.append('action','test_upload');
                                                if (csrfToken) fd.append('csrf_token', csrfToken);
                                                showStatus('Ejecutando prueba de subida…', 'info');
                                                fetch('../backup/ajax/cloud_config.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                                                    .then(window.parseJsonResponse)
                                                    .then(resp => {
                                                        if (resp && resp.success) {
                                                            showStatus(resp.message || 'Prueba exitosa', 'success');
                                                        } else {
                                                            showStatus(resp.message || 'Prueba fallida', 'danger');
                                                        }
                                                    })
                                                    .catch(() => showStatus('Error de conexión', 'danger'));
                                            });

                                            btnManualBat.addEventListener('click', function() {
                                                const mode = document.getElementById('cloudScheduleMode').value;
                                                if (mode === 'manual') {
                                                    showStatus('Selecciona una frecuencia (Diaria o Semanal) antes de generar el script.', 'warning');
                                                    return;
                                                }
                                                
                                                showStatus('Generando script manual...', 'info');
                                                const fd = new FormData();
                                                fd.append('action', 'generate_manual_script');
                                                if (csrfToken) fd.append('csrf_token', csrfToken);
                                                fd.append('cloud_schedule_mode', mode);
                                                fd.append('cloud_schedule_time', document.getElementById('cloudScheduleTime').value);
                                                fd.append('cloud_schedule_weekday', document.getElementById('cloudScheduleWeekday').value);

                                                fetch('../backup/ajax/cloud_config.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                                                    .then(window.parseJsonResponse)
                                                    .then(resp => {
                                                        if (resp && resp.success && resp.manual_script_base64) {
                                                            downloadBase64File(resp.manual_script_base64, resp.manual_script_filename, resp.manual_script_mime);
                                                            showStatus(`
                                                                <strong>Script listo.</strong><br>
                                                                <small class="text-muted">Se descargó el archivo. Ejecútalo como Administrador.</small>
                                                            `, 'success');
                                                        } else {
                                                            showStatus(resp.message || 'Error al generar script', 'danger');
                                                        }
                                                    })
                                                    .catch(() => showStatus('Error de conexión', 'danger'));
                                            });

                                            function verifyAuthCode(code) {
                                                btnVerify.disabled = true;
                                                btnVerify.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                                                // URL de redirección debe coincidir con la generada
                                                const protocol = window.location.protocol;
                                                const host = window.location.host;
                                                const redirectUri = `${protocol}//${host}/core/backup/cloud/callback.php`;

                                                $.post('../backup/ajax/cloud_config.php', {
                                                    action: 'save_token',
                                                    code: code,
                                                    redirect_uri: redirectUri,
                                                    csrf_token: csrfToken
                                                }, function(resp) {
                                                    btnVerify.disabled = false;
                                                    btnVerify.innerText = 'Verificar';

                                                    if (resp.success) {
                                                        showStatus(resp.message, 'success');
                                                        authCodeSection.classList.add('d-none');
                                                        loadCloudStatus(); // Recargar para ver estado conectado
                                                    } else {
                                                        showStatus(resp.message || 'Error al verificar código', 'danger');
                                                    }
                                                }, 'json').fail(function() {
                                                    btnVerify.disabled = false;
                                                    btnVerify.innerText = 'Verificar';
                                                    showStatus('Error de conexión', 'danger');
                                                });
                                            }

                                            function updateUIState(enabled) { }

                                            function showStatus(msg, type) {
                                                statusAlert.className = `alert alert-${type} mt-3`;
                                                statusAlert.innerHTML = msg;
                                                statusAlert.classList.remove('d-none');
                                            }

                                            function loadCloudStatus() {
                                                $.post('../backup/ajax/cloud_config.php', { action: 'get_status', csrf_token: csrfToken }, function(data) {
                                                    if (data) {
                                                        cloudEnabled.checked = data.enabled;
                                                        updateUIState(data.enabled);
                                                        
                                                        // Mostrar hint de subida en la pestaña Sistema
                                                        const hint = document.getElementById('cloudUploadHint');
                                                        if (hint) {
                                                            if (data.enabled) hint.classList.remove('d-none');
                                                            else hint.classList.add('d-none');
                                                        }

                                                        if (data.provider) {
                                                            document.getElementById('cloudProvider').value = data.provider;
                                                        }
                                                        if (data.client_id) {
                                                            document.getElementById('gdriveClientId').value = data.client_id;
                                                        }
                                                        if (data.folder_id) {
                                                            document.getElementById('gdriveFolderId').value = data.folder_id;
                                                        }
                                                        if (data.encrypt_enabled !== undefined) {
                                                            document.getElementById('cloudEncryptEnabled').checked = !!data.encrypt_enabled;
                                                        }
                                                        if (data.schedule_weekday) {
                                                            document.getElementById('cloudScheduleWeekday').value = data.schedule_weekday;
                                                        }
                                                        if (data.verify_ssl !== undefined) {
                                                            document.getElementById('cloudVerifySSL').checked = !!data.verify_ssl;
                                                        }
                                                        if (data.schedule_mode) {
                                                            document.getElementById('cloudScheduleMode').value = data.schedule_mode;
                                                        }
                                                        if (data.schedule_time) {
                                                            document.getElementById('cloudScheduleTime').value = data.schedule_time;
                                                        }
                                                        if (data.backup_api_token) {
                                                            document.getElementById('backupApiToken').value = data.backup_api_token;
                                                        }
                                                        if (data.client_secret) {
                                                            document.getElementById('gdriveClientSecret').value = data.client_secret;
                                                        }

                                                        if (data.has_token) {
                                                            btnAuth.className = 'btn btn-outline-danger';
                                                            btnAuth.innerHTML = '<i class="fas fa-unlink me-2"></i>Cerrar Sesión / Desconectar';
                                                            
                                                            // Bloquear campos sensibles
                                                            document.getElementById('gdriveClientId').readOnly = true;
                                                            document.getElementById('gdriveClientSecret').readOnly = true;
                                                        } else {
                                                            btnAuth.className = 'btn btn-outline-primary';
                                                            btnAuth.innerHTML = '<i class="fab fa-google me-2"></i>Conectar Cuenta Google';
                                                            
                                                            // Desbloquear campos
                                                            document.getElementById('gdriveClientId').readOnly = false;
                                                            document.getElementById('gdriveClientSecret').readOnly = false;
                                                        }
                                                    }
                                                }, 'json');
                                            }

                                            // Exponer función global para que el popup pueda llamarla
                                             window.handleGoogleCallback = function(code) {
                                                 document.getElementById('authCodeInput').value = code;
                                                 verifyAuthCode(code);
                                             };

                                             // Escuchar mensaje desde el popup (callback.php)
                                             window.addEventListener('message', function(event) {
                                                 if (event.data && event.data.type === 'GOOGLE_AUTH_CODE') {
                                                     window.handleGoogleCallback(event.data.code);
                                                 }
                                             });
                                         });
                                         </script>
                                        <div id="backupProgress" class="d-none mt-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small id="backupStage" class="text-muted">Preparando…</small>
                                                <small id="backupPercent" class="text-muted">0%</small>
                                            </div>
                                            <div class="progress" style="height:6px;">
                                                <div id="backupProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                                            </div>
                                        </div>
                                        <div id="backupResult" class="alert mt-3 d-none small" style="border-radius: 0.5rem;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm" style="border-radius: 1rem;">
                                    <div class="card-body p-4">
                                        <div class="d-flex align-items-center mb-3 justify-content-center">
                                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                <i class="fas fa-upload text-success fa-lg"></i>
                                            </div>
                                            <h6 class="mb-0 text-dark fw-bold">Restaurar Copia de Seguridad</h6>
                                        </div>
                                    
                                        <form id="restoreForm" enctype="multipart/form-data">
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">Modo de restauración</label>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="restore_mode" id="restoreOverwrite" value="overwrite" checked>
                                                            <label class="form-check-label small" for="restoreOverwrite">Completo (sobrescribe)</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="restore_mode" id="restoreMerge" value="merge">
                                                            <label class="form-check-label small" for="restoreMerge">Selectivo (fusionar sin borrar)</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="restoreModulesSection" class="mb-3 p-3 bg-light rounded-3 border-0 d-none">
                                                <label class="form-label small text-muted fw-bold mb-2">Módulos a restaurar</label>
                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodOrders" name="restore_modules[]" value="orders">
                                                            <label class="form-check-label small" for="rmodOrders">Órdenes</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodCash" name="restore_modules[]" value="cash">
                                                            <label class="form-check-label small" for="rmodCash">Caja</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodSales" name="restore_modules[]" value="sales">
                                                            <label class="form-check-label small" for="rmodSales">Ventas</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodInventory" name="restore_modules[]" value="inventory">
                                                            <label class="form-check-label small" for="rmodInventory">Inventario</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodSuppliers" name="restore_modules[]" value="suppliers">
                                                            <label class="form-check-label small" for="rmodSuppliers">Proveedores</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodClients" name="restore_modules[]" value="clients">
                                                            <label class="form-check-label small" for="rmodClients">Clientes</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="rmodConfig" name="restore_modules[]" value="config">
                                                            <label class="form-check-label small" for="rmodConfig">Configuración</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row g-2 mt-2">
                                                    <div class="col-12">
                                                        <label class="form-label small text-muted">Conflictos de registros</label>
                                                        <select class="form-select form-select-sm" name="conflict_strategy" id="restoreConflict" style="border-radius:.5rem;">
                                                            <option value="ignore">Ignorar duplicados (no sobrescribe)</option>
                                                            <option value="replace">Reemplazar duplicados (sobrescribe coincidencias)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mb-4 text-center">
                                                <div class="file-upload-wrapper p-4 border border-dashed rounded bg-white" style="border-radius: 1rem !important;">
                                                    <i class="fas fa-file-archive fa-2x text-muted mb-2"></i>
                                                    <p class="small text-muted mb-2">Seleccione archivo .zip (Completo) o .sql (Base de Datos)</p>
                                                    <input type="file" class="form-control form-control-sm" id="backupFile" name="backup_file" accept=".sql,.zip" required style="border-radius: 0.5rem;">
                                                </div>
                                            </div>
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-success rounded-pill shadow-sm">
                                                    <i class="fas fa-database me-2"></i>Restaurar respaldo
                                                </button>
                                            </div>
                                        </form>

                                        <div id="restoreProgress" class="d-none mt-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small id="restoreStage" class="text-muted">Preparando…</small>
                                                <small id="restorePercent" class="text-muted">0%</small>
                                            </div>
                                            <div class="progress" style="height:6px;">
                                                <div id="restoreProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                                            </div>
                                        </div>
                                        <div id="restoreResult" class="alert mt-3 d-none small" style="border-radius: 0.5rem;"></div>
                                        <div class="alert alert-warning mt-3 mb-0 d-flex align-items-center" role="alert" style="border-radius: 0.5rem;">
                                            <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
                                            <div class="small">
                                                <strong>Advertencia:</strong> La restauración sobrescribirá los datos existentes. Asegúrese de tener un respaldo reciente antes de continuar.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            (function(){
                                function toggleModulesSection(){
                                    const full = document.getElementById('fullBackup').checked;
                                    document.querySelectorAll('#modulesSection input[type=checkbox]').forEach(el => el.disabled = full);
                                    document.getElementById('modulesSection').classList.toggle('opacity-50', full);
                                }
                                const fullBackup = document.getElementById('fullBackup');
                                if(fullBackup) {
                                    fullBackup.addEventListener('change', toggleModulesSection);
                                    toggleModulesSection();
                                }

                                const backupForm = document.getElementById('backupForm');
                                if(backupForm) {
                                    $(backupForm).on('submit', function(e){
                                        e.preventDefault();
                                        const $result = $('#backupResult');
                                        $result.removeClass('alert-success alert-danger').addClass('d-none').text('');

                                        const full = document.getElementById('fullBackup') && document.getElementById('fullBackup').checked;
                                        const selected = $(this).find('input[name="modules[]"]:checked').length;
                                        if (!full && selected === 0) {
                                            showError('Selecciona al menos un módulo o activa "Respaldo completo".');
                                            return;
                                        }

                                        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                                        const data = $(this).serialize() + (csrf ? '&csrf_token=' + encodeURIComponent(csrf) : '');
                                        const btn = $(this).find('button[type=submit]');
                                        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Generando respaldo...');

                                        const prog = document.getElementById('backupProgress');
                                        const bar = document.getElementById('backupProgressBar');
                                        const stageEl = document.getElementById('backupStage');
                                        const pctEl = document.getElementById('backupPercent');
                                        
                                        // Detectar si es subida a nube para ajustar etapas
                                        const isCloud = document.getElementById('cloudEnabled') && document.getElementById('cloudEnabled').checked;
                                        let stages = ['Preparando', 'Exportando BD', 'Comprimiendo archivos', 'Finalizando'];
                                        if (isCloud) {
                                            stages = ['Preparando', 'Exportando BD', 'Comprimiendo archivos', 'Subiendo a Nube...', 'Verificando...'];
                                        }

                                        prog.classList.remove('d-none');
                                        // Resetear barra
                                        bar.style.width = '0%';
                                        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
                                        
                                        let p = 0, si = 0;
                                        stageEl.textContent = stages[si];
                                        
                                        const timer = setInterval(function(){
                                            // Si es nube, ir más lento al final
                                            const step = (isCloud && p > 70) ? 1 : 4; 
                                            p = Math.min(p + step, 98); // No llegar a 100 hasta que termine
                                            
                                            bar.style.width = p + '%';
                                            pctEl.textContent = Math.round(p) + '%';
                                            
                                            // Cambiar textos según porcentaje
                                            if (isCloud) {
                                                if (p > 15 && si === 0) { si = 1; stageEl.textContent = stages[si]; } // Exportando
                                                if (p > 40 && si === 1) { si = 2; stageEl.textContent = stages[si]; } // Comprimiendo
                                                if (p > 60 && si === 2) { si = 3; stageEl.textContent = stages[si]; } // Subiendo
                                                if (p > 90 && si === 3) { si = 4; stageEl.textContent = stages[si]; } // Verificando
                                            } else {
                                                if (p > 25 && si === 0) { si = 1; stageEl.textContent = stages[si]; }
                                                if (p > 55 && si === 1) { si = 2; stageEl.textContent = stages[si]; }
                                                if (p > 80 && si === 2) { si = 3; stageEl.textContent = stages[si]; }
                                            }
                                        }, 400);

                                        $.ajax({
                                            url: '../backup/ajax/export.php',
                                            method: 'POST',
                                            data: data,
                                            dataType: 'json'
                                        })
                                        .done(function(resp){
                                            clearInterval(timer);
                                            if(resp && resp.success){
                                                $result.removeClass('d-none').addClass('alert-success');
                                                const link = resp.file_url ? `<a href="${resp.file_url}" target="_blank" class="alert-link">Descargar respaldo</a>` : '';
                                                $result.html(`<i class='fas fa-check-circle me-2'></i>${resp.message || 'Respaldo generado correctamente.'} ${link}`);
                                                bar.style.width = '100%';
                                                pctEl.textContent = '100%';
                                                stageEl.textContent = 'Completado';
                                            } else {
                                                $result.removeClass('d-none').addClass('alert-danger').html(`<i class='fas fa-exclamation-triangle me-2'></i>${resp && resp.message ? resp.message : 'No se pudo generar el respaldo.'}`);
                                                bar.classList.remove('progress-bar-animated');
                                            }
                                        })
                                        .fail(function(xhr){
                                            clearInterval(timer);
                                            let msg = 'Error al generar el respaldo.';
                                            try { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch (e) {}
                                            $result.removeClass('d-none').addClass('alert-danger').html(`<i class='fas fa-exclamation-triangle me-2'></i>${msg}`);
                                            bar.classList.remove('progress-bar-animated');
                                        })
                                        .always(function(){
                                            btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Generar respaldo');
                                        });
                                    });
                                }

                                const restoreForm = document.getElementById('restoreForm');
                                if(restoreForm) {
                                    $(restoreForm).on('submit', function(e){
                                        e.preventDefault();
                                        const $result = $('#restoreResult');
                                        $result.removeClass('alert-success alert-danger').addClass('d-none').text('');

                                        const formData = new FormData(this);
                                        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                                        if (csrf) formData.append('csrf_token', csrf);
                                        const btn = $(this).find('button[type=submit]');
                                        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Restaurando respaldo...');

                                        const prog = document.getElementById('restoreProgress');
                                        const bar = document.getElementById('restoreProgressBar');
                                        const stageEl = document.getElementById('restoreStage');
                                        const pctEl = document.getElementById('restorePercent');
                                        const stages = ['Preparando','Descomprimiendo','Restaurando BD','Copiando archivos','Finalizando'];
                                        prog.classList.remove('d-none');
                                        let p = 0, si = 0;
                                        stageEl.textContent = stages[si];
                                        const timer = setInterval(function(){
                                            p = Math.min(p + 8, 95);
                                            bar.style.width = p + '%';
                                            pctEl.textContent = Math.round(p) + '%';
                                            if (p > 20 && si === 0) { si = 1; stageEl.textContent = stages[si]; }
                                            if (p > 40 && si === 1) { si = 2; stageEl.textContent = stages[si]; }
                                            if (p > 70 && si === 2) { si = 3; stageEl.textContent = stages[si]; }
                                            if (p > 85 && si === 3) { si = 4; stageEl.textContent = stages[si]; }
                                        }, 600);

                                        $.ajax({
                                            url: '../backup/ajax/import.php',
                                            method: 'POST',
                                            data: formData,
                                            processData: false,
                                            contentType: false,
                                            dataType: 'json'
                                        }).done(function(resp){
                                            clearInterval(timer);
                                            if(resp && resp.success){
                                                $result.removeClass('d-none').addClass('alert-success').html(`<i class='fas fa-check-circle me-2'></i>${resp.message || 'Respaldo restaurado correctamente.'}`);
                                                bar.style.width = '100%';
                                                pctEl.textContent = '100%';
                                                stageEl.textContent = 'Completado';
                                            } else {
                                                $result.removeClass('d-none').addClass('alert-danger').html(`<i class='fas fa-exclamation-triangle me-2'></i>${resp && resp.message ? resp.message : 'No se pudo restaurar el respaldo.'}`);
                                                bar.classList.remove('progress-bar-animated');
                                            }
                                        }).fail(function(xhr){
                                            clearInterval(timer);
                                            let msg = 'Error al restaurar el respaldo.';
                                            try {
                                                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                                                else { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; }
                                            } catch (e) {}
                                            $result.removeClass('d-none').addClass('alert-danger').html(`<i class='fas fa-exclamation-triangle me-2'></i>${msg}`);
                                            bar.classList.remove('progress-bar-animated');
                                        }).always(function(){
                                            btn.prop('disabled', false).html('<i class="fas fa-database me-2"></i>Restaurar respaldo');
                                        });
                                    });
                                    const rModeOverwrite = document.getElementById('restoreOverwrite');
                                    const rModeMerge = document.getElementById('restoreMerge');
                                    const rSection = document.getElementById('restoreModulesSection');
                                    function toggleRestoreSection() {
                                        const merge = rModeMerge.checked;
                                        rSection.classList.toggle('d-none', !merge);
                                    }
                                    rModeOverwrite.addEventListener('change', toggleRestoreSection);
                                    rModeMerge.addEventListener('change', toggleRestoreSection);
                                    toggleRestoreSection();
                                }
                            })();
                        });
                        </script>
                    </div>

                    <div class="tab-pane fade" id="pills-history" role="tabpanel">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0 fw-bold">Copias de Seguridad Disponibles</h6>
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadBackups()">
                                        <i class="fas fa-sync-alt me-1"></i>Actualizar
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="backupsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Archivo</th>
                                                <th>Tipo</th>
                                                <th>Fecha</th>
                                                <th>Tamaño</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="backupsTableBody">
                                            <tr><td colspan="5" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <script>
                                function loadBackups() {
                                    const tbody = document.getElementById('backupsTableBody');
                                    if(!tbody) return;
                                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando...</td></tr>';
                                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                                    const fd = new FormData();
                                    if (csrfToken) fd.append('csrf_token', csrfToken);
                                    
                                    fetch('../backup/ajax/list_backups.php', { method: 'POST', body: fd })
                                        .then(r => r.json())
                                        .then(data => {
                                            if(data.success && data.files.length > 0) {
                                                tbody.innerHTML = data.files.map(f => `
                                                    <tr>
                                                        <td data-label="Archivo" class="text-end text-md-start"><i class="fas fa-file-archive text-muted me-2"></i>${f.name}</td>
                                                        <td data-label="Tipo" class="text-end text-md-start"><span class="badge bg-light text-dark border">${f.type}</span></td>
                                                        <td data-label="Fecha" class="text-end text-md-start">${new Date(f.date * 1000).toLocaleString()}</td>
                                                        <td data-label="Tamaño" class="text-end text-md-start">${(f.size / 1024 / 1024).toFixed(2)} MB</td>
                                                        <td data-label="Acciones" class="text-end text-md-start">
                                                            <div class="btn-group shadow-sm">
                                                                <a href="${f.url}" class="btn btn-sm btn-outline-primary" download title="Descargar"><i class="fas fa-download"></i></a>
                                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('${f.name}')" title="Eliminar"><i class="fas fa-trash"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                `).join('');
                                            } else {
                                                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">No hay respaldos disponibles</td></tr>';
                                            }
                                        })
                                        .catch(e => {
                                            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Error al cargar respaldos</td></tr>';
                                        });
                                }

                                function deleteBackup(filename) {
                                    showConfirm('¿Está seguro de eliminar este respaldo?', function() {
                                        const fd = new FormData();
                                        fd.append('filename', filename);
                                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                                        if (csrfToken) fd.append('csrf_token', csrfToken);
                                        
                                        fetch('../backup/ajax/delete_backup.php', { method: 'POST', body: fd })
                                            .then(r => r.json())
                                            .then(data => {
                                                if(data.success) {
                                                    loadBackups();
                                                    showSuccess('Respaldo eliminado correctamente');
                                                } else {
                                                    showError(data.message || 'Error al eliminar');
                                                }
                                            })
                                            .catch(e => showError('Error de conexión'));
                                    });
                                }
                                </script>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pills-tools" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-4 h-100">
                                    <div class="card-header">
                                        <h5><i class="fas fa-download"></i> Exportar Clientes</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Formato de Exportación</label>
                                                    <select class="form-control" id="export_format">
                                                        <option value="csv">CSV</option>
                                                        <option value="excel">Excel</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Campos a Exportar</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_name" checked>
                                                    <label class="form-check-label" for="export_name">Nombre</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_phone" checked>
                                                    <label class="form-check-label" for="export_phone">Teléfono</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_email" checked>
                                                    <label class="form-check-label" for="export_email">Email</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_address">
                                                    <label class="form-check-label" for="export_address">Dirección</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_identification">
                                                    <label class="form-check-label" for="export_identification">Identificación</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="export_dates">
                                                    <label class="form-check-label" for="export_dates">Fechas</label>
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn btn-primary" onclick="exportClients()">
                                            <i class="fas fa-download"></i> Exportar Clientes
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-4 h-100">
                                    <div class="card-header">
                                        <h5><i class="fas fa-upload"></i> Importar Clientes</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-info">
                                            <strong>Formato esperado:</strong> CSV o Excel con columnas: Nombre, Teléfono, Email, Dirección, Identificación
                                        </div>
                                        <div class="mb-3">
                                            <label for="import_file" class="form-label">Seleccionar Archivo</label>
                                            <input type="file" class="form-control" id="import_file" accept=".csv,.xlsx,.xls">
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="update_existing">
                                            <label class="form-check-label" for="update_existing">
                                                Actualizar clientes existentes (basado en email)
                                            </label>
                                        </div>
                                        <button class="btn btn-primary" onclick="importClients()">
                                            <i class="fas fa-upload"></i> Importar Clientes
                                        </button>
                                        
                                        <div id="import_results" class="mt-3" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-chart-bar"></i> Estadísticas de Clientes</h5>
                            </div>
                            <div class="card-body">
                                <div class="row" id="client_stats">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h3 class="text-dark" id="total_clients">-</h3>
                                            <p class="mb-0">Total Clientes</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h3 class="text-dark" id="clients_with_email">-</h3>
                                            <p class="mb-0">Con Email</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h3 class="text-dark" id="clients_with_phone">-</h3>
                                            <p class="mb-0">Con Teléfono</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h3 class="text-dark" id="recent_clients">-</h3>
                                            <p class="mb-0">Últimos 30 días</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pills-cloud" role="tabpanel">
                        <div class="row justify-content-center">
                            <div class="col-md-8">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="d-flex align-items-center mb-4">
                                            <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                <i class="fas fa-cloud text-info fa-lg no-theme"></i>
                                            </div>
                                            <div>
                                                <h5 class="mb-0 text-dark fw-bold">Almacenamiento en la Nube</h5>
                                                <p class="text-muted small mb-0">Configure el respaldo automático a servicios externos.</p>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-info ms-auto" id="btnCloudHelp">
                                                <i class="fas fa-question-circle me-1"></i>Ayuda Detallada
                                            </button>
                                        </div>

                                        <div class="alert alert-info d-flex align-items-center" role="alert">
                                            <i class="fas fa-info-circle me-2 fa-lg"></i>
                                            <div>
                                                Actualmente soportamos <strong>Google Drive</strong>. Los respaldos se subirán automáticamente después de generarse.
                                            </div>
                                        </div>

                                        <form id="cloudConfigForm">
                                            <div class="mb-3 form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="cloudEnabled" name="cloud_enabled">
                                                <label class="form-check-label fw-bold" for="cloudEnabled">Habilitar Respaldo en la Nube</label>
                                            </div>

                                            <div id="cloudSettings">
                                                <div class="mb-3">
                                                    <label class="form-label">Proveedor</label>
                                                    <select class="form-select" name="provider" id="cloudProvider">
                                                        <option value="google_drive">Google Drive</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Client ID (Google Cloud)</label>
                                                    <input type="text" class="form-control" name="client_id" id="gdriveClientId" placeholder="xxx.apps.googleusercontent.com" autocomplete="off">
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Client Secret</label>
                                                        <input type="text" class="form-control" name="client_secret" id="gdriveClientSecret" placeholder="Client Secret" autocomplete="off">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">ID Carpeta (Google Drive)</label>
                                                    <input type="text" class="form-control" name="gdrive_folder_id" id="gdriveFolderId" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
                                                </div>
                                                <div class="mb-3 form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="cloudEncryptEnabled" name="cloud_encrypt_enabled">
                                                    <label class="form-check-label" for="cloudEncryptEnabled">Cifrar respaldo antes de subir (AES‑256)</label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Clave de cifrado</label>
                                                    <input type="text" class="form-control" name="cloud_encrypt_key" id="cloudEncryptKey" placeholder="Clave de cifrado" autocomplete="off">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Programación</label>
                                                    <div class="row g-2">
                                                        <div class="col-6">
                                                            <select class="form-select" id="cloudScheduleMode" name="cloud_schedule_mode">
                                                                <option value="manual">Manual</option>
                                                                <option value="daily">Diario</option>
                                                                <option value="weekly">Semanal</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-6">
                                                            <input type="time" class="form-control" id="cloudScheduleTime" name="cloud_schedule_time" value="02:00">
                                                        </div>
                                                    </div>
                                                    <small class="form-text text-muted">Se usará Windows Task Scheduler.</small>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Día semanal</label>
                                                    <select class="form-select" id="cloudScheduleWeekday" name="cloud_schedule_weekday">
                                                        <option value="Monday">Lunes</option>
                                                        <option value="Tuesday">Martes</option>
                                                        <option value="Wednesday">Miércoles</option>
                                                        <option value="Thursday">Jueves</option>
                                                        <option value="Friday">Viernes</option>
                                                        <option value="Saturday">Sábado</option>
                                                        <option value="Sunday">Domingo</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3 form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="cloudVerifySSL" name="cloud_verify_ssl">
                                                    <label class="form-check-label" for="cloudVerifySSL">Verificar certificado SSL en subida</label>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Token de API para programación</label>
                                                    <input type="text" class="form-control" id="backupApiToken" name="backup_api_token" placeholder="Crea una contraseña segura (ej: MiTokenSecreto123)">
                                                    <div class="form-text">
                                                        <i class="fas fa-info-circle text-primary"></i> 
                                                        <strong>Invéntate una contraseña aquí.</strong> Windows la usará para ejecutar la tarea programada.
                                                    </div>
                                                </div>
                                                
                                                <div class="d-grid gap-2 mb-3">
                                                    <button type="button" class="btn btn-outline-primary" id="btnGoogleDriveAuth">
                                                        <i class="fab fa-google me-2"></i>Conectar Cuenta Google
                                                    </button>
                                                </div>

                                                <div class="mb-3 d-none" id="authCodeSection">
                                                    <label class="form-label text-success fw-bold">Código de Autorización</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" name="auth_code" id="authCodeInput" placeholder="Pegue el código aquí">
                                                        <button class="btn btn-success" type="button" id="btnVerifyCode">Verificar</button>
                                                    </div>
                                                    <div class="form-text">Si la ventana emergente no funcionó, copie el código manualmente.</div>
                                                </div>

                                                <div id="connectionStatus" class="alert d-none mt-3"></div>
                                            </div>
                                        </form>
                                        <script>
                                        (function(){
                                            // Botón Ayuda
                                            const btn = document.getElementById('btnCloudHelp');
                                            if (btn) {
                                                btn.addEventListener('click', function(){
                                                    const proto = window.location.protocol;
                                                    const host = window.location.host;
                                                    const url = proto + '//' + host + '/core/backup/cloud/help.php';
                                                    const w = 1000, h = 800;
                                                    const left = (screen.width - w) / 2;
                                                    const top = (screen.height - h) / 2;
                                                    window.open(url, 'cloud_help', `width=${w},height=${h},left=${left},top=${top},scrollbars=yes`);
                                                });
                                            }
                                        })();
                                        </script>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
