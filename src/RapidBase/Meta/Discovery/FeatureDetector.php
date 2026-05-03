<?php

declare(strict_types=1);

namespace RapidBase\Meta\Discovery;

use PDO;

/**
 * FeatureDetector: Detecta capacidades del motor de base de datos en tiempo real.
 *
 * Ejecuta pruebas ligeras (sin modificar datos) para determinar qué funcionalidades
 * están disponibles. Los resultados se persisten en el schema_map para que las capas
 * superiores (Executor, Q, Gateway) puedan tomar decisiones de optimización
 * sin ejecutar estas pruebas en cada request.
 */
class FeatureDetector
{
    private PDO $pdo;
    private string $driver;

    /** @var array<string, callable> Registro de probes por nombre */
    private static array $customProbes = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Ejecuta todas las pruebas de detección y devuelve el mapa de features.
     *
     * @return array<string, bool|string|int>
     */
    public function detect(): array
    {
        $features = [
            'driver'              => $this->driver,
            'driver_version'      => $this->detectVersion(),
            'window_functions'    => $this->probeWindowFunctions(),
            'get_column_meta'     => $this->probeGetColumnMeta(),
            'named_parameters'    => $this->probeNamedParameters(),
            'native_json_column'  => $this->probeNativeJson(),
            'atomic_upsert'       => $this->probeAtomicUpsert(),
            'cte'                 => $this->probeCTE(),
            'returning'           => $this->probeReturning(),
            'transactions'        => $this->probeTransactions(),
            'savepoints'          => $this->probeSavepoints(),
            'limit_on_update'     => $this->probeLimitOnUpdateDelete(),
        ];

        // Ejecutar probes personalizados registrados por el usuario
        foreach (self::$customProbes as $name => $probe) {
            try {
                $features[$name] = $probe($this->pdo, $this->driver);
            } catch (\Throwable $e) {
                $features[$name] = false;
            }
        }

        return $features;
    }

    /**
     * Registra un probe personalizado que se ejecutará durante detect().
     *
     * @param string   $name  Nombre del feature (snake_case)
     * @param callable $probe fn(PDO $pdo, string $driver): bool|string|int
     */
    public static function registerProbe(string $name, callable $probe): void
    {
        self::$customProbes[$name] = $probe;
    }

    /**
     * Limpia probes personalizados (útil para tests).
     */
    public static function clearCustomProbes(): void
    {
        self::$customProbes = [];
    }

    // ================================================================
    //  Probes individuales
    // ================================================================

    /**
     * Detecta la versión del motor de base de datos.
     */
    private function detectVersion(): string
    {
        try {
            return match ($this->driver) {
                'mysql'  => $this->pdo->query("SELECT VERSION()")->fetchColumn() ?: '',
                'pgsql'  => $this->pdo->query("SHOW server_version")->fetchColumn() ?: '',
                'sqlite' => $this->pdo->query("SELECT sqlite_version()")->fetchColumn() ?: '',
                'sqlsrv' => $this->pdo->query("SELECT SERVERPROPERTY('ProductVersion')")->fetchColumn() ?: '',
                default  => $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?: 'unknown',
            };
        } catch (\Throwable $e) {
            try {
                return $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?: 'unknown';
            } catch (\Throwable $e) {
                return 'unknown';
            }
        }
    }

    /**
     * COUNT(*) OVER() — Window functions.
     * Esencial para la inyección de _total en paginación sin query adicional.
     */
    private function probeWindowFunctions(): bool
    {
        return $this->safeProbe("SELECT COUNT(*) OVER() AS _total FROM (SELECT 1) AS _t");
    }

    /**
     * PDOStatement::getColumnMeta() — ¿Devuelve nombres reales de columna?
     * En algunos drivers/versiones, getColumnMeta devuelve '*' en lugar de expandir.
     */
    private function probeGetColumnMeta(): bool
    {
        try {
            // Crear una tabla temporal para probar con SELECT *
            $tempTable = '_rb_feature_probe_' . mt_rand(1000, 9999);

            if ($this->driver === 'sqlite') {
                $this->pdo->exec("CREATE TEMP TABLE {$tempTable} (id INTEGER, name TEXT)");
                $this->pdo->exec("INSERT INTO temp.{$tempTable} VALUES (1, 'test')");
                $stmt = $this->pdo->query("SELECT * FROM temp.{$tempTable}");
            } else {
                $this->pdo->exec("CREATE TEMPORARY TABLE {$tempTable} (id INT, name VARCHAR(10))");
                $this->pdo->exec("INSERT INTO {$tempTable} VALUES (1, 'test')");
                $stmt = $this->pdo->query("SELECT * FROM {$tempTable}");
            }

            $stmt->fetchAll(PDO::FETCH_NUM);
            $reliable = true;

            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta === false || !isset($meta['name']) || $meta['name'] === '*') {
                    $reliable = false;
                    break;
                }
            }

            // Limpiar tabla temporal
            if ($this->driver === 'sqlite') {
                $this->pdo->exec("DROP TABLE IF EXISTS temp.{$tempTable}");
            } else {
                $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
            }

