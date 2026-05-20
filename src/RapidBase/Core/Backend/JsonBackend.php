<?php

namespace RapidBase\Core\Backend;

use RapidBase\Core\Conn;

/**
 * JsonBackend - Implementación de Backend que usa archivos JSON como almacenamiento
 * 
 * Permite usar una sintaxis similar a X::con('jsonDB')->from('users')->select('*')
 * pero operando sobre archivos JSON en lugar de bases de datos SQL.
 * 
 * Ejemplo de uso:
 * ```php
 * JsonBackend::con('jsonDB')->from('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
 * JsonBackend::con('jsonDB')->from('users')->select('*', [1, 10], '-id');
 * JsonBackend::con('jsonDB')->from('users')->upsert(['id' => 1, 'name' => 'Jane'], ['id']);
 * ```
 */
class JsonBackend extends Backend
{
    private string $basePath;
    private array $data = [];

    /**
     * Constructor
     * @param string $connectionId Identificador de la conexión (usado para determinar el path)
     */
    protected function __construct(string $connectionId)
    {
        parent::__construct($connectionId);
        
        // Determinar la base path para los archivos JSON
        $this->basePath = $this->resolveBasePath($connectionId);
        
        // Crear directorio si no existe
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
        
        // Cargar datos en memoria
        $this->loadData();
    }

    /**
     * Resuelve el path base para los archivos JSON
     */
    private function resolveBasePath(string $connectionId): string
    {
        // Si es un path absoluto, usarlo directamente
        if (str_starts_with($connectionId, '/') || str_contains($connectionId, ':\\')) {
            return rtrim($connectionId, '/\\');
        }
        
        // Si no, usar un directorio relativo al proyecto
        $base = defined('JSON_BACKEND_PATH') ? JSON_BACKEND_PATH : getcwd() . '/data/jsondb';
        return rtrim($base, '/\\') . '/' . $connectionId;
    }

    /**
     * Obtiene el path completo del archivo para la tabla actual
     */
    private function getFilePath(): string
    {
        return $this->basePath . '/' . $this->resolveTable() . '.json';
    }

