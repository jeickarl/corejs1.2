<?php
// Auto configuración para funcionar en raíz o subcarpeta sin cambios manuales
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$autoBaseUrl = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $autoBaseUrl = ($isHttps ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
}

$autoCookiePath = '/';
if (isset($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    $appDir = realpath(__DIR__ . '/..'); // directorio raíz de la app
    if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
        $rel = '/' . trim($rel, '/');
        $autoCookiePath = ($rel === '/' || $rel === '') ? '/' : $rel;
    }
}

$cookiePath = getenv('APP_COOKIE_PATH');
if (!$cookiePath || !is_string($cookiePath)) {
    $cookiePath = $autoCookiePath;
}

$assetsPath = getenv('APP_ASSETS_PATH');
if (!$assetsPath || !is_string($assetsPath)) {
    $assetsPath = rtrim($cookiePath, '/') . '/assets';
    if ($cookiePath === '/') {
        $assetsPath = '/assets';
    }
}

$baseUrl = getenv('APP_BASE_URL');
if (!$baseUrl || !is_string($baseUrl)) {
    $baseUrl = $autoBaseUrl;
}

$sessionName = getenv('APP_SESSION_NAME');
if (!$sessionName || !is_string($sessionName)) {
    $sessionName = 'CORE_SESSION';
}

$APP_CONFIG = [
    'base_url' => $baseUrl,
    'cookie_path' => $cookiePath,
    'assets_path' => $assetsPath,
    'session_name' => $sessionName
];
