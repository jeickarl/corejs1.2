<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
$pdo = db();

if (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) {
    echo "Este script es para single-db.\n";
    exit;
}

echo "Checking system_config...\n";
try {
    $pdo->query("SELECT tenant_id FROM system_config LIMIT 1");
    echo "system_config already has tenant_id.\n";
} catch (Exception $e) {
    echo "Adding tenant_id to system_config...\n";
    try {
        $pdo->exec("ALTER TABLE system_config ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 FIRST");
        $pdo->exec("CREATE INDEX idx_sysconf_tenant ON system_config(tenant_id)");
        // Remove old primary key if exists and add new composite one or just index
        // Usually config_key is unique, now config_key + tenant_id should be unique
        // Check if config_key is unique
        $pdo->exec("ALTER TABLE system_config DROP INDEX config_key");
    } catch (Exception $e2) {
        echo "Note on keys: " . $e2->getMessage() . "\n";
    }
    try {
        $pdo->exec("ALTER TABLE system_config ADD UNIQUE KEY unique_conf (tenant_id, config_key)");
    } catch (Exception $e3) {
         echo "Note on unique key: " . $e3->getMessage() . "\n";
    }
    echo "Done system_config.\n";
}

echo "Checking company_config...\n";
try {
    $pdo->query("SELECT tenant_id FROM company_config LIMIT 1");
    echo "company_config already has tenant_id.\n";
} catch (Exception $e) {
    echo "Adding tenant_id to company_config...\n";
    try {
        $pdo->exec("ALTER TABLE company_config ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
        $pdo->exec("CREATE INDEX idx_compconf_tenant ON company_config(tenant_id)");
        echo "Done company_config.\n";
    } catch (Exception $e2) {
        echo "Error adding to company_config: " . $e2->getMessage() . "\n";
    }
}

echo "Config schema repair completed.\n";
?>
