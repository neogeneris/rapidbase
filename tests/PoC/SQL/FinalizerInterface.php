<?php

namespace RapidBase\Core;

/**
 * Interface para los finalizadores de consultas SQL.
 * Garantiza que tanto F (rápido) como Fm (con métricas) tengan la misma API.
 */
interface FinalizerInterface
{
    public function select($fields = '*'): array;
    public function delete(): array;
    public function update(array $data): array;
    public function count($field = '*'): array;
    public function exists(): array;
}
