<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/company_settings.php';

$slug = isset($_GET['t']) ? trim($_GET['t']) : '';
$tenant_id = $slug ? getTenantIdFromSlug($slug) : null;
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
// Redirección a URL amigable si viene como número (T1/T2 -> /portal/<slug>)
if ($tenant_id && ctype_digit($slug)) {
    $pretty = getTenantPreferredSlug((int)$tenant_id);
    if ($pretty) {
        $dest = rtrim($pretty, '/') . '/';
        header('Location: ' . $dest, true, 302);
        exit;
    }
}
if (!$tenant_id) {
    http_response_code(404);
    echo 'Portal no disponible';
    exit;
}

if ($perDatabase && class_exists('DatabaseManager')) {
    try {
        $pdo = DatabaseManager::tenant((int)$tenant_id);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET collation_connection = utf8mb4_spanish_ci");
    } catch (Throwable $e) {
        http_response_code(503);
        echo 'Portal no disponible';
        exit;
    }
}

$company = [];
try {
    $hasTenantCompany = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'company_config') : false;
    if (!$perDatabase && $hasTenantCompany) {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo, company_phone, company_email, company_address FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([$tenant_id]);
    } else {
        $stmtCompany = $pdo->prepare("SELECT company_name, company_logo, company_phone, company_email, company_address FROM company_config ORDER BY id DESC LIMIT 1");
        $stmtCompany->execute([]);
    }
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: [];
}
catch (Throwable $e) {
}

$cfgMap = [
    'client_portal_home_title' => '',
    'client_portal_home_subtitle' => '',
    'client_portal_hero_image' => '',
    'client_portal_whatsapp_link' => '',
    'client_portal_about_text' => '',
    'client_portal_about_image' => '',
    'client_portal_services' => '[]',
    'client_portal_social_links' => '{}',
    'client_portal_map_embed_url' => '',
    'client_portal_address_text' => '',
    'client_portal_hours_text' => '',
    'client_portal_featured_video_url' => '',
    'client_portal_benefits' => '[]',
    'client_portal_gallery_images' => '[]'
];
try {
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    if (!$perDatabase && $hasTenantSystem) {
        $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ? AND config_key IN ('client_portal_home_title','client_portal_home_subtitle','client_portal_hero_image','client_portal_whatsapp_link','client_portal_about_text','client_portal_about_image','client_portal_services','client_portal_social_links','client_portal_map_embed_url','client_portal_address_text','client_portal_hours_text','client_portal_featured_video_url','client_portal_benefits','client_portal_gallery_images')");
        $sel->execute([$tenant_id]);
    } else {
        $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('client_portal_home_title','client_portal_home_subtitle','client_portal_hero_image','client_portal_whatsapp_link','client_portal_about_text','client_portal_about_image','client_portal_services','client_portal_social_links','client_portal_map_embed_url','client_portal_address_text','client_portal_hours_text','client_portal_featured_video_url','client_portal_benefits','client_portal_gallery_images')");
        $sel->execute([]);
    }
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $k = $row['config_key'];
        $v = (string)$row['config_value'];
        if (array_key_exists($k, $cfgMap)) {
            $cfgMap[$k] = $v;
        }
    }
}
catch (Throwable $e) {
}
function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
$services = json_decode($cfgMap['client_portal_services'] ?: '[]', true);
if (!is_array($services))
    $services = [];
$social = json_decode($cfgMap['client_portal_social_links'] ?: '{}', true);
if (!is_array($social))
    $social = [];
