<?php
/**
 * Dashboard de Analíticas y Reportes
 * Sistema Core - Módulo de Reportes
 */

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/performance_optimizer.php';

// Verificar autenticación
requireAuth();

// Inicializar optimizador de rendimiento
PerformanceOptimizer::init();

$tenant_id = getCurrentTenantId();

// Obtener parámetros de filtro
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Primer día del mes actual
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Hoy
$period = $_GET['period'] ?? 'month'; // month, quarter, year

// Configurar título de la página
$page_title = 'Dashboard de Analíticas';

// Capturar contenido
ob_start();
?>

<div class="container-modern">
    <!-- Header del Dashboard -->
    <div class="flex-modern justify-between items-center mb-6">
        <div>
            <h1 class="font-bold text-3xl text-gray-800">📊 Dashboard de Analíticas</h1>
            <p class="text-gray-600 mt-2">Análisis completo del rendimiento del negocio</p>
        </div>
        
        <!-- Filtros de fecha -->
        <div class="flex-modern gap-4">
            <div class="form-group-modern">
                <label class="form-label-modern">Período</label>
                <select class="form-control-modern" id="periodSelect" onchange="updatePeriod()">
                    <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Este Mes</option>
                    <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>Este Trimestre</option>
                    <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Este Año</option>
                    <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Personalizado</option>
                </select>
            </div>
            
            <div class="form-group-modern" id="customDateRange" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>">
                <label class="form-label-modern">Rango Personalizado</label>
                <div class="flex-modern gap-2">
                    <input type="date" class="form-control-modern" id="dateFrom" value="<?php echo $date_from; ?>">
                    <input type="date" class="form-control-modern" id="dateTo" value="<?php echo $date_to; ?>">
                    <button class="btn-modern btn-primary" onclick="applyCustomRange()">Aplicar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Métricas Principales -->
    <div class="grid-modern grid-4 mb-8">
        <?php
        // Obtener métricas principales
        $metrics = getMainMetrics($date_from, $date_to, $tenant_id);
        ?>
        
        <div class="dashboard-card animate-fade-in">
            <div class="card-icon bg-primary text-white">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="card-title">Ingresos Totales</div>
            <div class="card-value">$<?php echo number_format($metrics['total_revenue'], 2); ?></div>
            <div class="card-change <?php echo $metrics['revenue_change'] >= 0 ? 'positive' : 'negative'; ?>">
                <i class="fas fa-<?php echo $metrics['revenue_change'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                <?php echo abs($metrics['revenue_change']); ?>% vs período anterior
            </div>
        </div>

        <div class="dashboard-card animate-fade-in">
            <div class="card-icon bg-success text-white">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div class="card-title">Órdenes Completadas</div>
            <div class="card-value"><?php echo number_format($metrics['completed_orders']); ?></div>
            <div class="card-change <?php echo $metrics['orders_change'] >= 0 ? 'positive' : 'negative'; ?>">
                <i class="fas fa-<?php echo $metrics['orders_change'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                <?php echo abs($metrics['orders_change']); ?>% vs período anterior
            </div>
        </div>

        <div class="dashboard-card animate-fade-in">
            <div class="card-icon bg-warning text-white">
                <i class="fas fa-users"></i>
            </div>
            <div class="card-title">Clientes Nuevos</div>
            <div class="card-value"><?php echo number_format($metrics['new_clients']); ?></div>
            <div class="card-change <?php echo $metrics['clients_change'] >= 0 ? 'positive' : 'negative'; ?>">
                <i class="fas fa-<?php echo $metrics['clients_change'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                <?php echo abs($metrics['clients_change']); ?>% vs período anterior
            </div>
        </div>

        <div class="dashboard-card animate-fade-in">
            <div class="card-icon bg-info text-white">
                <i class="fas fa-clock"></i>
            </div>
            <div class="card-title">Tiempo Promedio</div>
            <div class="card-value"><?php echo $metrics['avg_completion_time']; ?> días</div>
            <div class="card-change <?php echo $metrics['time_change'] <= 0 ? 'positive' : 'negative'; ?>">
                <i class="fas fa-<?php echo $metrics['time_change'] <= 0 ? 'arrow-down' : 'arrow-up'; ?>"></i>
                <?php echo abs($metrics['time_change']); ?>% vs período anterior
            </div>
        </div>
    </div>

    <!-- Gráficos y Análisis -->
    <div class="grid-modern grid-2 mb-8">
        <!-- Gráfico de Ventas por Día -->
        <div class="card-modern">
            <div class="card-header">
                <h3 class="font-semibold text-lg">Ventas por Día</h3>
            </div>
            <div class="card-body">
                <canvas id="salesChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Gráfico de Órdenes por Estado -->
        <div class="card-modern">
            <div class="card-header">
                <h3 class="font-semibold text-lg">Órdenes por Estado</h3>
            </div>
            <div class="card-body">
                <canvas id="ordersChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabla de Productos Más Vendidos -->
    <div class="card-modern mb-8">
        <div class="card-header">
            <h3 class="font-semibold text-lg">Productos Más Vendidos</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Unidades Vendidas</th>
                            <th>Ingresos</th>
                            <th>Stock Actual</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $top_products = getTopProducts($date_from, $date_to, $tenant_id);
                        foreach ($top_products as $product):
                        ?>
                        <tr>
                            <td>
                                <div class="flex-modern items-center gap-3">
                                    <div class="w-8 h-8 bg-gray-200 rounded flex-modern items-center justify-center">
                                        <i class="fas fa-box text-gray-600"></i>
                                    </div>
                                    <div>
                                        <div class="font-medium"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="text-sm text-gray-500">SKU: <?php echo htmlspecialchars($product['sku']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($product['category']); ?></td>
                            <td>
                                <span class="font-semibold"><?php echo number_format($product['units_sold']); ?></span>
                            </td>
                            <td>
                                <span class="font-semibold text-success">$<?php echo number_format($product['revenue'], 2); ?></span>
                            </td>
                            <td>
                                <span class="badge-modern <?php echo $product['stock_status']; ?>">
                                    <?php echo number_format($product['current_stock']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($product['current_stock'] <= $product['min_stock']): ?>
                                    <span class="badge-modern badge-warning">Stock Bajo</span>
                                <?php elseif ($product['current_stock'] == 0): ?>
                                    <span class="badge-modern badge-danger">Sin Stock</span>
                                <?php else: ?>
                                    <span class="badge-modern badge-success">Disponible</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Análisis de Clientes -->
    <div class="grid-modern grid-2 mb-8">
        <!-- Clientes por Tipo -->
        <div class="card-modern">
            <div class="card-header">
                <h3 class="font-semibold text-lg">Clientes por Tipo</h3>
            </div>
            <div class="card-body">
                <canvas id="clientsChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Top Clientes -->
        <div class="card-modern">
            <div class="card-header">
                <h3 class="font-semibold text-lg">Top 5 Clientes</h3>
            </div>
            <div class="card-body">
                <?php
                $top_clients = getTopClients($date_from, $date_to, $tenant_id);
                foreach ($top_clients as $index => $client):
                ?>
                <div class="flex-modern justify-between items-center py-3 <?php echo $index < count($top_clients) - 1 ? 'border-b border-gray-200' : ''; ?>">
                    <div class="flex-modern items-center gap-3">
                        <div class="w-8 h-8 bg-primary text-white rounded-full flex-modern items-center justify-center text-sm font-semibold">
                            <?php echo $index + 1; ?>
                        </div>
                        <div>
                            <div class="font-medium"><?php echo htmlspecialchars($client['name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['email']); ?></div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold text-success">$<?php echo number_format($client['total_spent'], 2); ?></div>
                        <div class="text-sm text-gray-500"><?php echo $client['orders_count']; ?> órdenes</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Alertas y Recomendaciones -->
    <div class="card-modern">
        <div class="card-header">
            <h3 class="font-semibold text-lg">Alertas y Recomendaciones</h3>
        </div>
        <div class="card-body">
            <?php
            $alerts = getSystemAlerts($tenant_id);
            if (empty($alerts)):
            ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-success text-4xl mb-4"></i>
                <p class="text-gray-600">¡Excelente! No hay alertas pendientes.</p>
            </div>
            <?php else: ?>
            <div class="grid-modern grid-2">
                <?php foreach ($alerts as $alert): ?>
                <div class="alert-modern alert-<?php echo $alert['type']; ?>">
                    <i class="fas fa-<?php echo $alert['icon']; ?> text-lg"></i>
                    <div>
                        <div class="font-semibold"><?php echo htmlspecialchars($alert['title']); ?></div>
                        <div class="text-sm"><?php echo htmlspecialchars($alert['message']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Datos para los gráficos
const salesData = <?php echo json_encode(getSalesChartData($date_from, $date_to, $tenant_id)); ?>;
const ordersData = <?php echo json_encode(getOrdersChartData($date_from, $date_to, $tenant_id)); ?>;
const clientsData = <?php echo json_encode(getClientsChartData($date_from, $date_to, $tenant_id)); ?>;

// Gráfico de Ventas
const salesChart = new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: salesData.labels,
        datasets: [{
            label: 'Ventas Diarias',
            data: salesData.values,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Gráfico de Órdenes
const ordersChart = new Chart(document.getElementById('ordersChart'), {
    type: 'doughnut',
    data: {
        labels: ordersData.labels,
        datasets: [{
            data: ordersData.values,
            backgroundColor: [
                '#48bb78',
                '#ed8936',
                '#f56565',
                '#4299e1',
                '#9f7aea'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Gráfico de Clientes
const clientsChart = new Chart(document.getElementById('clientsChart'), {
    type: 'pie',
    data: {
        labels: clientsData.labels,
        datasets: [{
            data: clientsData.values,
            backgroundColor: [
                '#667eea',
                '#764ba2'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Funciones de filtrado
function updatePeriod() {
    const period = document.getElementById('periodSelect').value;
    const customRange = document.getElementById('customDateRange');
    
    if (period === 'custom') {
        customRange.style.display = 'block';
    } else {
        customRange.style.display = 'none';
        window.location.href = `?period=${period}`;
    }
}

function applyCustomRange() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    if (dateFrom && dateTo) {
        window.location.href = `?period=custom&date_from=${dateFrom}&date_to=${dateTo}`;
    }
}
</script>

<?php
$page_content = ob_get_clean();

// Funciones auxiliares
function getMainMetrics($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    // Obtener métricas del período actual
    $current_metrics = getMetricsForPeriod($date_from, $date_to, $tenant_id);
    
    // Obtener métricas del período anterior para comparación
    $days_diff = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24);
    $prev_date_from = date('Y-m-d', strtotime($date_from) - ($days_diff * 24 * 60 * 60));
    $prev_date_to = date('Y-m-d', strtotime($date_from) - (24 * 60 * 60));
    $prev_metrics = getMetricsForPeriod($prev_date_from, $prev_date_to, $tenant_id);
    
    // Calcular cambios porcentuales
    $revenue_change = $prev_metrics['total_revenue'] > 0 ? 
        (($current_metrics['total_revenue'] - $prev_metrics['total_revenue']) / $prev_metrics['total_revenue']) * 100 : 0;
    
    $orders_change = $prev_metrics['completed_orders'] > 0 ? 
        (($current_metrics['completed_orders'] - $prev_metrics['completed_orders']) / $prev_metrics['completed_orders']) * 100 : 0;
    
    $clients_change = $prev_metrics['new_clients'] > 0 ? 
        (($current_metrics['new_clients'] - $prev_metrics['new_clients']) / $prev_metrics['new_clients']) * 100 : 0;
    
    $time_change = $prev_metrics['avg_completion_time'] > 0 ? 
        (($current_metrics['avg_completion_time'] - $prev_metrics['avg_completion_time']) / $prev_metrics['avg_completion_time']) * 100 : 0;
    
    return [
        'total_revenue' => $current_metrics['total_revenue'],
        'completed_orders' => $current_metrics['completed_orders'],
        'new_clients' => $current_metrics['new_clients'],
        'avg_completion_time' => $current_metrics['avg_completion_time'],
        'revenue_change' => round($revenue_change, 1),
        'orders_change' => round($orders_change, 1),
        'clients_change' => round($clients_change, 1),
        'time_change' => round($time_change, 1)
    ];
}

function getMetricsForPeriod($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantInvoices = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'invoices') : false;
        $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;

        // Ingresos totales
        $sql = "
            SELECT COALESCE(SUM(total_amount), 0) as total_revenue
            FROM invoices 
            WHERE created_at BETWEEN ? AND ? AND status != 'cancelled'" . (($hasTenantInvoices && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantInvoices && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $revenue = $stmt->fetch()['total_revenue'];
        
        // Órdenes completadas
        $sql = "
            SELECT COUNT(*) as completed_orders
            FROM work_orders 
            WHERE created_at BETWEEN ? AND ? AND status = 'completed'" . (($hasTenantWorkOrders && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantWorkOrders && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $orders = $stmt->fetch()['completed_orders'];
        
        // Clientes nuevos
        $sql = "
            SELECT COUNT(*) as new_clients
            FROM clients 
            WHERE created_at BETWEEN ? AND ?" . (($hasTenantClients && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantClients && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $clients = $stmt->fetch()['new_clients'];
        
        // Tiempo promedio de completado
        $sql = "
            SELECT AVG(DATEDIFF(updated_at, created_at)) as avg_time
            FROM work_orders 
            WHERE status = 'completed' 
            AND created_at BETWEEN ? AND ?" . (($hasTenantWorkOrders && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantWorkOrders && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $avg_time = $stmt->fetch()['avg_time'] ?? 0;
        
        return [
            'total_revenue' => $revenue,
            'completed_orders' => $orders,
            'new_clients' => $clients,
            'avg_completion_time' => round($avg_time, 1)
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting metrics: " . $e->getMessage());
        return [
            'total_revenue' => 0,
            'completed_orders' => 0,
            'new_clients' => 0,
            'avg_completion_time' => 0
        ];
    }
}

function getSalesChartData($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantInvoices = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'invoices') : false;
        $sql = "
            SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_revenue
            FROM invoices 
            WHERE created_at BETWEEN ? AND ? AND status != 'cancelled'" . (($hasTenantInvoices && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            GROUP BY DATE(created_at)
            ORDER BY sale_date ASC
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantInvoices && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $values = [];
        
        foreach ($results as $row) {
            $labels[] = date('d/m', strtotime($row['sale_date']));
            $values[] = $row['daily_revenue'];
        }
        
        return ['labels' => $labels, 'values' => $values];
        
    } catch (PDOException $e) {
        error_log("Error getting sales chart data: " . $e->getMessage());
        return ['labels' => [], 'values' => []];
    }
}

function getOrdersChartData($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $sql = "
            SELECT status, COUNT(*) as count
            FROM work_orders
            WHERE created_at BETWEEN ? AND ?" . (($hasTenantWorkOrders && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            GROUP BY status
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantWorkOrders && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $values = [];
        
        foreach ($results as $row) {
            $labels[] = getStatusText($row['status']);
            $values[] = $row['count'];
        }
        
        return ['labels' => $labels, 'values' => $values];
        
    } catch (PDOException $e) {
        error_log("Error getting orders chart data: " . $e->getMessage());
        return ['labels' => [], 'values' => []];
    }
}

function getClientsChartData($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
        $sql = "
            SELECT client_type, COUNT(*) as count
            FROM clients
            WHERE created_at BETWEEN ? AND ?" . (($hasTenantClients && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            GROUP BY client_type
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantClients && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $values = [];
        
        foreach ($results as $row) {
            $labels[] = $row['client_type'] === 'individual' ? 'Individuales' : 'Empresas';
            $values[] = $row['count'];
        }
        
        return ['labels' => $labels, 'values' => $values];
        
    } catch (PDOException $e) {
        error_log("Error getting clients chart data: " . $e->getMessage());
        return ['labels' => [], 'values' => []];
    }
}

function getTopProducts($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantInv = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'inventory_products') : false;
        $hasTenantPc = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'product_categories') : false;
        $hasTenantOi = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'order_items') : false;
        $hasTenantWo = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $joinPc = ($hasTenantPc && $hasTenantInv && !$perDatabase) ? "LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.tenant_id = p.tenant_id" : "LEFT JOIN product_categories pc ON p.category_id = pc.id";
        $joinWo = ($hasTenantOi && $hasTenantWo && !$perDatabase) ? "LEFT JOIN work_orders wo ON oi.order_id = wo.id AND wo.tenant_id = oi.tenant_id" : "LEFT JOIN work_orders wo ON oi.order_id = wo.id";
        $sql = "
            SELECT 
                p.name, p.sku, pc.name as category,
                COALESCE(SUM(oi.quantity), 0) as units_sold,
                COALESCE(SUM(oi.quantity * oi.price), 0) as revenue,
                p.current_stock, p.min_stock
            FROM inventory_products p
            {$joinPc}
            LEFT JOIN order_items oi ON p.id = oi.product_id
            {$joinWo}
            WHERE wo.created_at BETWEEN ? AND ? AND wo.status = 'completed'" .
            (($hasTenantWo && !$perDatabase) ? " AND wo.tenant_id = ?" : "") .
            (($hasTenantInv && !$perDatabase) ? " AND p.tenant_id = ?" : "") . "
            GROUP BY p.id, p.name, p.sku, pc.name, p.current_stock, p.min_stock
            ORDER BY units_sold DESC
            LIMIT 10
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantWo && !$perDatabase) { $params[] = $tenantValue; }
        if ($hasTenantInv && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        foreach ($results as &$product) {
            if ($product['current_stock'] == 0) {
                $product['stock_status'] = 'badge-danger';
            } elseif ($product['current_stock'] <= $product['min_stock']) {
                $product['stock_status'] = 'badge-warning';
            } else {
                $product['stock_status'] = 'badge-success';
            }
        }
        
        return $results;
        
    } catch (PDOException $e) {
        error_log("Error getting top products: " . $e->getMessage());
        return [];
    }
}

function getTopClients($date_from, $date_to, $tenant_id) {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;
        $hasTenantWo = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $joinWo = ($hasTenantClients && $hasTenantWo && !$perDatabase) ? "LEFT JOIN work_orders wo ON c.id = wo.client_id AND wo.tenant_id = c.tenant_id" : "LEFT JOIN work_orders wo ON c.id = wo.client_id";
        $sql = "
            SELECT 
                CASE WHEN c.client_type = 'company' THEN c.company_name ELSE c.first_name END as name,
                c.email,
                COUNT(wo.id) as orders_count,
                COALESCE(SUM(wo.total_amount), 0) as total_spent
            FROM clients c
            {$joinWo}
            WHERE wo.created_at BETWEEN ? AND ? AND wo.status = 'completed'" .
            (($hasTenantWo && !$perDatabase) ? " AND wo.tenant_id = ?" : "") .
            (($hasTenantClients && !$perDatabase) ? " AND c.tenant_id = ?" : "") . "
            GROUP BY c.id, c.first_name, c.company_name, c.email
            ORDER BY total_spent DESC
            LIMIT 5
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$date_from, $date_to];
        if ($hasTenantWo && !$perDatabase) { $params[] = $tenantValue; }
        if ($hasTenantClients && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Error getting top clients: " . $e->getMessage());
        return [];
    }
}

function getSystemAlerts($tenant_id) {
    global $pdo;
    
    $alerts = [];
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
        $hasTenantInv = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'inventory_products') : false;
        $hasTenantWo = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'work_orders') : false;
        $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'clients') : false;

        // Verificar stock bajo
        $sql = "
            SELECT COUNT(*) as low_stock_count
            FROM inventory_products 
            WHERE current_stock <= min_stock AND is_active = 1" . (($hasTenantInv && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantInv && !$perDatabase) ? [$tenantValue] : []);
        $low_stock = $stmt->fetch()['low_stock_count'];
        
        if ($low_stock > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'exclamation-triangle',
                'title' => 'Stock Bajo',
                'message' => "$low_stock productos tienen stock bajo o están agotados"
            ];
        }
        
        // Verificar órdenes pendientes
        $sql = "
            SELECT COUNT(*) as pending_orders
            FROM work_orders 
            WHERE status IN ('received', 'diagnosing', 'waiting_parts', 'repairing')" . (($hasTenantWo && !$perDatabase) ? " AND tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantWo && !$perDatabase) ? [$tenantValue] : []);
        $pending = $stmt->fetch()['pending_orders'];
        
        if ($pending > 10) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'clock',
                'title' => 'Órdenes Pendientes',
                'message' => "$pending órdenes están en proceso"
            ];
        }
        
        // Verificar clientes sin actividad reciente
        $joinWo = ($hasTenantClients && $hasTenantWo && !$perDatabase) ? "LEFT JOIN work_orders wo ON c.id = wo.client_id AND wo.tenant_id = c.tenant_id" : "LEFT JOIN work_orders wo ON c.id = wo.client_id";
        $sql = "
            SELECT COUNT(*) as inactive_clients
            FROM clients c
            {$joinWo}
            WHERE (wo.id IS NULL OR wo.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY))" . (($hasTenantClients && !$perDatabase) ? " AND c.tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantClients && !$perDatabase) ? [$tenantValue] : []);
        $inactive = $stmt->fetch()['inactive_clients'];
        
        if ($inactive > 50) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'user-clock',
                'title' => 'Clientes Inactivos',
                'message' => "$inactive clientes no han tenido actividad reciente"
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error getting system alerts: " . $e->getMessage());
    }
    
    return $alerts;
}

include '../includes/page_template.php';
?>
