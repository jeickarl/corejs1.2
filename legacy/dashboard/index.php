<?php
require_once '../config/init_app.php';

// Verificar autenticación
requireAuth();
debugLog('dashboard:start');

// Registrar actividad
logActivity($_SESSION['user_id'], 'VIEW_DASHBOARD', null, null);

// Configuración del template
$page_title = 'Panel Principal';
$additional_css = ['../assets/css/calendar.css'];
$additional_js = ['https://cdn.jsdelivr.net/npm/chart.js'];

// Obtener Tenant ID
$tenant_id = getCurrentTenantId();

// Lógica de Saludo
$hour = date('H');
$greeting = 'Bienvenido';
$icon = 'fa-sun';
if ($hour < 12) {
    $greeting = 'Buenos días';
    $icon = 'fa-coffee';
}
else if ($hour < 18) {
    $greeting = 'Buenas tardes';
    $icon = 'fa-sun';
}
else {
    $greeting = 'Buenas noches';
    $icon = 'fa-moon';
}

// Funciones auxiliares para gráficas
function getSalesChartData($pdo, $days = 7, $offset = 0)
{
    try {
        $tenant_id = getCurrentTenantId();
        $tenantValue = function_exists('isPerDatabaseMode') && isPerDatabaseMode() ? 1 : (int)$tenant_id;
        $hasTenantInvoices = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'invoices') : false;
        $intervalStart = $days + $offset;
        $intervalEnd = $offset;

        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_revenue
            FROM invoices 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $intervalStart DAY) 
              AND created_at < DATE_SUB(CURDATE(), INTERVAL $intervalEnd DAY)
              AND status != 'cancelled'
              " . ($hasTenantInvoices ? "AND tenant_id = ?" : "") . "
            GROUP BY DATE(created_at)
            ORDER BY sale_date
        ");
        $stmt->execute($hasTenantInvoices ? [$tenantValue] : []);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $checkDay = $i + $offset;
            $date = date('Y-m-d', strtotime("-$checkDay days"));
            $found = false;
            foreach ($results as $row) {
                if ($row['sale_date'] == $date) {
                    $labels[] = date('d/m', strtotime($date));
                    $values[] = floatval($row['daily_revenue']);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $labels[] = date('d/m', strtotime($date));
                $values[] = 0;
            }
        }
        return ['labels' => $labels, 'values' => $values];
    }
    catch (Exception $e) {
        return ['labels' => [], 'values' => []];
    }
}

function getOrdersChartData($pdo)
{
    try {
        $tenant_id = getCurrentTenantId();
        $tenantValue = function_exists('isPerDatabaseMode') && isPerDatabaseMode() ? 1 : (int)$tenant_id;
        $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM work_orders 
            " . ($hasTenantWorkOrders ? "WHERE tenant_id = ?" : "") . "
            GROUP BY status
        ");
        $stmt->execute($hasTenantWorkOrders ? [$tenantValue] : []);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $values = [];
        $colors = [];

        $statusColors = [
            'received' => '#6610f2', // Indigo
            'pending' => '#ffc107', // Yellow
            'diagnosing' => '#0dcaf0', // Cyan
            'waiting_parts' => '#fd7e14', // Orange (Distinct from delivered)
            'repairing' => '#0d6efd', // Blue
            'testing' => '#20c997', // Teal
            'completed' => '#198754', // Green
            'delivered' => '#6c757d', // Gray
            'cancelled' => '#dc3545' // Red
        ];

        foreach ($results as $row) {
            $labels[] = getStatusText($row['status']);
            $values[] = intval($row['count']);
            $colors[] = $statusColors[$row['status']] ?? '#6c757d';
        }
        return ['labels' => $labels, 'values' => $values, 'colors' => $colors];
    }
    catch (Exception $e) {
        return ['labels' => [], 'values' => [], 'colors' => []];
    }
}

