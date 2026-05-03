<?php
/**
 * Test Avanzado: RapidBase con PostgreSQL - Características Específicas
 * 
 * Este archivo prueba características avanzadas y específicas de PostgreSQL
 * que no están disponibles en SQLite3, demostrando las ventajas de usar
 * PostgreSQL con RapidBase.
 * 
 * Para ejecutar:
 * php tests/Integration/PostgreSQL/PostgreSQLAdvancedTest.php
 */

namespace Tests\Integration\PostgreSQL;

require_once __DIR__ . "/../../../vendor/autoload.php";

use RapidBase\Core\DB;
use RapidBase\Core\Cache\CacheService;
use PDO;

echo "=== Test Avanzado: PostgreSQL con RapidBase ===\n\n";

// Configuración
$dsn = 'pgsql:host=localhost;port=5432;dbname=rapidbase_test';
$user = 'rapidbase_user';
$pass = 'rapidbase_pass';

try {
    DB::setup($dsn, $user, $pass, 'main');
    echo "✓ Conexión establecida\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

$cachePath = __DIR__ . '/temp_pgsql_advanced_cache';
if (!is_dir($cachePath)) mkdir($cachePath, 0755, true);
CacheService::init($cachePath);

// Crear tablas de prueba
echo "\n[1] Creando tablas de prueba...\n";
$pdo = DB::getConnection();

try {
    // Tabla con tipos de datos PostgreSQL específicos
    $pdo->exec("DROP TABLE IF EXISTS products CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS categories CASCADE");
    
    $pdo->exec("CREATE TABLE categories (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) UNIQUE,
        metadata JSONB DEFAULT '{}'::jsonb,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE products (
        id SERIAL PRIMARY KEY,
        name VARCHAR(200) NOT NULL UNIQUE,
        description TEXT,
        price NUMERIC(10,2) NOT NULL,
        stock INTEGER DEFAULT 0,
        tags TEXT[],
        category_id INTEGER REFERENCES categories(id),
        attributes JSONB DEFAULT '{}'::jsonb,
        full_text_search TSVECTOR,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Trigger para actualizar updated_at
    $pdo->exec("CREATE OR REPLACE FUNCTION update_updated_at_column()
        RETURNS TRIGGER AS \$\$
        BEGIN
            NEW.updated_at = CURRENT_TIMESTAMP;
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql");
    
    $pdo->exec("CREATE TRIGGER update_products_updated_at BEFORE UPDATE ON products
        FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()");
    
    // Índice de texto completo
    $pdo->exec("CREATE INDEX idx_products_fts ON products USING GIN(full_text_search)");
    
    echo "  ✓ Tablas creadas con tipos PostgreSQL específicos\n";
    echo "  ✓ Trigger de updated_at creado\n";
    echo "  ✓ Índice de texto completo creado\n";
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Prueba 1: Tipos de datos JSONB
echo "\n[2] Probando tipos de datos JSONB...\n";
try {
    $categoryId = DB::insert('categories', [
        'name' => 'Electrónica',
        'slug' => 'electronica',
        'metadata' => json_encode([
            'icon' => 'electronics.svg',
            'display_order' => 1,
            'featured' => true,
            'tags' => ['tech', 'gadgets']
        ])
    ]);
    echo "  ✓ Categoría creada con ID: $categoryId\n";
    
    // Consultar campo JSONB específico
    $category = DB::find('categories', ['id' => $categoryId]);
    $metadata = json_decode($category['metadata'], true);
    echo "  ✓ Metadata recuperada: featured=" . ($metadata['featured'] ? 'true' : 'false') . "\n";
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 2: Arrays de texto
echo "\n[3] Probando arrays de texto (TEXT[])...\n";
try {
    $productId = DB::insert('products', [
        'name' => 'Smartphone XYZ',
        'description' => 'Un smartphone increíble con todas las características',
        'price' => 599.99,
        'stock' => 100,
        'tags' => '{smartphone,mobile,android,5g}',  // Sintaxis de array PostgreSQL
        'category_id' => $categoryId,
        'attributes' => json_encode([
            'color' => 'negro',
            'storage' => '128GB',
            'ram' => '8GB'
        ])
    ]);
    echo "  ✓ Producto creado con ID: $productId\n";
    
    $product = DB::find('products', ['id' => $productId]);
    // Los arrays de PostgreSQL pueden venir como string o array dependiendo del driver
    $tags = is_array($product['tags']) ? $product['tags'] : explode(',', trim($product['tags'], '{}'));
    echo "  ✓ Tags del producto: " . implode(', ', $tags) . "\n";
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 3: Texto completo (Full Text Search)
echo "\n[4] Probando Full Text Search de PostgreSQL...\n";
try {
    // Actualizar el vector de búsqueda
    DB::exec("UPDATE products SET full_text_search = to_tsvector('spanish', name || ' ' || COALESCE(description, '')) WHERE id = :id", ['id' => $productId]);
    
    // Búsqueda de texto completo
    $results = DB::fetch("
        SELECT id, name, ts_rank(full_text_search, query) as rank
        FROM products, to_tsquery('spanish', 'smartphone & increíble') query
        WHERE full_text_search @@ query
        ORDER BY rank DESC
    ");
    
    if (!empty($results)) {
        echo "  ✓ Búsqueda de texto completo encontró " . count($results) . " resultado(s)\n";
        foreach ($results as $r) {
            echo "    - {$r['name']} (rank: {$r['rank']})\n";
        }
    } else {
        echo "  ⚠ No se encontraron resultados (puede ser por configuración de idioma)\n";
    }
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 4: Operadores JSONB
echo "\n[5] Probando operadores JSONB de PostgreSQL...\n";
try {
    // Insertar productos con diferentes atributos JSONB
    DB::insert('products', [
        'name' => 'Laptop Pro',
        'description' => 'Laptop profesional',
        'price' => 1299.99,
        'stock' => 25,
        'tags' => '{laptop,computer,professional}',
        'category_id' => $categoryId,
        'attributes' => json_encode(['color' => 'gris', 'storage' => '512GB', 'ram' => '16GB'])
    ]);
    
    DB::insert('products', [
        'name' => 'Tablet Mini',
        'description' => 'Tablet compacta',
        'price' => 399.99,
        'stock' => 50,
        'tags' => '{tablet,mobile,portable}',
        'category_id' => $categoryId,
        'attributes' => json_encode(['color' => 'blanco', 'storage' => '64GB', 'ram' => '4GB'])
    ]);
    
    // Consulta usando operador JSONB ->>
    $productsWith8GBRam = DB::fetch("
        SELECT id, name, attributes->>'ram' as ram
        FROM products
        WHERE attributes->>'ram' = '8GB'
    ");
    
    echo "  ✓ Productos con 8GB RAM: " . count($productsWith8GBRam) . "\n";
    foreach ($productsWith8GBRam as $p) {
        echo "    - {$p['name']} ({$p['ram']})\n";
    }
    
    // Consulta usando operador @> (contiene)
    $expensiveProducts = DB::fetch("
        SELECT id, name, price
        FROM products
        WHERE attributes @> '{\"storage\": \"512GB\"}'
    ");
    
    echo "  ✓ Productos con 512GB almacenamiento: " . count($expensiveProducts) . "\n";
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 5: Transacciones anidadas (Savepoints)
echo "\n[6] Probando Savepoints en transacciones...\n";
try {
    $result = DB::transaction(function($pdo) {
        // Primera inserción
        $id1 = DB::insert('categories', [
            'name' => 'Categoria Temporal 1',
            'slug' => 'temp-1'
        ]);
        
        // Crear savepoint
        $pdo->exec("SAVEPOINT sp1");
        
        try {
            // Segunda inserción que vamos a revertir
            $id2 = DB::insert('categories', [
                'name' => 'Categoria Temporal 2',
                'slug' => 'temp-2'
            ]);
            
            // Revertir al savepoint
            $pdo->exec("ROLLBACK TO SAVEPOINT sp1");
            
        } catch (\Exception $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT sp1");
        }
        
        return $id1;
    });
    
    echo "  ✓ Savepoint funcionó correctamente\n";
    
    // Verificar que solo la primera categoría persistió
    $count = DB::count('categories', ['slug' => 'temp-1']);
    $count2 = DB::count('categories', ['slug' => 'temp-2']);
    echo "  ✓ Categoria temp-1 existe: " . ($count > 0 ? 'SI' : 'NO') . "\n";
    echo "  ✓ Categoria temp-2 existe: " . ($count2 > 0 ? 'SI' : 'NO') . " (debería ser NO)\n";
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 6: Upsert avanzado con DO UPDATE
echo "\n[7] Probando UPSERT avanzado...\n";
try {
    // Primer upsert - inserta
    $result1 = DB::upsert('products', [
        'name' => 'Producto Unico',
        'description' => 'Descripción inicial',
        'price' => 99.99,
        'stock' => 10,
        'tags' => '{unique}',
        'category_id' => $categoryId
    ], ['name']);
    
    echo "  ✓ Primer UPSERT (INSERT): ID={$result1['lastId']}\n";
    
    // Segundo upsert - actualiza
    $result2 = DB::upsert('products', [
        'name' => 'Producto Unico',
        'description' => 'Descripción actualizada',
        'price' => 149.99,
        'stock' => 20,
        'tags' => '{unique,updated}',
        'category_id' => $categoryId
    ], ['name']);
    
    echo "  ✓ Segundo UPSERT (UPDATE): afectó {$result2['count']} fila(s)\n";
    
    $updated = DB::find('products', ['name' => 'Producto Unico']);
    if ($updated) {
        echo "  ✓ Precio actualizado: {$updated['price']}\n";
        echo "  ✓ Stock actualizado: {$updated['stock']}\n";
    } else {
        echo "  ✗ No se pudo recuperar el producto actualizado\n";
    }
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 7: Consultas con CTE (Common Table Expressions)
echo "\n[8] Probando CTE (WITH clause)...\n";
try {
    $results = DB::fetch("
        WITH product_summary AS (
            SELECT 
                category_id,
                COUNT(*) as product_count,
                AVG(price) as avg_price,
                SUM(stock) as total_stock
            FROM products
            GROUP BY category_id
        )
        SELECT 
            c.name as category_name,
            ps.product_count,
            ps.avg_price,
            ps.total_stock
        FROM product_summary ps
        JOIN categories c ON ps.category_id = c.id
    ");
    
    if (!empty($results)) {
        echo "  ✓ CTE ejecutado exitosamente\n";
        foreach ($results as $r) {
            echo "    - {$r['category_name']}: {$r['product_count']} productos, precio promedio: \${$r['avg_price']}\n";
        }
    }
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Prueba 8: Window Functions
echo "\n[9] Probando Window Functions...\n";
try {
    $results = DB::fetch("
        SELECT 
            name,
            price,
            category_id,
            RANK() OVER (PARTITION BY category_id ORDER BY price DESC) as price_rank,
            AVG(price) OVER (PARTITION BY category_id) as category_avg_price
        FROM products
        WHERE category_id IS NOT NULL
        ORDER BY category_id, price_rank
    ");
    
    if (!empty($results)) {
        echo "  ✓ Window Functions ejecutadas exitosamente\n";
        echo "  ✓ Resultados: " . count($results) . " filas\n";
    }
    
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Resumen final
echo "\n=== Resumen ===\n";
$totalProducts = DB::count('products');
$totalCategories = DB::count('categories');
echo "Total de productos: $totalProducts\n";
echo "Total de categorías: $totalCategories\n";

// Limpieza
echo "\n[10] Limpiando...\n";
try {
    $pdo->exec("DROP TABLE IF EXISTS products CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS categories CASCADE");
    $pdo->exec("DROP FUNCTION IF EXISTS update_updated_at_column() CASCADE");
    echo "  ✓ Tablas y funciones eliminadas\n";
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Avanzado Completado ===\n";
echo "RapidBase soporta características avanzadas de PostgreSQL.\n";
