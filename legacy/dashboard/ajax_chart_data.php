<?php
require_once '../config/session.php';
require_once '../config/database.php';

// Verificar autenticación
if (!isLoggedIn()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

// Obtener rango
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
if ($days <= 0) $days = 7;

function getSalesChartDataLocal($pdo, $days, $offset = 0) {
    try {
        $intervalStart = $days + $offset;
        $intervalEnd = $offset;
        
        $stmt = $pdo->query("
            SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_revenue
            FROM invoices 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $intervalStart DAY) 
              AND created_at < DATE_SUB(CURDATE(), INTERVAL $intervalEnd DAY)
              AND status != 'cancelled'
            GROUP BY DATE(created_at)
            ORDER BY sale_date
        ");
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
    } catch (Exception $e) {
        return ['labels' => [], 'values' => []];
    }
}

$currentData = getSalesChartDataLocal($pdo, $days, 0);
$prevData = getSalesChartDataLocal($pdo, $days, $days);

// Calcular KPIs
$vals = $currentData['values'];
$totalPeriod = array_sum($vals);
$avgDaily = count($vals) > 0 ? $totalPeriod / count($vals) : 0;
$maxDaily = count($vals) > 0 ? max($vals) : 0;

header('Content-Type: application/json');
echo json_encode([
    'labels' => $currentData['labels'],
    'current' => $currentData['values'],
    'previous' => $prevData['values'],
    'kpi' => [
        'avg' => $avgDaily,
        'max' => $maxDaily,
        'total' => $totalPeriod
    ]
]);
