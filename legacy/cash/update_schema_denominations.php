<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    echo "per_database mode detected. Skipping schema updates.\n";
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_closing_denominations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cash_session_id INT UNSIGNED NOT NULL,
            tenant_id INT(11) NOT NULL DEFAULT 1,
            denomination_value DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $hasTenant = false;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM cash_closing_denominations LIKE 'tenant_id'");
        $hasTenant = $res && $res->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasTenant = false;
    }
    if (!$hasTenant) {
        $pdo->exec("ALTER TABLE cash_closing_denominations ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER cash_session_id");
        try {
            $pdo->exec("
                UPDATE cash_closing_denominations d
                JOIN cash_sessions s ON d.cash_session_id = s.id
                SET d.tenant_id = s.tenant_id
            ");
        } catch (PDOException $e) {}
    }
    try { $pdo->exec("CREATE INDEX idx_cash_denoms_tenant ON cash_closing_denominations(tenant_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE cash_closing_denominations ADD CONSTRAINT fk_cash_denoms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE"); } catch (PDOException $e) {}
    echo "Schema for cash_closing_denominations updated successfully.";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
?>
