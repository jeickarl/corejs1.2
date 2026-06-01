<?php
// Obtener configuración de empresa desde la base de datos
$company_config = null;

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/app_config.php';
    require_once __DIR__ . '/../config/functions.php';

    if (isset($pdo)) {
        $tenantId = function_exists('getCurrentTenantId') ? (int)getCurrentTenantId() : (int)($_SESSION['tenant_id'] ?? 0);
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
        $company_query = "SELECT * FROM company_config" . (($hasTenantCompany && !$perDatabase) ? " WHERE tenant_id = ? " : " ") . "LIMIT 1";
        $company_stmt = $pdo->prepare($company_query);
        $company_stmt->execute(($hasTenantCompany && !$perDatabase) ? [$tenantId] : []);
        $company_config = $company_stmt->fetch(PDO::FETCH_ASSOC);
    }
}
catch (Exception $e) {
    $company_config = null;
}

// Configuración por defecto si no existe en la base de datos
$company_name = $company_config['company_name'] ?? 'Mi Empresa';

// Lógica de Logo: Prioridad Empresa > Defecto Programa
$system_logo = 'system_logo.png';
$company_logo = $system_logo; // Por defecto el del programa

if (!empty($company_config['company_logo'])) {
    $check_path = __DIR__ . '/../assets/img/' . $company_config['company_logo'];
    if (file_exists($check_path)) {
        $company_logo = $company_config['company_logo']; // Si existe el de empresa, se usa (superpone)
    }
}

// Helper para determinar si un enlace está activo
function is_active($path)
{
    $current_path = $_SERVER['PHP_SELF'];
    // Extraer el nombre del directorio padre del script actual
    // Ejemplo: /core/cash/index.php -> cash
    $current_dir = basename(dirname($current_path));

    // Si el path contiene el directorio actual, es activo
    // path ejemplo: ../cash/index.php
    if (strpos($path, $current_dir) !== false) {
        return 'active';
    }
    return '';
}

// User Profile Logic (Moved from Footer)
$baseUploadsFs = __DIR__ . '/../uploads/';
$tenantUploadsFs = function_exists('getTenantUploadDir') ? getTenantUploadDir($baseUploadsFs) : $baseUploadsFs;
$photoName = $_SESSION['user_photo'] ?? '';
$tenantId = $_SESSION['tenant_id'] ?? null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$uploadsScopeId = $perDatabase ? (int)($_SESSION['empresa_id'] ?? 0) : (int)($tenantId ?? 0);
if ($uploadsScopeId <= 0) {
    $uploadsScopeId = (int)($tenantId ?? 0);
}
$pathBase = isset($APP_CONFIG['cookie_path']) ? (string)$APP_CONFIG['cookie_path'] : '/';

// Build paths based on current session info
$tenantFsPath = $photoName ? (rtrim($tenantUploadsFs, "/\\") . '/users/' . $photoName) : '';
$sharedFsPath = $photoName ? (rtrim($baseUploadsFs, "/\\") . '/users/' . $photoName) : '';
$userPhotoPath = ($photoName && file_exists($tenantFsPath)) ? $tenantFsPath : (($photoName && file_exists($sharedFsPath)) ? $sharedFsPath : '');

// If session photo is missing or file not found, try to refresh from DB (tenant-aware)
if (empty($userPhotoPath) && isset($pdo) && isset($_SESSION['user_id']) && $tenantId) {
    try {
        $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
        $hasTenantUsers = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'users') : false;
        $sql = "SELECT photo FROM users WHERE id = ?" . (($hasTenantUsers && !$perDatabase) ? " AND tenant_id = ?" : "") . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(($hasTenantUsers && !$perDatabase) ? [$_SESSION['user_id'], (int)$tenantId] : [$_SESSION['user_id']]);
        $dbPhoto = (string)($st->fetchColumn() ?: '');
        if ($dbPhoto !== '') {
            $photoName = $dbPhoto;
            $_SESSION['user_photo'] = $dbPhoto; // keep session in sync
            $tenantFsPath = rtrim($tenantUploadsFs, "/\\") . '/users/' . $photoName;
            $sharedFsPath = rtrim($baseUploadsFs, "/\\") . '/users/' . $photoName;
            $userPhotoPath = file_exists($tenantFsPath) ? $tenantFsPath : (file_exists($sharedFsPath) ? $sharedFsPath : '');
        }
    } catch (Throwable $e) {
        // silent
    }
}

