<?php
/**
 * Configuración de PostgreSQL para Tests de Rendimiento
 * 
 * Centraliza todos los parámetros de conexión y configuraciones comunes
 * para facilitar la ejecución de las pruebas.
 * 
 * Uso:
 *   require_once __DIR__ . '/config.php';
 *   
 *   // Acceder a la configuración
 *   $config = PGConfig::get();
 *   echo $config['dsn'];
 *   
 *   // Obtener conexión PDO
 *   $pdo = PGConfig::getPDO();
 *   
 *   // Setup de RapidBase
 *   PGConfig::setupRapidBase();
 */

namespace Tests\Performance\PostgreSQL;

class PGConfig {
    
    /**
     * Parámetros de conexión a la base de datos
     */
    public const DB_HOST = 'localhost';
    public const DB_PORT = 5432;
    public const DB_NAME = 'rapidbase_test';
    public const DB_USER = 'rapidbase_user';
    public const DB_PASS = 'rapidbase_pass';
    
    /**
     * Opciones adicionales
     */
    public const CLEANUP_BEFORE_TESTS = true;
    public const VERBOSE_OUTPUT = true;
    
    /**
     * @var \PDO|null Instancia singleton de PDO
     */
    private static ?\PDO $pdoInstance = null;
    
    /**
     * Obtiene el array de configuración completo
     * 
     * @return array Array con todos los parámetros de configuración
     */
    public static function get(): array {
        return [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'dbname' => self::DB_NAME,
            'user' => self::DB_USER,
            'pass' => self::DB_PASS,
            'dsn' => self::getDSN(),
            'cleanup_before' => self::CLEANUP_BEFORE_TESTS,
            'verbose' => self::VERBOSE_OUTPUT,
        ];
    }
    
    /**
     * Construye y retorna el DSN para PostgreSQL
     * 
     * @return string DSN completo para conexión PDO
     */
    public static function getDSN(): string {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME
        );
    }
    
    /**
     * Obtiene una instancia de PDO conectada a la base de datos
     * 
     * Utiliza patrón singleton para reutilizar la conexión.
     * 
     * @param bool $forceNew Forzar creación de nueva conexión (default: false)
     * @return \PDO Instancia de PDO conectada
     * @throws \PDOException Si falla la conexión
     */
    public static function getPDO(bool $forceNew = false): \PDO {
        if (self::$pdoInstance === null || $forceNew) {
            $dsn = self::getDSN();
            
            self::$pdoInstance = new \PDO(
                $dsn,
                self::DB_USER,
                self::DB_PASS,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        
        return self::$pdoInstance;
    }
    
    /**
     * Configura RapidBase con los parámetros de PostgreSQL
     * 
     * @return void
     * @throws \Exception Si falla la configuración
     */
    public static function setupRapidBase(): void {
        if (!class_exists(\RapidBase\Core\DB::class)) {
            throw new \Exception('RapidBase no está disponible. Verifica el autoload.');
        }
        
        \RapidBase\Core\DB::setup(
            self::getDSN(),
            self::DB_USER,
            self::DB_PASS,
            'main'
        );
    }
    
    /**
     * Obtiene la conexión de RapidBase
     * 
     * @return \PDO Instancia de PDO de RapidBase
     */
    public static function getRapidBaseConnection(): \PDO {
        self::setupRapidBase();
        return \RapidBase\Core\DB::getConnection();
    }
    
    /**
     * Limpia todas las tablas de test de la base de datos
     * 
     * @param \PDO|null $pdo Instancia de PDO (usa la singleton si es null)
     * @return void
     */
    public static function cleanup(\PDO $pdo = null): void {
        $pdo = $pdo ?? self::getPDO();
        
        $tables = [
            'post_tags', 'post_categories', 'comments', 'tags', 
            'posts', 'categories', 'users', 'products', 'orders'
        ];
        
        foreach ($tables as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$table} CASCADE");
            } catch (\PDOException $e) {
                // Ignorar errores de tablas que no existen
            }
        }
    }
    
    /**
     * Imprime información de configuración (útil para debug)
     * 
     * @return void
     */
    public static function printInfo(): void {
        echo "=== Configuración PostgreSQL ===\n";
        echo "Host: " . self::DB_HOST . "\n";
        echo "Port: " . self::DB_PORT . "\n";
        echo "Database: " . self::DB_NAME . "\n";
        echo "User: " . self::DB_USER . "\n";
        echo "DSN: " . self::getDSN() . "\n";
        echo "==============================\n\n";
    }
}

// Funciones helper globales para uso rápido (opcional)

/**
 * Retorna el array de configuración
 * @return array
 */
function pg_config(): array {
    return PGConfig::get();
}

/**
 * Obtiene una instancia de PDO conectada
 * @param bool $forceNew
 * @return \PDO
 */
function pg_pdo(bool $forceNew = false): \PDO {
    return PGConfig::getPDO($forceNew);
}

/**
 * Configura RapidBase y retorna la conexión
 * @return \PDO
 */
function pg_rapidbase(): \PDO {
    PGConfig::setupRapidBase();
    return \RapidBase\Core\DB::getConnection();
}

/**
 * Limpia las tablas de test
 * @param \PDO|null $pdo
 * @return void
 */
function pg_cleanup(\PDO $pdo = null): void {
    PGConfig::cleanup($pdo);
}
