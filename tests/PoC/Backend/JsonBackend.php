<?php

namespace Tests\PoC\Backend;

require_once __DIR__ . '/Backend.php';

/**
 * Implementación de Backend que almacena datos en archivos JSON.
 * Cada entidad se guarda en un archivo separado con ID autoincremental.
 * Soporta JOINs usando SQLite en memoria.
 */
class JsonBackend extends Backend
{
    /**
     * @var string Directorio base para almacenar los archivos JSON
     */
    private string $baseDir;

    /**
     * @var array|null Caché de datos de la entidad actual
     */
    private ?array $cache = null;

    /**
     * @var array Configuración para JOINs
     */
    private array $joinConfig = [];

    /**
     * @var array Cláusula WHERE para consultas con JOIN
     */
    private ?array $joinWhere = null;

    /**
     * @var array Campos a seleccionar en JOINs
     */
    private array $joinFields = ['*'];

    /**
     * Constructor
     * 
     * @param string $baseDir Directorio base para los archivos JSON
     */
    public function __construct(string $baseDir = __DIR__ . '/../../data')
    {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        
        // Crear directorio si no existe
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    /**
     * Establece el directorio base (útil para testing o configuración dinámica)
     * 
     * @param string $baseDir Nuevo directorio base
     * @return static
     */
    public function setBaseDir(string $baseDir): static
    {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        
        // Crear directorio si no existe
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
        
        return $this;
    }

    /**
     * Obtiene la ruta del archivo para la entidad actual
     * 
     * @return string
     */
    private function getFilePath(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $this->entity . '.json';
    }

    /**
     * Carga los datos desde el archivo JSON a la caché
     * 
     * @return void
     */
    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $filePath = $this->getFilePath();
        
        if (!file_exists($filePath)) {
            $this->cache = [];
            return;
        }

        $content = file_get_contents($filePath);
        $this->cache = $content ? json_decode($content, true) : [];
        
        if (!is_array($this->cache)) {
            $this->cache = [];
        }
    }

