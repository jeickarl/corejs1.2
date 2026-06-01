<?php
/**
 * Inventory Integration Functions
 * Handles automatic inventory updates when products are used in work orders
 */

require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

/**
 * Deduct inventory when products are used in work orders
 * @param int $order_id Work order ID
 * @param array $products Array of products with quantities
 * @return array Result with success status and messages
 */
function deductInventoryFromOrder($order_id, $products) {
    global $pdo;
    
    $result = [
        'success' => true,
        'messages' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    try {
        $pdo->beginTransaction();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $hasTenantMovements = hasTenantColumnCached($pdo, 'inventory_movements');
        
        foreach ($products as $product) {
            $product_id = (int)$product['product_id'];
            $quantity_used = (float)$product['quantity_used'];
            
            if ($quantity_used <= 0) {
                continue;
            }
            
            // Get current product stock (tenant-aware)
            $sql = "SELECT name, sku, current_stock FROM products WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
            $productData = $stmt->fetch();
            
            if (!$productData) {
                $result['errors'][] = "Producto con ID $product_id no encontrado";
                continue;
            }
            
            $current_stock = (float)$productData['current_stock'];
            $new_stock = $current_stock - $quantity_used;
            
            // Check if there's sufficient stock
            if ($new_stock < 0) {
                $result['warnings'][] = "Stock insuficiente para {$productData['name']} ({$productData['sku']}). Stock actual: $current_stock, Requerido: $quantity_used";
                // Continue with negative stock but warn
            }
            
            // Update product stock (tenant-aware)
            $sql = "UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [$new_stock, $product_id];
            if ($hasTenantProducts && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            // Record inventory movement
            $notes = "Usado en orden de trabajo #$order_id";
            if ($hasTenantMovements) {
                if ($perDatabase) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, 'out', ?, 0, 'work_order', ?, ?, NOW())
                        ");
                        $stmt->execute([$product_id, $quantity_used, $order_id, $notes]);
                    } catch (Throwable $e) {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, ?, 'out', ?, 0, 'work_order', ?, ?, NOW())
                        ");
                        $stmt->execute([$tenantValue, $product_id, $quantity_used, $order_id, $notes]);
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_movements (
                            tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                            reference_id, notes, created_at
                        ) VALUES (?, ?, 'out', ?, 0, 'work_order', ?, ?, NOW())
                    ");
                    $stmt->execute([$tenantValue, $product_id, $quantity_used, $order_id, $notes]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_movements (
                        product_id, movement_type, quantity, unit_cost, reference_type, 
                        reference_id, notes, created_at
                    ) VALUES (?, 'out', ?, 0, 'work_order', ?, ?, NOW())
                ");
                $stmt->execute([$product_id, $quantity_used, $order_id, $notes]);
            }
            
            $result['messages'][] = "Stock actualizado para {$productData['name']}: $current_stock → $new_stock";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $result['success'] = false;
        $result['errors'][] = 'Error al actualizar inventario: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Restore inventory when products are removed from work orders
 * @param int $order_id Work order ID
 * @param array $products Array of products with quantities to restore
 * @return array Result with success status and messages
 */
function restoreInventoryFromOrder($order_id, $products) {
    global $pdo;
    
    $result = [
        'success' => true,
        'messages' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    try {
        $pdo->beginTransaction();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $hasTenantMovements = hasTenantColumnCached($pdo, 'inventory_movements');
        
        foreach ($products as $product) {
            $product_id = (int)$product['product_id'];
            $quantity_to_restore = (float)$product['quantity_used'];
            
            if ($quantity_to_restore <= 0) {
                continue;
            }
            
            // Get current product stock (tenant-aware)
            $sql = "SELECT name, sku, current_stock FROM products WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
            $productData = $stmt->fetch();
            
            if (!$productData) {
                $result['errors'][] = "Producto con ID $product_id no encontrado";
                continue;
            }
            
            $current_stock = (float)$productData['current_stock'];
            $new_stock = $current_stock + $quantity_to_restore;
            
            // Update product stock (tenant-aware)
            $sql = "UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [$new_stock, $product_id];
            if ($hasTenantProducts && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            // Record inventory movement
            $notes = "Devuelto de orden de trabajo #$order_id";
            if ($hasTenantMovements) {
                if ($perDatabase) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, 'in', ?, 0, 'work_order_return', ?, ?, NOW())
                        ");
                        $stmt->execute([$product_id, $quantity_to_restore, $order_id, $notes]);
                    } catch (Throwable $e) {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, ?, 'in', ?, 0, 'work_order_return', ?, ?, NOW())
                        ");
                        $stmt->execute([$tenantValue, $product_id, $quantity_to_restore, $order_id, $notes]);
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_movements (
                            tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                            reference_id, notes, created_at
                        ) VALUES (?, ?, 'in', ?, 0, 'work_order_return', ?, ?, NOW())
                    ");
                    $stmt->execute([$tenantValue, $product_id, $quantity_to_restore, $order_id, $notes]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_movements (
                        product_id, movement_type, quantity, unit_cost, reference_type, 
                        reference_id, notes, created_at
                    ) VALUES (?, 'in', ?, 0, 'work_order_return', ?, ?, NOW())
                ");
                $stmt->execute([$product_id, $quantity_to_restore, $order_id, $notes]);
            }
            
            $result['messages'][] = "Stock restaurado para {$productData['name']}: $current_stock → $new_stock";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $result['success'] = false;
        $result['errors'][] = 'Error al restaurar inventario: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Deduct inventory when sales are completed
 * @param int $sale_id Sale ID
 * @return array Result with success status and messages
 */
function deductInventoryFromSale($sale_id) {
    global $pdo;
    
    $result = [
        'success' => true,
        'messages' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    try {
        $pdo->beginTransaction();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $hasTenantSaleItems = hasTenantColumnCached($pdo, 'sale_items');
        $hasTenantMovements = hasTenantColumnCached($pdo, 'inventory_movements');
        
        // Get sale items that are products
        $sql = "
            SELECT si.product_id, si.quantity, p.name, p.sku, p.current_stock
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ? AND si.type = 'product' AND si.product_id IS NOT NULL" .
            (($hasTenantSaleItems && !$perDatabase) ? " AND si.tenant_id = ?" : "") .
            (($hasTenantProducts && !$perDatabase) ? " AND p.tenant_id = ?" : "") . "
        ";
        $stmt = $pdo->prepare($sql);
        $params = [$sale_id];
        if ($hasTenantSaleItems && !$perDatabase) { $params[] = $tenantValue; }
        if ($hasTenantProducts && !$perDatabase) { $params[] = $tenantValue; }
        $stmt->execute($params);
        $sale_products = $stmt->fetchAll();
        
        foreach ($sale_products as $product) {
            $product_id = $product['product_id'];
            $quantity_sold = (float)$product['quantity'];
            $current_stock = (float)$product['current_stock'];
            $new_stock = $current_stock - $quantity_sold;
            
            // Check if there's sufficient stock
            if ($new_stock < 0) {
                $result['warnings'][] = "Stock insuficiente para {$product['name']} ({$product['sku']}). Stock actual: $current_stock, Vendido: $quantity_sold";
                // Continue with negative stock but warn
            }
            
            // Update product stock (tenant-aware)
            $sql = "UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "");
            $stmt = $pdo->prepare($sql);
            $params = [$new_stock, $product_id];
            if ($hasTenantProducts && !$perDatabase) { $params[] = $tenantValue; }
            $stmt->execute($params);
            
            // Record inventory movement
            $notes = "Vendido en venta #$sale_id";
            if ($hasTenantMovements) {
                if ($perDatabase) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, 'out', ?, 0, 'sale', ?, ?, NOW())
                        ");
                        $stmt->execute([$product_id, $quantity_sold, $sale_id, $notes]);
                    } catch (Throwable $e) {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_movements (
                                tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                                reference_id, notes, created_at
                            ) VALUES (?, ?, 'out', ?, 0, 'sale', ?, ?, NOW())
                        ");
                        $stmt->execute([$tenantValue, $product_id, $quantity_sold, $sale_id, $notes]);
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_movements (
                            tenant_id, product_id, movement_type, quantity, unit_cost, reference_type, 
                            reference_id, notes, created_at
                        ) VALUES (?, ?, 'out', ?, 0, 'sale', ?, ?, NOW())
                    ");
                    $stmt->execute([$tenantValue, $product_id, $quantity_sold, $sale_id, $notes]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_movements (
                        product_id, movement_type, quantity, unit_cost, reference_type, 
                        reference_id, notes, created_at
                    ) VALUES (?, 'out', ?, 0, 'sale', ?, ?, NOW())
                ");
                $stmt->execute([$product_id, $quantity_sold, $sale_id, $notes]);
            }
            
            $result['messages'][] = "Stock actualizado para {$product['name']}: $current_stock → $new_stock";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $result['success'] = false;
        $result['errors'][] = 'Error al actualizar inventario desde venta: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Check product availability for work order
 * @param array $products Array of products with quantities needed
 * @return array Result with availability status and details
 */
function checkProductAvailability($products) {
    global $pdo;
    
    $result = [
        'all_available' => true,
        'products' => [],
        'warnings' => []
    ];
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        foreach ($products as $product) {
            $product_id = (int)$product['product_id'];
            $quantity_needed = (float)$product['quantity_needed'];
            
            // Get product details (tenant-aware)
            $sql = "
                SELECT id, name, sku, current_stock, minimum_stock, status
                FROM products 
                WHERE id = ?" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$product_id, $tenantValue] : [$product_id]);
            $productData = $stmt->fetch();
            
            if (!$productData) {
                $result['all_available'] = false;
                $result['warnings'][] = "Producto con ID $product_id no encontrado";
                continue;
            }
            
            $current_stock = (float)$productData['current_stock'];
            $minimum_stock = (float)$productData['minimum_stock'];
            $available = $current_stock >= $quantity_needed;
            $stock_after = $current_stock - $quantity_needed;
            $will_be_low_stock = $stock_after < $minimum_stock;
            
            $productInfo = [
                'id' => $product_id,
                'name' => $productData['name'],
                'sku' => $productData['sku'],
                'current_stock' => $current_stock,
                'minimum_stock' => $minimum_stock,
                'quantity_needed' => $quantity_needed,
                'available' => $available,
                'stock_after' => $stock_after,
                'will_be_low_stock' => $will_be_low_stock,
                'status' => $productData['status']
            ];
            
            if (!$available) {
                $result['all_available'] = false;
                $result['warnings'][] = "Stock insuficiente para {$productData['name']} ({$productData['sku']}). Disponible: $current_stock, Necesario: $quantity_needed";
            } elseif ($will_be_low_stock) {
                $result['warnings'][] = "El producto {$productData['name']} ({$productData['sku']}) quedará por debajo del stock mínimo después de usar $quantity_needed unidades";
            }
            
            if ($productData['status'] !== 'active') {
                $result['warnings'][] = "El producto {$productData['name']} ({$productData['sku']}) está inactivo";
            }
            
            $result['products'][] = $productInfo;
        }
        
    } catch (Exception $e) {
        $result['all_available'] = false;
        $result['warnings'][] = 'Error al verificar disponibilidad: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Get low stock products
 * @return array List of products with low stock
 */
function getLowStockProducts() {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $stmt = $pdo->prepare("
            SELECT id, name, sku, current_stock, minimum_stock, 
                   (current_stock - minimum_stock) as stock_difference
            FROM products 
            WHERE current_stock <= minimum_stock AND status = 'active'" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ORDER BY stock_difference ASC, name ASC
        ");
        $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$tenantValue] : []);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get out of stock products
 * @return array List of products that are out of stock
 */
function getOutOfStockProducts() {
    global $pdo;
    
    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $stmt = $pdo->prepare("
            SELECT id, name, sku, current_stock, minimum_stock
            FROM products 
            WHERE current_stock <= 0 AND status = 'active'" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ORDER BY name ASC
        ");
        $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$tenantValue] : []);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Generate inventory alert summary
 * @return array Summary of inventory alerts
 */
function getInventoryAlertSummary() {
    global $pdo;
    
    $summary = [
        'out_of_stock_count' => 0,
        'low_stock_count' => 0,
        'total_products' => 0,
        'out_of_stock_products' => [],
        'low_stock_products' => []
    ];
    
    try {
        // Total products
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenant_id = getCurrentTenantId();
        $tenantValue = $perDatabase ? 1 : $tenant_id;
        $hasTenantProducts = hasTenantColumnCached($pdo, 'products');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE status = 'active'" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : ""));
        $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$tenantValue] : []);
        $summary['total_products'] = $stmt->fetchColumn();
        
        // Out of stock
        $summary['out_of_stock_products'] = getOutOfStockProducts();
        $summary['out_of_stock_count'] = count($summary['out_of_stock_products']);
        
        // Low stock (excluding out of stock)
        $stmt = $pdo->prepare("
            SELECT id, name, sku, current_stock, minimum_stock
            FROM products 
            WHERE current_stock > 0 AND current_stock <= minimum_stock AND status = 'active'" . (($hasTenantProducts && !$perDatabase) ? " AND tenant_id = ?" : "") . "
            ORDER BY (current_stock - minimum_stock) ASC, name ASC
        ");
        $stmt->execute(($hasTenantProducts && !$perDatabase) ? [$tenantValue] : []);
        $summary['low_stock_products'] = $stmt->fetchAll();
        $summary['low_stock_count'] = count($summary['low_stock_products']);
        
    } catch (Exception $e) {
        // Return empty summary on error
    }
    
    return $summary;
}

/**
 * Log inventory integration action
 * @param string $action Action performed
 * @param int $reference_id Reference ID (order_id, sale_id, etc.)
 * @param string $reference_type Reference type
 * @param array $details Additional details
 */
function logInventoryAction($action, $reference_id, $reference_type, $details = []) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_logs (
                action, reference_id, reference_type, details, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        
        $details_json = json_encode($details);
        $stmt->execute([$action, $reference_id, $reference_type, $details_json]);
        
    } catch (Exception $e) {
        // Log errors silently - don't break the main process
        error_log("Inventory log error: " . $e->getMessage());
    }
}

/**
 * Auto-update inventory when order parts are modified
 * This function should be called when order_parts table is updated
 * @param int $order_id Work order ID
 * @param array $old_parts Previous parts configuration
 * @param array $new_parts New parts configuration
 * @return array Result with success status and messages
 */
function updateInventoryFromOrderChange($order_id, $old_parts, $new_parts) {
    $result = [
        'success' => true,
        'messages' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    // Calculate differences
    $parts_to_restore = [];
    $parts_to_deduct = [];
    
    // Create lookup arrays
    $old_lookup = [];
    foreach ($old_parts as $part) {
        $old_lookup[$part['product_id']] = (float)$part['quantity_used'];
    }
    
    $new_lookup = [];
    foreach ($new_parts as $part) {
        $new_lookup[$part['product_id']] = (float)$part['quantity_used'];
    }
    
    // Find parts to restore (removed or reduced)
    foreach ($old_lookup as $product_id => $old_quantity) {
        $new_quantity = $new_lookup[$product_id] ?? 0;
        if ($new_quantity < $old_quantity) {
            $parts_to_restore[] = [
                'product_id' => $product_id,
                'quantity_used' => $old_quantity - $new_quantity
            ];
        }
    }
    
    // Find parts to deduct (added or increased)
    foreach ($new_lookup as $product_id => $new_quantity) {
        $old_quantity = $old_lookup[$product_id] ?? 0;
        if ($new_quantity > $old_quantity) {
            $parts_to_deduct[] = [
                'product_id' => $product_id,
                'quantity_used' => $new_quantity - $old_quantity
            ];
        }
    }
    
    // Restore inventory for removed/reduced parts
    if (!empty($parts_to_restore)) {
        $restore_result = restoreInventoryFromOrder($order_id, $parts_to_restore);
        $result['messages'] = array_merge($result['messages'], $restore_result['messages']);
        $result['warnings'] = array_merge($result['warnings'], $restore_result['warnings']);
        $result['errors'] = array_merge($result['errors'], $restore_result['errors']);
        if (!$restore_result['success']) {
            $result['success'] = false;
        }
    }
    
    // Deduct inventory for added/increased parts
    if (!empty($parts_to_deduct)) {
        $deduct_result = deductInventoryFromOrder($order_id, $parts_to_deduct);
        $result['messages'] = array_merge($result['messages'], $deduct_result['messages']);
        $result['warnings'] = array_merge($result['warnings'], $deduct_result['warnings']);
        $result['errors'] = array_merge($result['errors'], $deduct_result['errors']);
        if (!$deduct_result['success']) {
            $result['success'] = false;
        }
    }
    
    // Log the action
    logInventoryAction('order_parts_updated', $order_id, 'work_order', [
        'parts_restored' => $parts_to_restore,
        'parts_deducted' => $parts_to_deduct,
        'result' => $result
    ]);
    
    return $result;
}
?>
