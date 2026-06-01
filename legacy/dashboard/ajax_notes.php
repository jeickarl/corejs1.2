<?php
require_once '../config/session.php';
require_once '../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    // 1. Asegurar que la tabla existe (Auto-migración simple)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_notes (
            user_id INT PRIMARY KEY,
            content TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    if ($method === 'GET') {
        // Obtener nota
        $stmt = $pdo->prepare("SELECT content FROM user_notes WHERE user_id = ?");
        $stmt->execute([$userId]);
        $note = $stmt->fetchColumn();
        
        echo json_encode(['content' => $note !== false ? $note : '']);
        
    } elseif ($method === 'POST') {
        // Guardar nota
        $data = json_decode(file_get_contents('php://input'), true);
        $content = $data['content'] ?? '';
        
        $stmt = $pdo->prepare("
            INSERT INTO user_notes (user_id, content) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE content = VALUES(content)
        ");
        $stmt->execute([$userId, $content]);
        
        echo json_encode(['status' => 'success', 'timestamp' => date('H:i:s')]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
