<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$slug = isset($_GET['t']) ? trim($_GET['t']) : '';
$tenant_id = $slug ? getTenantIdFromSlug($slug) : null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($tenant_id && ctype_digit($slug)) {
    $pretty = getTenantPreferredSlug((int)$tenant_id);
    if ($pretty) {
        $slug = $pretty;
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

$tenantValue = $perDatabase ? 1 : $portalTenantId;
$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasTenantHist = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_status_history') : false;
$hasTenantNotifications = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'notifications') : false;
$hasTenantUsers = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'users') : false;

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$verification_code = strtoupper(trim($_POST['verification_code'] ?? ''));
$verification_code = preg_replace('/[^A-Z0-9]/', '', $verification_code);
$decision = $_POST['decision'] ?? '';
$comment = trim($_POST['comment'] ?? '');
$signature_data = $_POST['signature_data'] ?? '';

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
    $baseUrl = getSystemBaseUrl();
    $icon = $type === 'error' ? '<i class="fa-solid fa-triangle-exclamation text-danger" style="font-size: 3.5rem;"></i>' :
        ($type === 'success' ? '<i class="fa-solid fa-circle-check text-success" style="font-size: 3.5rem;"></i>' :
        '<i class="fa-solid fa-circle-info text-primary" style="font-size: 3.5rem;"></i>');

    $logo_html = '';
    if (!empty($company['company_logo'])) {
        $logo_html = '<img src="' . $baseUrl . 'assets/img/' . htmlspecialchars($company['company_logo']) . '" alt="Logo" style="max-height: 40px; margin-right: 12px; border-radius: 8px;" loading="lazy" decoding="async" onerror="this.onerror=null; this.src=\'' . $baseUrl . 'assets/img/logo.png\';">';
    }
    $cname = htmlspecialchars($company['company_name'] ?? '');

    echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars(($company['company_name'] ?? 'Portal')) . ' | ' . htmlspecialchars($title) . '</title>
    <link rel="icon" type="image/png" href="' . $baseUrl . 'assets/img/' . htmlspecialchars(($company['company_logo'] ?? 'logo.png')) . '">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: "Inter", sans-serif; background-color: #f6f8fa; color: #1e293b; min-height: 100vh; display: flex; flex-direction: column; }
        .navbar-custom { background-color: rgba(255,255,255,0.95); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(0,0,0,0.05); padding: 15px 0; }
        .card-custom { border: none; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.04); border-radius: 24px; overflow: hidden; padding: 40px; }
        .btn-custom { padding: 12px 24px; border-radius: 50px; font-weight: 600; transition: all 0.3s; }
        .btn-primary-custom { background-color: #0d6efd; color: white; border: none; box-shadow: 0 6px 16px rgba(13,110,253,0.2) !important; }
        .btn-primary-custom:hover { background-color: #0b5ed7; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(13,110,253,0.25) !important; color: white; }
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
    <div class="container py-5 flex-grow-1 d-flex align-items-center justify-content-center">
        <div class="card card-custom w-100 text-center" style="max-width: 480px;">
            <div class="mb-4">' . $icon . '</div>
            <h3 class="fw-bold mb-3">' . htmlspecialchars($title) . '</h3>
            <p class="text-muted mb-4 fs-5">' . $message . '</p>
            <div class="d-flex flex-column gap-3 mt-2">
                <a href="' . htmlspecialchars($baseUrl . 'portal/verify.php?t=' . $slug) . '" class="btn btn-primary-custom btn-custom w-100"><i class="fa-solid fa-arrow-left me-2"></i>Volver al Inicio</a>
                ' . (($order_id && $type !== 'error') ? '<a href="' . htmlspecialchars($baseUrl . 'portal/receipt.php?t=' . $slug . '&order_id=' . (int)$order_id) . '" class="btn btn-outline-secondary btn-custom w-100 bg-light border-0 text-dark"><i class="fa-solid fa-file-pdf me-2"></i>Descargar Comprobante</a>' : '') . '
            </div>
        </div>
    </div>
</body>
</html>';
    exit;
}


if (!$order_id || !$verification_code || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(400);
    renderPortalMessage('Parámetros inválidos', 'La solicitud es incorrecta o está incompleta.', $slug, $company, 'error', $order_id);
}

// Validar código
$stmtV = $pdo->prepare("SELECT verification_code FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
$stmtV->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $portalTenantId] : [$order_id]);
$stored_code = strtoupper((string)($stmtV->fetchColumn() ?: ''));
$stored_code = preg_replace('/[^A-Z0-9]/', '', $stored_code);
if ($stored_code === '' || $stored_code !== $verification_code) {
    http_response_code(403);
    renderPortalMessage('Código incorrecto', 'El código de verificación proporcionado no coincide.', $slug, $company, 'error', $order_id);
}

