<?php

namespace RapidBase\Core\Backend;

/**
 * Clase de respuesta para operaciones de Backend
 */
class BackendResponse
{
    public function __construct(
        public array $data = [],
        public string $sql = '',
        public float $durationMs = 0,
        public int $total = 0,
        public int $page = 1,
        public int $limit = 30,
        public array $columns = [],
        public array $titles = [],
        public bool $success = true,
        public int $affected = 0,
        public mixed $lastId = null,
        public array $metadata = []
    ) {}
}
