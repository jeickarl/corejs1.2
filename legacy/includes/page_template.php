<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/security_enhancements.php';

// Asegurar que pdo esté disponible si no lo está
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

// Verificar sesión de caja globalmente
if (!isset($cash_session_open)) {
    $cash_session_open = isCashSessionOpen($pdo);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/index.php");
    exit();
}
SecurityEnhancements::setSecurityHeaders();
// Asegurar salida en UTF-8 para todo el HTML
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Sistema Core'; ?></title>
    <?php if (file_exists(__DIR__ . '/../config/app_config.php')) { require_once __DIR__ . '/../config/app_config.php'; } ?>
    <?php $assets_path = htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>
    <link rel="icon" type="image/png" href="<?php echo $assets_path; ?>/img/system_logo.png">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Estilos propios -->
    <link rel="stylesheet" href="<?php echo $assets_path; ?>/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $assets_path; ?>/css/modern-ui-enhancements.css?v=<?php echo time(); ?>">
    
    <?php
// Cargar color del tema desde system_config
if (!isset($pdo) && file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
}

$theme_color = 'black'; // Default negro
$primary = '#111111';
$primary_light = '#e5e7eb';
$primary_dark = '#000000';

if (isset($pdo) && function_exists('getCurrentTenantId')) {
    try {
        $t_id = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$t_id;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $sql = "SELECT config_value FROM system_config WHERE config_key = 'theme_color'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $theme_color = $row['config_value'];
        }
    }
    catch (Exception $e) {
    }
}

// Definir paletas
$palettes = [
    'black' => ['#111111', '#e5e7eb', '#000000'],
    'cherry' => ['#f43f5e', '#fb7185', '#e11d48'],
    'emerald' => ['#10b981', '#34d399', '#059669'],
    'royal' => ['#3b82f6', '#60a5fa', '#2563eb'],
    'amethyst' => ['#8b5cf6', '#a78bfa', '#7c3aed'],
    'onyx' => ['#334155', '#475569', '#1e293b']
];

if (isset($palettes[$theme_color])) {
    $primary = $palettes[$theme_color][0];
    $primary_light = $palettes[$theme_color][1];
    $primary_dark = $palettes[$theme_color][2];
}
if (isset($pdo) && function_exists('getCurrentTenantId')) {
    try {
        $t_idc = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$t_idc;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $q1 = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'primary_color'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $q1->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($r1 = $q1->fetch(PDO::FETCH_ASSOC)) { $val = trim($r1['config_value'] ?? ''); if ($val !== '') $primary = $val; }
        $q2 = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'primary_light'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $q2->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($r2 = $q2->fetch(PDO::FETCH_ASSOC)) { $val2 = trim($r2['config_value'] ?? ''); if ($val2 !== '') $primary_light = $val2; }
        $q3 = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'primary_dark'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $q3->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($r3 = $q3->fetch(PDO::FETCH_ASSOC)) { $val3 = trim($r3['config_value'] ?? ''); if ($val3 !== '') $primary_dark = $val3; }
    } catch (Exception $e) {
    }
}

// Calcular RGB para rgba() opacities
$hex = str_replace('#', '', $primary);
if (strlen($hex) == 3) {
    $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
    $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
    $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
}
else {
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
}
$primary_rgb = "$r, $g, $b";

$hexDark = str_replace('#', '', $primary_dark);
if (strlen($hexDark) == 3) {
    $rd = hexdec(substr($hexDark, 0, 1) . substr($hexDark, 0, 1));
    $gd = hexdec(substr($hexDark, 1, 1) . substr($hexDark, 1, 1));
    $bd = hexdec(substr($hexDark, 2, 1) . substr($hexDark, 2, 1));
}
else {
    $rd = hexdec(substr($hexDark, 0, 2));
    $gd = hexdec(substr($hexDark, 2, 2));
    $bd = hexdec(substr($hexDark, 4, 2));
}
$primary_dark_rgb = "$rd, $gd, $bd";
?>
    
    <!-- Dynamic Theme Colors -->
    <style>
        :root {
            --primary-color: <?php echo $primary; ?>;
            --primary-light: <?php echo $primary_light; ?>;
            --primary-dark: <?php echo $primary_dark; ?>;
            --primary-rgb: <?php echo $primary_rgb; ?>;
            --primary-dark-rgb: <?php echo $primary_dark_rgb; ?>;
        }
    </style>
