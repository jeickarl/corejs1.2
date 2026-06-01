<?php
require_once '../config/init_app.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST['csrf_token'] ?? '';
    if (!SecurityEnhancements::verifyCSRFToken($token)) {
        header("Location: index.php?error=Sesión%20expirada.%20Recarga%20e%20intenta%20de%20nuevo");
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!SecurityEnhancements::checkLoginAttempts($ip)) {
        header("Location: index.php?error=Demasiados intentos. Intenta más tarde");
        exit;
    }
    // Validar que los datos POST existan
    if (!isset($_POST["email"]) || !isset($_POST["password"])) {
        header("Location: index.php?error=Datos incompletos");
        exit;
    }
    
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    
    // Validar que no estén vacíos
    if (empty($email) || empty($password)) {
        header("Location: index.php?error=Email y contraseña son requeridos");
        exit;
    }
    
    // Validar formato de email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: index.php?error=Formato de email inválido");
        exit;
    }

    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    if (!$perDatabase) {
        $saasMode = getenv('SAAS_DB_MODE');
        $saasMode = is_string($saasMode) ? strtolower(trim($saasMode)) : '';
        $perDatabase = ($saasMode === 'per_database' || $saasMode === 'per-db' || $saasMode === 'perdb');
    }
    if ($perDatabase) {
        require_once '../config/tenant_manager.php';
        require_once '../config/database_manager.php';

        try {
            $auth = TenantManager::authenticate($email, $password);
            $empresaId = (int)($auth['empresa']['id'] ?? 0);
            $tenantPdo = DatabaseManager::tenant($empresaId);

            $pdo = $tenantPdo;
            TenantManager::establishSession($auth['user'], $auth['empresa'], $tenantPdo);

            regenerateSessionId();

            $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $cookiePath = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';
            $lifetime = $remember ? (365 * 24 * 60 * 60) : 1800;

            if ($remember) {
                setcookie('remember_me', '1', [
                    'expires' => time() + $lifetime,
                    'path' => $cookiePath,
                    'secure' => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                setcookie('remember_me', '', [
                    'expires' => time() - 3600,
                    'path' => $cookiePath
                ]);
            }

            $savedUsersCookie = isset($_COOKIE['saved_users']) ? json_decode($_COOKIE['saved_users'], true) : [];
            if (!is_array($savedUsersCookie)) $savedUsersCookie = [];
            $savedUsersCookie = array_filter($savedUsersCookie, function($u) use ($email) {
                return isset($u['email']) && $u['email'] !== $email;
            });
            $savedUsersCookie = array_values($savedUsersCookie);
            array_unshift($savedUsersCookie, [
                'email' => $email,
                'name' => (string)($_SESSION['user_name'] ?? explode('@', $email)[0]),
                'photo' => null
            ]);
            $savedUsersCookie = array_slice($savedUsersCookie, 0, 5);
            setcookie('saved_users', json_encode($savedUsersCookie), [
                'expires' => time() + (365 * 24 * 60 * 60),
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => false,
                'samesite' => 'Lax'
            ]);

            $tenantUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($tenantUserId > 0) {
                logActivity($tenantUserId, 'LOGIN', 'users', $tenantUserId);
            }

            SecurityEnhancements::logLoginAttempt($ip, $email, true);
            header("Location: ../dashboard/index.php");
            exit;
        } catch (Throwable $e) {
            SecurityEnhancements::logLoginAttempt($ip, $email, false);
            header("Location: index.php?error=Credenciales incorrectas");
            exit;
        }
    }

    // --- LÓGICA SAAS UNIFIED DB: Búsqueda de Inquilino ---
    // Verificar si el usuario pertenece a una empresa específica (SaaS)
    // Buscamos en la tabla `users` usando el email, lo cual nos dará el tenant_id
    
    try {
        // Buscar usuario globalmente por email
        // Nota: El índice único ahora es (email, tenant_id), por lo que un email podría repetirse en diferentes tenants.
        // Sin embargo, para el login simple, asumimos que el usuario ingresa su email y si está duplicado,
        // el sistema debería preguntar a cuál tenant entrar o asumir el último activo.
        // Por simplicidad inicial: Tomamos el primer usuario activo encontrado.
        
        $tenantId = null;
        try {
            $lk = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
            $lk->execute([$email]);
            $tenantId = $lk->fetchColumn();
        } catch (Exception $e) {
            $tenantId = null;
        }
        if ($tenantId) {
            $stmtUser = $pdo->prepare("
                SELECT u.*, t.company_name, t.slug as tenant_slug, t.status as tenant_status 
                FROM users u
                JOIN tenants t ON u.tenant_id = t.id
                WHERE u.email = ? AND u.tenant_id = ? AND u.active = 1
                LIMIT 1
            ");
            $stmtUser->execute([$email, $tenantId]);
        } else {
            $stmtUser = $pdo->prepare("
                SELECT u.*, t.company_name, t.slug as tenant_slug, t.status as tenant_status 
                FROM users u
                JOIN tenants t ON u.tenant_id = t.id
                WHERE u.email = ? AND u.active = 1
                LIMIT 1
            ");
            $stmtUser->execute([$email]);
        }
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Verificar estado del tenant
            if ($user['tenant_status'] !== 'active') {
                header("Location: index.php?error=La cuenta de su empresa está suspendida.");
                exit;
            }

            // Validar Contraseña
            $isValid = false;
            if (password_verify($password, $user['password'])) {
                $isValid = true;
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$newHash, $user['id']]);
                }
            } elseif (strlen($user['password']) === 32 && ctype_xdigit($user['password']) && md5($password) === $user['password']) {
                $isValid = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$newHash, $user['id']]);
            }

            if ($isValid) {
                // Configurar Sesión SaaS
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_photo'] = $user['photo'] ?? null;
                $_SESSION['tenant_id'] = $user['tenant_id'];
                $_SESSION['tenant_company_name'] = $user['company_name'];
                $_SESSION['last_activity'] = time();

                // Refrescar nombre de empresa desde company_config (caso alta por licencia crea en company_settings)
                try {
                    $stmtCfg = $pdo->prepare("SELECT company_name FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtCfg->execute([$user['tenant_id']]);
                    $cfgName = trim((string)$stmtCfg->fetchColumn());
                    if ($cfgName !== '') {
                        $_SESSION['tenant_company_name'] = $cfgName;
                    }
                } catch (Throwable $e) {}

                // Regenerar ID de sesión por seguridad
                regenerateSessionId();

                // Manejo de "No cerrar sesión" (Cookie persistente)
                $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
                $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
                $cookiePath = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';
                $lifetime = $remember ? (365 * 24 * 60 * 60) : 1800; // 1 año o 30 min
                
                // Cookie auxiliar remember_me
                if ($remember) {
                    setcookie('remember_me', '1', [
                        'expires' => time() + $lifetime,
                        'path' => $cookiePath,
                        'secure' => $isSecure,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                } else {
                    setcookie('remember_me', '', [
                        'expires' => time() - 3600,
                        'path' => $cookiePath
                    ]);
                }
                
                // --- COOKIE PARA CUENTAS GUARDADAS (Estilo Google) ---
                $savedUsersCookie = isset($_COOKIE['saved_users']) ? json_decode($_COOKIE['saved_users'], true) : [];
                if (!is_array($savedUsersCookie)) $savedUsersCookie = [];
                // Remover este email si ya existe para ponerlo de primero
                $savedUsersCookie = array_filter($savedUsersCookie, function($u) use ($user) {
                    return $u['email'] !== $user['email'];
                });
                $savedUsersCookie = array_values($savedUsersCookie); // reindex
                array_unshift($savedUsersCookie, [
                    'email' => $user['email'],
                    'name' => $user['name'] ?? explode('@', $user['email'])[0],
                    'photo' => $user['photo'] ?? null
                ]);
                $savedUsersCookie = array_slice($savedUsersCookie, 0, 5); // Max 5 accounts
                setcookie('saved_users', json_encode($savedUsersCookie), [
                    'expires' => time() + (365 * 24 * 60 * 60),
                    'path' => '/',
                    'secure' => $isSecure,
                    'httponly' => false,
                    'samesite' => 'Lax'
                ]);
                // -----------------------------------------------------

                // Registrar actividad de login
                // Nota: logActivity debería ser tenant-aware ahora
                logActivity($user['id'], 'LOGIN', 'users', $user['id']);
                
                SecurityEnhancements::logLoginAttempt($ip, $email, true);
                header("Location: ../dashboard/index.php");
                exit;
            } else {
                SecurityEnhancements::logLoginAttempt($ip, $email, false);
                header("Location: index.php?error=Credenciales incorrectas");
                exit;
            }
        } else {
            // Usuario no encontrado
            SecurityEnhancements::logLoginAttempt($ip, $email, false);
            header("Location: index.php?error=Credenciales incorrectas");
            exit;
        }

    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        header("Location: index.php?error=Error del sistema. Contacte soporte.");
        exit;
    }
    
    // El código original seguía aquí, pero con la nueva lógica SaaS unificada
    // todo el flujo se maneja dentro del bloque try-catch anterior.
    // Detenemos la ejecución aquí para evitar ejecutar código antiguo.
    exit;

    /* CÓDIGO ANTIGUO DESHABILITADO POR MIGRACIÓN A SINGLE-DB
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    ...
    */
}
?>
