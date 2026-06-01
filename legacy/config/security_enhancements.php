<?php
/**
 * Mejoras de Seguridad para Sistema Core
 * Implementa mejores prácticas de seguridad
 */

class SecurityEnhancements {
    
    /**
     * Configuración de seguridad
     */
    private static $config = [
        'max_login_attempts' => 5,
        'lockout_duration' => 900, // 15 minutos
        'session_timeout' => 1800, // 30 minutos
        'password_min_length' => 8,
        'require_special_chars' => true,
        'csrf_token_expiry' => 3600 // 1 hora
    ];
    
    /**
     * Generar token CSRF único
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Limpiar tokens expirados
        self::cleanExpiredTokens();
        
        // Reutilizar token existente si es válido (estrategia per-session)
        if (!empty($_SESSION['csrf_tokens'])) {
            // Obtener el último token generado
            $tokens = array_keys($_SESSION['csrf_tokens']);
            $last_token = end($tokens);
            // Verificar que no esté próximo a expirar (opcional, pero buena práctica)
            if ($_SESSION['csrf_tokens'][$last_token] > time() + 300) { // Si le quedan más de 5 minutos
                return $last_token;
            }
        }
        
        $token = bin2hex(random_bytes(32));
        $expiry = time() + self::$config['csrf_token_expiry'];
        
        $_SESSION['csrf_tokens'][$token] = $expiry;
        
        return $token;
    }
    
    /**
     * Verificar token CSRF
     */
    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        if (time() > $_SESSION['csrf_tokens'][$token]) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        // Token válido
        // NOTA: No eliminamos el token para permitir múltiples peticiones AJAX (ej: validaciones fallidas)
        // unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
    
    /**
     * Limpiar tokens CSRF expirados
     */
    private static function cleanExpiredTokens() {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        $current_time = time();
        foreach ($_SESSION['csrf_tokens'] as $token => $expiry) {
            if ($current_time > $expiry) {
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }
    }
    
    /**
     * Validar fortaleza de contraseña
     */
    public static function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < self::$config['password_min_length']) {
            $errors[] = "La contraseña debe tener al menos " . self::$config['password_min_length'] . " caracteres";
        }
        
        if (self::$config['require_special_chars']) {
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "La contraseña debe contener al menos una letra mayúscula";
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "La contraseña debe contener al menos una letra minúscula";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "La contraseña debe contener al menos un número";
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = "La contraseña debe contener al menos un carácter especial";
            }
        }
        
        return $errors;
    }
    
    /**
     * Control de intentos de login
     */
    public static function checkLoginAttempts($ip_address) {
        $pdo = self::pdoForLoginAttempts();
        if (!$pdo) {
            return true;
        }
        
        try {
            self::ensureLoginAttemptsTable($pdo);

            // Limpiar intentos antiguos
            $stmt = $pdo->prepare("
                DELETE FROM login_attempts 
                WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([self::$config['lockout_duration']]);
            
            // Contar intentos recientes
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempts 
                FROM login_attempts 
                WHERE ip_address = ? 
                AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ip_address, self::$config['lockout_duration']]);
            $result = $stmt->fetch();
            
            return $result['attempts'] < self::$config['max_login_attempts'];
            
        } catch (PDOException $e) {
            error_log("Error checking login attempts: " . $e->getMessage());
            return true; // Permitir login si hay error en la base de datos
        }
    }
    
    /**
     * Registrar intento de login
     */
    public static function logLoginAttempt($ip_address, $email, $success) {
        $pdo = self::pdoForLoginAttempts();
        if (!$pdo) {
            return;
        }
        
        try {
            self::ensureLoginAttemptsTable($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (ip_address, email, success, attempt_time)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$ip_address, $email, $success ? 1 : 0]);
        } catch (PDOException $e) {
            error_log("Error logging login attempt: " . $e->getMessage());
        }
    }

    private static function pdoForLoginAttempts(): ?PDO
    {
        if (function_exists('isPerDatabaseMode') && isPerDatabaseMode() && class_exists('DatabaseManager')) {
            try {
                return DatabaseManager::master();
            } catch (Throwable $e) {
                return null;
            }
        }
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        return null;
    }

    private static function ensureLoginAttemptsTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ip_address VARCHAR(45) NOT NULL,
                    email VARCHAR(255) NULL,
                    success TINYINT(1) NOT NULL DEFAULT 0,
                    attempt_time DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_login_attempts_ip_time (ip_address, attempt_time),
                    KEY idx_login_attempts_time (attempt_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
            ");
        } catch (Throwable $e) {
        }
    }
    
    /**
     * Sanitizar entrada de datos
     */
    public static function sanitizeInput($input, $type = 'string') {
        switch ($type) {
            case 'email':
                return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var(trim($input), FILTER_SANITIZE_URL);
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'string':
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Validar y limpiar archivos subidos
     */
    public static function validateUploadedFile($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 5242880) {
        $errors = [];
        
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = "Archivo no válido";
            return $errors;
        }
        
        // Verificar tamaño
        if ($file['size'] > $max_size) {
            $errors[] = "El archivo es demasiado grande. Máximo " . ($max_size / 1024 / 1024) . "MB";
        }
        
        // Verificar tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        
        $allowed_mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf'
        ];
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($mime_type, array_values($allowed_mimes)) || !in_array($file_extension, $allowed_types)) {
            $errors[] = "Tipo de archivo no permitido";
        }
        
        return $errors;
    }
    
    /**
     * Generar nombre seguro para archivo
     */
    public static function generateSecureFileName($original_name, $prefix = '') {
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $secure_name = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
        return $secure_name;
    }
    
    /**
     * Headers de seguridad HTTP
     */
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Solo en HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    /**
     * Log de actividades de seguridad
     */
    public static function logSecurityEvent($event_type, $details, $user_id = null) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO security_logs (event_type, details, user_id, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $event_type,
                json_encode($details),
                $user_id,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (PDOException $e) {
            error_log("Error logging security event: " . $e->getMessage());
        }
    }
}
?>
