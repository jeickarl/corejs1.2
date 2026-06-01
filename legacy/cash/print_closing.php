<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

requireAuth();

$session_id = $_GET['id'] ?? 0;

if (!$session_id) {
    die("ID de sesión inválido");
}

$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : $tenant_id;
$hasTenantCashSessions = hasTenantColumnCached($pdo, 'cash_sessions');
$hasTenantCashIncome = hasTenantColumnCached($pdo, 'cash_income');
$hasTenantCashExpenses = hasTenantColumnCached($pdo, 'cash_expenses');
$hasTenantUsers = hasTenantColumnCached($pdo, 'users');
$hasTenantCompany = hasTenantColumnCached($pdo, 'company_config');

// Fetch session info
$joinOpen = ($hasTenantUsers && $hasTenantCashSessions && !$perDatabase) ? "LEFT JOIN users u_open ON cs.opened_by = u_open.id AND u_open.tenant_id = cs.tenant_id" : "LEFT JOIN users u_open ON cs.opened_by = u_open.id";
$joinClose = ($hasTenantUsers && $hasTenantCashSessions && !$perDatabase) ? "LEFT JOIN users u_close ON cs.closed_by = u_close.id AND u_close.tenant_id = cs.tenant_id" : "LEFT JOIN users u_close ON cs.closed_by = u_close.id";
$stmt = $pdo->prepare("
    SELECT cs.*, 
           u_open.name as opened_by_name,
           u_close.name as closed_by_name
    FROM cash_sessions cs
    $joinOpen
    $joinClose
    WHERE cs.id = ? " . (($hasTenantCashSessions && !$perDatabase) ? "AND cs.tenant_id = ?" : "") . "
");
$stmt->execute(($hasTenantCashSessions && !$perDatabase) ? [$session_id, $tenantValue] : [$session_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Sesión no encontrada");
}

// Fetch Income by Category
$stmt = $pdo->prepare("
    SELECT tc.name as category_name, SUM(ci.amount) as total
    FROM cash_income ci
    LEFT JOIN transaction_categories tc ON ci.category_id = tc.id
    WHERE ci.cash_session_id = ? " . (($hasTenantCashIncome && !$perDatabase) ? "AND ci.tenant_id = ?" : "") . "
    GROUP BY tc.name
");
$stmt->execute(($hasTenantCashIncome && !$perDatabase) ? [$session_id, $tenantValue] : [$session_id]);
$income_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Expenses by Category
$stmt = $pdo->prepare("
    SELECT tc.name as category_name, SUM(ce.amount) as total
    FROM cash_expenses ce
    LEFT JOIN transaction_categories tc ON ce.category_id = tc.id
    WHERE ce.cash_session_id = ? " . (($hasTenantCashExpenses && !$perDatabase) ? "AND ce.tenant_id = ?" : "") . "
    GROUP BY tc.name
");
$stmt->execute(($hasTenantCashExpenses && !$perDatabase) ? [$session_id, $tenantValue] : [$session_id]);
$expenses_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total expenses
$total_expenses = 0;
foreach($expenses_by_category as $cat) $total_expenses += $cat['total'];

// Fetch Denominations
$hasTenantInDenoms = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM cash_closing_denominations LIKE 'tenant_id'");
    $hasTenantInDenoms = $chk && $chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasTenantInDenoms = false;
}
if ($hasTenantInDenoms) {
    $stmt = $pdo->prepare("SELECT * FROM cash_closing_denominations WHERE cash_session_id = ? AND tenant_id = ? ORDER BY denomination_value DESC");
    $stmt->execute([$session_id, $tenantValue]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM cash_closing_denominations WHERE cash_session_id = ? ORDER BY denomination_value DESC");
    $stmt->execute([$session_id]);
}
$denominations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Company Info
$company_config = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM company_config" . (($hasTenantCompany && !$perDatabase) ? " WHERE tenant_id = ?" : "") . " ORDER BY id DESC LIMIT 1");
    $stmt->execute(($hasTenantCompany && !$perDatabase) ? [$tenantValue] : []);
    $company_config = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Calcular ventas reales (system_total ya incluye el monto inicial)
$actual_sales = $session['system_total'] - $session['initial_amount'];
if ($actual_sales < 0) $actual_sales = 0;

$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=cierre_caja_' . preg_replace('/[^a-zA-Z0-9_\-]/','_', $session['session_number']) . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    // Encabezado empresa y sesión
    fputcsv($out, ['Empresa', $company_config['company_name'] ?? '']);
    fputcsv($out, ['Sesión', $session['session_number']]);
    fputcsv($out, ['Apertura', $session['opening_date']]);
    fputcsv($out, ['Cierre', $session['closing_date'] ?? '']);
    fputcsv($out, []); // línea en blanco
    // Resumen financiero
    fputcsv($out, ['Resumen Financiero']);
    fputcsv($out, ['Base Inicial', $session['initial_amount']]);
    fputcsv($out, ['(+) Ingresos Adicionales (Sistema)', $actual_sales]);
    fputcsv($out, ['(-) Total Egresos (Sistema)', $total_expenses]);
    fputcsv($out, ['Saldo Esperado (Sistema)', $session['initial_amount'] + $actual_sales - $total_expenses]);
    fputcsv($out, []); 
    // Totales por método (si existen en sesión)
    fputcsv($out, ['Totales por Método']);
    fputcsv($out, ['Efectivo (Sistema)', $session['total_cash'] ?? 0]);
    fputcsv($out, ['Transferencia (Sistema)', $session['total_transfer'] ?? 0]);
    fputcsv($out, ['Tarjeta (Sistema)', $session['total_card'] ?? 0]);
    fputcsv($out, ['Otros (Sistema)', $session['total_other'] ?? 0]);
    fputcsv($out, ['Conteo Físico Total', $session['physical_count'] ?? 0]);
    fputcsv($out, ['Diferencia Total', $session['difference'] ?? 0]);
    fputcsv($out, []);
    // Ingresos por categoría
    fputcsv($out, ['Ingresos por Categoría']);
    fputcsv($out, ['Categoría','Total']);
    foreach ($income_by_category as $row) {
        fputcsv($out, [$row['category_name'] ?? 'Sin categoría', $row['total']]);
    }
    fputcsv($out, []);
    // Egresos por categoría
    fputcsv($out, ['Egresos por Categoría']);
    fputcsv($out, ['Categoría','Total']);
    foreach ($expenses_by_category as $row) {
        fputcsv($out, [$row['category_name'] ?? 'Sin categoría', $row['total']]);
    }
    fputcsv($out, []);
    // Denominaciones
    fputcsv($out, ['Denominaciones']);
    fputcsv($out, ['Denominación','Cantidad','Subtotal']);
    foreach ($denominations as $d) {
        fputcsv($out, [$d['denomination_value'], $d['quantity'], $d['subtotal']]);
    }
    fclose($out);
    exit;
}
$currency = CompanySettings::getCurrency();
$symbol = $currency['symbol'];

$EMBEDDED = $EMBEDDED ?? false;
$embedded = isset($_GET['embedded']) ? true : (bool)$EMBEDDED;
if ($embedded) { ob_start(); }
if (!$embedded): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Caja #<?php echo htmlspecialchars($session['session_number']); ?></title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-size: 14px; background: #f5f5f5; }
        .page-container { background: white; max-width: 800px; margin: 20px auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header-logo { max-height: 80px; }
        .table-sm th, .table-sm td { padding: 0.5rem; }
        .bg-light-gray { background-color: #f8f9fa; }
        .total-row { font-weight: bold; background-color: #e9ecef; }
        
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .page-container { box-shadow: none; margin: 0; padding: 20px; max-width: 100%; }
            .no-print { display: none !important; }
            .table { width: 100% !important; }
        }
    </style>
</head>
<body>
<?php endif; ?>
    <div class="page-container"<?php if ($embedded) echo ' style="background:white;max-width:800px;margin:20px auto;padding:40px;box-shadow:0 0 10px rgba(0,0,0,0.1);"'; ?>>
        <div class="mb-4 text-end no-print">
            <div class="btn-group">
                <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print me-2"></i>Imprimir</button>
                <a href="?id=<?php echo intval($session_id); ?>&export=csv" class="btn btn-success"><i class="fas fa-file-csv me-2"></i>Exportar CSV</a>
                <button onclick="downloadClosingPDF()" class="btn btn-danger"><i class="fas fa-file-pdf me-2"></i>Descargar PDF</button>
                <?php if ($embedded): ?>
                <button onclick="history.back()" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Volver</button>
                <?php else: ?>
                <button onclick="window.close()" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Cerrar</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Header -->
        <div class="row mb-4 border-bottom pb-3">
            <div class="col-8">
                <h3 class="mb-0 fw-bold"><?php echo htmlspecialchars($company_config['company_name'] ?? 'Empresa'); ?></h3>
                <div class="text-muted small">
                    <?php if(!empty($company_config['company_address'])) echo htmlspecialchars($company_config['company_address']) . '<br>'; ?>
                    <?php if(!empty($company_config['company_phone'])) echo 'Tel: ' . htmlspecialchars($company_config['company_phone']) . '<br>'; ?>
                    <?php if(!empty($company_config['company_email'])) echo 'Email: ' . htmlspecialchars($company_config['company_email']); ?>
                </div>
            </div>
            <div class="col-4 text-end">
                <h4 class="mb-1">CIERRE DE CAJA</h4>
                <h5 class="text-muted">#<?php echo htmlspecialchars($session['session_number']); ?></h5>
                <div class="badge bg-<?php echo $session['status'] === 'closed' ? 'success' : 'warning'; ?> fs-6">
                    <?php echo $session['status'] === 'closed' ? 'CERRADA' : 'ABIERTA'; ?>
                </div>
            </div>
        </div>

        <!-- Session Info -->
        <div class="row mb-4">
            <div class="col-6">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body py-2">
                        <h6 class="card-title text-muted mb-2">APERTURA</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td class="text-muted ps-0">Fecha:</td>
                                <td class="fw-bold text-end"><?php echo date('d/m/Y H:i', strtotime($session['opening_date'])); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Usuario:</td>
                                <td class="fw-bold text-end"><?php echo htmlspecialchars($session['opened_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Base Inicial:</td>
                                <td class="fw-bold text-end"><?php echo formatCurrency($session['initial_amount']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body py-2">
                        <h6 class="card-title text-muted mb-2">CIERRE</h6>
                        <?php if($session['status'] === 'closed'): ?>
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td class="text-muted ps-0">Fecha:</td>
                                <td class="fw-bold text-end"><?php echo date('d/m/Y H:i', strtotime($session['closing_date'])); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Usuario:</td>
                                <td class="fw-bold text-end"><?php echo htmlspecialchars($session['closed_by_name']); ?></td>
                            </tr>
                        </table>
                        <?php else: ?>
                        <div class="text-center text-muted py-3">Sesión en curso</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="border-bottom pb-2 mb-3">Resumen Financiero</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="bg-light-gray">
                            <tr>
                                <th>Concepto</th>
                                <th class="text-end">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Base Inicial</td>
                                <td class="text-end"><?php echo formatCurrency($session['initial_amount']); ?></td>
                            </tr>
                            <tr>
                                <td>(+) Ingresos Adicionales (Sistema)</td>
                                <td class="text-end text-success"><?php echo formatCurrency($actual_sales); ?></td>
                            </tr>
                            <tr>
                                <td>(-) Total Egresos (Sistema)</td>
                                <td class="text-end text-danger"><?php echo formatCurrency($total_expenses); ?></td>
                            </tr>
                            <tr class="total-row">
                                <td>Saldo Esperado en Sistema</td>
                                <td class="text-end"><?php echo formatCurrency($session['initial_amount'] + $actual_sales - $total_expenses); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Income Details -->
            <div class="col-6">
                <h6 class="fw-bold mb-3">Ingresos por Categoría</h6>
                <table class="table table-sm table-striped border">
                    <thead class="bg-light">
                        <tr>
                            <th>Categoría</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($income_by_category)): ?>
                        <tr><td colspan="2" class="text-center text-muted">Sin movimientos</td></tr>
                        <?php else: ?>
                            <?php foreach($income_by_category as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['category_name'] ?? 'Sin categoría'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Expense Details -->
            <div class="col-6">
                <h6 class="fw-bold mb-3">Egresos por Categoría</h6>
                <table class="table table-sm table-striped border">
                    <thead class="bg-light">
                        <tr>
                            <th>Categoría</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($expenses_by_category)): ?>
                        <tr><td colspan="2" class="text-center text-muted">Sin movimientos</td></tr>
                        <?php else: ?>
                            <?php foreach($expenses_by_category as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['category_name'] ?? 'Sin categoría'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cash Count / Arqueo -->
        <div class="row mt-4">
            <div class="col-12">
                <h5 class="border-bottom pb-2 mb-3">Arqueo de Caja (Conteo Físico)</h5>
            </div>
            
            <?php if(!empty($denominations)): ?>
            <div class="col-md-6">
                <h6 class="text-muted mb-2">Desglose de Efectivo</h6>
                <table class="table table-sm table-bordered">
                    <thead class="bg-light">
                        <tr>
                            <th class="text-end">Denominación</th>
                            <th class="text-center">Cant.</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($denominations as $denom): ?>
                        <tr>
                            <td class="text-end"><?php echo formatCurrency($denom['denomination_value']); ?></td>
                            <td class="text-center"><?php echo $denom['quantity']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($denom['subtotal']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold bg-light">
                            <td colspan="2" class="text-end">Total Efectivo</td>
                            <td class="text-end"><?php echo formatCurrency($session['total_cash']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="col-md-<?php echo !empty($denominations) ? '6' : '12'; ?>">
                <h6 class="text-muted mb-2">Totales Reportados</h6>
                <table class="table table-sm table-bordered">
                    <tr>
                        <td>Efectivo (Físico)</td>
                        <td class="text-end"><?php echo formatCurrency($session['total_cash']); ?></td>
                    </tr>
                    <tr>
                        <td>Transferencias</td>
                        <td class="text-end"><?php echo formatCurrency($session['total_transfer']); ?></td>
                    </tr>
                    <tr>
                        <td>Tarjetas</td>
                        <td class="text-end"><?php echo formatCurrency($session['total_card']); ?></td>
                    </tr>
                    <tr>
                        <td>Otros</td>
                        <td class="text-end"><?php echo formatCurrency($session['total_other']); ?></td>
                    </tr>
                    <tr class="table-dark">
                        <td>TOTAL FÍSICO</td>
                        <td class="text-end"><?php echo formatCurrency($session['physical_count']); ?></td>
                    </tr>
                </table>

                <div class="alert alert-<?php echo ($session['difference'] == 0) ? 'success' : 'danger'; ?> mt-3 mb-0 text-center">
                    <h6 class="mb-0">DIFERENCIA: <?php echo formatCurrency($session['difference']); ?></h6>
                </div>
            </div>
        </div>
        
        <?php if(!empty($session['observations'])): ?>
        <div class="row mt-4">
            <div class="col-12">
                <h6 class="fw-bold">Observaciones:</h6>
                <p class="border p-2 bg-light rounded"><?php echo nl2br(htmlspecialchars($session['observations'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="row mt-5 pt-5">
            <div class="col-6 text-center">
                <div class="border-top border-dark w-75 mx-auto pt-2">
                    <?php echo htmlspecialchars($session['opened_by_name']); ?><br>
                    <small class="text-muted">Apertura</small>
                </div>
            </div>
            <div class="col-6 text-center">
                <div class="border-top border-dark w-75 mx-auto pt-2">
                    <?php echo htmlspecialchars($session['closed_by_name']); ?><br>
                    <small class="text-muted">Cierre</small>
                </div>
            </div>
        </div>

        <div class="text-center text-muted mt-5 small no-print">
            Generado el <?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>
    <?php if (!$embedded): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <?php endif; ?>
    <script>
        async function downloadClosingPDF() {
            const { jsPDF } = window.jspdf;
            const el = document.querySelector('.page-container');
            try {
                const canvas = await html2canvas(el, {
                    scale: 4,
                    backgroundColor: '#ffffff',
                    useCORS: true,
                    logging: false
                });
                const imgData = canvas.toDataURL('image/jpeg', 0.98);
                const pdf = new jsPDF('p', 'mm', 'letter');
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const imgProps = pdf.getImageProperties(imgData);
                const pdfWidth = pageWidth;
                const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
                pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
                pdf.save('Cierre_Caja_<?php echo htmlspecialchars($session['session_number']); ?>.pdf');
            } catch (err) {
                console.error(err);
                alert('No se pudo generar el PDF');
            }
        }
    </script>
<?php if (!$embedded): ?>
</body>
</html>
<?php else: ?>
<?php $page_content = ob_get_clean(); require_once '../includes/page_template.php'; ?>
<?php endif; ?>
