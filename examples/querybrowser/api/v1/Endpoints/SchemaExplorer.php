<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;
use RapidBase\Meta\Discovery\DiscoveryFactory;

// Cargar el modelo manualmente
require_once __DIR__ . '/../Models/Connection.php';

use RapidBase\Models\Connection;

class SchemaExplorer extends BaseEndpoint
{
    /**
     * Obtiene el modelo Connection desde la BD interna.
     */
    private function getConnectionModel(string $connId): ?Connection
    {
        $dbFile = defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../../data/connections.sqlite';
        if (!file_exists($dbFile)) return null;

        DB::setup("sqlite:$dbFile", '', '', 'internal');
        
        // Buscar primero por nombre
        $row = X::con('internal')->from('connections', ['name' => $connId])->first();
        
        // Si no encuentra por nombre, intentar por ID numérico
        if (!$row && is_numeric($connId)) {
            $row = X::con('internal')->from('connections', ['id' => (int)$connId])->first();
        }
        
        if (!$row) return null;

        $conn = new Connection($row);
        $conn->syncOriginal();
        return $conn;
    }

    /**
     * Activa la conexión en el pool si no está ya activa.
     */
    private function ensureConnectionActive(string $connId): bool
    {
        if (in_array($connId, Conn::listConnectionIds())) return true;

        $conn = $this->getConnectionModel($connId);
        if (!$conn) return false;

        // Usar el nombre normalizado como connectionKey
        $connectionKey = $this->normalizeConnectionName($conn->name);
        DB::setup($conn->buildDsn(), $conn->username ?? '', $conn->password ?? '', $connectionKey);
        return true;
    }

    /**
     * Normalize connection name to be used as connection key.
     */
    private function normalizeConnectionName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9_\-]/', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);
        return 'conn_' . $normalized;
    }

    /**
     * Asegura que el mapa de esquema para esta conexión esté cargado.
     * Si no hay mapa, lo descubre usando DiscoveryFactory.
     */
    private function ensureSchemaMapLoaded(string $connId): void
    {
        $currentMap = SchemaMap::getMap();
        if (!empty($currentMap['tables'])) return;   // ya cargado

        $conn = $this->getConnectionModel($connId);
        if (!$conn) throw new \Exception("Cannot load schema: connection data not found");

        // Asegurarse de que la conexión esté activa (tener un PDO disponible)
        $this->ensureConnectionActive($connId);

        // Usar el nombre normalizado como connectionKey
        $connectionKey = $this->normalizeConnectionName($conn->name);
        
        $pdo = Conn::get($connectionKey);
        $discovery = DiscoveryFactory::create($pdo);
        $databaseName = $conn->database;

        $allTables = $discovery->getTables($databaseName);
        $tablesMetadata = [];
        foreach ($allTables as $table) {
            $tablesMetadata[$table] = $discovery->discoverColumns($table, $databaseName);
        }

        $relationships = $discovery->discoverRelationships($databaseName);

        $map = [
            'tables'        => $tablesMetadata,
            'relationships' => $relationships,
            'driver'        => $conn->driver,
        ];

        SchemaMap::setMap($map, $connectionKey);
    }

    // ─── Endpoints públicos ─────────────────────────────────

    public function getSchema(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';

        try {
            // Obtener la conexión para obtener el nombre normalizado
            $conn = $this->getConnectionModel($connId);
            if (!$conn) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }
            
            $connectionKey = $this->normalizeConnectionName($conn->name);
            
            if (!$this->ensureConnectionActive($connId)) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }

            // Cargar esquema si es necesario
            $this->ensureSchemaMapLoaded($connId);

            Conn::select($connectionKey);
            $description = X::con($connectionKey)->description();

            return [
                'success'   => true,
                'tables'    => $description['tables'],
                'views'     => $description['views'],
                'relations' => $description['relations'],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    public function describeTable(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';
        $table  = $this->context->params['table'] ?? '';

        if (empty($table)) {
            return ['success' => false, 'error' => 'Missing table parameter'];
        }

        try {
            // Obtener la conexión para obtener el nombre normalizado
            $conn = $this->getConnectionModel($connId);
            if (!$conn) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }
            
            $connectionKey = $this->normalizeConnectionName($conn->name);
            
            if (!$this->ensureConnectionActive($connId)) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }
            $this->ensureSchemaMapLoaded($connId);

            Conn::select($connectionKey);
            $description = X::con($connectionKey)->from($table)->description();

            return [
                'success'   => true,
                'table'     => $table,
                'structure' => $description['tables'][0] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getRelatedTables(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';
        $tables = $this->context->params['tables'] ?? '';

        if (empty($tables)) {
            return ['success' => false, 'error' => 'Missing tables parameter'];
        }

        try {
            // Obtener la conexión para obtener el nombre normalizado
            $conn = $this->getConnectionModel($connId);
            if (!$conn) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }
            
            $connectionKey = $this->normalizeConnectionName($conn->name);
            
            if (!$this->ensureConnectionActive($connId)) {
                return ['success' => false, 'error' => 'Connection not found or unavailable'];
            }
            $this->ensureSchemaMapLoaded($connId);

            Conn::select($connectionKey);

            $tableList = json_decode($tables, true);
            if (!is_array($tableList) || empty($tableList)) {
                return ['success' => false, 'error' => 'Invalid tables list'];
            }

            $map = SchemaMap::getMap();
            $relsFrom = $map['relationships']['from'] ?? [];
            $relsTo   = $map['relationships']['to']   ?? [];

            $toList = [];
            $fromList = [];
            foreach ($tableList as $t) {
                foreach ($relsFrom[$t] ?? [] as $target => $rel) {
                    if (!in_array($target, $tableList)) $toList[$target] = true;
                }
                foreach ($relsTo[$t] ?? [] as $target => $rel) {
                    if (!in_array($target, $tableList)) $fromList[$target] = true;
                }
            }

            return [
                'success' => true,
                'to'      => array_keys($toList),
                'from'    => array_keys($fromList),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}