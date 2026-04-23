<?php
/**
 * RapidBase CLI Tool - Users CRUD Example
 * 
 * Commands:
 *   php cli.php make:schema    Generate schema_map.php
 *   php cli.php users:list     List all users (with pagination)
 *   php cli.php status         Show system status
 *   php cli.php db:seed        Reset database with seed data
 */

require_once __DIR__ . '/config.php';

use RapidBase\Core\DB;
use RapidBase\ORM\ActiveRecord\Model;
use RapidBase\Meta\SchemaMapper;

// Colores para terminal
function colorize(string $text, string $color): string {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'cyan' => "\033[36m",
        'bold' => "\033[1m",
        'reset' => "\033[0m"
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

// Obtener PDO desde config
global $pdo;
if (!isset($pdo)) {
    $config = require __DIR__ . '/config.php';
    $pdo = DB::connection();
}

// Obtener nombre de la base de datos (para SQLite es el path)
$dbName = match(DB::driver()) {
    'sqlite' => DB::database(),
    default => DB::database()
};

switch ($command) {
    case 'make:schema':
        printHeader("Generating Schema Map");

        $schemaFile = __DIR__ . '/schema_map.php';

        printLine("Scanning database structure...", 'yellow');
        
        // Usar el método estático correcto de SchemaMapper
        $success = SchemaMapper::generate($pdo, $dbName);

        if (!$success || !file_exists($schemaFile)) {
            printLine("❌ Failed to generate schema map!", 'red');
            exit(1);
        }

        // Leer y mostrar resumen
        $map = include $schemaFile;
        
        printLine("✅ Schema map generated successfully!", 'green');
        printLine("📄 File: $schemaFile", 'blue');

        $tableCount = count($map['tables']);
        printLine("📊 Tables found: $tableCount", 'bold');

        foreach ($map['tables'] as $tableName => $columns) {
            $colCount = count($columns);
            printLine("   - $tableName ($colCount columns)", 'cyan');
        }
        break;

    case 'users:list':
        printHeader("Users List");

        $page = isset($options['page']) ? (int)$options['page'] : 1;
        $limit = isset($options['limit']) ? (int)$options['limit'] : 10;

        try {
            $result = DB::grid('users', '*', [], null, $page, $limit);
            
            if (empty($result->data)) {
                printLine("No users found.", 'yellow');
                break;
            }

            // Obtener nombres de columnas del head
            $columns = $result->head['columns'] ?? ['id', 'name', 'email', 'role', 'created_at'];
            
            // Imprimir cabecera
            printf("%-5s | %-25s | %-30s | %-12s | %-20s\n", "ID", "Name", "Email", "Role", "Created At");
            echo str_repeat("-", 100) . "\n";

            // Imprimir filas (FETCH_NUM)
            foreach ($result->data as $row) {
                printf("%-5s | %-25s | %-30s | %-12s | %-20s\n", 
                    $row[0], // id
                    substr($row[1], 0, 25), // name
                    substr($row[2], 0, 30), // email
                    $row[3] ?? 'user', // role
                    $row[4] ?? '-' // created_at
                );
            }

            echo "\n";
            printLine("Page {$page} of {$result->page['total']} (Total: {$result->page['records']} records)", 'cyan');

        } catch (Exception $e) {
            printLine("❌ Error: " . $e->getMessage(), 'red');
        }
        break;

    case 'status':
        printHeader("System Status");
        
        printLine("Environment:", 'bold');
        echo "  PHP Version: " . PHP_VERSION . "\n";
        echo "  Database Driver: " . DB::driver() . "\n";
        echo "  Database Name: " . DB::database() . "\n";
        
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
            printLine("⚠️  This will DELETE all existing data!", 'yellow');
            printLine("Run with --force to confirm.", 'cyan');
            break;
        }

        try {
            // Recrear tabla
            DB::exec("DROP TABLE IF EXISTS users");
            DB::exec("CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                role TEXT DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Insertar datos
            $names = [
                ['John Doe', 'john@example.com', 'admin'],
                ['Jane Smith', 'jane@example.com', 'user'],
                ['Bob Johnson', 'bob@example.com', 'moderator'],
                ['Alice Williams', 'alice@example.com', 'user'],
                ['Charlie Brown', 'charlie@example.com', 'user'],
            ];

            foreach ($names as [$name, $email, $role]) {
                DB::insert('users', compact('name', 'email', 'role'));
            }

            printLine("✅ Database seeded successfully!", 'green');
            printLine("  Created 5 test users.", 'cyan');

        } catch (Exception $e) {
            printLine("❌ Error: " . $e->getMessage(), 'red');
        }
        break;

    case 'help':
    default:
        printHeader("RapidBase CLI - Users CRUD");
        echo "Available commands:\n\n";
        echo "  php cli.php make:schema       Generate schema_map.php from database\n";
        echo "  php cli.php users:list        List users with pagination\n";
        echo "      [--page=1] [--limit=10]\n";
        echo "  php cli.php status            Show system status and connection info\n";
        echo "  php cli.php db:seed           Reset database with test data\n";
        echo "      [--force]                 Confirm destructive action\n";
        echo "\n";
        break;
}
