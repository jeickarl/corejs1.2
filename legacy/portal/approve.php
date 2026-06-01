<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$nocacheHeaders = [
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
    'Pragma: no-cache',
    'Expires: 0'
];
foreach ($nocacheHeaders as $h) { header($h); }

$slug = isset($_GET['t']) ? trim($_GET['t']) : '';
$tenant_id = $slug ? getTenantIdFromSlug($slug) : null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
// Evitar perder el cuerpo POST: solo redirigir slugs numéricos en solicitudes GET
if ($tenant_id && ctype_digit($slug) && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')) {
    $pretty = getTenantPreferredSlug((int)$tenant_id);
    if ($pretty) {
        $qs = $_GET;
        $qs['t'] = $pretty;
        $base = getSystemBaseUrl() . 'portal/approve.php';
        $sep = '?';
        $url = $base . $sep . http_build_query($qs);
        header('Location: ' . $url, true, 302);
        exit;
    }
}

$portalTenantId = (int)($tenant_id ?: 0);
if (!$portalTenantId) {
    http_response_code(404);
    echo 'Portal no disponible';
    exit;
}

if ($perDatabase && class_exists('DatabaseManager')) {
    try {
        $pdo = DatabaseManager::tenant($portalTenantId);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
    } catch (Throwable $e) {
        http_response_code(503);
        echo 'Portal no disponible';
        exit;
    }
}

$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
$hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : (int)($_GET['order_id'] ?? 0);
$order_no = isset($_POST['order_no']) ? trim($_POST['order_no']) : trim($_GET['order_no'] ?? '');
$verification_code = strtoupper(trim($_POST['verification_code'] ?? ($_GET['verification_code'] ?? '')));
$verification_code = preg_replace('/[^A-Z0-9]/', '', $verification_code);
$id_full = trim($_POST['id_full'] ?? ($_GET['id_full'] ?? ''));
$id_full = preg_replace('/\D/', '', $id_full);
// Priorizar cédula si llegan ambos por cualquier motivo
if ($id_full !== '' && $verification_code !== '') {
    $verification_code = '';
}

$company = [];
try {
    if (!$perDatabase && $hasTenantCompany) {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$portalTenantId]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: [];
}
catch (Throwable $e) {
}

