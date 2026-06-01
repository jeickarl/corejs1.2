<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    echo "per_database mode detected. Skipping tenant_id schema repairs.\n";
    exit;
}

// 1. device_types
echo "Checking device_types...\n";
try {
    $pdo->query("SELECT tenant_id FROM device_types LIMIT 1");
    echo "device_types already has tenant_id.\n";
} catch (Exception $e) {
    echo "Adding tenant_id to device_types...\n";
    try {
        $pdo->exec("ALTER TABLE device_types ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
        $pdo->exec("CREATE INDEX idx_dt_tenant ON device_types(tenant_id)");
        echo "Done.\n";
    } catch (Exception $e2) {
        echo "Error adding to device_types: " . $e2->getMessage() . "\n";
    }
}

// 2. brands
echo "Checking brands...\n";
try {
    $pdo->query("SELECT tenant_id FROM brands LIMIT 1");
    echo "brands already has tenant_id.\n";
} catch (Exception $e) {
    echo "Adding tenant_id to brands...\n";
    try {
        $pdo->exec("ALTER TABLE brands ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
        $pdo->exec("CREATE INDEX idx_brand_tenant ON brands(tenant_id)");
        echo "Done.\n";
    } catch (Exception $e2) {
        echo "Error adding to brands: " . $e2->getMessage() . "\n";
    }
}

// 3. models
echo "Checking models...\n";
try {
    $pdo->query("SELECT tenant_id FROM models LIMIT 1");
    echo "models already has tenant_id.\n";
} catch (Exception $e) {
    echo "Adding tenant_id to models...\n";
    try {
        $pdo->exec("ALTER TABLE models ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
        $pdo->exec("CREATE INDEX idx_model_tenant ON models(tenant_id)");
        echo "Done.\n";
    } catch (Exception $e2) {
        echo "Error adding to models: " . $e2->getMessage() . "\n";
    }
}

echo "Schema repair completed.\n";
?>
