<?php
/**
 * Benchmark: Claves Numéricas vs Constantes vs Strings Directos
 * 
 * Objetivo: Medir el impacto real en performance de usar diferentes tipos de claves
 * para el array de estado interno.
 */

// Sin autoload, clases definidas inline

// -----------------------------------------------------------------------------
// 1. Implementación con CLAVES NUMÉRICAS DIRECTAS (Sin constantes)
// -----------------------------------------------------------------------------
class WNum {
    private array $state = [];
    
    public static function table($table, array $filter = []): self {
        $i = new self();
        // Índices numéricos puros: 0=table, 1=filter, 2=fields, 3=sort, 4=page, 5=data
        $i->state = [$table, $filter, '*', null, null, null];
        return $i;
    }
    
    public function select($fields = '*', $page = null, $sort = null): array {
        $this->state[2] = $fields;
        $this->state[4] = $page;
        $this->state[3] = $sort;
        return $this->build();
    }
    
    private function build(): array {
        // Acceso por índice numérico directo
        $table = $this->state[0];
        $filter = $this->state[1];
        $fields = $this->state[2];
        $sort = $this->state[3];
        $page = $this->state[4];
        
        $sql = "SELECT " . (is_array($fields) ? implode(',', $fields) : $fields);
        $sql .= " FROM " . (is_array($table) ? implode(',', $table) : $table);
        
        $params = [];
        if (!empty($filter)) {
            $conds = [];
            foreach ($filter as $k => $v) {
                $conds[] = "$k=?";
                $params[] = $v;
            }
            $sql .= " WHERE " . implode(' AND ', $conds);
        }
        
        if ($sort) {
            $d = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $f = ltrim($sort, '-');
            $sql .= " ORDER BY $f $d";
        }
        
        if ($page) {
            $pn = is_int($page) ? $page : $page[0];
            $ps = is_array($page) ? ($page[1] ?? 20) : 20;
            $off = ($pn - 1) * $ps;
            $params[] = $ps;
            $params[] = $off;
            $sql .= " LIMIT ? OFFSET ?";
        }
        
        return [$sql, $params];
    }
}

// -----------------------------------------------------------------------------
// 2. Implementación con CONSTANTES como claves (Versión actual modificada)
// -----------------------------------------------------------------------------
class WConst {
    private const TBL = 0;
    private const FLT = 1;
    private const FLD = 2;
    private const SRT = 3;
    private const PAG = 4;
    private const DAT = 5;
    
    private array $state = [];
    
    public static function table($table, array $filter = []): self {
        $i = new self();
        $i->state = [
            self::TBL => $table,
            self::FLT => $filter,
            self::FLD => '*',
            self::SRT => null,
            self::PAG => null,
            self::DAT => null
        ];
        return $i;
    }
    
    public function select($fields = '*', $page = null, $sort = null): array {
        $this->state[self::FLD] = $fields;
        $this->state[self::PAG] = $page;
        $this->state[self::SRT] = $sort;
        return $this->build();
    }
    
    private function build(): array {
        $table = $this->state[self::TBL];
        $filter = $this->state[self::FLT];
        $fields = $this->state[self::FLD];
        $sort = $this->state[self::SRT];
        $page = $this->state[self::PAG];
        
        $sql = "SELECT " . (is_array($fields) ? implode(',', $fields) : $fields);
        $sql .= " FROM " . (is_array($table) ? implode(',', $table) : $table);
        
        $params = [];
        if (!empty($filter)) {
            $conds = [];
            foreach ($filter as $k => $v) {
                $conds[] = "$k=?";
                $params[] = $v;
            }
            $sql .= " WHERE " . implode(' AND ', $conds);
        }
        
        if ($sort) {
            $d = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $f = ltrim($sort, '-');
            $sql .= " ORDER BY $f $d";
        }
        
        if ($page) {
            $pn = is_int($page) ? $page : $page[0];
            $ps = is_array($page) ? ($page[1] ?? 20) : 20;
            $off = ($pn - 1) * $ps;
            $params[] = $ps;
            $params[] = $off;
            $sql .= " LIMIT ? OFFSET ?";
        }
        
        return [$sql, $params];
    }
}

// -----------------------------------------------------------------------------
// 3. Implementación con STRINGS DIRECTOS como claves
// -----------------------------------------------------------------------------
class WStr {
    private array $state = [];
    
    public static function table($table, array $filter = []): self {
        $i = new self();
        $i->state = [
            'table'  => $table,
            'filter' => $filter,
            'fields' => '*',
            'sort'   => null,
            'page'   => null,
            'data'   => null
        ];
        return $i;
    }
    