function renderPortalMessage($title, $message, $slug, $company, $type = 'info', $order_id = null)
{
    global $pdo, $tenant_id;
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
    $icon = $type === 'error' ? '<i class="fa-solid fa-triangle-exclamation text-danger" style="font-size: 3.5rem;"></i>' :
        ($type === 'success' ? '<i class="fa-solid fa-circle-check text-success" style="font-size: 3.5rem;"></i>' :
        '<i class="fa-solid fa-circle-info text-primary" style="font-size: 3.5rem;"></i>');

    $logo_html = '';
    if (!empty($company['company_logo'])) {
        $logo_html = '<img src="' . getSystemBaseUrl() . 'assets/img/' . htmlspecialchars($company['company_logo']) . '" alt="Logo" style="max-height: 40px; margin-right: 12px; border-radius: 8px;" loading="lazy" decoding="async" onerror="this.onerror=null; this.src=\'' . getSystemBaseUrl() . 'assets/img/logo.png\';">';
    }
    $cname = htmlspecialchars($company['company_name'] ?? '');

    $order_summary_html = '';
    if ($order_id && $type !== 'error') {
        $tid = $tenant_id ?: null;
        try {
            if (!$perDatabase && $hasTenantWorkOrders && !$tid) {
                $probe = $pdo->prepare("SELECT tenant_id FROM work_orders WHERE id = ? LIMIT 1");
                $probe->execute([$order_id]);
                $tid = (int)($probe->fetchColumn() ?: 0);
            }
        } catch (Throwable $__) {}
        $orderInfo = ['device_brand'=>'','device_model'=>'','reported_issue'=>'','status'=>'','order_number'=>null,'estimated_cost'=>0];
        try {
            $sql = "SELECT device_brand, device_model, reported_issue, status, order_number, estimated_cost FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1";
            $stI = $pdo->prepare($sql);
            $stI->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, (int)$tid] : [$order_id]);
            $orderInfo = $stI->fetch(PDO::FETCH_ASSOC) ?: $orderInfo;
        } catch (Throwable $__) {}
        $history = [];
        try {
            $hasTenantHist = false;
            try {
                $c = $pdo->query("SHOW COLUMNS FROM order_status_history LIKE 'tenant_id'");
                $hasTenantHist = ($c && $c->rowCount() > 0);
            } catch (Throwable $__) {}
            if ($hasTenantHist && !$perDatabase) {
                $stH = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? AND tenant_id = ? ORDER BY created_at ASC");
                $stH->execute([$order_id, $tid]);
            } else {
                $stH = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
                $stH->execute([$order_id]);
            }
            $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $__) {}
        $statusStyles = [
            'pending' => ['icon' => 'fa-hourglass-half', 'color' => '#64748b'],
            'received' => ['icon' => 'fa-box', 'color' => '#0ea5e9'],
            'diagnosing' => ['icon' => 'fa-magnifying-glass', 'color' => '#8b5cf6'],
            'approved' => ['icon' => 'fa-circle-check', 'color' => '#10b981'],
            'waiting_parts' => ['icon' => 'fa-pause', 'color' => '#f59e0b'],
            'repairing' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#2563eb'],
            'testing' => ['icon' => 'fa-flask', 'color' => '#06b6d4'],
            'completed' => ['icon' => 'fa-circle-check', 'color' => '#22c55e'],
            'ready' => ['icon' => 'fa-flag-checkered', 'color' => '#14b8a6'],
            'delivered' => ['icon' => 'fa-truck', 'color' => '#0ea5e9'],
            'cancelled' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
            'rejected' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
            'esperando_aprobacion' => ['icon' => 'fa-pen-to-square', 'color' => '#f59e0b'],
            'esperando_repuestos' => ['icon' => 'fa-pause', 'color' => '#6f42c1'],
            'reparando' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#007bff'],
            'testeando' => ['icon' => 'fa-flask', 'color' => '#17a2b8'],
            'completado' => ['icon' => 'fa-circle-check', 'color' => '#28a745'],
            'entregado' => ['icon' => 'fa-truck', 'color' => '#6c757d'],
            'cancelado' => ['icon' => 'fa-circle-xmark', 'color' => '#dc3545'],
            'asignado' => ['icon' => 'fa-box', 'color' => '#6cc4ea'],
            'pendiente' => ['icon' => 'fa-hourglass-half', 'color' => '#ffc107']
        ];
        $statusLabelUI = 'Pendiente';
        $raw = trim((string)($orderInfo['status'] ?? ''));
        if ($raw !== '') {
            $stn = strtolower($raw);
            $syn = ['esperando aprobacion','esperando-aprobacion','esperando_aprobación','esperando aprobación','esperandoaprobacion','esperando_aprovacion','waiting_authorization','waiting approval','pending_approval'];
            if (in_array($stn, $syn, true)) $statusLabelUI = 'Esperando Aprobación'; else $statusLabelUI = $raw;
        }
        $order_prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix($tid) : 'ORD';
        $order_num = (isset($orderInfo['order_number']) && (int)$orderInfo['order_number'] > 0) ? (int)$orderInfo['order_number'] : (int)$order_id;
        $order_code_display = $order_prefix . '-' . str_pad($order_num, 6, '0', STR_PAD_LEFT);
        // Header de orden (chip y estado)
        ob_start();
        ?>
        <div class="mt-0 text-start">
            <div class="header-order d-flex justify-content-between align-items-center mb-3">
                <div class="header-controls">
                    <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2"><?php echo htmlspecialchars($order_code_display); ?></span>
                </div>
                <span class="badge badge-state">Estado: <?php echo htmlspecialchars($statusLabelUI); ?></span>
            </div>
        </div>
        <?php
        $order_header_html = ob_get_clean();

        // Cuerpo: detalles, botones y historial
        ob_start();
        ?>
        <div class="mt-3 text-start">
                    <div class="order-details">
                        <div class="order-detail-row">
                            <div class="detail-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                            <div class="detail-content">
                                <strong>Equipo</strong>
                                <span><?php echo htmlspecialchars(trim(($orderInfo['device_brand'] ?? '') . ' ' . ($orderInfo['device_model'] ?? ''))); ?></span>
                            </div>
                        </div>
                        <div class="order-detail-row">
                            <div class="detail-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                            <div class="detail-content">
                                <strong>Monto</strong>
                                <span><?php echo CompanySettings::formatCurrency($orderInfo['estimated_cost'] ?? 0); ?></span>
                            </div>
                        </div>
                        <div class="order-detail-row">
                            <div class="detail-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <div class="detail-content">
                                <strong>Problema</strong>
                                <span><?php echo htmlspecialchars($orderInfo['reported_issue'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 my-3">
                        <div class="col-12 col-sm-6">
                            <a href="verify.php?t=<?php echo urlencode($slug); ?>" class="btn btn-primary-custom btn-custom w-100 rounded-pill d-inline-flex align-items-center justify-content-center px-3">
                                <i class="fa-solid fa-arrow-left me-2"></i><span>Volver al Inicio</span>
                            </a>
                        </div>
                        <div class="col-12 col-sm-6">
                            <a class="btn btn-outline-secondary w-100 rounded-pill d-inline-flex align-items-center justify-content-center px-3 shadow-sm" href="receipt.php?t=<?php echo urlencode($slug); ?>&order_id=<?php echo (int)$order_id; ?>">
                                <i class="fa-solid fa-file-pdf me-2"></i><span>Descargar Comprobante</span>
                            </a>
                        </div>
                    </div>
                    <hr class="my-4">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="fw-bold d-flex align-items-center">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Historial
                            <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary-subtle ms-2"><?php echo (int)count($history); ?></span>
                        </div>
                        <button id="historyToggle" type="button" class="btn btn-sm btn-outline-secondary rounded-pill d-inline-flex align-items-center">
                            <i class="fa-solid fa-chevron-down me-1"></i><span>Mostrar</span>
                        </button>
                    </div>
                    <?php if (empty($history)): ?>
                        <div id="historyBlock" class="bg-light rounded-3 p-3 text-muted small d-none">Aún no hay movimientos registrados para esta orden.</div>
                    <?php else: ?>
                        <div id="historyBlock" class="timeline-card p-3 d-none">
                            <?php foreach ($history as $h): 
                                $stRaw = strtolower(trim($h['status'] ?? ''));
                                $stKey = preg_replace('/\s+/', '_', $stRaw);
                                $style = $statusStyles[$stKey] ?? ['icon' => 'fa-circle-dot', 'color' => '#0d6efd'];
                                $clr = $style['color'];
                                $icon = $style['icon'];
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot" style="background: <?php echo htmlspecialchars($clr); ?>"></div>
                                    <div class="timeline-content">
                                        <div class="fw-semibold d-flex align-items-center">
                                            <i class="fa-solid <?php echo htmlspecialchars($icon); ?> me-2" style="color: <?php echo htmlspecialchars($clr); ?>"></i>
                                            <?php echo htmlspecialchars($h['status']); ?>
                                        </div>
                                        <?php if (!empty($h['notes'])): ?><div class="text-muted small"><?php echo htmlspecialchars($h['notes']); ?></div><?php endif; ?>
                                        <div class="timeline-time"><?php echo htmlspecialchars(formatCompanyDate($h['created_at'] ?? '', true)); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <script>
                        (function(){
                            var btn=document.getElementById('historyToggle');
                            var blk=document.getElementById('historyBlock');
                            if(!btn||!blk) return;
                            var label=btn.querySelector('span');
                            var icon=btn.querySelector('i');
                            var key='portal_history_open_<?php echo (int)$order_id; ?>';
                            var open=(localStorage.getItem(key)==='1');
                            function update(){
                                blk.classList.toggle('d-none',!open);
                                icon.className='fa-solid '+(open?'fa-chevron-up':'fa-chevron-down')+' me-1';
                                if(label) label.textContent=open?'Ocultar':'Mostrar';
                            }
                            btn.addEventListener('click',function(){ open=!open; localStorage.setItem(key,open?'1':'0'); update(); });
                            update();
                        })();
                    </script>
        </div>
        <?php
        $order_body_html = ob_get_clean();
    }

    echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <link rel="icon" type="image/png" href="' . getSystemBaseUrl() . 'assets/img/' . htmlspecialchars(($company['company_logo'] ?? 'logo.png')) . '">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #0d6efd; --primary-light: #e0f2fe; --text-main: #2b3445; --text-muted: #7d879c; --bg-color: #f3f6f9; }
        body { font-family: "Poppins", sans-serif; background-color: var(--bg-color); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }
        .navbar-custom { background-color: rgba(255,255,255,0.70) !important; backdrop-filter: blur(8px); border-bottom: 1px solid rgba(0,0,0,0.04); padding: 15px 0; }
        .card-custom { border: none; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); box-shadow: 0 15px 35px rgba(0,0,0,0.04), 0 5px 15px rgba(0,0,0,0.02); border-radius: 24px; overflow: hidden; padding: 40px; }
        .btn-custom { padding: 12px 24px; border-radius: 50px; font-weight: 600; transition: all 0.3s; }
        .btn-primary-custom { background-color: #0d6efd; color: white; border: none; box-shadow: 0 6px 16px rgba(13,110,253,0.2) !important; }
        .btn-primary-custom:hover { background-color: #0b5ed7; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(13,110,253,0.25) !important; color: white; }
        .btn-outline-secondary { border-radius: 12px; font-weight: 500; }
        .badge-state { background: var(--primary-light); color: var(--primary-color); border: 1px solid rgba(13,110,253,0.1); padding: 8px 16px; border-radius: 30px; font-weight: 600; font-size: 0.85rem; letter-spacing: 0.3px; }
        .header-order { background: transparent; border: 0; border-radius: 0; padding: 0; }
        .header-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .order-detail-row { display: flex; align-items: flex-start; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px dashed #eef2f6; }
        .order-detail-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-icon { width: 40px; height: 40px; border-radius: 12px; background: var(--primary-light); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-right: 16px; flex-shrink: 0; }
        .detail-content strong { display: block; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .detail-content span { font-weight: 500; font-size: 1.05rem; color: var(--text-main); }
        .timeline-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fafc; }
        .timeline-item { display: flex; gap: 12px; padding: 10px 0; }
        .timeline-dot { width: 10px; height: 10px; border-radius: 50%; background: #0d6efd; margin-top: 6px; flex-shrink: 0; }
        .timeline-content { flex: 1; }
        .timeline-time { color: #64748b; font-size: 0.82rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-custom sticky-top">
        <div class="container d-flex justify-content-center">
            <a class="navbar-brand d-flex align-items-center" href="' . htmlspecialchars($slug) . '">
                ' . $logo_html . '<span class="fw-bold fs-5 text-dark">' . $cname . '</span>
            </a>
        </div>
    </nav>
    <div class="container py-5 flex-grow-1 d-flex flex-column align-items-center">
        <div class="card card-custom w-100 text-center" style="max-width: 480px;">
            ' . $order_header_html . '
            <div class="mb-2"><i class="fa-solid fa-circle-check text-success" style="font-size: 2.4rem;"></i></div>
            <h3 class="fw-bold mb-3">' . htmlspecialchars($title) . '</h3>
            <p class="text-muted mb-4 fs-5">' . $message . '</p>
            ' . $order_body_html . '
        </div>
    </div>
</body>
</html>';
    exit;
}

// Si llega por GET sin ningún dato de verificación ni identificación, redirigir a la pantalla principal
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $hasAnyInput = ($order_id > 0) || ($order_no !== '') || ($verification_code !== '') || ($id_full !== '');
    if (!$hasAnyInput) {
        $redir = 'verify.php';
        if ($slug !== '') {
            $redir .= '?t=' . urlencode($slug);
            if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                $redir .= '&debug=1';
            }
        }
        header('Location: ' . $redir, true, 302);
        exit;
    }
}

if ($verification_code === '' && $id_full === '') {
    http_response_code(400);
    $msgInv = 'Falta el dato de verificación para continuar.';
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $msgInv .= '<div class="small text-muted mt-3">Diagnóstico: method=' . $safe($_SERVER['REQUEST_METHOD'] ?? '') .
                   ' | order_id=' . $safe($order_id) .
                   ' | order_no=' . $safe($order_no) .
                   ' | code(len)=' . strlen($verification_code) .
                   ' | id_full(len)=' . strlen($id_full) . '</div>';
    }
    renderPortalMessage('Parámetros inválidos', $msgInv, $slug, $company, 'error');
}

// Si llega solo identificación sin order_id ni número de orden, pedir número de orden
if ($order_id === 0 && $order_no === '' && $verification_code === '' && $id_full !== '') {
    try {
        // Intentar localizar por cédula si hay una única orden para ese cliente
        if ($tenant_id) {
            $q = $pdo->prepare("
                SELECT w.id 
                FROM work_orders w 
                WHERE " . ((!$perDatabase && $hasTenantWorkOrders) ? "w.tenant_id = ? AND " : "") . " w.client_id IN (
                    SELECT c.id 
                    FROM clients c 
                    WHERE " . ((!$perDatabase && $hasTenantClients) ? "c.tenant_id = ? AND " : "") . "
                      AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.tax_id,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                      OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                      OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                  )
                ORDER BY w.id DESC
                LIMIT 2
            ");
            $params = [$id_full, $id_full, $id_full];
            if (!$perDatabase && $hasTenantClients) { array_unshift($params, $portalTenantId); }
            if (!$perDatabase && $hasTenantWorkOrders) { array_unshift($params, $portalTenantId); }
            $q->execute($params);
        } else {
            $q = $pdo->prepare("
                SELECT w.id, w.tenant_id 
                FROM work_orders w 
                WHERE w.client_id IN (
                    SELECT c.id 
                    FROM clients c 
                    WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.tax_id,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                       OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.id_number,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                       OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone,'-',''),' ','') , '.', ''), '/', ''), '_', '') = ?
                )
                ORDER BY w.id DESC
                LIMIT 2
            ");
            $q->execute([$id_full, $id_full, $id_full]);
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            $order_id = (int)($rows[0]['id'] ?? 0);
            if (!$tenant_id && isset($rows[0]['tenant_id'])) {
                $tenant_id = (int)$rows[0]['tenant_id'];
            }
        } elseif (count($rows) === 0) {
            http_response_code(404);
            renderPortalMessage('Orden no encontrada', 'No hay órdenes asociadas a esa cédula. Verificá el número e intentá nuevamente.', $slug, $company, 'error');
        } else {
            http_response_code(400);
            renderPortalMessage('Varias órdenes detectadas', 'Encontramos varias órdenes con esa cédula. Ingresá además el número de orden o usá el código del comprobante.', $slug, $company, 'error');
        }
    }
    catch (Throwable $e) {
        http_response_code(400);
        renderPortalMessage('Error de verificación', 'No fue posible validar la cédula. Intentá nuevamente o usá el código del comprobante.', $slug, $company, 'error');
    }
}

