<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/app_config.php';
/**
 * Configuración segura de sesiones
 */

// Configurar parámetros de sesión antes de iniciarla (solo si no está activa)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
}

// Determinar si el usuario eligió no cerrar sesión automáticamente
// Considerar tanto cookie persistente como el POST del login inicial
$remember = (isset($_COOKIE['remember_me']) && $_COOKIE['remember_me'] === '1')
    || (isset($_POST['remember_me']) && $_POST['remember_me'] === '1');
$lifetime = $remember ? (365 * 24 * 60 * 60) : 1800; // 1 año o 30 min

// Configurar tiempo de vida de la sesión según preferencia (solo si no está activa)
if (session_status() === PHP_SESSION_NONE) {
    // Asegurar ruta de almacenamiento de sesiones válida
    $savePathRaw = (string)ini_get('session.save_path');
    $savePath = trim($savePathRaw);
    // PHP puede devolver formatos como "N;C:\\ruta\\sessions"
    if (strpos($savePath, ';') !== false) {
        $parts = explode(';', $savePath);
        $savePath = trim((string)end($parts));
    }

    $projectSessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    $candidates = [];
    if ($savePath !== '') { $candidates[] = $savePath; }
    $fallback = sys_get_temp_dir();
    if (is_string($fallback) && $fallback !== '') { $candidates[] = $fallback; }
    $candidates[] = $projectSessionPath;

    $chosenPath = null;
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') { continue; }
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0777, true);
        }
        if (is_dir($candidate) && is_writable($candidate)) {
            $chosenPath = $candidate;
            break;
        }
    }
    if ($chosenPath !== null) {
        ini_set('session.save_path', $chosenPath);
    }
    
    ini_set('session.gc_maxlifetime', $lifetime);
    ini_set('session.cookie_lifetime', $lifetime);
    $cookiePath = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';
    $reqPath = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : (isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/');
    if (strpos($reqPath, $cookiePath) !== 0) { $cookiePath = '/'; }
    $sessionName = isset($APP_CONFIG['session_name']) ? (string)$APP_CONFIG['session_name'] : 'CORE_SESSION';
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => $cookiePath,
        'secure' => isset($isHttps) ? $isHttps : false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    $started = @session_start();
    if (!$started) {
        // Si falla la lectura del archivo de sesión, intentamos forzar una sesión nueva
        @session_id(bin2hex(random_bytes(16)));
        $projectSessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($projectSessionPath)) {
            @mkdir($projectSessionPath, 0777, true);
        }
        if (is_dir($projectSessionPath) && is_writable($projectSessionPath)) {
            @ini_set('session.save_path', $projectSessionPath);
        }
        $started = @session_start();
        if (!$started) {
            error_log('No se pudo iniciar la sesión PHP. Verifica permisos en session.save_path=' . (string)ini_get('session.save_path'));
        }
    }
}

/**
 * Regenerar ID de sesión para prevenir session fixation
 */
function regenerateSessionId() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Verificar si la sesión es válida
 */
function isValidSession() {
    // Verificar si existe user_id
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $saasMode = getenv('SAAS_DB_MODE');
    $saasMode = is_string($saasMode) ? strtolower(trim($saasMode)) : '';
    if ($saasMode === 'per_database' || $saasMode === 'per-db' || $saasMode === 'perdb') {
        if (!isset($_SESSION['empresa_id']) || (int)$_SESSION['empresa_id'] <= 0) {
            return false;
        }
    }
    
    // Verificar timeout de sesión (condicional con remember_me)
    if (isset($_SESSION['last_activity'])) {
        $remember = isset($_COOKIE['remember_me']) && $_COOKIE['remember_me'] === '1';
        $timeout = $remember ? (365 * 24 * 60 * 60) : 1800; // 1 año o 30 min
        if (time() - $_SESSION['last_activity'] > $timeout) {
            destroySession();
            return false;
        }
    }
    
    // Actualizar última actividad
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Destruir sesión de forma segura
 */
function destroySession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Limpiar cookie remember_me si existe
        if (isset($_COOKIE['remember_me'])) {
            $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $cookiePath = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';
            $reqPath = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : (isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/');
            if (strpos($reqPath, $cookiePath) !== 0) { $cookiePath = '/'; }
            setcookie('remember_me', '', [
                'expires' => time() - 3600,
                'path' => $cookiePath,
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        session_destroy();
    }
}

/**
 * Verificar autenticación y redirigir si es necesario
 */
function requireAuth($redirect_url = '../login/index.php') {
    if (!isValidSession()) {
        $isAjax = false;
        try {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                $isAjax = true;
            }
            if (!$isAjax && isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                $isAjax = true;
            }
            if (!$isAjax) {
                $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
                $base = basename($script);
                if (strpos($base, 'ajax_') === 0 || strpos($base, 'process_') === 0 || strpos($script, '/ajax/') !== false || strpos($script, '/api/') !== false) {
                    $isAjax = true;
                }
            }
        } catch (Throwable $e) {
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión inválida. Inicia sesión nuevamente.']);
            exit();
        }

        header("Location: $redirect_url");
        exit();
    }
}

/**
 * Verificar rol de usuario
 */
function requireRole($required_role) {
    requireAuth();
    
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== $required_role) {
        header("HTTP/1.1 403 Forbidden");
        die("Acceso denegado: No tienes permisos para acceder a esta página.");
    }
}

/**
 * Verificar si el usuario tiene uno de los roles especificados
 */
function hasRole($roles) {
    if (!isValidSession()) {
        return false;
    }
    
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $normalize = function($r) {
        $r = strtolower(trim((string)$r));
        if ($r === 'administrador' || $r === 'administrator') return 'admin';
        if ($r === 'tecnico' || $r === 'technician') return 'technician';
        if ($r === 'inventario' || $r === 'inventory_manager') return 'inventory';
        if ($r === 'owner' || $r === 'super_admin' || $r === 'superadmin') return 'admin';
        return $r;
    };
    
    // Si se pasa un string, convertir a array
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    $current = $normalize($_SESSION['user_role']);
    $targets = array_map($normalize, $roles);
    return in_array($current, $targets, true);
}

/**
 * Obtener información del usuario actual
 */
function getCurrentUser() {
    if (!isValidSession()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? null
    ];
}
?>
