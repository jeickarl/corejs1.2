<?php
require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli' && isset($_GET['action']) && $_GET['action'] === 'remove_global_statuses') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/../config/functions.php';
    if (!function_exists('isAdminSession') || !isAdminSession()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acción no disponible']);
    exit;
}

if (PHP_SAPI !== 'cli' && isset($_GET['action']) && $_GET['action'] === 'normalize_order_statuses') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/../config/functions.php';
    if (!isAdminSession()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
    $tenantId = getCurrentTenantId();
    if (!$tenantId || $tenantId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Tenant inválido']);
        exit;
    }
    $perDatabase = isPerDatabaseMode();
    $tenantValue = $perDatabase ? 1 : (int)$tenantId;
    $hasTenantWo = false;
    $hasTenantHist = false;
    try { $c = $pdo->query("SHOW COLUMNS FROM work_orders LIKE 'tenant_id'"); $hasTenantWo = ($c && $c->rowCount() > 0); } catch (Throwable $__) {}
    try { $c = $pdo->query("SHOW COLUMNS FROM order_status_history LIKE 'tenant_id'"); $hasTenantHist = ($c && $c->rowCount() > 0); } catch (Throwable $__) {}
    $map = [
        'pending' => 'pendiente',
        'received' => 'asignado',
        'diagnosing' => 'diagnosticando',
        'waiting_parts' => 'esperando_repuestos',
        'repairing' => 'reparando',
        'testing' => 'testeando',
        'completed' => 'completado',
        'delivered' => 'entregado',
        'cancelled' => 'cancelado',
        'canceled' => 'cancelado',
        'approved' => 'aprobado'
    ];
    $totalWO = 0;
    $totalH = 0;
    foreach ($map as $en => $es) {
        if ($hasTenantWo && !$perDatabase) {
            $updWO = $pdo->prepare("UPDATE work_orders SET status = ? WHERE tenant_id = ? AND LOWER(TRIM(status)) = ?");
            $updWO->execute([$es, $tenantValue, $en]);
        } else {
            $updWO = $pdo->prepare("UPDATE work_orders SET status = ? WHERE LOWER(TRIM(status)) = ?");
            $updWO->execute([$es, $en]);
        }
        $totalWO += (int)$updWO->rowCount();
        try {
            if ($hasTenantHist && !$perDatabase) {
                $updH = $pdo->prepare("UPDATE order_status_history SET status = ? WHERE tenant_id = ? AND LOWER(TRIM(status)) = ?");
                $updH->execute([$es, $tenantValue, $en]);
            } else {
                $updH = $pdo->prepare("UPDATE order_status_history SET status = ? WHERE LOWER(TRIM(status)) = ?");
                $updH->execute([$es, $en]);
            }
            $totalH += (int)$updH->rowCount();
        } catch (Throwable $__) {}
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'work_orders_updated' => $totalWO, 'history_updated' => $totalH]);
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'normalize_order_statuses') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    try {
        $map = [
            'pending' => 'pendiente',
            'received' => 'asignado',
            'diagnosing' => 'diagnosticando',
            'waiting_parts' => 'esperando_repuestos',
            'repairing' => 'reparando',
            'testing' => 'testeando',
            'completed' => 'completado',
            'delivered' => 'entregado',
            'cancelled' => 'cancelado',
            'canceled' => 'cancelado',
            'approved' => 'aprobado'
        ];
        $totalWO = 0;
        $totalH = 0;
        foreach ($map as $en => $es) {
            if ($tenantArg > 0) {
                $updWO = $pdo->prepare("UPDATE work_orders SET status = ? WHERE tenant_id = ? AND LOWER(TRIM(status)) = ?");
                $updWO->execute([$es, $tenantArg, $en]);
                $totalWO += (int)$updWO->rowCount();
                try {
                    $updH = $pdo->prepare("UPDATE order_status_history SET status = ? WHERE tenant_id = ? AND LOWER(TRIM(status)) = ?");
                    $updH->execute([$es, $tenantArg, $en]);
                    $totalH += (int)$updH->rowCount();
                } catch (Throwable $__) {}
            } else {
                $updWO = $pdo->prepare("UPDATE work_orders SET status = ? WHERE LOWER(TRIM(status)) = ?");
                $updWO->execute([$es, $en]);
                $totalWO += (int)$updWO->rowCount();
                try {
                    $updH = $pdo->prepare("UPDATE order_status_history SET status = ? WHERE LOWER(TRIM(status)) = ?");
                    $updH->execute([$es, $en]);
                    $totalH += (int)$updH->rowCount();
                } catch (Throwable $__) {}
            }
        }
        echo "Normalización completa: work_orders actualizados={$totalWO}, historial={$totalH}\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'fix_work_orders_columns') {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $colsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders'");
        $colsStmt->execute([$dbName]);
        $cols = array_map('strtolower', $colsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $need = [];
        if (!in_array('tenant_id', $cols, true)) { $need[] = "ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id"; }
        if (!in_array('order_number', $cols, true)) { $need[] = "ADD COLUMN order_number INT(11) NOT NULL DEFAULT 0 AFTER id"; }
        if (!in_array('verification_code', $cols, true)) { $need[] = "ADD COLUMN verification_code VARCHAR(64) DEFAULT NULL AFTER technician_notes"; }
        if (!in_array('approval_status', $cols, true)) { $need[] = "ADD COLUMN approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER verification_code"; }
        if (!in_array('approved_at', $cols, true)) { $need[] = "ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approval_status"; }
        if (!in_array('approval_signature_path', $cols, true)) { $need[] = "ADD COLUMN approval_signature_path VARCHAR(500) DEFAULT NULL AFTER approved_at"; }
        if (!in_array('approval_comment', $cols, true)) { $need[] = "ADD COLUMN approval_comment TEXT DEFAULT NULL AFTER approval_signature_path"; }
        if (!in_array('approved_quote_amount', $cols, true)) { $need[] = "ADD COLUMN approved_quote_amount DECIMAL(12,2) NULL AFTER approved_at"; }
        if (!empty($need)) {
            $sql = "ALTER TABLE work_orders " . implode(", ", $need);
            $pdo->exec($sql);
            echo "work_orders actualizado: " . count($need) . " columnas añadidas\n";
        } else {
            echo "work_orders ya tiene las columnas necesarias\n";
        }
        // Índices útiles
        try { $pdo->exec("ALTER TABLE work_orders ADD KEY idx_work_orders_tenant (tenant_id)"); } catch (Throwable $__) {}
        try { $pdo->exec("ALTER TABLE work_orders ADD KEY idx_work_orders_order_number (order_number)"); } catch (Throwable $__) {}
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'add_order_status') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 1;
    $slug = isset($argv[3]) ? strtolower(trim($argv[3])) : '';
    $name = isset($argv[4]) ? trim($argv[4]) : '';
    $emoji = isset($argv[5]) ? trim($argv[5]) : '';
    $color = isset($argv[6]) ? trim($argv[6]) : '#28a745';
    $description = isset($argv[7]) ? trim($argv[7]) : 'Presupuesto aprobado por el cliente';
    if ($slug === '' || $name === '') {
        fwrite(STDERR, "ERROR: slug y nombre son obligatorios\n");
        exit(1);
    }
    try {
        $hasTenant = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        if ($hasTenant) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ? AND tenant_id = ?");
            $exists->execute([$slug, $tenantArg]);
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ?");
            $exists->execute([$slug]);
        }
        $cnt = (int)$exists->fetchColumn();
        if ($cnt > 0) {
            echo "Estado ya existe: $slug\n";
            exit(0);
        }
        // sort_order siguiente
        if ($hasTenant) {
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM order_statuses WHERE tenant_id = ?");
            $maxStmt->execute([$tenantArg]);
        } else {
            $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM order_statuses");
        }
        $next = (int)($hasTenant ? $maxStmt->fetchColumn() : $maxStmt->fetchColumn()) + 1;
        if ($hasTenant) {
            $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, slug, emoji, name, description, color, is_active, is_default, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, NOW())");
            $ins->execute([$tenantArg, $slug, $emoji, $name, $description, $color, $next]);
        } else {
            $ins = $pdo->prepare("INSERT INTO order_statuses (slug, emoji, name, description, color, is_active, is_default, sort_order, created_at) VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW())");
            $ins->execute([$slug, $emoji, $name, $description, $color, $next]);
        }
        echo "Estado añadido: $slug\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

