<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
$pdo = db();

// Verificar autenticación
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

// Configurar headers para JSON
header('Content-Type: application/json');

try {
    // Obtener datos del JSON
    $input = json_decode(file_get_contents('php://input'), true);

    // Verificar CSRF
    if (!isset($input['csrf_token']) || !SecurityEnhancements::verifyCSRFToken($input['csrf_token'])) {
        SecurityEnhancements::logSecurityEvent('CSRF_VERIFICATION_FAILED', ['page' => 'orders/delete_order.php', 'order_id' => $input['order_id'] ?? null], $_SESSION['user_id'] ?? null);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido o expirado']);
        exit();
    }
    
    if (!isset($input['order_id']) || empty($input['order_id'])) {
        throw new Exception('ID de orden no válido');
    }
    
    $order_id = (int)$input['order_id'];
    
    if ($order_id <= 0) {
        throw new Exception('ID de orden no válido');
    }
    
    // Verificar que la orden existe
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    if ($perDatabase) {
        $stmt = $pdo->prepare("SELECT id, device_photo FROM work_orders WHERE id = ?");
        $stmt->execute([$order_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, device_photo FROM work_orders WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$order_id, $tenant_id]);
    }
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Orden no encontrada');
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    try {
        // Eliminar registros relacionados primero (por las foreign keys)
        
        // 1. Eliminar facturas vinculadas y sus dependencias
        // Verificar si existe la tabla invoices y tiene order_id
        $has_invoices = false;
        try {
            $check_invoices = $pdo->query("SHOW TABLES LIKE 'invoices'");
            if ($check_invoices->rowCount() > 0) {
                $check_col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'order_id'");
                if ($check_col->rowCount() > 0) {
                    $has_invoices = true;
                }
            }
        } catch (Exception $e) {
            // Ignorar error si no se puede verificar
        }

        if ($has_invoices) {
            if ($perDatabase) {
                $stmt = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ?");
                $stmt->execute([$order_id]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND tenant_id = ?");
                $stmt->execute([$order_id, $tenant_id]);
            }
            $linked_invoices = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($linked_invoices)) {
                // Preparar placeholders para IN clause
                $placeholders = implode(',', array_fill(0, count($linked_invoices), '?'));
                
                // Eliminar items de facturas
                if ($perDatabase) {
                    $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id IN ($placeholders)");
                    $stmt->execute($linked_invoices);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE tenant_id = ? AND invoice_id IN ($placeholders)");
                    $stmt->execute(array_merge([$tenant_id], $linked_invoices));
                }
                
                // Eliminar pagos de facturas
                if ($perDatabase) {
                    $stmt = $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id IN ($placeholders)");
                    $stmt->execute($linked_invoices);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM invoice_payments WHERE tenant_id = ? AND invoice_id IN ($placeholders)");
                    $stmt->execute(array_merge([$tenant_id], $linked_invoices));
                }
                
                // Eliminar facturas
                if ($perDatabase) {
                    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id IN ($placeholders)");
                    $stmt->execute($linked_invoices);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM invoices WHERE tenant_id = ? AND id IN ($placeholders)");
                    $stmt->execute(array_merge([$tenant_id], $linked_invoices));
                }
            }
        }
        
        // 2. Eliminar accesorios del equipo
        try {
            $hasTenantCol = hasTenantColumnCached($pdo, 'order_equipment_accessories');
            if ($hasTenantCol && !$perDatabase) {
                $stmt = $pdo->prepare("DELETE FROM order_equipment_accessories WHERE order_id = ? AND tenant_id = ?");
                $stmt->execute([$order_id, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM order_equipment_accessories WHERE order_id = ?");
                $stmt->execute([$order_id]);
            }
        } catch (PDOException $e) {
            // Continuar aunque falle la eliminación de accesorios
            error_log("Error eliminando accesorios de la orden: " . $e->getMessage());
        }
        
        // 3. Eliminar historial de estados
        $stmt = $pdo->prepare("DELETE FROM order_status_history WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // 4. Eliminar la orden principal
        if ($perDatabase) {
            $stmt = $pdo->prepare("DELETE FROM work_orders WHERE id = ?");
            $stmt->execute([$order_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM work_orders WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$order_id, $tenant_id]);
        }
        
        // Confirmar transacción
        $pdo->commit();

        // --- ELIMINACIÓN DE ARCHIVOS FÍSICOS ---
        // 1. Eliminar fotos registradas en device_photo
        if (!empty($order['device_photo'])) {
            $photos = json_decode($order['device_photo'], true);
            if (is_array($photos)) {
                foreach ($photos as $photo) {
                    // Ruta antigua/común: uploads/device_photos/
                    $path_common = __DIR__ . '/../uploads/device_photos/' . $photo;
                    if (file_exists($path_common)) {
                        unlink($path_common);
                    }
                    
                    // Ruta específica de orden: uploads/orders/{id}/
                    $path_order = __DIR__ . '/../uploads/orders/' . $order_id . '/' . $photo;
                    if (file_exists($path_order)) {
                        unlink($path_order);
                    }
                }
            }
        }

        try {
            deleteOrderAssets($tenant_id, $order_id);
        } catch (Throwable $e) {
            enqueueDeleteJob($tenant_id, 'order_assets', ['order_id' => $order_id]);
        }
        // ---------------------------------------
        
        // Registrar actividad
        logActivity($_SESSION['user_id'], 'DELETE_ORDER', 'work_orders', $order_id);
        
        echo json_encode([
            'success' => true,
            'message' => 'Orden y archivos eliminados exitosamente'
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error al eliminar orden: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
