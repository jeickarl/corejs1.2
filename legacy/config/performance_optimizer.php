<?php
/**
 * Optimizador de Rendimiento para Sistema Core
 * Implementa técnicas de optimización y caché
 */

class PerformanceOptimizer {
    
    private static $cache_dir = '../cache/';
    private static $cache_enabled = true;
    private static $cache_ttl = 3600; // 1 hora por defecto
    
    /**
     * Inicializar sistema de caché
     */
    public static function init() {
        if (!is_dir(self::$cache_dir)) {
            mkdir(self::$cache_dir, 0755, true);
        }
        
        // Limpiar caché expirado
        self::cleanExpiredCache();
    }
    
    /**
     * Obtener datos del caché
     */
    public static function getCache($key) {
        if (!self::$cache_enabled) {
            return false;
        }
        
        $cache_file = self::$cache_dir . md5($key) . '.cache';
        
        if (!file_exists($cache_file)) {
            return false;
        }
        
        $data = file_get_contents($cache_file);
        $cached = json_decode($data, true);
        
        if (!$cached || time() > $cached['expires']) {
            unlink($cache_file);
            return false;
        }
        
        return $cached['data'];
    }
    
    /**
     * Guardar datos en caché
     */
    public static function setCache($key, $data, $ttl = null) {
        if (!self::$cache_enabled) {
            return false;
        }
        
        $ttl = $ttl ?: self::$cache_ttl;
        $cache_file = self::$cache_dir . md5($key) . '.cache';
        
        $cached = [
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        return file_put_contents($cache_file, json_encode($cached));
    }
    
    /**
     * Eliminar caché específico
     */
    public static function deleteCache($key) {
        $cache_file = self::$cache_dir . md5($key) . '.cache';
        if (file_exists($cache_file)) {
            return unlink($cache_file);
        }
        return false;
    }
    
    /**
     * Limpiar todo el caché
     */
    public static function clearCache() {
        $files = glob(self::$cache_dir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
    
    /**
     * Limpiar caché expirado
     */
    private static function cleanExpiredCache() {
        $files = glob(self::$cache_dir . '*.cache');
        foreach ($files as $file) {
            $data = file_get_contents($file);
            $cached = json_decode($data, true);
            
            if (!$cached || time() > $cached['expires']) {
                unlink($file);
            }
        }
    }
    
    /**
     * Optimizar consultas de base de datos
     */
    public static function optimizeQuery($sql, $params = []) {
        global $pdo;
        
        // Usar prepared statements
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters con tipos correctos
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        return $stmt;
    }
    
    /**
     * Paginación optimizada
     */
    public static function getPaginatedResults($sql, $params = [], $page = 1, $per_page = 20) {
        global $pdo;
        
        $offset = ($page - 1) * $per_page;
        
        // Consulta para contar total
        $count_sql = "SELECT COUNT(*) as total FROM ($sql) as count_table";
        $count_stmt = self::optimizeQuery($count_sql, $params);
        $count_stmt->execute();
        $total = $count_stmt->fetch()['total'];
        
        // Consulta principal con LIMIT
        $data_sql = $sql . " LIMIT $per_page OFFSET $offset";
        $data_stmt = self::optimizeQuery($data_sql, $params);
        $data_stmt->execute();
        $data = $data_stmt->fetchAll();
        
        return [
            'data' => $data,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page,
            'per_page' => $per_page
        ];
    }
    
    /**
     * Compresión de respuesta
     */
    public static function enableCompression() {
        if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 1);
        }
    }
    
    /**
     * Headers de caché para archivos estáticos
     */
    public static function setStaticCacheHeaders($file_path, $max_age = 86400) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        $content_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml'
        ];
        
