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
 */
class W
{
    // Estado interno como array simple (más ligero que objetos)
    private array $state = [];

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
            $instance->state['from'] = $table;
            $instance->state['from_type'] = 'raw';
        } elseif (is_array($table)) {
            // Lista de tablas, construimos el string
            $instance->state['from'] = implode(', ', $table);
            $instance->state['from_type'] = 'list';
        } else {
            throw new \InvalidArgumentException("El primer parámetro de table() debe ser string o array.");
        }

        // Normalización inicial de WHERE si existe filtro
        if (!empty($filter)) {
            $instance->state['where_parts'] = self::parseWhere($filter);
            $instance->state['where_params'] = $instance->state['where_parts']['params'];
        } else {
            $instance->state['where_parts'] = ['sql' => '', 'params' => []];
            $instance->state['where_params'] = [];
        }

        // Reset de otros componentes
        $instance->state['select'] = '*';
        $instance->state['order'] = '';
        $instance->state['limit'] = null;
        $instance->state['offset'] = null;

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
        if (is_array($fields)) {
            $this->state['select'] = implode(', ', $fields);
        } else {
            $this->state['select'] = $fields;
        }

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
        $table = $this->state['from'];
        if ($this->state['from_type'] === 'list') {
            // Si es lista, tomamos la primera como objetivo del delete
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state['where_parts']['sql'];
        $params = $this->state['where_params'];

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
        $params = $this->state['where_params']; // Empezamos con los params del where

        foreach ($data as $col => $val) {
            $placeholder = '?';
            $setParts[] = "{$col} = {$placeholder}";
            $params[] = $val;
        }

        $table = $this->state['from'];
        if ($this->state['from_type'] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state['where_parts']['sql'];
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    // --- Métodos Privados de Construcción (Lógica pura de arrays) ---

    private function buildSelect(): array
    {
        $sql = "SELECT {$this->state['select']} FROM {$this->state['from']}";
        
        $params = $this->state['where_params'];

        if (!empty($this->state['where_parts']['sql'])) {
            $sql .= " WHERE " . $this->state['where_parts']['sql'];
        }

        if ($this->state['order']) {
            $sql .= " ORDER BY " . $this->state['order'];
        }

        if ($this->state['limit'] !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->state['limit'];
        }

        if ($this->state['offset'] !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->state['offset'];
        }

        return [$sql, $params];
    }

    private function applyOrder($sort): void
    {
        if (is_string($sort)) {
            // Manejo simple de dirección
            $dir = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $field = ltrim($sort, '-+');
            $this->state['order'] = "{$field} {$dir}";
        } elseif (is_array($sort)) {
            $parts = [];
            foreach ($sort as $s) {
                $dir = strpos($s, '-') === 0 ? 'DESC' : 'ASC';
                $field = ltrim($s, '-+');
                $parts[] = "{$field} {$dir}";
            }
            $this->state['order'] = implode(', ', $parts);
        }
    }

    private function applyPage($page): void
    {
        $pageSize = defined('static::PAGE_SIZE') ? static::PAGE_SIZE : 20; // Default fallback
        
        if (is_int($page)) {
            $this->state['limit'] = $pageSize;
            $this->state['offset'] = max(0, ($page - 1) * $pageSize);
        } elseif (is_array($page) && count($page) === 2) {
            $this->state['limit'] = (int)$page[1];
            $this->state['offset'] = max(0, ((int)$page[0] - 1) * (int)$page[1]);
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
}