// Si falta order_id pero hay código, buscar por código (sin filtrar por tenant)
if (!$order_id && $verification_code !== '') {
    try {
        $find = $pdo->prepare("SELECT id" . ((!$perDatabase && $hasTenantWorkOrders) ? ", tenant_id" : "") . " FROM work_orders WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(verification_code,'-',''),' ','') , '.', ''), '/', ''), '_', '')) = ? LIMIT 1");
        $find->execute([$verification_code]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $order_id = (int)$row['id'];
            if (!$perDatabase && !$tenant_id) {
                $tenant_id = (int)$row['tenant_id'];
            }
        }
        else {
            http_response_code(404);
            renderPortalMessage('Orden no encontrada', 'No se ha encontrado ninguna orden con ese código.', $slug, $company, 'error');
        }
    }
    catch (Throwable $e) {
        http_response_code(400);
        renderPortalMessage('Error de verificación', 'Ocurrió un error al verificar la orden.', $slug, $company, 'error');
    }
}
// Si el slug no resolvió tenant, intentar deducir por la orden (sin filtrar por tenant)
if (!$perDatabase && !$tenant_id) {
    try {
        $probe = $pdo->prepare("SELECT tenant_id, verification_code FROM work_orders WHERE id = ? LIMIT 1");
        $probe->execute([$order_id]);
        $row = $probe->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            renderPortalMessage('Orden no encontrada', 'No se ha encontrado ninguna orden con ese ID.', $slug, $company, 'error');
        }
        $stored_code_probe = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['verification_code'] ?? '')));
        if ($verification_code !== '' && $id_full === '') {
            if ($stored_code_probe === '' || $stored_code_probe !== $verification_code) {
                http_response_code(403);
                $msgCode = 'El código de verificación no coincide para esta orden.';
                if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                    $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
                    $msgCode .= '<div class="small text-muted mt-3">Diagnóstico: enviado=' . $safe($verification_code) . ' | esperado=' . $safe($stored_code_probe) . '</div>';
                }
                renderPortalMessage('Código inválido', $msgCode, $slug, $company, 'error');
            }
        }
        $tenant_id = (int)$row['tenant_id'];
        try {
            $getSlug = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
            $getSlug->execute([$tenant_id]);
            $slugDb = trim((string)($getSlug->fetchColumn() ?: ''));
            if ($slugDb !== '') {
                $slug = $slugDb;
            }
        }
        catch (Throwable $e) {
        }
    }
    catch (Throwable $e) {
        http_response_code(400);
        renderPortalMessage('Error de verificación', 'Error al procesar la solicitud.', $slug, $company, 'error');
    }
}

