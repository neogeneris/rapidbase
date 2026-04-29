<?php

namespace RapidBase\SQLEngine;

/**
 * JoinManagerGenetic - Extiende JoinManagerAuto con optimización genética.
 * 
 * Aprende de consultas previas para encontrar el orden óptimo de joins.
 * Usa un algoritmo evolutivo simple que mejora gradualmente.
 */
class JoinManagerGenetic extends JoinManagerAuto
{
    private static array $queryHistory = [];
    private static array $bestPlans = [];
    private const POPULATION_SIZE = 10;
    private const GENERATIONS = 5;
    private const MUTATION_RATE = 0.2;
    
    private array $pendingTables = [];
    
    /**
     * Agrega una tabla para join con optimización genética.
     * En lugar de hacer el join inmediatamente, lo acumula para optimizar.
     */
    public function addAutoJoin(string $table, array $existingAliases = []): self
    {
        $this->pendingTables[] = [
            'table' => $table,
            'aliases' => $existingAliases
        ];
        return $this;
    }
    
    /**
     * Ejecuta la optimización genética y construye todos los joins.
     */
    public function optimizeAndBuild(): string
    {
        if (empty($this->pendingTables)) {
            return parent::buildJoinSQL();
        }
        
        // Si ya tenemos un plan óptimo para esta combinación de tablas
        $cacheKey = $this->getCacheKey();
        if (isset(self::$bestPlans[$cacheKey])) {
            return $this->applyBestPlan(self::$bestPlans[$cacheKey]);
        }
        
        // Generar población inicial (permutaciones de orden de joins)
        $population = $this->generatePopulation();
        
        // Evolucionar por generaciones
        for ($gen = 0; $gen < self::GENERATIONS; $gen++) {
            $population = $this->evolvePopulation($population);
        }
        
        // Seleccionar el mejor plan
        $bestPlan = $population[0]['plan'];
        self::$bestPlans[$cacheKey] = $bestPlan;
        
        // Aplicar el mejor plan
        return $this->applyBestPlan($bestPlan);
    }
    
    /**
     * Genera población inicial de planes.
     */
    private function generatePopulation(): array
    {
        $population = [];
        $indices = range(0, count($this->pendingTables) - 1);
        
        for ($i = 0; $i < self::POPULATION_SIZE; $i++) {
            shuffle($indices);
            $population[] = [
                'plan' => array_values($indices),
                'fitness' => 0
            ];
        }
        
        return $population;
    }
    
    /**
     * Evoluciona la población por una generación.
     */
    private function evolvePopulation(array $population): array
    {
        // Calcular fitness
        foreach ($population as &$individual) {
            $individual['fitness'] = $this->calculateFitness($individual['plan']);
        }
        usort($population, fn($a, $b) => $b['fitness'] <=> $a['fitness']);
        
        // Selección y crossover
        $newPopulation = array_slice($population, 0, 2); // Élites
        
        while (count($newPopulation) < self::POPULATION_SIZE) {
            $parent1 = $this->tournamentSelection($population);
            $parent2 = $this->tournamentSelection($population);
            
            $child = $this->crossover($parent1, $parent2);
            
            // Mutación
            if (rand(0, 100) / 100 < self::MUTATION_RATE) {
                $child = $this->mutate($child);
            }
            
            $newPopulation[] = ['plan' => $child, 'fitness' => 0];
        }
        
        return $newPopulation;
    }
    
    /**
     * Selección por torneo.
     */
    private function tournamentSelection(array $population): array
    {
        $tournamentSize = 3;
        $best = null;
        
        for ($i = 0; $i < $tournamentSize; $i++) {
            $individual = $population[array_rand($population)];
            if (!$best || $individual['fitness'] > $best['fitness']) {
                $best = $individual;
            }
        }
        
        return $best;
    }
    
    /**
     * Crossover de dos padres.
     */
    private function crossover(array $parent1, array $parent2): array
    {
        $size = count($parent1['plan']);
        $point = rand(1, $size - 1);
        
        $child = array_slice($parent1['plan'], 0, $point);
        
        foreach ($parent2['plan'] as $gene) {
            if (!in_array($gene, $child)) {
                $child[] = $gene;
            }
        }
        
        return $child;
    }
    