function backfillOrderHistory(PDO $pdo, int $orderId, ?int $tenantId = null): array {
    $out = [];
    try {
        require_once __DIR__ . '/../config/functions.php';
        ensureOrderStatusHistorySchema($pdo);
        $stmt = $pdo->prepare("SELECT id, tenant_id, status, approval_status, created_at, received_date, completed_date, delivered_date, approved_at, updated_at FROM work_orders WHERE id = ? " . ($tenantId ? "AND tenant_id = ?" : "") . " LIMIT 1");
        $params = $tenantId ? [$orderId, $tenantId] : [$orderId];
        $stmt->execute($params);
        $wo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wo) {
            // Intentar por order_number
            $stmt2 = $pdo->prepare("SELECT id, tenant_id, status, approval_status, created_at, received_date, completed_date, delivered_date, approved_at, updated_at FROM work_orders WHERE order_number = ? " . ($tenantId ? "AND tenant_id = ?" : "") . " LIMIT 1");
            $stmt2->execute($tenantId ? [$orderId, $tenantId] : [$orderId]);
            $wo = $stmt2->fetch(PDO::FETCH_ASSOC);
            if (!$wo) return ["Orden no encontrada: $orderId"];
        }
        $orderId = (int)$wo['id'];
        $tid = (int)$wo['tenant_id'];
        $existingStmt = $pdo->prepare("SELECT status, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
        $existingStmt->execute([$orderId]);
        $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $have = [];
        foreach ($existing as $e) { $have[strtolower(trim((string)$e['status']))] = true; }
        $events = [];
        $push = function($ts, $status, $note) use (&$events) {
            if (!$ts || trim((string)$ts) === '') return;
            $events[] = ['ts' => $ts, 'status' => strtolower(trim($status)), 'note' => $note];
        };
        // Creación
        $push($wo['created_at'] ?? null, normalizeStatusSlug($wo['status'] ?? 'pendiente'), 'Orden creada');
        // Recibido
        $push($wo['received_date'] ?? null, 'asignado', 'Equipo recibido');
        // Aprobado/Rechazado
        if (!empty($wo['approved_at'])) {
            $push($wo['approved_at'], 'aprobado', 'Presupuesto aprobado');
        } else {
            $ap = strtolower((string)($wo['approval_status'] ?? ''));
            if (in_array($ap, ['rejected','rechazado'], true)) {
                $push(($wo['updated_at'] ?? $wo['created_at'] ?? null), 'rechazado', 'Presupuesto rechazado');
            }
        }
        // Completado
        $push($wo['completed_date'] ?? null, 'completado', 'Trabajo completado');
        // Entregado
        $push($wo['delivered_date'] ?? null, 'entregado', 'Dispositivo entregado');
        // Ordenar por timestamp ascendente
        usort($events, function($a,$b){ return strtotime($a['ts']) <=> strtotime($b['ts']); });
        $ins = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, changed_by, tenant_id, created_at) VALUES (?, ?, ?, NULL, ?, ?)");
        $added = 0;
        foreach ($events as $ev) {
            $st = strtolower(trim($ev['status']));
            if (!isset($have[$st])) {
                $ins->execute([$orderId, $st, $ev['note'], $tid, $ev['ts']]);
                $have[$st] = true;
                $added++;
            }
        }
        $out[] = "Backfill historial: orden {$orderId}, tenant {$tid}, añadidos {$added}";
    } catch (Throwable $e) {
        $out[] = "Error backfill: " . $e->getMessage();
    }
    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'backfill_order_history') {
    $orderId = isset($argv[2]) ? (int)$argv[2] : 0;
    $tenantId = isset($argv[3]) ? (int)$argv[3] : null;
    if ($orderId <= 0) {
        fwrite(STDERR, "ERROR: uso: backfill_order_history <order_id> [tenant_id]\n");
        exit(1);
    }
    $out = backfillOrderHistory($pdo, $orderId, $tenantId);
    echo implode("\n", $out) . "\n";
    exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'seed_aprobado') {
    try {
        $tenantId = resolveBaseTenantId();
        $slug = 'aprobado';
        $name = 'Aprobado';
        $emoji = '✍️';
        $color = '#28a745';
        $description = 'Presupuesto aprobado por el cliente';
        $hasTenant = false;
        try {
            $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
            $hasTenant = ($c && $c->rowCount() > 0);
        } catch (Throwable $e) {}
        if ($hasTenant) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ? AND tenant_id = ?");
            $exists->execute([$slug, $tenantId]);
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM order_statuses WHERE slug = ?");
            $exists->execute([$slug]);
        }
        $cnt = (int)$exists->fetchColumn();
        if ($cnt > 0) {
            echo "Estado ya existía: aprobado\n";
            exit(0);
        }
        if ($hasTenant) {
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM order_statuses WHERE tenant_id = ?");
            $maxStmt->execute([$tenantId]);
        } else {
            $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM order_statuses");
        }
        $next = (int)($hasTenant ? $maxStmt->fetchColumn() : $maxStmt->fetchColumn()) + 1;
        if ($hasTenant) {
            $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, slug, emoji, name, description, color, is_active, is_default, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, NOW())");
            $ins->execute([$tenantId, $slug, $emoji, $name, $description, $color, $next]);
        } else {
            $ins = $pdo->prepare("INSERT INTO order_statuses (slug, emoji, name, description, color, is_active, is_default, sort_order, created_at) VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW())");
            $ins->execute([$slug, $emoji, $name, $description, $color, $next]);
        }
        echo "Estado sembrado: aprobado\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'dedupe_order_statuses') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    try {
        // Asegurar columna tenant_id e índice único por slug+tenant
        try {
            $c = $pdo->query("SHOW COLUMNS FROM order_statuses LIKE 'tenant_id'");
            if (!$c || $c->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_statuses ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id");
                $pdo->exec("CREATE INDEX idx_order_statuses_tenant ON order_statuses(tenant_id)");
            }
        } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE order_statuses ADD UNIQUE KEY uq_order_statuses_slug_tenant (slug, tenant_id)"); } catch (Throwable $__) {}

        // Normalizar tenant_id para registros sin asignar (0 o NULL) al tenant base
        try {
            $baseId = resolveBaseTenantId();
            $pdo->exec("UPDATE order_statuses SET tenant_id = {$baseId} WHERE tenant_id IS NULL OR tenant_id = 0");
        } catch (Throwable $e) {}

        // Detectar duplicados por tenant
        $whereTenant = $tenantArg > 0 ? "WHERE tenant_id = ?" : "";
        $sqlDup = "
            SELECT LOWER(TRIM(slug)) AS s, tenant_id, COUNT(*) AS c
            FROM order_statuses
            {$whereTenant}
            GROUP BY tenant_id, LOWER(TRIM(slug))
            HAVING COUNT(*) > 1
        ";
        $st = $tenantArg > 0 ? $pdo->prepare($sqlDup) : $pdo->prepare(str_replace("{$whereTenant}", "", $sqlDup));
        if ($tenantArg > 0) { $st->execute([$tenantArg]); } else { $st->execute(); }
        $dups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $removed = 0;
        foreach ($dups as $d) {
            $slug = (string)$d['s'];
            $tid = (int)$d['tenant_id'];
            // Seleccionar el registro que se debe conservar: preferir is_default=1, luego menor sort_order, luego más antiguo
            $q = $pdo->prepare("
                SELECT id, is_default, sort_order, created_at
                FROM order_statuses
                WHERE tenant_id = ? AND LOWER(TRIM(slug)) = ?
                ORDER BY is_default DESC, sort_order ASC, created_at ASC
            ");
            $q->execute([$tid, $slug]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) <= 1) { continue; }
            $keepId = (int)$rows[0]['id'];
            $toDelete = array_slice(array_map(function($r){ return (int)$r['id']; }, $rows), 1);
            if (!empty($toDelete)) {
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $params = array_merge([$tid, $slug], $toDelete);
                $del = $pdo->prepare("DELETE FROM order_statuses WHERE tenant_id = ? AND LOWER(TRIM(slug)) = ? AND id IN ($in)");
                $del->execute($params);
                $removed += (int)$del->rowCount();
            }
            // Asegurar único por índice (por si hay colisiones restantes)
            try { $upd = $pdo->prepare("UPDATE order_statuses SET slug = ? WHERE id = ?"); $upd->execute([$slug, $keepId]); } catch (Throwable $__) {}
        }

        echo "Estados duplicados eliminados: {$removed}\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI !== 'cli' && isset($_GET['action']) && $_GET['action'] === 'set_base_tenant_id') {
    require_once __DIR__ . '/auth.php';
    if (!isAdminSession()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
    $tid = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    if ($tid <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'tenant inválido']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO system_config (tenant_id, config_key, config_value) VALUES (?, 'base_tenant_id', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
        $stmt->execute([$tid, strval($tid)]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'base_tenant_id actualizado', 'tenant_id' => $tid]);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'fix_tenants_ai') {
    try {
        $changes = ensureAutoIncrementTenants($pdo);
        echo implode("\n", $changes) . "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'list_statuses') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($tenantArg <= 0) {
        fwrite(STDERR, "ERROR: tenant inválido\n");
        exit(1);
    }
    $st = $pdo->prepare("SELECT slug, name, color, emoji, is_default, is_active, sort_order FROM order_statuses WHERE tenant_id = ? ORDER BY sort_order, name");
    $st->execute([$tenantArg]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['tenant_id' => $tenantArg, 'count' => count($rows), 'statuses' => $rows], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'normalize_tenant_zero') {
    try {
        $zeroCount = (int)($pdo->query("SELECT COUNT(*) FROM tenants WHERE id = 0")->fetchColumn() ?: 0);
        if ($zeroCount === 0) {
            echo "Sin tenant con id=0\n";
            exit(0);
        }
        $maxId = (int)($pdo->query("SELECT IFNULL(MAX(id),0) FROM tenants")->fetchColumn() ?: 0);
        $newId = $maxId + 1;
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE tenants SET id = ? WHERE id = 0")->execute([$newId]);
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $q = $pdo->prepare("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'
        ");
        $q->execute([$db]);
        $tables = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $t) {
            $stmt = $pdo->prepare("UPDATE `$t` SET tenant_id = ? WHERE tenant_id = 0");
            $stmt->execute([$newId]);
        }
        $pdo->commit();
        echo "tenant_id=0 normalizado a $newId en " . count($tables) . " tablas\n";
        exit(0);
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $__) {}
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'seed_statuses') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($tenantArg <= 0) {
        fwrite(STDERR, "ERROR: tenant inválido\n");
        exit(1);
    }
    try {
        ensureDefaultOrderStatuses($tenantArg);
        echo "Estados sembrados para tenant $tenantArg\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'seed_defaults_force') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($tenantArg <= 0) {
        fwrite(STDERR, "ERROR: tenant inválido\n");
        exit(1);
    }
    try {
        $existing = $pdo->prepare("SELECT slug FROM order_statuses WHERE tenant_id = ?");
        $existing->execute([$tenantArg]);
        $slugs = array_map(function($r){ return strtolower(trim($r['slug'])); }, $existing->fetchAll(PDO::FETCH_ASSOC));
        $defaults = [
            ['pendiente','Pendiente','⏳','#ffc107','Orden creada y pendiente de revisión',1,1,1],
            ['asignado','Asignado','📦','#6cc4ea','Dispositivo recibido en el taller',0,1,2],
            ['diagnosticando','Diagnosticando','🔍','#fd7e14','Equipo en diagnóstico técnico',0,1,3],
            ['esperando_repuestos','Esperando Repuestos','⏸️','#6f42c1','Orden en espera de repuestos',0,1,4],
            ['reparando','Reparando','🔧','#007bff','Equipo en reparación',0,1,5],
            ['testeando','Testeando','🧪','#17a2b8','Equipo en pruebas de funcionamiento',0,1,6],
            ['completado','Completado','✅','#28a745','Trabajo completado, listo para entrega',0,1,7],
            ['entregado','Entregado','🚚','#6c757d','Dispositivo entregado al cliente',0,1,8],
            ['cancelado','Cancelado','❌','#dc3545','Orden cancelada',0,1,9]
        ];
        $ins = $pdo->prepare("INSERT INTO order_statuses (tenant_id, name, slug, emoji, color, description, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $added = 0;
        foreach ($defaults as $d) {
            if (!in_array($d[0], $slugs, true)) {
                $ins->execute([$tenantArg, $d[1], $d[0], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7]]);
                $added++;
            }
        }
        echo "Defaults forzados: $added insertados para tenant $tenantArg\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2 && $argv[1] === 'remove_english_statuses') {
    $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($tenantArg <= 0) {
        fwrite(STDERR, "ERROR: tenant inválido\n");
        exit(1);
    }
    try {
        $english = ['pending','received','diagnosing','waiting_parts','repairing','testing','completed','delivered','cancelled','approved'];
        $placeholders = implode(',', array_fill(0, count($english), '?'));
        $params = array_merge([$tenantArg], $english);
        $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE tenant_id = ? AND slug IN ($placeholders)");
        $stmt->execute($params);
        echo "Estados en inglés eliminados para tenant $tenantArg\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI !== 'cli' && isset($_GET['action']) && $_GET['action'] === 'remove_approved') {
    require_once __DIR__ . '/auth.php';
    $tenantArg = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    if ($tenantArg <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'tenant inválido']);
        exit;
    }
    $out = removeApprovedOrderStatus($pdo, $tenantArg);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'messages' => $out]);
    exit;
}