// Obtener estadísticas avanzadas (BI)
try {
    // 1. Estadísticas Generales
    $stmt = $pdo->prepare("SELECT 
        (SELECT COUNT(*) FROM work_orders WHERE status != 'cancelled' AND tenant_id = ?) as total_orders,
        (SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('completed', 'delivered', 'cancelled') AND tenant_id = ?) as pending_orders,
        (SELECT COUNT(*) FROM clients WHERE tenant_id = ?) as total_clients
    ");
    $stmt->execute([$tenant_id, $tenant_id, $tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Revenue Data (Day, Week, Month, Total)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status NOT IN ('cancelled', 'draft') AND DATE(created_at) = CURDATE() AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_day = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status NOT IN ('cancelled', 'draft') AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_week = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status NOT IN ('cancelled', 'draft') AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_month = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status NOT IN ('cancelled', 'draft') AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_total = $stmt->fetchColumn();

    // Default to Month for display
    $totalSales = $revenue_month;

    // 2. Cálculo de Tendencias (Semana Actual vs Semana Anterior)
    // Órdenes
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $orders_current = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $orders_prev = $stmt->fetchColumn();

    $orders_trend = ($orders_prev > 0) ? (($orders_current - $orders_prev) / $orders_prev) * 100 : 100;

    // Ventas
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $sales_current = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $sales_prev = $stmt->fetchColumn() ?: 0;

    $sales_trend = ($sales_prev > 0) ? (($sales_current - $sales_prev) / $sales_prev) * 100 : 100;

    // 3. Alertas de Stock Bajo
    $stmt = $pdo->prepare("
        SELECT name, current_stock, min_stock 
        FROM inventory_products 
        WHERE current_stock <= min_stock AND is_active = 1 AND tenant_id = ?
        ORDER BY current_stock ASC 
        LIMIT 5
    ");
    $stmt->execute([$tenant_id]);
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Órdenes Prioritarias y Estancadas
    // 4. Órdenes Estancadas (Más de 3 días y no completadas/entregadas)
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, c.first_name, c.company_name, o.device_model, o.status, 
               DATEDIFF(NOW(), o.created_at) as days_open, o.priority,
               (SELECT GROUP_CONCAT(ea.name SEPARATOR ', ') 
                FROM order_equipment_accessories oea 
                JOIN equipment_accessories ea ON oea.accessory_id = ea.id 
                WHERE oea.order_id = o.id AND oea.is_included = 1) as accessories
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id
        WHERE o.status NOT IN ('completed', 'delivered', 'cancelled')
          AND DATEDIFF(NOW(), o.created_at) > 3
          AND o.tenant_id = ?
        ORDER BY days_open DESC LIMIT 5
    ");
    $stmt->execute([$tenant_id]);
    $stagnantOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Órdenes Recientes
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, c.first_name, c.company_name, o.device_brand, o.device_model, o.status, o.created_at,
               (SELECT GROUP_CONCAT(ea.name SEPARATOR ', ') 
                FROM order_equipment_accessories oea 
                JOIN equipment_accessories ea ON oea.accessory_id = ea.id 
                WHERE oea.order_id = o.id AND oea.is_included = 1) as accessories
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id
        WHERE o.tenant_id = ?
        ORDER BY o.created_at DESC LIMIT 5
    ");
    $stmt->execute([$tenant_id]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Órdenes Listas para Entregar (Completadas pero no Entregadas)
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, c.first_name, c.company_name, c.phone, o.device_brand, o.device_model, 
               o.completed_date as completed_at, COALESCE(o.final_cost, o.estimated_cost, 0) as total_amount,
               (SELECT GROUP_CONCAT(ea.name SEPARATOR ', ') 
                FROM order_equipment_accessories oea 
                JOIN equipment_accessories ea ON oea.accessory_id = ea.id 
                WHERE oea.order_id = o.id AND oea.is_included = 1) as accessories
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id AND c.tenant_id = o.tenant_id
        WHERE o.status = 'completed' AND o.tenant_id = ?
        ORDER BY o.completed_date ASC
    ");
    $stmt->execute([$tenant_id]);
    $readyOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Datos gráficas
    $salesChartData = getSalesChartData($pdo, 7, 0); // Current 7 days
    $salesChartDataPrev = getSalesChartData($pdo, 7, 7); // Previous 7 days

    // Calculate KPIs
    $vals = $salesChartData['values'];
    $totalPeriod = array_sum($vals);
    $avgDaily = count($vals) > 0 ? $totalPeriod / count($vals) : 0;
    $maxDaily = count($vals) > 0 ? max($vals) : 0;

    $ordersChartData = getOrdersChartData($pdo);
    debugLog('dashboard:queries_ok');

}
catch (Exception $e) {
    debugLog('dashboard:error ' . $e->getMessage());
    $stats = ['total_orders' => 0, 'pending_orders' => 0, 'total_clients' => 0];
    $totalSales = 0;
    $orders_trend = 0;
    $sales_trend = 0;
    $lowStockItems = [];
    $stagnantOrders = [];
    $recentOrders = [];
    $readyOrders = [];
    $totalPeriod = 0;
    $avgDaily = 0;
    $maxDaily = 0;
    $salesChartData = ['labels' => [], 'values' => []];
    $ordersChartData = ['labels' => [], 'values' => [], 'colors' => []];
}

ob_start();
debugLog('dashboard:render_start');
?>

<!-- Header & Global Search -->
<div class="d-flex flex-column flex-md-row justify-content-start align-items-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">
            <i class="fas <?php echo $icon; ?> text-warning me-2"></i><?php echo $greeting; ?>, <?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]); ?>
        </h2>
        <p class="text-muted mb-0">Resumen inteligente de tu negocio.</p>
    </div>
</div>

<style>
/* Let Bootstrap handle the row columns natively without custom raw CSS flex constraints */
#dashboard-main-row .card-modern { margin-bottom: 1rem; }
.card-modern { position: relative; }
.nav-tabs { margin-top: .25rem; }
#redundant-row { display: none; }
@media (max-width: 768px) {
    .card-modern {
        margin-left: 14px;
        margin-right: 14px;
    }
}
</style>

<!-- KPI Cards con BI (Trends) -->
<div class="row g-3 mb-4">
    <!-- Órdenes -->
    <div class="col-12 col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-primary-soft">
            <div class="card-body p-3 p-xl-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="text-primary no-theme fw-bold text-uppercase small ls-1 mb-1" style="font-size: 0.75rem;">Órdenes Totales</p>
                        <h2 class="fw-bold text-dark mb-0 counter" data-target="<?php echo $stats['total_orders']; ?>">0</h2>
                    </div>
                    <div class="icon-box bg-white shadow-sm text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fas fa-tools fa-lg"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center mt-auto">
                    <?php if ($orders_trend >= 0): ?>
                        <span class="trend-badge trend-up me-2 small"><i class="fas fa-arrow-up"></i> <?php echo number_format($orders_trend, 1); ?>%</span>
                    <?php
else: ?>
                        <span class="trend-badge trend-down me-2 small"><i class="fas fa-arrow-down"></i> <?php echo number_format(abs($orders_trend), 1); ?>%</span>
                    <?php
endif; ?>
                    <small class="text-muted d-none d-sm-inline" style="font-size: 0.7rem;">vs ant.</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Ventas -->
    <div class="col-12 col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-success-soft">
            <div class="card-body p-3 p-xl-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="pe-2">
                        <div class="d-flex align-items-center mb-1 flex-wrap gap-1">
                            <p class="text-success fw-bold text-uppercase small ls-1 mb-0 me-2" style="font-size: 0.75rem;">Ingresos</p>
                            <select id="revenueFilter" class="form-select border-0 bg-transparent text-success fw-bold p-0 text-decoration-underline shadow-none" style="font-size: 0.75rem; width: auto; min-width: 60px; max-width: 100px; cursor: pointer; display: inline-block;">
                                <option value="today">Hoy</option>
                                <option value="week">Semana</option>
                                <option value="month" selected>Mensual</option>
                                <option value="year">Año</option>
                                <option value="total">Total</option>
                            </select>
                        </div>
                        <h3 class="fw-bold text-dark mb-0" id="revenueDisplay" style="font-size: 1.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;" title="<?php echo htmlspecialchars(formatCurrency($totalSales)); ?>"><?php echo formatCurrency($totalSales); ?></h3>
                        <!-- Hidden values for JS -->
                        <input type="hidden" id="rev-day" value="<?php echo formatCurrency($revenue_day); ?>">
                        <input type="hidden" id="rev-week" value="<?php echo formatCurrency($revenue_week); ?>">
                        <input type="hidden" id="rev-month" value="<?php echo formatCurrency($revenue_month); ?>">
                        <input type="hidden" id="rev-total" value="<?php echo formatCurrency($revenue_total); ?>">
                    </div>
                    <div class="icon-box bg-white shadow-sm text-success rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; flex-shrink: 0;">
                        <i class="fas fa-dollar-sign fa-lg"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center mt-auto">
                    <?php if ($sales_trend >= 0): ?>
                        <span class="trend-badge trend-up me-2 small"><i class="fas fa-arrow-up"></i> <?php echo number_format($sales_trend, 1); ?>%</span>
                    <?php
else: ?>
                        <span class="trend-badge trend-down me-2 small"><i class="fas fa-arrow-down"></i> <?php echo number_format(abs($sales_trend), 1); ?>%</span>
                    <?php
endif; ?>
                    <small class="text-muted d-none d-sm-inline" style="font-size: 0.7rem;">vs ant.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Pendientes -->
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-warning-soft">
            <div class="card-body p-3 p-xl-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="text-warning fw-bold text-uppercase small ls-1 mb-1" style="font-size: 0.75rem;">En Taller</p>
                        <h2 class="fw-bold text-dark mb-0 counter" data-target="<?php echo $stats['pending_orders']; ?>">0</h2>
                    </div>
                    <div class="icon-box bg-white shadow-sm text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fas fa-clock fa-lg"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center text-muted mt-auto" style="font-size: 0.8rem;">
                    <span class="text-dark fw-bold me-1"><?php echo count($stagnantOrders); ?></span>
                    <span>urgentes</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="dashboard-main-row" class="row g-4 mb-4 align-items-start">
    <!-- Gráfico Principal -->
    <div class="col-12 col-md-8 col-left">
        <div class="card-modern mb-4">
            <div class="card-header border-0 d-flex flex-column flex-xl-row justify-content-between align-items-center gap-3 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-primary bg-opacity-10 no-theme p-2 rounded">
                        <i class="fas fa-chart-line text-primary"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-dark">Rendimiento Financiero</h5>
                </div>
                
                <div class="d-flex align-items-center flex-wrap gap-3 justify-content-center justify-content-xl-end w-100 w-xl-auto">
                    <!-- KPIs -->
                    <div class="d-flex gap-3 gap-md-4 text-muted small border-end pe-3">
                        <div class="text-end">
                            <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Total</span>
                            <span class="fw-bold text-primary" id="kpiTotal"><?php echo formatCurrency($totalPeriod); ?></span>
                        </div>
                        <div class="text-end d-none d-sm-block">
                            <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Promedio</span>
                            <span class="fw-bold text-dark" id="kpiAvg"><?php echo formatCurrency($avgDaily); ?></span>
                        </div>
                        <div class="text-end d-none d-sm-block">
                            <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Mejor Día</span>
                            <span class="fw-bold text-success" id="kpiMax"><?php echo formatCurrency($maxDaily); ?></span>
                        </div>
                    </div>
                    
                    <!-- Controls -->
                    <div class="d-flex align-items-center gap-3 bg-light rounded-pill px-3 py-1 border">
                        <select class="form-select form-select-sm border-0 bg-transparent fw-bold text-secondary focus-ring-none py-1 ps-0 pe-4" style="width: auto; cursor: pointer; font-size: 0.85rem;" id="chartRange">
                            <option value="7">Últimos 7 Días</option>
                            <option value="15">Últimos 15 Días</option>
                            <option value="30">Últimos 30 Días</option>
                            <option value="90">Últimos 3 Meses</option>
                        </select>
                        
                        <div class="vr h-50 my-auto text-muted opacity-25"></div>
                        
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-2 ps-0">
                            <label class="form-check-label small text-muted fw-bold pt-1" for="compareToggle" style="font-size: 0.8rem; cursor: pointer; white-space: nowrap;">Comparar</label>
                            <input class="form-check-input m-0" type="checkbox" id="compareToggle" style="cursor: pointer; width: 32px; height: 18px;">
                        </div>
                    </div>

                    <!-- Actions -->
                    <button class="btn btn-sm btn-light text-muted hover-primary" id="downloadChartBtn" title="Descargar Gráfico">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <canvas id="salesChart" height="300"></canvas>
            </div>
        </div>

        <!-- Estado de Órdenes (Moved from Right Col) -->
        <div class="card-modern">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-chart-pie text-primary no-theme me-2"></i>Estado de Órdenes</h5>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-12 col-md-5 mb-4 mb-md-0" style="min-height: 250px;">
                         <canvas id="ordersChart"></canvas>
                    </div>
                    <div class="col-12 col-md-7">
                        <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-start" id="ordersLegend">
                            <!-- Custom Legend populated by JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            /* Modern Pill Tabs for Dashboard */
            #myTab.custom-pills {
                gap: 0.5rem;
                padding-bottom: 0.5rem;
            }
            #myTab.custom-pills .nav-link {
                color: var(--bs-secondary);
                background-color: transparent;
                border-radius: 50rem;
                font-weight: 600;
                padding: 0.6rem 1.2rem;
                transition: all 0.2s ease;
                border: 1px solid transparent;
            }
            #myTab.custom-pills .nav-link:hover {
                background-color: rgba(0,0,0,0.03);
            }
            #myTab.custom-pills .nav-link.active {
                color: #fff;
                background-color: #0d6efd; /* Primary Blue */
                box-shadow: 0 4px 10px rgba(13, 110, 253, 0.25);
            }
            /* Dark mode text for active pills if primary is too light, but primary is usually dark enough */
        </style>
        
        <!-- Tablas de Datos (Restauradas) -->
        <div class="card-modern mt-4 w-100 position-relative" style="overflow: hidden;">
            <div class="card-header border-0 pb-2 pt-3 shadow-none bg-transparent px-0 w-100">
                <ul class="nav nav-pills custom-pills flex-nowrap overflow-auto px-3 w-100" id="myTab" role="tablist" style="scrollbar-width: none;">
                    <li class="nav-item text-nowrap" role="presentation">
                        <button class="nav-link active" id="recent-tab" data-bs-toggle="pill" data-bs-target="#recent" type="button" role="tab">Recientes</button>
                    </li>
                    <li class="nav-item text-nowrap" role="presentation">
                        <button class="nav-link" id="urgent-tab" data-bs-toggle="pill" data-bs-target="#urgent" type="button" role="tab">
                            Atención Urgente 
                            <?php if (count($stagnantOrders) > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1"><?php echo count($stagnantOrders); ?></span>
                            <?php
endif; ?>
                        </button>
                    </li>
                    <li class="nav-item text-nowrap" role="presentation">
                        <button class="nav-link" id="ready-tab" data-bs-toggle="pill" data-bs-target="#ready" type="button" role="tab">
                            Listos para Entregar
                            <?php if (count($readyOrders) > 0): ?>
                                <span class="badge bg-white text-primary rounded-pill ms-1 shadow-sm"><?php echo count($readyOrders); ?></span>
                            <?php
endif; ?>
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-3 p-md-4 bg-light rounded-bottom-4">
                <div class="tab-content w-100 overflow-hidden" id="myTabContent">
                    <div class="tab-pane fade show active" id="recent" role="tabpanel">
                        <!-- Desktop View -->
                        <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                            <table class="table table-hover align-middle mb-0 text-nowrap">
                                <thead class="small text-uppercase bg-light">
                                    <tr>
                                        <th class="ps-4">ID</th>
                                        <th>Cliente</th>
                                        <th>Dispositivo</th>
                                        <th>Estado</th>
                                        <th class="text-end pe-4">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold">#<?php echo $order['id']; ?></td>
                                            <td><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></td>
                                            <td class="text-muted">
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
                                                <?php if (!empty($order['accessories'])): ?>
                                                    <small class="text-primary"><i class="fas fa-plug fa-xs me-1"></i><?php echo htmlspecialchars($order['accessories']); ?></small>
                                                <?php
    else: ?>
                                                    <small class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                                                <?php
    endif; ?>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?php echo getStatusText($order['status']); ?></span></td>
                                            <td class="text-end pe-4"><a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-light rounded-circle"><i class="fas fa-eye"></i></a></td>
                                        </tr>
                                    <?php
endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile View -->
                        <div class="d-block d-lg-none">
                            <div class="row g-3">
                                <?php foreach ($recentOrders as $order): ?>
                                    <div class="col-12">
                                        <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <?php $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id']; $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD'; ?>
                                                    <span class="fw-bold fs-6"><?php echo htmlspecialchars($prefix) . '-' . str_pad($num, 4, '0', STR_PAD_LEFT); ?></span>
                                                    <span class="badge bg-light text-dark border"><?php echo getStatusText($order['status']); ?></span>
                                                </div>
                                                <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></div>
                                                <div class="text-muted small mb-2">
                                                    <i class="fas fa-mobile-alt me-1"></i> <?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?>
                                                </div>
                                                <?php if (!empty($order['accessories'])): ?>
                                                    <div class="small text-primary mb-3"><i class="fas fa-plug fa-xs me-1"></i><?php echo htmlspecialchars($order['accessories']); ?></div>
                                                <?php
    else: ?>
                                                    <div class="small text-muted mb-3"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</div>
                                                <?php
    endif; ?>
                                                <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary w-100 shadow-sm custom-btn-responsive rounded-pill">Ver Detalles</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php
endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="urgent" role="tabpanel">
                         <!-- Desktop View -->
                         <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                            <table class="table table-hover align-middle mb-0 text-nowrap">
                                <thead class="small text-uppercase bg-light">
                                    <tr>
                                        <th class="ps-4">ID</th>
                                        <th>Cliente</th>
                                        <th>Dispositivo</th>
                                        <th>Estado</th>
                                        <th>Tiempo</th>
                                        <th class="text-end pe-4">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stagnantOrders)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">¡Todo al día! No hay órdenes estancadas.</td></tr>
                                    <?php
