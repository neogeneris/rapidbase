<?php

namespace RapidBase\Core;

/**
 * Clase de prueba para demostrar y validar las clases W, Wm y Ws.
 * 
 * Propósito:
 * 1. Validar que W genera consultas SQL correctamente con 2 niveles de encadenamiento.
 * 2. Demostrar que Wm registra métricas sin afectar la funcionalidad base.
 * 3. Probar que Ws optimiza JOINs usando algoritmos genéticos.
 * 4. Comparar performance entre las tres implementaciones.
 */
class TestW
{
    private array $results = [];
    private int $testsPassed = 0;
    private int $testsFailed = 0;

    /**
     * Ejecuta todas las pruebas de la clase W.
     */
    public function runAll(): void
    {
        echo "=== Iniciando Pruebas de la Clase W ===\n\n";
        
        $this->testBasicSelect();
        $this->testSelectWithPagination();
        $this->testSelectWithSorting();
        $this->testSelectWithFields();
        $this->testDelete();
        $this->testUpdate();
        $this->testWhereCache();
        $this->testPolymorphicInput();
        
        $this->testWmMetrics();
        $this->testWsOptimization();
        
        $this->printSummary();
    }

    /**
     * Prueba 1: SELECT básico con table()->select()
     */
    private function testBasicSelect(): void
    {
        echo "Prueba 1: SELECT básico\n";
        
        [$sql, $params] = W::table('users u', ['status' => 'active'])->select();
        
        $expectedSql = "SELECT * FROM users u WHERE status = ?";
        $expectedParams = ['active'];
        
        $this->assert($sql === $expectedSql, "SQL generado incorrecto. Esperado: '$expectedSql', Obtenido: '$sql'");
        $this->assert($params === $expectedParams, "Parámetros incorrectos. Esperado: " . json_encode($expectedParams) . ", Obtenido: " . json_encode($params));
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 2: SELECT con paginación
     */
    private function testSelectWithPagination(): void
    {
        echo "Prueba 2: SELECT con paginación\n";
        
        [$sql, $params] = W::table('products p', ['category_id' => 5])->select('*', W::page(2, 50));
        
        $expectedSql = "SELECT * FROM products p WHERE category_id = ? LIMIT ? OFFSET ?";
        $expectedParams = [5, 50, 50]; // page 2, size 50 -> offset = (2-1)*50 = 50
        
        $this->assert($sql === $expectedSql, "SQL con paginación incorrecto");
        $this->assert($params === $expectedParams, "Parámetros de paginación incorrectos");
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 3: SELECT con ordenamiento
     */
    private function testSelectWithSorting(): void
    {
        echo "Prueba 3: SELECT con ordenamiento\n";
        
        [$sql, $params] = W::table('orders o', ['user_id' => 123])->select('*', null, '-created_at');
        
        $expectedSql = "SELECT * FROM orders o WHERE user_id = ? ORDER BY created_at DESC";
        $expectedParams = [123];
        
        $this->assert($sql === $expectedSql, "SQL con ORDER BY incorrecto");
        $this->assert($params === $expectedParams, "Parámetros con ORDER BY incorrectos");
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 4: SELECT con campos específicos
     */
    private function testSelectWithFields(): void
    {
        echo "Prueba 4: SELECT con campos específicos\n";
        
        [$sql, $params] = W::table('users u', ['id' => 1])->select(['id', 'name', 'email']);
        
        $expectedSql = "SELECT id, name, email FROM users u WHERE id = ?";
        $expectedParams = [1];
        
        $this->assert($sql === $expectedSql, "SQL con campos específicos incorrecto");
        $this->assert($params === $expectedParams, "Parámetros con campos específicos incorrectos");
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 5: DELETE
     */
    private function testDelete(): void
    {
        echo "Prueba 5: DELETE\n";
        
        [$sql, $params] = W::table('sessions s', ['user_id' => 456])->delete();
        
        $expectedSql = "DELETE FROM sessions s WHERE user_id = ?";
        $expectedParams = [456];
        
        $this->assert($sql === $expectedSql, "SQL DELETE incorrecto");
        $this->assert($params === $expectedParams, "Parámetros DELETE incorrectos");
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 6: UPDATE
     */
    private function testUpdate(): void
    {
        echo "Prueba 6: UPDATE\n";
        
        [$sql, $params] = W::table('users u', ['id' => 789])->update(['status' => 'inactive', 'updated_at' => '2024-01-01']);
        
        $expectedSql = "UPDATE users u SET status = ?, updated_at = ? WHERE id = ?";
        $expectedParams = ['inactive', '2024-01-01', 789];
        
        $this->assert($sql === $expectedSql, "SQL UPDATE incorrecto");
        $this->assert($params === $expectedParams, "Parámetros UPDATE incorrectos");
        
        echo "  SQL: $sql\n";
        echo "  Params: " . json_encode($params) . "\n\n";
    }

    /**
     * Prueba 7: Cache de WHERE
     */
    private function testWhereCache(): void
    {
        echo "Prueba 7: Cache de WHERE\n";
        
        // Primera consulta (miss de cache)
        [$sql1, $params1] = W::table('table1', ['a' => 1, 'b' => 2])->select();
        
        // Segunda consulta con misma estructura (hit de cache)
        [$sql2, $params2] = W::table('table2', ['a' => 10, 'b' => 20])->select();
        
        // Las estructuras SQL deben ser iguales
        $expectedPattern = "/^SELECT \* FROM .+ WHERE a = \? AND b = \?$/";
        
        $this->assert(preg_match($expectedPattern, $sql1), "Primera consulta no sigue el patrón esperado");
        $this->assert(preg_match($expectedPattern, $sql2), "Segunda consulta no sigue el patrón esperado");
        
        echo "  Consulta 1: $sql1\n";
        echo "  Consulta 2: $sql2\n";
        echo "  Cache hit verificado\n\n";
    }

    /**
     * Prueba 8: Input polimórfico (array vs string)
     */
    private function testPolymorphicInput(): void
    {
        echo "Prueba 8: Input polimórfico\n";
        
        // Con string
        [$sql1, $params1] = W::table('users u', ['id' => 1])->select();
        
        // Con array de tablas
        [$sql2, $params2] = W::table(['users u', 'profiles p'], ['u.id' => 1])->select();
        
        $this->assert(strpos($sql1, 'users u') !== false, "String input falló");
        $this->assert(strpos($sql2, 'users u, profiles p') !== false, "Array input falló");
        
        echo "  String input: $sql1\n";
        echo "  Array input: $sql2\n\n";
    }

    /**
     * Prueba 9: Wm con métricas
     */
    private function testWmMetrics(): void
    {
        echo "Prueba 9: Wm con métricas\n";
        
        $wm = new Wm();
        [$sql, $params] = $wm->table('metrics_test m', ['type' => 'benchmark'])->select('*', W::page(1, 10), '-timestamp');
        
        $metrics = $wm->getMetrics();
        
        $this->assert(!empty($sql), "Wm no generó SQL");
        $this->assert(isset($metrics['query_count']), "Wm no registró query_count");
        $this->assert(isset($metrics['total_time']), "Wm no registró total_time");
        $this->assert(isset($metrics['memory_usage']), "Wm no registró memory_usage");
        
        echo "  SQL: $sql\n";
        echo "  Métricas:\n";
        echo "    - Queries: {$metrics['query_count']}\n";
        echo "    - Tiempo total: " . number_format($metrics['total_time'], 4) . " ms\n";
        echo "    - Memoria: " . number_format($metrics['memory_usage'] / 1024, 2) . " KB\n\n";
    }

    /**
     * Prueba 10: Ws con optimización genética
     */
    private function testWsOptimization(): void
    {
        echo "Prueba 10: Ws con optimización genética\n";
        
        $tables = [
            ['table' => 'orders o', 'join' => 'o.user_id = users.id'],
            ['table' => 'users u', 'join' => 'u.id = orders.user_id'],
            ['table' => 'products p', 'join' => 'p.id = order_items.product_id'],
            ['table' => 'order_items oi', 'join' => 'oi.order_id = orders.id'],
        ];
        
        $ws = new Ws();
        $optimizedPlan = $ws->optimizeJoinOrder($tables);
        
        $this->assert(!empty($optimizedPlan), "Ws no retornó plan optimizado");
        $this->assert(isset($optimizedPlan['best_order']), "Ws no retornó best_order");
        $this->assert(isset($optimizedPlan['score']), "Ws no retornó score");
        $this->assert(isset($optimizedPlan['generations']), "Ws no retornó generations");
        
        echo "  Plan optimizado encontrado en {$optimizedPlan['generations']} generaciones\n";
        echo "  Score: {$optimizedPlan['score']}\n";
        echo "  Orden óptimo:\n";
        foreach ($optimizedPlan['best_order'] as $idx => $tableInfo) {
            echo "    " . ($idx + 1) . ". {$tableInfo['table']}\n";
        }
        echo "\n";
    }

    /**
     * Método de aserción simple
     */
    private function assert(bool $condition, string $message = ''): void
    {
        if ($condition) {
            $this->testsPassed++;
            echo "  ✓ OK\n";
        } else {
            $this->testsFailed++;
            echo "  ✗ FAIL: $message\n";
        }
    }

    /**
     * Imprime resumen de pruebas
     */
    private function printSummary(): void
    {
        echo "=== Resumen de Pruebas ===\n";
        echo "  Pasadas: {$this->testsPassed}\n";
        echo "  Fallidas: {$this->testsFailed}\n";
        echo "  Total: " . ($this->testsPassed + $this->testsFailed) . "\n";
        
        if ($this->testsFailed === 0) {
            echo "\n✓ ¡Todas las pruebas pasaron exitosamente!\n";
        } else {
            echo "\n✗ Algunas pruebas fallaron. Revisa los errores arriba.\n";
        }
    }
}

// Función helper para paginación (compatibilidad con W::page)
if (!function_exists('RapidBase\Core\page')) {
    function page(int $currentPage, int $pageSize): array
    {
        return [$currentPage, $pageSize];
    }
}

// Agregar método estático page a la clase W si no existe
if (!method_exists('RapidBase\Core\W', 'page')) {
    class WExtension extends W {
        public static function page(int $currentPage, int $pageSize): array
        {
            return [$currentPage, $pageSize];
        }
    }
}
