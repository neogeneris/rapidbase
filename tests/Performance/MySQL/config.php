<?php
/**
 * Configuración centralizada para pruebas de rendimiento en MySQL/MariaDB
 * 
 * Edita este archivo para cambiar la configuración de conexión.
 * Todos los scripts en esta carpeta usarán esta configuración automáticamente.
 */

namespace Tests\Performance\MySQL;

class MySQLConfig {
    
    // ==================== CONFIGURACIÓN DE CONEXIÓN ====================
    // Edita estos valores según tu entorno
    
    public const DB_HOST = 'localhost';
    public const DB_PORT = 3306;
    public const DB_NAME = 'rapidbase_test';
    public const DB_USER = 'rapidbase_user';
    public const DB_PASS = 'rapidbase_pass';
    public const DB_CHARSET = 'utf8mb4';
    
    // ==================== CONFIGURACIÓN DE PRUEBAS ====================
    
    public const TEST_CUSTOMER_COUNT = 10000;
    public const TEST_CATEGORY_COUNT = 50;
    public const TEST_PRODUCT_COUNT = 5000;
    public const TEST_ORDER_COUNT = 5000;
    
    // ==================== MÉTODOS ESTÁTICOS ====================
    
    /**
     * Obtener DSN completo para PDO
     */
    public static function getDsn(): string {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME,
            self::DB_CHARSET
        );
    }
    
    /**
     * Obtener instancia PDO configurada
     */
    public static function getPDO(): \PDO {
        static $pdo = null;
        
        if ($pdo === null) {
            $dsn = self::getDsn();
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new \PDO($dsn, self::DB_USER, self::DB_PASS, $options);
        }
        
        return $pdo;
    }
    
    /**
     * Configurar RapidBase con la conexión MySQL
     */
    public static function setupRapidBase(): void {
        \RapidBase\Core\DB::setup(self::getDsn(), self::DB_USER, self::DB_PASS, 'main');
    }
    
    /**
     * Cerrar conexión
     */
    public static function close(): void {
        \RapidBase\Core\Conn::close('main');
    }
    
    /**
     * Limpiar tablas de prueba (DROP IF EXISTS)
     */
    public static function cleanup(): void {
        $pdo = self::getPDO();
        $tables = ['order_items', 'orders', 'products', 'categories', 'customers'];
        
        foreach ($tables as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
            } catch (\Exception $e) {
                // Ignorar errores al limpiar
            }
        }
    }
}

// ==================== FUNCIONES HELPER GLOBALES ====================

/**
 * Obtener instancia de configuración
 */
function mysql_config(): MySQLConfig {
    return new MySQLConfig();
}

/**
 * Obtener PDO configurado
 */
function mysql_pdo(): \PDO {
    return MySQLConfig::getPDO();
}

/**
 * Configurar RapidBase y retornar PDO
 */
function mysql_setup(): \PDO {
    MySQLConfig::setupRapidBase();
    return MySQLConfig::getPDO();
}

/**
 * Limpiar tablas de prueba
 */
function mysql_cleanup(): void {
    MySQLConfig::cleanup();
}