else: ?>
                                        <?php foreach ($stagnantOrders as $order): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold">
                                                <?php $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id']; $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD'; ?>
                                                <?php echo htmlspecialchars($prefix) . '-' . str_pad($num, 4, '0', STR_PAD_LEFT); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></td>
                                            <td class="text-dark">
                                                <div class="fw-bold"><?php echo htmlspecialchars($order['device_model']); ?></div>
                                                <?php if (!empty($order['accessories'])): ?>
                                                    <small class="text-primary"><i class="fas fa-plug fa-xs me-1"></i><?php echo htmlspecialchars($order['accessories']); ?></small>
                                                <?php
        else: ?>
                                                    <small class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                                                <?php
        endif; ?>
                                            </td>
                                            <td><span class="badge bg-warning text-dark"><?php echo getStatusText($order['status']); ?></span></td>
                                            <td class="fw-bold">
                                                <?php if (in_array($order['priority'] ?? '', ['high', 'urgent'])): ?>
                                                    <span class="badge bg-danger"><i class="fas fa-fire me-1"></i>URGENTE</span>
                                                <?php
        else: ?>
                                                    <span class="text-danger"><i class="far fa-clock me-1"></i> <?php echo $order['days_open']; ?> días</span>
                                                <?php
        endif; ?>
                                            </td>
                                            <td class="text-end pe-4"><a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill">Gestionar</a></td>
                                        </tr>
                                        <?php
    endforeach; ?>
                                    <?php
endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile View -->
                        <div class="d-block d-lg-none">
                            <?php if (empty($stagnantOrders)): ?>
                                <div class="text-center py-4 text-muted bg-white rounded-4 shadow-sm">
                                    ¡Todo al día! No hay órdenes estancadas.
                                </div>
                            <?php
else: ?>
                                <div class="row g-3">
                                    <?php foreach ($stagnantOrders as $order): ?>
                                        <div class="col-12">
                                            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                                                <div class="card-body p-3">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <?php $num = isset($order['order_number']) && (int)$order['order_number'] > 0 ? (int)$order['order_number'] : (int)$order['id']; $prefix = function_exists('getCompanyPrefix') ? getCompanyPrefix() : 'ORD'; ?>
                                                        <span class="fw-bold fs-6"><?php echo htmlspecialchars($prefix) . '-' . str_pad($num, 4, '0', STR_PAD_LEFT); ?></span>
                                                        <span class="badge bg-warning text-dark"><?php echo getStatusText($order['status']); ?></span>
                                                    </div>
                                                    <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></div>
                                                    
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <div class="text-muted small">
                                                            <i class="fas fa-mobile-alt me-1"></i> <?php echo htmlspecialchars($order['device_model']); ?>
                                                        </div>
                                                        <div class="fw-bold">
                                                            <?php if (in_array($order['priority'] ?? '', ['high', 'urgent'])): ?>
                                                                <span class="badge bg-danger"><i class="fas fa-fire me-1"></i>URGENTE</span>
                                                            <?php
        else: ?>
                                                                <span class="text-danger small"><i class="far fa-clock me-1"></i> <?php echo $order['days_open']; ?> días</span>
                                                            <?php
        endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-danger w-100 shadow-sm rounded-pill mt-2">Gestionar Orden</a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php
    endforeach; ?>
                                </div>
                            <?php
