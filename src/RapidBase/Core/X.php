<?php

namespace RapidBase\Core;

use RapidBase\Core\Cache\CountCache;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;
use PDO;

/**
 * X – Ejecutor fluido que delega en Gateway (eventos + cache) y devuelve XResponse.
 * Optimizado: select() ya NO ejecuta COUNT(*) automático.
 */
class X
{
    private string $connectionId;
    private mixed $table = null;
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

    public function from(string|array $table, array $filter = []): self
    {
        $this->table = $table;
        $this->filter = $filter;
        return $this;
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

    public function grid(
        string|array $fields = '*',
        int $page = 1,
        int $limit = 30,
        array|string $sort = [],
        int $countTtl = 300
    ): array {
        $xRes = $this->executeSelect($fields, Q::page($page, $limit), $sort, true, $countTtl);
        return [
            'data'      => $xRes->data,
            'total'     => $xRes->total,
            'columns'   => $xRes->columns,
            'titles'    => $xRes->titles,
            'limit'     => $limit,
            'page'      => $page,
            'last_page' => $limit > 0 ? (int) ceil($xRes->total / $limit) : 1,
            'debug'     => ['sql' => $xRes->sql],
            'stats'     => ['duration' => $xRes->durationMs],
        ];
    }

    public function insert(array $data): XResponse
    {
        $this->useConnection();
        $affected = Gateway::insert($this->resolveTable(), $data);
        $status = Gateway::status();
        return new XResponse(
            data: [], sql: $status['sql'] ?? '', durationMs: $status['duration'] ?? 0,
            success: $affected > 0, affected: $affected, lastId: $affected
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
            return ($driver === 'mysql') ? 'window' : 'separate';
        }
        return $this->totalStrategy;
    }

    private function executeSelect(
        string|array $fields,
        mixed $pagination,
        string|array $sort,
        bool $withTotal = false,
        ?int $countTtl = null
    ): XResponse {
        $this->useConnection();
        $strategy = $this->resolveStrategy();

        if (!$withTotal) {
            // Sin total: consulta directa (con o sin caché)
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
            $query = Q::from($this->table, $this->filter);
            $compiled = $query->select($fields, $pagination, (array)$sort, null, [], true);
            $result = $compiled->run(PDO::FETCH_NUM);
            $rows = $result['rows'] ?? [];
            $projectionMap = $compiled->getProjectionMap();
            $total = 0;
            if (!empty($rows) && isset($projectionMap['_total'])) {
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
        if (is_string($this->table)) {
            return $this->table;
        }
        return is_string($this->table[0] ?? '') ? $this->table[0] : '';
    }
}