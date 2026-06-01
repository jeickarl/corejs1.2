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

$email = $argv[1] ?? '';
$newPassword = $argv[2] ?? '';

$email = is_string($email) ? trim($email) : '';
$newPassword = is_string($newPassword) ? (string)$newPassword : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out('Uso: php saas/reset_master_password.php email@dominio.com NUEVA_CLAVE');
    exit(2);
}
if ($newPassword === '') {
    out('ERROR: La nueva clave no puede estar vacía.');
    exit(3);
}

$pdo = DatabaseManager::master();
$stmt = $pdo->prepare('SELECT id, empresa_id, email FROM usuarios_master WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($user)) {
    out('ERROR: No existe el usuario en usuarios_master.');
    exit(4);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$upd = $pdo->prepare('UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE id = ?');
$upd->execute([$hash, (int)$user['id']]);

$empresaId = (int)($user['empresa_id'] ?? 0);
if ($empresaId > 0 && DatabaseManager::mode() === 'per_database') {
    try {
        $tenant = DatabaseManager::tenant($empresaId);
        try {
            $u = $tenant->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?');
            $u->execute([$hash, $email]);
        } catch (Throwable $e) {
            $u = $tenant->prepare('UPDATE users SET password = ? WHERE email = ?');
            $u->execute([$hash, $email]);
        }
    } catch (Throwable $e) {
    }
}

out('OK');

