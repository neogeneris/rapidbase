<?php
namespace RapidBase\Core\SQL;

/**
 * Flat Query Engine - Punto de entrada único y optimizado.
 * Uso: Q::from('tabla', $filtros)->build(QType::SELECT, 'campos');
 */
class Q {
    // Constantes para índices del array de estado (Pila numérica para velocidad)
    const T = 0; // Table
    const F = 1; // Filter/Where
    const J = 2; // Joins
    const O = 3; // Order
    const L = 4; // Limit (puede ser [offset, limit])
    const G = 5; // Group
    const H = 6; // Having
    const S = 7; // Select fields (opcional en from, usualmente en build)

    /**
     * Inicia la consulta.
     * @param string|array $table Nombre de tabla o array con alias/joins implícitos
     * @param array $filter Filtros WHERE iniciales
     * @return self
     */
    public static function from($table, array $filter = []): self {
        $instance = new self();
        $instance->state = [
            self::T => $table,
            self::F => $filter,
            self::J => [],
            self::O => null,
            self::L => null,
            self::G => null,
            self::H => null,
            self::S => null
        ];
        return $instance;
    }

    private array $state;

    // Métodos fluentes opcionales para configuración adicional (si se necesita fuera del array inicial)
    public function orderBy(string $order): self {
        $this->state[self::O] = $order;
        return $this;
    }

    public function limit($limit): self {
        $this->state[self::L] = $limit;
        return $this;
    }

    public function groupBy($fields): self {
        $this->state[self::G] = $fields;
        return $this;
    }

    public function having(array $filter): self {
        $this->state[self::H] = $filter;
        return $this;
    }

    /**
     * Genera el SQL final.
     * @param int $type Tipo de consulta (QType::SELECT, etc.)
     * @param mixed $payload Datos adicionales (campos para select, datos para insert/update)
     * @return array [sql, params]
     */
    public function build(int $type, $payload = null): array {
        switch ($type) {
            case QType::SELECT:
                return $this->compileSelect($payload);
            case QType::INSERT:
                return $this->compileInsert($payload);
            case QType::UPDATE:
                return $this->compileUpdate($payload);
            case QType::DELETE:
                return $this->compileDelete();
            case QType::COUNT:
                return $this->compileCount();
            case QType::EXISTS:
                return $this->compileExists();
            default:
                throw new \InvalidArgumentException("Tipo de consulta no soportado");
        }
    }

    // --- COMPILADORES ---

    private function compileSelect($fields): array {
        $params = [];
        
        // 1. FROM & JOINS
        $joinManager = new DeterministicJoin($this->state[self::T], $this->state[self::J]);
        $fromSql = $joinManager->getFromClause();
        $joinSql = $joinManager->getJoinClause();
        
        // 2. WHERE (Usando el Parser robusto)
        $whereSql = '';
        if (!empty($this->state[self::F])) {
            $parser = new ConditionParser();
            $whereData = $parser->parse($this->state[self::F]);
            $whereSql = ' WHERE ' . $whereData['sql'];
            $params = array_merge($params, $whereData['params']);
        }

        // 3. GROUP BY
        $groupSql = '';
        if ($this->state[self::G]) {
            $cols = is_array($this->state[self::G]) ? implode(', ', $this->state[self::G]) : $this->state[self::G];
            $groupSql = ' GROUP BY ' . $cols;
        }

        // 4. HAVING
        $havingSql = '';
        if (!empty($this->state[self::H])) {
            $parser = new ConditionParser();
            $havingData = $parser->parse($this->state[self::H]);
            $havingSql = ' HAVING ' . $havingData['sql'];
            $params = array_merge($params, $havingData['params']);
        }

        // 5. ORDER BY
        $orderSql = '';
        if ($this->state[self::O]) {
            $orderSql = ' ORDER BY ' . $this->normalizeOrder($this->state[self::O]);
        }

        // 6. LIMIT
        $limitSql = '';
        if ($this->state[self::L]) {
            if (is_array($this->state[self::L])) {
                $limitSql = ' LIMIT ? OFFSET ?';
                $params[] = (int)$this->state[self::L][1];
                $params[] = (int)$this->state[self::L][0];
            } else {
                $limitSql = ' LIMIT ?';
                $params[] = (int)$this->state[self::L];
            }
        }

        // 7. SELECT FIELDS
        $selectFields = '*';
        if ($fields !== null) {
            $selectFields = is_array($fields) ? implode(', ', $fields) : $fields;
        } elseif ($this->state[self::S]) {
            $selectFields = is_array($this->state[self::S]) ? implode(', ', $this->state[self::S]) : $this->state[self::S];
        }

        $sql = sprintf(
            "SELECT %s FROM %s%s%s%s%s%s%s",
            $selectFields,
            $fromSql,
            $joinSql,
            $whereSql,
            $groupSql,
            $havingSql,
            $orderSql,
            $limitSql
        );

        return [$sql, $params];
    }

