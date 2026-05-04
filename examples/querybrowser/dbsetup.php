<?php
/**
 * dbsetup.php - Genera una base de datos SQLite de demostracion para el QueryBrowser
 * 
 * Uso: php dbsetup.php
 * 
 * Crea la carpeta /data si no existe y genera el archivo demo.sqlite
 * con tablas: users, posts, categories, tags, post_tags, comments
 */

// Configuracion
$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$dbFile = $dataDir . DIRECTORY_SEPARATOR . 'demo.sqlite';

// Crear carpeta data si no existe
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
    echo "[OK] Folder 'data' created.\n";
}

// Eliminar base de datos anterior si existe (opcional)
if (file_exists($dbFile)) {
    unlink($dbFile);
    echo "[INFO] Previous database deleted.\n";
}

try {
    // Conectar a SQLite (crea el archivo)
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "[INFO] Creating tables...\n";
    
    // 1. Tabla users
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            username TEXT UNIQUE NOT NULL,
            phone TEXT,
            website TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 2. Tabla posts
    $pdo->exec("
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // 3. Tabla categories
    $pdo->exec("
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 4. Tabla tags
    $pdo->exec("
        CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 5. Tabla pivot post_categories (relacion muchos a muchos)
    $pdo->exec("
        CREATE TABLE post_categories (
            post_id INTEGER,
            category_id INTEGER,
            PRIMARY KEY (post_id, category_id),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        )
    ");
    
    // 6. Tabla pivot post_tags
    $pdo->exec("
        CREATE TABLE post_tags (
            post_id INTEGER,
            tag_id INTEGER,
            PRIMARY KEY (post_id, tag_id),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )
    ");
    
    // 7. Tabla comments
    $pdo->exec("
        CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    echo "[OK] Tables created.\n";
    echo "[INFO] Inserting sample data...\n";
    
    // Insertar usuarios
    $users = [
        ['Juan Perez', 'juan@example.com', 'juanp', '555-1234', 'https://juan.dev'],
        ['Ana Gomez', 'ana@example.com', 'anag', '555-5678', 'https://ana.dev'],
        ['Carlos Ruiz', 'carlos@example.com', 'carlosr', '555-9012', 'https://carlos.dev'],
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (name, email, username, phone, website) VALUES (?, ?, ?, ?, ?)");
    foreach ($users as $u) {
        $stmtUser->execute($u);
    }
    echo "   - Users inserted: " . count($users) . "\n";
    
    // Insertar categorias
    $categories = ['Tecnologia', 'Programacion', 'Base de Datos', 'PHP', 'JavaScript', 'SQLite'];
    $stmtCat = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
    foreach ($categories as $cat) {
        $stmtCat->execute([$cat]);
    }
    echo "   - Categories inserted: " . count($categories) . "\n";
    
    // Insertar tags
    $tags = ['tutorial', 'ejemplo', 'avanzado', 'principiante', 'tips', 'noticias'];
    $stmtTag = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
    foreach ($tags as $tag) {
        $stmtTag->execute([$tag]);
    }
    echo "   - Tags inserted: " . count($tags) . "\n";
    
    // Insertar posts (relacionados con usuarios)
    $posts = [
        [1, 'Introduccion a SQLite', 'SQLite es una base de datos ligera y sin servidor...', 1, 3],
        [1, 'Como usar JOIN en SQL', 'Los JOIN permiten combinar tablas relacionadas...', 2, 4],
        [2, 'PHP y PDO: Lo basico', 'PDO es la extension recomendada para acceder a BD...', 1, 5],
        [2, 'JavaScript para principiantes', 'Aprende los fundamentos de JS...', 3, 5],
        [3, 'Optimizacion de consultas', 'Indices y explicacion de planes...', 2, 1],
        [3, 'Modelado de datos', 'Normalizacion y claves foraneas...', 2, 3],
    ];
    $stmtPost = $pdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
    $postIds = [];
    foreach ($posts as $p) {
        $stmtPost->execute([$p[0], $p[1], $p[2]]);
        $postIds[] = $pdo->lastInsertId();
    }
    echo "   - Posts inserted: " . count($posts) . "\n";
    
    // Asignar categorias a posts
    $postCats = [
        [1, 1], [1, 2], [2, 3], [3, 4], [3, 5], [4, 5], [4, 6], [5, 2], [5, 3], [6, 1], [6, 6]
    ];
    $stmtPC = $pdo->prepare("INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)");
    foreach ($postCats as $pc) {
        $stmtPC->execute([$postIds[$pc[0]-1], $pc[1]]);
    }
    
    // Asignar tags a posts
    $postTags = [
        [1, 1], [1, 4], [2, 2], [2, 5], [3, 1], [3, 3], [4, 4], [5, 2], [5, 5], [6, 1], [6, 6]
    ];
    $stmtPT = $pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");
    foreach ($postTags as $pt) {
        $stmtPT->execute([$postIds[$pt[0]-1], $pt[1]]);
    }
    
    // Insertar comentarios
    $comments = [
        [1, 2, 'Excelente articulo, muy claro.'],
        [1, 3, 'Me ayudo mucho, gracias.'],
        [2, 1, 'Podrias profundizar en LEFT JOIN?'],
        [3, 2, 'PDO es lo mejor, gran post.'],
        [4, 3, 'Me encanta JavaScript, buen resumen.'],
        [5, 1, 'Muy util para optimizar mis consultas.'],
        [6, 2, 'Buenas practicas, gracias.'],
    ];
    $stmtCom = $pdo->prepare("INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)");
    foreach ($comments as $c) {
        $stmtCom->execute([$c[0], $c[1], $c[2]]);
    }
    echo "   - Comments inserted: " . count($comments) . "\n";
    
    // Mostrar resumen
    echo "\n[OK] Demo database created successfully!\n";
    echo "File: $dbFile\n";
    echo "Size: " . number_format(filesize($dbFile) / 1024, 2) . " KB\n";
    echo "\nNow you can use the QueryBrowser with this database.\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}