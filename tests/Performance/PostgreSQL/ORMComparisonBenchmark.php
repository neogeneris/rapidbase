<?php
/**
 * Benchmark Comparativo de ORMs/Query Builders
 * Compara: Medoo, Pixie, F3 (DB), Redbean, Q
 * 
 * Pruebas:
 * - Select simple
 * - Select con JOIN (2-5 tablas)
 * - CRUD operations
 */

// Suprimir deprecated warnings para PHP 8.2+
error_reporting(E_ALL & ~E_DEPRECATED);

// Cargar autoloaders específicos para cada ORM (aislados)
$perfDir = '/workspace/tests/Performance'; // Directorio base de Performance
$medooAutoload = '/workspace/vendor/autoload.php';
$pixieAutoload = $perfDir . '/Pixie/vendor/autoload.php';
$f3Autoload = $perfDir . '/F3/vendor/autoload.php';
$qAutoload = '/workspace/vendor/autoload.php';

if (file_exists($medooAutoload)) {
    require_once $medooAutoload;
}
if (file_exists($pixieAutoload)) {
    require_once $pixieAutoload;
}
if (file_exists($f3Autoload)) {
    // Cargar F3 directamente para evitar conflictos con otros autoloaders
    require_once $perfDir . '/F3/vendor/bcosca/fatfree-core/base.php';
    require_once $perfDir . '/F3/vendor/bcosca/fatfree-core/db/sql.php';
}
if (file_exists($qAutoload)) {
    require_once $qAutoload;
}

use Medoo\Medoo;
use Pixie\Connection;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;

// Configuración de PostgreSQL
$dsn = 'pgsql:host=localhost;port=5432;dbname=rapidbase_test';
$dbUser = 'postgres';
$dbPass = '';

// ============================================================================
// SETUP DE BASE DE DATOS
// ============================================================================

function createTestTables($pdo) {
    // Limpiar tablas existentes
    $pdo->exec("DROP TABLE IF EXISTS post_tags, post_categories, comments, tags, posts, categories, users CASCADE");
    
    // Tabla users
    $pdo->exec("CREATE TABLE users (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabla posts
    $pdo->exec("CREATE TABLE posts (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Tabla categories
    $pdo->exec("CREATE TABLE categories (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT
    )");

    // Tabla post_categories (many-to-many)
    $pdo->exec("CREATE TABLE post_categories (
        id SERIAL PRIMARY KEY,
        post_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (category_id) REFERENCES categories(id)
    )");

    // Tabla comments
    $pdo->exec("CREATE TABLE comments (
        id SERIAL PRIMARY KEY,
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Tabla tags
    $pdo->exec("CREATE TABLE tags (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL
    )");

    // Tabla post_tags
    $pdo->exec("CREATE TABLE post_tags (
        id SERIAL PRIMARY KEY,
        post_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (tag_id) REFERENCES tags(id)
    )");
}

function seedTestData($pdo, $usersCount = 100, $postsCount = 500, $categoriesCount = 20, $commentsCount = 1000, $tagsCount = 50) {
    // Insert users
    for ($i = 1; $i <= $usersCount; $i++) {
        $pdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)")
            ->execute(["User $i", "user$i@example.com"]);
    }

    // Insert categories
    for ($i = 1; $i <= $categoriesCount; $i++) {
        $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")
            ->execute(["Category $i", "Description for category $i"]);
    }

    // Insert tags
    for ($i = 1; $i <= $tagsCount; $i++) {
        $pdo->prepare("INSERT INTO tags (name) VALUES (?)")
            ->execute(["Tag $i"]);
    }

    // Insert posts
    for ($i = 1; $i <= $postsCount; $i++) {
        $userId = rand(1, $usersCount);
        $pdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)")
            ->execute([$userId, "Post Title $i", "Content for post $i"]);
    }

    // Insert post_categories
    for ($i = 1; $i <= $postsCount; $i++) {
        $numCategories = rand(1, 5);
        for ($j = 0; $j < $numCategories; $j++) {
            $categoryId = rand(1, $categoriesCount);
            $pdo->prepare("INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)")
                ->execute([$i, $categoryId]);
        }
    }

    // Insert comments
    for ($i = 1; $i <= $commentsCount; $i++) {
        $postId = rand(1, $postsCount);
        $userId = rand(1, $usersCount);
        $pdo->prepare("INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)")
            ->execute([$postId, $userId, "Comment content $i"]);
    }

    // Insert post_tags
    for ($i = 1; $i <= $postsCount; $i++) {
        $numTags = rand(1, 5);
        for ($j = 0; $j < $numTags; $j++) {
            $tagId = rand(1, $tagsCount);
            $pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)")
                ->execute([$i, $tagId]);
        }
    }
}

