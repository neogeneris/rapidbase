<?php

namespace RapidBase\Core\SQL;

/**
 * SqlCompiler: Genera SQL final usando plantillas sprintf para máxima eficiencia.
 * Recibe el estado procesado y devuelve [sql, params].
 */
class SqlCompiler
{
    // Plantillas predefinidas
    private const TPL_SELECT = 'SELECT %s FROM %s%s%s%s%s%s';
    private const TPL_DELETE = 'DELETE FROM %s%s';
    private const TPL_COUNT  = 'SELECT COUNT(*) as total FROM %s%s';
    private const TPL_EXISTS = 'SELECT EXISTS(SELECT 1 FROM %s%s) as check';
    private const TPL_UPDATE = 'UPDATE %s SET %s%s';
    private const TPL_INSERT = 'INSERT INTO %s (%s) VALUES %s';

    /**
     * Compila una consulta SELECT.
     */
    public function compileSelect(array $state, string $fields): array
    {
        $tableSql = $this->quoteTable($state['table']);
        $joinSql = $state['join'];
        $whereSql = $state['where_sql'] ? " WHERE {$state['where_sql']}" : '';
        $groupSql = $state['group'] ? " GROUP BY {$state['group']}" : '';
        $havingSql = $state['having_sql'] ? " HAVING {$state['having_sql']}" : '';
        $orderSql = $state['order'] ? " ORDER BY {$state['order']}" : '';
        $limitSql = $state['limit_sql'] ? " LIMIT {$state['limit_sql']}" : '';

        $sql = sprintf(
            self::TPL_SELECT,
            $fields,
            $tableSql,
            $joinSql ? " $joinSql" : '',
            $whereSql,
            $groupSql,
            $havingSql,
            $orderSql,
            $limitSql
        );

        return [$sql, $state['params']];
    }

    /**
     * Compila una consulta DELETE.
     */
    public function compileDelete(array $state): array
    {
        $tableSql = $this->quoteTable($state['table']);
        $whereSql = $state['where_sql'] ? " WHERE {$state['where_sql']}" : '';

        $sql = sprintf(self::TPL_DELETE, $tableSql, $whereSql);
        return [$sql, $state['params']];
    }

    /**
     * Compila una consulta COUNT.
     */
    public function compileCount(array $state): array
    {
        $tableSql = $this->quoteTable($state['table']);
        $whereSql = $state['where_sql'] ? " WHERE {$state['where_sql']}" : '';

        $sql = sprintf(self::TPL_COUNT, $tableSql, $whereSql);
        return [$sql, $state['params']];
    }

    /**
     * Compila una consulta EXISTS.
     */
    public function compileExists(array $state): array
    {
        $tableSql = $this->quoteTable($state['table']);
        $whereSql = $state['where_sql'] ? " WHERE {$state['where_sql']}" : '';

        $sql = sprintf(self::TPL_EXISTS, $tableSql, $whereSql);
        return [$sql, $state['params']];
    }

    /**
     * Compila una consulta UPDATE.
     * @param array $data Datos a actualizar [col => val, ...]
     */
    public function compileUpdate(array $state, array $data): array
    {
        $tableSql = $this->quoteTable($state['table']);
        
        $setParts = [];
        $setParams = []; // Parámetros del SET van primero
        
        foreach ($data as $col => $val) {
            $setParts[] = "$col = ?";
            $setParams[] = $val;
        }
        
        $setSql = implode(', ', $setParts);
        $whereSql = $state['where_sql'] ? " WHERE {$state['where_sql']}" : '';

        // Los parámetros finales son: primero los del SET, luego los del WHERE
        $params = array_merge($setParams, $state['params']);

        $sql = sprintf(self::TPL_UPDATE, $tableSql, $setSql, $whereSql);
        return [$sql, $params];
    }

    /**
     * Compila un INSERT múltiple.
     * @param array $rows Array de arrays asociativos [['col'=>val], ['col'=>val]]
     */
    public function compileInsertMulti(array $state, array $rows): array
    {
        if (empty($rows)) {
            return ['', []];
        }

        $tableSql = $this->quoteTable($state['table']);
        $firstRow = $rows[0];
        $columns = array_keys($firstRow);
        $colsSql = implode(', ', $columns);

        // Generar placeholders para una fila
        $rowPlaceholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        
        // Repetir para todas las filas
        $valuesSql = implode(',', array_fill(0, count($rows), $rowPlaceholders));

        // Aplanar parámetros
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col];
            }
        }

        $sql = sprintf(self::TPL_INSERT, $tableSql, $colsSql, $valuesSql);
        return [$sql, $params];
    }

    private function quoteTable(string $table): string
    {
        // Manejar alias "tabla alias" o "tabla AS alias"
        if (strpos($table, ' ') !== false) {
            $parts = preg_split('/\s+AS\s+/i', trim($table));
            $name = $parts[0];
            $alias = $parts[1] ?? $name;
            return "\"$name\" AS $alias";
        }
        return "\"$table\"";
    }
}
