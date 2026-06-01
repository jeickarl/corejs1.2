<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';

$onlyEmpresaId = isset($argv[1]) ? (int)$argv[1] : 0;

$master = DatabaseManager::master();
$empresas = $master->query("SELECT id, nombre, estado FROM empresas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$ddls = [];

$ddls[] = "
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_un_n2` (`notification_id`),
  CONSTRAINT `fk_un_n2` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_categories_tenant` (`tenant_id`),
  KEY `idx_product_categories_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `inventory_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `internal_code` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `purchase_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) NOT NULL DEFAULT 0,
  `max_stock` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `unit_type` varchar(50) NOT NULL DEFAULT 'unidad',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_internal_code` (`internal_code`),
  KEY `idx_inventory_products_tenant` (`tenant_id`),
  KEY `idx_inventory_products_category` (`category_id`),
  KEY `idx_inventory_products_brand` (`brand_id`),
  KEY `idx_inventory_products_supplier` (`supplier_id`),
  KEY `idx_inventory_products_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `product_id` int(11) NOT NULL,
  `movement_type` enum('in','out','adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_movements_tenant` (`tenant_id`),
  KEY `idx_inventory_movements_product` (`product_id`),
  KEY `idx_inventory_movements_type` (`movement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `supplier_code` varchar(50) DEFAULT NULL,
  `supplier_type` enum('company','individual') NOT NULL DEFAULT 'company',
  `company_name` varchar(255) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `document_type` enum('cc','nit','ce','passport') DEFAULT NULL,
  `document_number` varchar(50) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `payment_terms` varchar(50) NOT NULL DEFAULT 'net30',
  `credit_limit` decimal(10,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `account_type` enum('checking','savings') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `rating` tinyint(1) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_supplier_code` (`supplier_code`),
  KEY `idx_suppliers_tenant` (`tenant_id`),
  KEY `idx_suppliers_company_name` (`company_name`),
  KEY `idx_suppliers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_terms` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','partially_paid','paid') NOT NULL DEFAULT 'pending',
  `status` enum('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_po_number` (`po_number`),
  KEY `idx_purchase_orders_tenant` (`tenant_id`),
  KEY `idx_purchase_orders_supplier` (`supplier_id`),
  KEY `idx_purchase_orders_payment_status` (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

$ddls[] = "
CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT 1,
  `supplier_id` int(11) NOT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_date` date NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cash_session_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `status` enum('active','voided') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_supplier_payments_request` (`request_id`),
  KEY `idx_supplier_payments_tenant` (`tenant_id`),
  KEY `idx_supplier_payments_supplier` (`supplier_id`),
  KEY `idx_supplier_payments_po` (`purchase_order_id`),
  KEY `idx_supplier_payments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
";

foreach ($empresas as $e) {
    $empresaId = (int)($e['id'] ?? 0);
    if ($empresaId <= 0) {
        continue;
    }
    if ($onlyEmpresaId > 0 && $empresaId !== $onlyEmpresaId) {
        continue;
    }
    if (($e['estado'] ?? '') !== 'active') {
        continue;
    }

    $name = (string)($e['nombre'] ?? '');
    echo "Empresa {$empresaId} {$name}\n";

    try {
        $pdo = DatabaseManager::tenant($empresaId);
    } catch (Throwable $ex) {
        echo "  FAIL connect: {$ex->getMessage()}\n";
        continue;
    }

    $ok = 0;
    foreach ($ddls as $sql) {
        try {
            $pdo->exec($sql);
            $ok++;
        } catch (Throwable $ex) {
            echo "  FAIL ddl: {$ex->getMessage()}\n";
        }
    }
    echo "  OK {$ok}/" . count($ddls) . "\n";
}
