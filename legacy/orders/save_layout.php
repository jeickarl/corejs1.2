<?php
// Guardado de distribución de imprimible de orden de trabajo
// Crea/actualiza la clave 'work_order_layout' en system_config
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/security_enhancements.php';
require_once '../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
SecurityEnhancements::setSecurityHeaders();

$pdo = db();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }

    // Verificar sesión/autenticación
    requireLogin();

    // Leer JSON de entrada
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON inválido']);
        exit;
    }

    $csrf = isset($payload['csrf_token']) ? $payload['csrf_token'] : '';
    if (!SecurityEnhancements::verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF inválido']);
        exit;
    }

    $layout = isset($payload['layout']) ? $payload['layout'] : null;
    if (!is_array($layout)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Layout inválido']);
        exit;
    }

    // Sanitizar estructura: mantener sólo containers y rows si existen
    $clean = [
        'containers' => [],
        'rows' => []
    ];

    if (isset($layout['containers']) && is_array($layout['containers'])) {
        foreach ($layout['containers'] as $cid => $order) {
            if (!is_string($cid) || !is_array($order)) { continue; }
            $cleanOrder = [];
            foreach ($order as $key) {
                if (is_string($key) && mb_strlen($key) <= 100) {
                    $cleanOrder[] = $key;
                }
            }
            $clean['containers'][$cid] = $cleanOrder;
        }
    }

    if (isset($layout['rows']) && is_array($layout['rows'])) {
        foreach ($layout['rows'] as $sectionKey => $rowsOrder) {
            if (!is_string($sectionKey) || !is_array($rowsOrder)) { continue; }
            $cleanRows = [];
            foreach ($rowsOrder as $rk) {
                if (is_string($rk) && mb_strlen($rk) <= 100) {
                    $cleanRows[] = $rk;
                }
            }
            $clean['rows'][$sectionKey] = $cleanRows;
        }
    }

    // Guardar en system_config
    $jsonValue = json_encode($clean, JSON_UNESCAPED_UNICODE);

    // Usar transacción para atomicidad
    $pdo->beginTransaction();
    $tenant_id = getCurrentTenantId();
    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
    $tid = $perDatabase ? 1 : $tenant_id;
    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
    if ($hasTenantSystem && !$perDatabase) {
        $stmt = $pdo->prepare("UPDATE system_config SET config_value = :val WHERE config_key = 'work_order_layout' AND tenant_id = :tid");
        $stmt->execute([':val' => $jsonValue, ':tid' => $tid]);
    } else {
        $stmt = $pdo->prepare("UPDATE system_config SET config_value = :val WHERE config_key = 'work_order_layout'");
        $stmt->execute([':val' => $jsonValue]);
    }
    if ($stmt->rowCount() === 0) {
        if ($hasTenantSystem) {
            $stmt2 = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (:tid, 'work_order_layout', :val)");
            $stmt2->execute([':tid' => $tid, ':val' => $jsonValue]);
        } else {
            $stmt2 = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES ('work_order_layout', :val)");
            $stmt2->execute([':val' => $jsonValue]);
        }
    }
    $pdo->commit();

    // Log de seguridad
    SecurityEnhancements::logSecurityEvent($pdo, 'LAYOUT_SAVE', 'work_order_layout actualizado', (int)($_SESSION['user_id'] ?? 0));

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno', 'detail' => $e->getMessage()]);
}
