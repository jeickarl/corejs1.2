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

$pdo = DatabaseManager::master();
$rows = $pdo->query('SELECT id, empresa_id, email, rol, nombre, activo, ultimo_login_at FROM usuarios_master ORDER BY empresa_id ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    out('No hay usuarios en usuarios_master.');
    exit(0);
}

out('id | empresa_id | activo | rol | email | nombre | ultimo_login_at');
foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    $empresaId = (int)($r['empresa_id'] ?? 0);
    $activo = (int)($r['activo'] ?? 0);
    $rol = (string)($r['rol'] ?? '');
    $email = (string)($r['email'] ?? '');
    $nombre = (string)($r['nombre'] ?? '');
    $last = (string)($r['ultimo_login_at'] ?? '');
    out($id . ' | ' . $empresaId . ' | ' . $activo . ' | ' . $rol . ' | ' . $email . ' | ' . $nombre . ' | ' . $last);
}

