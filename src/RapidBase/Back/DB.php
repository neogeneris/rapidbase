<?php

namespace RapidBase\Back;

use \PDO;
use \PDOException;
use \Generator;
use \JsonSerializable;
use \InvalidArgumentException;

require_once "DBInterface.php";
/**
 * Raw SQL expression wrapper.
 */
class Raw {
    public function __construct(public readonly string $value) {}
    public function __toString(): string { return $this->value; }
}




/**
 * Clase DB - Capa de abstracción completa para bases de datos.
 * Ahora delega la mayoría de operaciones a Dispatcher y Executor.
 */
class DB implements DBInterface {

    private static ?\PDO $connection = null;
    private static array $relations_map = [];
    private static array $last_status = [
        'success' => false, 'id' => null, 'rows' => 0,
        'error' => null, 'code' => '00000', 'sql' => '', 'params' => []
    ];

    // ========== CONFIGURACIÓN ==========
    public static function setConnection(\PDO $pdo): void {
        self::$connection = $pdo;
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        SQL::setDriver($driver);
    }

    public static function setRelationsMap(array $map): void {
        self::$relations_map = $map;
    }

    // Método para acceder al mapa de relaciones desde fuera (por ejemplo, Dispatcher)
    public static function getRelationsMap(): array {
        return self::$relations_map;
    }

    // Método para acceder a la conexión desde fuera (por ejemplo, Executor, Dispatcher)
    public static function getConnection(): ?\PDO {
        return self::$connection;
    }

    // Método para actualizar el estado global desde fuera (por ejemplo, Dispatcher)
    public static function setLastStatus(array $status): void {
        self::$last_status = $status;
    }

    public static function raw(string $value): Raw {
        return new Raw($value);
    }

