<?php

namespace RapidBase\Core;

use RapidBase\Core\Cache\CountCache;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;
use PDO;

class X
{
    private string $connectionId;
    private string|array|CompiledQuery|null $table = null;
    private array $filter = [];
    private bool $useCache = false;
    private int $cacheTtl = 3600;
    private ?int $countTtl = null;
    private string $totalStrategy = 'auto';

    private function __construct(string $connectionId)
    {
        $this->connectionId = $connectionId;
    }

    public static function con(string $connectionId): self
    {
        return new self($connectionId);
    }
	
	/**
	 * Registra (o reemplaza) la conexión asociada a este identificador.
	 * Equivale a DB::setup(), pero usando el connectionId de la instancia.
	 *
	 * @param string $dsn
	 * @param string $user
	 * @param string $pass
	 * @return $this
	 */
	public function connect(string $dsn, string $user = '', string $pass = ''): self
	{
		DB::setup($dsn, $user, $pass, $this->connectionId);
		return $this;
	}

	/**
	 * Cierra la conexión asociada a esta instancia (la elimina del pool).
	 *
	 * @return $this
	 */
	public function close(): self
	{
		Conn::close($this->connectionId);
		return $this;
	}

    /**
     * Define la tabla o subconsulta para la operación.
     * 
     * @param string|array|CompiledQuery $table Nombre de tabla, array con JOIN, o CompiledQuery
     * @param array $filter Condiciones WHERE iniciales
     */
    public function from(string|array|CompiledQuery $table, array $filter = []): self  // ← MODIFICADO
    {
        $this->table = $table;
        $this->filter = $filter;
        return $this;
    }

    /**
     * Alias semántico para INSERT operations.
     * Funciona igual que from() pero para operaciones de inserción.
     */
    public function into(string|array|CompiledQuery $table, array $filter = []): self  // ← NUEVO
    {
        return $this->from($table, $filter);
    }

    public function cached(int $ttl = 3600): self
    {
        $this->useCache = true;
        $this->cacheTtl = $ttl;
        return $this;
    }

    public function withCountTtl(int $ttl): self
    {
        $this->countTtl = $ttl;
        return $this;
    }

    public function totalStrategy(string $strategy): self
    {
        if (!in_array($strategy, ['auto', 'window', 'separate'])) {
            throw new \InvalidArgumentException("Invalid total strategy: $strategy");
        }
        $this->totalStrategy = $strategy;
        return $this;
    }

    private function useConnection(): void
    {
        Conn::get($this->connectionId);
        Conn::select($this->connectionId);
    }

    public function select(
        string|array $fields = '*',
        mixed $pagination = null,
        string|array $sort = [],
        bool $withTotal = false
    ): XResponse {
        return $this->executeSelect($fields, $pagination, $sort, $withTotal);
    }

    public function first(): ?array
    {
        $this->useConnection();
        $result = Gateway::select(
            '*', $this->table, $this->filter, [], [], [],
            [0, 1], PDO::FETCH_ASSOC
        );
        return $result['data'][0] ?? null;
    }

    /**
     * Verifica si existe al menos un registro que cumpla las condiciones.
     */
    public function exists(): bool  // ← NUEVO
    {
        $this->useConnection();
        return Gateway::exists($this->resolveTable(), $this->filter);
    }

    public function count(): int
    {
        $this->useConnection();
        $ttl = $this->countTtl ?? 300;
        $originalTtl = null;
        if (method_exists(CountCache::class, 'getTtl')) {
            $originalTtl = CountCache::getTtl();
            CountCache::setTtl($ttl);
        }
        $result = Gateway::count($this->table, $this->filter);
        if ($originalTtl !== null) {
            CountCache::setTtl($originalTtl);
        }
        return $result;
    }

    /**
     * Grid con paginación flexible.
     *
     * @param string|array $fields
     * @param int|array|null $pagination  Número de página (int) o array [page, perPage], o null (usa 1,30)
     * @param string|array|null $sort     Orden: ej. '-id' o ['-id', 'name'] o 'id ASC'
     * @param int $countTtl               TTL para el total (solo cuando se recalcula)
     * @return array
     */
    public function grid($fields = '*', $pagination = null, $sort = null, int $countTtl = 300): array
    {
        // Normalizar paginación usando Q::page (ya soporta int o array)
        if ($pagination === null) {
            $pagination = [1, 30];
        }
        [$offset, $limit] = Q::page($pagination);

        // Normalizar sort: si es string, lo dejamos como viene (Gateway lo entiende)
        if (is_string($sort) && str_contains($sort, ',')) {
            $sort = array_map('trim', explode(',', $sort));
        }

        $res = $this->executeSelect($fields, [$offset, $limit], $sort, true, $countTtl);

        $page = $limit > 0 ? (int)($offset / $limit) + 1 : 1;

        return [
            'data'      => $res->data,
            'total'     => $res->total,
            'columns'   => $res->columns,
            'titles'    => $res->titles,
            'limit'     => $limit,
            'page'      => $page,
            'last_page' => $limit > 0 ? (int) ceil($res->total / $limit) : 1,
            'debug'     => ['sql' => $res->sql],
            'stats'     => ['duration' => $res->durationMs],
        ];
    }

