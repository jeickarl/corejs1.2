<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';
require_once __DIR__ . '/../config/functions.php';

$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    $master = DatabaseManager::master();
    $rows = $master->query("SELECT id, nombre, estado, db_name, created_at FROM empresas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        echo (int)$r['id'] . " | " . ($r['nombre'] ?? '') . " | " . ($r['estado'] ?? '') . " | " . ($r['db_name'] ?? '') . " | " . ($r['created_at'] ?? '') . PHP_EOL;
    }
    exit(0);
}

$master = DatabaseManager::master();
$rows = $master->query("SELECT id, company_name, status, slug, created_at FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $r) {
    echo (int)$r['id'] . " | " . ($r['company_name'] ?? '') . " | " . ($r['status'] ?? '') . " | " . ($r['slug'] ?? '') . " | " . ($r['created_at'] ?? '') . PHP_EOL;
}