endif; ?>
                        </div>
                    </div>
                    
                    <div class="tab-pane fade" id="ready" role="tabpanel">
                         <!-- Desktop View -->
                         <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                            <table class="table table-hover align-middle mb-0 text-nowrap">
                                <thead class="small text-uppercase bg-light">
                                    <tr>
                                        <th class="ps-4">Cliente / Teléfono</th>
                                        <th>Dispositivo</th>
                                        <th>Terminado</th>
                                        <th class="text-end">Por Cobrar</th>
                                        <th class="text-end pe-4">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($readyOrders)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No hay equipos pendientes de entrega.</td></tr>
                                    <?php
else: ?>
                                        <?php foreach ($readyOrders as $order): ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold"><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></div>
                                                    <small class="text-muted"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($order['phone']); ?></small>
                                                </td>
                                                <td class="text-dark">
                                                    <div class="fw-bold"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
                                                    <?php if (!empty($order['accessories'])): ?>
                                                        <small class="text-primary"><i class="fas fa-plug fa-xs me-1"></i><?php echo htmlspecialchars($order['accessories']); ?></small>
                                                    <?php
        else: ?>
                                                        <small class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                                                    <?php
        endif; ?>
                                                </td>
                                                <td class="text-muted small">
                                                    <i class="far fa-check-circle text-success me-1"></i>
                                                    <?php echo date('d/m H:i', strtotime($order['completed_at'])); ?>
                                                </td>
                                                <td class="text-end fw-bold text-success"><?php echo formatCurrency($order['total_amount']); ?></td>
                                                <td class="text-end pe-4">
                                                    <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                                        <i class="fas fa-check me-1"></i> Entregar
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php
    endforeach; ?>
                                    <?php
endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile View -->
                        <div class="d-block d-lg-none">
                            <?php if (empty($readyOrders)): ?>
                                <div class="text-center py-4 text-muted bg-white rounded-4 shadow-sm">
                                    No hay equipos pendientes de entrega.
                                </div>
                            <?php
else: ?>
                                <div class="row g-3">
                                    <?php foreach ($readyOrders as $order): ?>
                                        <div class="col-12">
                                            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-success" style="border-left: 4px solid #198754 !important;">
                                                <div class="card-body p-3">
                                                    <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($order['company_name'] ?: $order['first_name']); ?></div>
                                                    <div class="text-muted small mb-2"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($order['phone']); ?></div>
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="text-dark small"><i class="fas fa-mobile-alt me-1"></i> <?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <span class="text-muted small"><i class="far fa-check-circle text-success me-1"></i><?php echo date('d/m H:i', strtotime($order['completed_at'])); ?></span>
                                                        <span class="fw-bold text-success"><?php echo formatCurrency($order['total_amount']); ?></span>
                                                    </div>
                                                    <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-success w-100 shadow-sm rounded-pill">
                                                        <i class="fas fa-check me-1"></i> Entregar
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php
    endforeach; ?>
                                </div>
                            <?php
endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Widgets de Productividad (Notas + Calendario) -->
    <div class="col-12 col-md-4 col-right" style="position: sticky; top: 110px; z-index: 2;">
        <div class="d-flex flex-column gap-4 h-100">
            <!-- Notas Rápidas -->
            <div class="flex-grow-1 card-modern overflow-hidden d-flex flex-column" style="min-height: 400px;">
                <div class="card-header border-0 d-flex justify-content-between align-items-center bg-warning bg-opacity-10">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-sticky-note text-warning me-2"></i>Notas Personales</h6>
                        <button class="btn btn-sm btn-link text-dark p-0 opacity-50 hover-opacity-100" onclick="insertBullet()" title="Insertar lista" style="transition: opacity 0.2s;">
                            <i class="fas fa-list-ul"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="noteStatus"><i class="far fa-check-circle"></i> Sincronizado</small>
                </div>
                <div class="card-body p-0 d-flex flex-column flex-grow-1">
                    <div class="notepad-container p-3 flex-grow-1">
                        <textarea class="form-control border-0 h-100 p-0 notepad-textarea" id="quickNotes" placeholder="Escribe tus notas aquí..." style="resize: none;"></textarea>
                    </div>
                </div>
            </div>
            <!-- Calendario Widget -->
            <div class="card-modern">
                <div class="calendar-widget" id="dashboardCalendar">
                    <!-- JS will render calendar here -->
                </div>
            </div>
            
            <div class="card-modern h-100">
                <div class="card-header border-0">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Stock Bajo</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($lowStockItems)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-check-circle fa-3x text-success mb-3 opacity-50"></i>
                            <p>Inventario saludable</p>
                        </div>
                    <?php
else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($lowStockItems as $item): ?>
                                <li class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center border-light">
                                    <div>
                                        <h6 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($item['name']); ?></h6>
                                        <small class="text-muted">Mínimo: <?php echo $item['min_stock']; ?></small>
                                    </div>
                                    <span class="badge bg-danger rounded-pill px-3 py-2">Quedan: <?php echo $item['current_stock']; ?></span>
                                </li>
                            <?php
    endforeach; ?>
                        </ul>
                        <div class="p-3 text-center border-top">
                            <a href="../inventory/index.php?stock=low" class="btn btn-sm btn-light text-primary no-theme fw-bold w-100">Ver reporte completo</a>
                        </div>
                    <?php
endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- FAB -->
<div class="fab-container">
    <div class="fab-menu" id="fabMenu">
        <a href="../orders/new.php" class="fab-item">
            <span>Nueva Orden</span>
            <div class="icon-box bg-primary text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-plus"></i></div>
        </a>
        <a href="../billing/new.php" class="fab-item">
            <span>Crear Venta</span>
            <div class="icon-box bg-success text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-file-invoice-dollar"></i></div>
        </a>
        <a href="../clients/new.php" class="fab-item">
            <span>Nuevo Cliente</span>
            <div class="icon-box bg-info text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-user-plus"></i></div>
        </a>
    </div>
    <div class="fab-button" onclick="toggleFab()">
        <i class="fas fa-plus" id="fabIcon"></i>
    </div>
</div>

<script>
// Revenue Filter Logic
function updateRevenueDisplay(period) {
    const display = document.getElementById('revenueDisplay');
    const value = document.getElementById('rev-' + period).value;
    display.innerText = value;
}

// FAB
function toggleFab() {
    const menu = document.getElementById('fabMenu');
    const icon = document.getElementById('fabIcon');
    menu.classList.toggle('active');
    
    if (menu.classList.contains('active')) {
        icon.classList.remove('fa-plus');
        icon.classList.add('fa-times');
    } else {
        icon.classList.remove('fa-times');
        icon.classList.add('fa-plus');
    }
}

// Live Search Logic
const searchInput = document.getElementById('searchQuery');
const searchType = document.getElementById('searchType');
const searchResults = document.getElementById('searchResults');
// Cerrar FAB al hacer click fuera
document.addEventListener('click', function(e) {
    const container = document.querySelector('.fab-container');
    const menu = document.getElementById('fabMenu');
    if (!container.contains(e.target) && menu.classList.contains('active')) {
        toggleFab();
    }
});

