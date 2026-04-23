<?php
/**
 * CLI Tool for RapidBase Users CRUD Example
 * 
 * Commands:
 *  php cli.php make:schema   - Generate/Update schema_map.php
 *  php cli.php users:list    - List users (pagination supported)
 *  php cli.php status        - Check system status
 *  php cli.php db:seed       - Reset database with sample data
 */

require_once __DIR__ . '/config.php';

use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

// Colores para consola
$COLORS = [
    'reset' => "\033[0m",
    'bold' => "\033[1m",
    'red' => "\033[31m",
    'green' => "\033[32m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'cyan' => "\033[36m",
];

function printLine($text, $color = 'reset') {
    global $COLORS;
    echo $COLORS[$color] . $text . $COLORS['reset'] . PHP_EOL;
}

function printHeader($title) {
    echo PHP_EOL;
    printLine(str_repeat('=', 60), 'cyan');
    printLine(" $title", 'bold');
    printLine(str_repeat('=', 60), 'cyan');
    echo PHP_EOL;
}

// Comandos disponibles
$commands = [
    'make:schema' => 'Generate or update schema_map.php',
    'users:list' => 'List all users with pagination',
    'status' => 'Check system status and connections',
    'db:seed' => 'Reset database with sample data',
];

if ($argc < 2) {
    printHeader("RapidBase CLI Tools");
    printLine("Usage: php cli.php <command> [options]", 'bold');
    printLine("\nAvailable commands:", 'yellow');
    foreach ($commands as $cmd => $desc) {
        printLine("  $cmd", 'cyan');
        echo "    $desc" . PHP_EOL;
    }
    echo PHP_EOL;
    exit(0);
}

$command = $argv[1];
$options = [];

// Parsear opciones (--key=value)
for ($i = 2; $i < $argc; $i++) {
    if (strpos($argv[$i], '--') === 0) {
        $parts = explode('=', substr($argv[$i], 2));
        $key = $parts[0];
        $value = $parts[1] ?? true;
        $options[$key] = $value;
    }
}

try {
    switch ($command) {
        case 'make:schema':
            printHeader("Generating Schema Map");
            
            $schemaFile = __DIR__ . '/schema_map.php';
            
            printLine("Scanning database structure...", 'yellow');
            $mapper = new SchemaMapper();
            $map = $mapper->generateMap();
            
            if (empty($map['tables'])) {
                printLine("❌ No tables found in database!", 'red');
                exit(1);
            }
            
            // Guardar archivo
            $export = var_export($map, true);
            $content = "<?php\n// Auto-generated schema map by Meta\\SchemaMapper\nreturn $export;\n";
            
            if (file_put_contents($schemaFile, $content)) {
                printLine("✅ Schema map generated successfully!", 'green');
                printLine("📄 File: $schemaFile", 'blue');
                
                $tableCount = count($map['tables']);
                printLine("📊 Tables found: $tableCount", 'bold');
                
                foreach ($map['tables'] as $tableName => $columns) {
                    $colCount = count($columns);
                    printLine("   - $tableName ($colCount columns)", 'cyan');
                }
            } else {
                printLine("❌ Error writing schema file!", 'red');
                exit(1);
            }
            break;

        case 'users:list':
            printHeader("Users List");
            
            $page = isset($options['page']) ? (int)$options['page'] : 1;
            $limit = isset($options['limit']) ? (int)$options['limit'] : 10;
            
            try {
                $result = DB::grid('users', '*', [], [], $page, $limit);
                
                if (empty($result['data'])) {
                    printLine("No users found.", 'yellow');
                    exit(0);
                }
                
                // Obtener nombres de columnas del head
                $columns = $result['head']['columns'] ?? ['id', 'name', 'email', 'role', 'created_at'];
                $titles = $result['head']['titles'] ?? array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $columns);
                
                // Imprimir cabecera
                printf("%-5s %-20s %-30s %-15s %-20s\n", ...$titles);
                printLine(str_repeat('-', 95), 'cyan');
                
                // Imprimir filas
                foreach ($result['data'] as $row) {
                    // $row viene como array numérico [id, name, email, role, created_at]
                    printf("%-5s %-20s %-30s %-15s %-20s\n", 
                        $row[0], // id
                        substr($row[1], 0, 18), // name
                        substr($row[2], 0, 28), // email
                        $row[3], // role
                        $row[4] // created_at
                    );
                }
                
                printLine(str_repeat('-', 95), 'cyan');
                printLine("Page {$result['page']['current']} of {$result['page']['total']} (Total: {$result['page']['records']} records)", 'bold');
                
            } catch (Exception $e) {
                printLine("❌ Error fetching users: " . $e->getMessage(), 'red');
                exit(1);
            }
            break;

        case 'status':
            printHeader("System Status");
            
            // Verificar conexión
            try {
                $pdo = DB::getInstance();
                printLine("✅ Database connection: OK", 'green');
                
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                printLine("   Driver: " . strtoupper($driver), 'cyan');
                
                $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                printLine("   Version: $version", 'cyan');
            } catch (Exception $e) {
                printLine("❌ Database connection: FAILED", 'red');
                printLine("   Error: " . $e->getMessage(), 'red');
                exit(1);
            }
            
            // Verificar schema_map.php
            $schemaFile = __DIR__ . '/schema_map.php';
            if (file_exists($schemaFile)) {
                printLine("✅ Schema map: Found", 'green');
                $map = require $schemaFile;
                printLine("   Tables: " . count($map['tables']), 'cyan');
                printLine("   Generated: " . ($map['generated_at'] ?? 'Unknown'), 'cyan');
            } else {
                printLine("⚠️  Schema map: Not found", 'yellow');
                printLine("   Run 'php cli.php make:schema' to generate it.", 'yellow');
            }
            
            // Verificar caché
            $cacheDir = dirname(__DIR__, 3) . '/Core/Cache/data';
            if (is_dir($cacheDir)) {
                $files = glob("$cacheDir/*.cache");
                printLine("✅ Cache directory: OK (" . count($files) . " files)", 'green');
            } else {
                printLine("⚠️  Cache directory: Not found", 'yellow');
            }
            
            printLine("\n✨ System is ready!", 'green');
            break;

        case 'db:seed':
            printHeader("Database Seeding");
            
            if (!isset($options['force']) && !isset($options['f'])) {
                printLine("⚠️  WARNING: This will DELETE all existing data!", 'yellow');
                printLine("Run with --force to confirm.", 'yellow');
                exit(0);
            }
            
            printLine("Dropping existing table...", 'yellow');
            DB::exec("DROP TABLE IF EXISTS users");
            
            printLine("Creating table...", 'yellow');
            DB::exec("CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                role TEXT DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            printLine("Inserting sample data...", 'yellow');
            $roles = ['user', 'admin', 'moderator'];
            $names = [
                'John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Williams',
                'Charlie Brown', 'Diana Prince', 'Edward Norton', 'Fiona Apple',
                'George Lucas', 'Hannah Montana', 'Ivan Petrov', 'Julia Roberts',
                'Kevin Spacey', 'Laura Palmer', 'Michael Scott', 'Nancy Wheeler',
                'Oscar Martinez', 'Pam Beesly', 'Quentin Tarantino', 'Rachel Green'
            ];
            
            for ($i = 0; $i < 51; $i++) {
                $name = $names[$i % count($names)];
                $email = strtolower(str_replace(' ', '.', $name)) . ($i > 0 ? ".$i" : "") . "@example.com";
                $role = $roles[array_rand($roles)];
                
                DB::exec("INSERT INTO users (name, email, role) VALUES (?, ?, ?)", 
                    [$name, $email, $role]
                );
            }
            
            printLine("✅ Database seeded successfully!", 'green');
            printLine("   Inserted 51 users", 'cyan');
            break;

        default:
            printLine("❌ Unknown command: $command", 'red');
            printLine("Run 'php cli.php' to see available commands.", 'yellow');
            exit(1);
    }
} catch (Exception $e) {
    printLine("\n❌ Fatal error: " . $e->getMessage(), 'red');
    printLine($e->getTraceAsString(), 'red');
    exit(1);
}

echo PHP_EOL;
