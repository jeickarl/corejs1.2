<?php
/**
 * Configuración mejorada de base de datos
 */

 require_once __DIR__ . '/env_loader.php';

// Configuración de la base de datos
date_default_timezone_set('America/Bogota');

// Desactivar visualización de errores en producción (Hostinger/Web)
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// --- LÓGICA SAAS MULTI-TENANT ---
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/functions.php';

$saasMode = getenv('SAAS_DB_MODE');
$saasMode = is_string($saasMode) ? strtolower(trim($saasMode)) : '';
if ($saasMode === '') {
    // Hardening: si por cualquier motivo getenv() no trae SAAS_DB_MODE
    // en una petición concreta, usamos la intención persistida en .env.local.
    $envLocalPath = __DIR__ . DIRECTORY_SEPARATOR . '.env.local';
    if (is_file($envLocalPath) && is_readable($envLocalPath)) {
        $envLocal = file_get_contents($envLocalPath);
        if (is_string($envLocal) && preg_match('/^\s*SAAS_DB_MODE\s*=\s*per_database\s*$/mi', $envLocal)) {
            $saasMode = 'per_database';
        }
    }
}
if ($saasMode === 'per_database' || $saasMode === 'per-db' || $saasMode === 'perdb') {
    require_once __DIR__ . '/database_manager.php';

    try {
        $pdo = null;
        $sessionHasUser = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
        $sessionHasEmpresa = isset($_SESSION['empresa_id']) && (int)$_SESSION['empresa_id'] > 0;
        if ($sessionHasUser && $sessionHasEmpresa) {
            $pdo = DatabaseManager::tenantFromSession();
        }
        if (!$pdo) {
            $pdo = DatabaseManager::master();
        }

        $pdo->query('SELECT 1');
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        $invalidTenantSessionMessages = [
            'Empresa no encontrada.',
            'Empresa inactiva.',
            'Configuración de base de datos incompleta.',
        ];
        $isInvalidTenantSession = ($e instanceof RuntimeException) && in_array($msg, $invalidTenantSessionMessages, true);
        if ($isInvalidTenantSession) {
            if (function_exists('destroySession')) {
                destroySession();
            } elseif (isset($_SESSION) && is_array($_SESSION)) {
                $_SESSION = [];
            }

            if (php_sapi_name() === 'cli') {
                echo "Sesión inválida.\n";
            } else {
                $isJson = false;
                if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                    $isJson = true;
                }
                if (!$isJson) {
                    $scriptName = basename($_SERVER['SCRIPT_NAME']);
                    if (strpos($scriptName, 'process_') === 0 ||
                        strpos($scriptName, 'ajax_') === 0 ||
                        strpos($_SERVER['SCRIPT_NAME'], '/ajax/') !== false ||
                        strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
                        $isJson = true;
                    }
                }

                if ($isJson) {
                    header('Content-Type: application/json');
                    http_response_code(401);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Sesión inválida. Inicia sesión nuevamente.'
                    ]);
                } else {
                    $base = '/core';
                    if (isset($APP_CONFIG) && is_array($APP_CONFIG) && isset($APP_CONFIG['cookie_path'])) {
                        $base = rtrim((string)$APP_CONFIG['cookie_path'], '/');
                        if ($base === '') {
                            $base = '/';
                        }
                    }
                    if ($base !== '/' && strpos($base, '/') !== 0) {
                        $base = '/' . $base;
                    }
                    $loginUrl = rtrim($base, '/') . '/login/index.php?error=' . rawurlencode('Sesión inválida. Inicia sesión nuevamente.');
                    header('Location: ' . $loginUrl);
                }
            }
            exit;
        }

        error_log("Error de conexión a la base de datos: " . $msg);

        if (php_sapi_name() === 'cli') {
            echo "Error de conexión a la base de datos.\n";
        } else {
            $isJson = false;
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                $isJson = true;
            }
            if (!$isJson) {
                $scriptName = basename($_SERVER['SCRIPT_NAME']);
                if (strpos($scriptName, 'process_') === 0 ||
                    strpos($scriptName, 'ajax_') === 0 ||
                    strpos($_SERVER['SCRIPT_NAME'], '/ajax/') !== false ||
                    strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
                    $isJson = true;
                }
            }

            if ($isJson) {
                header('Content-Type: application/json');
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'message' => 'Error de conexión a la base de datos. Por favor, intente más tarde.'
                ]);
            } else {
                http_response_code(503);
                include __DIR__ . '/../errors/database_error.html';
            }
        }
        exit;
    }
} else {
$default_db_name = 'core_db'; // Base de datos ÚNICA para todos los tenants
$current_db_name = $default_db_name;

// NOTA: Ya no cambiamos de base de datos dinámicamente.
// La separación de datos se hace lógicamente mediante `tenant_id` en las consultas.

$db_config = [
      'host' => 'localhost',
    'dbname' => $current_db_name,
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci"
    ]
];

