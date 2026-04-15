<?php

namespace Core;

/**
 * Clase Executor - Ejecutor atómico de sentencias SQL.
 * Centraliza la ejecución, el manejo de errores y las transacciones.
 */
class Executor {

    /**
     * Ejecuta una sentencia SELECT y retorna el PDOStatement.
     */
    public static function query(string $sql, array $params = [], ?\PDO $pdo = null): \PDOStatement {
        $pdo = $pdo ?? Conn::get();
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
     */
    public static function action(string $sql, array $params = [], ?\PDO $pdo = null): array {
        $pdo = $pdo ?? Conn::get();
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
     * CORRECCIÓN: Se ajusta la llamada a self::query para coincidir con la firma (sql, params, pdo).
     */
    public static function stream(string $sql, array $params = [], ?\PDO $pdo = null): \Generator {
        // Ahora pasamos los argumentos en el orden correcto
        $stmt = self::query($sql, $params, $pdo);
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Ejecuta una serie de operaciones dentro de una transacción atómica.
     */
    public static function transaction(callable $callback, ?\PDO $pdo = null): mixed {
        $pdo = $pdo ?? Conn::get();
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
    public static function batch(string $sql, array $params_list, ?\PDO $pdo = null): int {
        $pdo = $pdo ?? Conn::get();
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