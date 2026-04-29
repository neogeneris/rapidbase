<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

/**
 * SqlCompiler - Genera SQL usando plantillas sprintf.
 * 
 * Recibe un array numérico indexado por constantes de clase.
 * Máxima eficiencia: sin claves de cadena, sin objetos adicionales.
 */
class SqlCompiler
{
    // Índices del array de estado compilado
    public const SEL    = 0; // Campos SELECT
    public const FROM   = 1; // Cláusula FROM/JOIN completa (incluye "FROM")
    public const WHERE  = 2; // Condiciones WHERE (sin "WHERE")
    public const GROUP  = 3; // Columnas GROUP BY (sin "GROUP BY")
    public const HAVING = 4; // Condiciones HAVING (sin "HAVING")
    public const ORDER  = 5; // Columnas ORDER BY (sin "ORDER BY")
    public const LIMIT  = 6; // LIMIT/OFFSET con placeholders (sin "LIMIT")
    public const PARAMS = 7; // Array de parámetros finales

    // Plantillas sprintf (minúsculas, sin lógica)
    private const TPL_SELECT = 'SELECT %s %s %s %s %s %s %s';
    //                          SEL, FROM, WHERE (con "WHERE"), GROUP, HAVING, ORDER, LIMIT
    private const TPL_DELETE = 'DELETE FROM %s%s';
    private const TPL_COUNT  = 'SELECT COUNT(*) FROM %s%s';
    private const TPL_EXISTS = 'SELECT EXISTS(SELECT 1 FROM %s%s)';
    private const TPL_UPDATE = 'UPDATE %s SET %s%s';
    private const TPL_INSERT = 'INSERT INTO %s (%s) VALUES %s';

    /**
     * Compila un SELECT.
     *
     * @param array $state Array con índices numéricos según constantes.
     * @return array [sql, params]
     */
    public function compileSelect(array $state): array
    {
        $sel    = $state[self::SEL]    ?? '*';
        $from   = $state[self::FROM]   ?? '';
        $where  = $state[self::WHERE]  ? ' WHERE ' . $state[self::WHERE] : '';
        $group  = $state[self::GROUP]  ? ' GROUP BY ' . $state[self::GROUP] : '';
        $having = $state[self::HAVING] ? ' HAVING ' . $state[self::HAVING] : '';
        $order  = $state[self::ORDER]  ? ' ORDER BY ' . $state[self::ORDER] : '';
        $limit  = $state[self::LIMIT]  ? ' LIMIT ' . $state[self::LIMIT] : '';
        $params = $state[self::PARAMS] ?? [];

        $sql = sprintf(
            self::TPL_SELECT,
            $sel,
            $from,
            $where,
            $group,
            $having,
            $order,
            $limit
        );
        return [$sql, $params];
    }

    /**
     * Compila DELETE.
     */
    public function compileDelete(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_DELETE, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila COUNT.
     */
    public function compileCount(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_COUNT, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila EXISTS.
     */
    public function compileExists(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_EXISTS, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila UPDATE.
     *
     * @param array $data Datos a actualizar [columna => valor]
     */
    public function compileUpdate(array $state, array $data): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];

        $setParts = [];
        $setParams = [];
        foreach ($data as $col => $val) {
            $setParts[] = ConditionMatrix::quote($col) . ' = ?';
            $setParams[] = $val;
        }
        $setSql = implode(', ', $setParts);

        // Los parámetros del SET van antes que los del WHERE
        $params = array_merge($setParams, $params);
        $sql = sprintf(self::TPL_UPDATE, $from, $setSql, $where);
        return [$sql, $params];
    }

    /**
     * Compila INSERT (simple o múltiple).
     *
     * @param array $rows Una fila asociativa o array de filas
     */
    public function compileInsert(array $state, array $rows): array
    {
        $from   = $state[self::FROM] ?? '';

        $data = isset($rows[0]) && is_array($rows[0]) ? $rows : [$rows];
        if (empty($data)) {
            return ['', []];
        }

        $columns = array_keys($data[0]);
        $colsSql = implode(', ', array_map([ConditionMatrix::class, 'quote'], $columns));

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesSql = implode(', ', array_fill(0, count($data), $rowPlaceholder));

        $params = [];
        foreach ($data as $row) {
            foreach ($columns as $c) {
                $params[] = $row[$c] ?? null;
            }
        }

        $sql = sprintf(self::TPL_INSERT, $from, $colsSql, $valuesSql);
        return [$sql, $params];
    }
}