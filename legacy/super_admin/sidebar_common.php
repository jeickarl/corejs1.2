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
$active = $sa_active ?? '';
?>
<div id="sidebar" class="sidebar-modern">
    <div class="sidebar-header">
        <div class="sidebar-brand d-flex align-items-center">
            <div class="brand-logo-container">
                <img src="../assets/img/system_logo.png" alt="Logo" class="brand-icon">
            </div>
            <div class="brand-text-container d-flex flex-column">
                <span class="brand-title">CORE</span>
                <span class="brand-subtitle">MASTER CONTROL</span>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <ul class="nav-list">
            <li class="nav-section mt-3">
                <span class="section-title">SUPER ADMIN</span>
            </li>
            <li class="nav-item">
                <a href="index.php" class="nav-link <?php echo $active==='dashboard'?'active':''; ?>">
                    <i class="fas fa-tachometer-alt nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="licenses.php" class="nav-link <?php echo $active==='licenses'?'active':''; ?>">
                    <i class="fas fa-key nav-icon"></i>
                    <span class="nav-text">Licencias</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="db_pool.php" class="nav-link <?php echo $active==='db_pool'?'active':''; ?>">
                    <i class="fas fa-layer-group nav-icon"></i>
                    <span class="nav-text">Pool de BDs</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="tenants.php" class="nav-link <?php echo $active==='tenants'?'active':''; ?>">
                    <i class="fas fa-building nav-icon"></i>
                    <span class="nav-text">Empresas</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="smtp_setup.php" class="nav-link <?php echo $active==='smtp'?'active':''; ?>">
                    <i class="fas fa-envelope nav-icon"></i>
                    <span class="nav-text">SMTP</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="email_templates.php" class="nav-link <?php echo $active==='templates'?'active':''; ?>">
                    <i class="fas fa-file-code nav-icon"></i>
                    <span class="nav-text">Plantillas Email</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="updates.php" class="nav-link <?php echo $active==='updates'?'active':''; ?>">
                    <i class="fas fa-database nav-icon"></i>
                    <span class="nav-text">Actualizaciones DB</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="security_setup.php" class="nav-link <?php echo $active==='security'?'active':''; ?>">
                    <i class="fas fa-shield-alt nav-icon"></i>
                    <span class="nav-text">Seguridad</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="health_check.php" class="nav-link <?php echo $active==='health'?'active':''; ?>">
                    <i class="fas fa-heartbeat nav-icon"></i>
                    <span class="nav-text">Diagnóstico</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="profile.php" class="nav-link <?php echo $active==='profile'?'active':''; ?>">
                    <i class="fas fa-circle-user nav-icon"></i>
                    <span class="nav-text">Mi Perfil</span>
                </a>
            </li>
            <li class="nav-item mt-auto">
                <a href="logout.php" class="nav-link text-danger">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <span class="nav-text">Cerrar Sesión</span>
                </a>
            </li>
        </ul>
    </nav>
</div>
<div id="sidebarOverlay" class="sidebar-overlay"></div>
