<?php
if (file_exists(__DIR__ . '/../config/security_enhancements.php')) {
  require_once __DIR__ . '/../config/security_enhancements.php';
}
$csrf_meta = '';
try {
  if (class_exists('SecurityEnhancements')) {
    $csrf_meta = (string)SecurityEnhancements::generateCSRFToken();
  } elseif (isset($_SESSION['csrf_token'])) {
    $csrf_meta = (string)$_SESSION['csrf_token'];
  }
} catch (Throwable $e) {
  $csrf_meta = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_meta, ENT_QUOTES); ?>">
    <title>Nexar - Sistema de Gestión</title>
    <?php if (file_exists(__DIR__ . '/../config/app_config.php')) { require_once __DIR__ . '/../config/app_config.php'; } ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/img/system_logo.png">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/css/modern-ui-enhancements.css?v=<?php echo time(); ?>">
    
    <?php
// Cargar color del tema desde system_config
if (!isset($pdo) && file_exists(__DIR__ . '/../config/database.php')) {
  require_once __DIR__ . '/../config/database.php';
}

$theme_color = 'black'; // Default negro
$primary = '#111111';
$primary_light = '#e5e7eb';
$primary_dark = '#000000';
$_theme_color_from_db = 'black';

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
      $_theme_color_from_db = $theme_color;
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
  } catch (Exception $e) {}
}
// Utilidades de color
function clamp_channel($v){ return max(0, min(255, (int)round($v))); }
function hex_to_rgb($hex) {
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) {
        return [
            hexdec(str_repeat($hex[0], 2)),
            hexdec(str_repeat($hex[1], 2)),
            hexdec(str_repeat($hex[2], 2))
        ];
    }
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
function rgb_to_hex($r,$g,$b){ return sprintf("#%02x%02x%02x", clamp_channel($r), clamp_channel($g), clamp_channel($b)); }
function adjust_brightness($hex, $percent) {
    [$r,$g,$b] = hex_to_rgb($hex);
    $r = $r + ($percent/100.0)*(255 - $r);
    $g = $g + ($percent/100.0)*(255 - $g);
    $b = $b + ($percent/100.0)*(255 - $b);
    return rgb_to_hex($r,$g,$b);
}
function adjust_darken($hex, $percent) {
    [$r,$g,$b] = hex_to_rgb($hex);
    $r = $r * (1 - $percent/100.0);
    $g = $g * (1 - $percent/100.0);
    $b = $b * (1 - $percent/100.0);
    return rgb_to_hex($r,$g,$b);
}
// Si el usuario eligió 'custom' y definió primary_color, derivar tonos claro/oscuro
if (isset($_theme_color_from_db) && $_theme_color_from_db === 'custom') {
    if (!empty($primary)) {
        if ($primary_light === '#e5e7eb' || $primary_light === '' || $primary_light === null) {
            $primary_light = adjust_brightness($primary, 60); // más claro
        }
        if ($primary_dark === '#000000' || $primary_dark === '' || $primary_dark === null) {
            $primary_dark = adjust_darken($primary, 25); // más oscuro
        }
    }
}
// Convert hex color to "r, g, b"
function hex_to_rgb_string($hex) {
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
    return $r . ', ' . $g . ', ' . $b;
}
$primary_dark_rgb = hex_to_rgb_string($primary_dark);
// theme mode
$theme_mode = 'light';
if (isset($pdo) && function_exists('getCurrentTenantId')) {
  try {
    $t_idm = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$t_idm;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    $tmstmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'theme_mode'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $tmstmt->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
    if ($tmrow = $tmstmt->fetch(PDO::FETCH_ASSOC)) $theme_mode = $tmrow['config_value'] ?: 'light';
  } catch (Exception $e) {}
}
?>
    
    <!-- Dynamic Theme Colors -->
    <style>
        :root {
            --primary-color: <?php echo $primary; ?>;
            --primary-light: <?php echo $primary_light; ?>;
            --primary-dark: <?php echo $primary_dark; ?>;
            --primary-dark-rgb: <?php echo $primary_dark_rgb; ?>;
            /* Bootstrap integration */
            --bs-primary: <?php echo $primary; ?>;
            --bs-primary-rgb: <?php echo hex_to_rgb_string($primary); ?>;
            --bs-focus-ring-color: rgba(<?php echo $primary_dark_rgb; ?>, .25);
        }
    </style>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    </head>
