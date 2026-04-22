<?php

namespace RapidBase\Core;

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

    /**
     * Exporta los datos en formato "Rapid-Pack" (RPF).
     * Optimizado para consumo directo por componentes UI (Grids) sin overhead de CPU.
     * 
     * Estructura:
     * {
     *   "head": { "vars": [...], "map": {...} },
     *   "body": [ [val1, val2...], ... ],
     *   "meta": { "total": N, "page": N, ... }
     * }
     */
    public function toRapidPack(): array {
        return [
            "head" => [
                "vars" => $this->metadata['flat_columns'] ?? [],
                "map"  => $this->metadata['projection_map'] ?? []
            ],
            "body" => $this->data, // Array numérico puro (FETCH_NUM)
            "meta" => [
                "count" => count($this->data),
                "total" => (int)$this->total,
                "page"  => (int)($this->state['page'] ?? 1),
                "limit" => (int)($this->state['per_page'] ?? 0),
                "sort"  => $this->metadata['sort_status'] ?? null,
                "took"  => (float)($this->metadata['execution_time'] ?? 0)
            ]
        ];
    }

    /**
     * Exporta los datos en formato Grid moderno (compatible con grid.js y similares).
     * 
     * Estructura:
     * {
     *   "head": {
     *     "columns": ["id", "name", "email"],
     *     "titles": ["ID", "Name", "Email"]
     *   },
     *   "data": [[1, "John", "john@email.com"], ...],
     *   "page": {
     *     "current": 1,
     *     "total": 6,
     *     "limit": 10,
     *     "records": 51,
     *     "next": 2,
     *     "prev": null,
     *     "first": 1,
     *     "last": 6
     *   },
     *   "stats": {
     *     "exec_ms": 0.0868,
     *     "cache": true,
     *     "cache_type": "L2",
     *     "memory_kb": 124.5,
     *     "queries": 1
     *   }
     * }
     */
    public function toGridFormat(): array {
        $pagination = $this->pagination();
        $cacheInfo = $this->metadata['cache_info'] ?? [];
        
        return [
            "head" => [
                "columns" => $this->metadata['columns'] ?? [],
                "titles"  => $this->metadata['titles'] ?? []
            ],
            "data" => $this->data,
            "page" => [
                "current" => $this->state['page'] ?? 1,
                "total"   => $pagination['last'] ?? 1,
                "limit"   => $this->state['per_page'] ?? 10,
                "records" => (int)$this->total,
                "next"    => $pagination['next'],
                "prev"    => $pagination['prev'],
                "first"   => 1,
                "last"    => $pagination['last'] ?? 1
            ],
            "stats" => [
                "exec_ms"    => (float)($this->metadata['execution_time'] ?? 0),
                "cache"      => $cacheInfo['used'] ?? false,
                "cache_type" => $cacheInfo['type'] ?? null,
                "memory_kb"  => round(memory_get_peak_usage(true) / 1024, 2),
                "queries"    => 1
            ]
        ];
    }

    /**
     * Exporta los datos como JSON en formato Rapid-Pack.
     */
    public function toJson(): string {
        return json_encode($this->toRapidPack(), JSON_NUMERIC_CHECK);
    }

    /**
     * Exporta los datos como JSON en formato Grid moderno.
     */
    public function toJsonGrid(): string {
        return json_encode($this->toGridFormat(), JSON_NUMERIC_CHECK);
    }
}