    public function insert(array $data): XResponse
    {
        $this->useConnection();
        $affected = Gateway::insert($this->resolveTable(), $data);
        $status = Gateway::status();
        return new XResponse(
            data: [], sql: $status['sql'] ?? '', durationMs: $status['duration'] ?? 0,
            success: $affected > 0, affected: (int) $affected, lastId: $affected
        );
    }

    /**
     * Insertar o actualizar según conflicto de columnas.
     * 
     * @param array $data Datos a insertar/actualizar
     * @param array $conflictColumns Columnas que definen conflicto (ej: ['email'])
     */
    public function upsert(array $data, array $conflictColumns = []): XResponse  // ← NUEVO
    {
        $this->useConnection();
        $result = Gateway::upsert($this->resolveTable(), $data, $conflictColumns);
        return new XResponse(
            data: [], 
            sql: $result['sql'] ?? '', 
            durationMs: $result['duration'] ?? 0,
            success: $result['success'], 
            affected: $result['count'] ?? 0, 
            lastId: $result['lastId'] ?? null
        );
    }

    public function update(array $data, ?int $limit = null): XResponse
    {
        $this->useConnection();
        $table = $this->resolveTable();
        if ($limit !== null) {
            $compiled = Q::from($table, $this->filter)->update($data, $limit);
            $result = $compiled->run();
            CountCache::invalidate($table);
            return new XResponse(
                data: [], sql: $compiled->getSql(), durationMs: 0,
                success: ($result['count'] ?? 0) > 0, affected: $result['count'] ?? 0
            );
        }
        $affected = Gateway::update($table, $data, $this->filter);
        $status = Gateway::status();
        return new XResponse(
            data: [], sql: $status['sql'] ?? '', durationMs: $status['duration'] ?? 0,
            success: $affected > 0, affected: $affected
        );
    }

    public function delete(?int $limit = null): XResponse
    {
        $this->useConnection();
        $table = $this->resolveTable();
        $compiled = Q::from($table, $this->filter)->delete($limit);
        $result = $compiled->run();
        CountCache::invalidate($table);
        return new XResponse(
            data: [], sql: $compiled->getSql(), durationMs: 0,
            success: ($result['count'] ?? 0) > 0, affected: $result['count'] ?? 0
        );
    }