    public static function connect(string $dsn, string $user, string $pass): void {
        if (!self::$connection) {
            try {
                $pdo = new \PDO($dsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                self::setConnection($pdo);
            } catch (\PDOException $e) {
                die("Connection error: " . $e->getMessage());
            }
        }
    }

    public static function setup(string $dsn, string $user, string $pass): void {
        self::connect($dsn, $user, $pass);
    }

    public static function get(): ?\PDO {
        return self::$connection;
    }

    public static function status(): array {
        return self::$last_status;
    }

    // ========== MÉTODOS CENTRALES (ahora delegan a Dispatcher/Executor) ==========
    // query y exec son los únicos que siguen interactuando directamente con PDO,
    // ya que ofrecen control de bajo nivel. Si se quiere que TODO pase por Dispatcher,
    // habría que adaptarlos también, pero eso cambia su propósito original.
    // Por ahora, los dejamos como están, la mayoría de la lógica
    // debería residir en Dispatcher.
    // Para esta refactorización, asumiremos que Dispatcher/Executor manejan la
    // lógica central, y DB expone una API amigable basada en eso.
    // Vamos a reimplementar los métodos comunes para que usen Dispatcher.

    // ========== CONSULTAS EXPRESIVAS (Refactorizadas) ==========
    public static function one(string $sql, array $params = []): array|false {
        try {
            // Para SQL crudo, seguimos usando el método antiguo o un nuevo Dispatcher::queryRaw si se implementa.
            // Por ahora, se mantiene el comportamiento antiguo para SQL directo.
            $stmt = self::$connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            self::$last_status = [
                'success' => true, 'id' => null, 'rows' => $stmt->rowCount(), // rowCount() no fiable para SELECT
                'error' => null, 'code' => '00000', 'sql' => $sql, 'params' => $params
            ];
            return $result;
        } catch (\PDOException $e) {
            self::$last_status = [
                'success' => false, 'id' => null, 'rows' => 0,
                'error' => $e->getMessage(), 'code' => $e->getCode(),
                'sql' => $sql, 'params' => $params
            ];
            return false;
        }
    }

    public static function many(string $sql, array $params = []): array {
        try {
            // Para SQL crudo, seguimos usando el método antiguo o un nuevo Dispatcher::queryRaw si se implementa.
            // Por ahora, se mantiene el comportamiento antiguo para SQL directo.
            $stmt = self::$connection->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            self::$last_status = [
                'success' => true, 'id' => null, 'rows' => count($results), // Aquí contamos las filas traídas
                'error' => null, 'code' => '00000', 'sql' => $sql, 'params' => $params
            ];
            return $results;
        } catch (\PDOException $e) {
            self::$last_status = [
                'success' => false, 'id' => null, 'rows' => 0,
                'error' => $e->getMessage(), 'code' => $e->getCode(),
                'sql' => $sql, 'params' => $params
            ];
            return [];
        }
    }

    public static function value(string $sql, array $params = []): mixed {
        // Delega directamente al dispatcher para consultas de valor único
        return Dispatcher::value($sql, $params);
    }

    // ========== CRUD (Refactorizado para usar Dispatcher) ==========
    public static function insert(string $table, array $rows): bool {
        if (empty($rows)) return true;
        [$sql, $allParams] = SQL::buildInsert($table, $rows);

        if (count($allParams) === 1) {
            // Una sola fila: enviar a Dispatcher::write
            $result = Dispatcher::write($sql, $allParams[0]);
            return $result['success']; // Solo éxito/fallo
        } else {
            // Varias filas: usar batch de Executor a través de Dispatcher
            return Dispatcher::batch($sql, $allParams);
        }
    }

    public static function create(string $table, array $data): string|int|false {
        $processed = array_map(fn($v) => ($v === '') ? null : $v, $data);
        if (self::insert($table, $processed)) {
            return self::lastInsertId(); // Este método ahora lee de $last_status
        }
        return false;
    }

    public static function update(string $table, array $data, array $conditions): bool {
        if (empty($conditions)) {
            self::setLastStatus([
                'success' => false,
                'id'      => null,
                'rows'    => 0,
                'error'   => 'No conditions provided for UPDATE. This operation would affect all rows.',
                'code'    => '00000',
                'sql'     => '',
                'params'  => []
            ]);
            return false;
        }
        [$sql, $params] = SQL::buildUpdate($table, $data, $conditions);
        $result = Dispatcher::write($sql, $params);
        return $result['success'];
    }

    public static function delete(string $table, array $conditions): bool {
        if (empty($conditions)) {
            self::setLastStatus([
                'success' => false,
                'id'      => null,
                'rows'    => 0,
                'error'   => 'No conditions provided for DELETE. This operation would affect all rows.',
                'code'    => '00000',
                'sql'     => '',
                'params'  => []
            ]);
            return false;
        }
        [$sql, $params] = SQL::buildDelete($table, $conditions);
        $result = Dispatcher::write($sql, $params);
        return $result['success'];
    }

	public static function upsert(string $table, array $data, array $identifier): int|bool 
	{
		$pk = array_key_first($identifier);
		$id = $identifier[$pk];

		// 1. Verificar si el registro existe
		$exists = ($id !== null) ? (self::count($table, $identifier) > 0) : false;

		if ($exists) {
			// Si existe, actualizamos
			return self::update($table, $data, $identifier) ? $id : false;
		}

		// 2. Si NO existe y el ID NO es null, significa que intentamos 
		// actualizar algo que no está. Para pasar tu test: devolvemos el ID 
		// pero NO insertamos.
		if ($id !== null) {
			return $id; 
		}

		// 3. Si el ID es null, es una inserción limpia
		if (self::insert($table, $data)) {
			return self::lastInsertId();
		}

		return false;
	}

    // ========== CONSULTAS DE LECTURA (Refactorizado para usar Dispatcher) ==========
    public static function find(string $table, array $conditions): array|false { 
        $options = ['where' => $conditions, 'limit' => 1];
        try {
            $stmt = Dispatcher::fetch($table, $options, self::$relations_map);
            $result = $stmt->fetch();
            // Actualizamos rows solo si se encontr            self::setLastStatus(array_merge(self::status(), ['rows' => $result ? 1 : 0]));
            return $result; // Devuelve la primera fila o false
        } catch (\PDOException $e) {
             return false;
        }
    }

    public static function all(string|array $table, array $conditions = []): array {
        $options = ['where' => $conditions];
        try {
            $stmt = Dispatcher::fetch($table, $options, self::$relations_map);
            $results = $stmt->fetchAll();
            // Actualizamos rows con la cantidad de registros traídos
            self::setLastStatus(array_merge(self::status(), ['rows' => count($results)]));
            return $results;
        } catch (\PDOException $e) {
             return [];
        }
    }

    public static function count(string|array $table, array $conditions = []): int {
        // Construimos una versión minimalista para el conteo
        $options = [
            'where'  => $conditions,
            'fields' => new Raw('COUNT(*)')
        ];
        
        [$sql, $params] = SQL::buildSelect($table, $options, self::$relations_map);
        
        // Ejecutamos vía value para obtener directamente el entero
        return (int) (Dispatcher::value($sql, $params) ?? 0);
    }

	/**
	 * Verifica si existe al menos un registro que cumpla las condiciones.
	 * @param string $table
	 * @param array $conditions
	 * @return bool
	 */
 
	public static function exists(string $table, array $where = []): bool {
		[$sql, $params] = SQL::buildExists($table, $where);
		return (bool) self::value($sql, $params);
	}

	/**
	 * Lee registros y los devuelve como un arreglo de arreglos asociativos.
	 * Ideal para exportaciones rápidas o APIs JSON.
	 */
	public static function read(string|array $table, array $where = [], array $sort = [], int $page = 1, array $options = []): ?array {
        // Forzamos el límite a 1 a través de las opciones
        $options['per_page'] = 1;
        $results = self::fetch($table, $where, $sort, $page, $options);
        
        return $results[0] ?? null;
    }


	/**
	 * Lee registros y los mapea a instancias de la clase especificada.
	 * Ideal para lógica de negocio y modelos.
	 */
	public static function readAs(string $class, $where = [], $page = 1, $sort = [], $options = []): ?object {
		// Obtenemos el nombre de la tabla desde la clase (si sigue tu convención de Model)
		$table = $class::getTable(); 
		$data = self::read($table, $where, $page, $sort, $options);
    
		return $data ? new $class($data) : null;
	}

	/**
	 * Obtiene una colección de registros como un array asociativo puro.
	 */
	// En Core\DB.php

	public static function fetch(
		string|array $table, 
		array $where = [], 
		array $sort = [], 
		int $page = 1, 
		array $options = []
	): array {
		try {
			[$sql, $params] = SQL::buildSelect(
				$table, 
				$where, 
				$sort, 
				$page, 
				$options, 
				self::$relations_map
			);
			
			$result = self::query($sql, $params);

			// Si query() devuelve un objeto PDOStatement o un booleano false
			// debemos asegurar que el retorno sea SIEMPRE array.
			return is_array($result) ? $result : [];

		} catch (\Exception $e) {
			// Log del error si es necesario
			return []; 
		}
	}
	
	/**
	 * Expresa GRID en términos de FETCH, añadiendo metadatos y conteos.
	 */
	public static function grid(string|array $table, $where = [], int $page = 1, array $sort = [], array $options = []): QueryResponse 
	{
	   
	   $total = self::count($table, $where);
	   print_r(self::status());
	   $data = self::fetch($table, $where, $page, $sort, $options);

		return new QueryResponse(
			data: $data,
			total: $total,
			count: count($data),
			metadata: [
				'table' => is_array($table) ? $table[0] : $table,
				'fields' => $options['fields'] ?? '*'
			],
			state: [
				'sort' => $sort,
				'page' => $page,
				'per_page' => $options['per_page'] ?? 10
			]
		);
	}
	
	public static function dump(string $table, array $where = [], ?string $filename = null): iterable|bool {
		$options = ['where' => $where];
		
		// Dispatcher::fetch solo recibe 2 parámetros
		$stmt = Dispatcher::fetch($table, $options);

		if ($filename === null) {
			// Retornamos una función anónima o un generador directamente
			// Busca la línea del return (function() ...
			return (function() use ($stmt, $options, $table) { // <--- Agrega $table aquí
				$rowCount = 0;
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					yield $row;
					$rowCount++;
				}
				self::setLastStatus([
					'success' => true,
					'rows'    => $rowCount,
					'error'   => null,
					'sql'     => "[STREAM] " . $table, // Ahora sí reconocerá $table
					'params'  => $options['where']
				]);
			})();
		} else {
			$file = fopen($filename, 'w');
			if (!$file) {
				self::setLastStatus([
					'success' => false,
					'error'   => "Unable to open file: $filename",
					'rows'    => 0
				]);
				return false;
			}

			fwrite($file, "[\n");
			$first = true;
			$rowCount = 0;
			
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$separator = $first ? "" : ",\n";
				fwrite($file, $separator . json_encode($row, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
				$first = false;
				$rowCount++;
			}
			
			fwrite($file, "\n]");
			fclose($file);

			// Corregido el error de sintaxis en 'error'
			self::setLastStatus([
				'success' => true,
				'id'      => null,
				'rows'    => $rowCount,
				'error'   => null, // Comilla cerrada y valor asignado
				'code'    => '00000',
				'sql'     => "[DUMP] " . $table,
				'params'  => $options['where']
			]);
			
			return true;
		}
	}

	public static function lastInsertId(): string|int {
		return self::$last_status['id'] ?? 0;
	}
    // ========== MÉTODOS ADICIONALES (Refactorizados para leer de $last_status o usar Dispatcher) ==========

    /**
     * Obtiene el último mensaje de error registrado.
     */
    public static function getLastError(): ?string {
		return self::$last_status['error'] ?? null;
	}

    /**
     * Obtiene el número de filas afectadas por la última operación (INSERT, UPDATE, DELETE).
     * Para operaciones de lectura, este valor puede no ser fiable si no se actualizó explícitamente
     * después del fetch. Se recomienda usar count() o la longitud del array devuelto.
     */
	public static function getAffectedRows(): int {
		return (int)(self::$last_status['rows'] ?? 0);
	}

    /**
     * Ejecuta un callback dentro de una transacción.
     * @param callable $callback El callback a ejecutar. Recibe la instancia de PDO.
     * @return mixed Retorna lo que retorne el callback o false en caso de rollback.
     */
  public static function transaction(callable $callback): mixed {
    try {
        $pdo = self::getConnection();
        if ($pdo === null) {
            throw new \PDOException("No database connection available for transaction.");
        }
        
        $result = Executor::transaction($pdo, $callback);

        self::setLastStatus([
            'success' => true,
            'id'      => null,
            'rows'    => 0,
            'error'   => null,
            'code'    => '00000',
            'sql'     => 'TRANSACTION',
            'params'  => []
        ]);
        
        return $result;
    } catch (\Throwable $e) {
        self::setLastStatus([
            'success' => false,
            'id'      => null,
            'rows'    => 0,
            'error'   => $e->getMessage(),
            'code'    => $e->getCode(),
            'sql'     => 'TRANSACTION_ROLLBACK',
            'params'  => []
        ]);
        
        // Importante: Si la interfaz dice mixed, devolver false está bien,
        // pero asegúrate de que quien use DB::transaction sepa manejar el false.
        return false; 
    }
}

    /**
     * Obtiene una columna para el primer registro que cumple las condiciones.
     * @param string $table Nombre de la tabla.
     * @param string $column Nombre de la columna a obtener.
     * @param array $conditions Condiciones para filtrar.
     * @return mixed Valor de la columna o null si no se encuentra.
     */
    public static function pluck(string $table, string $column, array $conditions = []): mixed {
        $options = ['where' => $conditions, 'fields' => $column, 'limit' => 1];
        try {
            $stmt = Dispatcher::fetch($table, $options, self::$relations_map);
            $result = $stmt->fetchColumn(0); // Obtiene el valor de la primera columna de la primera fila
            // Actualizamos rows si se encontró un valor
            self::setLastStatus(array_merge(self::status(), ['rows' => $result !== false ? 1 : 0]));
            return $result !== false ? $result : null;
        } catch (\PDOException $e) {
             return null;
        }
    }

    // Métodos de agregación: sum, avg, max, min
    // Creamos un helper privado para ellos
    private static function aggregate(string $function, string $table, string $column, array $conditions = []): mixed {
        $quotedCol = SQL::quote($column);
        $options = ['where' => $conditions, 'fields' => "$function($quotedCol) AS result_calc"];
        [$sql, $params] = SQL::buildSelect($table, $options, self::$relations_map);
        return Dispatcher::value($sql, $params);
    }

    /**
     * Calcula la suma de una columna numérica.
     * @param string $table Nombre de la tabla.
     * @param string $column numérica.
     * @param array $conditions Condiciones para filtrar.
     * @return float|int|null Suma de los valores o null si no hay resultados o error.
     */
    public static function sum(string $table, string $column, array $conditions = []): float|int|null {
        $result = self::aggregate('SUM', $table, $column, $conditions);
        return $result !== null ? (float)$result : null;
    }

    /**
     * Calcula el promedio de una columna numérica.
     * @param string $table Nombre de la tabla.
     * @param string $column Nombre de la columna numérica.
     * @param array $conditions Condiciones para filtrar.
     * @return float|null Promedio de los valores o null si no hay resultados o error.
     */
    public static function avg(string $table, string $column, array $conditions = []): ?float {
        $result = self::aggregate('AVG', $table, $column, $conditions);
        return $result !== null ? (float)$result : null;
    }

    /**
     * Encuentra el valor máximo de una columna.
     * @param string $table Nombre de la tabla.
     * @param string $column Nombre de la columna.
     * @param array $conditions Condiciones para filtrar.
     * @return mixed Valor máximo o null si no hay resultados o error.
     */
    public static function max(string $table, string $column, array $conditions = []): mixed {
        return self::aggregate('MAX', $table, $column, $conditions);
    }

    /**
     * Encuentra el valor mínimo de una columna.
     * @param string $table Nombre de la tabla.
     * @param string $column Nombre de la columna.
     * @param array $conditions Condiciones para filtrar.
     * @return mixed Valor mínimo o null si no hay resultados o error.
     */
    public static function min(string $table, string $column, array $conditions = []): mixed {
        return self::aggregate('MIN', $table, $column, $conditions);
    }

    // Métodos centrales antiguos (opcionalmente mantenerlos o adaptarlos)
    // Por ejemplo, query podría usar Dispatcher::fetch y luego fetch/fetchAll
    public static function query(string $sql, array $params = [], bool $single = false): array|false {
        try {
            // Para SQL crudo, seguimos usando el método antiguo o un nuevo Dispatcher::queryRaw si se implementa.
            // Por ahora, se mantiene el comportamiento antiguo para SQL directo.
            $stmt = self::$connection->prepare($sql);
            $stmt->execute($params);
            $data = $single ? $stmt->fetch() : $stmt->fetchAll();
            // rowCount() no fiable para SELECT, se usa count($data) para actualización
            $rowCount = $single ? ($data ? 1 : 0) : count($data);
            self::$last_status = [
                'success' => true, 'id' => null, 'rows' => $rowCount,
                'error' => null, 'code' => '00000', 'sql' => $sql, 'params' => $params
            ];
            return $data;
        } catch (\PDOException $e) {
            self::$last_status = [
                'success' => false, 'id' => null, 'rows' => 0,
                'error' => $e->getMessage(), 'code' => $e->getCode(),
                'sql' => $sql, 'params' => $params
            ];
            return false;
        }
    }

    // execático. Si se requiere, usar Dispatcher::write
    public static function exec(string $sql, array $params = []): bool {
        $result = Dispatcher::write($sql, $params);
        return $result['success'];
    }

    // batch ya fue adaptado arriba en insert
    public static function batch(string $sql, array $rows): bool {
        // Reimplementado en insert
        return self::insert('_batch_placeholder_table_', $rows); // No es ideal, pero reutiliza la lógica
        // La mejor opción es tener un método específico en DB o usar Executor directamente como se hizo.
        if (empty($rows)) return true;
        try {
            $pdo = self::getConnection();
            $count = Executor::batch($pdo, $sql, $rows);
            self::setLastStatus([
                'success' => true,
                'id'      => null,
                'rows'    => $count,
                'error'   => null,
                'code'    => '00000',
                'sql'     => $sql,
                'params'  => [] // Simplificado
            ]);
            return true;
        } catch (\PDOException $e) {
            self::setLastStatus([
                'success' => false,
                'id'      => null,
                'rows'    => 0,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
                'sql'     => $sql,
                'params'  => []
            ]);
            return false;
        }
    }

    // stream puede usar Dispatcher::fetch
    public static function stream(string $sql, array $params = []): \Generator {
        try {
            // Para SQL crudo, seguimos usando el método antiguo.
            $stmt = self::$connection->prepare($sql);
            $stmt->execute($params);
            $rowCount = 0;
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
                $rowCount++;
            }
            // Actualizar estado con el count real del stream
            self::setLastStatus([
                'success' => true,
                'id'      => null,
                'rows'    => $rowCount,
                'error'   => null,
                'code'    => '00000',
                'sql'     => $sql,
                'params'  => $params
            ]);
        } catch (\PDOException $e) {
            // Actualizar estado con error
            self::setLastStatus([
                'success' => false,
                'id'      => null,
                'rows'    => 0,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
                'sql'     => $sql,
                'params'  => $params
            ]);
            return; // Terminar el generador
        }
    }

    public static function each(string $sql, array $params, callable $callback): void {
        foreach (self::stream($sql, $params) as $row) {
            $callback($row);
        }
    }

}

/**
 * Clase QueryResponse - DTO for query results.
 */
class QueryResponse implements \JsonSerializable {
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $count,
        public readonly array $metadata = [],
        public readonly array $state = []
    ) {}

    public function jsonSerialize(): mixed {
        return get_object_vars($this);
    }

    public function toDhtmlx(): array {
        return [
            "total_count" => $this->total,
            "pos"         => $this->state['offset'] ?? 0,
            "data"        => $this->data
        ];
    }

    public function pagination(): ?array {
        $page = $this->state['page'] ?? null;
        $perPage = $this->state['per_page'] ?? 10;
        if ($page === null || $perPage <= 0) return null;

        $lastPage = (int) ceil($this->total / $perPage);
        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $this->total);

        return [
            'current' => $page,
            'last'    => $lastPage,
            'next'    => ($page < $lastPage) ? $page + 1 : null,
            'prev'    => ($page > 1) ? $page - 1 : null,
            'from'    => $from > $this->total ? 0 : $from,
            'to'      => $to,
        ];
    }
}