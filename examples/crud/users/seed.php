<?php
/**
 * Database Seeder for Users CRUD Example
 * Creates the users table and populates with sample data
 */

require_once 'config.php';

use Core\DB;

echo "🌱 Seeding database...\n\n";

try {
    // Create users table
    DB::execute("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            role TEXT DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Table 'users' created successfully\n";

    // Check if already has data
    $count = DB::querySingle("SELECT COUNT(*) FROM users");
    if ($count > 0) {
        echo "ℹ️  Table already has $count records. Skipping seed.\n";
        exit(0);
    }

    // Sample data
    $sampleUsers = [
        ['John Doe', 'john@example.com', 'admin'],
        ['Jane Smith', 'jane@example.com', 'user'],
        ['Bob Johnson', 'bob@example.com', 'moderator'],
        ['Alice Williams', 'alice@example.com', 'user'],
        ['Charlie Brown', 'charlie@example.com', 'user'],
        ['Diana Prince', 'diana@example.com', 'admin'],
        ['Edward Norton', 'edward@example.com', 'user'],
        ['Fiona Apple', 'fiona@example.com', 'moderator'],
        ['George Lucas', 'george@example.com', 'user'],
        ['Hannah Montana', 'hannah@example.com', 'user'],
        ['Ivan Petrov', 'ivan@example.com', 'user'],
        ['Julia Roberts', 'julia@example.com', 'admin'],
        ['Kevin Hart', 'kevin@example.com', 'user'],
        ['Laura Croft', 'laura@example.com', 'moderator'],
        ['Michael Scott', 'michael@example.com', 'user'],
        ['Nancy Drew', 'nancy@example.com', 'user'],
        ['Oscar Wilde', 'oscar@example.com', 'user'],
        ['Patricia Arquette', 'patricia@example.com', 'user'],
        ['Quentin Tarantino', 'quentin@example.com', 'admin'],
        ['Rachel Green', 'rachel@example.com', 'user'],
        ['Steve Jobs', 'steve@example.com', 'moderator'],
        ['Tina Fey', 'tina@example.com', 'user'],
        ['Ursula K. Le Guin', 'ursula@example.com', 'user'],
        ['Vin Diesel', 'vin@example.com', 'user'],
        ['Walter White', 'walter@example.com', 'admin'],
        ['Xena Warrior', 'xena@example.com', 'user'],
        ['Yoda Master', 'yoda@example.com', 'moderator'],
        ['Zoe Saldana', 'zoe@example.com', 'user'],
        ['Aaron Paul', 'aaron@example.com', 'user'],
        ['Britney Spears', 'britney@example.com', 'user'],
        ['Chris Evans', 'chris@example.com', 'admin'],
        ['Emma Watson', 'emma@example.com', 'user'],
        ['Frank Sinatra', 'frank@example.com', 'user'],
        ['Grace Hopper', 'grace@example.com', 'moderator'],
        ['Henry Ford', 'henry@example.com', 'user'],
        ['Iris West', 'iris@example.com', 'user'],
        ['Jack Sparrow', 'jack@example.com', 'user'],
        ['Kate Winslet', 'kate@example.com', 'admin'],
        ['Leonardo DiCaprio', 'leonardo@example.com', 'user'],
        ['Marilyn Monroe', 'marilyn@example.com', 'user'],
        ['Nelson Mandela', 'nelson@example.com', 'moderator'],
        ['Oprah Winfrey', 'oprah@example.com', 'user'],
        ['Pablo Picasso', 'pablo@example.com', 'user'],
        ['Queen Elizabeth', 'queen@example.com', 'admin'],
        ['Robert Downey Jr', 'robert@example.com', 'user'],
        ['Scarlett Johansson', 'scarlett@example.com', 'user'],
        ['Tom Cruise', 'tom@example.com', 'moderator'],
        ['Uma Thurman', 'uma@example.com', 'user'],
        ['Victor Hugo', 'victor@example.com', 'user'],
        ['Will Smith', 'will@example.com', 'admin'],
        ['Zendaya', 'zendaya@example.com', 'user']
    ];

    // Insert sample data
    $inserted = 0;
    foreach ($sampleUsers as $userData) {
        try {
            DB::insert('users', [
                'name' => $userData[0],
                'email' => $userData[1],
                'role' => $userData[2],
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 365) . ' days'))
            ]);
            $inserted++;
        } catch (Exception $e) {
            // Skip duplicates
        }
    }

    echo "✅ Inserted $inserted sample users\n\n";
    
    // Show summary
    $total = DB::querySingle("SELECT COUNT(*) FROM users");
    echo "📊 Database Summary:\n";
    echo "   Total users: $total\n";
    
    $roles = DB::queryAll("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    echo "   Roles:\n";
    foreach ($roles as $role) {
        echo "      - {$role['role']}: {$role['count']}\n";
    }
    
    echo "\n🎉 Database seeding completed successfully!\n";
    echo "👉 Open index.php in your browser to view the CRUD interface.\n\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