// Compute URL only if we have a valid file path
$userPhotoUrl = '';
if (!empty($userPhotoPath) && !empty($photoName)) {
    if (strpos($userPhotoPath, rtrim($tenantUploadsFs, "/\\")) === 0) {
        $userPhotoUrl = rtrim($pathBase, '/') . '/uploads/' . ($uploadsScopeId ? $uploadsScopeId . '/' : '') . 'users/' . $photoName;
    } elseif (strpos($userPhotoPath, rtrim($baseUploadsFs, "/\\")) === 0) {
        $userPhotoUrl = rtrim($pathBase, '/') . '/uploads/users/' . $photoName;
    }
}
?>

<!-- Overlay para móvil -->
<div class="sidebar-overlay"></div>

<!-- Sidebar Moderna -->
<aside class="sidebar-modern d-flex flex-column" id="sidebar">

    <div class="sidebar-nav mt-2">
        <ul class="nav-list">
            <li class="nav-item">
                <a href="../dashboard/index.php" class="nav-link <?php echo is_active('../dashboard/index.php'); ?>">
                    <i class="fas fa-home nav-icon"></i>
                    <span class="nav-text">Inicio</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../orders/index.php" class="nav-link <?php echo is_active('../orders/index.php'); ?>">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    <span class="nav-text">Órdenes</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../clients/index.php" class="nav-link <?php echo is_active('../clients/index.php'); ?>">
                    <i class="fas fa-users nav-icon"></i>
                    <span class="nav-text">Clientes</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../billing/index.php" class="nav-link <?php echo is_active('../billing/index.php'); ?>">
                    <i class="fas fa-file-invoice-dollar nav-icon"></i>
                    <span class="nav-text">Ventas</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../cash/index.php" class="nav-link <?php echo is_active('../cash/index.php'); ?>">
                    <i class="fas fa-cash-register nav-icon"></i>
                    <span class="nav-text">Caja</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../inventory/index.php" class="nav-link <?php echo is_active('../inventory/index.php'); ?>">
                    <i class="fas fa-boxes nav-icon"></i>
                    <span class="nav-text">Inventario</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../suppliers/index.php" class="nav-link <?php echo is_active('../suppliers/index.php'); ?>">
                    <i class="fas fa-truck nav-icon"></i>
                    <span class="nav-text">Proveedores</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../config/settings.php" class="nav-link <?php echo is_active('../config/settings.php'); ?>">
                    <i class="fas fa-cog nav-icon"></i>
                    <span class="nav-text">Ajustes</span>
                </a>
            </li>
        </ul>
    </div>

        <!-- Sidebar Footer Removed -->
</aside>

<!-- Main content wrapper -->
<div class="main-content">
    <!-- Top Header -->
    <header class="top-header justify-content-between w-100 px-3 px-md-4">
        <!-- Logo and Menu Toggle Area (Left) -->
        <div class="header-left d-flex align-items-center gap-3">
            <button id="sidebarToggle" type="button" class="btn btn-light shadow-sm d-flex justify-content-center align-items-center" style="width: 40px; height: 40px; border-radius: 8px;" aria-label="Toggle Sidebar" onclick="(function(e){if(window.innerWidth>=992){document.body.classList.toggle('sidebar-collapsed');try{localStorage.setItem('sidebarCollapsed',document.body.classList.contains('sidebar-collapsed'));}catch(_){} }else{document.body.classList.toggle('sidebar-mobile-open');}})(event)">
                <i class="fas fa-bars text-secondary"></i>
            </button>
            <?php if (file_exists(__DIR__ . '/../config/app_config.php')) { require_once __DIR__ . '/../config/app_config.php'; } ?>
            <a href="../dashboard/index.php" id="logoToggleBtn" class="d-flex align-items-center text-decoration-none">
                <img src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/img/<?php echo htmlspecialchars($company_logo) . '?v=' . time(); ?>" alt="Logo" style="height: 32px; width: auto; object-fit: contain; margin-right: 12px; border-radius: 4px;">
                <span class="fw-bold fs-5 d-none d-sm-block text-dark" style="letter-spacing: -0.5px;"><?php echo htmlspecialchars($company_name); ?></span>
            </a>
            <div class="d-none d-md-block" style="width: 1px; height: 24px; background-color: #cbd5e1; margin: 0 5px;"></div>
            <h5 class="page-title mb-0 text-truncate d-none d-md-block text-muted fw-medium" style="max-width: 30vw; font-size: 1.05rem; position: relative; top: 1px;"><?php echo $page_title ?? 'Dashboard'; ?></h5>
        </div>

        <!-- User Profile and Notifications (Right) -->
        <div class="header-right d-flex align-items-center gap-2 gap-md-3">
             <div class="dropdown">
                 <button id="notifBellBtn" class="btn btn-light bg-white border shadow-sm position-relative rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                     <i class="fas fa-bell text-secondary"></i>
                     <span id="notifDot" class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="display:none;"></span>
                 </button>
                 <ul id="notifMenu" class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 py-0" style="border-radius: 12px; min-width: 320px;">
                     <li class="px-3 py-2 border-bottom"><span class="fw-semibold">Notificaciones</span></li>
                     <li id="notifItems">
                         <div class="px-3 py-3 text-muted small">Sin notificaciones</div>
                     </li>
                 </ul>
             </div>
             
             <!-- User Profile Dropdown -->
             <div class="dropdown">
                  <button class="btn btn-light bg-white border border-light-subtle shadow-sm rounded-pill d-flex align-items-center gap-2 py-1 px-2" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                      <?php if (!empty($photoName) && !empty($userPhotoPath) && !empty($userPhotoUrl)): ?>
                          <img src="<?php echo htmlspecialchars($userPhotoUrl); ?>?v=<?php echo time(); ?>" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                      <?php