$sample_defaults = [
    'instagram' => 'https://www.instagram.com/roytech_reparacion/',
    'tiktok' => 'https://www.tiktok.com/@roytech92',
    'facebook' => 'https://www.facebook.com/61566778799546',
    'youtube' => '',
    'featured_video_url' => 'https://www.facebook.com/61566778799546/videos/reparación-roytech_reparacion-electronica-serviciotecnico/3719716574989717/'
];
foreach ($sample_defaults as $k => $v) {
    if (empty($social[$k])) {
        $social[$k] = $v;
    }
}
$whatsapp_link = (string)($cfgMap['client_portal_whatsapp_link'] ?? '');
if ($whatsapp_link === '') {
    $phone_raw = (string)($company['company_phone'] ?? '');
    $phone_digits = preg_replace('/\D+/', '', $phone_raw);
    if ($phone_digits !== '') {
        $whatsapp_link = 'https://wa.me/' . $phone_digits . '?text=' . rawurlencode('Hola, quisiera consultar mi orden');
    }
}
$about_text = (string)($cfgMap['client_portal_about_text'] ?? '');
$featured_video_url = (string)($cfgMap['client_portal_featured_video_url'] ?? '');
if ($featured_video_url === '' && !empty($social['facebook'])) {
    $featured_video_url = 'https://www.facebook.com/61566778799546/videos/reparación-roytech_reparacion-electronica-serviciotecnico/3719716574989717/';
}
$featured_platform = '';
$featured_embed_html = '';
$featured_orientation = '16-9';
if ($featured_video_url) {
    $vid = '';
    if (preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})/i', $featured_video_url, $m)) {
        $vid = $m[1];
    }
    if (!$vid && preg_match('/v=([A-Za-z0-9_-]{11})/i', $featured_video_url, $m)) {
        $vid = $m[1];
    }
    if ($vid) {
        $featured_platform = 'youtube';
        $featured_embed_html = '<iframe width="100%" height="100%" src="https://www.youtube.com/embed/' . htmlspecialchars($vid, ENT_QUOTES, 'UTF-8') . '" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
        if (strpos($featured_video_url, 'shorts') !== false) {
            $featured_orientation = '9-16';
        }
    }
    elseif (strpos($featured_video_url, 'facebook.com') !== false) {
        $featured_platform = 'facebook';
        $featured_embed_html = '<iframe width="100%" height="100%" src="https://www.facebook.com/plugins/video.php?href=' . rawurlencode($featured_video_url) . '&show_text=false" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe>';
        $featured_orientation = '9-16';
    }
    elseif (strpos($featured_video_url, 'tiktok.com') !== false) {
        $featured_platform = 'tiktok';
        $featured_embed_html = '<blockquote class="tiktok-embed" cite="' . htmlspecialchars($featured_video_url, ENT_QUOTES, 'UTF-8') . '" style="max-width: 605px; min-width: 325px;"><section></section></blockquote>';
        $featured_orientation = '9-16';
    }
    elseif (strpos($featured_video_url, 'instagram.com') !== false) {
        $featured_platform = 'instagram';
        $featured_embed_html = '<blockquote class="instagram-media" data-instgrm-permalink="' . htmlspecialchars($featured_video_url, ENT_QUOTES, 'UTF-8') . '" style=" background:#FFF; border:0; margin: 0 auto; max-width:540px; width:100%;"></blockquote>';
        $featured_orientation = '9-16';
    }
}

// Fallbacks para mapa y galería
$company_addr_fallback = trim((string)($company['company_address'] ?? ''));
$map_url_raw = (string)($cfgMap['client_portal_map_embed_url'] ?? '');
if ($map_url_raw === '' && $company_addr_fallback !== '') {
    $map_url_raw = 'https://maps.google.com/maps?q=' . rawurlencode($company_addr_fallback) . '&z=16&output=embed';
}
$default_embed = 'https://maps.google.com/maps?q=Colombia&z=6&output=embed';
$map_url = $map_url_raw !== '' ? $map_url_raw : $default_embed;
if (stripos($map_url, 'http://') === 0) {
    $map_url = 'https://' . substr($map_url, 7);
}
$u = parse_url($map_url);
$h = strtolower($u['host'] ?? '');
$p = strtolower($u['path'] ?? '');
$q = $u['query'] ?? '';
$isGoogle = (strpos($h, 'google.com') !== false) || (strpos($h, 'maps.google.com') !== false);
$isShort = (strpos($h, 'maps.app.goo.gl') !== false) || (strpos($h, 'goo.gl') !== false);
if ($isShort) {
    $map_url = $default_embed;
}
elseif ($isGoogle) {
    if (strpos($p, '/maps/embed') === false) {
        if (strpos($p, '/maps') !== false) {
            $map_url = 'https://www.google.com/maps/embed?' . ($q ? $q . '&' : '') . 'output=embed';
        }
        else {
            $map_url = $default_embed;
        }
    }
    else {
        if ($q && stripos($q, 'output=embed') === false) {
            $map_url .= (strpos($map_url, '?') !== false ? '&' : '?') . 'output=embed';
        }
    }
}
else {
    $map_url = $default_embed;
}
$address_text = !empty($cfgMap['client_portal_address_text'])
    ? $cfgMap['client_portal_address_text']
    : ($company_addr_fallback !== '' ? $company_addr_fallback : 'Colombia');
$hours_text = !empty($cfgMap['client_portal_hours_text'])
    ? $cfgMap['client_portal_hours_text']
    : 'Lunes a Viernes de 9:00 a 18:00';

