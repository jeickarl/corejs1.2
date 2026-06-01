<?php
require_once '../config/auth.php';
require_once '../config/database.php';
require_once '../config/functions.php';
$pdo = db();
requireLogin();

$order_id = (int)($_GET['id'] ?? 0);
if (!$order_id) {
    echo '<div class="p-4">Orden no encontrada</div>';
    exit;
}
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
try {
    if ($perDatabase && function_exists('hasColumnCached') && !hasColumnCached($pdo, 'work_orders', 'approval_status')) {
        $pdo->exec("ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none'");
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
            $_SESSION['schema_cache_cols']['work_orders_approval_status'] = true;
        }
    }
    if ($perDatabase && function_exists('hasColumnCached') && !hasColumnCached($pdo, 'work_orders', 'order_number')) {
        $pdo->exec("ALTER TABLE work_orders ADD COLUMN order_number INT(11) NOT NULL DEFAULT 0 AFTER id");
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['schema_cache_cols'])) { $_SESSION['schema_cache_cols'] = []; }
            $_SESSION['schema_cache_cols']['work_orders_order_number'] = true;
        }
    }
} catch (Throwable $__) {
}

if ($perDatabase) {
    $hasOrderNumber = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'order_number') : false;
    $sql = $hasOrderNumber
        ? "SELECT id, status, approval_status, order_number FROM work_orders WHERE id = ?"
        : "SELECT id, status, approval_status, 0 AS order_number FROM work_orders WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$order_id]);
} else {
    $stmt = $pdo->prepare("SELECT id, status, approval_status, order_number FROM work_orders WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$order_id, $tenant_id]);
}
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    echo '<div class="p-4">Orden no encontrada</div>';
    exit;
}
$statuses = [];
try {
    $tenant_id = getCurrentTenantId();
    $hasTenant = hasTenantColumnCached($pdo, 'order_statuses');
    if ($hasTenant && !$perDatabase) {
        $st = $pdo->prepare("SELECT slug, name, emoji, color, sort_order FROM order_statuses WHERE is_active = 1 AND tenant_id = ? AND slug <> 'approved' ORDER BY sort_order, name");
        $st->execute([$tenantValue]);
        $statuses = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT slug, name, emoji, color, sort_order FROM order_statuses WHERE is_active = 1 AND slug <> 'approved' ORDER BY sort_order, name");
        $st->execute();
        $statuses = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $statuses = [];
}
$uniq = [];
$out = [];
$syn = ['esperando aprobacion','esperando-aprobacion','esperando_aprobación','esperando aprobación','esperandoaprobacion','esperando_aprovacion','waiting_authorization','waiting approval'];
foreach ($statuses as $row) {
    $slug = strtolower(trim((string)($row['slug'] ?? '')));
    if (in_array($slug, $syn, true)) {
        $slug = 'esperando_aprobacion';
        $row['slug'] = 'esperando_aprobacion';
        if (!isset($row['name']) || trim((string)$row['name']) === '') { $row['name'] = 'Esperando Aprobación'; }
        if (!isset($row['emoji']) || trim((string)$row['emoji']) === '') { $row['emoji'] = '✍️'; }
        if (!isset($row['color']) || trim((string)$row['color']) === '') { $row['color'] = '#ffc107'; }
    }
    if (isset($uniq[$slug])) { continue; }
    $uniq[$slug] = true;
    $out[] = $row;
}
$statuses = $out;
if (!$statuses || count($statuses) === 0) {
    $statuses = [
        ['slug' => 'pendiente', 'name' => 'Pendiente', 'emoji' => '⏳', 'color' => '#ffc107', 'sort_order' => 1],
        ['slug' => 'esperando_aprobacion', 'name' => 'Esperando Aprobación', 'emoji' => '✍️', 'color' => '#ffc107', 'sort_order' => 1],
        ['slug' => 'asignado', 'name' => 'Asignado', 'emoji' => '📦', 'color' => '#6cc4ea', 'sort_order' => 2],
        ['slug' => 'diagnosticando', 'name' => 'Diagnosticando', 'emoji' => '🔍', 'color' => '#fd7e14', 'sort_order' => 3],
        ['slug' => 'esperando_repuestos', 'name' => 'Esperando Repuestos', 'emoji' => '⏸️', 'color' => '#6f42c1', 'sort_order' => 4],
        ['slug' => 'reparando', 'name' => 'Reparando', 'emoji' => '🔧', 'color' => '#007bff', 'sort_order' => 5],
        ['slug' => 'testeando', 'name' => 'Testeando', 'emoji' => '🧪', 'color' => '#17a2b8', 'sort_order' => 6],
        ['slug' => 'completado', 'name' => 'Completado', 'emoji' => '✅', 'color' => '#28a745', 'sort_order' => 7],
        ['slug' => 'entregado', 'name' => 'Entregado', 'emoji' => '🚚', 'color' => '#6c757d', 'sort_order' => 8],
        ['slug' => 'cancelado', 'name' => 'Cancelado', 'emoji' => '❌', 'color' => '#dc3545', 'sort_order' => 9],
    ];
}
try {
    $hasWait = false;
    foreach ($statuses as $s) {
        $slug = strtolower(trim((string)($s['slug'] ?? '')));
        if ($slug === 'esperando_aprobacion') { $hasWait = true; break; }
    }
    if (!$hasWait) {
        array_unshift($statuses, ['slug' => 'esperando_aprobacion', 'name' => 'Esperando Aprobación', 'emoji' => '✍️', 'color' => '#ffc107', 'sort_order' => 1]);
    }
} catch (Throwable $e) {}
try {
    $hasAprobado = false;
    foreach ($statuses as $s) {
        $slug = strtolower(trim((string)($s['slug'] ?? '')));
        if ($slug === 'aprobado') { $hasAprobado = true; break; }
    }
    if (!$hasAprobado) {
        $statuses[] = ['slug' => 'aprobado', 'name' => 'Aprobado', 'emoji' => '✍️', 'color' => '#28a745', 'sort_order' => 0];
    }
} catch (Throwable $e) {}
try {
    if ($perDatabase) {
        $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_statuses_template' LIMIT 1");
        $tplStmt->execute([]);
    } else {
        $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_statuses_template' LIMIT 1");
        $tplStmt->execute([$tenant_id]);
    }
    $tplJson = (string)$tplStmt->fetchColumn();
    if ($tplJson) {
        $tplArr = json_decode($tplJson, true);
        if (is_array($tplArr) && count($tplArr) > 0) {
            $pos = [];
            foreach ($tplArr as $i => $row) {
                $slug = strtolower(trim((string)($row['slug'] ?? '')));
                if ($slug !== '') { $pos[$slug] = $i; }
            }
            usort($statuses, function($a, $b) use ($pos) {
                $sa = strtolower(trim($a['slug'] ?? ''));
                $sb = strtolower(trim($b['slug'] ?? ''));
                $pa = array_key_exists($sa, $pos) ? $pos[$sa] : PHP_INT_MAX;
                $pb = array_key_exists($sb, $pos) ? $pos[$sb] : PHP_INT_MAX;
                if ($pa === $pb) {
                    $oa = (int)($a['sort_order'] ?? 0);
                    $ob = (int)($b['sort_order'] ?? 0);
                    if ($oa === $ob) return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                    return $oa <=> $ob;
                }
                return $pa <=> $pb;
            });
        }
    }
} catch (Throwable $e) {}
ob_start();
?>
<div class="modal-header bg-dark text-white border-0">
    <h5 class="modal-title">
        <i class="fas fa-exchange-alt me-2"></i>Cambiar Estado
        <?php
            $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id'];
            $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD';
            $display = $prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
        ?>
        <span class="text-white-50 ms-2"><?= htmlspecialchars($display) ?></span>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body bg-light">
    <form id="changeStatusForm">
        <input type="hidden" name="action" value="change_status">
        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
        <div class="mb-3">
            <label class="form-label small text-muted fw-bold">Estado</label>
            <select class="form-select rounded-pill" name="status" id="statusSelect">
<?php 
$currentRaw = strtolower(trim((string)($order['status'] ?? '')));
$apSlug = strtolower(trim((string)($order['approval_status'] ?? '')));
$aliasesCur = [
    'pending' => 'pendiente',
    'received' => 'asignado',
    'diagnosing' => 'diagnosticando',
    'waiting_parts' => 'esperando_repuestos',
    'repairing' => 'reparando',
    'testing' => 'testeando',
    'completed' => 'completado',
    'delivered' => 'entregado',
    'cancelled' => 'cancelado',
    'canceled' => 'cancelado'
];
$currentSlug = getEffectiveStatusSlug($currentRaw, $apSlug);
foreach ($statuses as $s): 
    $active = ($currentSlug === $s['slug']);
    $raw = trim($s['emoji'] ?? '');
    $useDefault = ($raw === '' || preg_match('/^\?+$/', $raw));
    $map = [
        'pending' => '⏳',
        'esperando_aprobacion' => '✍️',
        'received' => '📦',
        'diagnosing' => '🔍',
        'aprobado' => '✍️',
        'waiting_parts' => '⏸️',
        'repairing' => '🔧',
        'testing' => '🧪',
        'completed' => '✅',
        'delivered' => '🚚',
        'cancelled' => '❌',
        'devolucion' => '↩️',
        'cancelado' => '❌',
        'entregado' => '🚚'
    ];
    $displayEmoji = $useDefault ? ($map[$s['slug']] ?? '❓') : $raw;
    $text = $displayEmoji . ' ' . $s['name'];
    $color = $s['color'] ?: '#6c757d';
?>
                <option value="<?= htmlspecialchars($s['slug']) ?>"
                        data-color="<?= htmlspecialchars($color) ?>"
                        data-text="<?= htmlspecialchars($text) ?>"
                        <?= $active ? 'selected' : '' ?>>
                    <?= htmlspecialchars($text) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mt-4">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white border-0">
                    <h6 class="mb-0 text-dark"><i class="fas fa-tools me-2"></i>Detalles del Servicio</h6>
                </div>
                <div class="card-body">
                    <?php
                    $hasTenantWo = hasTenantColumnCached($pdo, 'work_orders');
                    if ($hasTenantWo && !$perDatabase) {
                        $detailStmt = $pdo->prepare("SELECT reported_issue, diagnosis, solution, technician_notes FROM work_orders WHERE id = ? AND tenant_id = ?");
                        $detailStmt->execute([$order['id'], $tenantValue]);
                    } else {
                        $detailStmt = $pdo->prepare("SELECT reported_issue, diagnosis, solution, technician_notes FROM work_orders WHERE id = ? LIMIT 1");
                        $detailStmt->execute([$order['id']]);
                    }
                    $details = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: ['reported_issue'=>'','diagnosis'=>'','solution'=>'','technician_notes'=>''];
                    ?>
                    <div class="mb-3">
            <label class="form-label small text-muted fw-bold">Problema reportado</label>
                        <div class="bg-light p-3 rounded-3 border-start border-4 border-secondary">
                            <?= nl2br(htmlspecialchars($details['reported_issue'] ?? '')) ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">Diagnóstico</label>
                        <textarea class="form-control rounded-3 shadow-sm" id="diagInput" rows="3" placeholder="Escribe el diagnóstico..."><?= htmlspecialchars($details['diagnosis'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">Solución</label>
                        <textarea class="form-control rounded-3 shadow-sm" id="solInput" rows="3" placeholder="Escribe la solución..."><?= htmlspecialchars($details['solution'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-bold">Notas internas</label>
                        <textarea class="form-control rounded-3 shadow-sm" id="techNotesInput" rows="3" placeholder="Notas internas del técnico..."><?= htmlspecialchars($details['technician_notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-3 mt-3" id="deliveryFields" style="display:none;">
                <div class="card-header bg-white border-0">
                    <h6 class="mb-0 text-dark"><i class="fas fa-truck me-2"></i>Entrega</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted fw-bold">Costo</label>
                            <input type="number" step="0.01" min="0" class="form-control rounded-3" name="final_cost" id="finalCostInput">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted fw-bold">Abono</label>
                            <input type="number" step="0.01" min="0" class="form-control rounded-3" name="delivery_payment" id="deliveryPaymentInput">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<div class="modal-footer border-0 bg-light">
    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="submitChangeStatus()">
        <i class="fas fa-save me-2"></i>Guardar
    </button>
</div>
<script>
function submitChangeStatus() {
    function safeParseJson(text) {
        try { return JSON.parse(text); } catch (_) {}
        const start = text.indexOf('{');
        const end = text.lastIndexOf('}');
        if (start !== -1 && end !== -1 && end > start) {
            const maybe = text.slice(start, end + 1);
            try { return JSON.parse(maybe); } catch (_) {}
        }
        return null;
    }
    function showInlineAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show fixed-top m-3 shadow-lg`;
        alertDiv.style.maxWidth = '500px';
        alertDiv.style.left = '50%';
        alertDiv.style.transform = 'translateX(-50%)';
        alertDiv.style.zIndex = '2000';
        alertDiv.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : (type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle')} me-2 fa-lg"></i>
                <div>${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        document.body.appendChild(alertDiv);
        setTimeout(() => {
            if (alertDiv.parentNode) {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            }
        }, 4000);
    }
    const form = document.getElementById('changeStatusForm');
    const fd = new FormData(form);
    const diag = document.getElementById('diagInput').value;
    const sol = document.getElementById('solInput').value;
    const tech = document.getElementById('techNotesInput').value;
    const sel = document.getElementById('statusSelect');
    const selectedSlug = sel ? sel.value : '';
    const currentStatus = <?= json_encode($currentSlug) ?>;
    if (selectedSlug === currentStatus) {
        showInlineAlert('warning', 'Ya estás seleccionando el mismo estado. No se realizaron cambios.');
        return;
    }
    const detailsFd = new FormData();
    detailsFd.append('action', 'update_details');
    detailsFd.append('order_id', <?= (int)$order['id'] ?>);
    detailsFd.append('diagnosis', diag);
    detailsFd.append('solution', sol);
    detailsFd.append('technician_notes', tech);
    
    // Incluir token CSRF
    const tokenInput = document.getElementById('csrf_token');
    if (tokenInput) {
        detailsFd.append('csrf_token', tokenInput.value);
        if (!fd.has('csrf_token')) {
            fd.append('csrf_token', tokenInput.value);
        }
    }

    fetch('operations.php', { method: 'POST', body: detailsFd, headers: { 'Accept': 'application/json' } })
    .then(r => {
        if (!r.ok) throw new Error('Error en la solicitud: ' + r.statusText);
        return r.text();
    })
    .then(text => {
        const data = safeParseJson(text);
        if (data) return data;
        console.error('Respuesta no válida (detalles):', text);
        if (!text.trim()) return {};
        throw new Error('Error en la respuesta del servidor');
    })
    .then(() => {
        if (selectedSlug === 'delivered') {
            const fc = document.getElementById('finalCostInput').value;
            const dp = document.getElementById('deliveryPaymentInput').value;
            fd.append('final_cost', fc);
            fd.append('delivery_payment', dp);
        }
        return fetch('operations.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
    })
    .then(r => {
        if (!r.ok) throw new Error('Error en la solicitud: ' + r.statusText);
        return r.text();
    })
    .then(text => {
        const data = safeParseJson(text);
        if (data) return data;
        console.error('Respuesta no válida (estado):', text);
        throw new Error('Error en la respuesta del servidor');
    })
    .then(data => {
        if (data && data.success) {
            const modalEl = document.getElementById('changeStatusModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            const sel = document.getElementById('statusSelect');
            if (sel && sel.selectedIndex >= 0) {
                const opt = sel.options[sel.selectedIndex];
                const text = opt.getAttribute('data-text') || opt.textContent.trim();
                const color = opt.getAttribute('data-color') || '';
                const badgeEl = document.getElementById('currentStatusBadge');
                if (badgeEl) {
                    badgeEl.textContent = text;
                    if (color) {
                        badgeEl.style.backgroundColor = color;
                        badgeEl.style.color = '#fff';
                    }
                }
                const listBadge = document.getElementById('statusBadge_' + <?= (int)$order['id'] ?>);
                if (listBadge) {
                    listBadge.textContent = text;
                    if (color) {
                        listBadge.style.backgroundColor = color;
                        listBadge.style.color = '#fff';
                    }
                }
            }
            // Mostrar mensaje de éxito
            // showInlineAlert('success', data.message || 'Estado actualizado correctamente');
            // Recargar para reflejar cambios (opcional, si se prefiere)
             window.location.reload();
        } else {
            showInlineAlert('danger', data && data.message ? data.message : 'Error al cambiar estado');
        }
    })
    .catch(err => {
        console.error(err);
        showInlineAlert('danger', 'Error de conexión o respuesta inválida: ' + err.message);
    });
}
document.getElementById('statusSelect').addEventListener('change', function(){
    const v = this.value;
    const box = document.getElementById('deliveryFields');
    box.style.display = (v === 'delivered') ? '' : 'none';
});
document.addEventListener('DOMContentLoaded', function(){
    const sel = document.getElementById('statusSelect');
    if (sel) {
        const box = document.getElementById('deliveryFields');
        box.style.display = (sel.value === 'delivered') ? '' : 'none';
    }
});
</script>
<?php
echo ob_get_clean();
?>