// Validar verificación
$hasApprovedAmount = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'approved_quote_amount'");
    $hasApprovedAmount = ($c && $c->rowCount() > 0);
} catch (Throwable $__) {}
$cols = "id, estimated_cost, approval_status, status";
if ($hasApprovedAmount) { $cols .= ", approved_quote_amount"; }
$stmt = $pdo->prepare("SELECT $cols FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
$stmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenant_id] : [$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    try {
        // Solo reintentar por el mismo ID (sin cambiar de orden)
        $probe = $pdo->prepare("SELECT id" . ((!$perDatabase && $hasTenantWorkOrders) ? ", tenant_id" : "") . ", verification_code, estimated_cost, approval_status, status" . ($hasApprovedAmount ? ", approved_quote_amount" : "") . " FROM work_orders WHERE id = ? LIMIT 1");
        $probe->execute([$order_id]);
        $row = $probe->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Fallback: intentar por order_number si vino en el formulario
            $digits = '';
            if ($order_no !== '' && preg_match_all('/\d+/', (string)$order_no, $m) && !empty($m[0])) {
                $digits = implode('', $m[0]);
            }
            if ($digits !== '') {
                $probe2 = $pdo->prepare("SELECT id" . ((!$perDatabase && $hasTenantWorkOrders) ? ", tenant_id" : "") . ", verification_code, estimated_cost, approval_status, status" . ($hasApprovedAmount ? ", approved_quote_amount" : "") . " FROM work_orders WHERE order_number = ? LIMIT 1");
                $probe2->execute([(int)$digits]);
                $row = $probe2->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $order_id = (int)$row['id'];
                }
            }
            if (!$row) {
                http_response_code(404);
                $msgNF = 'No se encontró la orden seleccionada.';
                if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                    $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
                    $msgNF .= '<div class="small text-muted mt-3">Diagnóstico: order_id=' . $safe($order_id) . ' | order_no=' . $safe($order_no) . ' | tenant=' . $safe($tenant_id) . ' | method=' . $safe($_SERVER['REQUEST_METHOD'] ?? '') . '</div>';
                }
                renderPortalMessage('Orden no encontrada', $msgNF, $slug, $company, 'error');
            }
        }
        $stored_code_probe = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['verification_code'] ?? '')));
        if ($verification_code !== '' && $id_full === '') {
            if ($stored_code_probe === '' || $stored_code_probe !== $verification_code) {
                http_response_code(403);
                $msgCode2 = 'El código no coincide para esta orden.';
                if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                    $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
                    $msgCode2 .= '<div class="small text-muted mt-3">Diagnóstico: enviado=' . $safe($verification_code) . ' | esperado=' . $safe($stored_code_probe) . '</div>';
                }
                renderPortalMessage('Código inválido', $msgCode2, $slug, $company, 'error');
            }
        }
        if (!$perDatabase && isset($row['tenant_id'])) {
            $tenant_id = (int)$row['tenant_id'];
        }
        $order = ['id' => (int)$order_id, 'estimated_cost' => $row['estimated_cost'] ?? 0, 'approval_status' => $row['approval_status'] ?? null, 'status' => $row['status'] ?? null];
        if (isset($row['approved_quote_amount'])) { $order['approved_quote_amount'] = $row['approved_quote_amount']; }
    }
    catch (Throwable $e) {
        http_response_code(404);
        $msgErr = 'Ocurrió un error buscando esta orden.';
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
            $msgErr .= '<div class="small text-muted mt-3">Diagnóstico: order_id=' . $safe($order_id) . ' | tenant=' . $safe($tenant_id) . '</div>';
        }
        renderPortalMessage('Orden no encontrada', $msgErr, $slug, $company, 'error');
    }
}

