<?php
require_once '../config/session.php';
requireAuth('../login/index.php');
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$pdo = db();
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    http_response_code(400);
    echo 'ID inválido';
    exit;
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
$hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'device_types') : false;
$hasTenantCompanyConfig = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$hasOrderNumberWorkOrders = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'order_number') : false;
$hasClientObservationsWorkOrders = function_exists('hasColumnCached') ? hasColumnCached($pdo, 'work_orders', 'client_observations') : false;

$joinClients = ($hasTenantClients && $hasTenantWorkOrders && !$perDatabase)
    ? "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id"
    : "LEFT JOIN clients c ON wo.client_id = c.id";
$joinDeviceTypes = ($hasTenantDeviceTypes && $hasTenantWorkOrders && !$perDatabase)
    ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id"
    : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id";
$orderNumExpr = $hasOrderNumberWorkOrders ? "COALESCE(NULLIF(wo.order_number,0), wo.id)" : "wo.id";

$sql = "
    SELECT
        wo.id,
        $orderNumExpr AS orden_numero,
        wo.created_at,
        wo.device_brand,
        wo.device_model,
        wo.serial_number,
        wo.reported_issue,
        wo.technician_notes,
        " . ($hasClientObservationsWorkOrders ? "wo.client_observations," : "'' AS client_observations,") . "
        CASE
            WHEN c.company_name IS NOT NULL AND c.company_name != '' THEN c.company_name
            ELSE c.first_name
        END AS cliente_nombre,
        c.phone AS cliente_telefono,
        dt.name AS equipo_tipo
    FROM work_orders wo
    $joinClients
    $joinDeviceTypes
    WHERE wo.id = ?" . (($hasTenantWorkOrders && !$perDatabase) ? " AND wo.tenant_id = ?" : "") . "
    LIMIT 1
";
$params = [$order_id];
if ($hasTenantWorkOrders && !$perDatabase) {
    $params[] = $tenantValue;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$o = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$o) {
    http_response_code(404);
    echo 'Orden no encontrada';
    exit;
}

