<?php

namespace RapidBase\Core;

/**
 * Clase W: Variante de bajo acoplamiento y encadenamiento corto.
 * 
 * Filosofía:
 * 1. No usa estilo fluent extenso. Solo table() -> action().
 * 2. El estado interno es puramente un array (stack), sin objetos intermedios.
 * 3. Polimorfismo estricto en inputs (String vs Array).
 * 4. Reutilización de lógica de construcción de WHERE y FROM.
 * 5. Uso de constantes para claves del array (legibilidad + performance).
 */
class W
{
    // Constantes para claves del estado (mejor que strings, casi tan rápido como ints)
    private const ST_FROM = 0;
    private const ST_FROM_TYPE = 1;
    private const ST_WHERE_SQL = 2;
    private const ST_WHERE_PARAMS = 3;
    private const ST_SELECT = 4;
    private const ST_ORDER = 5;
    private const ST_LIMIT = 6;
    private const ST_OFFSET = 7;
    
    // Mapeo de nombres para debugging (opcional)
    private const STATE_KEYS = [
        self::ST_FROM => 'from',
        self::ST_FROM_TYPE => 'from_type',
        self::ST_WHERE_SQL => 'where_sql',
        self::ST_WHERE_PARAMS => 'where_params',
        self::ST_SELECT => 'select',
        self::ST_ORDER => 'order',
        self::ST_LIMIT => 'limit',
        self::ST_OFFSET => 'offset',
    ];

    // Estado interno como array indexado por constantes (más rápido que strings)
    private array $state = [];
    
    // Cache estático para templates de WHERE comunes (optimización L1)
    private static array $whereCache = [];
    
    // Page size por defecto
    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * Punto de entrada único. Prepara el contexto (FROM + WHERE base).
     * 
     * @param string|array $table Si es string: SQL crudo (ej: "users u"). Si es array: Lista de tablas.
     * @param array $filter Filtro base para el WHERE.
     * @return self Retorna una instancia nueva con el estado inicializado.
     */
    public static function table($table, array $filter = []): self
    {
        $instance = new self();
        
        // Normalización polimórfica de FROM
        if (is_string($table)) {
            // Asumimos SQL crudo directo
            $instance->state[self::ST_FROM] = $table;
            $instance->state[self::ST_FROM_TYPE] = 'raw';
        } elseif (is_array($table)) {
            // Lista de tablas, construimos el string
            $instance->state[self::ST_FROM] = implode(', ', $table);
            $instance->state[self::ST_FROM_TYPE] = 'list';
        } else {
            throw new \InvalidArgumentException("El primer parámetro de table() debe ser string o array.");
        }

        // Normalización inicial de WHERE si existe filtro
        if (!empty($filter)) {
            $whereKey = self::getWhereCacheKey($filter);
            if (isset(self::$whereCache[$whereKey])) {
                // Hit de cache: reutilizamos el parseo previo
                $instance->state[self::ST_WHERE_SQL] = self::$whereCache[$whereKey]['sql'];
                $instance->state[self::ST_WHERE_PARAMS] = self::$whereCache[$whereKey]['params'];
            } else {
                // Miss de cache: parseamos y guardamos
                $parsed = self::parseWhere($filter);
                self::$whereCache[$whereKey] = $parsed;
                $instance->state[self::ST_WHERE_SQL] = $parsed['sql'];
                $instance->state[self::ST_WHERE_PARAMS] = $parsed['params'];
            }
        } else {
            $instance->state[self::ST_WHERE_SQL] = '';
            $instance->state[self::ST_WHERE_PARAMS] = [];
        }

        // Reset de otros componentes
        $instance->state[self::ST_SELECT] = '*';
        $instance->state[self::ST_ORDER] = '';
        $instance->state[self::ST_LIMIT] = null;
        $instance->state[self::ST_OFFSET] = null;

        return $instance;
    }

    /**
     * Ejecuta la acción SELECT.
     * 
     * @param string|array $fields Campos a seleccionar.
     * @param int|array $page Si es int: Página actual (asume page size global o default). 
     *                        Si es array: [page, pageSize].
     * @param string|array $sort Ordenamiento. String: "-campo", Array: ["-campo1", "campo2"].
     * @return array [sql, params]
     */
    public function select($fields = '*', $page = null, $sort = null): array
    {
        // 1. Procesar Fields
        $this->state[self::ST_SELECT] = is_array($fields) ? implode(', ', $fields) : $fields;

        // 2. Procesar Sort (Polimórfico)
        if ($sort) {
            $this->applyOrder($sort);
        }

        // 3. Procesar Page (Polimórfico)
        if ($page !== null) {
            $this->applyPage($page);
        }

        return $this->buildSelect();
    }

