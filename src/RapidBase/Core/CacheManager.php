<?php

namespace RapidBase\Core;

class CacheManager {
    private CacheInterface $adapter;

    public function __construct(CacheInterface $adapter) {
        $this->adapter = $adapter;
    }

    public function get(string $table, string $key): mixed {
        return $this->adapter->get($table, $key);
    }

    public function set(string $table, string $key, mixed $data): bool {
        return $this->adapter->set($table, $key, $data);
    }

    public function invalidate(string $table): void {
        $this->adapter->invalidate($table);
    }
}