// Notas Rápidas (DB Persistence)
document.addEventListener('DOMContentLoaded', function() {
    const notes = document.getElementById('quickNotes');
    const status = document.getElementById('noteStatus');
    
    // Cargar notas desde el servidor
    fetch('ajax_notes.php', { headers: { 'Accept': 'application/json' } })
        .then(window.parseJsonResponse)
        .then(data => {
            if(data.content) {
                notes.value = data.content;
            }
        })
        .catch(err => console.error('Error cargando notas:', err));
    
    let timeout;
    notes.addEventListener('input', () => {
        status.innerHTML = '<i class="fas fa-sync fa-spin"></i> Guardando...';
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            fetch('ajax_notes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ content: notes.value })
            })
            .then(window.parseJsonResponse)
            .then(data => {
                status.innerHTML = '<i class="fas fa-check-circle text-success"></i> Guardado ' + data.timestamp;
            })
            .catch(err => {
                console.error('Error guardando:', err);
                status.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Error';
            });
        }, 1000); // 1 segundo debounce
    });

    // Helper for notes
    window.insertBullet = function() {
        const textarea = document.getElementById('quickNotes');
        if (!textarea) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        textarea.value = text.substring(0, start) + 'â?¢ ' + text.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + 2;
        textarea.focus();
        textarea.dispatchEvent(new Event('input'));
    };

    // Counters Animation
    const counters = document.querySelectorAll('.counter');
    counters.forEach(counter => {
        const target = +counter.getAttribute('data-target');
        const duration = 1500; 
        const increment = target / (duration / 16); 
        
        if (target === 0) {
            counter.innerText = '0';
            return;
        }

        let current = 0;
        const updateCounter = () => {
            current += increment;
            if (current < target) {
                counter.innerText = Math.ceil(current);
                requestAnimationFrame(updateCounter);
            } else {
                counter.innerText = target;
            }
        };
        updateCounter();
    });

    // Inicializar Gráficas (Versión simplificada para el nuevo layout)
    const salesCanvas = document.getElementById('salesChart');
    const salesCtx = salesCanvas.getContext('2d');
    
    // Gradient para un look moderno
    const gradientSales = salesCtx.createLinearGradient(0, 0, 0, 300);
    gradientSales.addColorStop(0, 'rgba(13, 110, 253, 0.25)'); 
    gradientSales.addColorStop(1, 'rgba(13, 110, 253, 0.0)');

    if (typeof Chart === 'undefined') {
        const container = salesCanvas.parentElement;
        container.innerHTML = `
            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                <i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i>
                <p class="mb-0 small">No se pudo cargar la librería de gráficos</p>
            </div>
        `;
    } else {
    const currencyCode = (window.SYSTEM_CONFIG && window.SYSTEM_CONFIG.currency && window.SYSTEM_CONFIG.currency.code) ? window.SYSTEM_CONFIG.currency.code : 'USD';
    const currencySymbol = (window.SYSTEM_CONFIG && window.SYSTEM_CONFIG.currency && window.SYSTEM_CONFIG.currency.symbol) ? window.SYSTEM_CONFIG.currency.symbol : '$';
    const salesChart = new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($salesChartData['labels']); ?>,
            datasets: [
                {
                    label: 'Periodo Actual',
                    data: <?php echo json_encode($salesChartData['values']); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: gradientSales,
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0d6efd',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#0d6efd',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 3,
                    order: 1
                },
                {
                    label: 'Periodo Anterior',
                    data: <?php echo json_encode($salesChartDataPrev['values']); ?>,
                    borderColor: '#cbd5e1',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.4,
                    fill: false,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    hidden: true, // Hidden by default
                    order: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 25,
                    bottom: 10,
                    left: 10,
                    right: 10
                }
            },
            plugins: { 
                legend: { 
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { 
                        boxWidth: 12, 
                        usePointStyle: true, 
                        font: { size: 11, family: "'Inter', sans-serif" },
                        padding: 20,
                        color: '#6b7280'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#111827',
                    bodyColor: '#4b5563',
                    borderColor: '#e5e7eb',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    usePointStyle: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                        label += new Intl.NumberFormat('en-US', { style: 'currency', currency: currencyCode }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { borderDash: [5, 5], color: '#f3f4f6', drawBorder: false }, 
                    ticks: { 
                        font: { family: "'Inter', sans-serif", size: 11 },
                        color: '#6b7280',
                        padding: 10,
                        callback: v => currencySymbol + v 
                    },
                    border: { display: false }
                },
                x: { 
                    grid: { display: false },
                    ticks: {
                        font: { family: "'Inter', sans-serif", size: 11 },
                        color: '#6b7280'
                    },
                    border: { display: false }
                }
            },
            interaction: {
                mode: 'index',
                intersect: false,
            },
            hover: {
                mode: 'nearest',
                intersect: true
            }
        }
    });

    // Toggle Comparison Logic
    document.getElementById('compareToggle').addEventListener('change', function(e) {
        const isChecked = e.target.checked;
        salesChart.data.datasets[1].hidden = !isChecked;
        salesChart.update();
    });

    // Chart Time Range Logic
    document.getElementById('chartRange').addEventListener('change', function() {
        const days = this.value;
        
        fetch(`ajax_chart_data.php?days=${days}`, { headers: { 'Accept': 'application/json' } })
            .then(window.parseJsonResponse)
            .then(data => {
                // Update Chart Data
                salesChart.data.labels = data.labels;
                salesChart.data.datasets[0].data = data.current;
                salesChart.data.datasets[1].data = data.previous;
                salesChart.update();
                
                // Update KPIs
                const formatter = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
                document.getElementById('kpiAvg').innerText = formatter.format(data.kpi.avg);
                document.getElementById('kpiMax').innerText = formatter.format(data.kpi.max);
                if (document.getElementById('kpiTotal') && data.kpi.total !== undefined) {
                    document.getElementById('kpiTotal').innerText = formatter.format(data.kpi.total);
                }
            })
            .catch(err => console.error('Error fetching chart data:', err));
    });

    // Download Chart Logic
    document.getElementById('downloadChartBtn')?.addEventListener('click', function() {
        const link = document.createElement('a');
        link.download = 'rendimiento_financiero_' + new Date().toISOString().slice(0,10) + '.png';
        link.href = document.getElementById('salesChart').toDataURL('image/png', 1.0);
        link.click();
    });

    const ordersCtx = document.getElementById('ordersChart').getContext('2d');
    const ordersData = {
        labels: <?php echo json_encode($ordersChartData['labels']); ?>,
        datasets: [{
            data: <?php echo json_encode($ordersChartData['values']); ?>,
            backgroundColor: <?php echo json_encode($ordersChartData['colors']); ?>,
            borderWidth: 0
        }]
    };

    // Check if data exists
    if (ordersData.labels.length === 0 || ordersData.datasets[0].data.every(val => val === 0)) {
        // Show No Data Message
        const container = document.getElementById('ordersChart').parentElement;
        container.innerHTML = `
            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                <i class="fas fa-chart-pie fa-3x mb-3 opacity-25"></i>
                <p class="mb-0 small">No hay órdenes registradas</p>
            </div>
        `;
    } else {
        new Chart(ordersCtx, {
            type: 'doughnut',
            data: ordersData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: { 
                    legend: { display: false } // Custom legend used instead
                },
                layout: { padding: 0 }
            }
        });
    }

        // Custom Legend Generation
        const legendContainer = document.getElementById('ordersLegend');
        if (legendContainer) {
            ordersData.labels.forEach((label, index) => {
                const val = ordersData.datasets[0].data[index];
                const color = ordersData.datasets[0].backgroundColor[index];
                
                const item = document.createElement('div');
                item.className = 'd-flex align-items-center p-2 rounded border bg-light';
                item.style.minWidth = '140px';
                item.innerHTML = `
                    <span class="rounded-circle me-2" style="width: 12px; height: 12px; background-color: ${color};"></span>
                    <div class="d-flex flex-column">
                        <span class="fw-bold text-dark small">${label}</span>
                        <span class="text-muted small">${val} órdenes</span>
                    </div>
                `;
                legendContainer.appendChild(item);
            });
        }
    }

    // Calendar Widget Logic
    const calendarEl = document.getElementById('dashboardCalendar');
    const today = new Date();
    let currentMonth = today.getMonth();
    let currentYear = today.getFullYear();
    let selectedDate = null;
    
    // Configuración Global
    const userCountry = "<?php echo CompanySettings::getPhoneConfig()['country'] ?? 'Colombia'; ?>";

    const Holidays = {
        getHolidays: function(year, country) {
            if (country === 'Colombia') {
                return this.getColombiaHolidays(year);
            }
            // Default or other countries can be added here
            return [];
        },

        getColombiaHolidays: function(year) {
            const holidays = [];
            
            // 1. Festivos Fijos
            const fixed = [
                { d: 1, m: 0, n: 'Año Nuevo' },
                { d: 1, m: 4, n: 'Día del Trabajo' },
                { d: 20, m: 6, n: 'Independencia de Colombia' },
                { d: 7, m: 7, n: 'Batalla de Boyacá' },
                { d: 8, m: 11, n: 'Inmaculada Concepción' },
                { d: 25, m: 11, n: 'Navidad' }
            ];
            fixed.forEach(h => holidays.push({ date: new Date(year, h.m, h.d), name: h.n }));

            // 2. Ley Emiliani (Se mueven al siguiente lunes)
            const emiliani = [
                { d: 6, m: 0, n: 'Reyes Magos' },
                { d: 19, m: 2, n: 'Día de San José' },
                { d: 29, m: 5, n: 'San Pedro y San Pablo' },
                { d: 15, m: 7, n: 'Asunción de la Virgen' },
                { d: 12, m: 9, n: 'Día de la Raza' },
                { d: 1, m: 10, n: 'Todos los Santos' },
                { d: 11, m: 10, n: 'Independencia de Cartagena' }
            ];
            emiliani.forEach(h => holidays.push({ date: this.moveToNextMonday(new Date(year, h.m, h.d)), name: h.n }));

            // 3. Basados en Pascua
            const easter = this.getEasterDate(year);
            
            // Jueves y Viernes Santo (No se mueven)
            holidays.push({ date: this.addDays(easter, -3), name: 'Jueves Santo' });
            holidays.push({ date: this.addDays(easter, -2), name: 'Viernes Santo' });
            
            // Emiliani desde Pascua (Ascensión, Corpus Christi, Sagrado Corazón)
            // Ascensión: 40 días después de Pascua (domingo) -> Lunes (+43)
            holidays.push({ date: this.moveToNextMonday(this.addDays(easter, 39)), name: 'Ascensión del Señor' });
            // Corpus Christi: 60 días después de Pascua (jueves) -> Lunes (+64)
            holidays.push({ date: this.moveToNextMonday(this.addDays(easter, 60)), name: 'Corpus Christi' });
            // Sagrado Corazón: 68 días después de Pascua (viernes) -> Lunes (+71)
            holidays.push({ date: this.moveToNextMonday(this.addDays(easter, 68)), name: 'Sagrado Corazón' });

            return holidays;
        },

        getEasterDate: function(year) {
            const a = year % 19;
            const b = Math.floor(year / 100);
            const c = year % 100;
            const d = Math.floor(b / 4);
            const e = b % 4;
            const f = Math.floor((b + 8) / 25);
            const g = Math.floor((b - f + 1) / 3);
            const h = (19 * a + b - d - g + 15) % 30;
            const i = Math.floor(c / 4);
            const k = c % 4;
            const l = (32 + 2 * e + 2 * i - h - k) % 7;
            const m = Math.floor((a + 11 * h + 22 * l) / 451);
            const month = Math.floor((h + l - 7 * m + 114) / 31) - 1; // 0-indexed
            const day = ((h + l - 7 * m + 114) % 31) + 1;
            return new Date(year, month, day);
        },

        moveToNextMonday: function(date) {
            const day = date.getDay();
            if (day === 1) return date; // Ya es lunes
            // Si es martes(2) a viernes(5), o sábado(6)/domingo(0), mover al siguiente lunes
            // Ley Emiliani mueve el festivo que cae en fin de semana? 
            // La ley dice: "se trasladará al lunes siguiente". Si cae lunes, se deja.
            // Si cae fin de semana también se traslada? Generalmente sí, o si cae entre semana.
            // Simplificación: Siempre mover al siguiente lunes, a menos que ya sea lunes?
            // Corrección: La ley mueve los que caen entre semana. Si cae domingo, pasa a lunes.
            // Pero para simplificar y asegurar consistencia con calendarios comunes:
            // La regla exacta: Si cae en lunes, se queda. Si no, al siguiente lunes.
            // Espera, la ley mueve la FECHA de celebración.
            // Ejemplo: Reyes (6 Enero). Si es Lunes, es el 6. Si es Martes, pasa al Lunes siguiente.
            
            // Implementación estándar Emiliani: Siempre se celebra el lunes siguiente a la fecha original,
            // EXCEPTO si la fecha original YA ES lunes.
            
            if (day === 1) return date;
            
            const daysUntilMonday = (8 - day) % 7; 
            // Si es domingo (0), (8-0)%7 = 1 (Lunes es mañana)
            // Si es martes (2), (8-2)%7 = 6 (Lunes es en 6 días)
            // Si es sábado (6), (8-6)%7 = 2 (Lunes es en 2 días)
            
            // Corrección lógica:
            // 0 (Sun) -> +1
            // 1 (Mon) -> 0 (should catch above)
            // 2 (Tue) -> +6
            // 3 (Wed) -> +5
            // 4 (Thu) -> +4
            // 5 (Fri) -> +3
            // 6 (Sat) -> +2
            
            let add = 0;
            if (day === 0) add = 1;
            else add = 8 - day;
            
            return this.addDays(date, add);
        },

        addDays: function(date, days) {
            const result = new Date(date);
            result.setDate(result.getDate() + days);
            return result;
        },
        
        isHoliday: function(day, month, year, holidays) {
            return holidays.find(h => 
                h.date.getDate() === day && 
                h.date.getMonth() === month && 
                h.date.getFullYear() === year
            );
        }
    };

    function renderCalendar(month, year) {
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startingDay = firstDay.getDay();
        const totalDays = lastDay.getDate();
        
        // Get Holidays for this year
        const yearHolidays = Holidays.getHolidays(year, userCountry);
        
        const monthNames = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
        
        let html = `
            <div class="calendar-header">
                <button onclick="changeMonth(-1)" title="Mes Anterior"><i class="fas fa-chevron-left"></i></button>
                <div class="calendar-title">${monthNames[month]} ${year}</div>
                <button onclick="changeMonth(1)" title="Mes Siguiente"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="calendar-days-header">
                <div>Do</div><div>Lu</div><div>Ma</div><div>Mi</div><div>Ju</div><div>Vi</div><div>Sa</div>
            </div>
            <div class="calendar-grid calendar-fade-in">
        `;

        // Empty cells for days before start of month
        for (let i = 0; i < startingDay; i++) {
            html += `<div class="calendar-day empty"></div>`;
        }

        // Days
        for (let i = 1; i <= totalDays; i++) {
            let classes = 'calendar-day';
            let title = '';
            
            // Check Holiday
            const holiday = Holidays.isHoliday(i, month, year, yearHolidays);
            if (holiday) {
                classes += ' holiday';
                title = `title="${holiday.name}"`;
            }
            
            // Check Today
            if (i === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                classes += ' today';
            }
            
            // Check Selected
            if (selectedDate && i === selectedDate.getDate() && month === selectedDate.getMonth() && year === selectedDate.getFullYear()) {
                classes += ' selected';
            }
            
            // Randomly add event dots (simulated) - reduced probability
            if (!holiday && Math.random() > 0.9) {
                classes += ' has-event';
            }

            html += `<div class="${classes}" ${title} onclick="selectDate(${i}, ${month}, ${year})">${i}</div>`;
        }

        html += `</div>`;
        calendarEl.innerHTML = html;
    }

    window.changeMonth = function(offset) {
        currentMonth += offset;
        if (currentMonth < 0) {
            currentMonth = 11;
            currentYear--;
        } else if (currentMonth > 11) {
            currentMonth = 0;
            currentYear++;
        }
        renderCalendar(currentMonth, currentYear);
    };
    
    window.selectDate = function(day, month, year) {
        selectedDate = new Date(year, month, day);
        renderCalendar(month, year);
        // Here you could trigger a modal or detail view
    };

    renderCalendar(currentMonth, currentYear);
});
</script>

<?php
$page_content = ob_get_clean();
require_once '../includes/page_template.php';
?>
