<?php
require_once __DIR__ . '/../config/database.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS `activity_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) DEFAULT NULL,
      `action` varchar(255) NOT NULL,
      `table_name` varchar(255) DEFAULT NULL,
      `record_id` int(11) DEFAULT NULL,
      `old_values` text DEFAULT NULL,
      `new_values` text DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `user_agent` varchar(255) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;";

    $pdo->exec($sql);
    echo "Tabla activity_logs creada o ya existe. <br>";
    echo "Por favor intente acceder al módulo nuevamente.";
} catch (PDOException $e) {
    echo "Error al crear la tabla: " . $e->getMessage();
}
?>