// Mantener acceso ampliado en sesión tras validar código
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['portal_access']) || !is_array($_SESSION['portal_access'])) {
    $_SESSION['portal_access'] = [];
}
if (!isset($_SESSION['portal_access'][$portalTenantId]) || !is_array($_SESSION['portal_access'][$portalTenantId])) {
    $_SESSION['portal_access'][$portalTenantId] = [];
}
$_SESSION['portal_access'][$portalTenantId][$order_id] = ['scope' => 'read_approve', 'exp' => time() + 1800];

$stmtStat = $pdo->prepare("SELECT approval_status, status FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
$stmtStat->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $portalTenantId] : [$order_id]);
$rowStat = $stmtStat->fetch(PDO::FETCH_ASSOC) ?: ['approval_status' => 'none', 'status' => 'pending'];
$already = strtolower((string)($rowStat['approval_status'] ?? 'none'));
if (in_array($already, ['approved', 'rejected'], true)) {
    $statusDisplay = $already === 'approved' ? 'Aprobado' : 'Rechazado';
    renderPortalMessage('Decisión ya registrada', 'Esta orden ya aparece como: <strong>' . htmlspecialchars($statusDisplay) . '</strong>.', $slug, $company, 'info', $order_id);
}

$sigPathRel = null;
if ($decision === 'approve' && strpos($signature_data, 'data:image/') === 0) {
    $signature_data = preg_replace('/^data:image\/[a-z]+;base64,/', '', $signature_data);
    $signature_data = str_replace(' ', '+', $signature_data);
    $image_decoded = base64_decode($signature_data, true);
    if ($image_decoded !== false && strlen($image_decoded) > 1000) {
        $baseDir = ensureTenantSubdirFs($tenant_id, 'orders/' . $order_id);
        $fileName = 'signature_' . date('Ymd_His') . '.png';
        $filePath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
        file_put_contents($filePath, $image_decoded);
        $sigPathRel = 'uploads/' . $tenant_id . '/orders/' . $order_id . '/' . $fileName;
    }
}

$status = $decision === 'approve' ? 'approved' : 'rejected';
$isApproved = ($status === 'approved') ? 1 : 0;
$sigRelParam = $sigPathRel ?: null;
$commentParam = $comment ?: null;

// Solo actualizar work_orders.status si el ENUM realmente soporta approved/rejected (o sus variantes en ES)
$approvedSlug = null;
$rejectedSlug = null;
try {
    $ctStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'status'");
    $ctStmt->execute();
    $colType = (string)$ctStmt->fetchColumn();
    if ($colType && stripos($colType, 'enum(') === 0) {
        $enumVals = [];
        if (preg_match('/^enum\\((.+)\\)$/i', $colType, $m)) {
            $raw = $m[1];
            $parts = array_map('trim', explode(',', $raw));
            $enumVals = array_map(function($p){ return trim($p, "'\""); }, $parts);
        }
        if (in_array('aprobado', $enumVals, true)) { $approvedSlug = 'aprobado'; }
        elseif (in_array('approved', $enumVals, true)) { $approvedSlug = 'approved'; }
        if (in_array('rechazado', $enumVals, true)) { $rejectedSlug = 'rechazado'; }
        elseif (in_array('rejected', $enumVals, true)) { $rejectedSlug = 'rejected'; }
    }
} catch (Throwable $__) {}