$company = [];
try {
    if (!$perDatabase && $hasTenantCompanyConfig) {
        $stc = $pdo->prepare("SELECT company_name, company_logo FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stc->execute([$tenantValue]);
    } else {
        $stc = $pdo->prepare("SELECT company_name, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
        $stc->execute();
    }
    $company = $stc->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $company = [];
}

$baseUrl = getSystemBaseUrl();
$portalTenantId = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)$tenant_id;
if ($portalTenantId <= 0) {
    $portalTenantId = (int)$tenant_id;
}
$slug = getTenantPreferredSlug($portalTenantId) ?? (string)$portalTenantId;
$prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_id) : 'ORD';
$visibleNum = (int)($o['orden_numero'] ?? 0);
if ($visibleNum <= 0) {
    $visibleNum = (int)$order_id;
}
$orderNoDisplay = $prefix . '-' . str_pad((string)$visibleNum, 4, '0', STR_PAD_LEFT);
$portalLink = rtrim($baseUrl, '/') . '/portal/verify.php?t=' . rawurlencode($slug) . '&order_no=' . rawurlencode($orderNoDisplay);
$isPreview = (string)($_GET['preview'] ?? '') === '1';
$allowedPreviewKeys = [
    'label_paper_size',
    'label_style',
    'label_custom_width_mm',
    'label_custom_height_mm',
    'label_padding_mm',
    'label_show_logo',
    'label_layout',
    'label_font_family',
    'label_multiline_lines',
    'label_logo_mm',
    'label_qr_mm',
    'label_order_font_pt',
    'label_line_font_pt',
    'label_uppercase',
    'label_border',
    'label_element_order',
    'label_copies',
    'label_show_qr',
    'label_show_client',
    'label_show_client_phone',
    'label_show_serial',
    'label_show_date',
    'label_show_device_type',
    'label_show_device',
    'label_show_issue',
    'label_show_client_observations',
    'label_show_tech_notes',
    'label_show_accessories',
    'label_preview_zoom',
];
$cfg = function(string $k, string $d = '') use ($isPreview, $allowedPreviewKeys): string {
    if ($isPreview && in_array($k, $allowedPreviewKeys, true) && isset($_GET[$k])) {
        return (string)$_GET[$k];
    }
    return function_exists('cfg_get') ? (string)cfg_get($k, $d) : $d;
};
$labelPreset = $cfg('label_paper_size', 'sticker_5030');
$labelStyle = $cfg('label_style', 'compact');
$labelShowQr = ($cfg('label_show_qr', '1') === '1');
$labelShowClient = ($cfg('label_show_client', '1') === '1');
$labelShowClientPhone = ($cfg('label_show_client_phone', '0') === '1');
$labelShowSerial = ($cfg('label_show_serial', '1') === '1');
$labelShowDate = ($cfg('label_show_date', '0') === '1');
$labelShowDeviceType = ($cfg('label_show_device_type', '0') === '1');
$labelShowDevice = ($cfg('label_show_device', '1') === '1');
$labelShowIssue = ($cfg('label_show_issue', '0') === '1');
$labelShowClientObs = ($cfg('label_show_client_observations', '0') === '1');
$labelShowTechNotes = ($cfg('label_show_tech_notes', '0') === '1');
$labelShowAccessories = ($cfg('label_show_accessories', '0') === '1');
$labelShowLogo = ($cfg('label_show_logo', '0') === '1');
$labelLayout = $cfg('label_layout', 'qr_bottom');
$labelFont = $cfg('label_font_family', 'arial');
$labelClamp = (int)$cfg('label_multiline_lines', '3');
if ($labelClamp < 1) { $labelClamp = 1; }
if ($labelClamp > 5) { $labelClamp = 5; }
$previewZoom = (float)$cfg('label_preview_zoom', '1');
if ($previewZoom < 1) { $previewZoom = 1; }
if ($previewZoom > 6) { $previewZoom = 6; }
if (!$isPreview) { $previewZoom = 1; }
$labelUpper = ($cfg('label_uppercase', '0') === '1');
$labelBorder = ($cfg('label_border', '0') === '1');
$labelOrderRaw = preg_replace('/[^a-z0-9_,]/', '', $cfg('label_element_order', 'client,device_type,device,serial,issue,client_observations,tech_notes,accessories,date'));
$labelCopies = (int)$cfg('label_copies', '1');
if ($labelCopies < 1) { $labelCopies = 1; }
if ($labelCopies > 10) { $labelCopies = 10; }

$labelWmm = 50.0;
$labelHmm = 30.0;
if ($labelPreset === 'sticker_4025') { $labelWmm = 40.0; $labelHmm = 25.0; }
if ($labelPreset === 'sticker_5025') { $labelWmm = 50.0; $labelHmm = 25.0; }
if ($labelPreset === 'sticker_5030') { $labelWmm = 50.0; $labelHmm = 30.0; }
if ($labelPreset === 'sticker_6040') { $labelWmm = 60.0; $labelHmm = 40.0; }
if ($labelPreset === 'sticker_7050') { $labelWmm = 70.0; $labelHmm = 50.0; }
if ($labelPreset === 'sticker_8050') { $labelWmm = 80.0; $labelHmm = 50.0; }
if ($labelPreset === 'sticker_10050') { $labelWmm = 100.0; $labelHmm = 50.0; }
if ($labelPreset === 'sticker_100150') { $labelWmm = 100.0; $labelHmm = 150.0; }
if ($labelPreset === 'custom') {
    $cw = (float)$cfg('label_custom_width_mm', '50');
    $ch = (float)$cfg('label_custom_height_mm', '30');
    if ($cw >= 20 && $cw <= 120) { $labelWmm = $cw; }
    if ($ch >= 15 && $ch <= 200) { $labelHmm = $ch; }
}
$padMm = (float)$cfg('label_padding_mm', $labelPreset === 'sticker_100150' ? '4' : '2');
if ($padMm < 0) { $padMm = 0; }
if ($padMm > 10) { $padMm = 10; }
$logoMm = (float)$cfg('label_logo_mm', $labelPreset === 'sticker_100150' ? '22' : '10');
if ($logoMm < 0) { $logoMm = 0; }
if ($logoMm > 40) { $logoMm = 40; }
if (!$labelShowLogo) { $logoMm = 0; }
$qrMm = (float)$cfg('label_qr_mm', $labelPreset === 'sticker_100150' ? '30' : '10');
if ($qrMm < 0) { $qrMm = 0; }
if ($qrMm > 60) { $qrMm = 60; }
$orderPt = (float)$cfg('label_order_font_pt', $labelPreset === 'sticker_100150' ? '18' : '11');
if ($orderPt < 8) { $orderPt = 8; }
if ($orderPt > 40) { $orderPt = 40; }
$linePt = (float)$cfg('label_line_font_pt', $labelPreset === 'sticker_100150' ? '11.5' : '7.5');
if ($linePt < 6) { $linePt = 6; }
if ($linePt > 30) { $linePt = 30; }

