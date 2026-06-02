<?php

declare(strict_types=1);

namespace RapidBase\Core\Contracts;

interface CacheInterface extends KeyValueWriterInterface
{
    public function setWithTtl(string $key, mixed $value, int $ttl): void;
}