// Obtener costo estimado actual
$est = 0.0;
try {
    $estStmt = $pdo->prepare("SELECT estimated_cost FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $estStmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $portalTenantId] : [$order_id]);
    $est = (float)($estStmt->fetchColumn() ?: 0);
} catch (Throwable $__) {}

$statusSlug = ($status === 'approved') ? $approvedSlug : $rejectedSlug;
$hasApprovedAmountCol = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'approved_quote_amount'");
    $hasApprovedAmountCol = ($c && $c->rowCount() > 0);
} catch (Throwable $__) {}
$setApprovedAmount = $hasApprovedAmountCol ? ", approved_quote_amount = " . (($status === 'approved') ? "?" : "NULL") : "";
$params = [$status, $sigRelParam, $commentParam, $isApproved];
if ($hasApprovedAmountCol && $status === 'approved') { $params[] = $est; }
$setStatusSql = "";
if ($statusSlug !== null) {
    $setStatusSql = ", status = ?";
    $params[] = $statusSlug;
}
$params[] = $order_id;
$whereTenantSql = "";
if (!$perDatabase && $hasTenantWorkOrders) {
    $whereTenantSql = " AND tenant_id = ?";
    $params[] = $portalTenantId;
}
$sql = "UPDATE work_orders 
        SET approval_status = ?, approval_signature_path = ?, approval_comment = ?, approved_at = CASE WHEN ? = 1 THEN NOW() ELSE approved_at END $setApprovedAmount$setStatusSql
        WHERE id = ?$whereTenantSql";
$stmtU = $pdo->prepare($sql);
$stmtU->execute($params);

if ($status === 'approved') {
    try {
        if ($hasTenantHist) {
            $history = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, 'approved', 'Aprobado por cliente', NULL, ?)");
            $history->execute([$order_id, $tenantValue]);
        } else {
            $history = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'approved', 'Aprobado por cliente', NULL)");
            $history->execute([$order_id]);
        }
    }
    catch (Throwable $e) {
    }
} else {
    try {
        if ($hasTenantHist) {
            $history = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id) VALUES (?, 'rejected', 'Rechazado por cliente', NULL, ?)");
            $history->execute([$order_id, $tenantValue]);
        } else {
            $history = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by) VALUES (?, 'rejected', 'Rechazado por cliente', NULL)");
            $history->execute([$order_id]);
        }
    }
    catch (Throwable $e) {
    }
}

// Notificaciones
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        meta TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NULL,
        CONSTRAINT fk_un_n FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $title = ($status === 'approved') ? 'Cliente aprobó presupuesto' : 'Cliente rechazó presupuesto';
    $body = 'Orden #' . (int)$order_id . '. ' . (($commentParam !== null && $commentParam !== '') ? $commentParam : 'Sin comentario.');
    $meta = json_encode(['order_id' => (int)$order_id, 'decision' => $status], JSON_UNESCAPED_UNICODE);
    if ($hasTenantNotifications) {
        $insN = $pdo->prepare("INSERT INTO notifications (tenant_id, type, title, body, meta) VALUES (?, 'client_decision', ?, ?, ?)");
        $insN->execute([$tenantValue, $title, $body, $meta]);
    } else {
        $insN = $pdo->prepare("INSERT INTO notifications (type, title, body, meta) VALUES ('client_decision', ?, ?, ?)");
        $insN->execute([$title, $body, $meta]);
    }
    $notId = (int)$pdo->lastInsertId();
    $sqlUsers = "SELECT id FROM users WHERE role IN ('admin','tecnico','technician','owner','super_admin')";
    $paramsUsers = [];
    if ($hasTenantUsers && !$perDatabase) {
        $sqlUsers .= " AND tenant_id = ?";
        $paramsUsers[] = $tenantValue;
    }
    $usersStmt = $pdo->prepare($sqlUsers);
    $usersStmt->execute($paramsUsers);
    $uids = $usersStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!empty($uids)) {
        $insUN = $pdo->prepare("INSERT INTO user_notifications (notification_id, user_id) VALUES (?, ?)");
        foreach ($uids as $uid) { $insUN->execute([$notId, (int)$uid]); }
    }
} catch (Throwable $__) {}

$statusDisplayFinal = $status === 'approved' ? 'Aprobada' : 'Rechazada';
$typeFinal = $status === 'approved' ? 'success' : 'info'; // Use info icon for rejection so it doesn't look like an actual error
renderPortalMessage('¡Gracias!', 'Tu decisión de presupuesto ha sido registrada correctamente como: <br><strong class="fs-4 text-dark d-inline-block mt-2">' . htmlspecialchars($statusDisplayFinal) . '</strong>.', $slug, $company, $typeFinal, $order_id);
