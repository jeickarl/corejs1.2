<?php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (function_exists('isPerDatabaseMode') && isPerDatabaseMode()) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Este script aplica solo para single-db.\n";
    exit;
}

function ensureWorkOrdersIdAI(PDO $pdo): array {
    $changes = [];
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $extra = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'id'");
    $extra->execute([$db]);
    $ex = $extra->fetchColumn();
    if (!$ex || stripos($ex, 'auto_increment') === false) {
        // Reasignar posibles registros con id=0 antes de crear PK/AI
        $zeros = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE id = 0")->fetchColumn();
        if ($zeros > 0) {
            $maxId = (int)($pdo->query("SELECT MAX(id) FROM work_orders")->fetchColumn() ?: 0);
            while ((int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE id = 0")->fetchColumn() > 0) {
                $maxId++;
                $pdo->prepare("UPDATE work_orders SET id = ? WHERE id = 0 LIMIT 1")->execute([$maxId]);
            }
            $changes[] = "work_orders: registros con id=0 reasignados";
        }
        $pk = $pdo->query("SHOW KEYS FROM work_orders WHERE Key_name = 'PRIMARY'");
        if (!$pk || $pk->rowCount() === 0) {
            $pdo->exec("ALTER TABLE work_orders ADD PRIMARY KEY (id)");
            $changes[] = "PRIMARY KEY añadido en work_orders(id)";
        }
        $pdo->exec("ALTER TABLE work_orders MODIFY id int(11) NOT NULL AUTO_INCREMENT");
        $changes[] = "AUTO_INCREMENT aplicado en work_orders.id";
    }
    return $changes;
}

function addOrderNumberColumn(PDO $pdo): array {
    $changes = [];
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $col = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'order_number'");
    $col->execute([$db]);
    if ((int)$col->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE work_orders ADD COLUMN order_number INT(11) NOT NULL DEFAULT 0 AFTER id");
        $changes[] = "Columna order_number creada en work_orders";
    }
    return $changes;
}

function backfillOrderNumbers(PDO $pdo): array {
    $changes = [];
    $tenants = $pdo->query("SELECT id FROM tenants ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tenants as $tenant_id) {
        $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE tenant_id = ? ORDER BY id ASC");
        $stmt->execute([$tenant_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $wid) {
            $n++;
            $pdo->prepare("UPDATE work_orders SET order_number = ? WHERE id = ? AND tenant_id = ?")->execute([$n, $wid, $tenant_id]);
        }
        $changes[] = "Tenant $tenant_id: numeración aplicada hasta $n";
    }
    return $changes;
}

function ensureOrderNumberIndex(PDO $pdo): array {
    $changes = [];
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'unique_order_tenant'");
    $idx->execute([$db]);
    if ((int)$idx->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE work_orders ADD UNIQUE KEY unique_order_tenant (order_number, tenant_id)");
        $changes[] = "Índice único unique_order_tenant (order_number, tenant_id) creado";
    }
    return $changes;
}

try {
    $c1 = ensureWorkOrdersIdAI($pdo);
    $c2 = addOrderNumberColumn($pdo);
    $c3 = backfillOrderNumbers($pdo);
    $c4 = ensureOrderNumberIndex($pdo);
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK\n";
    foreach (array_merge($c1, $c2, $c3, $c4) as $line) { echo $line . "\n"; }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage();
}
?>
