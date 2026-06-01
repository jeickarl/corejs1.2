<?php
require_once '../config/session.php';
require_once '../config/database.php';
header('Content-Type: application/json; charset=UTF-8');
$pdo = db();
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['unread' => 0, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        meta TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NULL,
        CONSTRAINT fk_un_n2 FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $isAdmin = ((string)($_SESSION['user_role'] ?? '')) === 'admin';
    if ($isAdmin) {
        $now = time();
        $lastScan = (int)($_SESSION['last_overdue_invoice_scan'] ?? 0);
        if ($lastScan <= 0 || ($now - $lastScan) >= 300) {
            $_SESSION['last_overdue_invoice_scan'] = $now;

            $tableHasColumn = function(PDO $pdo, string $table, string $column): bool {
                try {
                    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                    $st->execute([$column]);
                    return $st->fetchColumn() !== false;
                } catch (Throwable $e) {
                    return false;
                }
            };

            if ($tableHasColumn($pdo, 'invoices', 'overdue_notified_at') === false) {
                try {
                    $pdo->exec("ALTER TABLE invoices ADD COLUMN overdue_notified_at DATETIME NULL DEFAULT NULL");
                } catch (Throwable $e) {
                }
            }

            if ($tableHasColumn($pdo, 'invoices', 'overdue_notified_at')) {
                $hasTenantInvoices = $tableHasColumn($pdo, 'invoices', 'tenant_id');
                $sqlOverdue = "SELECT id, invoice_number, due_date, pending_amount
                              FROM invoices
                              WHERE payment_status IN ('pending','partial')
                                AND pending_amount > 0
                                AND due_date IS NOT NULL AND due_date <> ''
                                AND due_date < CURDATE()
                                AND (status IS NULL OR status <> 'cancelled')
                                AND overdue_notified_at IS NULL"
                              . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : "")
                              . " ORDER BY due_date ASC LIMIT 25";
                $stOverdue = $pdo->prepare($sqlOverdue);
                $stOverdue->execute((!$perDatabase && $hasTenantInvoices) ? [$tenantValue] : []);
                $overdueInvoices = $stOverdue->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (!empty($overdueInvoices)) {
                    $hasTenantUsers = $tableHasColumn($pdo, 'users', 'tenant_id');
                    $sqlAdmins = "SELECT id FROM users WHERE role = 'admin' AND active = 1"
                        . ((!$perDatabase && $hasTenantUsers) ? " AND tenant_id = ?" : "");
                    $stAdmins = $pdo->prepare($sqlAdmins);
                    $stAdmins->execute((!$perDatabase && $hasTenantUsers) ? [$tenantValue] : []);
                    $adminIds = array_map('intval', $stAdmins->fetchAll(PDO::FETCH_COLUMN) ?: []);

                    if (!empty($adminIds)) {
                        $insNotif = $pdo->prepare("INSERT INTO notifications (tenant_id, type, title, body, meta, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                        $insUn = $pdo->prepare("INSERT INTO user_notifications (notification_id, user_id) VALUES (?, ?)");
                        $updInv = $pdo->prepare("UPDATE invoices SET overdue_notified_at = NOW() WHERE id = ?" . ((!$perDatabase && $hasTenantInvoices) ? " AND tenant_id = ?" : ""));

                        foreach ($overdueInvoices as $inv) {
                            $invoiceId = (int)($inv['id'] ?? 0);
                            if ($invoiceId <= 0) continue;
                            $invoiceNumber = (string)($inv['invoice_number'] ?? '');
                            $dueDate = (string)($inv['due_date'] ?? '');
                            $pendingAmount = (float)($inv['pending_amount'] ?? 0);

                            $title = 'Venta vencida';
                            $body = 'Factura ' . $invoiceNumber . ' venció el ' . $dueDate . '. Saldo pendiente: ' . rtrim(rtrim(number_format($pendingAmount, 2, '.', ''), '0'), '.');
                            $meta = json_encode([
                                'invoice_id' => $invoiceId,
                                'invoice_number' => $invoiceNumber,
                                'due_date' => $dueDate,
                                'pending_amount' => $pendingAmount
                            ], JSON_UNESCAPED_UNICODE);

                            $insNotif->execute([$tenantValue, 'invoice_overdue', $title, $body, $meta]);
                            $notificationId = (int)$pdo->lastInsertId();
                            if ($notificationId > 0) {
                                foreach ($adminIds as $aid) {
                                    $insUn->execute([$notificationId, (int)$aid]);
                                }
                            }
                            $updInv->execute((!$perDatabase && $hasTenantInvoices) ? [$invoiceId, $tenantValue] : [$invoiceId]);
                        }
                    }
                }
            }
        }
    }

    $sql = "SELECT n.id, n.title, n.body, n.meta, n.created_at, un.read_at
            FROM notifications n
            INNER JOIN user_notifications un ON un.notification_id = n.id
            WHERE un.user_id = ?" . (!$perDatabase ? " AND n.tenant_id = ?" : "") . "
            ORDER BY n.created_at DESC LIMIT 10";
    $st = $pdo->prepare($sql);
    $params = [$user_id];
    if (!$perDatabase) { $params[] = $tenantValue; }
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    $unread = 0;
    foreach ($rows as $r) {
        $read = !empty($r['read_at']);
        if (!$read) $unread++;
        $items[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'body' => (string)($r['body'] ?? ''),
            'created_at' => (string)$r['created_at'],
            'read' => $read,
            'meta' => $r['meta'] ? @json_decode($r['meta'], true) : null
        ];
    }
    echo json_encode(['unread' => $unread, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['unread'=>0,'items'=>[]]);
}
