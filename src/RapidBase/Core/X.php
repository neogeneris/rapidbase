<?php

namespace RapidBase\Core;

use RapidBase\Core\Cache\CountCache;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;
use PDO;

/**
 * X – Ejecutor fluido que delega en Gateway (eventos + cache) y devuelve XResponse.
 */
class X
{
    private string $connectionId;
    private mixed  $table;
    private array  $filter;

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
        $this->table  = $table;
        $this->filter = $filter;
        return $this;
    }

    private function useConnection(): void
    {
        Conn::get($this->connectionId);   // valida existencia
        Conn::select($this->connectionId);
    }

    // ─── Lectura ────────────────────────────────────────

    public function select(
        string|array $fields = '*',
        mixed $pagination = null,
        string|array $sort = []
    ): XResponse {
        return $this->executeSelect($fields, $pagination, $sort);
    }

    /**
     * Devuelve la primera fila como array asociativo, o null si no hay.
     */
    public function first(): ?array
    {
        $this->useConnection();
        $result = Gateway::select(
            '*',
            $this->table,
            $this->filter,
            [],
            [],
            [],
            [0, 1],
            PDO::FETCH_ASSOC
        );
        return $result['data'][0] ?? null;
    }

    public function count(): int
    {
        $this->useConnection();
        return Gateway::count($this->table, $this->filter);
    }

    public function grid(
        string|array $fields = '*',
        int $page = 1,
        int $limit = 30,
        array|string $sort = []
    ): array {
        $total = CountCache::remember(
            $this->resolveTable(),
            $this->filter,
            fn() => $this->count()
        );

        $res = $this->select($fields, Q::page($page, $limit), $sort);

        return [
            'data'      => $res->data,
            'total'     => $total,
            'columns'   => $res->columns,
            'titles'    => $res->titles,
            'limit'     => $limit,
            'page'      => $page,
            'last_page' => $limit > 0 ? (int) ceil($total / $limit) : 1,
            'debug'     => ['sql' => $res->sql],
            'stats'     => ['duration' => $res->durationMs],
        ];
    }

    // ─── Escritura ──────────────────────────────────────

    public function insert(array $data): XResponse
    {
        $this->useConnection();
        $affected = Gateway::insert($this->resolveTable(), $data);
        $status = Gateway::status();
        return new XResponse(
            data: [],
            sql: $status['sql'] ?? '',
            durationMs: $status['duration'] ?? 0,
            success: $affected > 0,
            affected: $affected,
            lastId: $affected
        );
    }

    public function update(array $data, ?int $limit = null): XResponse
    {
        $this->useConnection();
        $table = $this->resolveTable();
        if ($limit !== null) {
            $compiled = Q::from($table, $this->filter)->update($data, $limit);
            $result = $compiled->run();
            return new XResponse(
                data: [],
                sql: $compiled->getSql(),
                durationMs: 0,
                success: ($result['count'] ?? 0) > 0,
                affected: $result['count'] ?? 0
            );
        }
        $affected = Gateway::update($table, $data, $this->filter);
        $status = Gateway::status();
        return new XResponse(
            data: [],
            sql: $status['sql'] ?? '',
            durationMs: $status['duration'] ?? 0,
            success: $affected > 0,
            affected: $affected
        );
    }

    public function delete(?int $limit = null): XResponse
    {
        $this->useConnection();
        $table = $this->resolveTable();
        $compiled = Q::from($table, $this->filter)->delete($limit);
        $result = $compiled->run();
        return new XResponse(
            data: [],
            sql: $compiled->getSql(),
            durationMs: 0,
            success: ($result['count'] ?? 0) > 0,
            affected: $result['count'] ?? 0
        );
    }

    // ─── SQL crudo ──────────────────────────────────────

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
                data: $rows,
                sql: $sql,
                durationMs: $elapsed,
                total: count($rows),
                columns: $columns,
                titles: array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $columns)
            );
        } else {
            $result = Executor::action($sql);
            $elapsed = round((microtime(true) - $start) * 1000, 4);
            return new XResponse(
                data: [],
                sql: $sql,
                durationMs: $elapsed,
                success: $result['success'] ?? false,
                affected: $result['count'] ?? 0
            );
        }
    }

    // ─── Utilidades ─────────────────────────────────────

    public function toSQL(CompiledQuery $compiled): string
    {
        return $compiled->getSql();
    }

    // ─── Interno ────────────────────────────────────────

    private function executeSelect(string|array $fields, mixed $pagination, string|array $sort): XResponse
    {
        $this->useConnection();
        $result = Gateway::select(
            $fields,
            $this->table,
            $this->filter,
            [],
            [],
            (array)$sort,
            $pagination,
            PDO::FETCH_NUM
        );

        $rows     = $result['data']          ?? [];
        $cols     = $result['metadata']['cols'] ?? [];
        $sql      = $result['metadata']['sql'] ?? '';
        $duration = $result['metadata']['execution_time'] ?? 0;  // ya en ms

        // Normalizar page/limit
        $rawPage  = $result['page']  ?? 0;
        $rawLimit = $result['limit'] ?? 0;

        if ($pagination !== null) {
            $page  = max(1, $rawPage);
            $limit = max(1, $rawLimit);
        } else {
            $page  = 1;
            $limit = max(1, $rawLimit ?: 30);  // siempre positivo
        }

        // Total real con CountCache
        $total = CountCache::remember(
            $this->resolveTable(),
            $this->filter,
            fn() => $this->count()
        );

        $titles = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $cols);

        return new XResponse(
            data: $rows,
            sql: $sql,
            durationMs: $duration,
            total: $total,
            page: $page,
            limit: $limit,
            columns: $cols,
            titles: $titles,
            success: true
        );
    }

    private function resolveTable(): string
    {
        if (is_string($this->table)) {
            return $this->table;
        }
        return is_string($this->table[0] ?? '') ? $this->table[0] : '';
    }
}