function removeGlobalOrderStatuses(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE tenant_id IS NULL OR tenant_id = 0");
        $stmt->execute();
        $affected = (int)$stmt->rowCount();
        return $affected > 0 ? ["Eliminados estados globales ($affected filas)"] : ["Sin estados globales para eliminar"];
    } catch (Throwable $e) {
        return ["Error eliminando estados globales: " . $e->getMessage()];
    }
}

function removeApprovedOrderStatus(PDO $pdo, int $tenantId): array {
    try {
        $stmt = $pdo->prepare("DELETE FROM order_statuses WHERE slug = 'approved' AND tenant_id = ?");
        $stmt->execute([$tenantId]);
        $affected = (int)$stmt->rowCount();
        return $affected > 0 ? ["Eliminado estado 'approved' para tenant $tenantId ($affected filas)"] : ["Sin cambios en tenant $tenantId"];
    } catch (Throwable $e) {
        return ["Error eliminando 'approved' para tenant $tenantId: " . $e->getMessage()];
    }
}

function createTenantQuick(PDO $pdo, string $companyName, string $adminEmail, string $adminPassword): array {
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT tenant_id FROM saas_users_lookup WHERE email = ? LIMIT 1");
        $stmt->execute([$adminEmail]);
        if ($stmt->fetchColumn()) { return ["Email ya registrado"]; }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $companyName), '-'));
        $maxId = (int)($pdo->query("SELECT IFNULL(MAX(id),0) FROM tenants")->fetchColumn() ?: 0);
        $tenantId = $maxId + 1;
        $stmt = $pdo->prepare("INSERT INTO tenants (id, company_name, slug, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
        $stmt->execute([$tenantId, $companyName, $slug]);
        $stmt = $pdo->prepare("INSERT INTO saas_users_lookup (email, tenant_id) VALUES (?, ?)");
        $stmt->execute([$adminEmail, $tenantId]);
        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (?, ?, ?, ?, 'admin', 1, NOW())");
        $stmt->execute([$tenantId, "Administrador", $adminEmail, $passwordHash]);
        $stmt = $pdo->prepare("INSERT INTO company_config (tenant_id, company_name, company_email, company_address, created_at, updated_at) VALUES (?, ?, ?, 'Dirección de la empresa', NOW(), NOW())");
        $stmt->execute([$tenantId, $companyName, $adminEmail]);
        try { ensureDefaultOrderStatuses($tenantId); } catch (Throwable $e) {}
        try { ensureDefaultTenantCatalogs($tenantId); } catch (Throwable $e) {}
        $out[] = "Tenant creado: $tenantId";
        return $out;
    } catch (Throwable $e) {
        return ["Error creando tenant: " . $e->getMessage()];
    }
}

