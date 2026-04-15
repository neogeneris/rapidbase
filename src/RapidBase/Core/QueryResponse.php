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
}