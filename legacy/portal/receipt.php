<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$slug = isset($_GET['t']) ? trim($_GET['t']) : '';
$tenant_id = $slug ? getTenantIdFromSlug($slug) : null;
if (!$tenant_id) { $tenant_id = 1; $slug = '1'; }
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$portalTenantId = (int)$tenant_id;

if ($perDatabase && class_exists('DatabaseManager')) {
    try {
        $pdo = DatabaseManager::tenant($portalTenantId);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
    } catch (Throwable $e) {
        http_response_code(503);
        die('Portal no disponible');
    }
}

$hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
$hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { http_response_code(400); die('Orden inválida'); }

$stmt = $pdo->prepare("SELECT id, order_number, device_brand, device_model, reported_issue, verification_code, approval_status, approved_at, approval_signature_path, estimated_cost FROM work_orders WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
$stmt->execute((!$perDatabase && $hasTenantWorkOrders) ? [$order_id, $tenant_id] : [$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); die('Orden no encontrada'); }
// Asegurar código de verificación para impresión
try {
    $code = strtoupper(trim((string)($order['verification_code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    if ($code === '') {
        $code = generateVerificationCode(6);
        $upd = $pdo->prepare("UPDATE work_orders SET verification_code = ? WHERE id = ?" . ((!$perDatabase && $hasTenantWorkOrders) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $upd->execute((!$perDatabase && $hasTenantWorkOrders) ? [$code, $order_id, $tenant_id] : [$code, $order_id]);
        $order['verification_code'] = $code;
    } else {
        $order['verification_code'] = $code;
    }
} catch (Throwable $e) {}
$company = [];
try {
    if (!$perDatabase && $hasTenantCompany) {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$tenant_id]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante</title>
    <link rel="icon" type="image/png" href="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo htmlspecialchars($company['company_logo'] ?? 'logo.png'); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #0d6efd; --text: #1e293b; --muted: #64748b; --bg: #f6f8fa; }
        body { background: var(--bg); color: var(--text); }
        .card-modern { border: none; background: #fff; box-shadow: 0 15px 35px rgba(0,0,0,0.04), 0 5px 15px rgba(0,0,0,0.02); border-radius: 24px; overflow: hidden; }
        .badge-chip { border-radius: 999px; padding: 6px 12px; font-weight: 600; }
        .btn-pill { border-radius: 999px; font-weight: 600; }
        .section { border: 1px solid #eef2f6; border-radius: 16px; padding: 16px; background: #f8fafc; }
        .icon-box { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: #fff; border: 1px solid #eee; }
        .qr-box img { border-radius: 12px; border: 1px solid #eef2f6; }
        @media print { .no-print { display: none; } body { background: #fff; } .card-modern { box-shadow: none; } }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-7">
                <div class="card-modern">
                    <div class="p-4 p-md-5">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <img src="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo htmlspecialchars($company['company_logo'] ?? 'logo.png'); ?>" alt="" class="icon-box" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='<?php echo getSystemBaseUrl(); ?>assets/img/logo.png';">
                                <div class="fw-bold fs-5"><?php echo htmlspecialchars($company['company_name'] ?? ''); ?></div>
                            </div>
                            <?php $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix($tenant_id) : 'ORD'; ?>
                            <?php $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order_id; ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle badge-chip"><?php echo htmlspecialchars($prefix); ?>-<?php echo str_pad($num, 6, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <h3 class="fw-bold mb-1">Comprobante de Presupuesto</h3>
                        <?php $st = strtolower((string)($order['approval_status'] ?? '')); $stDisp = ($st === 'approved' ? 'Aprobado' : ($st === 'rejected' ? 'Rechazado' : ($st === 'pending' ? 'Pendiente' : ucfirst($st)))); ?>
                        <div class="text-muted mb-3">Estado: <strong><?php echo htmlspecialchars($stDisp); ?></strong> • Fecha: <strong><?php echo htmlspecialchars(formatCompanyDate($order['approved_at'] ?? date('Y-m-d H:i'), true)); ?></strong></div>
                        
                        <div class="section mb-3">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div><i class="fa-solid fa-mobile-screen-button text-muted me-2"></i><strong>Equipo</strong></div>
                                    <div><?php echo htmlspecialchars(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? '')) ?: ''); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div><i class="fa-solid fa-money-bill-wave text-muted me-2"></i><strong>Monto</strong></div>
                                    <div class="fw-bold text-success"><?php echo CompanySettings::formatCurrency($order['estimated_cost'] ?? 0); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div><i class="fa-solid fa-key text-muted me-2"></i><strong>Código de Verificación</strong></div>
                                    <div><span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle badge-chip"><?php echo htmlspecialchars($order['verification_code'] ?? ''); ?></span></div>
                                </div>
                                <?php if (!empty($order['reported_issue'])): ?>
                                <div class="col-12">
                                    <div><i class="fa-solid fa-triangle-exclamation text-muted me-2"></i><strong>Problema</strong></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($order['reported_issue']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($order['approval_signature_path'])): ?>
                        <div class="mb-3">
                            <div class="text-muted mb-1">Firma del Cliente</div>
                            <img src="../<?php echo htmlspecialchars($order['approval_signature_path']); ?>" alt="Firma" style="max-width:100%;border:1px dashed #cbd5e1;border-radius:12px;background:#fff">
                        </div>
                        <?php endif; ?>
                        
                        
                        
                        <div class="row g-3 align-items-center no-print">
                            <div class="col-12">
                                <?php $base = getSystemBaseUrl(); $verifyUrl = $base . 'portal/verify.php?t=' . urlencode($slug) . '&order_no=' . urlencode($prefix . '-' . $num); ?>
                                <button class="btn btn-outline-secondary btn-pill" onclick="window.print()"><i class="fa-solid fa-file-pdf me-2"></i>Descargar Comprobante</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
