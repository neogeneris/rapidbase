<?php

declare(strict_types=1);

namespace RapidBase\Core\Contracts;

interface KeyValueReaderInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
}