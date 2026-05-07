<?php

namespace RapidBase\Core;

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;
use PDO;

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

    // ─── Lectura ────────────────────────────────────────

    public function select(
        string|array $fields = '*',
        mixed $pagination = null,
        string|array $sort = []
    ): XResponse {
        return $this->executeSelect($fields, $pagination, $sort);
    }

    public function first(): ?array
{
    Conn::select($this->connectionId);
    $result = Gateway::select(
        '*',
        $this->table,
        $this->filter,
        [],
        [],
        [],
        [0, 1],
        PDO::FETCH_ASSOC   // ← Devuelve array asociativo
    );
    return $result['data'][0] ?? null;
}

    public function count(): int
    {
        Conn::select($this->connectionId);
        return Gateway::count($this->table, $this->filter);
    }

    /** Alias específico para grids, devuelve array listo para el frontend */
public function grid(
    string|array $fields = '*',
    int $page = 1,
    int $limit = 30,
    array|string $sort = []   // ← debe estar así. Neo
): array {

	$res = $this->select($fields, Q::page($page, $limit), $sort);
        return [
            'data'      => $res->data,
            'total'     => $res->total,
            'columns'   => $res->columns,
            'titles'    => $res->titles,
            'limit'     => $res->limit,
            'page'      => $res->page,
            'last_page' => $res->lastPage,
            'debug'     => [
                'sql'      => $res->sql,
                'duration' => $res->durationMs,
            ],
        ];
    }

    // ─── Escritura ──────────────────────────────────────

    public function insert(array $data): XResponse
    {
        Conn::select($this->connectionId);
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
        Conn::select($this->connectionId);
        $table = $this->resolveTable();
        if ($limit !== null) {
            $compiled = Q::from($table, $this->filter)->update($data, $limit);
            $result = $compiled->run();
            $affected = $result['count'] ?? 0;
            return new XResponse(
                data: [],
                sql: $compiled->getSql(),
                durationMs: 0,
                success: $affected > 0,
                affected: $affected
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
        Conn::select($this->connectionId);
        $table = $this->resolveTable();
        $compiled = Q::from($table, $this->filter)->delete($limit);
        $result = $compiled->run();
        $affected = $result['count'] ?? 0;
        return new XResponse(
            data: [],
            sql: $compiled->getSql(),
            durationMs: 0,
            success: $affected > 0,
            affected: $affected
        );
    }

    // ─── SQL crudo ──────────────────────────────────────

    public function raw(string $sql): XResponse
    {
        Conn::select($this->connectionId);
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
        Conn::select($this->connectionId);
        $start = microtime(true);
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
        $durationMs = round((microtime(true) - $start) * 1000, 4);

        $rows    = $result['data']          ?? [];
        $total   = $result['total']         ?? count($rows);
        $cols    = $result['metadata']['cols'] ?? [];
        $page    = $result['page']          ?? 1;
        $limit   = $result['limit']         ?? 30;
        $sql     = $result['metadata']['sql'] ?? '';
        $titles  = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $cols);

        return new XResponse(
            data: $rows,
            sql: $sql,
            durationMs: $durationMs,
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