    public function raw(string $sql): XResponse
    {
        $this->useConnection();
        $start = microtime(true);
        $upper = strtoupper(trim($sql));
        $isSelect = str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'DESCRIBE')
            || str_starts_with($upper, 'SHOW') || str_starts_with($upper, 'EXPLAIN')
            || str_starts_with($upper, 'PRAGMA');
        if ($isSelect) {
            $stmt = Executor::query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'] ?? "col_$i";
            }
            $elapsed = round((microtime(true) - $start) * 1000, 4);
            return new XResponse(
                data: $rows, sql: $sql, durationMs: $elapsed, total: count($rows),
                columns: $columns, titles: array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $columns)
            );
        } else {
            $result = Executor::action($sql);
            $elapsed = round((microtime(true) - $start) * 1000, 4);
            return new XResponse(
                data: [], sql: $sql, durationMs: $elapsed,
                success: $result['success'] ?? false, affected: $result['count'] ?? 0
            );
        }
    }

    public function toSQL(CompiledQuery $compiled): string
    {
        return $compiled->getSql();
    }


    private function resolveStrategy(): string
    {
        if ($this->totalStrategy === 'auto') {
            $driver = Conn::getDriver($this->connectionId);
            // MySQL: window functions son lentas, preferir separate
            return ($driver === 'mysql') ? 'separate' : 'window';
        }
        return $this->totalStrategy;
    }

    /**
     * Ejecuta la consulta y devuelve XResponse.
     */
    private function executeSelect(
        string|array $fields,
        mixed $pagination,
        mixed $sort,
        bool $withTotal = false,
        ?int $countTtl = null
    ): XResponse {
        $this->useConnection();
        $strategy = $this->resolveStrategy();

        // Sin total: consulta directa (con o sin caché)
        if (!$withTotal) {
            if ($this->useCache) {
                $result = Gateway::selectCached(
                    $fields, $this->table, $this->filter, [], [], (array)$sort,
                    $pagination, $this->cacheTtl, PDO::FETCH_NUM
                );
            } else {
                $result = Gateway::select(
                    $fields, $this->table, $this->filter, [], [], (array)$sort,
                    $pagination, PDO::FETCH_NUM
                );
            }
            $rows = $result['data'] ?? [];
            $cols = $result['metadata']['cols'] ?? [];
            $sql = $result['metadata']['sql'] ?? '';
            $duration = $result['metadata']['execution_time'] ?? 0;
            $rawPage = $result['page'] ?? 0;
            $rawLimit = $result['limit'] ?? 0;
            if ($pagination !== null) {
                $page = max(1, $rawPage);
                $limit = max(1, $rawLimit);
            } else {
                $page = 1;
                $limit = max(1, $rawLimit ?: 30);
            }
            $total = count($rows);
            $titles = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $cols);
            return new XResponse(
                data: $rows, sql: $sql, durationMs: $duration,
                total: $total, page: $page, limit: $limit,
                columns: $cols, titles: $titles, success: true
            );
        }

        // --- Con total ---
        if ($strategy === 'window') {
            // Expandir * a columnas reales para evitar '*' en la proyección
            $selectFields = $fields;
            if ($fields === '*') {
                $map = SchemaMap::getMap();
                $tableSchema = $map['tables'][$this->resolveTable()] ?? null;
                if ($tableSchema) {
                    $selectFields = array_keys($tableSchema);
                }
            }

            $query = Q::from($this->table, $this->filter);
            $compiled = $query->select($selectFields, $pagination, (array)$sort, null, [], true);
            $result = $compiled->run(PDO::FETCH_NUM);
            $rows = $result['rows'] ?? [];
            $projectionMap = $compiled->getProjectionMap();

            if (!empty($rows)) {
                // Hay filas → extraer _total de la ventana COUNT(*) OVER()
                if (isset($projectionMap['_total'])) {
                    $totalIndex = $projectionMap['_total'];
                    $total = (int) $rows[0][$totalIndex];
                    foreach ($rows as &$row) {
                        unset($row[$totalIndex]);
                        $row = array_values($row);
                    }
                    unset($row);
                    unset($projectionMap['_total']);
                } else {
                    $total = count($rows);
                }

                // Cachear el total para futuras páginas (evita consultar COUNT(*) OVER() de nuevo)
                CountCache::remember(
                    $this->resolveTable(),
                    $this->filter,
                    fn() => $total
                );
            } else {
                // Página vacía → recuperar total de caché o calcularlo una sola vez
                $total = CountCache::remember(
                    $this->resolveTable(),
                    $this->filter,
                    fn() => $this->count()
                );
            }

            // Reordenar proyección si expandimos *
            if ($selectFields !== '*' && is_array($selectFields)) {
                $orderedMap = [];
                $i = 0;
                foreach ($selectFields as $field) {
                    if (isset($projectionMap[$field])) {
                        $orderedMap[$field] = $i++;
                    }
                }
                $projectionMap = $orderedMap;
            }

            // Eliminar posibles '*' residuales
            if (isset($projectionMap['*'])) {
                unset($projectionMap['*']);
                $i = 0;
                foreach ($projectionMap as $k => $v) {
                    $projectionMap[$k] = $i++;
                }
            }

            $cols = array_keys($projectionMap);
            $sql = $compiled->getSql();
            $duration = $result['metadata']['execution_time'] ?? 0;
            if ($pagination !== null) {
                if (is_array($pagination)) {
                    $limit = max(1, (int)($pagination[1] ?? 30));
                    $offset = max(0, (int)($pagination[0] ?? 0));
                    $page = (int)(($offset / $limit) + 1);
                } else {
                    $limit = max(1, (int)$pagination);
                    $page = 1;
                }
            } else {
                $page = 1;
                $limit = 30;
            }
            $titles = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $cols);
            return new XResponse(
                data: $rows, sql: $sql, durationMs: $duration,
                total: $total, page: $page, limit: $limit,
                columns: $cols, titles: $titles, success: true
            );
        }

        // strategy === 'separate'
        $originalTtl = null;
        if ($countTtl !== null && method_exists(CountCache::class, 'getTtl')) {
            $originalTtl = CountCache::getTtl();
            CountCache::setTtl($countTtl);
        }
        try {
            $total = CountCache::remember(
                $this->resolveTable(),
                $this->filter,
                fn() => $this->count()
            );
        } finally {
            if ($originalTtl !== null) {
                CountCache::setTtl($originalTtl);
            }
        }

        if ($this->useCache) {
            $result = Gateway::selectCached(
                $fields, $this->table, $this->filter, [], [], (array)$sort,
                $pagination, $this->cacheTtl, PDO::FETCH_NUM
            );
        } else {
            $result = Gateway::select(
                $fields, $this->table, $this->filter, [], [], (array)$sort,
                $pagination, PDO::FETCH_NUM
            );
        }
        $rows = $result['data'] ?? [];
        $cols = $result['metadata']['cols'] ?? [];
        $sql = $result['metadata']['sql'] ?? '';
        $duration = $result['metadata']['execution_time'] ?? 0;
        $rawPage = $result['page'] ?? 0;
        $rawLimit = $result['limit'] ?? 0;
        if ($pagination !== null) {
            $page = max(1, $rawPage);
            $limit = max(1, $rawLimit);
        } else {
            $page = 1;
            $limit = max(1, $rawLimit ?: 30);
        }
        $titles = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $cols);
        return new XResponse(
            data: $rows, sql: $sql, durationMs: $duration,
            total: $total, page: $page, limit: $limit,
            columns: $cols, titles: $titles, success: true
        );
    }

    private function resolveTable(): string
    {
        if ($this->table === null) {
            throw new \RuntimeException("No table selected. Call ->from() before using grid(), select(), etc.");
        }
        
        // Si es CompiledQuery, no podemos dar un nombre de tabla simple
        if ($this->table instanceof CompiledQuery) {
            throw new \RuntimeException("Cannot use subquery (CompiledQuery) for operations that require a table name (INSERT, UPDATE, DELETE, COUNT with TTL, etc.).");
        }
        
        if (is_string($this->table)) {
            return $this->table;
        }
        return is_string($this->table[0] ?? '') ? $this->table[0] : '';
    }

    /**
     * Realiza un ping a la conexión activa.
     *
     * @param int $retries No usado, se mantiene por compatibilidad
     * @param int $delayMs No usado
     * @return array [success, latency, error]
     */
    public function ping(int $retries = 1, int $delayMs = 100): array
    {
        try {
            $pdo = Conn::get($this->connectionId);
            $start = microtime(true);
            $pdo->query('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            return [
                'success' => true,
                'latency' => $latency,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'latency' => null,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns a clean schema description with tables, views, and relations.
     *
     * @return array
     */
    public function description(): array
    {
        $this->useConnection();

        $tables = $this->table;
        if (empty($tables)) {
            $tables = array_keys(SchemaMap::getMap()['tables'] ?? []);
        } elseif (is_string($tables)) {
            $tables = [$tables];
        }

        $map = SchemaMap::getMap();
        $tableDefs = [];
        $relations = [];

        foreach ($tables as $tableName) {
            $tableInfo = $map['tables'][$tableName] ?? null;

            if (!$tableInfo) {
                $tableDefs[] = [
                    'name'        => $tableName,
                    'columns'     => [],
                    'primaryKeys' => [],
                ];
                continue;
            }

            $columns = [];
            $pks     = [];
            foreach ($tableInfo as $colName => $def) {
                $columns[$colName] = $def['type'] ?? 'string';
                if ($def['primary'] ?? false) {
                    $pks[] = $colName;
                }
            }
            $tableDefs[] = [
                'name'        => $tableName,
                'columns'     => $columns,
                'primaryKeys' => $pks,
            ];

            // Outgoing relations
            foreach ($map['relationships']['from'][$tableName] ?? [] as $target => $rel) {
                $relations[] = [
                    'sourceTable'  => $tableName,
                    'sourceColumn' => $rel['local_key'],
                    'targetTable'  => $target,
                    'targetColumn' => $rel['foreign_key'],
                    'type'         => $rel['type'] ?? 'belongsTo',
                ];
            }
            // Incoming relations
            foreach ($map['relationships']['to'][$tableName] ?? [] as $source => $rel) {
                $relations[] = [
                    'sourceTable'  => $source,
                    'sourceColumn' => $rel['local_key'],
                    'targetTable'  => $tableName,
                    'targetColumn' => $rel['foreign_key'],
                    'type'         => $rel['type'] ?? 'hasMany',
                ];
            }
        }

        return [
            'tables'    => $tableDefs,
            'views'     => [],
            'relations' => $relations,
        ];
    }
}