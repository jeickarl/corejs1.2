<?php
session_name('CORE_SA_SESSION');
session_start();
$_SESSION = [];
$params = session_get_cookie_params();
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$appDir = realpath(__DIR__ . '/..');
$basePath = '/';
if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
    $rel = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    $rel = '/' . trim($rel, '/');
    $basePath = ($rel === '' || $rel === '/') ? '/' : $rel;
}
$saPath = rtrim($basePath, '/') . '/super_admin';
setcookie(session_name(), '', [
    'expires' => time() - 3600,
    'path' => $saPath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_destroy();
header("Location: login.php");
exit;
?>
