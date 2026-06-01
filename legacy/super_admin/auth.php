<?php
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_name('CORE_SA_SESSION');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appDir = realpath(__DIR__ . '/..');
    $basePath = '/';
    if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
        $rel = '/' . trim($rel, '/');
        $basePath = ($rel === '' || $rel === '/') ? '/' : $rel;
    }
    $saPath = rtrim($basePath, '/') . '/super_admin';
    session_set_cookie_params([
        'path' => $saPath,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!isset($_SESSION['csrf_token_sa']) || !is_string($_SESSION['csrf_token_sa']) || $_SESSION['csrf_token_sa'] === '') {
    $_SESSION['csrf_token_sa'] = bin2hex(random_bytes(32));
}

// Check if user is logged in as Super Admin
// The session value is now an array, so we check if it is set and not empty
if (!isset($_SESSION['SESSION_SAAS_SUPERADMIN']) || empty($_SESSION['SESSION_SAAS_SUPERADMIN'])) {
    header("Location: login.php");
    exit;
}

// Database connection
require_once __DIR__ . '/../config/database.php';
$pdo = db();
?>