    /**
     * Guarda los datos desde la caché al archivo JSON
     * 
     * @return void
     */
    private function save(): void
    {
        $filePath = $this->getFilePath();
        file_put_contents($filePath, json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cache = null; // Invalidar caché después de guardar
    }

    /**
     * Obtiene el siguiente ID autoincremental
     * 
     * @return int
     */
    private function getNextId(): int
    {
        $this->load();
        
        if (empty($this->cache)) {
            return 1;
        }

        $maxId = 0;
        foreach ($this->cache as $record) {
            if (isset($record['id']) && $record['id'] > $maxId) {
                $maxId = $record['id'];
            }
        }
        
        return $maxId + 1;
    }

    /**
     * Inserta uno o múltiples registros en la entidad
     * 
     * @param array $records Array de registros a insertar
     * @return array IDs de los registros insertados
     */
    public function insert(array $records): array
    {
        return $this->measureTime(function() use ($records) {
            $this->load();
            
            $insertedIds = [];
            $isSingleRecord = !isset($records[0]) || (isset($records['id']) || isset($records[0]));
            
            // Si es un solo registro (asociativo), lo convertimos a array de un elemento
            if ($isSingleRecord && !empty($records) && array_keys($records) !== range(0, count($records) - 1)) {
                $records = [$records];
            }

            foreach ($records as $record) {
                $record['id'] = $this->getNextId();
                $this->cache[] = $record;
                $insertedIds[] = $record['id'];
            }

            $this->save();
            
            return $insertedIds;
        });
    }

    /**
     * Actualiza registros que coincidan con el criterio
     * 
     * @param array $data Datos a actualizar
     * @param array|null $where Criterio de filtrado (null para todos)
     * @return int Número de registros afectados
     */
    public function update(array $data, ?array $where = null): int
    {
        return $this->measureTime(function() use ($data, $where) {
            $this->load();
            
            $affected = 0;
            
            foreach ($this->cache as $key => $record) {
                if ($this->matchesWhere($record, $where)) {
                    // No permitir actualizar el ID
                    unset($data['id']);
                    $this->cache[$key] = array_merge($record, $data);
                    $affected++;
                }
            }

            if ($affected > 0) {
                $this->save();
            }
            
            return $affected;
        });
    }

    /**
     * Elimina registros que coincidan con el criterio
     * 
     * @param array|null $where Criterio de filtrado (null para todos)
     * @return int Número de registros eliminados
     */
    public function delete(?array $where = null): int
    {
        return $this->measureTime(function() use ($where) {
            $this->load();
            
            $deleted = 0;
            $newCache = [];

            foreach ($this->cache as $record) {
                if ($this->matchesWhere($record, $where)) {
                    $deleted++;
                } else {
                    $newCache[] = $record;
                }
            }

            if ($deleted > 0) {
                $this->cache = $newCache;
                $this->save();
            }
            
            return $deleted;
        });
    }

    /**
     * Selecciona registros de la entidad
     * 
     * @param array|string $fields Campos a seleccionar ('*' para todos)
     * @param array|null $where Criterio de filtrado
     * @return array Resultados de la consulta
     */
    public function select(array|string $fields = '*', ?array $where = null): array
    {
        return $this->measureTime(function() use ($fields, $where) {
            $this->load();
            
            $results = [];
            
            foreach ($this->cache as $record) {
                if ($this->matchesWhere($record, $where)) {
                    if ($fields === '*') {
                        $results[] = $record;
                    } else {
                        // Filtrar campos específicos
                        $filtered = [];
                        foreach ((array)$fields as $field) {
                            if (isset($record[$field])) {
                                $filtered[$field] = $record[$field];
                            }
                        }
                        $results[] = $filtered;
                    }
                }
            }
            
            return $results;
        });
    }

    /**
     * Verifica si un registro coincide con el criterio WHERE
     * 
     * @param array $record Registro a verificar
     * @param array|null $where Criterio de filtrado
     * @return bool
     */
    private function matchesWhere(array $record, ?array $where): bool
    {
        if ($where === null) {
            return true;
        }

        foreach ($where as $key => $value) {
            if (!isset($record[$key]) || $record[$key] !== $value) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Limpia la caché (útil para testing)
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Elimina completamente la entidad y su archivo
     * 
     * @return bool
     */
    public function dropEntity(): bool
    {
        $filePath = $this->getFilePath();
        $this->cache = null;
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return true;
    }

    /**
     * Configura un JOIN con otra tabla/entidad
     * 
     * @param string $table Nombre de la tabla a hacer JOIN
     * @param string $localField Campo de la tabla actual
     * @param string $foreignField Campo de la tabla externa
     * @param string $type Tipo de JOIN (INNER, LEFT, RIGHT)
     * @return static
     */
    public function join(string $table, string $localField, string $foreignField, string $type = 'INNER'): static
    {
        $this->joinConfig[] = [
            'table' => $table,
            'local_field' => $localField,
            'foreign_field' => $foreignField,
            'type' => strtoupper($type)
        ];
        return $this;
    }

    /**
     * Alias para join() - Configura un LEFT JOIN
     * 
     * @param string $table Nombre de la tabla a hacer JOIN
     * @param string $localField Campo de la tabla actual
     * @param string $foreignField Campo de la tabla externa
     * @return static
     */
    public function leftJoin(string $table, string $localField, string $foreignField): static
    {
        return $this->join($table, $localField, $foreignField, 'LEFT');
    }

    /**
     * Alias para join() - Configura un RIGHT JOIN
     * 
     * @param string $table Nombre de la tabla a hacer JOIN
     * @param string $localField Campo de la tabla actual
     * @param string $foreignField Campo de la tabla externa
     * @return static
     */
    public function rightJoin(string $table, string $localField, string $foreignField): static
    {
        return $this->join($table, $localField, $foreignField, 'RIGHT');
    }

    /**
     * Realiza un JOIN utilizando un algoritmo nativo en PHP (Hash Join).
     * Generalmente más rápido para datasets pequeños/medianos al evitar overhead de SQLite.
     *
     * @param string $table Nombre de la tabla externa.
     * @param string $localField Clave foránea en la tabla local.
     * @param string $foreignField Clave primaria en la tabla externa.
     * @param string $type Tipo de JOIN ('INNER', 'LEFT').
     * @return static
     */
    public function joinNative(string $table, string $localField, string $foreignField, string $type = 'INNER'): static
    {
        $startTime = microtime(true);
        
        // Cargar datos de la tabla local en caché si no están cargados
        if ($this->cache === null) {
            $this->load();
        }
        $localData = $this->cache ?? [];
        
        // Obtener datos de la tabla extranjera
        $foreignBackend = new static($this->baseDir);
        $foreignBackend->entity = $table;
        $foreignBackend->clearCache();
        $foreignData = $foreignBackend->select('*');
        
        if (empty($localData)) {
            $this->queryResult = [];
            $this->executionTime = (microtime(true) - $startTime) * 1000;
            return $this;
        }

        $results = [];

        // Optimización: Hash Join (construir índice para la tabla extranjera)
        // Esto reduce la complejidad de O(N*M) a O(N+M)
        $foreignIndex = [];
        foreach ($foreignData as $row) {
            $key = $row[$foreignField] ?? null;
            if ($key !== null) {
                if (!isset($foreignIndex[$key])) {
                    $foreignIndex[$key] = [];
                }
                $foreignIndex[$key][] = $row;
            }
        }

        // Iterar sobre la tabla local y unir usando el índice (O(m))
        foreach ($localData as $localRow) {
            $localKeyVal = $localRow[$localField] ?? null;
            $matches = $foreignIndex[$localKeyVal] ?? [];

            if (!empty($matches)) {
                foreach ($matches as $foreignRow) {
                    // El operador + es más rápido que array_merge.
                    // $foreignRow + $localRow da prioridad a los campos de la tabla foránea en caso de colisión de nombres.
                    $results[] = $foreignRow + $localRow;
                }
            } elseif ($type === 'LEFT') {
                // Para LEFT JOIN, si no hay match, agregamos la fila local
                $results[] = $localRow; 
            }
        }

        $this->queryResult = $results;
        $this->executionTime = (microtime(true) - $startTime) * 1000;
        return $this;
    }

    /**
     * Establece los campos para seleccionar en una consulta con JOIN
     * 
     * @param array|string $fields Campos a seleccionar
     * @return static
     */
    public function selectFields(array|string $fields): static
    {
        $this->joinFields = $fields === '*' ? ['*'] : (array)$fields;
        return $this;
    }

    /**
     * Establece la cláusula WHERE para consultas con JOIN
     * 
     * @param array $where Criterio de filtrado
     * @return static
     */
    public function where(array $where): static
    {
        $this->joinWhere = $where;
        return $this;
    }

    /**
     * Ejecuta una consulta con JOINs.
     * Usa estrategia inteligente: PHP nativo para datasets pequeños, SQLite con índices para grandes volúmenes.
     * 
     * @return array Resultados del JOIN
     */
    public function get(): array
    {
        return $this->measureTime(function() {
            // Si no hay JOINs configurados, hacer un select normal
            if (empty($this->joinConfig)) {
                return $this->select($this->joinFields, $this->joinWhere);
            }

            $this->load();
            $totalRecords = count($this->cache ?? []);

            // Inteligencia de Selección de Sustrato (Estrategia Camaleón):
            // Si los registros son muy pocos (< 500) y solo hay 1 JOIN, el Hash Join en PHP nativo gana por no tener I/O.
            // Si superamos ese umbral o hay múltiples JOINs, SQLite con índices en disco es superior.
            if ($totalRecords < 500 && count($this->joinConfig) === 1) {
                $join = $this->joinConfig[0];
                // Ejecutar JOIN nativo en PHP y devolver directamente el resultado
                return $this->joinNative(
                    $join['table'], 
                    $join['local_field'], 
                    $join['foreign_field'], 
                    $join['type']
                )->queryResult;
            }

            // Para todo lo demás (Datasets grandes o múltiples JOINs encadenados), SQLite con índices manda
            return $this->executeJoinWithSQLite();
        });
    }

    /**
     * Ejecuta JOINs usando SQLite en memoria o archivo temporal según el volumen de datos.
     * Optimizado con PRAGMAs para velocidad extrema y creación automática de índices.
     * 
     * @return array Resultados de la consulta
     */
    private function executeJoinWithSQLite(): array
    {
        // Usar un archivo temporal en disco (/tmp) en vez de :memory: para evitar límites de RAM
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rapidbase_' . uniqid() . '.db';
        $sqlite = new \SQLite3($tempFile);
        
        // Optimizar SQLite para velocidad extrema (Modo WAL y Síncrono en OFF)
        $sqlite->exec("PRAGMA journal_mode = WAL;");
        $sqlite->exec("PRAGMA synchronous = OFF;");
        
        try {
            // Crear tabla principal
            $this->createTempTable($sqlite, $this->entity, $this->cache ?? []);
            
            // Crear tablas secundarias y sus ÍNDICES automáticamente
            foreach ($this->joinConfig as $join) {
                $joinBackend = new static($this->baseDir);
                $joinBackend->entity = $join['table'];
                $joinBackend->clearCache();
                $joinData = $joinBackend->select('*');
                
                $this->createTempTable($sqlite, $join['table'], $joinData);
                
                // MAGIA NEGRA: Crear índice automático en la llave foránea de inmediato
                $indexName = "idx_" . $join['table'] . "_" . $join['foreign_field'];
                $sqlite->exec("CREATE INDEX IF NOT EXISTS \"$indexName\" ON \"{$join['table']}\" (\"{$join['foreign_field']}\")");
            }

            // Crear índice en la tabla local también para la condición del JOIN
            foreach ($this->joinConfig as $join) {
                $localIndexName = "idx_" . $this->entity . "_" . $join['local_field'];
                $sqlite->exec("CREATE INDEX IF NOT EXISTS \"$localIndexName\" ON \"{$this->entity}\" (\"{$join['local_field']}\")");
            }

            $sql = $this->buildJoinSQL();
            $result = $sqlite->query($sql);
            
            if (!$result) {
                throw new \Exception("Error en consulta JOIN: " . $sqlite->lastErrorMsg());
            }

            $rows = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }

            return $rows;

        } finally {
            $sqlite->close();
            // Limpieza obligatoria del archivo del sustrato
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            // Resetear estados
            $this->joinConfig = [];
            $this->joinWhere = null;
            $this->joinFields = ['*'];
        }
    }

    /**
     * Crea una tabla temporal en SQLite e inserta datos
     * 
     * @param \SQLite3 $sqlite Instancia de SQLite
     * @param string $tableName Nombre de la tabla
     * @param array $data Datos a insertar
     */
    private function createTempTable(\SQLite3 $sqlite, string $tableName, array $data): void
    {
        if (empty($data)) {
            // Crear tabla vacía con solo columna id
            $sqlite->exec("CREATE TABLE IF NOT EXISTS \"$tableName\" (id INTEGER PRIMARY KEY)");
            return;
        }

        // Obtener todas las columnas únicas de los datos
        $columns = [];
        foreach ($data as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        // Crear sentencia CREATE TABLE
        $columnDefs = [];
        foreach (array_keys($columns) as $col) {
            if ($col === 'id') {
                $columnDefs[] = "\"$col\" INTEGER";
            } else {
                $columnDefs[] = "\"$col\" TEXT";
            }
        }

        // Asegurar que id siempre esté primero
        $finalColumnDefs = [];
        if (isset($columns['id'])) {
            $finalColumnDefs[] = '"id" INTEGER';
            unset($columns['id']);
        }
        foreach (array_keys($columns) as $col) {
            $finalColumnDefs[] = "\"$col\" TEXT";
        }

        $createSQL = "CREATE TEMPORARY TABLE \"$tableName\" (" . implode(', ', $finalColumnDefs) . ")";
        $sqlite->exec($createSQL);

        // Insertar datos optimizado: Preparar la sentencia UNA SOLA VEZ fuera del bucle
        if (!empty($data)) {
            $firstRow = reset($data);
            $cols = array_keys($firstRow);
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $quotedCols = implode(', ', array_map(fn($c) => "\"$c\"", $cols));
            
            $stmt = $sqlite->prepare("INSERT INTO \"$tableName\" ($quotedCols) VALUES ($placeholders)");
            
            if ($stmt) {
                $sqlite->exec("BEGIN TRANSACTION");
                foreach ($data as $row) {
                    foreach ($cols as $i => $col) {
                        $value = $row[$col] ?? null;
                        // Intentar preservar tipos numéricos para que los índices sean eficientes
                        $bindValue = $value === null ? null : (is_int($value) ? (int)$value : (string)$value);
                        $stmt->bindValue($i + 1, $bindValue);
                    }
                    $stmt->execute();
                    $stmt->reset(); // Resetea el statement para la siguiente fila sin destruirlo
                }
                $sqlite->exec("COMMIT");
                $stmt->close();
            }
        }
    }

    /**
     * Construye la consulta SQL para el JOIN
     * 
     * @return string Consulta SQL
     */
    private function buildJoinSQL(): string
    {
        // Construir SELECT
        if ($this->joinFields === ['*']) {
            $selectPart = '*';
        } else {
            $selectPart = implode(', ', array_map(fn($f) => "\"$f\"", $this->joinFields));
        }

        // Construir FROM y JOINs
        $fromPart = "\"{$this->entity}\"";
        
        foreach ($this->joinConfig as $join) {
            $joinType = $join['type'] . ' JOIN';
            $joinTable = "\"{$join['table']}\"";
            $joinCondition = "\"{$this->entity}\".\"{$join['local_field']}\" = \"{$join['table']}\".\"{$join['foreign_field']}\"";
            
            $fromPart .= " $joinType $joinTable ON $joinCondition";
        }

        // Construir WHERE
        $wherePart = '';
        if ($this->joinWhere !== null && !empty($this->joinWhere)) {
            $conditions = [];
            foreach ($this->joinWhere as $field => $value) {
                // Determinar si el campo pertenece a la tabla principal o a alguna tabla del JOIN
                $tableAlias = $this->entity;
                $conditions[] = "\"$tableAlias\".\"$field\" = '" . addslashes($value) . "'";
            }
            if (!empty($conditions)) {
                $wherePart = ' WHERE ' . implode(' AND ', $conditions);
            }
        }

        return "SELECT $selectPart FROM $fromPart$wherePart";
    }
}
