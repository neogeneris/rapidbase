<?php
/**
 * RapidBase CLI Tool - Users CRUD Example
 */

require_once __DIR__ . '/config.php';

use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

// Colores para terminal
function colorize(string $text, string $color): string {
    $colors = [
        'red' => "\033[31m", 'green' => "\033[32m", 'yellow' => "\033[33m",
        'blue' => "\033[34m", 'cyan' => "\033[36m", 'bold' => "\033[1m", 'reset' => "\033[0m"
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function printHeader(string $title): void {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo colorize("  $title", 'bold') . "\n";
    echo str_repeat("=", 60) . "\n\n";
}

function printLine(string $text, string $color = 'reset'): void {
    echo colorize($text, $color) . "\n";
}

// Parsear argumentos
$command = $argv[1] ?? 'help';
$options = [];
foreach ($argv as $i => $arg) {
    if ($i > 1 && strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2));
        $options[$parts[0]] = $parts[1] ?? true;
    }
}

// Obtener PDO desde la conexión global
$pdo = DB::getConnection();

// Para SchemaMapper, pasamos el nombre lógico o usamos null para que detecte automáticamente
// En SQLite a menudo es mejor pasar null o el nombre 'main'
$dbName = 'main'; 
switch ($command) {
    case 'make:schema':
        printHeader("Generating Schema Map");
        $schemaFile = __DIR__ . '/schema_map.php';
        printLine("Scanning database structure...", 'yellow');
        
        // CORRECCIÓN: Pasar $pdo explícitamente
        $success = SchemaMapper::generate($pdo, $dbName);

        if (!$success || !file_exists($schemaFile)) {
            printLine("❌ Failed to generate schema map!", 'red');
            exit(1);
        }

        $map = include $schemaFile;
        printLine("✅ Schema map generated successfully!", 'green');
        printLine("📊 Tables found: " . count($map['tables']), 'bold');
        break;

    case 'users:list':
        printHeader("Users List");
        $page = isset($options['page']) ? (int)$options['page'] : 1;
        $limit = isset($options['limit']) ? (int)$options['limit'] : 10;

        try {
            // La firma actual es grid($table, $conditions, $page, $sort, $perPage)
            $result = DB::grid('users', [], $page, [], $limit);
            
            if (empty($result->data)) {
                printLine("No users found.", 'yellow');
                break;
            }

            printf("%-5s | %-25s | %-30s | %-12s | %-20s\n", "ID", "Name", "Email", "Role", "Created At");
            echo str_repeat("-", 100) . "\n";

            foreach ($result->data as $row) {
                // FETCH_NUM devuelve array numérico: [0]=>id, [1]=>name, [2]=>email, [3]=>role, [4]=>created_at
                printf("%-5s | %-25s | %-30s | %-12s | %-20s\n", 
                    $row[0] ?? '-', 
                    substr($row[1] ?? '', 0, 25), 
                    substr($row[2] ?? '', 0, 30), 
                    $row[3] ?? 'user', 
                    $row[4] ?? '-'
                );
            }
            
            // Usar el método pagination() de QueryResponse
            $pagination = $result->pagination();
            if ($pagination) {
                printLine("Page {$pagination['current']} of {$pagination['last']} (Total: {$result->total})", 'cyan');
            } else {
                printLine("Total: {$result->total} users", 'cyan');
            }

        } catch (Exception $e) {
            printLine("❌ Error: " . $e->getMessage(), 'red');
        }
        break;

        case 'status':
        printHeader("System Status");
        
        // Obtener driver desde PDO directamente
        $pdo = DB::getConnection();
        $driver = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'unknown';
        
        printLine("Environment:", 'bold');
        echo "  PHP Version: " . PHP_VERSION . "\n";
        echo "  Database Driver: " . $driver . "\n";
        echo "  Database Path: " . (defined('DB_PATH') ? DB_PATH : 'N/A') . "\n";
        
        // Verificar schema_map
        $schemaFile = __DIR__ . '/schema_map.php';
        if (file_exists($schemaFile)) {
            printLine("  ✓ Schema map found", 'green');
            $map = include $schemaFile;
            echo "  Tables mapped: " . count($map['tables']) . "\n";
            echo "  Generated at: " . ($map['generated_at'] ?? 'unknown') . "\n";
        } else {
            printLine("  ✗ Schema map not found (run 'php cli.php make:schema')", 'yellow');
        }
        
        // Probar conexión
        try {
            $count = DB::value('SELECT COUNT(*) FROM users');
            printLine("  ✓ Database connection OK ($count users)", 'green');
        } catch (Exception $e) {
            printLine("  ✗ Database connection failed: " . $e->getMessage(), 'red');
        }
        break;
    case 'db:seed':
        printHeader("Seeding Database");
        if (!isset($options['force'])) {
            printLine("⚠️ Run with --force to confirm deletion of all data.", 'yellow');
            break;
        }
        try {
            DB::exec("DROP TABLE IF EXISTS users");
            DB::exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, role TEXT DEFAULT 'user', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
            
            $users = [
                ['John Doe', 'john@example.com', 'admin'],
                ['Jane Smith', 'jane@example.com', 'user'],
                ['Bob Johnson', 'bob@example.com', 'moderator'],
                ['Alice Williams', 'alice@example.com', 'user'],
                ['Charlie Brown', 'charlie@example.com', 'user'],
            ];
            foreach ($users as $u) {
                DB::insert('users', ['name' => $u[0], 'email' => $u[1], 'role' => $u[2]]);
            }
            printLine("✅ Database seeded (5 users).", 'green');
        } catch (Exception $e) {
            printLine("❌ Error: " . $e->getMessage(), 'red');
        }
        break;

    default:
        printHeader("RapidBase CLI");
        echo "Commands:\n  make:schema\n  users:list [--page=1] [--limit=10]\n  status\n  db:seed --force\n";
        break;
}