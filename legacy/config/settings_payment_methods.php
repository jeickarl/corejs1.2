<style>
/* Diseño Responsivo para Tabla de Métodos de Pago y Cuentas */
@media (max-width: 767.98px) {
    #paymentMethodsTable thead { display: none; }
    #paymentMethodsTable, #paymentMethodsTable tbody, #paymentMethodsTable tr, #paymentMethodsTable td { display: block; width: 100%; }
    #paymentMethodsTable tr { margin-bottom: 1rem; background-color: #fff; border: 1px solid rgba(0,0,0,0.1); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); position: relative; }
    #paymentMethodsTable td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 0.75rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.05); text-align: right; }
    #paymentMethodsTable td::before { content: attr(data-label); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; width: 35%; flex-shrink: 0; margin-right: 1rem; text-align: left; }
    #paymentMethodsTable td:last-child { border-bottom: none; background-color: #f8f9fa; font-weight: bold; border-radius: 0 0 0.75rem 0.75rem; }

    #pmAccModalBody { display: block; width: 100%; }
    #pmAccModalBody tr { display: block; width: 100%; margin-bottom: 1rem; border: 1px solid #dee2e6; border-radius: 0.5rem; background: #fff; }
    #pmAccModalBody td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 0.5rem 1rem; border-bottom: 1px solid #dee2e6; text-align: right; }
    #pmAccModalBody td::before { content: attr(data-label); font-weight: bold; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; text-align: left; margin-right: 1rem; }
    #pmAccModalBody td:last-child { border-bottom: none; background-color: #f8f9fa; }
    
    /* Ocultar thead si la tabla completa del modal tiene clase table */
    .modal-body table thead { display: none; }
}
</style>
<script>
(function(){
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c]; }); }
    function parseJsonResponse(response) {
        return response.text().then(function(text){
            const ct = response.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                throw new Error('Respuesta no JSON');
            }
            try { return JSON.parse(text); }
            catch(e){ throw new Error('JSON inválido'); }
        });
    }
    function fetchJson(fd){
        return fetch('config_operations.php',{method:'POST', headers:{'Accept':'application/json'}, body:fd})
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return parseJsonResponse(r); });
    }
    if (typeof window.postJson === 'undefined') {
        window.postJson = function(fd){
            return fetch('config_operations.php',{method:'POST', headers:{'Accept':'application/json'}, body:fd})
                .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return parseJsonResponse(r); });
        };
    }

    function loadMethodsForAccounts(){
        var fd = new FormData(); fd.append('action','payment_methods_list'); fd.append('limit','100'); fd.append('page','1');
        fetchJson(fd).then(function(d){
            var sel = document.getElementById('pa_method'); if (!sel) return; sel.innerHTML='';
            (d.methods||[]).forEach(function(m){ var opt=document.createElement('option'); opt.value=m.id; opt.textContent=m.name; sel.appendChild(opt); });
        }).catch(function(){});
    }
    function renderAccounts(){
        var fd = new FormData(); fd.append('action','payment_accounts_list');
        fetchJson(fd).then(function(d){
            var tbody = document.getElementById('pmAccModalBody'); if (!tbody) return; tbody.innerHTML='';
            (d.accounts||[]).forEach(function(a){
                var tr=document.createElement('tr');
                var active = (parseInt(a.is_active||1)===1);
                var def = (parseInt(a.is_default||0)===1);
                tr.innerHTML = '<td data-label="Alias">'+esc(a.alias||a.account_name||'')+'</td>'
                    + '<td data-label="Número">'+esc(a.account_number||'')+'</td>'
                    + '<td data-label="Tipo">'+esc(a.type||'')+'</td>'
                    + '<td data-label="Titular">'+esc(a.holder_name||'')+'</td>'
                    + '<td data-label="Estado"><span class="badge '+(active?'bg-success':'bg-secondary')+'">'+(active?'Activo':'Inactivo')+'</span></td>'
                    + '<td data-label="Predeterminado"><span class="badge '+(def?'bg-dark':'bg-light text-dark')+'">'+(def?'Sí':'No')+'</span></td>'
                    + '<td data-label="Acciones">'
                    + '<div class="btn-group">'
                        + '<button class="btn btn-sm btn-outline-dark" data-action="acc-edit" data-id="'+a.id+'" data-method="'+esc(a.method_name||a.method_id)+'" data-alias="'+esc(a.alias||a.account_name||'')+'" data-number="'+esc(a.account_number||'')+'" data-type="'+esc(a.type||'')+'" data-holder="'+esc(a.holder_name||'')+'" data-holder_id="'+esc(a.holder_id||'')+'" data-default="'+(def?1:0)+'" data-active="'+(active?1:0)+'"><i class="fas fa-edit"></i></button> '
                        + '<button class="btn btn-sm '+(active?'btn-outline-secondary':'btn-outline-success')+'" data-action="acc-toggle" data-id="'+a.id+'" data-next="'+(active?'inactive':'active')+'"><i class="fas '+(active?'fa-eye-slash':'fa-eye')+'"></i></button> '
                        + '<button class="btn btn-sm '+(def?'btn-outline-secondary':'btn-outline-info')+'" data-action="acc-default" data-id="'+a.id+'"><i class="fas fa-star"></i></button> '
                        + '<button class="btn btn-sm btn-outline-danger" data-action="acc-delete" data-id="'+a.id+'"><i class="fas fa-trash"></i></button>'
                        + '</div></td>';
                tbody.appendChild(tr);
            });
        }).catch(function(err){ if (err && err.name==='AbortError') return; console.error('Error rendering accounts:', err); });
    }
    window.loadPM = window.loadPM || function(){};
    document.addEventListener('DOMContentLoaded', function(){
        loadMethodsForAccounts();
        renderAccounts();
        var tabBtn = document.getElementById('payment-methods-tab');
        if (tabBtn) { tabBtn.addEventListener('shown.bs.tab', function(){ loadMethodsForAccounts(); renderAccounts(); }); }
        var addBtn = document.getElementById('pa_add_btn');
        if (addBtn) addBtn.addEventListener('click', function(){
            var fd = new FormData(); fd.append('action','payment_accounts_add');
            fd.append('method_id', (document.getElementById('pa_method')||{}).value||'');
            fd.append('alias', (document.getElementById('pa_alias')||{}).value||'');
            fd.append('number', (document.getElementById('pa_number')||{}).value||'');
            fd.append('type', (document.getElementById('pa_type')||{}).value||'');
            fd.append('holder', (document.getElementById('pa_holder')||{}).value||'');
            fd.append('holder_id', (document.getElementById('pa_holder_id')||{}).value||'');
            fd.append('is_default', (document.getElementById('pa_default')||{checked:false}).checked ? '1':'0');
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } }).catch(function(){});
        });
    });
    document.addEventListener('click', function(e){
        var b = e.target.closest('button'); if(!b) return; var a=b.getAttribute('data-action'); var id=b.getAttribute('data-id');
        if(a==='acc-toggle'){
            var next=b.getAttribute('data-next'); var fd=new FormData(); fd.append('action','payment_accounts_toggle'); fd.append('id',id); fd.append('state',next);
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } }).catch(function(){});
        } else if(a==='acc-default'){
            var fd=new FormData(); fd.append('action','payment_accounts_set_default'); fd.append('id',id);
            fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } }).catch(function(){});
        } else if(a==='acc-delete'){
            if (typeof showConfirm==='function') {
                showConfirm('¿Estás seguro de eliminar esta cuenta?', function(){
                    var fd=new FormData(); fd.append('action','payment_accounts_delete'); fd.append('id',id);
                    fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); if (typeof showSuccess==='function') showSuccess('Cuenta eliminada'); } else { if (typeof showError==='function') showError(d && d.message ? d.message : 'Error al eliminar cuenta'); } }).catch(function(){});
                });
            } else {
                var fd=new FormData(); fd.append('action','payment_accounts_delete'); fd.append('id',id);
                fetchJson(fd).then(function(d){ if(d && d.success){ renderAccounts(); refreshPMAccountsModal(); } }).catch(function(){});
            }
        } else if(a==='acc-edit'){
            var modalEl = document.getElementById('accEditModal'); if(!modalEl) return;
            var methodInput = document.getElementById('accEditMethod');
            var aliasInput = document.getElementById('accEditAlias');
            var numberInput = document.getElementById('accEditNumber');
            var typeSelect = document.getElementById('accEditType');
            var holderInput = document.getElementById('accEditHolder');
            var holderIdInput = document.getElementById('accEditHolderId');
            var defChk = document.getElementById('accEditDefault');
            var actChk = document.getElementById('accEditActive');
            var idInput = document.getElementById('accEditId');
            if (methodInput) methodInput.value = b.getAttribute('data-method')||'';
            if (aliasInput && numberInput && idInput){ aliasInput.value = b.getAttribute('data-alias')||''; numberInput.value = b.getAttribute('data-number')||''; idInput.value = id; }
            if (typeSelect) typeSelect.value = b.getAttribute('data-type')||'';
            if (holderInput) holderInput.value = b.getAttribute('data-holder')||'';
            if (holderIdInput) holderIdInput.value = b.getAttribute('data-holder_id')||'';
            if (defChk) defChk.checked = (b.getAttribute('data-default')==='1');
            if (actChk) actChk.checked = (b.getAttribute('data-active')==='1');
            try { document.body.appendChild(modalEl); } catch(e){}
            new bootstrap.Modal(modalEl).show();
        }
    });
    (function(){
        document.addEventListener('DOMContentLoaded', function(){
            var saveAcc = document.getElementById('accEditSave');
            if (saveAcc) saveAcc.addEventListener('click', function(){
                var id = document.getElementById('accEditId')?.value || '';
                var alias = document.getElementById('accEditAlias')?.value || '';
                var number = document.getElementById('accEditNumber')?.value || '';
                var type = document.getElementById('accEditType')?.value || '';
                var holder = document.getElementById('accEditHolder')?.value || '';
                var holder_id = document.getElementById('accEditHolderId')?.value || '';
                var is_default = document.getElementById('accEditDefault')?.checked ? '1':'0';
                var is_active = document.getElementById('accEditActive')?.checked ? '1':'0';
                var fd=new FormData(); fd.append('action','payment_accounts_update'); fd.append('id',id); fd.append('alias',alias); fd.append('number',number); fd.append('type',type); fd.append('holder',holder); fd.append('holder_id',holder_id); fd.append('is_default',is_default); fd.append('is_active',is_active);
                fetchJson(fd).then(function(d){ if(d && d.success){ var mEl=document.getElementById('accEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } renderAccounts(); refreshPMAccountsModal(); } }).catch(function(){});
            });
            var pmSave = document.getElementById('pmEditSave');
            if (pmSave) pmSave.addEventListener('click', function(){
                var id = document.getElementById('pmEditId')?.value || '';
                var name = (document.getElementById('pmEditName')?.value || '').trim();
                var accId = document.getElementById('pmEditAccountId')?.value || '';
                var accNumber = document.getElementById('pmEditAccNumber')?.value || '';
                var accType = document.getElementById('pmEditAccType')?.value || '';
                var accHolder = document.getElementById('pmEditAccHolder')?.value || '';
                var accHolderId = document.getElementById('pmEditAccHolderId')?.value || '';
                if (!id) return;
                var doAccounts = function(){
                    if (accNumber.trim()!==''){
                        var fa = new FormData();
                        if (accId){ fa.append('action','payment_accounts_update'); fa.append('id', accId); }
                        else { fa.append('action','payment_accounts_add'); fa.append('method_id', id); }
                        fa.append('alias','');
                        fa.append('number', accNumber);
                        fa.append('type', accType);
                        fa.append('holder', accHolder);
                        fa.append('holder_id', accHolderId);
                        fa.append('is_default','1');
                        fetchJson(fa).then(function(dx){ var mEl=document.getElementById('pmEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } loadPM && loadPM(); }).catch(function(){});
                    } else { var mEl=document.getElementById('pmEditModal'); if(mEl){ try{ bootstrap.Modal.getInstance(mEl)?.hide(); }catch(e){} } loadPM && loadPM(); }
                };
                if (name){
                    var fd = new FormData(); fd.append('action','payment_methods_update'); fd.append('id', id); fd.append('name', name);
                    var m = document.querySelector('meta[name="csrf-token"]'); if (m) fd.append('csrf_token', m.getAttribute('content'));
                    fetchJson(fd).then(function(d){ if(d && d.success){ doAccounts(); } }).catch(function(){});
                } else {
                    doAccounts();
                }
            });
            
        });
    })();
})();
</script>

<!-- TAB: Pagos (#payment-methods) -->
<div class="tab-pane" id="payment-methods" role="tabpanel">
    <div class="row mt-4">
        <div class="col-md-12">
            
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
                <div class="card-header bg-white border-bottom-0 pt-4 ps-4 pe-4 d-flex justify-content-between align-items-center" style="border-radius: 1rem 1rem 0 0;">
                    <h5 class="mb-0 text-dark">
                        <i class="fas fa-credit-card me-2"></i>Métodos de Pago
                    </h5>
                    <button class="btn btn-dark rounded-pill shadow-sm" id="pm_add_btn">
                        <i class="fas fa-plus me-2"></i>Crear método
                    </button>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="paymentMethodsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="border-0 ps-4" style="border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Nombre</th>
                                    <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Cuenta</th>
                                    <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Titular</th>
                                    <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Tipo</th>
                                    <th class="border-0" style="padding-top: 1rem; padding-bottom: 1rem;">Estado</th>
                                    <th class="border-0 pe-4 text-end" style="border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; padding-top: 1rem; padding-bottom: 1rem;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="pmTableBody">
                                <?php
$pm_has_status = false;
$pm_has_is_active = false;
$pm_list = [];
try {
    $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'status'");
    $pm_has_status = $c && $c->rowCount() > 0;
}
catch (PDOException $e) {
}
try {
    $c = $pdo->query("SHOW COLUMNS FROM payment_methods LIKE 'is_active'");
    $pm_has_is_active = $c && $c->rowCount() > 0;
}
catch (PDOException $e) {
}

// Asegurar tabla de cuentas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_method_accounts (
                                        id INT(11) NOT NULL AUTO_INCREMENT,
                                        method_id INT(11) NOT NULL,
                                        account_name VARCHAR(100) NULL,
                                        alias VARCHAR(100) NULL,
                                        account_number VARCHAR(100) NOT NULL,
                                        type VARCHAR(50) NULL,
                                        holder_name VARCHAR(120) NULL,
                                        holder_id VARCHAR(60) NULL,
                                        is_default TINYINT(1) NOT NULL DEFAULT 0,
                                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                                        PRIMARY KEY (id),
                                        INDEX(method_id)
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
catch (Exception $e) {
}

try {
    $status_col = $pm_has_status ? ", m.status" : ($pm_has_is_active ? ", m.is_active" : "");
    $sql = "SELECT m.id, m.name $status_col, 
                                            (SELECT account_number FROM payment_method_accounts WHERE method_id = m.id ORDER BY is_default DESC LIMIT 1) as account_number,
                                            (SELECT holder_name FROM payment_method_accounts WHERE method_id = m.id ORDER BY is_default DESC LIMIT 1) as holder_name,
                                            (SELECT type FROM payment_method_accounts WHERE method_id = m.id ORDER BY is_default DESC LIMIT 1) as type
                                            FROM payment_methods m ORDER BY m.name ASC LIMIT 6";
    $stmt = $pdo->query($sql);
    $pm_list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
catch (PDOException $e) {
    $pm_list = [];
}
foreach ($pm_list as $m):
    $active = true;
    if ($pm_has_status) {
        $active = (($m['status'] ?? '') === 'active');
    }
    elseif ($pm_has_is_active) {
        $active = (intval($m['is_active'] ?? 1) === 1);
    }

    // Icono dinámico
    $icon = 'fa-money-bill-wave';
    $lowerName = mb_strtolower($m['name'], 'UTF-8');
    if (strpos($lowerName, 'tarjeta') !== false || strpos($lowerName, 'visa') !== false || strpos($lowerName, 'master') !== false) {
        $icon = 'fa-credit-card';
    }
    elseif (strpos($lowerName, 'banco') !== false || strpos($lowerName, 'transferencia') !== false) {
        $icon = 'fa-university';
    }
    elseif (strpos($lowerName, 'efectivo') !== false || strpos($lowerName, 'cash') !== false) {
        $icon = 'fa-coins';
    }
    elseif (strpos($lowerName, 'nequi') !== false || strpos($lowerName, 'daviplata') !== false || strpos($lowerName, 'movil') !== false) {
        $icon = 'fa-mobile-alt';
    }
    elseif (strpos($lowerName, 'cheque') !== false) {
        $icon = 'fa-money-check-alt';
    }
?>
                                <tr>
                                    <td class="ps-4" data-label="Nombre">
                                        <div class="d-flex align-items-center justify-content-end justify-content-md-start">
                                            <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 45px; height: 45px;">
                                                <i class="fas <?php echo $icon; ?> text-dark fa-lg"></i>
                                            </div>
                                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($m['name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Cuenta"><span class="text-muted"><?php echo htmlspecialchars($m['account_number'] ?? '-'); ?></span></td>
                                    <td data-label="Titular"><span class="text-muted"><?php echo htmlspecialchars($m['holder_name'] ?? '-'); ?></span></td>
                                    <td data-label="Tipo"><span class="text-muted"><?php echo htmlspecialchars($m['type'] ?? '-'); ?></span></td>
                                    <td data-label="Estado">
                                        <span class="badge rounded-pill bg-<?php echo $active ? 'success' : 'secondary'; ?> bg-opacity-10 text-<?php echo $active ? 'success' : 'secondary'; ?> px-3 py-2 border border-<?php echo $active ? 'success' : 'secondary'; ?> border-opacity-10">
                                            <i class="fas fa-<?php echo $active ? 'check-circle' : 'times-circle'; ?> me-1"></i>
                                            <?php echo $active ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td class="pe-4 text-end" data-label="Acciones">
                                        <div class="btn-group shadow-sm" role="group">
                                            <button class="btn btn-sm btn-outline-dark rounded-start" data-action="edit" data-id="<?php echo intval($m['id']); ?>" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm <?php echo $active ? 'btn-outline-secondary' : 'btn-outline-dark'; ?>" data-action="toggle" data-id="<?php echo intval($m['id']); ?>" data-next="<?php echo $active ? 'inactive' : 'active'; ?>" title="<?php echo $active ? 'Desactivar' : 'Activar'; ?>">
                                                <i class="fas <?php echo $active ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger rounded-end" data-action="delete" data-id="<?php echo intval($m['id']); ?>" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php
endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            
        </div>
    </div>
</div>
</div>
