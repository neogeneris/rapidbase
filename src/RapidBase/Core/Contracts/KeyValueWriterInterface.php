<?php

declare(strict_types=1);

namespace RapidBase\Core\Contracts;

interface KeyValueWriterInterface extends KeyValueReaderInterface
{
    public function set(string $key, mixed $value): void;
    public function delete(string $key): void;
    public function clear(): void;
}