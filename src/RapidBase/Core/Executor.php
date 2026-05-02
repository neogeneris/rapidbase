<?php

namespace RapidBase\Core;

/**
 * Clase Executor - Ejecutor atómico de sentencias SQL.
 * Centraliza la ejecución, el manejo de errores y las transacciones.
 *
 * Ahora todos los métodos aceptan un parámetro opcional $connectionName
 * para seleccionar la conexión deseada del pool de Conn.
 */
class Executor
{
    /**
     * Ejecuta una sentencia SELECT y retorna el PDOStatement.
     *
     * @param string      $sql
     * @param array       $params
     * @param string|null $connectionName Nombre de conexión en Conn (null = default)
     * @return \PDOStatement
     */
    public static function query(string $sql, array $params = [], ?string $connectionName = null): \PDOStatement
    {
        $pdo = Conn::get($connectionName);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Error de Lectura (Query): " . $e->getMessage() . " | SQL: $sql");
        }
    }

    /**
     * Ejecuta sentencias de escritura (INSERT, UPDATE, DELETE).
     *
     * @param string      $sql
     * @param array       $params
     * @param string|null $connectionName
     * @return array
     */
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
            throw new \RuntimeException("Error de Escritura (Action): " . $e->getMessage() . " | SQL: $sql");
        }
    }

    /**
     * Crea un Generador (Cursor) para iterar resultados masivos sin agotar la RAM.
     */
    public static function stream(string $sql, array $params = [], ?string $connectionName = null): \Generator
    {
        $stmt = self::query($sql, $params, $connectionName);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Ejecuta una serie de operaciones dentro de una transacción atómica.
     */
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
            throw new \RuntimeException("Transacción fallida: " . $e->getMessage());
        }
    }

    /**
     * Ejecuta la misma sentencia SQL para múltiples conjuntos de parámetros.
     */
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
            throw new \RuntimeException("Error en procesamiento por lotes (Batch): " . $e->getMessage());
        }
    }
}