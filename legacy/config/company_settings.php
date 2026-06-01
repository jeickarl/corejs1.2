<?php
/**
 * Funciones para manejar configuraciones de empresa
 */

require_once 'database.php';

class CompanySettings {
    private static $config = null;
    
    /**
     * Cargar configuraciones desde la base de datos
     */
    public static function loadConfig() {
        if (self::$config === null) {
            $pdo = db();
            self::$config = [];
            
            try {
                $tenant_id = null;
                if (function_exists('getCurrentTenantId')) {
                    $tenant_id = getCurrentTenantId();
                } elseif (isset($_SESSION['tenant_id'])) {
                    $tenant_id = $_SESSION['tenant_id'];
                }

                if ($tenant_id) {
                    $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
                    $tenantValue = $perDatabase ? 1 : (int)$tenant_id;
                    $hasTenantSystem = function_exists('hasTenantColumnCached') ? hasTenantColumnCached($pdo, 'system_config') : false;
                    if ($hasTenantSystem && !$perDatabase) {
                        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE tenant_id = ?");
                        $stmt->execute([$tenantValue]);
                    } else {
                        $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config");
                        $stmt->execute([]);
                    }
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        self::$config[$row['config_key']] = $row['config_value'];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error loading company settings: " . $e->getMessage());
                // Valores por defecto
                self::$config = [
                    'currency' => 'COP',
                    'currency_symbol' => '$',
                    'currency_name' => 'Peso Colombiano',
                    'phone_prefix' => '+57',
                    'phone_country' => 'Colombia',
                    'time_format' => '12',
                    'date_format' => 'd/m/Y',
                    'datetime_format' => 'd/m/Y H:i A'
                ];
            }
        }
        
        return self::$config;
    }
    
    /**
     * Obtener valor de configuración
     */
    public static function get($key, $default = null) {
        $config = self::loadConfig();
        return $config[$key] ?? $default;
    }
    
    /**
     * Obtener configuración de moneda
     */
    public static function getCurrency() {
        return [
            'code' => self::get('currency', 'COP'),
            'symbol' => self::get('currency_symbol', '$'),
            'name' => self::get('currency_name', 'Peso Colombiano'),
            'decimals' => (int)self::get('currency_decimals', 0)
        ];
    }
    
    public static function getTaxConfig() {
        $enabled = self::get('tax_enabled', '0');
        return [
            'enabled' => ($enabled === '1'),
            'name' => self::get('tax_name', 'IVA'),
            'rate' => (float)self::get('tax_rate', 19)
        ];
    }
    
    /**
     * Obtener configuración de teléfono
     */
    public static function getPhoneConfig() {
        return [
            'prefix' => self::get('phone_prefix', '+57'),
            'country' => self::get('phone_country', 'Colombia')
        ];
    }
    
    /**
     * Obtener configuración de formato de tiempo
     */
    public static function getTimeConfig() {
        return [
            'format' => self::get('time_format', '12'),
            'date_format' => self::get('date_format', 'd/m/Y'),
            'datetime_format' => self::get('datetime_format', 'd/m/Y H:i A')
        ];
    }
    
    /**
     * Formatear número de teléfono (solo limpiar, sin agregar indicativo)
     */
    public static function formatPhone($phone) {
        // Limpiar el número (quitar espacios, guiones, etc.)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        // Formatear como XXX XXX XXXX
        if (strlen($cleanPhone) >= 10) {
            return substr($cleanPhone, 0, 3) . ' ' . substr($cleanPhone, 3, 3) . ' ' . substr($cleanPhone, 6);
        } elseif (strlen($cleanPhone) >= 7) {
            return substr($cleanPhone, 0, 3) . ' ' . substr($cleanPhone, 3);
        }
        
        return $cleanPhone;
    }
    
    /**
     * Obtener número de teléfono completo con indicativo
     */
    public static function getFullPhone($phone) {
        $phoneConfig = self::getPhoneConfig();
        $prefix = $phoneConfig['prefix'];
        $formattedPhone = self::formatPhone($phone);
        
        return $prefix . ' ' . $formattedPhone;
    }
    
    /**
     * Formatear fecha según configuración
     */
    public static function formatDate($date, $includeTime = false) {
        $timeConfig = self::getTimeConfig();
        
        if (!$date) {
            return '';
        }
        
        $timestamp = is_string($date) ? strtotime($date) : $date;
        
        if ($includeTime) {
            $format = $timeConfig['format'] === '24' ? 
                str_replace(' A', '', $timeConfig['datetime_format']) : 
                $timeConfig['datetime_format'];
        } else {
            $format = $timeConfig['date_format'];
        }
        
        return date($format, $timestamp);
    }
    
    /**
     * Formatear solo la hora según configuración
     */
    public static function formatTime($date) {
        $timeConfig = self::getTimeConfig();
        
        if (!$date) {
            return '';
        }
        
        $timestamp = is_string($date) ? strtotime($date) : $date;
        
        // Usar formato 12h o 24h según configuración
        $format = $timeConfig['format'] === '24' ? 'H:i' : 'g:i A';
        
        return date($format, $timestamp);
    }
    
    /**
     * Formatear moneda
     */
    public static function formatCurrency($amount, $showCode = false) {
        $currency = self::getCurrency();
        
        if ($amount === null || $amount === '') {
            return '';
        }
        
        // Formatear: sin decimales, coma para miles (según preferencia usuario)
        $formatted = $currency['symbol'] . ' ' . number_format($amount, 0, '.', ',');
        
        if ($showCode) {
            $formatted .= ' ' . $currency['code'];
        }
        
        return $formatted;
    }
    
    /**
     * Validar número de teléfono
     */
    public static function validatePhone($phone) {
        $cleanPhone = preg_replace('/[^0-9+()]/', '', $phone);
        
        // Debe tener al menos 7 dígitos (sin contar el indicativo)
        $digits = preg_replace('/[^0-9]/', '', $cleanPhone);
        
        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }
    
    /**
     * Obtener lista de países comunes con indicativos
     */
    public static function getCountryList() {
        return [
            '(57)' => 'Colombia',
            '(1)' => 'Estados Unidos/Canadá',
            '(52)' => 'México',
            '(34)' => 'España',
            '(39)' => 'Italia',
            '(33)' => 'Francia',
            '(49)' => 'Alemania',
            '(44)' => 'Reino Unido',
            '(55)' => 'Brasil',
            '(54)' => 'Argentina',
            '(56)' => 'Chile',
            '(51)' => 'Perú',
            '(58)' => 'Venezuela',
            '(593)' => 'Ecuador',
            '(595)' => 'Paraguay',
            '(598)' => 'Uruguay',
            '(591)' => 'Bolivia'
        ];
    }
}
?>
