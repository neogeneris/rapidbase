<?php

namespace RapidBase\Models;

use RapidBase\ORM\ActiveRecord\Model;

class Connection extends Model
{
    protected static string $table = 'connections';

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