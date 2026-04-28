<?php

namespace RapidBase\Core;

/**
 * Clase Ws: Variante de W con algoritmos genéticos para optimización de JOINs.
 * 
 * Filosofía:
 * 1. Hereda toda la funcionalidad de W.
 * 2. Implementa algoritmos sofisticados para encontrar el mejor orden de JOINs.
 * 3. Usa técnicas de algoritmo genético para evaluar múltiples planes de consulta.
 * 4. Mantiene compatibilidad total con la API de W.
 */
class Ws extends W
{
    // Configuración del algoritmo genético
    private const POPULATION_SIZE = 20;
    private const MAX_GENERATIONS = 50;
    private const MUTATION_RATE = 0.1;
    private const CROSSOVER_RATE = 0.7;
    
    // Cache de planes óptimos por combinación de tablas
    private static array $optimalPlans = [];
    
    // Métricas de optimización
    private static array $optimizationStats = [
        'evaluations' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];
    
    /**
     * Obtiene estadísticas de optimización.
     */
    public static function getOptimizationStats(): array
    {
        return self::$optimizationStats;
    }
    
    /**
     * Limpia el cache de planes óptimos.
     */
    public static function clearPlanCache(): void
    {
        self::$optimalPlans = [];
    }
    
    /**
     * Punto de entrada único con optimización de JOINs.
     * 
     * @param string|array $table Si es string: SQL crudo. Si es array: Lista de tablas para optimizar.
     * @param array $filter Filtro base para el WHERE.
     * @return self Retorna una instancia nueva con el orden óptimo de tablas.
     */
    public static function table($table, array $filter = []): self
    {
        // Solo optimizamos si es un array de tablas
        if (is_array($table) && count($table) > 1) {
            $optimizedTables = self::optimizeJoinOrder($table);
            $table = $optimizedTables;
        }
        
        return parent::table($table, $filter);
    }
    
    /**
     * Optimiza el orden de JOINs usando un algoritmo genético simplificado.
     * 
     * @param array $tables Lista de tablas a unir.
     * @return array Lista de tablas en orden óptimo.
     */
    private static function optimizeJoinOrder(array $tables): array
    {
        $cacheKey = md5(serialize(sort($tables)));
        
        // Verificar cache primero
        if (isset(self::$optimalPlans[$cacheKey])) {
            self::$optimizationStats['cache_hits']++;
            return self::$optimalPlans[$cacheKey];
        }
        
        self::$optimizationStats['cache_misses']++;
        
        // Si hay 2 o menos tablas, no vale la pena optimizar
        if (count($tables) <= 2) {
            self::$optimalPlans[$cacheKey] = $tables;
            return $tables;
        }
        
        // Generar población inicial
        $population = self::generateInitialPopulation($tables);
        
        // Evaluar y evolucionar por varias generaciones
        $bestPlan = $tables;
        $bestScore = PHP_INT_MAX;
        
        for ($gen = 0; $gen < self::MAX_GENERATIONS; $gen++) {
            // Evaluar cada individuo
            $scores = [];
            foreach ($population as $individual) {
                $score = self::evaluatePlan($individual);
                $scores[] = $score;
                
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestPlan = $individual;
                }
            }
            
            self::$optimizationStats['evaluations'] += count($population);
            
            // Crear nueva generación
            $population = self::evolve($population, $scores);
        }
        
        // Guardar en cache
        self::$optimalPlans[$cacheKey] = $bestPlan;
        
