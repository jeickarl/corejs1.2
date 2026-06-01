<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_enhancements.php';

// Endurece salida JSON y evita fugas de errores
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
header('Content-Type: application/json; charset=UTF-8');
try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_spanish_ci"); } catch (Throwable $e) {}
try { $pdo->exec("SET CHARACTER SET utf8mb4"); } catch (Throwable $e) {}

if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }
    $action = trim($_POST['action'] ?? '');
    // Aislamiento por tenant
    $tenant_id = getCurrentTenantId();
    $perDatabase = isPerDatabaseMode();
    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
    $hasTenantSystemConfig = hasTenantColumnCached($pdo, 'system_config');
    $hasTenantCompanyConfig = hasTenantColumnCached($pdo, 'company_config');
    $hasTenantWorkOrders = hasTenantColumnCached($pdo, 'work_orders');
    // Detectar si la tabla soporta tenant_id sin alterar estructura
    $hasTenant = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
        $hasTenant = ($c && $c->rowCount() > 0);
    } catch (Throwable $e) {}

    $upsertSystemConfig = function(string $key, string $value) use ($pdo, $hasTenantSystemConfig, $tenantValue): void {
        if ($hasTenantSystemConfig) {
            $ins = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            $ins->execute([$tenantValue, $key, $value]);
            return;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    };
    
    
    // Verificar token CSRF solo para acciones que modifican datos
    if ($action !== 'get_all' && $action !== 'get_template' && $action !== 'seed_defaults' && $action !== 'get_dashboard_config') {
        $csrf = $_POST['csrf_token'] ?? '';
        $csrfOk = false;
        if ($csrf !== '') {
            if (class_exists('SecurityEnhancements') && SecurityEnhancements::verifyCSRFToken($csrf)) {
                $csrfOk = true;
            } else {
                $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
                if ($sessionCsrf !== '' && hash_equals($sessionCsrf, (string)$csrf)) {
                    $csrfOk = true;
                }
            }
        }
        if (!$csrfOk) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o expirado']);
            exit;
        }

        // Verificar permisos de administrador para acciones de modificación
        if (!isAdminSession()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado: Se requieren permisos de administrador']);
            exit;
        }
    }
    
    switch ($action) {
        case 'seed_defaults':
            if (!isAdminSession()) {
                throw new Exception('Acceso denegado');
            }
            try {
                ensureDefaultOrderStatuses($tenantValue);
                echo json_encode(['success' => true, 'message' => 'Estados predeterminados cargados exitosamente']);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al cargar estados: ' . $e->getMessage()]);
            }
            break;

        case 'geocode':
            try {
                $q = trim($_POST['q'] ?? '');
                if ($q === '') { throw new Exception('Consulta vacía'); }
                $ua = 'NexarClientPortal/1.0 (+https://localhost/)';
                // Intentar Nominatim (OSM)
                $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&accept-language=es&q=' . urlencode($q);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                unset($ch);
                $lat = null; $lng = null;
                if ($resp && $code === 200) {
                    $data = json_decode($resp, true);
                    if (is_array($data) && !empty($data[0])) {
                        $lat = isset($data[0]['lat']) ? floatval($data[0]['lat']) : null;
                        $lng = isset($data[0]['lon']) ? floatval($data[0]['lon']) : null;
                    }
                }
                // Fallback a Photon (Komoot)
                if ($lat === null || $lng === null) {
                    $url2 = 'https://photon.komoot.io/api/?q=' . urlencode($q) . '&limit=1';
                    $ch2 = curl_init($url2);
                    curl_setopt_array($ch2, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 8,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_USERAGENT => $ua,
                        CURLOPT_HTTPHEADER => ['Accept: application/json']
                    ]);
                    $resp2 = curl_exec($ch2);
                    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    unset($ch2);
                    if ($resp2 && $code2 === 200) {
                        $data2 = json_decode($resp2, true);
                        if (isset($data2['features'][0]['geometry']['coordinates']) && is_array($data2['features'][0]['geometry']['coordinates'])) {
                            $lng = floatval($data2['features'][0]['geometry']['coordinates'][0]);
                            $lat = floatval($data2['features'][0]['geometry']['coordinates'][1]);
                        }
                    }
                }
                if ($lat === null || $lng === null) {
                    throw new Exception('No se pudo geocodificar la dirección');
                }
                echo json_encode(['success' => true, 'lat' => $lat, 'lng' => $lng]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error de geocodificación: ' . $e->getMessage()]);
            }
            break;
        case 'get_client_portal_config':
            try {
                if ($hasTenantSystemConfig) {
                    $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ? AND config_key IN ('client_portal_enable_lookup_by_id','client_portal_show_timeline','client_portal_allow_approval','client_portal_home_title','client_portal_home_subtitle','client_portal_hero_image','client_portal_whatsapp_link','client_portal_about_text','client_portal_about_image','client_portal_services','client_portal_social_links','client_portal_map_embed_url','client_portal_address_text','client_portal_hours_text','client_portal_featured_video_url','client_portal_benefits','client_portal_gallery_images')");
                    $sel->execute([$tenantValue]);
                } else {
                    $sel = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('client_portal_enable_lookup_by_id','client_portal_show_timeline','client_portal_allow_approval','client_portal_home_title','client_portal_home_subtitle','client_portal_hero_image','client_portal_whatsapp_link','client_portal_about_text','client_portal_about_image','client_portal_services','client_portal_social_links','client_portal_map_embed_url','client_portal_address_text','client_portal_hours_text','client_portal_featured_video_url','client_portal_benefits','client_portal_gallery_images')");
                    $sel->execute([]);
                }
                $map = [
                    'client_portal_enable_lookup_by_id' => '0',
                    'client_portal_show_timeline' => '1',
                    'client_portal_allow_approval' => '1',
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
                foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $k = $row['config_key']; $v = (string)$row['config_value'];
                    if (array_key_exists($k, $map)) { $map[$k] = $v; }
                }
                if (trim((string)$map['client_portal_address_text']) === '') {
                    try {
                        if ($hasTenantCompanyConfig && !$perDatabase) {
                            $stC = $pdo->prepare("SELECT company_address FROM company_config WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
                            $stC->execute([$tenantValue]);
                        } else {
                            $stC = $pdo->prepare("SELECT company_address FROM company_config ORDER BY id DESC LIMIT 1");
                            $stC->execute([]);
                        }
                        $addr = trim((string)$stC->fetchColumn());
                        if ($addr !== '') {
                            $map['client_portal_address_text'] = $addr;
                            if (trim((string)$map['client_portal_map_embed_url']) === '') {
                                $map['client_portal_map_embed_url'] = 'https://maps.google.com/maps?q=' . rawurlencode($addr) . '&z=16&output=embed';
                            }
                        }
                    } catch (Throwable $__) {
                    }
                }
                echo json_encode(['success' => true, 'data' => $map]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al leer configuración: ' . $e->getMessage()]);
            }
            break;
        case 'save_client_portal_config':
            try {
                $lookup = isset($_POST['enable_lookup_by_id']) && $_POST['enable_lookup_by_id'] === '1' ? '1' : '0';
                $timeline = isset($_POST['show_timeline']) && $_POST['show_timeline'] === '1' ? '1' : '0';
                $approval = isset($_POST['allow_approval']) && $_POST['allow_approval'] === '1' ? '1' : '0';
                $upsertSystemConfig('client_portal_enable_lookup_by_id', $lookup);
                $upsertSystemConfig('client_portal_show_timeline', $timeline);
                $upsertSystemConfig('client_portal_allow_approval', $approval);
                $title = trim($_POST['home_title'] ?? '');
                $subtitle = trim($_POST['home_subtitle'] ?? '');
                $hero_image = trim($_POST['hero_image'] ?? '');
                $wa_link = trim($_POST['whatsapp_link'] ?? '');
                $about_text = trim($_POST['about_text'] ?? '');
                $about_image = trim($_POST['about_image'] ?? '');
                $services = (string)($_POST['services_json'] ?? '[]');
                $social = (string)($_POST['social_json'] ?? '{}');
                $map_url = trim($_POST['map_embed_url'] ?? '');
                $addr = trim($_POST['address_text'] ?? '');
                $hours = trim($_POST['hours_text'] ?? '');
                $featured_video_url = trim($_POST['featured_video_url'] ?? '');
                $benefits = (string)($_POST['benefits_json'] ?? '[]');
                $gallery = (string)($_POST['gallery_json'] ?? '[]');
                $upsertSystemConfig('client_portal_home_title', $title);
                $upsertSystemConfig('client_portal_home_subtitle', $subtitle);
                $upsertSystemConfig('client_portal_hero_image', $hero_image);
                $upsertSystemConfig('client_portal_whatsapp_link', $wa_link);
                $upsertSystemConfig('client_portal_about_text', $about_text);
                $upsertSystemConfig('client_portal_about_image', $about_image);
                $upsertSystemConfig('client_portal_services', $services ?: '[]');
                $upsertSystemConfig('client_portal_social_links', $social ?: '{}');
                $upsertSystemConfig('client_portal_map_embed_url', $map_url);
                $upsertSystemConfig('client_portal_address_text', $addr);
                $upsertSystemConfig('client_portal_hours_text', $hours);
                $upsertSystemConfig('client_portal_featured_video_url', $featured_video_url);
                $upsertSystemConfig('client_portal_benefits', $benefits ?: '[]');
                $upsertSystemConfig('client_portal_gallery_images', $gallery ?: '[]');
                echo json_encode(['success' => true, 'message' => 'Configuración del portal guardada']);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al guardar configuración: ' . $e->getMessage()]);
            }
            break;
        case 'update_client_portal_field':
            try {
                $key = trim($_POST['key'] ?? '');
                $value = (string)($_POST['value'] ?? '');
                $allowed = [
                    'client_portal_home_title',
                    'client_portal_home_subtitle',
                    'client_portal_about_text',
                    'client_portal_address_text',
                    'client_portal_hours_text',
                    'client_portal_featured_video_url'
                ];
                if (!in_array($key, $allowed, true)) {
                    throw new Exception('Clave no permitida');
                }
                $upsertSystemConfig($key, $value);
                echo json_encode(['success' => true, 'message' => 'Campo actualizado', 'key' => $key]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()]);
            }
            break;
        case 'update_client_portal_fields':
            try {
                $pairsJson = (string)($_POST['pairs'] ?? '[]');
                $pairs = json_decode($pairsJson, true);
                if (!is_array($pairs)) { $pairs = []; }
                $allowed = [
                    'client_portal_home_title',
                    'client_portal_home_subtitle',
                    'client_portal_about_text',
                    'client_portal_address_text',
                    'client_portal_hours_text',
                    'client_portal_featured_video_url'
                ];
                foreach ($pairs as $p) {
                    $k = isset($p['key']) ? trim((string)$p['key']) : '';
                    $v = isset($p['value']) ? (string)$p['value'] : '';
                    if ($k === '' || !in_array($k, $allowed, true)) { continue; }
                    $upsertSystemConfig($k, $v);
                }
                echo json_encode(['success' => true, 'message' => 'Campos actualizados']);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()]);
            }
            break;
        case 'get_dashboard_config':
            try {
                if ($hasTenantSystemConfig) {
                    $cardsStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_dashboard_cards' LIMIT 1");
                    $cardsStmt->execute([$tenantValue]);
                } else {
                    $cardsStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_dashboard_cards' LIMIT 1");
                    $cardsStmt->execute([]);
                }
                $cards = $cardsStmt->fetchColumn();
                if ($hasTenantSystemConfig) {
                    $exStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_dashboard_excluded_for_total' LIMIT 1");
                    $exStmt->execute([$tenantValue]);
                } else {
                    $exStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_dashboard_excluded_for_total' LIMIT 1");
                    $exStmt->execute([]);
                }
                $excluded = $exStmt->fetchColumn();
                echo json_encode([
                    'success' => true,
                    'cards' => $cards ? json_decode($cards, true) : [],
                    'excluded' => $excluded ? json_decode($excluded, true) : []
                ]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al leer configuración: ' . $e->getMessage()]);
            }
            break;
        case 'save_dashboard_config':
            $cards = isset($_POST['cards']) ? $_POST['cards'] : '[]';
            $excluded = isset($_POST['excluded']) ? $_POST['excluded'] : '[]';
            $ordered = isset($_POST['ordered']) ? $_POST['ordered'] : '[]';
            // Normalizar entradas
            $cardsArr = json_decode($cards, true);
            $excludedArr = json_decode($excluded, true);
            $orderedArr = json_decode($ordered, true);
            if (!is_array($cardsArr)) $cardsArr = [];
            if (!is_array($excludedArr)) $excludedArr = [];
            if (!is_array($orderedArr)) $orderedArr = [];
            // Limitar tamaño razonable por seguridad (no funcional)
            $cardsArr = array_values(array_unique(array_map('strval', $cardsArr)));
            $excludedArr = array_values(array_unique(array_map('strval', $excludedArr)));
            $orderedArr = array_values(array_map('strval', $orderedArr));
            try {
                $upsertSystemConfig('order_dashboard_cards', json_encode($cardsArr, JSON_UNESCAPED_UNICODE));
                $upsertSystemConfig('order_dashboard_excluded_for_total', json_encode($excludedArr, JSON_UNESCAPED_UNICODE));
                // El orden enviado aquí solo aplica para las tarjetas del dashboard; no modifica sort_order global
                echo json_encode(['success' => true, 'message' => 'Configuración guardada']);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Error al guardar configuración: ' . $e->getMessage()]);
            }
            break;
        case 'get_template':
            if ($hasTenantSystemConfig) {
                $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_statuses_template' LIMIT 1");
                $stmt->execute([$tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_statuses_template' LIMIT 1");
                $stmt->execute([]);
            }
            $tpl = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'template' => $tpl ? json_decode($tpl, true) : []]);
            break;
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $emoji = trim($_POST['emoji'] ?? '');
            $color = trim($_POST['color'] ?? '#6c757d');
            $description = trim($_POST['description'] ?? '');
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('El nombre del estado es requerido');
            }
            
            // Generar slug único
            $slug = strtolower(str_replace(' ', '_', $name));
            $slug = preg_replace('/[^a-z0-9_]/', '', $slug);

            $styleMap = [
                'pendiente' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'pending' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'recibido' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'received' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'asignado' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'assigned' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'diagnosticando' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'diagnosing' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'esperando_aprobacion' => ['emoji' => '✍️', 'color' => '#ffc107'],
                'esperando_repuestos' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'waiting_parts' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'reparando' => ['emoji' => '🔧', 'color' => '#007bff'],
                'repairing' => ['emoji' => '🔧', 'color' => '#007bff'],
                'testeando' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'testing' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'completado' => ['emoji' => '✅', 'color' => '#28a745'],
                'completed' => ['emoji' => '✅', 'color' => '#28a745'],
                'entregado' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'delivered' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'cancelado' => ['emoji' => '❌', 'color' => '#dc3545'],
                'cancelled' => ['emoji' => '❌', 'color' => '#dc3545'],
                'devolucion' => ['emoji' => '↩️', 'color' => '#20c997'],
                'garantia' => ['emoji' => '🛡️', 'color' => '#20c997'],
            ];
            $slugKey = strtolower(trim((string)$slug));
            if (isset($styleMap[$slugKey])) {
                if ($emoji === '') { $emoji = $styleMap[$slugKey]['emoji']; }
                if ($color === '' || strtolower($color) === '#6c757d') { $color = $styleMap[$slugKey]['color']; }
            }
            
            // De-duplicación defensiva ante doble envío (misma pestaña o doble listener)
            try {
                if ($hasTenant) {
                    $check = $pdo->prepare("
                        SELECT id FROM order_statuses 
                        WHERE tenant_id = ? 
                          AND (LOWER(name) = LOWER(?) OR slug = ?) 
                          AND TIMESTAMPDIFF(SECOND, COALESCE(created_at, NOW()), NOW()) <= 300
                        ORDER BY id DESC LIMIT 1
                    ");
                    $check->execute([$tenantValue, $name, $slug]);
                } else {
                    $check = $pdo->prepare("
                        SELECT id FROM order_statuses 
                        WHERE (LOWER(name) = LOWER(?) OR slug = ?) 
                          AND TIMESTAMPDIFF(SECOND, COALESCE(created_at, NOW()), NOW()) <= 300
                        ORDER BY id DESC LIMIT 1
                    ");
                    $check->execute([$name, $slug]);
                }
                $existingId = (int)($check->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    echo json_encode(['success' => true, 'message' => 'Estado ya existía (reusado)', 'data' => ['id' => $existingId, 'slug' => $slug]]);
                    break;
                }
            } catch (Throwable $e) { /* continuar */ }
            
            // Verificar si el slug ya existe
            if ($hasTenant) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ? AND tenant_id = ?");
                $stmt->execute([$slug, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ?");
                $stmt->execute([$slug]);
            }
            if ($stmt->fetchColumn() > 0) {
                // Si existe un slug igual, evitar crear duplicado accidental
                // Devolver éxito suave para no romper el flujo
                $existing = $hasTenant
                    ? $pdo->prepare("SELECT id FROM order_statuses WHERE slug = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1")
                    : $pdo->prepare("SELECT id FROM order_statuses WHERE slug = ? ORDER BY id DESC LIMIT 1");
                if ($hasTenant) { $existing->execute([$slug, $tenantValue]); } else { $existing->execute([$slug]); }
                $eid = (int)($existing->fetchColumn() ?: 0);
                echo json_encode(['success' => true, 'message' => 'Estado ya existía (slug reutilizado)', 'data' => ['id' => $eid, 'slug' => $slug]]);
                break;
            }
            
            // Si es estado por defecto, desactivar otros estados por defecto
            if ($is_default) {
                if ($hasTenant) {
                    $stmtReset = $pdo->prepare("UPDATE order_statuses SET is_default = 0 WHERE tenant_id = ?");
                    $stmtReset->execute([$tenantValue]);
                } else {
                    $pdo->exec("UPDATE order_statuses SET is_default = 0");
                }
            }
            
            // Obtener el siguiente sort_order
            if ($hasTenant) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM order_statuses WHERE tenant_id = ?");
                $stmt->execute([$tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM order_statuses");
                $stmt->execute();
            }
            $sort_order = $stmt->fetchColumn();
            
            // Insertar nuevo estado
            if ($hasTenant) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
                ");
                $stmt->execute([$tenantValue, $name, $slug, $emoji, $color, $description, $is_default, $sort_order]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO order_statuses (name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
                ");
                $stmt->execute([$name, $slug, $emoji, $color, $description, $is_default, $sort_order]);
            }
            $newId = (int)$pdo->lastInsertId();
            try {
                $stmt = $hasTenant
                    ? $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC")
                    : $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC, name ASC");
                if ($hasTenant) { $stmt->execute([$tenantValue]); } else { $stmt->execute(); }
                $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $json = json_encode($list, JSON_UNESCAPED_UNICODE);
                $upsertSystemConfig('order_statuses_template', $json);
            } catch (Throwable $e) {}
            echo json_encode(['success' => true, 'message' => 'Estado creado exitosamente', 'data' => ['id' => $newId, 'slug' => $slug]]);
            break;
        
        case 'save_template':
            $stmt = $hasTenant
                ? $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC")
                : $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC, name ASC");
            if ($hasTenant) { $stmt->execute([$tenantValue]); } else { $stmt->execute(); }
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $json = json_encode($list, JSON_UNESCAPED_UNICODE);
            $upsertSystemConfig('order_statuses_template', $json);
            echo json_encode(['success' => true, 'message' => 'Plantilla guardada', 'count' => count($list)]);
            break;
            
        case 'update':
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $emoji = trim($_POST['emoji'] ?? '');
            $color = trim($_POST['color'] ?? '#6c757d');
            $description = trim($_POST['description'] ?? '');
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if ($id <= 0 || empty($name)) {
                throw new Exception('Datos inválidos');
            }
            
            // Verificar que el estado existe
            if ($hasTenant) {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE id = ?");
                $stmt->execute([$id]);
            }
            $status = $stmt->fetch();
            
            if (!$status) {
                throw new Exception('Estado no encontrado');
            }

            $styleMap = [
                'pendiente' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'pending' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'recibido' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'received' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'asignado' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'assigned' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'diagnosticando' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'diagnosing' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'esperando_aprobacion' => ['emoji' => '✍️', 'color' => '#ffc107'],
                'esperando_repuestos' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'waiting_parts' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'reparando' => ['emoji' => '🔧', 'color' => '#007bff'],
                'repairing' => ['emoji' => '🔧', 'color' => '#007bff'],
                'testeando' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'testing' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'completado' => ['emoji' => '✅', 'color' => '#28a745'],
                'completed' => ['emoji' => '✅', 'color' => '#28a745'],
                'entregado' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'delivered' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'cancelado' => ['emoji' => '❌', 'color' => '#dc3545'],
                'cancelled' => ['emoji' => '❌', 'color' => '#dc3545'],
                'devolucion' => ['emoji' => '↩️', 'color' => '#20c997'],
                'garantia' => ['emoji' => '🛡️', 'color' => '#20c997'],
            ];
            $slugKey = strtolower(trim((string)($status['slug'] ?? '')));
            if (isset($styleMap[$slugKey])) {
                if ($emoji === '') { $emoji = $styleMap[$slugKey]['emoji']; }
                if ($color === '' || strtolower($color) === '#6c757d') { $color = $styleMap[$slugKey]['color']; }
            }
            
            // Si es estado por defecto, desactivar otros estados por defecto
            if ($is_default) {
                if ($hasTenant) {
                    $stmtReset = $pdo->prepare("UPDATE order_statuses SET is_default = 0 WHERE tenant_id = ? AND id != ?");
                    $stmtReset->execute([$tenantValue, $id]);
                } else {
                    $stmtReset = $pdo->prepare("UPDATE order_statuses SET is_default = 0 WHERE id != ?");
                    $stmtReset->execute([$id]);
                }
            }
            
            // Actualizar estado
            if ($hasTenant) {
                $stmt = $pdo->prepare("
                    UPDATE order_statuses 
                    SET name = ?, emoji = ?, color = ?, description = ?, is_default = ?, is_active = ?, updated_at = NOW() 
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([$name, $emoji, $color, $description, $is_default, $is_active, $id, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE order_statuses 
                    SET name = ?, emoji = ?, color = ?, description = ?, is_default = ?, is_active = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$name, $emoji, $color, $description, $is_default, $is_active, $id]);
            }
            try {
                $stmt = $hasTenant
                    ? $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC")
                    : $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC, name ASC");
                if ($hasTenant) { $stmt->execute([$tenantValue]); } else { $stmt->execute(); }
                $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $json = json_encode($list, JSON_UNESCAPED_UNICODE);
                $upsertSystemConfig('order_statuses_template', $json);
            } catch (Throwable $e) {}
            
            echo json_encode(['success' => true, 'message' => 'Estado actualizado exitosamente', 'data' => ['id' => $id]]);
            break;
            
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('ID inválido');
            }
            
            // Verificar que el estado existe
            if ($hasTenant) {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE id = ?");
                $stmt->execute([$id]);
            }
            $status = $stmt->fetch();
            
            if (!$status) {
                throw new Exception('Estado no encontrado');
            }
            
            // Verificar si es estado por defecto
            if ($status['is_default']) {
                throw new Exception('No se puede eliminar el estado por defecto');
            }
            
            // Verificar si hay órdenes asociadas
            if ($hasTenant && $hasTenantWorkOrders && !$perDatabase) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE tenant_id = ? AND status = (SELECT slug FROM order_statuses WHERE id = ? AND tenant_id = ?)");
                $stmt->execute([$tenantValue, $id, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE status = (SELECT slug FROM order_statuses WHERE id = ?)");
                $stmt->execute([$id]);
            }
            $orderCount = $stmt->fetchColumn();
            
            if ($orderCount > 0) {
                throw new Exception('No se puede eliminar el estado porque tiene órdenes asociadas');
            }
            
            // Eliminar estado
            if ($hasTenant) {
                $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $tenantValue]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE id = ?");
                $stmt->execute([$id]);
            }
            try {
                $stmt = $hasTenant
                    ? $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC")
                    : $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC, name ASC");
                if ($hasTenant) { $stmt->execute([$tenantValue]); } else { $stmt->execute(); }
                $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $json = json_encode($list, JSON_UNESCAPED_UNICODE);
                $upsertSystemConfig('order_statuses_template', $json);
            } catch (Throwable $e) {}
            
            echo json_encode(['success' => true, 'message' => 'Estado eliminado exitosamente', 'data' => ['id' => $id]]);
            break;
            
        case 'get_all':
            // Sembrar estados por defecto si el tenant no tiene ninguno
            try {
                if ($hasTenant) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE tenant_id = ?");
                    $chk->execute([$tenantValue]);
                } else {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM order_statuses");
                    $chk->execute();
                }
                $count = (int)$chk->fetchColumn();
                if ($count === 0) {
                    $rows = [];
                    $baseId = resolveBaseTenantId();
                    try {
                        ensureSystemConfigSchema();
                        if ($hasTenantSystemConfig && !$perDatabase) {
                            $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE tenant_id = ? AND config_key = 'order_statuses_template' LIMIT 1");
                            $tplStmt->execute([(int)$baseId]);
                        } else {
                            $tplStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'order_statuses_template' LIMIT 1");
                            $tplStmt->execute([]);
                        }
                        $tpl = $tplStmt->fetchColumn();
                        if ($tpl) {
                            $decoded = json_decode($tpl, true);
                            if (is_array($decoded) && count($decoded) > 0) { $rows = $decoded; }
                        }
                    } catch (Throwable $e) {}
                    try {
                        if (!$rows) {
                            $sel = $pdo->prepare("SELECT name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC");
                            $sel->execute([$baseId]);
                            $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        }
                    } catch (Throwable $e) {}
                    if ($rows && $hasTenant) {
                        $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        foreach ($rows as $r) {
                            $ins->execute([$tenantValue, $r['name'], $r['slug'], $r['emoji'], $r['color'], $r['description'], (int)($r['is_default'] ?? 0), (int)($r['is_active'] ?? 1), (int)($r['sort_order'] ?? 0)]);
                        }
                    } else {
                        $defaults = [
                            ['pendiente','Pendiente','⏳','#ffc107','Orden creada y pendiente de revisión',1,1,1],
                            ['asignado','Asignado','📦','#6cc4ea','Dispositivo recibido en el taller',0,1,2],
                            ['diagnosticando','Diagnosticando','🔍','#fd7e14','Equipo en diagnóstico técnico',0,1,3],
                            ['esperando_aprobacion','Esperando Aprobación','✍️','#ffc107','Orden esperando aprobación del cliente',0,1,4],
                            ['esperando_repuestos','Esperando Repuestos','⏸️','#6f42c1','Orden en espera de repuestos',0,1,5],
                            ['reparando','Reparando','🔧','#007bff','Equipo en reparación',0,1,6],
                            ['testeando','Testeando','🧪','#17a2b8','Equipo en pruebas de funcionamiento',0,1,7],
                            ['completado','Completado','✅','#28a745','Trabajo completado, listo para entrega',0,1,8],
                            ['entregado','Entregado','🚚','#6c757d','Dispositivo entregado al cliente',0,1,9],
                            ['cancelado','Cancelado','❌','#dc3545','Orden cancelada',0,1,10]
                        ];
                        if ($hasTenant) {
                            $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            foreach ($defaults as $d) { $ins->execute([$tenantValue, $d[1], $d[0], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7]]); }
                        } else {
                            $ins = $pdo->prepare("INSERT INTO order_statuses (name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            foreach ($defaults as $d) { $ins->execute([$d[1], $d[0], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7]]); }
                        }
                    }
                }
            } catch (Throwable $e) {}
            
            try {
                if ($hasTenant) {
                    $needles = ['esperando_aprobacion','esperando aprobacion','esperando_aprovacion','waiting_authorization','waiting approval'];
                    $placeholders = implode(',', array_fill(0, count($needles), '?'));
                    $sel = $pdo->prepare("SELECT id, slug FROM order_statuses WHERE tenant_id = ? AND LOWER(TRIM(slug)) IN ($placeholders) ORDER BY id ASC");
                    $params = array_merge([(int)$tenantValue], array_map(function($s){ return strtolower(trim($s)); }, $needles));
                    $sel->execute($params);
                    $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $canonicalId = null;
                    $toDelete = [];
                    foreach ($rows as $r) {
                        $slug = strtolower(trim((string)($r['slug'] ?? '')));
                        if ($slug === 'esperando_aprobacion' && $canonicalId === null) {
                            $canonicalId = (int)$r['id'];
                        } else {
                            $toDelete[] = (int)$r['id'];
                        }
                    }
                    if ($canonicalId === null && !empty($rows)) {
                        $canonicalId = (int)$rows[0]['id'];
                    }
                    if ($canonicalId) {
                        $pdo->prepare("UPDATE order_statuses SET slug = 'esperando_aprobacion', name = COALESCE(NULLIF(name,''),'Esperando Aprobación'), emoji = COALESCE(NULLIF(emoji,''),'✍️'), color = COALESCE(NULLIF(color,''),'#ffc107'), is_active = 1 WHERE id = ? AND tenant_id = ?")
                            ->execute([$canonicalId, (int)$tenantValue]);
                        foreach ($toDelete as $did) {
                            $pdo->prepare("DELETE FROM order_statuses WHERE id = ? AND tenant_id = ?")->execute([$did, (int)$tenantValue]);
                        }
                    } else {
                        $exists = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE tenant_id = ? AND slug = 'esperando_aprobacion'");
                        $exists->execute([(int)$tenantValue]);
                        if ((int)$exists->fetchColumn() === 0) {
                            $so = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM order_statuses WHERE tenant_id = ?");
                            $so->execute([(int)$tenantValue]);
                            $next = (int)$so->fetchColumn();
                            $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, 'Esperando Aprobación', 'esperando_aprobacion', '✍️', '#ffc107', 'Orden esperando aprobación del cliente', 0, 1, ?, NOW())")
                                ->execute([(int)$tenantValue, $next ?: 1]);
                        }
                    }
                }
            } catch (Throwable $e) {}

            if ($hasTenant) {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE tenant_id = ? AND is_active = 1 AND slug <> 'approved' ORDER BY sort_order ASC");
                $stmt->execute([$tenantValue]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM order_statuses WHERE is_active = 1 AND slug <> 'approved' ORDER BY sort_order ASC");
                $stmt->execute([]);
            }
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $statuses]);
            break;
        
        case 'reorder':
            $idsJson = $_POST['ids'] ?? '[]';
            $ids = json_decode($idsJson, true);
            if (!is_array($ids) || count($ids) === 0) {
                throw new Exception('Lista de IDs inválida');
            }
            // Validar que los IDs pertenecen al tenant (si aplica)
            try {
                $pdo->beginTransaction();
                $order = 1;
                // Actualizar sort_order para los IDs recibidos en el orden dado
                foreach ($ids as $id) {
                    $idInt = (int)$id;
                    if ($idInt <= 0) continue;
                    if ($hasTenant) {
                        $own = $pdo->prepare("SELECT tenant_id FROM order_statuses WHERE id = ?");
                        $own->execute([$idInt]);
                        $tid = $own->fetchColumn();
                        if ((int)$tid !== (int)$tenantValue) { continue; }
                    }
                    $stmt = $hasTenant
                        ? $pdo->prepare("UPDATE order_statuses SET sort_order = ? WHERE id = ? AND tenant_id = ?")
                        : $pdo->prepare("UPDATE order_statuses SET sort_order = ? WHERE id = ?");
                    if ($hasTenant) { $stmt->execute([$order, $idInt, $tenantValue]); }
                    else { $stmt->execute([$order, $idInt]); }
                    $order++;
                }
                // Resequenciar el resto para evitar solapes y que queden “por defecto”
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                if ($hasTenant) {
                    $selRest = $pdo->prepare("SELECT id FROM order_statuses WHERE tenant_id = ? AND id NOT IN ($placeholders) ORDER BY sort_order ASC");
                    $params = array_merge([$tenantValue], array_map('intval', $ids));
                    $selRest->execute($params);
                } else {
                    $selRest = $pdo->prepare("SELECT id FROM order_statuses WHERE id NOT IN ($placeholders) ORDER BY sort_order ASC");
                    $selRest->execute(array_map('intval', $ids));
                }
                $rest = $selRest->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($rest as $rid) {
                    $ridInt = (int)$rid;
                    $stmt = $hasTenant
                        ? $pdo->prepare("UPDATE order_statuses SET sort_order = ? WHERE id = ? AND tenant_id = ?")
                        : $pdo->prepare("UPDATE order_statuses SET sort_order = ? WHERE id = ?");
                    if ($hasTenant) { $stmt->execute([$order, $ridInt, $tenantValue]); }
                    else { $stmt->execute([$order, $ridInt]); }
                    $order++;
                }
                $selAll = $hasTenant
                    ? $pdo->prepare("SELECT id, name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC")
                    : $pdo->prepare("SELECT id, name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC");
                if ($hasTenant) { $selAll->execute([$tenantValue]); } else { $selAll->execute(); }
                $rows = $selAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $byId = [];
                foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
                $ordered = [];
                foreach ($ids as $id) {
                    $idInt = (int)$id;
                    if (isset($byId[$idInt])) { $ordered[] = $byId[$idInt]; unset($byId[$idInt]); }
                }
                foreach ($byId as $r) { $ordered[] = $r; }
                $json = json_encode($ordered, JSON_UNESCAPED_UNICODE);
                $upsertSystemConfig('order_statuses_template', $json);
                $pdo->commit();
                $applied = [];
                $pos = 1;
                foreach ($ordered as $r) {
                    $applied[] = ['id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'name' => (string)$r['name'], 'sort_order' => $pos];
                    $pos++;
                }
                echo json_encode(['success' => true, 'message' => 'Orden actualizado y plantilla sincronizada', 'applied_order' => $applied]);
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $__) {}
                echo json_encode(['success' => false, 'message' => 'Error al actualizar orden: ' . $e->getMessage()]);
            }
            break;

        case 'normalize_styles':
            $force = (int)($_POST['force'] ?? 0) === 1;
            $map = [
                'pendiente' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'pending' => ['emoji' => '⏳', 'color' => '#ffc107'],
                'recibido' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'received' => ['emoji' => '📦', 'color' => '#0d6efd'],
                'asignado' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'assigned' => ['emoji' => '📥', 'color' => '#6cc4ea'],
                'diagnosticando' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'diagnosing' => ['emoji' => '🔍', 'color' => '#fd7e14'],
                'esperando_repuestos' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'waiting_parts' => ['emoji' => '⏸️', 'color' => '#6f42c1'],
                'esperando_aprobacion' => ['emoji' => '✍️', 'color' => '#ffc107'],
                'waiting_authorization' => ['emoji' => '✍️', 'color' => '#ffc107'],
                'reparando' => ['emoji' => '🔧', 'color' => '#007bff'],
                'repairing' => ['emoji' => '🔧', 'color' => '#007bff'],
                'testeando' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'testing' => ['emoji' => '🧪', 'color' => '#17a2b8'],
                'completado' => ['emoji' => '✅', 'color' => '#28a745'],
                'completed' => ['emoji' => '✅', 'color' => '#28a745'],
                'listo' => ['emoji' => '✅', 'color' => '#28a745'],
                'entregado' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'delivered' => ['emoji' => '🚚', 'color' => '#6c757d'],
                'cancelado' => ['emoji' => '❌', 'color' => '#dc3545'],
                'cancelled' => ['emoji' => '❌', 'color' => '#dc3545'],
                'devolucion' => ['emoji' => '↩️', 'color' => '#20c997'],
                'garantia' => ['emoji' => '🛡️', 'color' => '#20c997'],
                'warranty' => ['emoji' => '🛡️', 'color' => '#20c997'],
            ];
            $isValidHex = function($c) {
                $c = strtolower(trim((string)$c));
                return (bool)preg_match('/^#[0-9a-f]{6}$/', $c);
            };
            $isLikelyEmoji = function($e) {
                $e = trim((string)$e);
                if ($e === '') return false;
                if (preg_match('/^\?+$/', $e)) return false;
                return true;
            };
            $suggest = function($slug, $name) use ($map) {
                $s = strtolower(trim((string)$slug));
                $n = strtolower(trim((string)$name));
                if (isset($map[$s])) return $map[$s];
                $hits = [
                    'pend' => $map['pendiente'],
                    'recei' => $map['recibido'],
                    'diag' => $map['diagnosticando'],
                    'repuest' => $map['esperando_repuestos'],
                    'aprob' => $map['esperando_aprobacion'],
                    'repar' => $map['reparando'],
                    'test' => $map['testeando'],
                    'compl' => $map['completado'],
                    'listo' => $map['listo'],
                    'entre' => $map['entregado'],
                    'canc' => $map['cancelado'],
                    'devol' => $map['devolucion'],
                    'garan' => $map['garantia'],
                ];
                foreach ($hits as $k => $v) {
                    if ($s !== '' && strpos($s, $k) !== false) return $v;
                    if ($n !== '' && strpos($n, $k) !== false) return $v;
                }
                return ['emoji' => '❓', 'color' => '#6c757d'];
            };

            try {
                $sel = $hasTenant
                    ? $pdo->prepare("SELECT id, name, slug, emoji, color FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, id ASC")
                    : $pdo->prepare("SELECT id, name, slug, emoji, color FROM order_statuses ORDER BY sort_order ASC, id ASC");
                if ($hasTenant) { $sel->execute([(int)$tenantValue]); } else { $sel->execute([]); }
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $pdo->beginTransaction();
                $updated = 0;
                foreach ($rows as $r) {
                    $id = (int)($r['id'] ?? 0);
                    if ($id <= 0) continue;
                    $slug = (string)($r['slug'] ?? '');
                    $name = (string)($r['name'] ?? '');
                    $curEmoji = (string)($r['emoji'] ?? '');
                    $curColor = (string)($r['color'] ?? '');
                    $sug = $suggest($slug, $name);
                    $newEmoji = $force ? $sug['emoji'] : ($isLikelyEmoji($curEmoji) ? $curEmoji : $sug['emoji']);
                    $newColor = $force ? $sug['color'] : ($isValidHex($curColor) ? $curColor : $sug['color']);
                    if ($newEmoji === $curEmoji && $newColor === $curColor) continue;
                    $upd = $hasTenant
                        ? $pdo->prepare("UPDATE order_statuses SET emoji = ?, color = ? WHERE id = ? AND tenant_id = ?")
                        : $pdo->prepare("UPDATE order_statuses SET emoji = ?, color = ? WHERE id = ?");
                    if ($hasTenant) { $upd->execute([$newEmoji, $newColor, $id, (int)$tenantValue]); }
                    else { $upd->execute([$newEmoji, $newColor, $id]); }
                    $updated++;
                }

                $selAll = $hasTenant
                    ? $pdo->prepare("SELECT id, name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order ASC, id ASC")
                    : $pdo->prepare("SELECT id, name, slug, emoji, color, description, is_default, is_active, sort_order FROM order_statuses ORDER BY sort_order ASC, id ASC");
                if ($hasTenant) { $selAll->execute([(int)$tenantValue]); } else { $selAll->execute([]); }
                $all = $selAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $upsertSystemConfig('order_statuses_template', json_encode($all, JSON_UNESCAPED_UNICODE));

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Emojis y colores aplicados', 'updated' => $updated]);
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $__) {}
                echo json_encode(['success' => false, 'message' => 'Error al aplicar emojis/colores: ' . $e->getMessage()]);
            }
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