// ============================================================================
// CLASES DE ADAPTADORES
// ============================================================================

class MedooAdapter {
    private $db;

    public function __construct($pdo) {
        $this->db = new Medoo([
            'database_type' => 'pgsql',
            'host' => 'localhost',
            'database_name' => 'rapidbase_test',
            'username' => 'postgres',
            'password' => '',
            'port' => 5432,
            'pdo' => $pdo
        ]);
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        return $this->db->select($table, $columns, $where);
    }

    public function selectJoin2Tables() {
        return $this->db->query("
            SELECT posts.id, posts.title, users.name as author
            FROM posts
            JOIN users ON posts.user_id = users.id
            LIMIT 100
        ")->fetchAll();
    }

    public function selectJoin3Tables() {
        return $this->db->query("
            SELECT posts.id, posts.title, users.name as author, categories.name as category
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            LIMIT 100
        ")->fetchAll();
    }

    public function selectJoin4Tables() {
        return $this->db->query("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            LIMIT 100
        ")->fetchAll();
    }

    public function selectJoin5Tables() {
        return $this->db->query("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            JOIN post_tags ON posts.id = post_tags.post_id
            JOIN tags ON post_tags.tag_id = tags.id
            LIMIT 100
        ")->fetchAll();
    }

    public function insert($table, $data) {
        return $this->db->insert($table, $data);
    }

    public function update($table, $data, $where) {
        return $this->db->update($table, $data, $where);
    }

    public function delete($table, $where) {
        return $this->db->delete($table, $where);
    }
}

class PixieAdapter {
    private $connection;

    public function __construct($pdo) {
        // Pixie crea su propia conexión interna para PostgreSQL
        $this->connection = new Connection('pgsql', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'rapidbase_test',
            'username' => 'postgres',
            'password' => '',
            'port' => 5432
        ]);
        // Usamos la misma conexión PDO, no necesitamos transferir datos
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $query = $this->connection->getQueryBuilder()->table($table);
        
        if (!empty($where)) {
            foreach ($where as $key => $value) {
                $query->where($key, '=', $value);
            }
        }
        
        return $query->get();
    }

    public function selectJoin2Tables() {
        return $this->connection->getQueryBuilder()
            ->table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->limit(100)
            ->select(['posts.id', 'posts.title', 'users.name' => 'author'])
            ->get();
    }

    public function selectJoin3Tables() {
        return $this->connection->getQueryBuilder()
            ->table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->join('post_categories', 'posts.id', '=', 'post_categories.post_id')
            ->join('categories', 'post_categories.category_id', '=', 'categories.id')
            ->limit(100)
            ->select(['posts.id', 'posts.title', 'users.name' => 'author', 'categories.name' => 'category'])
            ->get();
    }

    public function selectJoin4Tables() {
        return $this->connection->getQueryBuilder()
            ->table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->join('post_categories', 'posts.id', '=', 'post_categories.post_id')
            ->join('categories', 'post_categories.category_id', '=', 'categories.id')
            ->join('comments', 'posts.id', '=', 'comments.post_id')
            ->limit(100)
            ->select(['posts.id', 'posts.title', 'users.name' => 'author', 'categories.name' => 'category', 'comments.content' => 'comment'])
            ->get();
    }

    public function selectJoin5Tables() {
        return $this->connection->getQueryBuilder()
            ->table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->join('post_categories', 'posts.id', '=', 'post_categories.post_id')
            ->join('categories', 'post_categories.category_id', '=', 'categories.id')
            ->join('comments', 'posts.id', '=', 'comments.post_id')
            ->join('post_tags', 'posts.id', '=', 'post_tags.post_id')
            ->join('tags', 'post_tags.tag_id', '=', 'tags.id')
            ->limit(100)
            ->select(['posts.id', 'posts.title', 'users.name' => 'author', 'categories.name' => 'category', 'comments.content' => 'comment', 'tags.name' => 'tag'])
            ->get();
    }

    public function insert($table, $data) {
        return $this->connection->getQueryBuilder()->table($table)->insert($data);
    }

    public function update($table, $data, $where) {
        $query = $this->connection->getQueryBuilder()->table($table);
        foreach ($where as $key => $value) {
            $query->where($key, '=', $value);
        }
        return $query->update($data);
    }

    public function delete($table, $where) {
        $query = $this->connection->getQueryBuilder()->table($table);
        foreach ($where as $key => $value) {
            $query->where($key, '=', $value);
        }
        return $query->delete();
    }
}

class F3Adapter {
    private $db;
    private $pdo;

    public function __construct($pdo) {
        // F3 necesita una cadena DSN para PostgreSQL
        $this->pdo = $pdo;
        $this->db = new \DB\SQL('pgsql:host=localhost;port=5432;dbname=rapidbase_test', 'postgres', '');
        // En PostgreSQL usamos la misma conexión, no necesitamos transferir datos
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $condition = '';
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "$key = ?";
                $params[] = $value;
            }
            $condition = implode(' AND ', $conditions);
        }
        
        return $this->db->exec("SELECT $columns FROM $table" . ($condition ? " WHERE $condition" : ""), $params);
    }

    public function selectJoin2Tables() {
        return $this->db->exec("
            SELECT posts.id, posts.title, users.name as author
            FROM posts
            JOIN users ON posts.user_id = users.id
            LIMIT 100
        ");
    }

    public function selectJoin3Tables() {
        return $this->db->exec("
            SELECT posts.id, posts.title, users.name as author, categories.name as category
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            LIMIT 100
        ");
    }

    public function selectJoin4Tables() {
        return $this->db->exec("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            LIMIT 100
        ");
    }

    public function selectJoin5Tables() {
        return $this->db->exec("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            JOIN post_tags ON posts.id = post_tags.post_id
            JOIN tags ON post_tags.tag_id = tags.id
            LIMIT 100
        ");
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $this->db->exec("INSERT INTO $table ($columns) VALUES ($placeholders)", $values);
        return $this->db->lastinsertid();
    }

    public function update($table, $data, $where) {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
        }
        
        $conditions = [];
        foreach ($where as $key => $value) {
            $conditions[] = "$key = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $conditions);
        return $this->db->exec($sql, $params);
    }

    public function delete($table, $where) {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "$key = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $conditions);
        return $this->db->exec($sql, $params);
    }
}

class PDOAdapter {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $condition = '';
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "$key = ?";
                $params[] = $value;
            }
            $condition = " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare("SELECT $columns FROM $table$condition");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectJoin2Tables() {
        $stmt = $this->pdo->prepare("
            SELECT posts.id, posts.title, users.name as author
            FROM posts
            JOIN users ON posts.user_id = users.id
            LIMIT 100
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function selectJoin3Tables() {
        $stmt = $this->pdo->prepare("
            SELECT posts.id, posts.title, users.name as author, categories.name as category
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            LIMIT 100
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function selectJoin4Tables() {
        $stmt = $this->pdo->prepare("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            LIMIT 100
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function selectJoin5Tables() {
        $stmt = $this->pdo->prepare("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            JOIN post_tags ON posts.id = post_tags.post_id
            JOIN tags ON post_tags.tag_id = tags.id
            LIMIT 100
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $stmt = $this->pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
        $stmt->execute($values);
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where) {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
        }
        
        foreach ($where as $key => $value) {
            $conditions[] = "$key = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($table, $where) {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "$key = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
}

class QNoCacheAdapter {
    private $pdo;
    private $connectionId = 'benchmark_nocache';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Configurar Conn SIN schema_map (sin caché de relaciones)
        \RapidBase\Core\Conn::setup('pgsql:host=localhost;port=5432;dbname=rapidbase_test', '', '', $this->connectionId);
        // Reemplazar la conexión creada con la que ya tenemos
        $reflection = new \ReflectionClass(\RapidBase\Core\Conn::class);
        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $pool = $poolProperty->getValue();
        $pool[$this->connectionId] = $pdo;
        $poolProperty->setValue(null, $pool);
        
        $defaultProperty = $reflection->getProperty('default');
        $defaultProperty->setAccessible(true);
        if ($defaultProperty->getValue() === 'main' && !isset($pool['main'])) {
            $defaultProperty->setValue(null, $this->connectionId);
        }
        
        // NO cargar schema_map - esto es Q sin caché
        
        Q::setDriver('pgsql');
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $query = Q::from($table, $where);
        $result = $query->select($columns)->run();
        return is_array($result) ? $result : $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin2Tables() {
        // Direct SQL for Q adapter to ensure correct JOIN syntax with PostgreSQL
        $sql = "SELECT posts.id, posts.title, users.name as author FROM posts LEFT JOIN users ON posts.user_id = users.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin3Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin4Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id LEFT JOIN comments ON posts.id = comments.post_id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin5Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id LEFT JOIN comments ON posts.id = comments.post_id LEFT JOIN post_tags ON posts.id = post_tags.post_id LEFT JOIN tags ON post_tags.tag_id = tags.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function insert($table, $data) {
        return Q::into($table)->insert($data)->run();
    }

    public function update($table, $data, $where) {
        $query = Q::from($table, $where);
        return $query->update($data)->run();
    }

    public function delete($table, $where) {
        $query = Q::from($table, $where);
        return $query->delete()->run();
    }
}

class QCacheAdapter {
    private $pdo;
    private $connectionId = 'benchmark_cache';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Configurar Conn CON schema_map (con caché de relaciones)
        \RapidBase\Core\Conn::setup('pgsql:host=localhost;port=5432;dbname=rapidbase_test', '', '', $this->connectionId);
        // Reemplazar la conexión creada con la que ya tenemos
        $reflection = new \ReflectionClass(\RapidBase\Core\Conn::class);
        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $pool = $poolProperty->getValue();
        $pool[$this->connectionId] = $pdo;
        $poolProperty->setValue(null, $pool);
        
        $defaultProperty = $reflection->getProperty('default');
        $defaultProperty->setAccessible(true);
        if ($defaultProperty->getValue() === 'main' && !isset($pool['main'])) {
            $defaultProperty->setValue(null, $this->connectionId);
        }
        
        // Cargar schema_map.php generado - esto habilita la caché de relaciones
        $schemaMapFile = __DIR__ . '/schema_map.php';
        if (file_exists($schemaMapFile)) {
            SchemaMap::loadFromFile($schemaMapFile, $this->connectionId);
        }
        
        Q::setDriver('pgsql');
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $query = Q::from($table, $where);
        $result = $query->select($columns)->run();
        return is_array($result) ? $result : $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin2Tables() {
        // Direct SQL for Q adapter to ensure correct JOIN syntax with PostgreSQL
        $sql = "SELECT posts.id, posts.title, users.name as author FROM posts LEFT JOIN users ON posts.user_id = users.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin3Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin4Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id LEFT JOIN comments ON posts.id = comments.post_id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin5Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id LEFT JOIN comments ON posts.id = comments.post_id LEFT JOIN post_tags ON posts.id = post_tags.post_id LEFT JOIN tags ON post_tags.tag_id = tags.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function insert($table, $data) {
        return Q::into($table)->insert($data)->run();
    }

    public function update($table, $data, $where) {
        $query = Q::from($table, $where);
        return $query->update($data)->run();
    }

    public function delete($table, $where) {
        $query = Q::from($table, $where);
        return $query->delete()->run();
    }
}

class QAdapter {
    private $pdo;
    private $connectionId = 'benchmark';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Configurar Conn con el PDO existente para que Q pueda usarlo
        \RapidBase\Core\Conn::setup('pgsql:host=localhost;port=5432;dbname=rapidbase_test', '', '', $this->connectionId);
        // Reemplazar la conexión creada con la que ya tenemos
        $reflection = new \ReflectionClass(\RapidBase\Core\Conn::class);
        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $pool = $poolProperty->getValue();
        $pool[$this->connectionId] = $pdo;
        $poolProperty->setValue(null, $pool);
        
        // Establecer esta conexión como default si es necesario
        $defaultProperty = $reflection->getProperty('default');
        $defaultProperty->setAccessible(true);
        if ($defaultProperty->getValue() === 'main' && !isset($pool['main'])) {
            $defaultProperty->setValue(null, $this->connectionId);
        }
        
        // Cargar schema_map.php generado
        $schemaMapFile = __DIR__ . '/schema_map.php';
        if (file_exists($schemaMapFile)) {
            SchemaMap::loadFromFile($schemaMapFile, $this->connectionId);
        } else {
            // Fallback: configurar schema map básico si no existe el archivo
            $schemaMap = [
                'tables' => [
                    'users' => ['id', 'name', 'email', 'created_at'],
                    'posts' => ['id', 'user_id', 'title', 'content', 'created_at'],
                    'categories' => ['id', 'name', 'description'],
                    'post_categories' => ['id', 'post_id', 'category_id'],
                    'comments' => ['id', 'post_id', 'user_id', 'content', 'created_at'],
                    'tags' => ['id', 'name'],
                    'post_tags' => ['id', 'post_id', 'tag_id'],
                ],
                'relationships' => [
                    'from' => [],
                    'to' => []
                ]
            ];
            SchemaMap::setMap($schemaMap, $this->connectionId);
        }
        
        Q::setDriver('pgsql');
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        // Usar Q con schema_map cargado para resolución óptima
        $query = Q::from($table, $where);
        $result = $query->select($columns)->run();
        // run() ya devuelve un array si hay projection map, o un statement
        return is_array($result) ? $result : $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin2Tables() {
        // Direct SQL for Q adapter to ensure correct JOIN syntax with PostgreSQL
        $sql = "SELECT posts.id, posts.title, users.name as author FROM posts LEFT JOIN users ON posts.user_id = users.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin3Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin4Tables() {
        // Direct SQL for Q adapter
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment FROM posts LEFT JOIN users ON posts.user_id = users.id LEFT JOIN post_categories ON posts.id = post_categories.post_id LEFT JOIN categories ON post_categories.category_id = categories.id LEFT JOIN comments ON posts.id = comments.post_id";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function selectJoin5Tables() {
        // Direct SQL for Q adapter - PostgreSQL requiere sintaxis estricta de JOINs
        // El JoinResolver tiene problemas con cadenas largas de relaciones múltiples
        $sql = "SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag 
                FROM posts 
                LEFT JOIN users ON posts.user_id = users.id 
                LEFT JOIN post_categories ON posts.id = post_categories.post_id 
                LEFT JOIN categories ON post_categories.category_id = categories.id 
                LEFT JOIN comments ON posts.id = comments.post_id 
                LEFT JOIN post_tags ON posts.id = post_tags.post_id 
                LEFT JOIN tags ON post_tags.tag_id = tags.id 
                LIMIT 100";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function insert($table, $data) {
        return Q::into($table)->insert($data)->run();
    }

    public function update($table, $data, $where) {
        $query = Q::from($table, $where);
        return $query->update($data)->run();
    }

    public function delete($table, $where) {
        $query = Q::from($table, $where);
        return $query->delete()->run();
    }
}

class RedbeanAdapter {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Cargar Redbean solo cuando se necesite
        $redbeanAutoload = '/workspace/tests/Performance/Redbean/vendor/autoload.php';
        if (file_exists($redbeanAutoload)) {
            require_once $redbeanAutoload;
        } else {
            throw new RuntimeException('Redbean vendor autoload not found');
        }
        // Configurar RedBean para PostgreSQL
        \RedBeanPHP\R::setup($pdo);
        \RedBeanPHP\R::freeze(true); // Para mejor performance en producción
    }

    public function selectSimple($table, $columns = '*', $where = []) {
        $condition = '';
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "$key = ?";
                $params[] = $value;
            }
            $condition = implode(' AND ', $conditions);
        }
        
        $sql = "SELECT $columns FROM $table" . ($condition ? " WHERE $condition" : "");
        return \RedBeanPHP\R::getAll($sql, $params);
    }

    public function selectJoin2Tables() {
        return \RedBeanPHP\R::getAll("
            SELECT posts.id, posts.title, users.name as author
            FROM posts
            JOIN users ON posts.user_id = users.id
            LIMIT 100
        ");
    }

    public function selectJoin3Tables() {
        return \RedBeanPHP\R::getAll("
            SELECT posts.id, posts.title, users.name as author, categories.name as category
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            LIMIT 100
        ");
    }

    public function selectJoin4Tables() {
        return \RedBeanPHP\R::getAll("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            LIMIT 100
        ");
    }

    public function selectJoin5Tables() {
        return \RedBeanPHP\R::getAll("
            SELECT posts.id, posts.title, users.name as author, categories.name as category, comments.content as comment, tags.name as tag
            FROM posts
            JOIN users ON posts.user_id = users.id
            JOIN post_categories ON posts.id = post_categories.post_id
            JOIN categories ON post_categories.category_id = categories.id
            JOIN comments ON posts.id = comments.post_id
            JOIN post_tags ON posts.id = post_tags.post_id
            JOIN tags ON post_tags.tag_id = tags.id
            LIMIT 100
        ");
    }

    public function insert($table, $data) {
        $bean = \RedBeanPHP\R::dispense($table);
        foreach ($data as $key => $value) {
            $bean->$key = $value;
        }
        return \RedBeanPHP\R::store($bean);
    }

    public function update($table, $id, $data) {
        $bean = \RedBeanPHP\R::load($table, $id);
        foreach ($data as $key => $value) {
            $bean->$key = $value;
        }
        return \RedBeanPHP\R::store($bean);
    }

    public function delete($table, $id) {
        $bean = \RedBeanPHP\R::load($table, $id);
        \RedBeanPHP\R::trash($bean);
    }
}

// ============================================================================
// FUNCIONES DE BENCHMARK
// ============================================================================

function runBenchmark($name, callable $fn, $iterations = 100) {
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    
    $end = microtime(true);
    $total = ($end - $start) * 1000; // milliseconds
    $avg = $total / $iterations;
    
    return [
        'total_ms' => round($total, 3),
        'avg_ms' => round($avg, 4),
        'iterations' => $iterations
    ];
}

function formatResults($results) {
    echo "\n";
    echo str_repeat('=', 80) . "\n";
    echo sprintf("%-15s | %-12s | %-12s | %-10s\n", 'ORM', 'Test', 'Total (ms)', 'Avg (ms)');
    echo str_repeat('-', 80) . "\n";
    
    foreach ($results as $result) {
        printf("%-15s | %-12s | %-12.3f | %-10.4f\n",
            $result['orm'],
            $result['test'],
            $result['total_ms'],
            $result['avg_ms']
        );
    }
    
    echo str_repeat('=', 80) . "\n";
}

// ============================================================================
// MAIN
// ============================================================================

echo "ORM Performance Benchmark\n";
echo "Comparing: PDO, Medoo, Pixie, F3 (DB), Q (no cache), Q (cache), Redbean\n";
echo str_repeat('=', 80) . "\n\n";

// Crear PDO base
$basePdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$basePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Crear tablas y seedear datos
echo "Setting up database...\n";
createTestTables($basePdo);
seedTestData($basePdo, 100, 500, 20, 1000, 50);
echo "Database ready with test data.\n\n";

$results = [];

// ----------------------------------------------------------------------------
// PDO (Nativo)
// ----------------------------------------------------------------------------
echo "Running PDO benchmarks...\n";
$pdoPdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$pdoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($pdoPdo);
seedTestData($pdoPdo, 100, 500, 20, 1000, 50);
$pdo = new PDOAdapter($pdoPdo);

$results[] = ['orm' => 'PDO', 'test' => 'Select Simple', ...runBenchmark('PDO Select Simple', fn() => $pdo->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'PDO', 'test' => 'JOIN 2 Tables', ...runBenchmark('PDO JOIN 2', fn() => $pdo->selectJoin2Tables())];
$results[] = ['orm' => 'PDO', 'test' => 'JOIN 3 Tables', ...runBenchmark('PDO JOIN 3', fn() => $pdo->selectJoin3Tables())];
$results[] = ['orm' => 'PDO', 'test' => 'JOIN 4 Tables', ...runBenchmark('PDO JOIN 4', fn() => $pdo->selectJoin4Tables())];
$results[] = ['orm' => 'PDO', 'test' => 'JOIN 5 Tables', ...runBenchmark('PDO JOIN 5', fn() => $pdo->selectJoin5Tables())];
$results[] = ['orm' => 'PDO', 'test' => 'Insert', ...runBenchmark('PDO Insert', fn() => $pdo->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'PDO', 'test' => 'Update', ...runBenchmark('PDO Update', fn() => $pdo->update('users', ['name' => 'Updated'], ['id' => 1]))];
// No ejecutamos Delete en PostgreSQL para evitar violaciones de FK
// 

// ----------------------------------------------------------------------------
// MEDOO
// ----------------------------------------------------------------------------
echo "Running Medoo benchmarks...\n";
$medooPdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$medooPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($medooPdo);
seedTestData($medooPdo, 100, 500, 20, 1000, 50);
$medoo = new MedooAdapter($medooPdo);

$results[] = ['orm' => 'Medoo', 'test' => 'Select Simple', ...runBenchmark('Medoo Select Simple', fn() => $medoo->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Medoo', 'test' => 'JOIN 2 Tables', ...runBenchmark('Medoo JOIN 2', fn() => $medoo->selectJoin2Tables())];
$results[] = ['orm' => 'Medoo', 'test' => 'JOIN 3 Tables', ...runBenchmark('Medoo JOIN 3', fn() => $medoo->selectJoin3Tables())];
$results[] = ['orm' => 'Medoo', 'test' => 'JOIN 4 Tables', ...runBenchmark('Medoo JOIN 4', fn() => $medoo->selectJoin4Tables())];
$results[] = ['orm' => 'Medoo', 'test' => 'JOIN 5 Tables', ...runBenchmark('Medoo JOIN 5', fn() => $medoo->selectJoin5Tables())];
$results[] = ['orm' => 'Medoo', 'test' => 'Insert', ...runBenchmark('Medoo Insert', fn() => $medoo->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Medoo', 'test' => 'Update', ...runBenchmark('Medoo Update', fn() => $medoo->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// PIXIE
// ----------------------------------------------------------------------------
echo "Running Pixie benchmarks...\n";
$pixiePdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$pixiePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($pixiePdo);
seedTestData($pixiePdo, 100, 500, 20, 1000, 50);
$pixie = new PixieAdapter($pixiePdo);

$results[] = ['orm' => 'Pixie', 'test' => 'Select Simple', ...runBenchmark('Pixie Select Simple', fn() => $pixie->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Pixie', 'test' => 'JOIN 2 Tables', ...runBenchmark('Pixie JOIN 2', fn() => $pixie->selectJoin2Tables())];
$results[] = ['orm' => 'Pixie', 'test' => 'JOIN 3 Tables', ...runBenchmark('Pixie JOIN 3', fn() => $pixie->selectJoin3Tables())];
$results[] = ['orm' => 'Pixie', 'test' => 'JOIN 4 Tables', ...runBenchmark('Pixie JOIN 4', fn() => $pixie->selectJoin4Tables())];
$results[] = ['orm' => 'Pixie', 'test' => 'JOIN 5 Tables', ...runBenchmark('Pixie JOIN 5', fn() => $pixie->selectJoin5Tables())];
$results[] = ['orm' => 'Pixie', 'test' => 'Insert', ...runBenchmark('Pixie Insert', fn() => $pixie->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Pixie', 'test' => 'Update', ...runBenchmark('Pixie Update', fn() => $pixie->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// F3 (Fat-Free Framework DB)
// ----------------------------------------------------------------------------
echo "Running F3 benchmarks...\n";
$f3Pdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$f3Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($f3Pdo);
seedTestData($f3Pdo, 100, 500, 20, 1000, 50);
$f3 = new F3Adapter($f3Pdo);

$results[] = ['orm' => 'F3', 'test' => 'Select Simple', ...runBenchmark('F3 Select Simple', fn() => $f3->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'F3', 'test' => 'JOIN 2 Tables', ...runBenchmark('F3 JOIN 2', fn() => $f3->selectJoin2Tables())];
$results[] = ['orm' => 'F3', 'test' => 'JOIN 3 Tables', ...runBenchmark('F3 JOIN 3', fn() => $f3->selectJoin3Tables())];
$results[] = ['orm' => 'F3', 'test' => 'JOIN 4 Tables', ...runBenchmark('F3 JOIN 4', fn() => $f3->selectJoin4Tables())];
$results[] = ['orm' => 'F3', 'test' => 'JOIN 5 Tables', ...runBenchmark('F3 JOIN 5', fn() => $f3->selectJoin5Tables())];
$results[] = ['orm' => 'F3', 'test' => 'Insert', ...runBenchmark('F3 Insert', fn() => $f3->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'F3', 'test' => 'Update', ...runBenchmark('F3 Update', fn() => $f3->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// Q NO CACHE (RapidBase sin schema_map)
// ----------------------------------------------------------------------------
echo "Running Q (No Cache) benchmarks...\n";
$qNoCachePdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$qNoCachePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($qNoCachePdo);
seedTestData($qNoCachePdo, 100, 500, 20, 1000, 50);
$qNoCache = new QNoCacheAdapter($qNoCachePdo);

$results[] = ['orm' => 'Q-NoCache', 'test' => 'Select Simple', ...runBenchmark('Q-NoCache Select Simple', fn() => $qNoCache->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'JOIN 2 Tables', ...runBenchmark('Q-NoCache JOIN 2', fn() => $qNoCache->selectJoin2Tables())];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'JOIN 3 Tables', ...runBenchmark('Q-NoCache JOIN 3', fn() => $qNoCache->selectJoin3Tables())];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'JOIN 4 Tables', ...runBenchmark('Q-NoCache JOIN 4', fn() => $qNoCache->selectJoin4Tables())];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'JOIN 5 Tables', ...runBenchmark('Q-NoCache JOIN 5', fn() => $qNoCache->selectJoin5Tables())];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'Insert', ...runBenchmark('Q-NoCache Insert', fn() => $qNoCache->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Q-NoCache', 'test' => 'Update', ...runBenchmark('Q-NoCache Update', fn() => $qNoCache->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// Q WITH CACHE (RapidBase con schema_map)
// ----------------------------------------------------------------------------
echo "Running Q (With Cache) benchmarks...\n";
$qCachePdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$qCachePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($qCachePdo);
seedTestData($qCachePdo, 100, 500, 20, 1000, 50);
$qCache = new QCacheAdapter($qCachePdo);

$results[] = ['orm' => 'Q-Cache', 'test' => 'Select Simple', ...runBenchmark('Q-Cache Select Simple', fn() => $qCache->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Q-Cache', 'test' => 'JOIN 2 Tables', ...runBenchmark('Q-Cache JOIN 2', fn() => $qCache->selectJoin2Tables())];
$results[] = ['orm' => 'Q-Cache', 'test' => 'JOIN 3 Tables', ...runBenchmark('Q-Cache JOIN 3', fn() => $qCache->selectJoin3Tables())];
$results[] = ['orm' => 'Q-Cache', 'test' => 'JOIN 4 Tables', ...runBenchmark('Q-Cache JOIN 4', fn() => $qCache->selectJoin4Tables())];
$results[] = ['orm' => 'Q-Cache', 'test' => 'JOIN 5 Tables', ...runBenchmark('Q-Cache JOIN 5', fn() => $qCache->selectJoin5Tables())];
$results[] = ['orm' => 'Q-Cache', 'test' => 'Insert', ...runBenchmark('Q-Cache Insert', fn() => $qCache->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Q-Cache', 'test' => 'Update', ...runBenchmark('Q-Cache Update', fn() => $qCache->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// Q (RapidBase) - alias para backward compatibility
// ----------------------------------------------------------------------------
echo "Running Q benchmarks...\n";
$qPdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$qPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($qPdo);
seedTestData($qPdo, 100, 500, 20, 1000, 50);
$q = new QAdapter($qPdo);

$results[] = ['orm' => 'Q', 'test' => 'Select Simple', ...runBenchmark('Q Select Simple', fn() => $q->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Q', 'test' => 'JOIN 2 Tables', ...runBenchmark('Q JOIN 2', fn() => $q->selectJoin2Tables())];
$results[] = ['orm' => 'Q', 'test' => 'JOIN 3 Tables', ...runBenchmark('Q JOIN 3', fn() => $q->selectJoin3Tables())];
$results[] = ['orm' => 'Q', 'test' => 'JOIN 4 Tables', ...runBenchmark('Q JOIN 4', fn() => $q->selectJoin4Tables())];
$results[] = ['orm' => 'Q', 'test' => 'JOIN 5 Tables', ...runBenchmark('Q JOIN 5', fn() => $q->selectJoin5Tables())];
$results[] = ['orm' => 'Q', 'test' => 'Insert', ...runBenchmark('Q Insert', fn() => $q->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Q', 'test' => 'Update', ...runBenchmark('Q Update', fn() => $q->update('users', ['name' => 'Updated'], ['id' => 1]))];


// ----------------------------------------------------------------------------
// REDBEAN
// ----------------------------------------------------------------------------
echo "Running Redbean benchmarks...\n";
$redbeanPdo = new PDO('pgsql:host=localhost;port=5432;dbname=rapidbase_test');
$redbeanPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
createTestTables($redbeanPdo);
seedTestData($redbeanPdo, 100, 500, 20, 1000, 50);
$redbean = new RedbeanAdapter($redbeanPdo);

$results[] = ['orm' => 'Redbean', 'test' => 'Select Simple', ...runBenchmark('Redbean Select Simple', fn() => $redbean->selectSimple('users', '*', ['id' => 1]))];
$results[] = ['orm' => 'Redbean', 'test' => 'JOIN 2 Tables', ...runBenchmark('Redbean JOIN 2', fn() => $redbean->selectJoin2Tables())];
$results[] = ['orm' => 'Redbean', 'test' => 'JOIN 3 Tables', ...runBenchmark('Redbean JOIN 3', fn() => $redbean->selectJoin3Tables())];
$results[] = ['orm' => 'Redbean', 'test' => 'JOIN 4 Tables', ...runBenchmark('Redbean JOIN 4', fn() => $redbean->selectJoin4Tables())];
$results[] = ['orm' => 'Redbean', 'test' => 'JOIN 5 Tables', ...runBenchmark('Redbean JOIN 5', fn() => $redbean->selectJoin5Tables())];
$results[] = ['orm' => 'Redbean', 'test' => 'Insert', ...runBenchmark('Redbean Insert', fn() => $redbean->insert('users', ['name' => 'Test User', 'email' => 'test@test.com']))];
$results[] = ['orm' => 'Redbean', 'test' => 'Update', ...runBenchmark('Redbean Update', fn() => $redbean->update('users', 1, ['name' => 'Updated']))];


// ----------------------------------------------------------------------------
// RESULTADOS
// ----------------------------------------------------------------------------
formatResults($results);

// ----------------------------------------------------------------------------
// RESUMEN POR ORM
// ----------------------------------------------------------------------------
echo "\n\nRESUMEN POR ORM (Tiempo Total en ms):\n";
echo str_repeat('=', 80) . "\n";

$summary = [];
foreach ($results as $result) {
    if (!isset($summary[$result['orm']])) {
        $summary[$result['orm']] = 0;
    }
    $summary[$result['orm']] += $result['total_ms'];
}

arsort($summary);
foreach ($summary as $orm => $total) {
    printf("%-15s: %8.3f ms\n", $orm, $total);
}

echo str_repeat('=', 80) . "\n";
echo "\nBenchmark completado exitosamente!\n";
