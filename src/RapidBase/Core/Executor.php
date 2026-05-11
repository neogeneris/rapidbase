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
		$start = microtime(true);
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

		// Forzar conversión de parámetros numéricos a entero (evita error en MySQL con LIMIT/OFFSET)
		$params = array_map(function ($p) {
			if (is_string($p) && ctype_digit($p)) {
				return (int)$p;
			}
			return $p;
		}, $params);

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

				$rows = $stmt->fetchAll(\PDO::FETCH_NUM);

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

				$total = count($rows);
				if (!empty($map) && isset($map['_total'])) {
					$totalIndex = $map['_total'];
					if (!empty($rows) && isset($rows[0][$totalIndex])) {
						$total = (int) $rows[0][$totalIndex];
					}
				}

				$privateKeys = array_filter(array_keys($map), fn($k) => str_starts_with($k, '_'));
				if (!empty($privateKeys)) {
					$indicesToRemove = array_intersect_key($map, array_flip($privateKeys));
					$indexes = array_values($indicesToRemove);
					foreach ($rows as &$row) {
						foreach ($indexes as $idx) {
							unset($row[$idx]);
						}
						$row = array_values($row);
					}
					unset($row);
					$map = array_diff_key($map, array_flip($privateKeys));
					$map = self::reindexMap($map);
				}

				if ($cq instanceof CompiledQuery) {
					$cq->setProjectionMap($map);
				}

				$result['rows']    = $rows;
				$result['count']   = count($rows);
				$result['cols']    = array_keys($map);
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
				// Añadir metadata aquí antes de retornar directamente
				$duration = (microtime(true) - $start) * 1000;
				$result['metadata']['execution_time'] = $duration;
				return $result;

			default:
				throw new \RuntimeException("Unknown query type: $type");
		}

		// Para los casos que salen con break, añadir metadata antes de retornar
		$duration = (microtime(true) - $start) * 1000;
		$result['metadata']['execution_time'] = $duration;
		return $result;
	}

    /**
     * Re‑indexa un mapa de columna → índice, manteniendo el orden original
     * y asignando nuevos índices secuenciales (0,1,2,…).
     */
    private static function reindexMap(array $map): array
    {
        asort($map);
        $index = 0;
        $newMap = [];
        foreach ($map as $name => $oldIndex) {
            $newMap[$name] = $index++;
        }
        return $newMap;
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

            $lastId = null;
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                $sqlUpper = strtoupper(trim($sql));
                if (strpos($sqlUpper, 'INSERT') === 0 || strpos($sqlUpper, 'INTO') !== false) {
                    try {
                        $lastId = $pdo->lastInsertId();
                    } catch (\PDOException $e) {
                        $lastId = null;
                    }
                }
            } else {
                try {
                    $lastId = $pdo->lastInsertId();
                } catch (\PDOException $e) {
                    $lastId = null;
                }
            }

            return [
                'count'   => $stmt->rowCount(),
                'lastId'  => $lastId,
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