else: ?>
                          <?php
    $displayName = trim($_SESSION['user_name'] ?? '');
    $initial = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'U';
?>
                          <div class="avatar-initial d-flex align-items-center justify-content-center fw-bold text-white shadow-sm" style="width: 32px; height: 32px; border-radius: 50%; font-size: 0.9rem; background: var(--primary-color);">
                              <?php echo htmlspecialchars($initial); ?>
                          </div>
                      <?php
endif; ?>
                      <div class="text-start d-none d-md-block lh-sm pe-2">
                          <div class="fw-bold text-dark" style="font-size: 0.85rem; padding-bottom: 1px;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Usuario'); ?></div>
                          <div class="text-secondary" style="font-size: 0.70rem; font-weight: 500;"><?php echo ucfirst(htmlspecialchars($_SESSION['user_role'] ?? 'Usuario')); ?></div>
                      </div>
                      <i class="fas fa-chevron-down text-secondary ms-1 pe-1 d-none d-sm-block" style="font-size: 0.75rem;"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 py-2" style="border-radius: 12px; min-width: 220px;">
                      <li class="px-3 py-2 mb-1 d-md-none border-bottom">
                          <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Usuario'); ?></div>
                          <div class="text-secondary" style="font-size: 0.75rem;"><?php echo ucfirst(htmlspecialchars($_SESSION['user_role'] ?? 'Usuario')); ?></div>
                      </li>
                      <li><a class="dropdown-item py-2 d-flex align-items-center gap-3 text-secondary" href="../config/settings.php#users"><i class="fas fa-user-circle fa-lg"></i> Mi Perfil</a></li>
                      <li><a class="dropdown-item py-2 d-flex align-items-center gap-3 text-secondary" href="../config/settings.php"><i class="fas fa-cog fa-lg"></i> Ajustes</a></li>
                      <li><hr class="dropdown-divider my-2"></li>
                      <li><a class="dropdown-item text-danger py-2 fw-semibold d-flex align-items-center gap-3" href="../login/logout.php"><i class="fas fa-sign-out-alt fa-lg"></i> Cerrar Sesión</a></li>
                  </ul>
             </div>
        </div>
    </header>
    <script>
    (function() {
      var header = document.querySelector('.top-header');
      if (!header) return;
      var startX = 0, startY = 0, startTime = 0, swiping = false;
      var threshold = 50, allowedTime = 600, restraint = 80;
      function onStart(e) {
        if (window.innerWidth > 768) return;
        var t = (e.changedTouches && e.changedTouches[0]) || (e.touches && e.touches[0]);
        if (!t) return;
        startX = t.pageX; startY = t.pageY; startTime = Date.now(); swiping = true;
      }
      function onEnd(e) {
        if (!swiping) return; swiping = false;
        if (window.innerWidth > 768) return;
        var t = (e.changedTouches && e.changedTouches[0]) || (e.touches && e.touches[0]);
        if (!t) return;
        var distX = t.pageX - startX, distY = t.pageY - startY;
        var elapsed = Date.now() - startTime;
        if (elapsed <= allowedTime && Math.abs(distY) <= restraint && Math.abs(distX) >= threshold) {
          var links = Array.prototype.slice.call(document.querySelectorAll('.sidebar-nav .nav-link'));
          if (!links.length) return;
          var idx = links.findIndex(function(l){ return /\bactive\b/.test(l.className); });
          if (idx < 0) {
            var path = location.pathname.toLowerCase();
            idx = links.findIndex(function(l){ var h=(l.getAttribute('href')||'').toLowerCase(); return h && path.indexOf(h.split('/').slice(-2).join('/'))>=0; });
            if (idx < 0) idx = 0;
          }
          var nextIdx = distX < 0 ? (idx + 1) % links.length : (idx - 1 + links.length) % links.length;
          var href = links[nextIdx].getAttribute('href');
          if (href) location.href = href;
        }
      }
      header.addEventListener('touchstart', onStart, { passive: true });
      header.addEventListener('touchend', onEnd, { passive: true });
    })();
    </script>
    <script>
    (function(){
        var basePath = <?php
        $basePath = isset($APP_CONFIG['cookie_path']) ? rtrim((string)$APP_CONFIG['cookie_path'], '/') : '';
        if ($basePath === '/') { $basePath = ''; }
        $reqPath = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : (isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '');
        if ($basePath !== '' && $reqPath !== '' && strpos($reqPath, $basePath) !== 0) {
            $basePath = '';
        }
        echo json_encode($basePath);
        ?>;
        function absUrl(p){
            var b = basePath || '';
            if (b && b[0] !== '/') b = '/' + b;
            return b + p;
        }
        var dot = document.getElementById('notifDot');
        var list = document.getElementById('notifItems');
        function fmtDate(s){ try{ var d=new Date(s); return d.toLocaleString(); }catch(_){ return s; } }
        function render(items){
            if (!list) return;
            if (!items || !items.length){
                list.innerHTML = '<div class="px-3 py-3 text-muted small">Sin notificaciones</div>';
                return;
            }
            var html = '';
            var idsToMark = [];
            for (var i=0;i<items.length;i++){
                var it = items[i];
                var cls = it.read ? '' : 'bg-light';
                var body = it.body ? ('<div class="small text-muted mt-1">'+escapeHtml(it.body)+'</div>') : '';
                html += '<div class="px-3 py-2 '+cls+' border-bottom">' +
                        '<div class="fw-semibold">'+escapeHtml(it.title)+'</div>' +
                        body +
                        '<div class="small text-muted mt-1">'+fmtDate(it.created_at)+'</div>' +
                        '</div>';
                if (!it.read) idsToMark.push(it.id);
            }
            list.innerHTML = html;
            if (idsToMark.length){
                try {
                    fetch(absUrl('/notifications/mark_read.php'), {
                        method:'POST',
                        headers:{'Content-Type':'application/json'},
                        credentials: 'same-origin',
                        body: JSON.stringify({ ids: idsToMark })
                    });
                } catch(_) {}
            }
        }
        function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);}); }
        var inFlight = false;
        var timer = null;
        var bootTimer = null;
        var stopped = false;
        function poll(){
            if (stopped) return;
            if (inFlight) return;
            inFlight = true;
            fetch(absUrl('/notifications/fetch.php'), { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                .then(function(r){
                    if (!r || !r.ok) return null;
                    if (typeof window.parseJsonResponse === 'function') {
                        return window.parseJsonResponse(r).then(function(d){
                            if (!d) return null;
                            if (d.success === false && (d.message === 'Respuesta no JSON' || d.message === 'JSON inválido')) return null;
                            return d;
                        });
                    }
                    return r.json().catch(function(){ return null; });
                })
                .then(function(data){
                    if (!data) return;
                    if (dot) dot.style.display = (data.unread && data.unread>0) ? 'block' : 'none';
                    render(data.items||[]);
                })
                .catch(function(){})
                .then(function(){ inFlight = false; });
        }
        timer = setInterval(poll, 25000);
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
        window.addEventListener('pagehide', function(){ stopped = true; try { if (timer) clearInterval(timer); } catch(_) {} timer = null; try { if (bootTimer) clearTimeout(bootTimer); } catch(_) {} bootTimer = null; });
        bootTimer = setTimeout(poll, 1200);
    })();
    </script>
