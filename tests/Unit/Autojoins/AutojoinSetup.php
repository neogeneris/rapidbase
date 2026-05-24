<?php
/**
 * Setup para pruebas de Autojoins
 * Verifica que exista el schema_map.php y lo genera si es necesario
 */

namespace RapidBase\Tests\Autojoins;

use RapidBase\Meta\SchemaMapper;
use RapidBase\Core\SchemaMap;

class AutojoinSetup
{
    private static string $schemaMapPath = '';
    private static bool $initialized = false;

    /**
     * Inicializa el entorno para pruebas de autojoins
     * @param string|null $customSchemaMapPath Ruta personalizada al schema_map.php
     * @return bool True si el schema map está listo
     */
    public static function init(?string $customSchemaMapPath = null): bool
    {
        if (self::$initialized) {
            return true;
        }

        // Determinar ruta del schema_map.php
        self::$schemaMapPath = $customSchemaMapPath ?? __DIR__ . '/../../Performance/schema_map.php';

        // Verificar si existe el schema_map.php
        if (!file_exists(self::$schemaMapPath)) {
            echo "⚠️  schema_map.php no encontrado en: " . self::$schemaMapPath . "\n";
            echo "🔄 Generando schema_map.php...\n";
            
            if (!self::generateSchemaMap()) {
                echo "❌ Error: No se pudo generar el schema_map.php\n";
                return false;
            }
            
            echo "✅ schema_map.php generado exitosamente\n";
        } else {
            echo "✅ schema_map.php encontrado en: " . self::$schemaMapPath . "\n";
        }

        // Cargar el schema map en SchemaMap
        if (!self::loadSchemaMap()) {
            echo "❌ Error: No se pudo cargar el schema_map.php\n";
            return false;
        }

        self::$initialized = true;
        return true;
    }

    /**
     * Genera el schema_map.php usando SchemaMapper
     */
    private static function generateSchemaMap(): bool
    {
        try {
            // Obtener configuración de prueba (SQLite)
            $config = [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ];

            // Crear base de datos de prueba con tablas de ejemplo
            self::createTestDatabase($config['database']);

            // Instanciar SchemaMapper
            $mapper = new SchemaMapper($config);
            
            // Generar el schema map
            $schemaMap = $mapper->generate();
            
            // Guardar en archivo
            $output = var_export($schemaMap, true);
            $content = "<?php\n// Auto-generated schema map by Meta\\SchemaMapper\nreturn " . $output . ";\n";
            
            // Asegurar que el directorio exista
            $dir = dirname(self::$schemaMapPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            file_put_contents(self::$schemaMapPath, $content);
            
            return true;
        } catch (\Exception $e) {
            echo "Error generando schema_map: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Crea la base de datos de prueba con el schema necesario
     */
    private static function createTestDatabase(string $dbPath): void
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Crear tablas de ejemplo para tests de joins
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS post_categories (
                id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS post_tags (
                id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (tag_id) REFERENCES tags(id)
            )
        ");

        // Insertar datos de prueba
        $pdo->exec("INSERT OR IGNORE INTO users (id, name, email) VALUES (1, 'John Doe', 'john@example.com')");
        $pdo->exec("INSERT OR IGNORE INTO users (id, name, email) VALUES (2, 'Jane Smith', 'jane@example.com')");
        
        $pdo->exec("INSERT OR IGNORE INTO posts (id, user_id, title, content) VALUES (1, 1, 'First Post', 'Content 1')");
        $pdo->exec("INSERT OR IGNORE INTO posts (id, user_id, title, content) VALUES (2, 1, 'Second Post', 'Content 2')");
        $pdo->exec("INSERT OR IGNORE INTO posts (id, user_id, title, content) VALUES (3, 2, 'Third Post', 'Content 3')");
        
        $pdo->exec("INSERT OR IGNORE INTO comments (id, post_id, user_id, content) VALUES (1, 1, 2, 'Great post!')");
        $pdo->exec("INSERT OR IGNORE INTO comments (id, post_id, user_id, content) VALUES (2, 1, 1, 'Thanks!')");
        
        $pdo->exec("INSERT OR IGNORE INTO categories (id, name) VALUES (1, 'Technology')");
        $pdo->exec("INSERT OR IGNORE INTO categories (id, name) VALUES (2, 'Programming')");
        
        $pdo->exec("INSERT OR IGNORE INTO tags (id, name) VALUES (1, 'PHP')");
        $pdo->exec("INSERT OR IGNORE INTO tags (id, name) VALUES (2, 'Database')");
        
        $pdo->exec("INSERT OR IGNORE INTO post_categories (post_id, category_id) VALUES (1, 1)");
        $pdo->exec("INSERT OR IGNORE INTO post_categories (post_id, category_id) VALUES (1, 2)");
        
        $pdo->exec("INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (1, 1)");
        $pdo->exec("INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (1, 2)");
    }

    /**
     * Carga el schema_map.php en SchemaMap
     */
    private static function loadSchemaMap(): bool
    {
        try {
            SchemaMap::loadFromFile(self::$schemaMapPath);
            return true;
        } catch (\Exception $e) {
            echo "Error cargando schema_map: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Obtiene la ruta al schema_map.php
     */
    public static function getSchemaMapPath(): string
    {
        return self::$schemaMapPath;
    }

    /**
     * Reinicia el estado inicializado (útil para tests)
     */
    public static function reset(): void
    {
        self::$initialized = false;
        self::$schemaMapPath = '';
    }
}
