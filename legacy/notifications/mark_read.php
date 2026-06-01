<?php
require_once '../config/session.php';
require_once '../config/database.php';
header('Content-Type: application/json; charset=UTF-8');
$pdo = db();
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}
$tenant_id = getCurrentTenantId();
$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
$tenantValue = $perDatabase ? 1 : (int)$tenant_id;
$ids = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['ids']) && is_array($data['ids'])) {
        foreach ($data['ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) $ids[] = $id;
        }
    }
}
if (empty($ids)) { echo json_encode(['ok'=>true]); exit; }
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
        CONSTRAINT fk_un_n3 FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $params[] = $user_id;
    $sql = "UPDATE user_notifications un
            INNER JOIN notifications n ON n.id = un.notification_id
            SET un.read_at = NOW()
            WHERE un.notification_id IN ($place) AND un.user_id = ?" . (!$perDatabase ? " AND n.tenant_id = ?" : "");
    if (!$perDatabase) { $params[] = $tenantValue; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false]);
}
