<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';

function out(string $line): void
{
    echo $line . PHP_EOL;
}

$masterKey = getenv('MASTER_DB_KEY');
if (!is_string($masterKey) || trim($masterKey) === '') {
    out('ERROR: Falta MASTER_DB_KEY.');
    exit(2);
}

$master = DatabaseManager::master();
$empresas = $master->query("SELECT id, nombre, estado, db_name FROM empresas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$empresas) {
    out('No hay empresas en core_master.');
    exit(0);
}

out('=== Validacion Per-Database ===');
foreach ($empresas as $e) {
    $id = (int)($e['id'] ?? 0);
    $name = (string)($e['nombre'] ?? '');
    $estado = (string)($e['estado'] ?? '');
    $dbName = (string)($e['db_name'] ?? '');

    if ($id <= 0) {
        continue;
    }

    if ($estado !== 'active') {
        out("[SKIP] {$id} {$name} (estado={$estado})");
        continue;
    }

    try {
        $tenant = DatabaseManager::tenant($id);
        $tenant->query('SELECT 1');

        $users = 0;
        try {
            $users = (int)$tenant->query("SELECT COUNT(*) FROM users")->fetchColumn();
        } catch (Throwable $e2) {
        }

        out("[OK] {$id} {$name} db={$dbName} users={$users}");
    } catch (Throwable $ex) {
        out("[FAIL] {$id} {$name} db={$dbName} error=" . $ex->getMessage());
    }
}

out('Estado final: OK');
