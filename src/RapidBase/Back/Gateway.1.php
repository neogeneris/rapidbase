<?php

namespace RapidBase\Back;

use \Exception;
use \PDOStatement;

/**
 * Clase Gateway - El punto de control y despacho del Framework.
 * Une la Fundición (SQL), el Pool (Conn) y el Obrero (Executor).
 */
class Gateway {

    /** @var array Guarda el rastro de la última operación para el monitor */
    private static array $lastStatus = [];

    /**
     * LECTURA: Ejecuta consultas SELECT.
     */
    public static function select(
        mixed $fields   = '*', 
        mixed $table    = '', 
        array $where    = [], 
        array $groupBy  = [], 
        array $having   = [], 
        array $sort     = [], 
        int $page       = 1, 
        int $perPage    = 10
    ): PDOStatement {
        
        [$sql, $params] = SQL::buildSelect($fields, $table, $where, $groupBy, $having, $sort, $page, $perPage);

        try {
            // CORRECCIÓN: Firma (sql, params, pdo)
            $stmt = Executor::query($sql, $params, Conn::get());
            
            self::logStatus(true, $sql, $params);
            return $stmt;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * ACCIÓN: Ejecuta INSERT, UPDATE o DELETE.
     */
	public static function action(string $type, ...$args): array {
		$method = 'build' . ucfirst(strtolower($type));
		
		if (!method_exists(SQL::class, $method)) {
			throw new Exception("El método de construcción [$method] no existe en la clase SQL.");
		}

		[$sql, $params] = SQL::$method(...$args);

		try {
			// Obtenemos el resultado del Executor (que incluye count, lastId, success)
			$res = Executor::action($sql, $params, Conn::get());
			
			// ¡ESTA ES LA LÍNEA CLAVE!: Sincronizamos el estado para que DB::lastInsertId() funcione
			self::logStatus(true, $sql, $params, null, [
				'id'    => $res['lastId'], 
				'rows'  => $res['count']
			]);
			
			return $res;
		} catch (Exception $e) {
			self::logError($e, $sql, $params);
			throw $e;
		}
	}
    /**
     * EXISTENCIA: Verifica si un registro existe.
     */
    public static function exists(string $table, array $where): bool {
        [$sql, $params] = SQL::buildExists($table, $where);
        
        try {
            // CORRECCIÓN: Firma (sql, params, pdo)
            $stmt = Executor::query($sql, $params, Conn::get());
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $exists = (bool) ($row['check'] ?? false);
            
            self::logStatus(true, $sql, $params);
            return $exists;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            return false;
        }
    }

    /**
     * CONTEO: Ejecuta la lógica de conteo.
     */
    public static function count(mixed $table, array $where = [], array $groupBy = []): int {
        [$sql, $params] = SQL::buildCount($table, $where, $groupBy);
        
        try {
            // CORRECCIÓN: Firma (sql, params, pdo)
            $stmt = Executor::query($sql, $params, Conn::get());
            $total = (int) $stmt->fetchColumn();
            
            self::logStatus(true, $sql, $params);
            return $total;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            return 0;
        }
    }

    /**
     * BATCH: Ejecuta inserciones masivas.
     */
    public static function batch(string $table, array $data): bool {
        [$sql, $paramsList] = SQL::buildInsert($table, $data);
        
        try {
            // CORRECCIÓN: Firma (sql, paramsList, pdo)
            $count = Executor::batch($sql, $paramsList, Conn::get());
            
            self::logStatus(true, $sql . " [BATCH]", $paramsList[0] ?? [], null, ['count' => $count]);
            return true;
        } catch (Exception $e) {
            self::logError($e, $sql, []);
            return false;
        }
    }

    /**
     * Retorna el estado de la última operación ejecutada.
     */
    public static function status(): array {
        return self::$lastStatus;
    }

    /**
     * Registra un éxito en el estado interno.
     */
    private static function logStatus(bool $success, string $sql, array $params, string $error = null, array $extra = []): void {
        self::$lastStatus = array_merge([
            'success'   => $success,
            'sql'       => $sql,
            'params'    => $params,
            'error'     => $error,
            'timestamp' => microtime(true)
        ], $extra);

        // --- EL CORAZÓN DE LOS EVENTOS ---
        if (class_exists(__NAMESPACE__ . '\Event')) {
            $eventName = $success ? 'db.success' : 'db.error';
            Event::fire($eventName, self::$lastStatus);
            Event::fire('db.log', self::$lastStatus);
        }
    }

    /**
     * Registra un error en el estado interno.
     */
    private static function logError(Exception $e, string $sql, array $params): void {
        self::logStatus(false, $sql, $params, $e->getMessage(), ['code' => $e->getCode()]);
    }
}