<?php

declare(strict_types=1);

namespace RapidBase\Tests\Performance;

use RapidBase\Core\Contracts\KeyValueWriterInterface;
use RapidBase\Core\Contracts\KeyValueReaderInterface;

/**
 * SerialFileCacheAdapter - Implementación de KeyValueWriterInterface
 * que usa serialize/unserialize en archivos .dat (misma técnica que Autoloader original)
 */
class SerialFileCacheAdapter implements KeyValueWriterInterface
{
    private string $filePath;
    private array $data = [];
    private bool $loaded = false;
    private float $lastReadDuration = 0.0;
    private float $lastWriteDuration = 0.0;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->load();
        return isset($this->data[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->persist();
    }

    public function delete(string $key): void
    {
        $this->load();
        unset($this->data[$key]);
        $this->persist();
    }

    public function clear(): void
    {
        $this->data = [];
        $this->persist();
    }

    private function persist(): void
    {
        $start = microtime(true);
        if ($this->data !== []) {
            file_put_contents($this->filePath, serialize($this->data), LOCK_EX);
        } elseif (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
        $this->lastWriteDuration = (microtime(true) - $start) * 1000;
    }

    private function load(): void
    {
        if (!$this->loaded) {
            $start = microtime(true);
            $this->data = file_exists($this->filePath) 
                ? unserialize(file_get_contents($this->filePath)) ?: [] 
                : [];
            $this->loaded = true;
            $this->lastReadDuration = (microtime(true) - $start) * 1000;
        }
    }

    public function getLastReadDuration(): float
    {
        return $this->lastReadDuration;
    }

    public function getLastWriteDuration(): float
    {
        return $this->lastWriteDuration;
    }

    public function getDataCount(): int
    {
        return count($this->data);
    }
}