    public function select($fields = '*', $page = null, $sort = null): array {
        $this->state['fields'] = $fields;
        $this->state['page']   = $page;
        $this->state['sort']   = $sort;
        return $this->build();
    }
    
    private function build(): array {
        $table = $this->state['table'];
        $filter = $this->state['filter'];
        $fields = $this->state['fields'];
        $sort = $this->state['sort'];
        $page = $this->state['page'];
        
        $sql = "SELECT " . (is_array($fields) ? implode(',', $fields) : $fields);
        $sql .= " FROM " . (is_array($table) ? implode(',', $table) : $table);
        
        $params = [];
        if (!empty($filter)) {
            $conds = [];
            foreach ($filter as $k => $v) {
                $conds[] = "$k=?";
                $params[] = $v;
            }
            $sql .= " WHERE " . implode(' AND ', $conds);
        }
        
        if ($sort) {
            $d = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $f = ltrim($sort, '-');
            $sql .= " ORDER BY $f $d";
        }
        
        if ($page) {
            $pn = is_int($page) ? $page : $page[0];
            $ps = is_array($page) ? ($page[1] ?? 20) : 20;
            $off = ($pn - 1) * $ps;
            $params[] = $ps;
            $params[] = $off;
            $sql .= " LIMIT ? OFFSET ?";
        }
        
        return [$sql, $params];
    }
}

// -----------------------------------------------------------------------------
// BENCHMARK
// -----------------------------------------------------------------------------
echo "=== Benchmark: Tipo de Clave en Array de Estado ===\n\n";

$iterations = 100000;
$table = 'users';
$filter = ['status' => 'active', 'role' => 'admin'];
$fields = ['id', 'name', 'email'];
$page = [1, 20];
$sort = '-created_at';

// Calentar
for ($i = 0; $i < 100; $i++) {
    WNum::table($table, $filter)->select($fields, $page, $sort);
    WConst::table($table, $filter)->select($fields, $page, $sort);
    WStr::table($table, $filter)->select($fields, $page, $sort);
}

// Test WNum
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    WNum::table($table, $filter)->select($fields, $page, $sort);
}
$timeNum = (microtime(true) - $start) * 1000;
$memNum = memory_get_usage() - $startMem;

// Test WConst
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    WConst::table($table, $filter)->select($fields, $page, $sort);
}
$timeConst = (microtime(true) - $start) * 1000;
$memConst = memory_get_usage() - $startMem;

// Test WStr
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    WStr::table($table, $filter)->select($fields, $page, $sort);
}
$timeStr = (microtime(true) - $start) * 1000;
$memStr = memory_get_usage() - $startMem;

// Resultados
echo "Iteraciones: {$iterations}\n\n";

printf("%-15s | %-12s | %-12s | %-10s\n", "Implementación", "Tiempo (ms)", "Memoria (KB)", "% vs Num");
echo str_repeat("-", 60) . "\n";
printf("%-15s | %-12.2f | %-12.2f | %-10s\n", "Numérica", $timeNum, $memNum/1024, "100%");
printf("%-15s | %-12.2f | %-12.2f | %-10.1f%%\n", "Constantes", $timeConst, $memConst/1024, ($timeConst/$timeNum)*100);
printf("%-15s | %-12.2f | %-12.2f | %-10.1f%%\n", "Strings", $timeStr, $memStr/1024, ($timeStr/$timeNum)*100);

echo "\n=== Conclusión ===\n";
if ($timeStr <= $timeNum * 1.05) {
    echo "✓ Los STRINGS tienen performance comparable (<5% diferencia).\n";
    echo "  Recomendación: Usar strings por MEJOR legibilidad sin penalty significativo.\n";
} elseif ($timeStr <= $timeNum * 1.10) {
    echo "△ Los strings son ligeramente más lentos (5-10%).\n";
    echo "  Considerar: ¿Vale la pena la legibilidad extra?\n";
} else {
    echo "✗ Los strings son significativamente más lentos (>10%).\n";
    echo "  Recomendación: Usar constantes o numéricos.\n";
}

// Verificar que todos producen el mismo SQL
$sqlNum = WNum::table($table, $filter)->select($fields, $page, $sort)[0];
$sqlConst = WConst::table($table, $filter)->select($fields, $page, $sort)[0];
$sqlStr = WStr::table($table, $filter)->select($fields, $page, $sort)[0];

echo "\n=== Validación de Correctitud ===\n";
echo "SQL Num:     $sqlNum\n";
echo "SQL Const:   $sqlConst\n";
echo "SQL Str:     $sqlStr\n";
echo ($sqlNum === $sqlConst && $sqlConst === $sqlStr) ? "✓ Todos generan el mismo SQL\n" : "✗ Diferencias detectadas\n";