function ensureAutoIncrementClients(PDO $pdo): array {
    $dbStmt = $pdo->query("SELECT DATABASE()");
    $db = $dbStmt->fetchColumn();
    $aiStmt = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'id'");
    $aiStmt->execute([$db]);
    $extra = $aiStmt->fetchColumn();
    $changes = [];
    if (!$extra || stripos($extra, 'auto_increment') === false) {
        $pkCheck = $pdo->query("SHOW KEYS FROM clients WHERE Key_name = 'PRIMARY'");
        if (!$pkCheck || $pkCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE clients ADD PRIMARY KEY (id)");
            $changes[] = "PRIMARY KEY añadido en clients(id)";
        }
        $pdo->exec("ALTER TABLE clients MODIFY id int(11) NOT NULL AUTO_INCREMENT");
        $changes[] = "AUTO_INCREMENT aplicado en clients.id";
    }
    $zeros = $pdo->query("SELECT COUNT(*) FROM clients WHERE id = 0")->fetchColumn();
    if ((int)$zeros > 0) {
        $maxId = (int)($pdo->query("SELECT MAX(id) FROM clients")->fetchColumn() ?: 0);
        while ((int)$pdo->query("SELECT COUNT(*) FROM clients WHERE id = 0")->fetchColumn() > 0) {
            $maxId++;
            $upd = $pdo->prepare("UPDATE clients SET id = ? WHERE id = 0 LIMIT 1");
            $upd->execute([$maxId]);
        }
        $changes[] = "Registros con id=0 reasignados";
    }
    return $changes;
}