<?php
$sidebar_style = 'white';
$sidebar_color = '#ffffff';
if (isset($pdo) && function_exists('getCurrentTenantId')) {
    try {
        $t_id2 = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$t_id2;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sidebar_style'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $st->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($rw = $st->fetch(PDO::FETCH_ASSOC)) $sidebar_style = $rw['config_value'] ?: 'brand';
        $sc = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sidebar_color'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $sc->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($rw2 = $sc->fetch(PDO::FETCH_ASSOC)) $sidebar_color = '#ffffff';
    } catch (Exception $e) {
    }
}
$sidebar_style = 'white';
function hex_to_rgb_vals($hex) {
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) {
        $r = hexdec(str_repeat($hex[0], 2));
        $g = hexdec(str_repeat($hex[1], 2));
        $b = hexdec(str_repeat($hex[2], 2));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return [$r, $g, $b];
}
list($sr, $sg, $sb) = hex_to_rgb_vals($sidebar_color);
$brightness = ($sr * 299 + $sg * 587 + $sb * 114) / 1000;
$sidebar_text = $brightness > 170 ? '#1f2937' : '#ffffff';
$pbrightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
$active_bg_override = '';
if (isset($pdo) && function_exists('getCurrentTenantId')) {
    try {
        $t_id3 = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$t_id3;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $ac = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sidebar_active_bg'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
        $ac->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($rw3 = $ac->fetch(PDO::FETCH_ASSOC)) $active_bg_override = trim($rw3['config_value'] ?? '');
    } catch (Exception $e) {
    }
}
if ($active_bg_override !== '') {
    $ab = ltrim($active_bg_override, '#');
    $ar = hexdec(substr($ab, 0, 2));
    $ag = hexdec(substr($ab, 2, 2));
    $ab2 = hexdec(substr($ab, 4, 2));
    $abright = ($ar * 299 + $ag * 587 + $ab2 * 114) / 1000;
    $active_text_override = $abright > 170 ? '#000000' : '#ffffff';
}
?>
    <style>
  :root {
    --sidebar-bg: <?= $sidebar_style === 'brand' ? 'var(--primary-color)' : htmlspecialchars($sidebar_color) ?>;
    --sidebar-text: <?= $sidebar_style === 'brand' ? ($pbrightness > 170 ? '#1f2937' : '#ffffff') : htmlspecialchars($sidebar_text) ?>;
    --sidebar-hover-bg: <?php
      function adjust_color_pt($hex, $percent) {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = max(0, min(255, $r + (255 - $r) * $percent / 100));
        $g = max(0, min(255, $g + (255 - $g) * $percent / 100));
        $b = max(0, min(255, $b + (255 - $b) * $percent / 100));
        printf("#%02x%02x%02x", $r, $g, $b);
      }
      if ($sidebar_style === 'brand') {
        echo $pbrightness > 170 ? sprintf("#%02x%02x%02x", max(0, $r - 20), max(0, $g - 20), max(0, $b - 20)) : adjust_color_pt($primary, 12);
      } else {
        echo $brightness > 170 ? sprintf("#%02x%02x%02x", max(0, $sr - 20), max(0, $sg - 20), max(0, $sb - 20)) : adjust_color_pt($sidebar_color, 12);
      }
    ?>;
    --sidebar-active-bg: <?= htmlspecialchars($active_bg_override !== '' ? $active_bg_override : ($brightness > 170 ? sprintf("#%02x%02x%02x", max(0, $sr - 30), max(0, $sg - 30), max(0, $sb - 30)) : adjust_color_pt($sidebar_color, 22))) ?>;
    --sidebar-active-text: <?= htmlspecialchars(isset($active_text_override) ? $active_text_override : ($sidebar_style === 'brand' ? '#ffffff' : ($brightness > 170 ? '#000000' : '#ffffff'))) ?>;
  }
