<?php
require_once 'auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$page_title = 'Reparación de Base de Datos';
$sa_active = 'health';

$message = '';
$type = 'info';
$isPerDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($isPerDatabase) {
    $message = 'Esta herramienta es para single-db. En per_database la reparación debe ejecutarse en la base del tenant.';
    $type = 'warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isPerDatabase) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    } elseif (!isset($_POST['csrf_token_sa'], $_SESSION['csrf_token_sa']) || !is_string($_POST['csrf_token_sa']) || !is_string($_SESSION['csrf_token_sa']) || $_POST['csrf_token_sa'] === '' || $_SESSION['csrf_token_sa'] === '' || !hash_equals($_SESSION['csrf_token_sa'], $_POST['csrf_token_sa'])) {
        $message = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
        $type = 'danger';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. inventory_products
        $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_products` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `internal_code` varchar(50) NOT NULL,
            `name` varchar(255) NOT NULL,
            `category_id` int(11) DEFAULT NULL,
            `brand_id` int(11) DEFAULT NULL,
            `supplier_id` int(11) DEFAULT NULL,
            `description` text DEFAULT NULL,
            `purchase_price` decimal(10,2) DEFAULT 0.00,
            `sale_price` decimal(10,2) NOT NULL,
            `current_stock` int(11) DEFAULT 0,
            `min_stock` int(11) DEFAULT 5,
            `max_stock` int(11) DEFAULT 100,
            `location` varchar(100) DEFAULT NULL,
            `unit_type` varchar(50) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `notes` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `internal_code` (`internal_code`),
            KEY `idx_tenant_inventory_products` (`tenant_id`),
            KEY `category_id` (`category_id`),
            KEY `brand_id` (`brand_id`),
            KEY `supplier_id` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. inventory_movements
        $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_movements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `movement_type` enum('in','out','adjustment') NOT NULL,
            `movement_subtype` varchar(50) NOT NULL,
            `quantity` decimal(10,2) NOT NULL,
            `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
            `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
            `stock_before` decimal(10,2) NOT NULL,
            `stock_after` decimal(10,2) NOT NULL,
            `reference_type` varchar(50) DEFAULT NULL,
            `reference_id` int(11) DEFAULT NULL,
            `reference_number` varchar(50) DEFAULT NULL,
            `reason` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_tenant_inventory_movements` (`tenant_id`),
            KEY `product_id` (`product_id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. suppliers
        $pdo->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `document_type` varchar(50) NOT NULL,
            `document_number` varchar(50) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `mobile` varchar(20) DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `country` varchar(100) DEFAULT NULL,
            `bank` varchar(100) DEFAULT NULL,
            `account_type` varchar(50) DEFAULT NULL,
            `account_number` varchar(50) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_tenant_suppliers` (`tenant_id`),
            KEY `document_number` (`document_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. supplier_payments
        $pdo->exec("CREATE TABLE IF NOT EXISTS `supplier_payments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `supplier_id` int(11) NOT NULL,
            `purchase_order_id` int(11) DEFAULT NULL,
            `payment_amount` decimal(12,2) NOT NULL,
            `payment_method` varchar(50) NOT NULL,
            `payment_date` date NOT NULL,
            `reference_number` varchar(100) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `cash_session_id` int(11) NOT NULL,
            `created_by` int(11) DEFAULT NULL,
            `request_id` varchar(64) DEFAULT NULL,
            `status` enum('active','voided') DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `supplier_id` (`supplier_id`),
            KEY `purchase_order_id` (`purchase_order_id`),
            UNIQUE KEY `request_id` (`request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. invoices
        $pdo->exec("CREATE TABLE IF NOT EXISTS `invoices` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_number` varchar(50) NOT NULL,
            `client_id` int(11) NOT NULL,
            `document_type` varchar(50) DEFAULT 'service',
            `invoice_date` date NOT NULL,
            `due_date` date DEFAULT NULL,
            `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
            `discount_amount` decimal(12,2) DEFAULT 0.00,
            `tax_amount` decimal(12,2) DEFAULT 0.00,
            `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
            `paid_amount` decimal(12,2) DEFAULT 0.00,
            `pending_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
            `payment_status` enum('pending','partial','paid') DEFAULT 'pending',
            `status` enum('draft','sent','cancelled') DEFAULT 'draft',
            `notes` text DEFAULT NULL,
            `terms_conditions` text DEFAULT NULL,
            `order_id` int(11) DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `client_id` (`client_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. invoice_items
        $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_id` int(11) NOT NULL,
            `item_type` varchar(50) DEFAULT 'manual',
            `description` varchar(255) NOT NULL,
            `quantity` decimal(10,2) NOT NULL,
            `unit_price` decimal(12,2) NOT NULL,
            `total_price` decimal(12,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. invoice_payments
        $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_payments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_id` int(11) NOT NULL,
            `payment_amount` decimal(12,2) NOT NULL,
            `payment_method` varchar(50) NOT NULL,
            `payment_date` datetime NOT NULL,
            `reference_number` varchar(100) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `cash_session_id` int(11) DEFAULT NULL,
            `pm_account_id` int(11) DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. settings (dummy table if needed, but system_config seems to be the real one)
        // If system_config exists, we don't need settings.
        // Let's create system_config if missing.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `config_key` varchar(100) NOT NULL,
            `config_value` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `config_key` (`config_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->commit();
        $message = "Tablas reparadas y creadas exitosamente.";
        $type = "success";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $message = "Error reparando base de datos: " . $e->getMessage();
        $type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reparación BD - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/super_admin.css">
</head>
<body class="bg-light">
    <?php include 'sidebar_common.php'; ?>
    <div class="main-content">
        <?php $sa_title = 'Reparación de Base de Datos'; include 'header_common.php'; ?>
        
        <div class="container-fluid p-4">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body text-center p-5">
                    <i class="fas fa-tools fa-4x text-warning mb-3"></i>
                    <h3>Reparación Automática</h3>
                    <p class="text-muted">Esto creará las tablas faltantes detectadas en el diagnóstico.</p>
                    
                    <form method="post">
                        <input type="hidden" name="csrf_token_sa" value="<?php echo htmlspecialchars($_SESSION['csrf_token_sa'] ?? ''); ?>">
                        <button type="submit" class="btn btn-primary btn-lg px-5" <?php echo $isPerDatabase ? 'disabled' : ''; ?>>
                            <i class="fas fa-play me-2"></i>Ejecutar Reparación
                        </button>
                    </form>
                    
                    <div class="mt-4">
                        <a href="health_check.php" class="btn btn-link text-muted">Volver al Diagnóstico</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
