<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';

$empresaId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($empresaId <= 0) {
    echo "Uso: php saas/inspect_tenant_db.php EMPRESA_ID\n";
    exit(2);
}

$pdo = DatabaseManager::tenant($empresaId);
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!is_array($tables)) {
    $tables = [];
}

echo "db={$db}\n";
echo "tables_count=" . count($tables) . "\n";
echo "tables_sample=" . implode(',', array_slice($tables, 0, 40)) . "\n";