<?php if ($sidebar_style === 'white'): ?>
.sidebar-modern { background: #ffffff !important; }
.sidebar-modern .nav-link,
.sidebar-modern .nav-icon,
.sidebar-modern .brand-text,
.sidebar-modern .brand-icon { color: #1f2937 !important; }
.sidebar-modern .nav-link:hover { background: #f1f5f9 !important; color: #1f2937 !important; }
.sidebar-modern .nav-link.active,
.sidebar-modern .nav-item.active .nav-link { background: var(--sidebar-active-bg) !important; color: var(--sidebar-active-text) !important; }
.sidebar-modern .nav-link.active .nav-icon,
.sidebar-modern .nav-item.active .nav-icon { color: var(--sidebar-active-text) !important; }
<?php else: ?>
.sidebar-modern { background: var(--sidebar-bg) !important; }
.sidebar-modern .nav-link,
.sidebar-modern .nav-icon,
.sidebar-modern .brand-text,
.sidebar-modern .brand-icon { color: var(--sidebar-text) !important; }
.sidebar-modern .nav-link:hover { background: var(--sidebar-hover-bg) !important; color: var(--primary-color) !important; }
.sidebar-modern .nav-link.active,
.sidebar-modern .nav-item.active .nav-link { background: var(--sidebar-active-bg) !important; color: var(--sidebar-active-text) !important; }
.sidebar-modern .nav-link.active .nav-icon,
.sidebar-modern .nav-item.active .nav-icon { color: var(--sidebar-active-text) !important; }
.sidebar-modern .nav-link:hover .nav-icon { color: var(--primary-color) !important; }
<?php endif; ?>
    </style>
    
    <!-- CSS adicional específico de la página -->
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php
    endforeach; ?>
    <?php
endif; ?>

    <!-- Configuración Global del Sistema -->
    <script>
        window.SYSTEM_CONFIG = <?php
// Wrap in try-catch to avoid fatal errors breaking the JS
try {
    echo json_encode([
        'currency' => class_exists('CompanySettings') ?CompanySettings::getCurrency() : ['symbol' => '$', 'code' => 'USD'],
        'phone' => class_exists('CompanySettings') ?CompanySettings::getPhoneConfig() : ['prefix' => '', 'country' => '']
    ]);
}
catch (Throwable $e) {
    echo json_encode([
        'currency' => ['symbol' => '$', 'code' => 'USD'],
        'phone' => ['prefix' => '', 'country' => '']
    ]);
}
?>;
    </script>
    <script src="<?php echo $assets_path; ?>/js/utils.js"></script>
</head>
<?php
$sidebar_style = 'brand';
if (isset($pdo) && function_exists('getCurrentTenantId')) {
    try {
        $t_id2 = getCurrentTenantId();
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $tenantValue = $perDatabase ? 1 : (int)$t_id2;
        $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
        $sql = "SELECT config_value FROM system_config WHERE config_key = 'sidebar_style'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
        if ($row2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $sidebar_style = $row2['config_value'] ?: 'brand';
            if ($sidebar_style === 'dualWhiteDark') $sidebar_style = 'white';
        }
    } catch (Exception $e) {
    }
}
$sidebar_style = 'white';
$body_extra_class = '';
if ($sidebar_style === 'white') {
    $body_extra_class = 'theme-sidebar-white';
}
?>
<body class="bg-light <?php echo $body_extra_class; ?>">
<script>
// Aplicar estado colapsado del sidebar antes del contenido
(function() {
  try {
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
      document.body.classList.add('sidebar-collapsed');
    }
  } catch (e) {}
})();
</script>
<script>
// Acento visual en barra superior al hacer scroll
(function() {
  function updateHeaderScroll() {
    var th = document.querySelector('.top-header');
    if (!th) return;
    if (window.scrollY > 0) th.classList.add('scrolled');
    else th.classList.remove('scrolled');
  }
  window.addEventListener('scroll', updateHeaderScroll, { passive: true });
  document.addEventListener('DOMContentLoaded', updateHeaderScroll);
})();
</script>
    
    <!-- El Navbar móvil superior ha sido eliminado. El Mini-Sidebar permanente se encarga de la navegación. -->
    
    <!-- Incluir sidebar (Este archivo abre main-content-wrapper y main-content) -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Incluir modal de apertura de caja (Global) -->
    <?php include __DIR__ . '/modals/cash_open_modal.php'; ?>
        <div class="container-fluid px-2 px-md-4 py-4">
            <?php if (isset($page_content)): ?>
                <?php echo $page_content; ?>
            <?php
endif; ?>
        </div>
        
    </div> <!-- End main-content -->
</div> <!-- End main-content-wrapper -->
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php
      $assets_path = '/assets';
      if (file_exists(__DIR__ . '/../config/app_config.php')) {
          require_once __DIR__ . '/../config/app_config.php';
          $assets_path = $APP_CONFIG['assets_path'] ?? '/assets';
      }
    ?>
    <script src="<?php echo htmlspecialchars($assets_path); ?>/js/app.js?v=<?php echo time(); ?>"></script>
    
    <!-- JavaScript adicional específico de la página -->
    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php
    endforeach; ?>
    <?php
endif; ?>
    
</body>
</html>