$okIdentity = false;
if ($verification_code !== '') {
    $stmtV = $pdo->prepare("SELECT verification_code FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $stmtV->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenant_id] : [$order_id]);
    $stored_code = strtoupper((string)($stmtV->fetchColumn() ?: ''));
    $stored_code = preg_replace('/[^A-Z0-9]/', '', $stored_code);
    if ($stored_code !== '' && $stored_code === $verification_code) {
        $okIdentity = true;
    }
}
if (!$okIdentity && $id_full !== '') {
    $q = $pdo->prepare("SELECT c.tax_id, c.id_number, c.phone FROM clients c INNER JOIN work_orders w ON w.client_id = c.id WHERE w.id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND w.tenant_id = ?" : "") . " LIMIT 1");
    $q->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenant_id] : [$order_id]);
    $cli = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $cand1 = preg_replace('/\D/', '', (string)($cli['tax_id'] ?? ''));
    $cand2 = preg_replace('/\D/', '', (string)($cli['id_number'] ?? ''));
    $cand3 = preg_replace('/\D/', '', (string)($cli['phone'] ?? ''));
    $nf = ltrim($id_full, '0');
    $c1 = ltrim($cand1, '0');
    $c2 = ltrim($cand2, '0');
    $c3 = ltrim($cand3, '0');
    if ($id_full !== '' && ($id_full === $cand1 || $id_full === $cand2 || $id_full === $cand3 || $nf === $c1 || $nf === $c2 || $nf === $c3)) {
        $okIdentity = true;
    }
}
if (!$okIdentity) {
    http_response_code(403);
    $msg = 'Los datos de verificación no coinciden. Intenta nuevamente.';
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $safe = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $msg .= '<div class="small text-muted mt-3">Diagnóstico: orden=' . $safe($order_id) . ' | tenant=' . $safe($tenant_id) . ' | ingresado_id=' . $safe($id_full) . ' | tax_id=' . $safe($cand1 ?? '') . ' | id_number=' . $safe($cand2 ?? '') . '</div>';
    }
    renderPortalMessage('Verificación fallida', $msg, $slug, $company, 'error', $order_id);
}
// Acceso temporal en sesión para ver historial y aprobar en esta sesión
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['portal_access']) || !is_array($_SESSION['portal_access'])) {
    $_SESSION['portal_access'] = [];
}
if (!isset($_SESSION['portal_access'][$portalTenantId]) || !is_array($_SESSION['portal_access'][$portalTenantId])) {
    $_SESSION['portal_access'][$portalTenantId] = [];
}
$_SESSION['portal_access'][$portalTenantId][$order_id] = ['scope' => 'read_approve', 'exp' => time() + 1800];

$apStatus = strtolower((string)($order['approval_status'] ?? 'none'));
if (in_array($apStatus, ['approved', 'rejected'], true)) {
    $statusDisplay = $apStatus === 'approved' ? 'Aprobado' : 'Rechazado';
    renderPortalMessage('Presupuesto ' . $statusDisplay, 'Ya existe una decisión registrada para esta orden.', $slug, $company, 'info', $order_id);
}

$logo_html_ui = '';
if (!empty($company['company_logo'])) {
    $logo_html_ui = '<img src="' . getSystemBaseUrl() . 'assets/img/' . htmlspecialchars($company['company_logo']) . '" alt="Logo" style="max-height: 40px; margin-right: 12px; border-radius: 8px;" loading="lazy" decoding="async" onerror="this.onerror=null; this.src=\'' . getSystemBaseUrl() . 'assets/img/logo.png\';">';
}
$cname_ui = htmlspecialchars($company['company_name'] ?? '');

