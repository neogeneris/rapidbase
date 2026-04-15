<?php

namespace Core;

interface CacheInterface {
    public function get(string $table, string $key): mixed;
    public function set(string $table, string $key, mixed $data): bool;
    public function invalidate(string $table): void;
    public function exists(string $table, string $key): bool;
}