try {
    // Si getCurrentTenantId no está definido (porque functions.php no se ha cargado aún), definirlo temporalmente o usar null
    $tenant_id = function_exists('getCurrentTenantId') ? getCurrentTenantId() : (isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : null);
    
    // Usar la clase TenantPDO si está definida (normalmente en functions.php que se carga antes o después dependiendo del flujo)
    // Para evitar errores de "Class not found", verificamos.
    if (class_exists('TenantPDO')) {
        $pdo = new TenantPDO(
            "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}",
            $db_config['username'],
            $db_config['password'],
            $db_config['options'],
            $tenant_id
        );
    } else {
        // Fallback a PDO normal si no estamos en un contexto donde functions.php esté cargado
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}",
            $db_config['username'],
            $db_config['password'],
            $db_config['options']
        );
    }

    // Verificar conexión
    $pdo->query('SELECT 1');
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    
    if (function_exists('ensureTenantBootstrap')) {
        ensureTenantBootstrap($pdo, $tenant_id);
    }

} catch (PDOException $e) {
    $recovered = false;
    $code = (string)$e->getCode();
    $msg = (string)$e->getMessage();
    $unknownDb = ($code === '1049') || (stripos($msg, 'unknown database') !== false);

    if ($unknownDb && is_file(__DIR__ . '/database_manager.php')) {
        require_once __DIR__ . '/database_manager.php';
        try {
            $pdo = null;
            $sessionHasUser = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
            $sessionHasEmpresa = isset($_SESSION['empresa_id']) && (int)$_SESSION['empresa_id'] > 0;
            if ($sessionHasUser && $sessionHasEmpresa) {
                $pdo = DatabaseManager::tenantFromSession();
            }
            if (!$pdo) {
                $pdo = DatabaseManager::master();
            }

            $pdo->query('SELECT 1');
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
            $pdo->exec("SET CHARACTER SET utf8mb4");
            $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
            $pdo->exec("SET time_zone = '" . date('P') . "'");

            $recovered = true;
        } catch (Throwable $e2) {
            $msg2 = (string)$e2->getMessage();
            $invalidTenantSessionMessages = [
                'Empresa no encontrada.',
                'Empresa inactiva.',
                'Configuración de base de datos incompleta.',
            ];
            $isInvalidTenantSession = ($e2 instanceof RuntimeException) && in_array($msg2, $invalidTenantSessionMessages, true);
            if ($isInvalidTenantSession) {
                if (function_exists('destroySession')) {
                    destroySession();
                } elseif (isset($_SESSION) && is_array($_SESSION)) {
                    $_SESSION = [];
                }

                if (php_sapi_name() === 'cli') {
                    echo "Sesión inválida.\n";
                } else {
                    $isJson = false;
                    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                        $isJson = true;
                    }
                    if (!$isJson) {
                        $scriptName = basename($_SERVER['SCRIPT_NAME']);
                        if (strpos($scriptName, 'process_') === 0 ||
                            strpos($scriptName, 'ajax_') === 0 ||
                            strpos($_SERVER['SCRIPT_NAME'], '/ajax/') !== false ||
                            strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
                            $isJson = true;
                        }
                    }

                    if ($isJson) {
                        header('Content-Type: application/json');
                        http_response_code(401);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Sesión inválida. Inicia sesión nuevamente.'
                        ]);
                    } else {
                        $base = '/core';
                        if (isset($APP_CONFIG) && is_array($APP_CONFIG) && isset($APP_CONFIG['cookie_path'])) {
                            $base = rtrim((string)$APP_CONFIG['cookie_path'], '/');
                            if ($base === '') {
                                $base = '/';
                            }
                        }
                        if ($base !== '/' && strpos($base, '/') !== 0) {
                            $base = '/' . $base;
                        }
                        $loginUrl = rtrim($base, '/') . '/login/index.php?error=' . rawurlencode('Sesión inválida. Inicia sesión nuevamente.');
                        header('Location: ' . $loginUrl);
                    }
                }
                exit;
            }
        }
    }

    if ($recovered) {
        if (function_exists('ensureTenantBootstrap')) {
            ensureTenantBootstrap($pdo, $tenant_id);
        }
    } else {
    // Manejo de error de conexión
    // ... código existente ...
    // die("Error de conexión a la base de datos: " . $e->getMessage());
    
    // Log del error
    error_log("Error de conexión a la base de datos: " . $e->getMessage());
    
    // Mostrar error amigable al usuario
    if (php_sapi_name() === 'cli') {
        echo "Error de conexión a la base de datos.\n";
    } else {
        // Detectar si es una solicitud JSON/AJAX
        $isJson = false;
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            $isJson = true;
        }
        
        // Fallback: Detectar por nombre de script o ruta
        if (!$isJson) {
            $scriptName = basename($_SERVER['SCRIPT_NAME']);
            // Scripts que comienzan con 'process_', 'ajax_', o están en carpetas 'ajax' o 'api'
            if (strpos($scriptName, 'process_') === 0 || 
                strpos($scriptName, 'ajax_') === 0 || 
                strpos($_SERVER['SCRIPT_NAME'], '/ajax/') !== false ||
                strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
                $isJson = true;
            }
        }
        
        if ($isJson) {
            header('Content-Type: application/json');
            http_response_code(503);
            echo json_encode([
                'success' => false, 
                'message' => 'Error de conexión a la base de datos. Por favor, intente más tarde.'
            ]);
        } else {
            http_response_code(503);
            include __DIR__ . '/../errors/database_error.html';
        }
    }
    exit;
    }
}
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('Conexión a base de datos no inicializada.');
}

function db(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Conexión a base de datos no inicializada.');
    }
    return $pdo;
}

/**
 * Función para ejecutar consultas de forma segura
 */
function executeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Error en consulta SQL: " . $e->getMessage() . " | SQL: " . $sql);
        throw $e;
    }
}

/**
 * Función para obtener un registro
 */
function fetchOne($pdo, $sql, $params = []) {
    $stmt = executeQuery($pdo, $sql, $params);
    return $stmt->fetch();
}

/**
 * Función para obtener múltiples registros
 */
function fetchAll($pdo, $sql, $params = []) {
    $stmt = executeQuery($pdo, $sql, $params);
    return $stmt->fetchAll();
}

/**
 * Función para insertar y obtener el ID
 */
function insertAndGetId($pdo, $sql, $params = []) {
    executeQuery($pdo, $sql, $params);
    return $pdo->lastInsertId();
}
?>