            return $reliable;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Parámetros con nombre (:param) — Todos los drivers soportados los tienen,
     * pero Oracle con oci8 a veces tiene problemas.
     */
    private function probeNamedParameters(): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT :val AS result");
            $stmt->execute(['val' => 1]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['result']) && (int)$row['result'] === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Soporte nativo de columnas/funciones JSON.
     * MySQL 5.7+, MariaDB 10.2+, PostgreSQL 9.2+, SQLite 3.38+.
     */
    private function probeNativeJson(): bool
    {
        return match ($this->driver) {
            'mysql'  => $this->safeProbe("SELECT JSON_OBJECT('key', 'value') AS j"),
            'pgsql'  => $this->safeProbe("SELECT '{}'::jsonb AS j"),
            'sqlite' => $this->safeProbe("SELECT json('{}') AS j"),
            default  => false,
        };
    }

    /**
     * INSERT ... ON CONFLICT / ON DUPLICATE KEY — Upsert nativo y atómico.
     */
    private function probeAtomicUpsert(): bool
    {
        try {
            $tempTable = '_rb_upsert_probe_' . mt_rand(1000, 9999);
            
            if ($this->driver === 'sqlite') {
                $this->pdo->exec("CREATE TEMP TABLE {$tempTable} (id INTEGER PRIMARY KEY, val TEXT)");
                $this->pdo->exec("INSERT INTO temp.{$tempTable} (id, val) VALUES (1, 'a')");
                // SQLite ON CONFLICT
                $sql = "INSERT INTO temp.{$tempTable} (id, val) VALUES (1, 'b') ON CONFLICT(id) DO UPDATE SET val=excluded.val";
            } elseif ($this->driver === 'mysql') {
                $this->pdo->exec("CREATE TEMPORARY TABLE {$tempTable} (id INT PRIMARY KEY, val VARCHAR(10))");
                $this->pdo->exec("INSERT INTO {$tempTable} (id, val) VALUES (1, 'a')");
                // MySQL ON DUPLICATE KEY UPDATE
                $sql = "INSERT INTO {$tempTable} (id, val) VALUES (1, 'b') ON DUPLICATE KEY UPDATE val=VALUES(val)";
            } elseif ($this->driver === 'pgsql') {
                $this->pdo->exec("CREATE TEMPORARY TABLE {$tempTable} (id INT PRIMARY KEY, val TEXT)");
                $this->pdo->exec("INSERT INTO {$tempTable} (id, val) VALUES (1, 'a')");
                // PostgreSQL ON CONFLICT
                $sql = "INSERT INTO {$tempTable} (id, val) VALUES (1, 'b') ON CONFLICT(id) DO UPDATE SET val=EXCLUDED.val";
            } else {
                return false;
            }

            $this->pdo->exec($sql);
            $finalVal = $this->pdo->query("SELECT val FROM " . ($this->driver === 'sqlite' ? "temp." : "") . "{$tempTable} WHERE id=1")->fetchColumn();
            
            // Limpiar
            if ($this->driver === 'sqlite') {
                $this->pdo->exec("DROP TABLE IF EXISTS temp.{$tempTable}");
            } else {
                $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
            }

            return $finalVal === 'b';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Common Table Expressions (WITH ... AS).
     * MySQL 8.0+, MariaDB 10.2+, PostgreSQL 8.4+, SQLite 3.8.3+.
     */
    private function probeCTE(): bool
    {
        return $this->safeProbe("WITH _cte AS (SELECT 1 AS n) SELECT n FROM _cte");
    }

    /**
     * INSERT ... RETURNING — Devolver filas afectadas tras un INSERT.
     * PostgreSQL lo soporta nativamente. SQLite desde 3.35.0. MySQL no.
     */
    private function probeReturning(): bool
    {
        return match ($this->driver) {
            'pgsql'  => true,  // Siempre soportado
            'sqlite' => $this->isVersionAtLeast('3.35.0'),
            'mysql'  => false, // MySQL/MariaDB no lo soporta
            default  => false,
        };
    }

    /**
     * Soporte de transacciones (COMMIT/ROLLBACK).
     */
    private function probeTransactions(): bool
    {
        try {
            // Algunos motores como MyISAM en MySQL no fallan al iniciar transaccion,
            // pero no hacen nada. Sin embargo, PDO::beginTransaction() suele ser honesto.
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $this->pdo->query("SELECT 1")->execute();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Soporte de Savepoints (transacciones anidadas).
     */
    private function probeSavepoints(): bool
    {
        try {
            $this->pdo->exec("SAVEPOINT _probe_sp");
            $this->pdo->exec("RELEASE SAVEPOINT _probe_sp");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Soporte de LIMIT en UPDATE y DELETE.
     * MySQL lo soporta, pero Postgres y SQLite estándar no.
     */
    private function probeLimitOnUpdateDelete(): bool
    {
        return match ($this->driver) {
            'mysql' => true,
            default => false,
        };
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * Ejecuta una consulta SELECT de prueba y devuelve true si no lanza excepción.
     */
    private function safeProbe(string $sql): bool
    {
        try {
            $stmt = $this->pdo->query($sql);
            $stmt->fetchAll();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Compara la versión del driver contra un mínimo requerido.
     */
    private function isVersionAtLeast(string $minVersion): bool
    {
        $current = $this->detectVersion();
        // Extraer solo la parte numérica (ej: "8.0.32-MariaDB" -> "8.0.32")
        if (preg_match('/^(\d+\.\d+\.\d+)/', $current, $m)) {
            return version_compare($m[1], $minVersion, '>=');
        }
        return false;
    }
}
