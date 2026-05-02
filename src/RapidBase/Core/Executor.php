<?php

namespace RapidBase\Core;

use RapidBase\Core\SQL\CompiledQuery;

class Executor
{
    public static function execute(
        CompiledQuery $cq,
        int $fetchMode = \PDO::FETCH_NUM,
        ?string $class = null,
        ?string $connectionName = null
    ): mixed {
        switch ($cq->getType()) {
            case CompiledQuery::SELECT:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);

                if ($fetchMode === \PDO::FETCH_CLASS && $class !== null) {
                    return $stmt->fetchAll($fetchMode, $class);
                }

                if ($fetchMode !== \PDO::FETCH_NUM) {
                    return $stmt->fetchAll($fetchMode);
                }

                $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
                $map = $cq->getProjectionMap();
                
                if (empty($map) && !empty($rows)) {
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
                }
                
                if (empty($map)) {
                    return $rows;
                }

                return array_map(function ($row) use ($map) {
                    $assoc = [];
                    foreach ($map as $alias => $idx) {
                        $assoc[$alias] = $row[$idx] ?? null;
                    }
                    return $assoc;
                }, $rows);

            case CompiledQuery::COUNT:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);
                return (int) $stmt->fetchColumn();

            case CompiledQuery::EXISTS:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);
                return (bool) $stmt->fetchColumn();

            case CompiledQuery::INSERT:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                $result['action'] = 'insert';
                return $result;

            case CompiledQuery::UPDATE:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                $result['action'] = 'update';
                return $result;

            case CompiledQuery::DELETE:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                $result['action'] = 'delete';
                return $result;

            case CompiledQuery::UPSERT:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                $result['action'] = 'upsert';
                return $result;

            default:
                throw new \RuntimeException("Unknown compiled query type: {$cq->getType()}");
        }
    }

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