$qrPx = $isPreview
    ? (int)round(($qrMm * 3.7795275591) * $previewZoom)
    : (int)round(($qrMm * 3.8));
if ($qrPx < 90) { $qrPx = 90; }
if ($qrPx > 900) { $qrPx = 900; }

$qrFormat = $isPreview ? 'png' : 'svg';
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/'
    . '?format=' . $qrFormat
    . '&ecc=H'
    . '&margin=0'
    . '&size=' . $qrPx . 'x' . $qrPx
    . '&data=' . urlencode($portalLink);

$logo = (string)($company['company_logo'] ?? '');
$logoSrc = '';
if ($logo !== '') {
    $logoSrc = rtrim($baseUrl, '/') . '/assets/img/' . rawurlencode(basename($logo));
}
if ($logoSrc === '') {
    $logoSrc = rtrim($baseUrl, '/') . '/assets/img/logo.png';
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$cleanLine = function($s): string {
    $t = trim(preg_replace('/\s+/', ' ', (string)$s));
    $t = strip_tags($t);
    return $t;
};

$deviceLine = trim((string)($o['device_brand'] ?? '') . ' ' . (string)($o['device_model'] ?? ''));
$deviceTypeLine = $cleanLine($o['equipo_tipo'] ?? '');
$clientLine = (string)($o['cliente_nombre'] ?? '');
if ($labelShowClientPhone) {
    $ph = trim((string)($o['cliente_telefono'] ?? ''));
    if ($ph !== '') { $clientLine = rtrim($clientLine . ' - ' . $ph, ' -'); }
}
$issueLine = $cleanLine($o['reported_issue'] ?? '');
$clientObsLine = $cleanLine($o['client_observations'] ?? '');
$techNotesLine = $cleanLine($o['technician_notes'] ?? '');
$serialValue = (string)($o['serial_number'] ?? '');

$accessoriesLine = '';
if ($labelShowAccessories) {
    $hasTenantEquipmentAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'equipment_accessories') : false;
    $hasTenantOrderEquipmentAccessories = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_equipment_accessories') : false;
    try {
        if (!$perDatabase && $hasTenantEquipmentAccessories && $hasTenantOrderEquipmentAccessories) {
            $stmtAcc = $pdo->prepare("
                SELECT ea.name
                FROM order_equipment_accessories oea
                JOIN equipment_accessories ea ON oea.accessory_id = ea.id
                WHERE oea.order_id = ? AND oea.is_included = 1 AND ea.tenant_id = ? AND oea.tenant_id = ?
                ORDER BY ea.sort_order ASC, ea.name ASC
            ");
            $stmtAcc->execute([$order_id, $tenantValue, $tenantValue]);
        } else {
            $stmtAcc = $pdo->prepare("
                SELECT ea.name
                FROM order_equipment_accessories oea
                JOIN equipment_accessories ea ON oea.accessory_id = ea.id
                WHERE oea.order_id = ? AND oea.is_included = 1
                ORDER BY ea.sort_order ASC, ea.name ASC
            ");
            $stmtAcc->execute([$order_id]);
        }
        $names = $stmtAcc->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $names = array_values(array_filter(array_map('trim', array_map('strval', $names)), function($x){ return $x !== ''; }));
        if (!empty($names)) {
            $accessoriesLine = 'Acc: ' . implode(', ', $names);
        }
    } catch (Throwable $e) {
        $accessoriesLine = '';
    }
}

$dateLine = '';
try {
    if (!empty($o['created_at'])) {
        $dateLine = function_exists('formatCompanyDate') ? (string)formatCompanyDate($o['created_at']) : (string)date('Y-m-d', strtotime((string)$o['created_at']));
    }
} catch (Throwable $e) { $dateLine = ''; }

if ($labelUpper) {
    $clientLine = mb_strtoupper($clientLine, 'UTF-8');
    $deviceLine = mb_strtoupper($deviceLine, 'UTF-8');
    $deviceTypeLine = mb_strtoupper($deviceTypeLine, 'UTF-8');
    $issueLine = mb_strtoupper($issueLine, 'UTF-8');
    $clientObsLine = mb_strtoupper($clientObsLine, 'UTF-8');
    $techNotesLine = mb_strtoupper($techNotesLine, 'UTF-8');
    $accessoriesLine = mb_strtoupper($accessoriesLine, 'UTF-8');
    $serialValue = mb_strtoupper($serialValue, 'UTF-8');
    $dateLine = mb_strtoupper($dateLine, 'UTF-8');
}

$elements = array_values(array_filter(array_map('trim', explode(',', (string)$labelOrderRaw)), function($x){ return $x !== ''; }));
$allowedElements = ['client','device_type','device','serial','issue','client_observations','tech_notes','accessories','date'];
$elements = array_values(array_filter($elements, function($x) use ($allowedElements){ return in_array($x, $allowedElements, true); }));
if (empty($elements)) {
    $elements = $allowedElements;
}
foreach ($allowedElements as $k) {
    if (!in_array($k, $elements, true)) {
        $elements[] = $k;
    }
}

$linesByKey = [
    'client' => ($labelShowClient && $clientLine !== '') ? $clientLine : '',
    'device_type' => ($labelShowDeviceType && $deviceTypeLine !== '') ? $deviceTypeLine : '',
    'device' => ($labelShowDevice && $deviceLine !== '') ? $deviceLine : '',
    'serial' => ($labelShowSerial && $serialValue !== '') ? ('S/N: ' . $serialValue) : '',
    'issue' => ($labelShowIssue && $issueLine !== '') ? ('Falla: ' . $issueLine) : '',
    'client_observations' => ($labelShowClientObs && $clientObsLine !== '') ? ('Obs: ' . $clientObsLine) : '',
    'tech_notes' => ($labelShowTechNotes && $techNotesLine !== '') ? ('Téc: ' . $techNotesLine) : '',
    'accessories' => ($labelShowAccessories && $accessoriesLine !== '') ? $accessoriesLine : '',
    'date' => ($labelShowDate && $dateLine !== '') ? $dateLine : ''
];

$renderLines = [];
$wrapKeys = ($labelStyle === 'detailed')
    ? ['issue','client_observations','tech_notes','accessories']
    : ['issue'];
foreach ($elements as $k) {
    $t = (string)($linesByKey[$k] ?? '');
    if ($t === '') { continue; }
    $cls = 'line';
    if ($k === 'client') {
        $cls = 'line muted';
        if ($labelShowClientPhone) { $cls = 'line muted wrap'; }
    }
    if ($k === 'date') { $cls = 'small'; }
    if (in_array($k, $wrapKeys, true)) { $cls = trim($cls . ' wrap'); }
    $renderLines[] = ['key' => $k, 'cls' => $cls, 'text' => $t];
}

$isSmallLabel = ($labelWmm <= 60.0 && $labelHmm <= 40.0);
$topGapMm = $isSmallLabel ? 1.0 : 2.0;
$orderMtMm = $isSmallLabel ? 0.2 : 1.0;
$gridMtMm = $isSmallLabel ? 0.4 : 1.0;
$gridGapMm = $isSmallLabel ? 0.35 : 0.6;
$lineLh = $isSmallLabel ? 1.08 : 1.15;
if ($labelStyle === 'compact') {
    $gridGapMm = max(0.2, $gridGapMm - 0.1);
    $lineLh = $isSmallLabel ? 1.06 : 1.12;
}
$qrBoxPadMm = $isSmallLabel ? 0.6 : 1.0;

if ($isSmallLabel) {
    $orderPt = $orderPt * 0.92;
    $linePt = $linePt * 0.92;
    if ($orderPt < 8) { $orderPt = 8; }
    if ($linePt < 6) { $linePt = 6; }
}

$fontMap = [
    'arial' => "Arial, sans-serif",
    'tahoma' => "Tahoma, sans-serif",
    'verdana' => "Verdana, sans-serif"
];
$fontFamily = $fontMap[$labelFont] ?? $fontMap['arial'];

$useQr = ($labelShowQr && $qrMm > 0);
if ($labelLayout === 'no_qr') { $useQr = false; }
if (!$useQr) { $labelLayout = 'no_qr'; }
if (!in_array($labelLayout, ['qr_right', 'qr_bottom', 'no_qr'], true)) { $labelLayout = 'qr_bottom'; }

if ($useQr && $isSmallLabel && ($labelLayout === 'qr_bottom' || $labelLayout === 'qr_right')) {
    if ($labelHmm <= 25.0) { $qrMm = min($qrMm, 8.0); }
    else if ($labelHmm <= 30.0) { $qrMm = min($qrMm, 10.0); }
    else { $qrMm = min($qrMm, 12.0); }
    $qrBoxPadMm = min($qrBoxPadMm, 0.4);
    $labelClamp = min($labelClamp, 2);
}

$bottomDateText = '';
if ($useQr && $labelLayout === 'qr_bottom') {
    $tmp = [];
    foreach ($renderLines as $rl) {
        if (($rl['key'] ?? '') === 'date' && $bottomDateText === '') {
            $bottomDateText = (string)($rl['text'] ?? '');
            continue;
        }
        $tmp[] = $rl;
    }
    $renderLines = $tmp;
}

$rightTopLines = $renderLines;
$rightIssueText = '';
$rightIssuePrefix = '';
if ($useQr && $labelLayout === 'qr_right') {
    $tmp = [];
    foreach ($renderLines as $rl) {
        $k = (string)($rl['key'] ?? '');
        if ($k === 'issue' && $rightIssueText === '') {
            $rightIssueText = (string)($rl['text'] ?? '');
            $rightIssuePrefix = (string)($rl['cls'] ?? 'line');
            continue;
        }
        $tmp[] = $rl;
    }
    $rightTopLines = $tmp;
}

$mmToPx = 3.7795275591;
$unit = $isPreview ? 'px' : 'mm';
$wCss = $isPreview ? (int)round($labelWmm * $mmToPx * $previewZoom) : $labelWmm;
$hCss = $isPreview ? (int)round($labelHmm * $mmToPx * $previewZoom) : $labelHmm;
$padCss = $isPreview ? (int)round($padMm * $mmToPx * $previewZoom) : $padMm;
$topGapCss = $isPreview ? (int)round($topGapMm * $mmToPx * $previewZoom) : $topGapMm;
$logoCss = $isPreview ? (int)round($logoMm * $mmToPx * $previewZoom) : $logoMm;
$qrCss = $isPreview ? (int)round($qrMm * $mmToPx * $previewZoom) : $qrMm;
$qrBoxPadCss = $isPreview ? (int)round($qrBoxPadMm * $mmToPx * $previewZoom) : $qrBoxPadMm;
$qrBorderCss = $isPreview ? (int)max(1, round(0.2 * $mmToPx * $previewZoom)) : 0.2;
$qrRadiusCss = $isPreview ? (int)max(2, round(1.2 * $mmToPx * $previewZoom)) : 1.2;
$orderMtCss = $isPreview ? (int)round($orderMtMm * $mmToPx * $previewZoom) : $orderMtMm;
$gridMtCss = $isPreview ? (int)round($gridMtMm * $mmToPx * $previewZoom) : $gridMtMm;
$gridGapCss = $isPreview ? (int)round($gridGapMm * $mmToPx * $previewZoom) : $gridGapMm;
$borderCss = $isPreview ? (int)max(1, round(0.3 * $mmToPx * $previewZoom)) : 0.3;
$ptToPx = 96.0 / 72.0;
$orderFontCss = $isPreview ? (int)max(10, round(($orderPt * $ptToPx) * $previewZoom)) : $orderPt;
$lineFontCss = $isPreview ? (int)max(8, round(($linePt * $ptToPx) * $previewZoom)) : $linePt;
$smallFontCss = $isPreview ? (int)max(8, round((max(6.0, (float)$linePt - 1.0) * $ptToPx) * $previewZoom)) : max(6.0, (float)$linePt - 1.0);
$orderLineHeightCss = $isPreview ? (int)max(10, round($orderFontCss * 1.05)) : 1.05;
$lineLineHeightCss = $isPreview ? (int)max(8, round($lineFontCss * $lineLh)) : $lineLh;
$qrOuterCss = $qrCss + ($qrBoxPadCss * 2) + ($qrBorderCss * 2);
$orderUnderFontCss = $isPreview ? (int)max(10, round($orderFontCss * 0.78)) : (float)max(8.0, ($orderPt * 0.78));
$orderUnderLhCss = $isPreview ? (int)max(10, round($orderUnderFontCss * 1.05)) : 1.05;

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiqueta <?php echo esc($orderNoDisplay); ?></title>
    <style>
        @page { size: <?php echo (float)$labelWmm; ?>mm <?php echo (float)$labelHmm; ?>mm; margin: 0; }
        html, body { width: <?php echo (float)$wCss . $unit; ?>; height: <?php echo (float)$hCss . $unit; ?>; margin: 0; padding: 0; background: #fff; font-family: <?php echo $fontFamily; ?>; overflow: hidden; -webkit-font-smoothing: antialiased; text-rendering: auto; }
        .label { width: <?php echo (float)$wCss . $unit; ?>; height: <?php echo (float)$hCss . $unit; ?>; padding: <?php echo (float)$padCss . $unit; ?>; box-sizing: border-box; overflow: hidden; display: flex; flex-direction: column; position: relative; <?php echo $labelBorder ? ('border: ' . (float)$borderCss . $unit . ' solid #111;') : ''; ?> }
        .top { display: flex; justify-content: space-between; align-items: center; gap: <?php echo (float)$topGapCss . $unit; ?>; }
        .header { display: flex; align-items: center; gap: <?php echo (float)$topGapCss . $unit; ?>; }
        .logo { width: <?php echo (float)$logoCss . $unit; ?>; height: <?php echo (float)$logoCss . $unit; ?>; object-fit: contain; }
        .qrBox { padding: <?php echo (float)$qrBoxPadCss . $unit; ?>; background: #fff; border: <?php echo (float)$qrBorderCss . $unit; ?> solid rgba(0,0,0,.15); border-radius: <?php echo (float)$qrRadiusCss . $unit; ?>; flex: 0 0 auto; }
        .qr { width: <?php echo (float)$qrCss . $unit; ?>; height: <?php echo (float)$qrCss . $unit; ?>; object-fit: contain; display: block; }
        .order { font-weight: 800; font-size: <?php echo (float)$orderFontCss . ($isPreview ? 'px' : 'pt'); ?>; margin-top: <?php echo (float)$orderMtCss . $unit; ?>; line-height: <?php echo $isPreview ? ((int)$orderLineHeightCss . 'px') : (float)$orderLineHeightCss; ?>; }
        .line { font-size: <?php echo (float)$lineFontCss . ($isPreview ? 'px' : 'pt'); ?>; line-height: <?php echo $isPreview ? ((int)$lineLineHeightCss . 'px') : (float)$lineLineHeightCss; ?>; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .muted { color: #333; }
        .grid { display: grid; grid-template-columns: 1fr; gap: <?php echo (float)$gridGapCss . $unit; ?>; margin-top: <?php echo (float)$gridMtCss . $unit; ?>; flex: 1 1 auto; min-height: 0; overflow: hidden; align-content: start; }
        .small { font-size: <?php echo (float)$smallFontCss . ($isPreview ? 'px' : 'pt'); ?>; color: #333; }
        .wrap { white-space: normal; overflow: hidden; text-overflow: clip; display: -webkit-box; -webkit-line-clamp: <?php echo (int)$labelClamp; ?>; -webkit-box-orient: vertical; }
        .bottomRow { flex: 0 0 auto; display: flex; justify-content: space-between; align-items: flex-end; }
        .bottomDate { max-width: 65%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rightLayout { display: grid; grid-template-columns: 1fr auto; column-gap: <?php echo (float)$topGapCss . $unit; ?>; align-items: start; }
        .rightCol { min-width: 0; display: flex; flex-direction: column; min-height: 0; }
        .rightQr { justify-self: end; align-self: start; display: flex; flex-direction: column; align-items: flex-end; }
        .rightUnder { width: <?php echo (float)$qrOuterCss . $unit; ?>; margin-top: <?php echo (float)$topGapCss . $unit; ?>; text-align: center; }
        .orderUnder { margin-top: 0; font-size: <?php echo (float)$orderUnderFontCss . ($isPreview ? 'px' : 'pt'); ?>; line-height: <?php echo $isPreview ? ((int)$orderUnderLhCss . 'px') : (float)$orderUnderLhCss; ?>; }
        .issueArea { margin-top: <?php echo (float)$gridGapCss . $unit; ?>; font-size: <?php echo (float)$lineFontCss . ($isPreview ? 'px' : 'pt'); ?>; line-height: <?php echo $isPreview ? ((int)$lineLineHeightCss . 'px') : (float)$lineLineHeightCss; ?>; white-space: normal; overflow: hidden; flex: 1 1 auto; min-height: 0; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <?php for ($cp = 0; $cp < $labelCopies; $cp++): ?>
    <div class="label">
        <?php if ($labelLayout === 'qr_right'): ?>
            <div class="rightLayout">
                <div class="rightCol">
                    <?php if ($logoMm > 0): ?>
                    <div class="header">
                        <img class="logo" src="<?php echo esc($logoSrc); ?>" alt="">
                    </div>
                    <?php endif; ?>
                    <div class="grid">
                        <?php foreach ($rightTopLines as $rl): ?>
                        <div class="<?php echo esc($rl['cls']); ?>"><?php echo esc($rl['text']); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($rightIssueText !== ''): ?>
                    <div class="issueArea"><?php echo esc($rightIssueText); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($useQr): ?>
                <div class="rightQr">
                    <div class="qrBox"><img class="qr" src="<?php echo esc($qrImg); ?>" alt=""></div>
                    <div class="rightUnder">
                        <div class="order orderUnder"><?php echo esc($orderNoDisplay); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="top">
                <div class="header">
                    <?php if ($logoMm > 0): ?>
                    <img class="logo" src="<?php echo esc($logoSrc); ?>" alt="">
                    <?php endif; ?>
                    <div class="order"><?php echo esc($orderNoDisplay); ?></div>
                </div>
            </div>
            <div class="grid">
                <?php foreach ($renderLines as $rl): ?>
                <div class="<?php echo esc($rl['cls']); ?>"><?php echo esc($rl['text']); ?></div>
                <?php endforeach; ?>
            </div>
            <?php if ($useQr): ?>
            <div class="bottomRow">
                <div class="bottomDate small"><?php echo esc($bottomDateText); ?></div>
                <div class="qrBox"><img class="qr" src="<?php echo esc($qrImg); ?>" alt=""></div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($cp < $labelCopies - 1): ?>
    <div style="page-break-after: always;"></div>
    <?php endif; ?>
    <?php endfor; ?>
    <script>
        (function () {
            const p = new URLSearchParams(window.location.search);
            if (p.get('print') === 'true') {
                setTimeout(function () { window.print(); }, 150);
            }
        })();
    </script>
</body>
</html>