function ensureAutoIncrementTenants(PDO $pdo): array {
    $changes = [];
    try {
        $dbStmt = $pdo->query("SELECT DATABASE()");
        $db = $dbStmt->fetchColumn();
        $aiStmt = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'id'");
        $aiStmt->execute([$db]);
        $extra = $aiStmt->fetchColumn();
        if (!$extra || stripos($extra, 'auto_increment') === false) {
            $pkCheck = $pdo->query("SHOW KEYS FROM tenants WHERE Key_name = 'PRIMARY'");
            if (!$pkCheck || $pkCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE tenants ADD PRIMARY KEY (id)");
                $changes[] = "PRIMARY KEY añadido en tenants(id)";
            }
            $pdo->exec("ALTER TABLE tenants MODIFY id int(11) NOT NULL AUTO_INCREMENT");
            $changes[] = "AUTO_INCREMENT aplicado en tenants.id";
        }
        $zeros = $pdo->query("SELECT COUNT(*) FROM tenants WHERE id = 0")->fetchColumn();
        if ((int)$zeros > 0) {
            $maxId = (int)($pdo->query("SELECT MAX(id) FROM tenants")->fetchColumn() ?: 0);
            $maxId++;
            $pdo->beginTransaction();
            try {
                $updT = $pdo->prepare("UPDATE tenants SET id = ? WHERE id = 0");
                $updT->execute([$maxId]);
                $updU = $pdo->prepare("UPDATE users SET tenant_id = ? WHERE tenant_id = 0");
                $updU->execute([$maxId]);
                $updC = $pdo->prepare("UPDATE company_config SET tenant_id = ? WHERE tenant_id = 0");
                $updC->execute([$maxId]);
                $updS = $pdo->prepare("UPDATE company_settings SET tenant_id = ? WHERE tenant_id = 0");
                try { $updS->execute([$maxId]); } catch (Throwable $__) {}
                $updL = $pdo->prepare("UPDATE saas_users_lookup SET tenant_id = ? WHERE tenant_id = 0");
                try { $updL->execute([$maxId]); } catch (Throwable $__) {}
                $pdo->commit();
                $changes[] = "Registros de tenant_id=0 reasignados al id $maxId";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $changes[] = "Error reasignando tenants id=0: " . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $changes[] = "Error ajustando tenants: " . $e->getMessage();
    }
    return $changes;
}

function ensureOrderStatusesIndexes(PDO $pdo): array {
    $changes = [];
    // Asegurar PK y AUTO_INCREMENT en id
    $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $extraStmt = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'order_statuses' AND COLUMN_NAME = 'id'");
    $extraStmt->execute([$db]);
    $extra = (string)($extraStmt->fetchColumn() ?: '');
    $pkCheck = $pdo->query("SHOW KEYS FROM order_statuses WHERE Key_name = 'PRIMARY'");
    if (!$pkCheck || $pkCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE order_statuses ADD PRIMARY KEY (id)");
        $changes[] = "PRIMARY KEY añadido en order_statuses(id)";
    }
    if (stripos($extra, 'auto_increment') === false) {
        // Reasignar posibles registros con id=0 para evitar duplicados
        $zeros = (int)($pdo->query("SELECT COUNT(*) FROM order_statuses WHERE id = 0")->fetchColumn() ?: 0);
        if ($zeros > 0) {
            $maxId = (int)($pdo->query("SELECT MAX(id) FROM order_statuses")->fetchColumn() ?: 0);
            while ((int)$pdo->query("SELECT COUNT(*) FROM order_statuses WHERE id = 0")->fetchColumn() > 0) {
                $maxId++;
                $pdo->prepare("UPDATE order_statuses SET id = ? WHERE id = 0 LIMIT 1")->execute([$maxId]);
            }
            $changes[] = "order_statuses: registros con id=0 reasignados";
        }
        $pdo->exec("ALTER TABLE order_statuses MODIFY id int(11) NOT NULL AUTO_INCREMENT");
        $changes[] = "AUTO_INCREMENT aplicado en order_statuses.id";
    }
    $idxExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_statuses' AND INDEX_NAME = 'uq_order_statuses_slug_tenant'
    ");
    $idxExists->execute();
    if ((int)$idxExists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE order_statuses ADD UNIQUE KEY uq_order_statuses_slug_tenant (slug, tenant_id)");
        $changes[] = "Índice único uq_order_statuses_slug_tenant creado (slug, tenant_id)";
    }
    return $changes;
}

function ensureWorkOrdersCollation(PDO $pdo): array {
    $changes = [];
    try {
        $dbStmt = $pdo->query("SELECT DATABASE()");
        $db = $dbStmt->fetchColumn();
        $tblStmt = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders'");
        $tblStmt->execute([$db]);
        $current = (string)($tblStmt->fetchColumn() ?: '');
        if (strtolower($current) !== 'utf8mb4_spanish_ci') {
            $pdo->exec("ALTER TABLE work_orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
            $changes[] = "Collation de work_orders convertido a utf8mb4_spanish_ci";
        }
        // Asegurar columnas críticas
        $cols = ['verification_code','approval_status','approval_comment'];
        foreach ($cols as $col) {
            $colStmt = $pdo->prepare("
                SELECT CHARACTER_SET_NAME, COLLATION_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = ?
            ");
            $colStmt->execute([$db, $col]);
            $info = $colStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dataType = strtolower((string)($info['DATA_TYPE'] ?? ''));
            $len = (int)($info['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            $coll = strtolower((string)($info['COLLATION_NAME'] ?? ''));
            if (in_array($dataType, ['varchar','text','mediumtext','longtext','tinytext'], true) && $coll !== 'utf8mb4_spanish_ci') {
                if ($dataType === 'varchar' && $len > 0) {
                    $pdo->exec("ALTER TABLE work_orders MODIFY `$col` VARCHAR($len) COLLATE utf8mb4_spanish_ci");
                } else {
                    $pdo->exec("ALTER TABLE work_orders MODIFY `$col` $dataType COLLATE utf8mb4_spanish_ci");
                }
                $changes[] = "Collation de work_orders.$col ajustado a utf8mb4_spanish_ci";
            }
        }
    } catch (Throwable $e) {
        $changes[] = "Error ajustando collation de work_orders: " . $e->getMessage();
    }
    return $changes;
}

function ensureTableCollation(PDO $pdo, string $table, array $columns): array {
    $changes = [];
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $tbl = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $tbl->execute([$db, $table]);
        $current = (string)($tbl->fetchColumn() ?: '');
        if (strtolower($current) !== 'utf8mb4_spanish_ci') {
            $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
            $changes[] = "Collation de $table convertido a utf8mb4_spanish_ci";
        }
        foreach ($columns as $col) {
            $colStmt = $pdo->prepare("
                SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $colStmt->execute([$db, $table, $col]);
            $info = $colStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dataType = strtolower((string)($info['DATA_TYPE'] ?? ''));
            $len = (int)($info['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            $coll = strtolower((string)($info['COLLATION_NAME'] ?? ''));
            if (in_array($dataType, ['varchar','text','mediumtext','longtext','tinytext'], true) && $coll !== 'utf8mb4_spanish_ci') {
                if ($dataType === 'varchar' && $len > 0) {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `$col` VARCHAR($len) COLLATE utf8mb4_spanish_ci");
                } else {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `$col` $dataType COLLATE utf8mb4_spanish_ci");
                }
                $changes[] = "Collation de $table.$col ajustado a utf8mb4_spanish_ci";
            }
        }
    } catch (Throwable $e) {
        $changes[] = "Error ajustando collation de $table: " . $e->getMessage();
    }
    return $changes;
}

try {
    if (PHP_SAPI === 'cli' && isset($argv) && count($argv) >= 2) {
        if ($argv[1] === 'remove_approved') {
            $tenantArg = isset($argv[2]) ? (int)$argv[2] : 0;
            if ($tenantArg <= 0) {
                fwrite(STDERR, "ERROR: tenant inválido\n");
                exit(1);
            }
            $out = removeApprovedOrderStatus($pdo, $tenantArg);
            echo implode("\n", $out) . "\n";
            exit(0);
        }
        if ($argv[1] === 'create_tenant_quick') {
            $company = isset($argv[2]) ? (string)$argv[2] : '';
            $email = isset($argv[3]) ? (string)$argv[3] : '';
            $password = isset($argv[4]) ? (string)$argv[4] : '';
            if ($company === '' || $email === '' || $password === '') {
                fwrite(STDERR, "ERROR: parámetros: create_tenant_quick <company> <admin_email> <admin_password>\n");
                exit(1);
            }
            $out = createTenantQuick($pdo, $company, $email, $password);
            echo implode("\n", $out) . "\n";
            exit(0);
        }
    }
    $pdo->beginTransaction();
    $c1 = ensureAutoIncrementClients($pdo);
    $c1b = ensureAutoIncrementTenants($pdo);
    $c2 = ensureOrderStatusesIndexes($pdo);
    $c3 = ensureWorkOrdersCollation($pdo);
    $c4 = ensureTableCollation($pdo, 'order_statuses', ['name','slug','description','emoji']);
    $c5 = ensureTableCollation($pdo, 'clients', ['name','tax_id','id_number','phone','email','address']);
    $c6 = ensureTableCollation($pdo, 'system_config', ['config_key','config_value']);
    $c7 = ensureTableCollation($pdo, 'company_config', ['company_name','company_logo']);
    $c8 = ensureTableCollation($pdo, 'tenants', ['name','slug']);
    $c9 = ensureTableCollation($pdo, 'order_status_history', ['status','notes']);
    if ($pdo->inTransaction()) { $pdo->commit(); }
    $all = array_merge($c1, $c1b, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9);
    if (PHP_SAPI === 'cli') {
        echo $all ? ("OK\n" . implode("\n", $all) . "\n") : "OK\nSin cambios\n";
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $all ? ("OK\n" . implode("\n", $all) . "\n") : "OK\nSin cambios\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage();
}
?>
