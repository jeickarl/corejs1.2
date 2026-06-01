<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/database_manager.php';
require_once __DIR__ . '/../config/crypto.php';

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

function out(string $line): void
{
    echo $line . PHP_EOL;
}

$apply = hasFlag($argv, 'apply');
$oldKey = argValue($argv, 'old', getenv('OLD_MASTER_DB_KEY') ?: null);
$newKey = argValue($argv, 'new', getenv('NEW_MASTER_DB_KEY') ?: null);

if (!is_string($oldKey) || trim($oldKey) === '') {
    out('ERROR: Falta clave vieja. Usa --old=... o OLD_MASTER_DB_KEY.');
    exit(2);
}
if (!is_string($newKey) || trim($newKey) === '') {
    out('ERROR: Falta clave nueva. Usa --new=... o NEW_MASTER_DB_KEY.');
    exit(2);
}

$oldKey = trim($oldKey);
$newKey = trim($newKey);

if ($oldKey === $newKey) {
    out('ERROR: La clave vieja y la nueva son iguales.');
    exit(2);
}

out('=== Rotacion MASTER_DB_KEY ===');
out('Modo: ' . ($apply ? 'APPLY' : 'DRY-RUN'));

$master = DatabaseManager::master();
$rows = $master->query("SELECT id, nombre, db_password_enc, db_password_iv, db_password_tag FROM empresas WHERE estado <> 'deleted' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    out('No hay empresas para rotar.');
    exit(0);
}

$originalKey = getenv('MASTER_DB_KEY');
$ok = 0;
$fail = 0;

foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    $nombre = (string)($r['nombre'] ?? '');
    $enc = (string)($r['db_password_enc'] ?? '');
    $iv = (string)($r['db_password_iv'] ?? '');
    $tag = (string)($r['db_password_tag'] ?? '');

    if ($id <= 0 || $enc === '' || $iv === '' || $tag === '') {
        $fail++;
        out("[FAIL] {$id} {$nombre}: credenciales incompletas.");
        continue;
    }

    try {
        putenv('MASTER_DB_KEY=' . $oldKey);
        $_ENV['MASTER_DB_KEY'] = $oldKey;
        $plaintext = Crypto::decrypt($enc, $iv, $tag);

        putenv('MASTER_DB_KEY=' . $newKey);
        $_ENV['MASTER_DB_KEY'] = $newKey;
        $new = Crypto::encrypt($plaintext);

        if ($apply) {
            $upd = $master->prepare("UPDATE empresas SET db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$new['enc'], $new['iv'], $new['tag'], $id]);
        }

        $ok++;
        out("[OK] {$id} {$nombre}");
    } catch (Throwable $e) {
        $fail++;
        out("[FAIL] {$id} {$nombre}: no se pudo descifrar/recifrar.");
    }
}

if (is_string($originalKey) && $originalKey !== '') {
    putenv('MASTER_DB_KEY=' . $originalKey);
    $_ENV['MASTER_DB_KEY'] = $originalKey;
} else {
    putenv('MASTER_DB_KEY');
    unset($_ENV['MASTER_DB_KEY']);
}

out('--- Resumen ---');
out('OK: ' . $ok);
out('FAIL: ' . $fail);
out('Estado final: OK');