    private function compileInsert($data): array {
        // Detectar si es insert múltiple
        $isMulti = isset($data[0]) && is_array($data[0]);
        $rows = $isMulti ? $data : [$data];
        
        if (empty($rows)) return ['', []];

        $columns = array_keys($rows[0]);
        $colsSql = '`' . implode('`, `', $columns) . '`';
        
        // Generar placeholders para una fila
        $placeholders = '(' . str_repeat('?, ', count($columns) - 1) . '?)';
        
        // Repetir para todas las filas
        $valuesSql = implode(', ', array_fill(0, count($rows), $placeholders));
        
        $sql = sprintf("INSERT INTO `%s` (%s) VALUES %s", $this->state[self::T], $colsSql, $valuesSql);
        
        // Aplanar parámetros
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col];
            }
        }
        
        return [$sql, $params];
    }

    private function compileUpdate($data): array {
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setParts[] = "`$col` = ?";
            $params[] = $val;
        }
        $setSql = implode(', ', $setParts);

        $whereSql = '';
        if (!empty($this->state[self::F])) {
            $parser = new ConditionParser();
            $whereData = $parser->parse($this->state[self::F]);
            $whereSql = ' WHERE ' . $whereData['sql'];
            $params = array_merge($params, $whereData['params']);
        }

        $sql = sprintf("UPDATE `%s` SET %s%s", $this->state[self::T], $setSql, $whereSql);
        return [$sql, $params];
    }

    private function compileDelete(): array {
        $params = [];
        $whereSql = '';
        
        if (!empty($this->state[self::F])) {
            $parser = new ConditionParser();
            $whereData = $parser->parse($this->state[self::F]);
            $whereSql = ' WHERE ' . $whereData['sql'];
            $params = $whereData['params'];
        }

        $sql = sprintf("DELETE FROM `%s`%s", $this->state[self::T], $whereSql);
        return [$sql, $params];
    }

    private function compileCount(): array {
        $params = [];
        $whereSql = '';
        
        if (!empty($this->state[self::F])) {
            $parser = new ConditionParser();
            $whereData = $parser->parse($this->state[self::F]);
            $whereSql = ' WHERE ' . $whereData['sql'];
            $params = $whereData['params'];
        }

        $sql = sprintf("SELECT COUNT(*) as total FROM `%s`%s", $this->state[self::T], $whereSql);
        return [$sql, $params];
    }

    private function compileExists(): array {
        $params = [];
        $whereSql = '';
        
        if (!empty($this->state[self::F])) {
            $parser = new ConditionParser();
            $whereData = $parser->parse($this->state[self::F]);
            $whereSql = ' WHERE ' . $whereData['sql'];
            $params = $whereData['params'];
        }

        $sql = sprintf("SELECT EXISTS(SELECT 1 FROM `%s`%s) as check_flag", $this->state[self::T], $whereSql);
        return [$sql, $params];
    }

    // --- UTILIDADES ---

    private function normalizeOrder($order): string {
        if (is_array($order)) return implode(', ', $order);
        // Soporte para sintaxis '-campo' -> DESC
        if (strpos($order, '-') === 0) {
            return substr($order, 1) . ' DESC';
        }
        return $order . ' ASC';
    }
}