    /**
     * Ejecuta la acción DELETE.
     * Usa el FROM y WHERE definidos en table().
     * 
     * @return array [sql, params]
     */
    public function delete(): array
    {
        // Validación simple: DELETE solo suele tener una tabla principal
        $table = $this->state[self::ST_FROM];
        if ($this->state[self::ST_FROM_TYPE] === 'list') {
            // Si es lista, tomamos la primera como objetivo del delete
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::ST_WHERE_SQL];
        $params = $this->state[self::ST_WHERE_PARAMS];

        $sql = "DELETE FROM {$table}";
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    /**
     * Ejecuta la acción UPDATE.
     * 
     * @param array $data Datos a actualizar.
     * @return array [sql, params]
     */
    public function update(array $data): array
    {
        $setParts = [];
        $params = $this->state[self::ST_WHERE_PARAMS]; // Empezamos con los params del where

        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }

        $table = $this->state[self::ST_FROM];
        if ($this->state[self::ST_FROM_TYPE] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::ST_WHERE_SQL];
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    // --- Métodos Privados de Construcción (Lógica pura de arrays) ---

    private function buildSelect(): array
    {
        $sql = "SELECT {$this->state[self::ST_SELECT]} FROM {$this->state[self::ST_FROM]}";
        
        $params = $this->state[self::ST_WHERE_PARAMS];

        if (!empty($this->state[self::ST_WHERE_SQL])) {
            $sql .= " WHERE " . $this->state[self::ST_WHERE_SQL];
        }

        if ($this->state[self::ST_ORDER]) {
            $sql .= " ORDER BY " . $this->state[self::ST_ORDER];
        }

        if ($this->state[self::ST_LIMIT] !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->state[self::ST_LIMIT];
        }

        if ($this->state[self::ST_OFFSET] !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->state[self::ST_OFFSET];
        }

        return [$sql, $params];
    }

    private function applyOrder($sort): void
    {
        if (is_string($sort)) {
            // Manejo simple de dirección
            $dir = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $field = ltrim($sort, '-+');
            $this->state[self::ST_ORDER] = "{$field} {$dir}";
        } elseif (is_array($sort)) {
            $parts = [];
            foreach ($sort as $s) {
                $dir = strpos($s, '-') === 0 ? 'DESC' : 'ASC';
                $field = ltrim($s, '-+');
                $parts[] = "{$field} {$dir}";
            }
            $this->state[self::ST_ORDER] = implode(', ', $parts);
        }
    }

    private function applyPage($page): void
    {
        $pageSize = defined('static::PAGE_SIZE') ? static::PAGE_SIZE : self::DEFAULT_PAGE_SIZE;
        
        if (is_int($page)) {
            $this->state[self::ST_LIMIT] = $pageSize;
            $this->state[self::ST_OFFSET] = max(0, ($page - 1) * $pageSize);
        } elseif (is_array($page) && count($page) === 2) {
            $this->state[self::ST_LIMIT] = (int)$page[1];
            $this->state[self::ST_OFFSET] = max(0, ((int)$page[0] - 1) * (int)$page[1]);
        }
    }

    /**
     * Parser de WHERE recursivo pero basado en arrays planos para rendimiento.
     * Devuelve ['sql' => 'a=? AND b=?', 'params' => [valA, valB]]
     */
    private static function parseWhere(array $filter): array
    {
        $sqlParts = [];
        $params = [];

        foreach ($filter as $key => $value) {
            if ($value === null) {
                $sqlParts[] = "{$key} IS NULL";
            } elseif (is_array($value)) {
                // Soporte básico para IN
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
    
    /**
     * Genera una clave única para el cache de WHERE basada en la estructura del filtro.
     * Solo considera las claves y tipos de valores, no los valores mismos.
     */
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
        // Ordenamos para que ['a'=>1, 'b'=>2] sea igual a ['b'=>2, 'a'=>1]
        ksort($structure);
        return md5(serialize($structure));
    }
}