        return $bestPlan;
    }
    
    /**
     * Genera la población inicial permutando las tablas.
     */
    private static function generateInitialPopulation(array $tables): array
    {
        $population = [];
        $base = $tables;
        
        for ($i = 0; $i < self::POPULATION_SIZE; $i++) {
            shuffle($base);
            $population[] = $base;
        }
        
        // Asegurar que el orden original esté incluido
        if (!in_array($tables, $population, true)) {
            $population[0] = $tables;
        }
        
        return $population;
    }
    
    /**
     * Evalúa un plan de JOINs retornando un score (menor es mejor).
     * El score considera:
     * - Cantidad de JOINs necesarios
     * - Complejidad estimada de las condiciones de JOIN
     * - Orden de tablas según relaciones conocidas
     */
    private static function evaluatePlan(array $plan): float
    {
        // Score base: longitud del plan
        $score = count($plan);
        
        // Penalizar si no hay relaciones conocidas entre tablas consecutivas
        // Esto podría mejorarse cargando un mapa de relaciones real
        for ($i = 0; $i < count($plan) - 1; $i++) {
            $table1 = self::extractTableName($plan[$i]);
            $table2 = self::extractTableName($plan[$i + 1]);
            
            // Si las tablas no tienen relación conocida, penalizar
            if (!self::hasKnownRelation($table1, $table2)) {
                $score += 2.0;
            }
        }
        
        // Bonus por empezar con la tabla más pequeña (estimado)
        // En una implementación real, esto vendría de estadísticas de la DB
        $firstTable = self::extractTableName($plan[0]);
        if (self::isSmallTable($firstTable)) {
            $score -= 1.0;
        }
        
        return $score;
    }
    
    /**
     * Evoluciona la población usando crossover y mutación.
     */
    private static function evolve(array $population, array $scores): array
    {
        $newPopulation = [];
        
        // Ordenar por score (mejores primeros)
        array_multisort($scores, SORT_ASC, $population);
        
        // Elitismo: mantener los 2 mejores
        $newPopulation[] = $population[0];
        $newPopulation[] = $population[1];
        
        // Generar el resto mediante crossover
        while (count($newPopulation) < self::POPULATION_SIZE) {
            // Selección por torneo
            $parent1 = self::tournamentSelect($population, $scores);
            $parent2 = self::tournamentSelect($population, $scores);
            
            // Crossover
            if (mt_rand() / mt_getrandmax() < self::CROSSOVER_RATE) {
                $children = self::crossover($parent1, $parent2);
                $newPopulation[] = $children[0];
                if (count($newPopulation) < self::POPULATION_SIZE) {
                    $newPopulation[] = $children[1];
                }
            } else {
                $newPopulation[] = $parent1;
            }
        }
        
        // Mutación
        for ($i = 0; $i < count($newPopulation); $i++) {
            if (mt_rand() / mt_getrandmax() < self::MUTATION_RATE) {
                $newPopulation[$i] = self::mutate($newPopulation[$i]);
            }
        }
        
        return $newPopulation;
    }
    
    /**
     * Selección por torneo.
     */
    private static function tournamentSelect(array $population, array $scores): array
    {
        $tournamentSize = 3;
        $bestIdx = mt_rand(0, count($population) - 1);
        $bestScore = $scores[$bestIdx];
        
        for ($i = 1; $i < $tournamentSize; $i++) {
            $idx = mt_rand(0, count($population) - 1);
            if ($scores[$idx] < $bestScore) {
                $bestScore = $scores[$idx];
                $bestIdx = $idx;
            }
        }
        
        return $population[$bestIdx];
    }
    
    /**
     * Crossover ordenado para permutaciones.
     */
    private static function crossover(array $parent1, array $parent2): array
    {
        $size = count($parent1);
        $point1 = mt_rand(0, $size - 2);
        $point2 = mt_rand($point1 + 1, $size - 1);
        
        // Copiar sección del padre1
        $child = array_fill(0, $size, null);
        for ($i = $point1; $i <= $point2; $i++) {
            $child[$i] = $parent1[$i];
        }
        
        // Rellenar con elementos del padre2 en orden
        $j = 0;
        for ($i = 0; $i < $size; $i++) {
            if ($child[$i] === null) {
                while (in_array($parent2[$j], $child, true)) {
                    $j++;
                }
                $child[$i] = $parent2[$j];
                $j++;
            }
        }
        
        // Segundo hijo (simétrico)
        $child2 = array_fill(0, $size, null);
        for ($i = $point1; $i <= $point2; $i++) {
            $child2[$i] = $parent2[$i];
        }
        
        $j = 0;
        for ($i = 0; $i < $size; $i++) {
            if ($child2[$i] === null) {
                while (in_array($parent1[$j], $child2, true)) {
                    $j++;
                }
                $child2[$i] = $parent1[$j];
                $j++;
            }
        }
        
        return [$child, $child2];
    }
    
    /**
     * Mutación por intercambio.
     */
    private static function mutate(array $individual): array
    {
        $size = count($individual);
        if ($size < 2) {
            return $individual;
        }
        
        $idx1 = mt_rand(0, $size - 1);
        $idx2 = mt_rand(0, $size - 1);
        
        $temp = $individual[$idx1];
        $individual[$idx1] = $individual[$idx2];
        $individual[$idx2] = $temp;
        
        return $individual;
    }
    
    /**
     * Extrae el nombre base de una tabla (sin alias).
     */
    private static function extractTableName(string $tableSpec): string
    {
        $parts = preg_split('/\s+AS\s+/i', trim($tableSpec));
        return trim($parts[0]);
    }
    
    /**
     * Verifica si existe una relación conocida entre dos tablas.
     * En una implementación real, esto consultaría el mapa de relaciones de SQL.
     */
    private static function hasKnownRelation(string $table1, string $table2): bool
    {
        // Placeholder: en producción, consultar SQL::$relMap
        // Por ahora, asumimos que todas las tablas pueden tener relación
        return true;
    }
    
    /**
     * Determina si una tabla es "pequeña" según estadísticas.
     * En una implementación real, esto consultaría estadísticas de la DB.
     */
    private static function isSmallTable(string $table): bool
    {
        // Placeholder: tables comunes que suelen ser pequeñas
        $smallTables = ['users', 'roles', 'permissions', 'settings', 'config'];
        return in_array(strtolower($table), $smallTables);
    }
}
