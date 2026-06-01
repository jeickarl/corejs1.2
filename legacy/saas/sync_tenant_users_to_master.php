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

if (DatabaseManager::mode() !== 'per_database') {
    out('ERROR: Este script solo aplica a SAAS_DB_MODE=per_database');
    exit(2);
}

$master = DatabaseManager::master();
$empresas = $master->query("SELECT id, nombre, estado FROM empresas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$empresas) {
    out('No hay empresas en core_master.');
    exit(0);
}

$valid_roles = ['admin', 'technician', 'inventory', 'user'];
$role_map = [
    'Administrador' => 'admin',
    'Editor' => 'technician',
    'Inventario' => 'inventory',
    'Usuario' => 'user'
];

$selMaster = $master->prepare('SELECT id, empresa_id FROM usuarios_master WHERE email = ? LIMIT 1');
$insMaster = $master->prepare('
    INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
');

$created = 0;
$exists = 0;
$conflicts = 0;
$fails = 0;

out('=== Sync Tenant Users -> usuarios_master ===');
foreach ($empresas as $e) {
    $empresaId = (int)($e['id'] ?? 0);
    $nombre = (string)($e['nombre'] ?? '');
    $estado = (string)($e['estado'] ?? '');

    if ($empresaId <= 0) {
        continue;
    }
    if ($estado !== 'active') {
        out("[SKIP] {$empresaId} {$nombre} (estado={$estado})");
        continue;
    }

    try {
        $tenant = DatabaseManager::tenant($empresaId);
    } catch (Throwable $ex) {
        $fails++;
        out("[FAIL] {$empresaId} {$nombre} tenant_connect " . $ex->getMessage());
        continue;
    }

    try {
        $rows = $tenant->query('SELECT id, name, email, password, role, active FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        $fails++;
        out("[FAIL] {$empresaId} {$nombre} users_select " . $ex->getMessage());
        continue;
    }

    $syncedHere = 0;
    foreach ($rows as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $name = (string)($r['name'] ?? '');
        $passHash = (string)($r['password'] ?? '');
        $role = (string)($r['role'] ?? 'user');
        $active = (int)($r['active'] ?? 1);

        if (!in_array($role, $valid_roles)) {
            $role = $role_map[$role] ?? 'user';
        }

        try {
            $selMaster->execute([$email]);
            $existing = $selMaster->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                $existingEmpresa = (int)($existing['empresa_id'] ?? 0);
                if ($existingEmpresa !== $empresaId) {
                    $conflicts++;
                    out("[CONFLICT] {$email} master_empresa={$existingEmpresa} tenant_empresa={$empresaId}");
                } else {
                    $exists++;
                }
                continue;
            }

            $insMaster->execute([$empresaId, $email, $passHash, $role, $name, $active]);
            $created++;
            $syncedHere++;
        } catch (Throwable $ex) {
            $fails++;
            out("[FAIL] {$empresaId} {$nombre} {$email} " . $ex->getMessage());
        }
    }

    out("[OK] {$empresaId} {$nombre} created={$syncedHere}");
}

out("Resumen: created={$created} exists={$exists} conflicts={$conflicts} fails={$fails}");

