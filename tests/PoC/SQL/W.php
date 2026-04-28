<?php

namespace RapidBase\Core;

/**
 * Clase W: Variante de bajo acoplamiento y encadenamiento corto (PoC).
 */
class W
{
    // Constantes de 4 caracteres para claves del estado
    protected const T_FRO = 0;
    protected const T_TYP = 1;
    protected const T_WHR = 2;
    protected const T_PRM = 3;
    protected const T_SEL = 4;
    protected const T_ORD = 5;
    protected const T_LIM = 6;
    protected const T_OFF = 7;
    protected const T_GRP = 8;
    protected const T_HAV = 9;
    
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    
    // Estado interno protegido para permitir herencia
    protected array $state = [];
    
    private static array $whereCache = [];
    private const DEF_PAGE = 20;

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }

    /**
     * @return static
     */
    public static function from($from, array $filter = []): self
    {
        $instance = new static();
        
        if (is_string($from)) {
            $instance->state[self::T_FRO] = $from;
            $instance->state[self::T_TYP] = 'raw';
        } elseif (is_array($from)) {
            $instance->state[self::T_FRO] = implode(', ', $from);
            $instance->state[self::T_TYP] = 'list';
        } else {
            throw new \InvalidArgumentException("El primer parámetro de from() debe ser string o array.");
        }

        if (!empty($filter)) {
            $whereKey = self::getWhereCacheKey($filter);
            if (isset(self::$whereCache[$whereKey])) {
                $instance->state[self::T_WHR] = self::$whereCache[$whereKey]['sql'];
                $instance->state[self::T_PRM] = self::$whereCache[$whereKey]['params'];
            } else {
                $parsed = self::parseWhere($filter);
                self::$whereCache[$whereKey] = $parsed;
                $instance->state[self::T_WHR] = $parsed['sql'];
                $instance->state[self::T_PRM] = $parsed['params'];
            }
        } else {
            $instance->state[self::T_WHR] = '';
            $instance->state[self::T_PRM] = [];
        }

        $instance->state[self::T_SEL] = '*';
        $instance->state[self::T_ORD] = '';
        $instance->state[self::T_LIM] = null;
        $instance->state[self::T_OFF] = null;
        $instance->state[self::T_GRP] = '';
        $instance->state[self::T_HAV] = '';

        return $instance;
    }

    public function select($fields = '*', $limit = null, $sort = null, array $group = [], array $having = []): array
    {
        $this->state[self::T_SEL] = is_array($fields) ? implode(', ', $fields) : $fields;

        if ($limit !== null) {
            $this->applyLimit($limit);
        }

        if ($sort) {
            $this->applyOrder($sort);
        }

        if (!empty($group)) {
            $this->state[self::T_GRP] = implode(', ', $group);
        }

        if (!empty($having)) {
            $parsed = self::parseWhere($having);
            $this->state[self::T_HAV] = $parsed['sql'];
            $this->state[self::T_PRM] = array_merge($this->state[self::T_PRM], $parsed['params']);
        }

        return $this->buildSelect();
    }

    public function delete(): array
    {
        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::T_WHR];
        $params = $this->state[self::T_PRM];

        $sql = "DELETE FROM {$table}";
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    public function update(array $data): array
    {
        $setParts = [];
        $params = $this->state[self::T_PRM];

        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }

        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::T_WHR];
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    private function buildSelect(): array
    {
        $sql = "SELECT {$this->state[self::T_SEL]} FROM {$this->state[self::T_FRO]}";
        
        $params = $this->state[self::T_PRM];

        if (!empty($this->state[self::T_WHR])) {
            $sql .= " WHERE " . $this->state[self::T_WHR];
        }

        if ($this->state[self::T_ORD]) {
            $sql .= " ORDER BY " . $this->state[self::T_ORD];
        }

        if (!empty($this->state[self::T_GRP])) {
            $sql .= " GROUP BY " . $this->state[self::T_GRP];
        }

        if (!empty($this->state[self::T_HAV])) {
            $sql .= " HAVING " . $this->state[self::T_HAV];
        }

        if ($this->state[self::T_LIM] !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->state[self::T_LIM];
        }

        if ($this->state[self::T_OFF] !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->state[self::T_OFF];
        }

        return [$sql, $params];
    }

    private function applyOrder($sort): void
    {
        if (is_string($sort)) {
            $dir = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $field = ltrim($sort, '-+');
            $this->state[self::T_ORD] = "{$field} {$dir}";
        } elseif (is_array($sort)) {
            $parts = [];
            foreach ($sort as $s) {
                $dir = strpos($s, '-') === 0 ? 'DESC' : 'ASC';
                $field = ltrim($s, '-+');
                $parts[] = "{$field} {$dir}";
            }
            $this->state[self::T_ORD] = implode(', ', $parts);
        }
    }

    private function applyLimit($limit): void
    {
        if (is_int($limit)) {
            $this->state[self::T_LIM] = $limit;
            $this->state[self::T_OFF] = null;
        } elseif (is_array($limit) && count($limit) >= 2) {
            $this->state[self::T_OFF] = max(0, (int)$limit[0]);
            $this->state[self::T_LIM] = max(1, (int)$limit[1]);
        }
    }

    private static function parseWhere(array $filter): array
    {
        $sqlParts = [];
        $params = [];

        foreach ($filter as $key => $value) {
            if ($value === null) {
                $sqlParts[] = "{$key} IS NULL";
            } elseif (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $sqlParts[] = "{$key} IN ({$placeholders})";
                $params = array_merge($params, $value);
            } else {
                $sqlParts[] = "{$key} = ?";
                $params[] = $value;
            }
        }

        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }

    public static function page(int $currentPage, int $pageSize): array
    {
        return [max(0, ($currentPage - 1) * $pageSize), $pageSize];
    }

    private static function getWhereCacheKey(array $filter): string
    {
        $structure = [];
        foreach ($filter as $key => $value) {
            if ($value === null) {
                $structure[$key] = 'null';
            } elseif (is_array($value)) {
                $structure[$key] = 'in:' . count($value);
            } else {
                $structure[$key] = 'eq';
            }
        }
        ksort($structure);
        return md5(serialize($structure));
    }
}
