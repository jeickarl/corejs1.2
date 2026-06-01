<?php

require_once __DIR__ . '/database_manager.php';

$perDatabase = function_exists('isPerDatabaseMode') && isPerDatabaseMode();

final class TenantManager
{
    public static function authenticate(string $email, string $password): array
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Credenciales incorrectas.');
        }

        $user = DatabaseManager::getUsuarioByEmail($email);
        if (!$user) {
            try {
                $master = DatabaseManager::master();
                $cnt = (int)($master->query('SELECT COUNT(*) FROM usuarios_master')->fetchColumn() ?: 0);
                $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
                $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
                $isLocal = ($host === 'localhost' || $host === '127.0.0.1') || ($ip === '127.0.0.1' || $ip === '::1');
                if ($cnt === 0 || $isLocal) {
                    $empresaIds = $master->query("SELECT id FROM empresas WHERE estado = 'active' ORDER BY id ASC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($empresaIds as $eidRaw) {
                        $eid = (int)$eidRaw;
                        if ($eid <= 0) {
                            continue;
                        }
                        try {
                            $tenantPdo = DatabaseManager::tenant($eid);
                            $st = $tenantPdo->prepare('SELECT id, name, email, password, role, active FROM users WHERE email = ? LIMIT 1');
                            $st->execute([$email]);
                            $tu = $st->fetch(PDO::FETCH_ASSOC);
                            if (!is_array($tu) || (int)($tu['active'] ?? 0) !== 1) {
                                continue;
                            }
                            $hash = (string)($tu['password'] ?? '');
                            $valid = false;
                            if ($hash !== '' && password_verify($password, $hash)) {
                                $valid = true;
                            } elseif (strlen($hash) === 32 && ctype_xdigit($hash) && md5($password) === $hash) {
                                $valid = true;
                            }
                            if (!$valid) {
                                continue;
                            }
                            $ins = $master->prepare('INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())');
                            $ins->execute([
                                $eid,
                                $email,
                                $hash !== '' ? $hash : password_hash($password, PASSWORD_DEFAULT),
                                (string)($tu['role'] ?? 'admin'),
                                (string)($tu['name'] ?? ''),
                            ]);
                            $user = DatabaseManager::getUsuarioByEmail($email);
                            break;
                        } catch (Throwable $e) {
                            continue;
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if (!$user || (int)($user['activo'] ?? 0) !== 1) {
            throw new RuntimeException('Credenciales incorrectas.');
        }

        $hash = (string)($user['password_hash'] ?? '');
        $valid = false;
        if ($hash !== '' && password_verify($password, $hash)) {
            $valid = true;
        } elseif (strlen($hash) === 32 && ctype_xdigit($hash) && md5($password) === $hash) {
            // Compatibilidad temporal con hashes legacy MD5; se migra a PASSWORD_DEFAULT
            $valid = true;
        }

        if (!$valid) {
            throw new RuntimeException('Credenciales incorrectas.');
        }

        $empresaId = (int)($user['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new RuntimeException('Credenciales incorrectas.');
        }

        $empresa = DatabaseManager::getEmpresa($empresaId);
        if (!$empresa || ($empresa['estado'] ?? '') !== 'active') {
            throw new RuntimeException('La cuenta de su empresa está suspendida.');
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $isLocal = ($host === 'localhost' || $host === '127.0.0.1') || ($ip === '127.0.0.1' || $ip === '::1');
        if (!$isLocal && !DatabaseManager::empresaHasLicense($empresaId)) {
            throw new RuntimeException('Su empresa no tiene una licencia activa.');
        }

        if (strlen($hash) === 32 && ctype_xdigit($hash) || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $pdo = DatabaseManager::master();
                $upd = $pdo->prepare('UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$newHash, (int)$user['id']]);
                $user['password_hash'] = $newHash;
            } catch (Throwable $e) {
            }
        }

        try {
            $pdo = DatabaseManager::master();
            $upd = $pdo->prepare('UPDATE usuarios_master SET ultimo_login_at = NOW() WHERE id = ?');
            $upd->execute([(int)$user['id']]);
        } catch (Throwable $e) {
        }

        return ['user' => $user, 'empresa' => $empresa];
    }

    public static function establishSession(array $masterUser, array $empresa, PDO $tenantPdo): void
    {
        $tenantUserId = self::ensureTenantUser($tenantPdo, $masterUser);

        $_SESSION['master_user_id'] = (int)($masterUser['id'] ?? 0);
        $_SESSION['empresa_id'] = (int)($empresa['id'] ?? 0);

        $_SESSION['user_id'] = (int)$tenantUserId;
        $_SESSION['user_name'] = (string)($masterUser['nombre'] ?? '');
        $_SESSION['user_role'] = (string)($masterUser['rol'] ?? 'admin');
        $_SESSION['user_email'] = (string)($masterUser['email'] ?? '');
        $_SESSION['user_photo'] = null;

        $_SESSION['tenant_id'] = 1;
        $_SESSION['tenant_company_name'] = (string)($empresa['nombre'] ?? '');
        $_SESSION['last_activity'] = time();
    }

    public static function ensureTenantUser(PDO $tenantPdo, array $masterUser): int
    {
        $email = (string)($masterUser['email'] ?? '');
        $name = (string)($masterUser['nombre'] ?? '');
        $role = (string)($masterUser['rol'] ?? 'admin');
        $hash = (string)($masterUser['password_hash'] ?? '');
        $active = (int)($masterUser['activo'] ?? 1);

        $stmt = $tenantPdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            $id = (int)$existingId;
            try {
                $upd = $tenantPdo->prepare('UPDATE users SET name = ?, role = ?, active = ?, password = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$name, $role, $active, $hash, $id]);
            } catch (Throwable $e) {
                try {
                    $upd = $tenantPdo->prepare('UPDATE users SET name = ?, role = ?, active = ?, password = ? WHERE id = ?');
                    $upd->execute([$name, $role, $active, $hash, $id]);
                } catch (Throwable $e2) {
                }
            }
            return $id;
        }

        try {
            $ins = $tenantPdo->prepare('INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (1, ?, ?, ?, ?, ?, NOW())');
            $ins->execute([$name, $email, $hash, $role, $active]);
            return (int)$tenantPdo->lastInsertId();
        } catch (Throwable $e) {
        }

        $ins = $tenantPdo->prepare('INSERT INTO users (name, email, password, role, active, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $ins->execute([$name, $email, $hash, $role, $active]);
        return (int)$tenantPdo->lastInsertId();
    }
}

