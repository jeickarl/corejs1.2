<?php
// Configuración de memoria y tiempo para bases de datos grandes
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 600);

require_once 'auth.php';
require_once __DIR__ . '/../config/database.php';

// Nombre del archivo de descarga
$db_name = $current_db_name ?? 'core_backup';
$filename = $db_name . '_' . date('Y-m-d_H-i-s') . '.sql';

// Headers para forzar la descarga
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Función para escapar valores
function escape_sql_value($value) {
    if ($value === null) {
        return "NULL";
    }
    // Escapar comillas simples y barras invertidas
    $value = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    // Reemplazar saltos de línea con sus representaciones escapadas
    $value = str_replace(["\r", "\n"], ['\r', '\n'], $value);
    return "'" . $value . "'";
}

// Iniciar salida
echo "-- Backup generado por CORE System\n";
echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "-- Base de Datos: " . $db_name . "\n";
echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
echo "SET time_zone = \"+00:00\";\n\n";
echo "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
echo "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
echo "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
echo "/*!40101 SET NAMES utf8mb4 */;\n\n";

// Obtener todas las tablas
$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    echo "\n\n-- --------------------------------------------------------\n\n";
    echo "-- Estructura de tabla para la tabla `$table`\n\n";
    
    // Drop table si existe
    echo "DROP TABLE IF EXISTS `$table`;\n";
    
    // Create table
    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo $row[1] . ";\n\n";
    
    // Datos
    echo "-- Volcado de datos para la tabla `$table`\n\n";
    
    // Contar filas para saber si vale la pena hacer el select
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rows)) {
            // Insertar en bloques para no hacer una query kilométrica
            $batchSize = 100;
            $totalRows = count($rows);
            $currentBatch = 0;
            
            // Obtener nombres de columnas
            $columns = array_keys($rows[0]);
            $colNames = "`" . implode("`, `", $columns) . "`";
            
            for ($i = 0; $i < $totalRows; $i += $batchSize) {
                $batch = array_slice($rows, $i, $batchSize);
                $valuesList = [];
                
                foreach ($batch as $row) {
                    $rowValues = [];
                    foreach ($row as $val) {
                        $rowValues[] = escape_sql_value($val);
                    }
                    $valuesList[] = "(" . implode(", ", $rowValues) . ")";
                }
                
                echo "INSERT INTO `$table` ($colNames) VALUES\n" . implode(",\n", $valuesList) . ";\n";
            }
        }
    }
}

echo "\n\n/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
echo "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
echo "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";

exit;
?>