<?php
require_once '../config/session.php';
require_once '../config/database.php';
if (!isValidSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

try {
    // Buscar borrador del usuario
    $stmt = $pdo->prepare("
        SELECT draft_data, updated_at 
        FROM invoice_drafts 
        WHERE user_id = ? 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($draft) {
        $draft_data = json_decode($draft['draft_data'], true);
        
        // Verificar que el borrador no sea muy antiguo (más de 7 días)
        $updated_at = new DateTime($draft['updated_at']);
        $seven_days_ago = new DateTime('-7 days');
        
        if ($updated_at < $seven_days_ago) {
            // Eliminar borrador antiguo
            $stmt = $pdo->prepare("DELETE FROM invoice_drafts WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            echo json_encode([
                'success' => false,
                'message' => 'Borrador expirado'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'draft' => $draft_data,
                'updated_at' => $draft['updated_at']
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No hay borrador disponible'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error al cargar borrador: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar borrador',
        'message' => $e->getMessage()
    ]);
}
?>
