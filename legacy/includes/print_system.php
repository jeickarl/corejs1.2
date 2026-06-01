<?php
/**
 * Sistema de Impresión Simplificado
 * Versión básica sin dependencia de tablas de documentos
 */

class PrintSystem {
    private PDO $pdo;
    
    public function __construct(PDO $database) {
        $this->pdo = $database;
    }
    
    /**
     * Obtener datos de orden de trabajo
     */
    public function getWorkOrderData($order_id) {
        try {
            $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
            $hasTenantWorkOrders = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($this->pdo, 'work_orders') : false;
            $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($this->pdo, 'clients') : false;
            $hasTenantDeviceTypes = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($this->pdo, 'device_types') : false;
            $joinClients = ($hasTenantClients && $hasTenantWorkOrders && !$perDatabase) ? "LEFT JOIN clients c ON wo.client_id = c.id AND c.tenant_id = wo.tenant_id" : "LEFT JOIN clients c ON wo.client_id = c.id";
            $joinDeviceTypes = ($hasTenantDeviceTypes && $hasTenantWorkOrders && !$perDatabase) ? "LEFT JOIN device_types dt ON wo.device_type_id = dt.id AND dt.tenant_id = wo.tenant_id" : "LEFT JOIN device_types dt ON wo.device_type_id = dt.id";
            $sql = "
                SELECT wo.*, 
                       CASE 
                           WHEN c.company_name IS NOT NULL AND c.company_name != '' THEN c.company_name
                           ELSE c.first_name
                       END AS client_name,
                       c.email AS client_email,
                       c.phone AS client_phone,
                       c.address AS client_address,
                       COALESCE(NULLIF(c.tax_id, ''), c.id_number) AS client_tax_id,
                       dt.name AS device_type
                FROM work_orders wo
                $joinClients
                $joinDeviceTypes
                WHERE wo.id = ?
            ";
            
            $params = [$order_id];
            
            // Add tenant filter if available
            if (function_exists('getCurrentTenantId')) {
                $tenant_id = getCurrentTenantId();
                if ($tenant_id && $hasTenantWorkOrders && !$perDatabase) {
                    $sql .= " AND wo.tenant_id = ?";
                    $params[] = $tenant_id;
                }
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!isset($row['order_number']) || (int)$row['order_number'] <= 0) {
                    $row['order_number'] = (int)$row['id'];
                }
            }
            return $row;
        } catch (PDOException $e) {
            error_log("Error en getWorkOrderData: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener datos de venta
     */
    public function getSaleData($sale_id) {
        $perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();
        $hasTenantSales = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($this->pdo, 'sales') : false;
        $hasTenantClients = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($this->pdo, 'clients') : false;
        $joinClients = ($hasTenantClients && $hasTenantSales && !$perDatabase) ? "LEFT JOIN clients c ON s.client_id = c.id AND c.tenant_id = s.tenant_id" : "LEFT JOIN clients c ON s.client_id = c.id";
        $sql = "
            SELECT s.*, 
                   CASE 
                       WHEN c.company_name IS NOT NULL AND c.company_name != '' THEN c.company_name
                       ELSE c.first_name
                   END as client_name,
                   c.email as client_email,
                   c.phone as client_phone,
                   c.address as client_address,
                   COALESCE(NULLIF(c.tax_id, ''), c.id_number) as client_tax_id
            FROM sales s
            $joinClients
            WHERE s.id = ?
        ";
        
        $params = [$sale_id];
        
        if (function_exists('getCurrentTenantId')) {
            $tenant_id = getCurrentTenantId();
            if ($tenant_id && $hasTenantSales && !$perDatabase) {
                $sql .= " AND s.tenant_id = ?";
                $params[] = $tenant_id;
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sale) {
            // Obtener items de la venta
            $items_stmt = $this->pdo->prepare("
                SELECT si.*, p.name as product_name, p.sku
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.id
                WHERE si.sale_id = ?
            ");
            $items_stmt->execute([$sale_id]);
            $sale['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $sale;
    }
}

/**
 * Función para generar botones de impresión
 */
function generatePrintButtons($type, $id, $buttonLabel = 'Imprimir', $buttonIcon = 'fa-print') {

    // Botones estáticos básicos (solo si no hay plantillas, o como opción extra)
    $static = [
        'work_order' => [
            ['name' => 'Orden de Servicio (Clásica)', 'url' => 'print_order.php']
        ],
        'work_order_label' => [
            ['name' => 'Etiqueta Adhesiva (50x30)', 'url' => 'print_label.php']
        ],
        'sale' => [
            ['name' => 'Factura de Venta', 'url' => '../billing/print.php']
        ]
    ];

    $staticButtons = $static[$type] ?? [];
    
    if (empty($staticButtons)) {
        return '<a href="#" class="btn btn-secondary rounded-pill shadow-sm" disabled><i class="fas ' . htmlspecialchars($buttonIcon) . '"></i> ' . htmlspecialchars($buttonLabel) . '</a>';
    }
    
    $html = '<div class="btn-group">';
    $html .= '<button type="button" class="btn btn-dark no-theme dropdown-toggle rounded-pill shadow-sm" data-bs-toggle="dropdown" aria-expanded="false">';
    $html .= '<i class="fas ' . htmlspecialchars($buttonIcon) . ' me-2"></i>' . htmlspecialchars($buttonLabel);
    $html .= '</button>';
    $html .= '<ul class="dropdown-menu shadow-sm border-0 rounded-3">';
    
    foreach ($staticButtons as $button) {
        $printUrl = $button['url'] . '?id=' . (int)$id . '&print=1';
        $html .= '<li><a class="dropdown-item" href="#" onclick="event.preventDefault(); if(window.__corePrint && typeof window.__corePrint.printUrl === \'function\'){ window.__corePrint.printUrl(\'' . $printUrl . '\'); } else { window.open(\'' . $printUrl . '\', \'PrintWindow\', \'width=1000,height=800,scrollbars=yes,resizable=yes\'); }">';
        $html .= '<i class="fas fa-file-pdf"></i> ' . htmlspecialchars($button['name']);
        $html .= '</a></li>';
    }

    $html .= '</ul>';
    $html .= '</div>';
    
    return $html;
}