<?php
$sidebar_style = 'brand';
$sidebar_color = '#ffffff';
if (isset($pdo) && function_exists('getCurrentTenantId')) {
  try {
    $t_id2 = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tenantValue = $perDatabase ? 1 : (int)$t_id2;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sidebar_style'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $st->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
    if ($rw = $st->fetch(PDO::FETCH_ASSOC)) { $sidebar_style = $rw['config_value'] ?: 'brand'; if ($sidebar_style === 'dualWhiteDark') $sidebar_style = 'white'; }
    $sc = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sidebar_color'" . (($hasTenantSystem && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1");
    $sc->execute(($hasTenantSystem && !$perDatabase) ? [$tenantValue] : []);
    if ($rw2 = $sc->fetch(PDO::FETCH_ASSOC)) $sidebar_color = $rw2['config_value'] ?: '#ffffff';
  } catch (Exception $e) {}
}
$sidebar_style = 'white';
function hex_to_rgb_arr($hex) {
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
list($sr, $sg, $sb) = hex_to_rgb_arr($sidebar_color);
$brightness = ($sr * 299 + $sg * 587 + $sb * 114) / 1000;
$sidebar_text = $brightness > 170 ? '#1f2937' : '#ffffff';
// Calcular brillo del color primario para contrastes en estilo "Predeterminado"
list($pr, $pg, $pb) = hex_to_rgb_arr($primary);
$pbrightness = ($pr * 299 + $pg * 587 + $pb * 114) / 1000;
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
  } catch (Exception $e) {}
}
if ($active_bg_override !== '') {
  $ab = ltrim($active_bg_override, '#');
  $ar = hexdec(substr($ab, 0, 2));
  $ag = hexdec(substr($ab, 2, 2));
  $ab2 = hexdec(substr($ab, 4, 2));
  $abright = ($ar * 299 + $ag * 587 + $ab2 * 114) / 1000;
  $active_text_override = $abright > 170 ? '#000000' : '#ffffff';
}
// Determinar colores por defecto para activo según estilo
$computed_active_bg = '';
$computed_active_text = '';
if ($active_bg_override !== '') {
  $computed_active_bg = $active_bg_override;
  $computed_active_text = isset($active_text_override) ? $active_text_override : ($brightness > 170 ? '#000000' : '#ffffff');
} else {
  if ($sidebar_style === 'brand') {
    $computed_active_bg = 'var(--primary-color)';
    $computed_active_text = '#ffffff';
  } elseif ($sidebar_style === 'white') {
    // En estilo blanco, resaltar activo con gris suave y texto oscuro
    $computed_active_bg = '#e5e7eb';
    $computed_active_text = '#1f2937';
  } else {
    // estilo blanco o cualquier otro: derivar del fondo lateral
    ob_start();
    // Derivar texto por defecto dependiendo del brillo
    echo $brightness > 170 ? sprintf("#%02x%02x%02x", max(0, $sr - 30), max(0, $sg - 30), max(0, $sb - 30)) : '';
    $derived = ob_get_clean();
    $computed_active_bg = $brightness > 170 ? sprintf("#%02x%02x%02x", max(0, $sr - 30), max(0, $sg - 30), max(0, $sb - 30)) : (function($hex) {
      $hex = ltrim($hex, '#');
      $r = hexdec(substr($hex, 0, 2));
      $g = hexdec(substr($hex, 2, 2));
      $b = hexdec(substr($hex, 4, 2));
      $r = max(0, min(255, $r + (255 - $r) * 22 / 100));
      $g = max(0, min(255, $g + (255 - $g) * 22 / 100));
      $b = max(0, min(255, $b + (255 - $b) * 22 / 100));
      return sprintf("#%02x%02x%02x", $r, $g, $b);
    })($sidebar_color);
    $computed_active_text = $brightness > 170 ? '#000000' : '#ffffff';
  }
}
?>
<?php
$body_extra_class = '';
if ($sidebar_style === 'white') {
  $body_extra_class = 'theme-sidebar-white';
}
$body_classes = trim(($theme_mode === 'dark' ? 'theme-dark-base' : 'bg-light') . ' ' . $body_extra_class);
?>
<body class="<?php echo $body_classes; ?>">
<style>
  :root {
    --sidebar-bg: <?= $sidebar_style === 'brand' ? 'var(--primary-color)' : htmlspecialchars($sidebar_color) ?>;
    --sidebar-text: <?= $sidebar_style === 'brand' ? ($pbrightness > 170 ? '#1f2937' : '#ffffff') : htmlspecialchars($sidebar_text) ?>;
    --sidebar-hover-bg: <?php
      // Derivar hover a partir del fondo
      function adjust_color($hex, $percent) {
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
        echo $pbrightness > 170 ? sprintf("#%02x%02x%02x", max(0, $pr - 20), max(0, $pg - 20), max(0, $pb - 20)) : adjust_color($primary, 12);
      } else {
        echo $brightness > 170 ? sprintf("#%02x%02x%02x", max(0, $sr - 20), max(0, $sg - 20), max(0, $sb - 20)) : adjust_color($sidebar_color, 12);
      }
    ?>;
    --sidebar-active-bg: <?= htmlspecialchars($computed_active_bg) ?>;
    --sidebar-active-text: <?= htmlspecialchars($computed_active_text) ?>;
  }
<?php if ($sidebar_style === 'white'): ?>
  .theme-sidebar-white .sidebar-modern { background: #ffffff !important; }
  .theme-sidebar-white .sidebar-modern .nav-link,
  .theme-sidebar-white .sidebar-modern .nav-icon,
  .theme-sidebar-white .sidebar-modern .brand-text,
  .theme-sidebar-white .sidebar-modern .brand-icon { color: #1f2937 !important; }
  .theme-sidebar-white .sidebar-modern .nav-link:hover { background: #f1f5f9 !important; color: #1f2937 !important; }
  .theme-sidebar-white .sidebar-modern .nav-link.active,
  .theme-sidebar-white .sidebar-modern .nav-item.active .nav-link { background: var(--sidebar-active-bg) !important; color: var(--sidebar-active-text) !important; }
  .theme-sidebar-white .sidebar-modern .nav-link.active .nav-icon,
  .theme-sidebar-white .sidebar-modern .nav-item.active .nav-icon { color: var(--sidebar-active-text) !important; }
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
<script>
// Aplicar estado colapsado del sidebar antes de renderizar contenido
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
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/app.js?v=<?php echo time(); ?>"></script>
