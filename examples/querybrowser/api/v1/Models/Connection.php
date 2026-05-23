<?php

namespace RapidBase\Models;

use RapidBase\ORM\ActiveRecord\Model;
use RapidBase\Core\DB;
use RapidBase\Core\X;

class Connection extends Model
{
    protected static string $table = 'connections';

    /**
     * Override read to use 'internal' connection
     */
    public static function read($id): ?static
    {
        // Intentar primero con X::con('internal') que es más directo
        try {
            $row = X::con('internal')->from(static::$table, ['id' => $id])->first();
            if ($row) {
                $conn = new static();
                $conn->fill($row);
                return $conn;
            }
        } catch (\Throwable $e) {
            // Fallback al método padre si falla
            $data = DB::find(static::$table, ['id' => $id]);
            if ($data) {
                $conn = new static();
                $conn->fill($data);
                return $conn;
            }
        }
        return null;
    }

    /**
     * Override all to use 'internal' connection
     */
    public static function all(): array
    {
        try {
            $rows = X::con('internal')->from(static::$table)->get();
            $result = [];
            foreach ($rows as $row) {
                $conn = new static();
                $conn->fill($row);
                $result[] = $conn;
            }
            return $result;
        } catch (\Throwable $e) {
            // Fallback al método padre si falla
            return parent::all();
        }
    }

    /**
     * Override create to use 'internal' connection
     */
    public static function create(array $attributes = []): int|string|bool
    {
        try {
            return X::con('internal')
                ->into(static::$table)
                ->values($attributes)
                ->execute();
        } catch (\Throwable $e) {
            // Fallback al método padre si falla
            return DB::insert(static::$table, $attributes);
        }
    }

    /**
     * Override delete to use 'internal' connection
     */
    public static function delete($id): bool
    {
        try {
            $result = X::con('internal')
                ->from(static::$table)
                ->where(['id' => $id])
                ->delete();
            return $result['success'] ?? false;
        } catch (\Throwable $e) {
            // Fallback al método padre si falla
            return DB::delete(static::$table, ['id' => $id]);
        }
    }

    /**
     * Build DSN string from connection attributes.
     * Supports sqlite, mysql, mariadb, pgsql, sqlsrv drivers.
     *
     * @return string DSN connection string
     */
    public function buildDsn(): string
    {
        $driver = strtolower($this->driver ?? 'sqlite');
        $host   = $this->host ?? 'localhost';
        $port   = $this->port ?? null;
        $dbName = $this->database ?? '';

        return match ($driver) {
            'sqlite'  => "sqlite:{$dbName}",
            'mysql',
            'mariadb' => "mysql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$dbName};charset=utf8mb4",
            'pgsql'   => "pgsql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$dbName}",
            'sqlsrv'  => "sqlsrv:Server={$host}" . ($port ? ",{$port}" : "") . ";Database={$dbName};Encrypt=0;TrustServerCertificate=1",
            default   => throw new \RuntimeException("Unsupported driver: $driver"),
        };
    }

    /**
     * Get connection data without password.
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        unset($data['password']);
        return $data;
    }
}