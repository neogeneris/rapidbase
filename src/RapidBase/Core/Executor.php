<?php

namespace RapidBase\Core;

use RapidBase\Core\SQL\CompiledQuery;

class Executor
{
    /**
     * Ejecuta un CompiledQuery (o array plano) y devuelve un array de control unificado:
     *   [rows => [...], count => int, success => bool, action => string, cols => [...], total => int, lastId => ...]
     */
    public static function execute(
        mixed $cq,
        int $fetchMode = \PDO::FETCH_NUM,
        ?string $class = null,
        ?string $connectionName = null
    ): array {
        // Normalización de entrada (híbrida)
        if (is_array($cq)) {
            $type   = $cq['type'];
            $sql    = $cq['sql'];
            $params = $cq['params'] ?? [];
            $map    = $cq['map'] ?? [];
        } else {
            $type   = $cq->getType();
            $sql    = $cq->getSql();
            $params = $cq->getParams();
            $map    = $cq->getProjectionMap();
        }

        $result = [
            'rows'    => [],
            'count'   => 0,
            'success' => false,
            'action'  => 'unknown',
            'cols'    => [],
            'total'   => 0,
            'lastId'  => null,
        ];

        switch ($type) {
            case CompiledQuery::SELECT:
                $stmt = self::query($sql, $params, $connectionName);

                // FETCH_CLASS: objetos personalizados
                if ($fetchMode === \PDO::FETCH_CLASS && $class !== null) {
                    $rows = $stmt->fetchAll($fetchMode, $class);
                    $result['rows']    = $rows;
                    $result['count']   = count($rows);
                    $result['cols']    = !empty($rows) ? array_keys((array)$rows[0]) : [];
                    $result['success'] = true;
                    $result['action']  = 'select';
                    $result['total']   = count($rows);
                    break;
                }

                // FETCH_OBJ: objetos genéricos
                if ($fetchMode === \PDO::FETCH_OBJ) {
                    $rows = $stmt->fetchAll($fetchMode);
                    $result['rows']    = $rows;
                    $result['count']   = count($rows);
                    $result['cols']    = !empty($rows) ? array_keys((array)$rows[0]) : [];
                    $result['success'] = true;
                    $result['action']  = 'select';
                    $result['total']   = count($rows);
                    break;
                }

                // FETCH_ASSOC: opcional para casos particulares
                if ($fetchMode === \PDO::FETCH_ASSOC) {
                    $rows = $stmt->fetchAll($fetchMode);
                    $result['rows']    = $rows;
                    $result['count']   = count($rows);
                    $result['cols']    = !empty($rows) ? array_keys((array)$rows[0]) : [];
                    $result['success'] = true;
                    $result['action']  = 'select';
                    $result['total']   = count($rows);
                    break;
                }

                // Por defecto: FETCH_NUM (filas numéricas)
                $rows = $stmt->fetchAll(\PDO::FETCH_NUM);

                // ── Determinar el mapa de columnas ─────────
                if (empty($map) && !empty($rows)) {
                    // Fallback: construir mapa desde getColumnMeta
                    $map = [];
                    $columnCount = $stmt->columnCount();
                    for ($i = 0; $i < $columnCount; $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        if ($meta !== false && isset($meta['name'])) {
                            $map[$meta['name']] = $i;
                        } else {
                            $map["col_$i"] = $i;
                        }
                    }
                    if ($cq instanceof CompiledQuery) {
                        $cq->setProjectionMap($map);
                    }
                }

                // Extraer _total si existe (inyectado por Q::select con paginación)
                $total = count($rows);
                if (!empty($map) && isset($map['_total'])) {
                    $totalIndex = $map['_total'];
                    if (!empty($rows) && isset($rows[0][$totalIndex])) {
                        $total = (int) $rows[0][$totalIndex];
                    }
                    unset($map['_total']);
                }

                // Sin hidratación: devolvemos las filas numéricas
                $result['rows']    = $rows;
                $result['count']   = count($rows);
                $result['cols']    = array_keys($map);   // nombres de columna
                $result['success'] = true;
                $result['action']  = 'select';
                $result['total']   = $total;
                break;

            case CompiledQuery::COUNT:
                $stmt = self::query($sql, $params, $connectionName);
                $val = (int) $stmt->fetchColumn();
                $result['rows']    = [$val];
                $result['count']   = $val;
                $result['cols']    = ['total'];
                $result['success'] = true;
                $result['action']  = 'count';
                $result['total']   = $val;
                break;

            case CompiledQuery::EXISTS:
                $stmt = self::query($sql, $params, $connectionName);
                $val = (bool) $stmt->fetchColumn();
                $result['rows']    = [$val];
                $result['count']   = $val ? 1 : 0;
                $result['cols']    = ['exists'];
                $result['success'] = true;
                $result['action']  = 'exists';
                $result['total']   = $val ? 1 : 0;
                break;

            case CompiledQuery::INSERT:
            case CompiledQuery::UPDATE:
            case CompiledQuery::DELETE:
            case CompiledQuery::UPSERT:
                $raw = self::action($sql, $params, $connectionName);
                $result['count']   = $raw['count'];
                $result['lastId']  = $raw['lastId'] ?? null;
                $result['success'] = $raw['success'];
                $result['action']  = match ($type) {
                    CompiledQuery::INSERT => 'insert',
                    CompiledQuery::UPDATE => 'update',
                    CompiledQuery::DELETE => 'delete',
                    CompiledQuery::UPSERT => 'upsert',
                };
                break;

            default:
                throw new \RuntimeException("Unknown query type: $type");
        }

        return $result;
    }

    // ─── Métodos legacy (sin cambios) ─────────────────────
    public static function query(string $sql, array $params = [], ?string $connectionName = null): \PDOStatement
    {
        $pdo = Conn::get($connectionName);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Query error: " . $e->getMessage() . " | SQL: $sql");
        }
    }

    public static function action(string $sql, array $params = [], ?string $connectionName = null): array
    {
        $pdo = Conn::get($connectionName);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'count'   => $stmt->rowCount(),
                'lastId'  => $pdo->lastInsertId(),
                'success' => true
            ];
        } catch (\PDOException $e) {
            throw new \RuntimeException("Write error (Action): " . $e->getMessage() . " | SQL: $sql");
        }
    }

    public static function stream(string $sql, array $params = [], ?string $connectionName = null): \Generator
    {
        $stmt = self::query($sql, $params, $connectionName);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public static function transaction(callable $callback, ?string $connectionName = null): mixed
    {
        $pdo = Conn::get($connectionName);
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Transaction failed: " . $e->getMessage());
        }
    }

    public static function batch(string $sql, array $params_list, ?string $connectionName = null): int
    {
        $pdo = Conn::get($connectionName);
        $totalAffected = 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            foreach ($params_list as $params) {
                $stmt->execute($params);
                $totalAffected += $stmt->rowCount();
            }
            $pdo->commit();
            return $totalAffected;
        } catch (\Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Batch error: " . $e->getMessage());
        }
    }
}