        if (isset($content_types[$extension])) {
            header('Content-Type: ' . $content_types[$extension]);
            header('Cache-Control: public, max-age=' . $max_age);
            header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + $max_age));
        }
    }
    
    /**
     * Minificar CSS
     */
    public static function minifyCSS($css) {
        // Remover comentarios
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remover espacios en blanco innecesarios
        $css = preg_replace('/\s+/', ' ', $css);
        $css = str_replace(['; ', ' {', '{ ', ' }', '} ', ': ', ' :'], [';', '{', '{', '}', '}', ':', ':'], $css);
        
        return trim($css);
    }
    
    /**
     * Minificar JavaScript
     */
    public static function minifyJS($js) {
        // Remover comentarios de una línea
        $js = preg_replace('/\/\/.*$/m', '', $js);
        
        // Remover comentarios multilínea
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);
        
        // Remover espacios en blanco innecesarios
        $js = preg_replace('/\s+/', ' ', $js);
        
        return trim($js);
    }
    
    /**
     * Optimizar imágenes
     */
    public static function optimizeImage($source_path, $destination_path, $quality = 85) {
        if (!extension_loaded('gd')) {
            return false;
        }
        
        $info = getimagesize($source_path);
        if (!$info) {
            return false;
        }
        
        $width = $info[0];
        $height = $info[1];
        $type = $info[2];
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($source_path);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($image, $destination_path, $quality);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($image, $destination_path, 9);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($image, $destination_path);
                break;
        }
        
        imagedestroy($image);
        return $result;
    }
    
    /**
     * Lazy loading para imágenes
     */
    public static function generateLazyLoadHTML($src, $alt = '', $class = '') {
        return sprintf(
            '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                   data-src="%s" 
                   alt="%s" 
                   class="lazy %s" 
                   loading="lazy">',
            htmlspecialchars($src),
            htmlspecialchars($alt),
            htmlspecialchars($class)
        );
    }
    
    /**
     * Monitoreo de rendimiento
     */
    public static function startTimer() {
        return microtime(true);
    }
    
    public static function endTimer($start_time) {
        return microtime(true) - $start_time;
    }
    
    /**
     * Log de rendimiento
     */
    public static function logPerformance($operation, $duration, $details = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'operation' => $operation,
            'duration' => round($duration * 1000, 2), // en milisegundos
            'details' => $details
        ];
        
        $log_file = self::$cache_dir . 'performance.log';
        file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Optimizar base de datos
     */
    public static function optimizeDatabase() {
        global $pdo;
        
        try {
            // Analizar tablas
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $pdo->exec("ANALYZE TABLE `$table`");
                $pdo->exec("OPTIMIZE TABLE `$table`");
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Error optimizing database: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Configurar índices recomendados
     */
    public static function createRecommendedIndexes() {
        global $pdo;
        
        $indexes = [
            'users' => [
                'idx_email' => 'CREATE INDEX idx_email ON users(email)',
                'idx_role' => 'CREATE INDEX idx_role ON users(role)',
                'idx_active' => 'CREATE INDEX idx_active ON users(active)'
            ],
            'clients' => [
                'idx_email' => 'CREATE INDEX idx_email ON clients(email)',
                'idx_phone' => 'CREATE INDEX idx_phone ON clients(phone)',
                'idx_created' => 'CREATE INDEX idx_created ON clients(created_at)'
            ],
            'work_orders' => [
                'idx_status' => 'CREATE INDEX idx_status ON work_orders(status)',
                'idx_client' => 'CREATE INDEX idx_client ON work_orders(client_id)',
                'idx_created' => 'CREATE INDEX idx_created ON work_orders(created_at)',
                'idx_priority' => 'CREATE INDEX idx_priority ON work_orders(priority)'
            ],
            'activity_logs' => [
                'idx_user_action' => 'CREATE INDEX idx_user_action ON activity_logs(user_id, action)',
                'idx_created' => 'CREATE INDEX idx_created ON activity_logs(created_at)'
            ]
        ];
        
        foreach ($indexes as $table => $table_indexes) {
            foreach ($table_indexes as $index_name => $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // El índice ya existe o hay otro error
                    error_log("Error creating index $index_name: " . $e->getMessage());
                }
            }
        }
    }
}
?>