// Cargar resumen e historial para una vista más completa
$orderInfo = ['device_brand' => '', 'device_model' => '', 'reported_issue' => '', 'status' => ''];
try {
    $stI = $pdo->prepare("SELECT device_brand, device_model, reported_issue, status FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $stI->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenant_id] : [$order_id]);
    $orderInfo = $stI->fetch(PDO::FETCH_ASSOC) ?: $orderInfo;
} catch (Throwable $e) {}
$history = [];
try {
$hasTenantHist = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM order_status_history LIKE 'tenant_id'");
    $hasTenantHist = ($c && $c->rowCount() > 0);
} catch (Throwable $__) {}
if ($hasTenantHist && !$perDatabase) {
    $stH = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? AND tenant_id = ? ORDER BY created_at ASC");
    $stH->execute([$order_id, $tenant_id]);
} else {
    $stH = $pdo->prepare("SELECT status, notes, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
    $stH->execute([$order_id]);
}
    $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$statusStyles = [
    'pending' => ['icon' => 'fa-hourglass-half', 'color' => '#64748b'],
    'received' => ['icon' => 'fa-box', 'color' => '#0ea5e9'],
    'diagnosing' => ['icon' => 'fa-magnifying-glass', 'color' => '#8b5cf6'],
    'approved' => ['icon' => 'fa-circle-check', 'color' => '#10b981'],
    'waiting_parts' => ['icon' => 'fa-pause', 'color' => '#f59e0b'],
    'repairing' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#2563eb'],
    'testing' => ['icon' => 'fa-flask', 'color' => '#06b6d4'],
    'completed' => ['icon' => 'fa-circle-check', 'color' => '#22c55e'],
    'ready' => ['icon' => 'fa-flag-checkered', 'color' => '#14b8a6'],
    'delivered' => ['icon' => 'fa-truck', 'color' => '#0ea5e9'],
    'cancelled' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'color' => '#ef4444'],
    'esperando_aprobacion' => ['icon' => 'fa-pen-to-square', 'color' => '#f59e0b'],
    'esperando_repuestos' => ['icon' => 'fa-pause', 'color' => '#6f42c1'],
    'reparando' => ['icon' => 'fa-screwdriver-wrench', 'color' => '#007bff'],
    'testeando' => ['icon' => 'fa-flask', 'color' => '#17a2b8'],
    'completado' => ['icon' => 'fa-circle-check', 'color' => '#28a745'],
    'entregado' => ['icon' => 'fa-truck', 'color' => '#6c757d'],
    'cancelado' => ['icon' => 'fa-circle-xmark', 'color' => '#dc3545'],
    'asignado' => ['icon' => 'fa-box', 'color' => '#6cc4ea'],
    'pendiente' => ['icon' => 'fa-hourglass-half', 'color' => '#ffc107']
];

// Normalización de sinónimos para estados
function normalizeStatusForPortal($status) {
    $status = strtolower(trim((string)$status));
    $syn = ['esperando aprobacion','esperando-aprobacion','esperando_aprobación','esperando aprobación','esperandoaprobacion','esperando_aprovacion','waiting_authorization','waiting approval','pending_approval'];
    if (in_array($status, $syn, true)) {
        return 'esperando_aprobacion';
    }
    return $status;
}

// Etiqueta de estado para resumen
$statusLabelUI = 'Pendiente';
$raw = trim((string)($orderInfo['status'] ?? ''));
if ($raw !== '') {
    $normalizedStatus = normalizeStatusForPortal($raw);
    // Si es esperando_aprobacion, mostrar nombre amigable
    if ($normalizedStatus === 'esperando_aprobacion') {
        $statusLabelUI = 'Esperando Aprobación';
    } else {
        $statusLabelUI = $raw;
    }
}

$allowSignature = false;
$currentStatusRaw = strtolower(trim((string)($orderInfo['status'] ?? '')));
$currentStatus = normalizeStatusForPortal($currentStatusRaw);
$apStatus = strtolower((string)($order['approval_status'] ?? 'none'));
$approvedAmount = isset($order['approved_quote_amount']) ? $order['approved_quote_amount'] : null;
$est = (float)($order['estimated_cost'] ?? 0);
if (in_array($currentStatus, ['esperando_aprobacion','pending_approval'], true) || $apStatus === 'pending') {
    if ($approvedAmount === null || (is_numeric($approvedAmount) && (float)$approvedAmount != $est)) {
        $allowSignature = true;
    }
}

// Render detalle con firma condicional
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company['company_name'] ?? 'Portal'); ?> | Aprobación de Presupuesto</title>
    <link rel="icon" type="image/png" href="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo htmlspecialchars($company['company_logo'] ?? 'logo.png'); ?>">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: "Poppins", sans-serif; background-color: #f6f8fa; color: #1e293b; min-height: 100vh; display: flex; flex-direction: column; }
        .navbar-custom { background-color: rgba(255,255,255,0.95); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(0,0,0,0.05); padding: 15px 0; }
        
        .card-main { 
            border: none; 
            background: #ffffff;
            box-shadow: 0 15px 35px rgba(0,0,0,0.04), 0 5px 15px rgba(0,0,0,0.02); 
            border-radius: 20px; 
            overflow: hidden;
            padding: 35px;
        }
        
        .sig-pad { 
            border: 2px dashed #cbd5e1; 
            border-radius: 12px; 
            background: #f8fafc; 
            height: 200px; 
            width: 100%; 
            touch-action: none; 
            transition: border-color 0.2s;
        }
        .sig-pad:focus, .sig-pad:active { border-color: #0d6efd; background: #fff; outline: none; }
        
        .btn-lg { border-radius: 50px; font-weight: 600; font-size: 1rem; padding: 14px 24px; transition: all 0.3s; }
        .btn-success-custom { background-color: #10b981; color: white; border: none; box-shadow: 0 6px 16px rgba(16,185,129,0.2); }
        .btn-success-custom:hover { background-color: #059669; color: white; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,0.3); }
        .btn-outline-danger { border-color: #f43f5e; color: #f43f5e; border-width: 2px; }
        .btn-outline-danger:hover { background-color: #f43f5e; color: white; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(244,63,94,0.2); }
        
        .form-control { border-radius: 12px; padding: 12px 16px; border: 2px solid #e2e8f0; background-color: #f8fafc; font-size: 0.95rem; }
        .form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,0.1); background-color: #fff; }
        .form-label { font-weight: 600; color: #475569; margin-bottom: 0.5rem; font-size: 0.95rem; }
        
        .alert-total { background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 15px 20px; display: inline-block; color: #166534; }

        .timeline-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fafc; }
        .timeline-item { display: flex; gap: 12px; padding: 10px 0; }
        .timeline-dot { width: 10px; height: 10px; border-radius: 50%; background: #0d6efd; margin-top: 6px; flex-shrink: 0; }
        .timeline-content { flex: 1; }
        .timeline-time { color: #64748b; font-size: 0.82rem; }

        .sig-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.85); display: none; align-items: center; justify-content: center; z-index: 1050; }
        .sig-overlay.active { display: flex; }
        .sig-overlay-inner { width: 100vw; height: 100vh; display: flex; flex-direction: column; }
        .sig-overlay-bar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: rgba(255,255,255,0.08); color: #fff; }
        .sig-overlay-actions { display: flex; gap: 10px; }
        .sig-overlay-canvas { flex: 1; display: flex; align-items: center; justify-content: center; padding: 8px; }
        #sigFull { background: #fff; border-radius: 8px; touch-action: none; }
    </style>
</head>
<body>
    <nav class="navbar navbar-custom sticky-top mb-4">
        <div class="container d-flex justify-content-center">
            <a class="navbar-brand d-flex align-items-center" href="<?php echo htmlspecialchars($slug); ?>">
                <?php echo $logo_html_ui; ?><span class="fw-bold fs-5 text-dark"><?php echo $cname_ui; ?></span>
            </a>
        </div>
    </nav>
    <div class="container flex-grow-1 pb-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card card-main">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex bg-primary bg-opacity-10 text-primary rounded-circle align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="fa-solid fa-file-signature fs-2"></i>
                        </div>
                        <h3 class="fw-bold mb-2">Presupuesto pendiente</h3>
                        <p class="text-muted">Revisa y autoriza el presupuesto para tu orden <strong>#<?php echo (int)$order_id; ?></strong>.</p>
                        
                        <div class="alert-total mt-3">
                            <span class="d-block small text-success fw-semibold opacity-75 mb-1 text-uppercase tracking-wider" style="letter-spacing: 1px;">Costo Estimado</span>
                            <h2 class="fw-bold mb-0 m-0"><?php echo CompanySettings::formatCurrency($order['estimated_cost'] ?? 0); ?></h2>
                        </div>
                    </div>

                    <div class="mb-4 text-start">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fa-solid fa-mobile-screen-button text-muted me-2"></i>
                            <strong class="me-2">Equipo:</strong>
                            <span><?php echo htmlspecialchars(trim(($orderInfo['device_brand'] ?? '') . ' ' . ($orderInfo['device_model'] ?? ''))); ?></span>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <i class="fa-solid fa-triangle-exclamation text-muted me-2"></i>
                            <strong class="me-2">Problema:</strong>
                            <span><?php echo htmlspecialchars($orderInfo['reported_issue'] ?? ''); ?></span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-circle-info text-muted me-2"></i>
                            <strong class="me-2">Estado actual:</strong>
                            <span><?php echo htmlspecialchars($statusLabelUI); ?></span>
                        </div>
                    </div>
                    
                    <div class="mb-4 text-start">
                        <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Historial</h6>
                        <?php if (empty($history)): ?>
                            <div class="timeline-card p-3 text-muted small">Aún no hay movimientos registrados para esta orden.</div>
                        <?php else: ?>
                            <div class="timeline-card p-3">
                                <?php foreach ($history as $h): ?>
                                    <?php 
                                        $stRaw = strtolower(trim($h['status'] ?? ''));
                                        $st = normalizeStatusForPortal($stRaw);
                                        $st = preg_replace('/\s+/', '_', $st);
                                        $style = $statusStyles[$st] ?? ['icon' => 'fa-circle-dot', 'color' => '#0d6efd'];
                                        $clr = $style['color'];
                                        $icon = $style['icon'];
                                    ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot" style="background: <?php echo htmlspecialchars($clr); ?>"></div>
                                        <div class="timeline-content w-100">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <i class="fa-solid <?php echo htmlspecialchars($icon); ?> me-2" style="color: <?php echo htmlspecialchars($clr); ?>"></i>
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($h['status']); ?></span>
                                                    <?php if (!empty($h['notes'])): ?>
                                                        <span class="text-muted small">— <?php echo htmlspecialchars($h['notes']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="timeline-time"><?php echo htmlspecialchars(formatCompanyDate($h['created_at'] ?? '', true)); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($allowSignature): ?>
                    <form method="POST" action="submit_approval.php?t=<?php echo urlencode($slug); ?>">
                        <input type="hidden" name="order_id" value="<?php echo (int)$order_id; ?>">
                        <input type="hidden" name="verification_code" value="<?php echo htmlspecialchars($verification_code); ?>">
                        <input type="hidden" name="signature_data" id="signature_data">
                        
                        <div class="mb-4">
                            <label class="form-label"><i class="fa-regular fa-comment-dots me-2 text-muted"></i>Comentario (Opcional)</label>
                            <textarea name="comment" class="form-control" rows="2" maxlength="500" placeholder="Escribe alguna nota adicional para nuestros técnicos"></textarea>
                        </div>
                        
                        <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label m-0 d-flex align-items-center"><i class="fa-solid fa-pen-nib me-2 text-muted"></i>Tu Firma</label>
                            <div class="d-flex align-items-center gap-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="openFullSigBtn"><i class="fa-solid fa-up-right-and-down-left-from-center me-1"></i>Pantalla completa</button>
                                <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none p-0 fw-medium" id="clearBtn"><i class="fa-solid fa-eraser me-1"></i>Limpiar</button>
                            </div>
                            </div>
                            <canvas id="sig" class="sig-pad w-100"></canvas>
                            <div class="form-text text-center mt-2 opacity-75">Dibuja tu firma en el recuadro superior para confirmar tu decisión.</div>
                        </div>
                        
                        <div class="d-flex flex-column flex-sm-row gap-3 mt-4 pt-3 border-top">
                            <button class="btn btn-success-custom btn-lg flex-fill m-0 w-100" type="submit" name="decision" value="approve">
                                <i class="fa-solid fa-check me-2"></i>Aprobar Reparación
                            </button>
                            <button class="btn btn-outline-danger btn-lg flex-fill m-0 w-100" type="submit" name="decision" value="reject">
                                <i class="fa-solid fa-xmark me-2"></i>Rechazar
                            </button>
                            <a class="btn btn-outline-secondary btn-lg flex-fill m-0 w-100" href="verify.php?t=<?php echo urlencode($slug); ?>&order_no=<?php echo (int)$order_id; ?>">
                                <i class="fa-solid fa-clock me-2"></i>Decidir más tarde
                            </a>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fa-solid fa-circle-info me-2"></i>El presupuesto no está pendiente de aprobación o ya fue decidido. Si hubo cambios en el monto, el taller reenviará la solicitud.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="sig-overlay" id="sigOverlay">
        <div class="sig-overlay-inner">
            <div class="sig-overlay-bar">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-signature"></i>
                    <span class="fw-semibold">Firma en pantalla completa</span>
                </div>
                <div class="sig-overlay-actions">
                    <button class="btn btn-light btn-sm rounded-pill" id="rotateFullBtn"><i class="fa-solid fa-rotate me-1"></i>Rotar</button>
                    <button class="btn btn-outline-light btn-sm rounded-pill" id="clearFullBtn"><i class="fa-solid fa-eraser me-1"></i>Limpiar</button>
                    <button class="btn btn-primary btn-sm rounded-pill" id="saveFullBtn"><i class="fa-solid fa-check me-1"></i>Usar firma</button>
                    <button class="btn btn-outline-light btn-sm rounded-pill" id="closeFullBtn"><i class="fa-solid fa-xmark me-1"></i>Salir</button>
                </div>
            </div>
            <div class="sig-overlay-canvas">
                <canvas id="sigFull"></canvas>
            </div>
        </div>
    </div>

    <script>
    const canvas = document.getElementById('sig');
    const ctx = canvas.getContext('2d');
    let drawing = false, lastX=0, lastY=0;
    
    function resize() { 
        canvas.width = canvas.parentElement.clientWidth; 
        canvas.height = 200; 
    }
    window.addEventListener('resize', resize); 
    resize();
    
    function start(e){ 
        e.preventDefault();
        drawing=true; 
        const r=canvas.getBoundingClientRect(); 
        lastX=(e.touches?e.touches[0].clientX:e.clientX)-r.left; 
        lastY=(e.touches?e.touches[0].clientY:e.clientY)-r.top; 
    }
    
    function move(e){ 
        if(!drawing) return; 
        e.preventDefault();
        const r=canvas.getBoundingClientRect(); 
        const x=(e.touches?e.touches[0].clientX:e.clientX)-r.left; 
        const y=(e.touches?e.touches[0].clientY:e.clientY)-r.top; 
        ctx.strokeStyle='#0f172a'; 
        ctx.lineWidth=3; 
        ctx.lineCap='round'; 
        ctx.lineJoin='round';
        ctx.beginPath(); 
        ctx.moveTo(lastX,lastY); 
        ctx.lineTo(x,y); 
        ctx.stroke(); 
        lastX=x; 
        lastY=y; 
    }
    
    function end(e){ 
        if(e) e.preventDefault();
        drawing=false; 
    }
    
    canvas.addEventListener('mousedown', start, {passive:false}); 
    canvas.addEventListener('mousemove', move, {passive:false}); 
    window.addEventListener('mouseup', end, {passive:false}); 
    
    canvas.addEventListener('touchstart', start, {passive:false}); 
    canvas.addEventListener('touchmove', move, {passive:false}); 
    window.addEventListener('touchend', end, {passive:false});
    
    document.getElementById('clearBtn').onclick = ()=>{ ctx.clearRect(0,0,canvas.width,canvas.height); };

    const overlay = document.getElementById('sigOverlay');
    const full = document.getElementById('sigFull');
    const fullCtx = full.getContext('2d');
    let fullDrawing=false, fx=0, fy=0;
    let rotated=false;

    function sizeFull() {
        const w = window.innerWidth;
        const h = window.innerHeight - 56;
        if (rotated) { full.width = h; full.height = w - 56; } else { full.width = w; full.height = h; }
    }
    window.addEventListener('resize', ()=>{ if (overlay.classList.contains('active')) sizeFull(); });

    function fullStart(e){
        e.preventDefault();
        fullDrawing=true;
        const r=full.getBoundingClientRect();
        fx=(e.touches?e.touches[0].clientX:e.clientX)-r.left;
        fy=(e.touches?e.touches[0].clientY:e.clientY)-r.top;
    }
    function fullMove(e){
        if(!fullDrawing) return;
        e.preventDefault();
        const r=full.getBoundingClientRect();
        const x=(e.touches?e.touches[0].clientX:e.clientX)-r.left;
        const y=(e.touches?e.touches[0].clientY:e.clientY)-r.top;
        fullCtx.strokeStyle='#0f172a'; 
        fullCtx.lineWidth=4; 
        fullCtx.lineCap='round'; 
        fullCtx.lineJoin='round';
        fullCtx.beginPath();
        fullCtx.moveTo(fx,fy);
        fullCtx.lineTo(x,y);
        fullCtx.stroke();
        fx=x; fy=y;
    }
    function fullEnd(e){ if(e) e.preventDefault(); fullDrawing=false; }

    document.getElementById('openFullSigBtn').onclick = ()=>{
        overlay.classList.add('active');
        rotated=false;
        sizeFull();
        fullCtx.clearRect(0,0,full.width,full.height);
    };
    document.getElementById('closeFullBtn').onclick = ()=>{ overlay.classList.remove('active'); };
    document.getElementById('rotateFullBtn').onclick = ()=>{
        rotated=!rotated;
        sizeFull();
    };
    document.getElementById('clearFullBtn').onclick = ()=>{ fullCtx.clearRect(0,0,full.width,full.height); };
    document.getElementById('saveFullBtn').onclick = ()=>{
        const img = full.toDataURL('image/png');
        if (img.length < 10000) return;
        const imageObj = new Image();
        imageObj.onload = function(){
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = 200;
            const scale = Math.min(canvas.width / imageObj.width, canvas.height / imageObj.height);
            const nx = (canvas.width - imageObj.width*scale)/2;
            const ny = (canvas.height - imageObj.height*scale)/2;
            ctx.clearRect(0,0,canvas.width,canvas.height);
            ctx.drawImage(imageObj, nx, ny, imageObj.width*scale, imageObj.height*scale);
            overlay.classList.remove('active');
        };
        imageObj.src = img;
    };

    full.addEventListener('mousedown', fullStart, {passive:false});
    full.addEventListener('mousemove', fullMove, {passive:false});
    window.addEventListener('mouseup', fullEnd, {passive:false});
    full.addEventListener('touchstart', fullStart, {passive:false});
    full.addEventListener('touchmove', fullMove, {passive:false});
    window.addEventListener('touchend', fullEnd, {passive:false});
    
    document.querySelector('form').addEventListener('submit', (e)=> {
        const data = canvas.toDataURL('image/png');
        if (data.length < 10000 && e.submitter?.value === 'approve') {
            e.preventDefault();
            alert('Por favor, dibuja tu firma en el recuadro para validar la aprobación.');
            return;
        }
        document.getElementById('signature_data').value = data;
    });
    </script>
</body>
</html>