    /**
     * Carga los datos desde el archivo JSON
     */
    private function loadData(): void
    {
        $filePath = $this->getFilePath();
        
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $this->data = json_decode($content, true) ?? [];
            
            // Asegurar que sea un array indexado
            if (!empty($this->data) && !array_is_list($this->data)) {
                // Si es un mapa (ej: {"1": {...}, "2": {...}}), convertir a array indexado
                $this->data = array_values($this->data);
            }
        } else {
            $this->data = [];
        }
    }

    /**
     * Guarda los datos al archivo JSON
     */
    private function saveData(): void
    {
        $filePath = $this->getFilePath();
        $content = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $content);
    }

    /**
     * Filtra los datos según el filtro actual
     */
    private function applyFilter(array $data): array
    {
        if (empty($this->filter)) {
            return $data;
        }

        return array_filter($data, function ($row) {
            foreach ($this->filter as $key => $value) {
                if (!isset($row[$key]) || $row[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Aplica ordenamiento a los datos
     */
    private function applySort(array $data, string|array $sort): array
    {
        if (empty($sort) || empty($data)) {
            return $data;
        }

        $sortFields = is_string($sort) ? [$sort] : $sort;
        
        foreach (array_reverse($sortFields) as $sortField) {
            $descending = false;
            $field = $sortField;
            
            if (str_starts_with($sortField, '-')) {
                $descending = true;
                $field = substr($sortField, 1);
            } elseif (str_ends_with($sortField, ' DESC')) {
                $descending = true;
                $field = preg_replace('/\s+DESC$/', '', $sortField);
            } elseif (str_ends_with($sortField, ' ASC')) {
                $field = preg_replace('/\s+ASC$/', '', $sortField);
            }

            usort($data, function ($a, $b) use ($field, $descending) {
                $valA = $a[$field] ?? null;
                $valB = $b[$field] ?? null;
                
                $result = $valA <=> $valB;
                return $descending ? -$result : $result;
            });
        }

        return $data;
    }

    /**
     * Inserta un registro
     */
    public function insert(array $data): BackendResponse
    {
        $start = microtime(true);
        
        // Auto-asignar ID si no existe
        if (!isset($data['id'])) {
            $maxId = 0;
            foreach ($this->data as $row) {
                if (isset($row['id']) && is_numeric($row['id']) && $row['id'] > $maxId) {
                    $maxId = $row['id'];
                }
            }
            $data['id'] = $maxId + 1;
        }

        $this->data[] = $data;
        $this->saveData();
        
        $duration = (microtime(true) - $start) * 1000;
        
        return new BackendResponse(
            data: [],
            sql: "INSERT INTO {$this->table}",
            durationMs: $duration,
            success: true,
            affected: 1,
            lastId: $data['id']
        );
    }

    /**
     * Actualiza registros que coincidan con el filtro
     */
    public function update(array $data, ?int $limit = null): BackendResponse
    {
        $start = microtime(true);
        
        $filtered = $this->applyFilter($this->data);
        $affected = 0;

        foreach ($this->data as $index => $row) {
            // Verificar si este row coincide con el filtro
            $matches = true;
            foreach ($this->filter as $key => $value) {
                if (!isset($row[$key]) || $row[$key] !== $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $this->data[$index] = array_merge($row, $data);
                $affected++;
                
                if ($limit !== null && $affected >= $limit) {
                    break;
                }
            }
        }

        $this->saveData();
        $duration = (microtime(true) - $start) * 1000;

        return new BackendResponse(
            data: [],
            sql: "UPDATE {$this->table}",
            durationMs: $duration,
            success: $affected > 0,
            affected: $affected
        );
    }

    /**
     * Inserta o actualiza (upsert)
     */
    public function upsert(array $data, array $conflictColumns = []): BackendResponse
    {
        $start = microtime(true);
        
        // Si no se especifican columnas de conflicto, intentar usar 'id'
        if (empty($conflictColumns)) {
            $conflictColumns = ['id'];
        }

        // Buscar registro existente
        $foundIndex = null;
        foreach ($this->data as $index => $row) {
            $matches = true;
            foreach ($conflictColumns as $col) {
                if (!isset($row[$col], $data[$col]) || $row[$col] !== $data[$col]) {
                    $matches = false;
                    break;
                }
            }
            
            if ($matches) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex !== null) {
            // Actualizar existente
            $this->data[$foundIndex] = array_merge($this->data[$foundIndex], $data);
            $this->saveData();
            
            $duration = (microtime(true) - $start) * 1000;
            
            return new BackendResponse(
                data: [],
                sql: "UPDATE {$this->table} (upsert)",
                durationMs: $duration,
                success: true,
                affected: 1,
                lastId: $this->data[$foundIndex]['id'] ?? null
            );
        } else {
            // Insertar nuevo
            return $this->insert($data);
        }
    }

    /**
     * Lee un solo registro (primero que coincida con el filtro)
     */
    public function read(): ?array
    {
        $filtered = $this->applyFilter($this->data);
        return !empty($filtered) ? reset($filtered) : null;
    }

    /**
     * Selecciona múltiples registros con paginación y orden
     */
    public function select(
        string|array $fields = '*',
        mixed $pagination = null,
        string|array $sort = [],
        bool $withTotal = false
    ): BackendResponse {
        $start = microtime(true);
        
        // Aplicar filtro
        $result = $this->applyFilter($this->data);
        
        // Aplicar orden
        $result = $this->applySort($result, $sort);
        
        // Calcular total antes de paginar
        $total = count($result);
        
        // Aplicar paginación
        if ($pagination !== null) {
            if (is_array($pagination) && count($pagination) === 2) {
                $offset = max(0, (int)$pagination[0]);
                $limit = max(1, (int)$pagination[1]);
            } else {
                $page = max(1, (int)$pagination);
                $limit = 30;
                $offset = ($page - 1) * $limit;
            }
            
            $result = array_slice($result, $offset, $limit);
            $page = $limit > 0 ? (int)($offset / $limit) + 1 : 1;
        } else {
            $page = 1;
            $limit = count($result);
        }
        
        // Proyectar campos si no es '*'
        if ($fields !== '*') {
            $fieldList = is_string($fields) ? array_map('trim', explode(',', $fields)) : $fields;
            $result = array_map(function ($row) use ($fieldList) {
                $projected = [];
                foreach ($fieldList as $field) {
                    if (isset($row[$field])) {
                        $projected[$field] = $row[$field];
                    }
                }
                return $projected;
            }, $result);
        }
        
        // Obtener nombres de columnas
        $columns = !empty($result) ? array_keys(reset($result)) : [];
        $titles = array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $columns);
        
        $duration = (microtime(true) - $start) * 1000;
        
        return new BackendResponse(
            data: $result,
            sql: "SELECT FROM {$this->table}",
            durationMs: $duration,
            total: $total,
            page: $page,
            limit: $limit,
            columns: $columns,
            titles: $titles,
            success: true
        );
    }

    /**
     * Elimina registros que coincidan con el filtro
     */
    public function delete(?int $limit = null): BackendResponse
    {
        $start = microtime(true);
        
        $deleted = 0;
        $newData = [];

        foreach ($this->data as $row) {
            // Verificar si este row coincide con el filtro
            $matches = true;
            foreach ($this->filter as $key => $value) {
                if (!isset($row[$key]) || $row[$key] !== $value) {
                    $matches = false;
                    break;
                }
            }

            if (!$matches) {
                $newData[] = $row;
            } else {
                $deleted++;
                if ($limit !== null && $deleted >= $limit) {
                    $newData[] = $row; // Mantener el resto
                    // Copiar el resto de datos originales
                    $currentIndex = array_search($row, $this->data, true);
                    for ($i = $currentIndex + 1; $i < count($this->data); $i++) {
                        $newData[] = $this->data[$i];
                    }
                    break;
                }
            }
        }

        $this->data = $newData;
        $this->saveData();
        
        $duration = (microtime(true) - $start) * 1000;
        
        return new BackendResponse(
            data: [],
            sql: "DELETE FROM {$this->table}",
            durationMs: $duration,
            success: $deleted > 0,
            affected: $deleted
        );
    }

    /**
     * Cuenta registros
     */
    public function count(): int
    {
        return count($this->applyFilter($this->data));
    }

    /**
     * Verifica existencia
     */
    public function exists(): bool
    {
        return !empty($this->applyFilter($this->data));
    }
}