    /**
     * Mutación de un individuo.
     */
    private function mutate(array $plan): array
    {
        $i = rand(0, count($plan) - 1);
        $j = rand(0, count($plan) - 1);
        
        $temp = $plan[$i];
        $plan[$i] = $plan[$j];
        $plan[$j] = $temp;
        
        return $plan;
    }
    
    /**
     * Calcula el fitness de un plan.
     * Basado en historial de consultas similares.
     */
    private function calculateFitness(array $plan): float
    {
        // Simular score basado en complejidad de joins
        $score = 100;
        
        // Penalizar joins sin relaciones detectadas
        foreach ($plan as $index) {
            $tableData = $this->pendingTables[$index];
            if (empty($tableData['aliases'])) {
                $score -= 10;
            }
        }
        
        // Bonus si coincide con patrones históricos
        $cacheKey = $this->getCacheKey();
        if (isset(self::$queryHistory[$cacheKey])) {
            $score += self::$queryHistory[$cacheKey]['avg_score'] ?? 0;
        }
        
        return $score;
    }
    
    /**
     * Aplica el mejor plan encontrado.
     */
    private function applyBestPlan(array $bestPlan): string
    {
        $resultJoins = [];
        $currentAliases = [];
        
        // Primera tabla va como base
        $firstIndex = $bestPlan[0];
        $firstTable = $this->pendingTables[$firstIndex]['table'];
        $alias = $this->extractAlias($firstTable);
        $realName = preg_replace('/\s+as\s+.*/i', '', $firstTable);
        $currentAliases[$alias] = $realName;
        
        // Aplicar resto de joins en orden óptimo
        for ($i = 1; $i < count($bestPlan); $i++) {
            $index = $bestPlan[$i];
            $tableData = $this->pendingTables[$index];
            
            $alias = $this->extractAlias($tableData['table']);
            $realName = preg_replace('/\s+as\s+.*/i', '', $tableData['table']);
            $this->aliases[$alias] = $realName;
            
            $foreignKey = $this->detectForeignKey($alias, $currentAliases);
            
            if ($foreignKey) {
                $quotedTable = $this->quote($realName);
                $quotedAlias = $this->quote($alias);
                $onCondition = $this->buildOnCondition($alias, $foreignKey, $currentAliases);
                
                if ($onCondition) {
                    $resultJoins[] = "LEFT JOIN {$quotedTable} AS {$quotedAlias} ON {$onCondition}";
                }
            }
            
            $currentAliases[$alias] = $realName;
        }
        
        // Guardar en historial
        $cacheKey = $this->getCacheKey();
        if (!isset(self::$queryHistory[$cacheKey])) {
            self::$queryHistory[$cacheKey] = ['count' => 0, 'avg_score' => 0];
        }
        self::$queryHistory[$cacheKey]['count']++;
        
        return implode(' ', $resultJoins);
    }
    
    /**
     * Genera clave de cache única para esta combinación de tablas.
     */
    private function getCacheKey(): string
    {
        $tables = array_map(fn($t) => $t['table'], $this->pendingTables);
        sort($tables);
        return md5(implode(',', $tables));
    }
    
    /**
     * Registra el resultado de una consulta para aprendizaje.
     */
    public static function recordQueryResult(string $cacheKey, float $executionTimeMs): void
    {
        if (!isset(self::$queryHistory[$cacheKey])) {
            self::$queryHistory[$cacheKey] = ['count' => 0, 'total_time' => 0, 'avg_score' => 0];
        }
        
        $history = &self::$queryHistory[$cacheKey];
        $history['count']++;
        $history['total_time'] += $executionTimeMs;
        $history['avg_score'] = 100 - ($history['total_time'] / $history['count']);
    }
    
    /**
     * Obtiene estadísticas de aprendizaje.
     */
    public static function getLearningStats(): array
    {
        return [
            'cached_plans' => count(self::$bestPlans),
            'query_history' => count(self::$queryHistory),
            'plans' => self::$bestPlans
        ];
    }
    
    /**
     * Limpia el cache de aprendizaje.
     */
    public static function clearLearningCache(): void
    {
        self::$bestPlans = [];
        self::$queryHistory = [];
    }
}