$benefits = json_decode($cfgMap['client_portal_benefits'] ?: '[]', true);
if (!is_array($benefits) || count($benefits) === 0) {
    $benefits = [
        ['icon' => 'fa-solid fa-bolt', 'title' => 'Diagnóstico Rápido', 'desc' => 'Evaluación inicial en minutos para identificar el problema de tu equipo.'],
        ['icon' => 'fa-solid fa-microchip', 'title' => 'Reparación Especializada', 'desc' => 'Técnicos expertos y equipamiento profesional para reparaciones complejas.'],
        ['icon' => 'fa-solid fa-shield-heart', 'title' => 'Garantía de Servicio', 'desc' => 'Todas nuestras reparaciones están garantizadas. Tu confianza es nuestra prioridad.'],
    ];
}
$gallery_images = json_decode($cfgMap['client_portal_gallery_images'] ?: '[]', true);
if (!is_array($gallery_images) || count($gallery_images) === 0) {
    $gallery_images = [
        'https://picsum.photos/seed/rep1/600/400',
        'https://picsum.photos/seed/rep2/600/400',
        'https://picsum.photos/seed/rep3/600/400'
    ];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc($company['company_name'] ?? ''); ?> - Portal</title>
    <link rel="icon" type="image/png" href="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo esc($company['company_logo'] ?? 'logo.png'); ?>">
    
    <!-- Google Fonts: Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --primary-light: #e0f2fe;
            --text-main: #2b3445;
            --text-muted: #7d879c;
            --bg-color: #f3f6f9;
        }

        body { 
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color); 
            background-image: radial-gradient(circle at top right, rgba(13, 110, 253, 0.05) 0%, transparent 40%),
                              radial-gradient(circle at bottom left, rgba(13, 110, 253, 0.08) 0%, transparent 40%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
        }
        
        .btn { border-radius: 50px; font-weight: 500; }
        
        /* Navbar modernizada (Glassmorphism sutil y espacioso) */
        .navbar-custom {
            background-color: rgba(255, 255, 255, 0.70) !important;
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(0,0,0,0.04);
            transition: all 0.3s;
            padding: 12px 0;
        }
        .navbar-brand img {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: contain;
            margin-right: 14px;
            background-color: #fff;
            padding: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.03);
        }
        .navbar-brand span { color: #1e293b !important; font-weight: 800; font-size: 1.4rem; letter-spacing: -0.6px; }

        .btn-nav-primary, .btn-nav-secondary {
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        .btn-nav-primary {
            background-color: #0d6efd;
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(13, 110, 253, 0.25);
        }
        .btn-nav-primary:hover {
            background-color: #0b5ed7;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.35);
        }
        .btn-nav-secondary {
            background-color: #e2e8f0;
            color: #0d6efd;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.4);
        }
        .btn-nav-secondary:hover {
            background-color: #cbd5e1;
            color: #0b5ed7;
            transform: translateY(-2px);
        }

        /* Hero animado (con canvas de nodos) */
        .hero {
            background-color: transparent;
            color: var(--text-main);
            padding: 80px 0 40px;
            text-align: center;
            position: relative;
            z-index: 1;
            overflow: hidden;
        }
        .particles-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            pointer-events: none;
        }
        .hero h1 {
            font-size: clamp(2.5rem, 5vw, 3.8rem);
            font-weight: 700;
            letter-spacing: -1px;
            line-height: 1.1;
            margin-bottom: 20px;
        }
        .hero p {
            font-size: 1.15rem;
            color: var(--text-muted);
            max-width: 650px;
            margin: 0 auto 35px;
            line-height: 1.6;
        }

        /* Cards Glassmorphism (Beneficios y Servicios) */
        .section-title {
            text-align: center;
            margin-bottom: 50px;
        }
        .section-title h2 { font-weight: 700; font-size: 2.2rem; letter-spacing: -0.5px; }
        
        .benefit-card {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            border: 1px solid rgba(255,255,255,0.8);
            transition: transform 0.3s, box-shadow 0.3s;
            height: 100%;
        }
        .benefit-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(13, 110, 253, 0.08);
        }
        .benefit-card .icon-wrapper {
            width: 80px; height: 80px;
            background: var(--primary-light);
            color: var(--primary-color);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 20px;
        }
        .benefit-card h5 { font-weight: 600; font-size: 1.2rem; }

        .service-card {
            display: flex;
            flex-direction: column;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 30px;
            border: 1px solid rgba(255,255,255,0.8);
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--text-main);
            height: 100%;
        }
        .service-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 45px rgba(13, 110, 253, 0.1);
        }
        .service-card .icon {
            font-size: 2.2rem;
            margin-bottom: 20px;
            color: var(--primary-color);
            transition: transform 0.3s;
        }
        .service-card:hover .icon { transform: scale(1.1); }
        .service-card h5 { font-weight: 600; font-size: 1.2rem; margin-bottom: 10px; }

        .about-section img {
            border-radius: 30px;
            max-width: 100%;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
        }

        /* Galería */
        .gallery-item {
            overflow: hidden;
            border-radius: 24px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }
        .gallery-item img {
            transition: transform 0.5s cubic-bezier(0.165, 0.84, 0.44, 1);
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        .gallery-item:hover img { transform: scale(1.1); }

        .map-container {
            border-radius: 24px;
            overflow: hidden;
            height: 400px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            border: 4px solid #fff;
        }

        /* Social Tiles Modernas */
        .social-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        @media (min-width: 1200px) { .social-grid { grid-template-columns: repeat(3, 1fr); } }
        
        .social-tile {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 24px;
            padding: 24px 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            aspect-ratio: 1 / 1;
            display: flex; align-items: center; justify-content: center; flex-direction: column;
            text-decoration: none; color: inherit;
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative; overflow: hidden;
        }
        .social-tile:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
        }
        .social-tile[data-url] { cursor: pointer; }
        .social-tile.disabled { opacity: 0.6; pointer-events: none; filter: grayscale(1); }
        
        .social-tile .icon {
            width: 70px; height: 70px;
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            background: #fff; color: #fff;
            font-size: 2.2rem; margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .social-tile.instagram .icon { background: radial-gradient(circle at 30% 110%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
        .social-tile.facebook .icon { background: #1877f2; }
        .social-tile.tiktok .icon { background: #000000; box-shadow: 0 0 0 2px rgba(0,242,234,0.4), 0 0 0 4px rgba(255,0,80,0.3); }
        .social-tile.youtube .icon { background: #ff0000; }
        
        .social-tile .label { font-weight: 600; font-size: 1.1rem; }
        .social-tile .hint { color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; }
        @media (min-width: 992px) { .social-tile { aspect-ratio: 4 / 3; } }

        .social-embed {
            background: rgba(255,255,255,0.8);
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255,255,255,0.7);
            overflow: hidden;
            width: 100%;
            margin: 16px 0;
            padding: 10px;
        }
        .social-embed iframe, .social-embed blockquote { border-radius: 16px; width: 100%; height: 100%; display: block; }
        .social-embed.ratio-16-9 { aspect-ratio: 16/9; }
        .social-embed.ratio-9-16 { aspect-ratio: 9/16; }
        @media (min-width: 992px) {
            .social-embed.ratio-9-16 { max-width: clamp(300px, 28vw, 360px); margin-left: auto; margin-right: auto; }
        }

        /* Footer */
        .footer {
            background-color: #1a202c;
            color: #a0aec0;
            padding-top: 50px;
            padding-bottom: 30px;
            border-top: 1px solid rgba(255,255,255,0.05);
            margin-top: 40px;
        }
        .footer a { color: #cbd5e0; text-decoration: none; transition: color 0.2s; }
        .footer a:hover { color: #fff; text-decoration: underline; }
        
        .footer .footer-social-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transition: all 0.3s;
        }
        .footer .footer-social-icon:hover { background-color: var(--primary-color); transform: translateY(-3px); }

        .whatsapp-fab {
            position: fixed; bottom: 30px; right: 30px;
            background-color: #25D366; color: #fff;
            width: 65px; height: 65px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem; box-shadow: 0 5px 20px rgba(37, 211, 102, 0.4);
            z-index: 1000; transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
        }
        .whatsapp-fab:hover { transform: scale(1.1) translateY(-5px); color: #fff; box-shadow: 0 8px 25px rgba(37, 211, 102, 0.6); }
    </style>
</head>
<body>


    <nav class="navbar navbar-expand-lg navbar-light fixed-top navbar-custom shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?php echo getSystemBaseUrl(); ?>portal/<?php echo urlencode($slug); ?>/">
                <img src="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo esc($company['company_logo'] ?? 'logo.png'); ?>" alt="Logo" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='<?php echo getSystemBaseUrl(); ?>assets/img/logo.png';">
                <span><?php echo esc($company['company_name'] ?? ''); ?></span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="mainNav">
                <ul class="navbar-nav me-auto d-lg-none mt-2 shadow-sm rounded-3 overflow-hidden bg-white">
                    <li class="nav-item">
                        <a class="nav-link text-dark fw-medium py-3 px-4 border-bottom border-light" href="#servicios"><i class="fa-solid fa-wrench text-muted me-3 text-center" style="width: 20px;"></i>Servicios</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark fw-medium py-3 px-4 border-bottom border-light" href="#contacto"><i class="fa-solid fa-hashtag text-muted me-3 text-center" style="width: 20px;"></i>Redes Sociales</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark fw-medium py-3 px-4 border-bottom border-light" href="#ubicacion"><i class="fa-solid fa-location-dot text-muted me-3 text-center" style="width: 20px;"></i>Ubicación</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark fw-medium py-3 px-4 border-bottom border-light" href="#galeria"><i class="fa-solid fa-images text-muted me-3 text-center" style="width: 20px;"></i>Galería de Fotos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-primary fw-bold py-3 px-4 border-bottom border-light" href="<?php echo getSystemBaseUrl(); ?>portal/verify.php?t=<?php echo urlencode($slug); ?>"><i class="fa-solid fa-magnifying-glass me-3 text-center" style="width: 20px;"></i>Consultar Orden</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-primary fw-bold py-3 px-4 border-bottom border-light" href="#" data-bs-toggle="modal" data-bs-target="#apptModal"><i class="fa-solid fa-calendar-plus me-3 text-center" style="width: 20px;"></i>Agendar Cita</a>
                    </li>
                    <?php if (!empty($whatsapp_link)): ?>
                    <li class="nav-item">
                        <a class="nav-link text-success fw-bold py-3 px-4" href="<?php echo esc($whatsapp_link); ?>" target="_blank"><i class="fa-brands fa-whatsapp fs-5 me-3 text-center" style="width: 20px;"></i>Escríbenos por WhatsApp</a>
                    </li>
                    <?php
endif; ?>
                </ul>
                
                <div class="d-none d-lg-flex align-items-center gap-3">
                    <a class="btn-nav-primary" href="<?php echo getSystemBaseUrl(); ?>portal/verify.php?t=<?php echo urlencode($slug); ?>">
                        <i class="fa-solid fa-magnifying-glass me-2"></i>Consultar Orden
                    </a>
                    <button class="btn-nav-secondary border-0" data-bs-toggle="modal" data-bs-target="#apptModal">
                        <i class="fa-solid fa-calendar-plus me-2"></i>Agendar Cita
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <header class="hero d-flex align-items-center justify-content-center" style="min-height: 40vh; padding: 60px 0 20px;">
        <canvas id="heroParticles" class="particles-canvas"></canvas>
        <div class="container position-relative">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle rounded-pill px-3 py-2 mb-3 fw-medium shadow-sm">
                <i class="fa-solid fa-star me-1"></i> Servicio Técnico Profesional
            </span>
            <h1 class="mb-2" data-edit-key="client_portal_home_title" style="font-size: clamp(2rem, 4vw, 3.2rem);"><?php echo esc($cfgMap['client_portal_home_title'] ?: 'Soluciones Técnicas para tus Dispositivos'); ?></h1>
            <p class="mb-4 text-muted" data-edit-key="client_portal_home_subtitle"><?php echo esc($cfgMap['client_portal_home_subtitle'] ?: 'Reparaciones expertas y servicio al cliente de primer nivel.'); ?></p>
            <div class="d-flex justify-content-center gap-3">
                <a class="btn btn-primary px-4 py-2 shadow-sm" style="border-radius: 50px; font-weight: 500;" href="<?php echo getSystemBaseUrl(); ?>portal/verify.php?t=<?php echo urlencode($slug); ?>">
                    <i class="fa-solid fa-magnifying-glass me-2"></i>Consultar Estado de Orden
                </a>
            </div>
        </div>
    </header>

    <section class="py-5 position-relative z-1">
        <div class="container">
            <div class="row g-4">
                <?php foreach ($benefits as $b):
    $bTitle = esc($b['title'] ?? '');
    $bDesc = esc($b['desc'] ?? '');
    $bIcon = esc($b['icon'] ?? '');
    $isFa = strpos($bIcon, 'fa-') !== false;
?>
                <div class="col-md-4">
                    <div class="benefit-card">
                        <div class="icon-wrapper">
                            <?php if ($isFa): ?>
                                <i class="<?php echo $bIcon; ?>"></i>
                            <?php
    else: ?>
                                <span><?php echo $bIcon; ?></span>
                            <?php
    endif; ?>
                        </div>
                        <h5 class="mb-3"><?php echo $bTitle; ?></h5>
                        <p class="text-muted small mb-0 lh-lg"><?php echo $bDesc; ?></p>
                    </div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>
    </section>

    <?php if (!empty($services)): ?>
    <section id="servicios" class="py-5 my-4 position-relative z-1" style="background-color: transparent;">
        <div class="container">
            <div class="section-title">
                <h2>¿Qué problema enfrentas?</h2>
                <p class="text-muted mt-2">Nuestros expertos están listos para ayudarte con las mejores soluciones.</p>
            </div>
            <div class="row g-4">
                <?php foreach ($services as $svc):
        $name = esc($svc['name'] ?? '');
        $desc = esc($svc['desc'] ?? '');
        $icon = esc($svc['icon'] ?? 'fa-solid fa-gear');
?>
                <div class="col-md-6 col-lg-4">
                    <a href="#" class="service-card">
                        <div class="icon"><i class="<?php echo $icon; ?>"></i></div>
                        <h5><?php echo $name; ?></h5>
                        <p class="text-muted small mb-0 lh-base"><?php echo $desc; ?></p>
                    </a>
                </div>
                <?php
    endforeach; ?>
            </div>
        </div>
    </section>
    <?php
endif; ?>

    <section id="contacto" class="py-5 position-relative z-1">
        <div class="container">
            <div class="row g-5 align-items-start">
                <div class="col-12 col-lg-7">
                    <div class="section-title text-lg-start mb-4">
                        <h2>Conecta con Nosotros</h2>
                        <p class="text-muted mt-2">Síguenos en nuestras redes sociales, mira nuestros últimos trabajos y mantente informado.</p>
                    </div>
                    <div class="social-grid">
                        <?php if (!empty($social['instagram'])): ?>
                            <a class="social-tile instagram" data-url="<?php echo esc($social['instagram']); ?>" target="_blank" rel="noopener" href="<?php echo esc($social['instagram']); ?>">
                                <span class="icon"><i class="fab fa-instagram"></i></span>
                                <div class="label">Instagram</div>
                                <div class="hint">Fotos y cortos</div>
                            </a>
                        <?php
else: ?>
                            <div class="social-tile instagram disabled">
                                <span class="icon"><i class="fab fa-instagram"></i></span>
                                <div class="label">Instagram</div>
                                <div class="hint">No disponible</div>
                            </div>
                        <?php
endif; ?>

                        <?php if (!empty($social['facebook'])): ?>
                            <a class="social-tile facebook" data-url="<?php echo esc($social['facebook']); ?>" target="_blank" rel="noopener" href="<?php echo esc($social['facebook']); ?>">
                                <span class="icon"><i class="fab fa-facebook-f"></i></span>
                                <div class="label">Facebook</div>
                                <div class="hint">Noticias y videos</div>
                            </a>
                        <?php
else: ?>
                            <div class="social-tile facebook disabled">
                                <span class="icon"><i class="fab fa-facebook-f"></i></span>
                                <div class="label">Facebook</div>
                                <div class="hint">No disponible</div>
                            </div>
                        <?php
endif; ?>

                        <?php if (!empty($social['tiktok'])): ?>
                            <a class="social-tile tiktok" data-url="<?php echo esc($social['tiktok']); ?>" target="_blank" rel="noopener" href="<?php echo esc($social['tiktok']); ?>">
                                <span class="icon"><i class="fab fa-tiktok"></i></span>
                                <div class="label">TikTok</div>
                                <div class="hint">Clips del taller</div>
                            </a>
                        <?php
else: ?>
                            <div class="social-tile tiktok disabled">
                                <span class="icon"><i class="fab fa-tiktok"></i></span>
                                <div class="label">TikTok</div>
                                <div class="hint">No disponible</div>
                            </div>
                        <?php
endif; ?>

                        <?php if (!empty($social['youtube'])): ?>
                            <a class="social-tile youtube" data-url="<?php echo esc($social['youtube']); ?>" target="_blank" rel="noopener" href="<?php echo esc($social['youtube']); ?>">
                                <span class="icon"><i class="fab fa-youtube"></i></span>
                                <div class="label">YouTube</div>
                                <div class="hint">Tutoriales completos</div>
                            </a>
                        <?php
else: ?>
                            <div class="social-tile youtube disabled">
                                <span class="icon"><i class="fab fa-youtube"></i></span>
                                <div class="label">YouTube</div>
                                <div class="hint">No disponible</div>
                            </div>
                        <?php
endif; ?>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <?php if (!empty($featured_embed_html)): ?>
                    <div id="socialEmbed" class="social-embed <?php echo $featured_orientation === '9-16' ? 'ratio-9-16' : 'ratio-16-9'; ?>">
                        <?php echo $featured_embed_html; ?>
                    </div>
                    <?php
else: ?>
                    <div id="socialEmbed" class="social-embed d-flex align-items-center justify-content-center" style="min-height: 300px;">
                        <div class="text-center p-4">
                            <i class="fa-solid fa-video text-muted fs-1 mb-3 opacity-25"></i>
                            <div class="fw-semibold mb-1">Video Destacado</div>
                            <div class="text-muted small">El administrador aún no ha configurado un video para esta sección.</div>
                        </div>
                    </div>
                    <?php
endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($cfgMap['client_portal_about_text'])): ?>
    <section class="py-5 my-4 about-section position-relative z-1">
        <div class="container">
            <div class="row align-items-center g-5">
                <?php if (!empty($cfgMap['client_portal_about_image'])): ?>
                <div class="col-md-6 order-2 order-md-1">
                    <img src="<?php echo esc($cfgMap['client_portal_about_image']); ?>" alt="Sobre nosotros" class="img-fluid">
                </div>
                <?php
    endif; ?>
                <div class="col-md-6 order-1 order-md-2">
                    <span class="text-primary fw-bold text-uppercase small tracking-wider mb-2 d-block">Conócenos un poco más</span>
                    <h2 class="fw-bold mb-4" style="font-size: 2.2rem; letter-spacing: -0.5px;">Nuestra Historia</h2>
                    <p class="text-muted lh-lg fs-6"><?php echo nl2br(esc($cfgMap['client_portal_about_text'])); ?></p>
                </div>
            </div>
        </div>
    </section>
    <?php
endif; ?>

    <section id="galeria" class="py-5 position-relative z-1">
        <div class="container">
            <div class="section-title">
                <h2>Galería de Trabajo</h2>
                <p class="text-muted mt-2">Un vistazo a nuestras instalaciones y algunos de nuestros trabajos recientes.</p>
            </div>
            <div class="row g-4">
                <?php foreach ($gallery_images as $index => $image_url): ?>
                <div class="col-md-4">
                    <div class="gallery-item">
                        <img src="<?php echo esc($image_url); ?>" class="img-fluid" alt="Galería <?php echo $index + 1; ?>">
                    </div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>
    </section>

    <section id="ubicacion" class="py-5 mt-4 mb-2 position-relative z-1" style="background-color: transparent;">
        <div class="container">
            <div class="row align-items-center g-5 bg-white p-4 p-md-5 rounded-4 shadow-sm" style="border: 1px solid rgba(0,0,0,0.05); backdrop-filter: blur(10px); background: rgba(255,255,255,0.7) !important;">
                <div class="col-md-5">
                    <h2 class="fw-bold mb-4" style="font-size: 2.2rem; letter-spacing: -0.5px;">Ubicación y Horarios</h2>
                    
                    <div class="d-flex align-items-start mb-4">
                        <div class="me-3 text-primary bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; flex-shrink:0;">
                            <i class="fa-solid fa-location-dot fs-5"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Dónde Encontrarnos</h6>
                            <p class="text-muted mb-0" data-edit-key="client_portal_address_text"><?php echo esc($address_text); ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start">
                        <div class="me-3 text-primary bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; flex-shrink:0;">
                            <i class="fa-solid fa-clock fs-5"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Horario de Atención</h6>
                            <p class="text-muted mb-0" data-edit-key="client_portal_hours_text"><?php echo esc($hours_text); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="map-container">
                        <iframe src="<?php echo esc($map_url); ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                    <!-- Botón "Cómo llegar" exclusivo para móvil -->
                    <a href="https://maps.google.com/?q=<?php echo urlencode($address_text); ?>" target="_blank" class="btn btn-primary w-100 mt-3 d-md-none fw-bold" style="padding: 14px; border-radius: 16px; box-shadow: 0 8px 20px rgba(13, 110, 253, 0.2);">
                        <i class="fa-solid fa-map-location-dot me-2"></i>Cómo llegar
                    </a>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-4 text-center text-md-start mb-4 mb-md-0">
                    <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-3 mb-3">
                        <img src="<?php echo getSystemBaseUrl(); ?>assets/img/<?php echo esc($company['company_logo'] ?? 'logo.png'); ?>" 
                             alt="Logo" 
                             style="width: 50px; height: 50px; border-radius: 14px; object-fit: contain; background: #fff; padding: 4px;" 
                             loading="lazy"
                             decoding="async"
                             onerror="this.onerror=null; this.src='<?php echo getSystemBaseUrl(); ?>assets/img/logo.png';">
                        <div class="d-flex flex-column align-items-start" style="line-height: 1.2;">
                            <span class="fw-bold text-white fs-5"><?php echo esc($company['company_name'] ?? ''); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex flex-column gap-2 text-start px-3 px-md-0">
                        <?php if (!empty($company['company_phone'])): ?>
                            <span class="small" style="color: #a0aec0;"><i class="fa-solid fa-phone me-2 opacity-75"></i><?php echo esc($company['company_phone']); ?></span>
                        <?php
endif; ?>
                        <?php if (!empty($company['company_address'])): ?>
                            <span class="small" style="color: #a0aec0;"><i class="fa-solid fa-location-dot me-2 opacity-75"></i><?php echo esc($company['company_address']); ?></span>
                        <?php
endif; ?>
                        <?php if (!empty($company['company_email'])): ?>
                            <span class="small" style="color: #a0aec0;"><i class="fa-solid fa-envelope me-2 opacity-75"></i><?php echo esc($company['company_email']); ?></span>
                        <?php
endif; ?>
                    </div>
                </div>
                
                <div class="col-md-4 text-center mb-4 mb-md-0">
                    <p class="small mb-0" style="color: #718096;">&copy; <?php echo date('Y'); ?> <?php echo esc($company['company_name'] ?? ''); ?>. Todos los derechos reservados.</p>
                </div>
                
                <div class="col-md-4 text-center text-md-end">
                    <div class="d-flex justify-content-center justify-content-md-end gap-2 mb-3">
                        <?php if (!empty($social['facebook'])): ?><a target="_blank" rel="noopener" href="<?php echo esc($social['facebook']); ?>" class="footer-social-icon"><i class="fab fa-facebook-f"></i></a><?php
endif; ?>
                        <?php if (!empty($social['instagram'])): ?><a target="_blank" rel="noopener" href="<?php echo esc($social['instagram']); ?>" class="footer-social-icon"><i class="fab fa-instagram"></i></a><?php
endif; ?>
                        <?php if (!empty($social['tiktok'])): ?><a target="_blank" rel="noopener" href="<?php echo esc($social['tiktok']); ?>" class="footer-social-icon"><i class="fab fa-tiktok"></i></a><?php
endif; ?>
                        <?php if (!empty($social['youtube'])): ?><a target="_blank" rel="noopener" href="<?php echo esc($social['youtube']); ?>" class="footer-social-icon"><i class="fab fa-youtube"></i></a><?php
endif; ?>
                    </div>
                    <span class="small" style="color: #4a5568;">Potenciado por <a href="../" target="_blank" class="fw-bold text-white text-decoration-none ms-1">Core</a></span>
                </div>
            </div>
        </div>
    </footer>

    <?php if (!empty($whatsapp_link)): ?>
        <a href="<?php echo esc($whatsapp_link); ?>" target="_blank" rel="noopener" class="whatsapp-fab" aria-label="Chat por WhatsApp" title="Chat por WhatsApp">
            <i class="fab fa-whatsapp"></i>
        </a>
    <?php
endif; ?>

    <div class="modal fade" id="apptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-calendar-plus me-2"></i>Agendar Cita</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Funcionalidad próximamente.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            var hero = document.querySelector('.hero');
            var canvas = document.getElementById('heroParticles');
            if (!hero || !canvas) return;
            var ctx = canvas.getContext('2d');
            var color = {r:13,g:110,b:253}; // Primary blue
            var particles = [];
            function size(){
                canvas.width = hero.clientWidth;
                canvas.height = hero.clientHeight;
                init();
            }
            function init(){
                particles.length = 0;
                var n = Math.max(30, Math.min(100, Math.floor((canvas.width*canvas.height)/9000)));
                for (var i=0;i<n;i++){
                    particles.push({
                        x: Math.random()*canvas.width,
                        y: Math.random()*canvas.height,
                        vx: (Math.random()-0.5)*0.5,
                        vy: (Math.random()-0.5)*0.5,
                        r: 1.5
                    });
                }
            }
            function step(){
                ctx.clearRect(0,0,canvas.width,canvas.height);
                for (var i=0;i<particles.length;i++){
                    var p = particles[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x <= 0 || p.x >= canvas.width) p.vx *= -1;
                    if (p.y <= 0 || p.y >= canvas.height) p.vy *= -1;
                    ctx.beginPath();
                    ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
                    ctx.fillStyle = 'rgba('+color.r+','+color.g+','+color.b+',0.6)';
                    ctx.fill();
                }
                var t = Math.min(150, Math.max(80, canvas.width/10));
                for (var i=0;i<particles.length;i++){
                    for (var j=i+1;j<particles.length;j++){
                        var a = particles[i], b = particles[j];
                        var dx = a.x-b.x, dy = a.y-b.y;
                        var d = Math.sqrt(dx*dx+dy*dy);
                        if (d < t){
                            var alpha = 1 - (d/t);
                            ctx.beginPath();
                            ctx.moveTo(a.x,a.y);
                            ctx.lineTo(b.x,b.y);
                            ctx.strokeStyle = 'rgba('+color.r+','+color.g+','+color.b+','+(0.2*alpha)+')';
                            ctx.lineWidth = 1;
                            ctx.stroke();
                        }
                    }
                }
                requestAnimationFrame(step);
            }
            size();
            window.addEventListener('resize', size);
            requestAnimationFrame(step);
        })();
    </script>
    <?php if ($featured_platform === 'tiktok'): ?>
    <script async src="https://www.tiktok.com/embed.js"></script>
    <?php
endif; ?>
    <?php if ($featured_platform === 'instagram'): ?>
    <script async src="https://www.instagram.com/embed.js"></script>
    <?php
endif; ?>
    <script>
        (function(){
            var embedBox = document.getElementById('socialEmbed');
            var tiles = document.querySelectorAll('.social-tile[data-url]');
            if (!embedBox || !tiles.length) return;
            function ensureScript(src){
                if ([].slice.call(document.scripts).some(function(s){ return s.src === src; })) return;
                var el = document.createElement('script');
                el.async = true; el.src = src;
                document.body.appendChild(el);
            }
            function detectOrientation(url){
                if (/tiktok\.com|instagram\.com|facebook\.com/.test(url) || /shorts/.test(url)) return '9-16';
                return '16-9';
            }
            function ytId(url){
                var m = url.match(/[?&]v=([A-Za-z0-9_-]{6,})/) ||
                        url.match(/youtu\.be\/([A-Za-z0-9_-]{6,})/) ||
                        url.match(/shorts\/([A-Za-z0-9_-]{6,})/);
                return m ? m[1] : '';
            }
            function buildEmbed(url){
                if (/youtu\.be|youtube\.com/.test(url)){
                    var id = ytId(url);
                    if (id) return '<iframe width="100%" height="100%" src="https://www.youtube.com/embed/'+id+'" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
                } else if (url.indexOf('facebook.com') !== -1){
                    return '<iframe width="100%" height="100%" src="https://www.facebook.com/plugins/video.php?href='+encodeURIComponent(url)+'&show_text=false" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe>';
                } else if (url.indexOf('tiktok.com') !== -1){
                    ensureScript('https://www.tiktok.com/embed.js');
                    return '<blockquote class="tiktok-embed" cite="'+url+'" style="max-width: 605px; min-width: 325px;"><section></section></blockquote>';
                } else if (url.indexOf('instagram.com') !== -1){
                    ensureScript('https://www.instagram.com/embed.js');
                    return '<blockquote class="instagram-media" data-instgrm-permalink="'+url+'" style=" background:#FFF; border:0; margin: 0 auto; max-width:540px; width:100%;"></blockquote>';
                }
                return '';
            }
            tiles.forEach(function(tile){
                tile.addEventListener('click', function(e){
                    var url = tile.getAttribute('data-url');
                    if (!url) return;
                    e.preventDefault();
                    var ratio = detectOrientation(url);
                    embedBox.classList.remove('ratio-16-9','ratio-9-16');
                    embedBox.classList.add(ratio === '9-16' ? 'ratio-9-16' : 'ratio-16-9');
                    embedBox.innerHTML = buildEmbed(url);
                });
            });
        })();
    </script>
</body>
</html>
