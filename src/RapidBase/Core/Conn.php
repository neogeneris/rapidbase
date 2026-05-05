<?php

namespace RapidBase\Core;

/**
 * Conn - Administra un pool de conexiones PDO con identificadores lógicos.
 * 
 * Permite registrar múltiples conexiones (cada una con un connectionId único),
 * cambiar la conexión activa, y obtener metadatos como driver, nombre real de la BD,
 * estado de transacción, timeout, etc., de forma segura (capturando excepciones
 * en atributos no soportados por todos los motores, especialmente SQLite).
 */
class Conn
{
    /** @var array<string, \PDO> Pool de conexiones indexado por connectionId */
    private static array $connections = [];

    /** @var array<string, array> Metadatos de cada conexión */
    private static array $metadata = [];

    /** @var string Identificador de la conexión activa actual */
    private static string $currentConnectionId = 'default';

    /**
     * Registra una nueva conexión en el pool.
     *
     * @param string $connectionId Nombre lógico que identifica la conexión (ej. 'main', 'reporting')
     * @param string $dsn          DSN completo (ej. 'mysql:host=localhost;dbname=mi_bd')
     * @param string $user
     * @param string $pass
     * @return void
     * @throws \PDOException
     */
    public static function add(string $connectionId, string $dsn, string $user = '', string $pass = ''): void
    {
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE          => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        self::$connections[$connectionId] = $pdo;
        self::$metadata[$connectionId] = [
            'connectionId'      => $connectionId,
            'dsn'               => $dsn,
            'driver'            => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
            'dbname'            => self::extractDbName($dsn),
            'user'              => $user,
            'connection_status' => self::safeGetAttribute($pdo, \PDO::ATTR_CONNECTION_STATUS, 'unknown'),
            'server_info'       => self::safeGetAttribute($pdo, \PDO::ATTR_SERVER_INFO, ''),
            'err_mode'          => $pdo->getAttribute(\PDO::ATTR_ERRMODE),
            'timeout'           => self::safeGetAttribute($pdo, \PDO::ATTR_TIMEOUT, 0),
            'autocommit'        => self::safeGetAttribute($pdo, \PDO::ATTR_AUTOCOMMIT, true),
            'persistent'        => $pdo->getAttribute(\PDO::ATTR_PERSISTENT),
        ];

        // La primera conexión registrada se vuelve la activa por defecto
        if (count(self::$connections) === 1) {
            self::$currentConnectionId = $connectionId;
        }
    }

    /**
     * Método de compatibilidad hacia atrás.
     * @deprecated Usar Conn::add() directamente.
     */
    public static function setup(string $dsn, string $user, string $pass, string $connectionId = 'main'): void
    {
        self::add($connectionId, $dsn, $user, $pass);
        self::select($connectionId);
    }

    /**
     * Cambia la conexión activa.
     *
     * @param string $connectionId
     * @throws \InvalidArgumentException
     */
    public static function select(string $connectionId): void
    {
        if (!isset(self::$connections[$connectionId])) {
            throw new \InvalidArgumentException("Connection '$connectionId' not found.");
        }
        self::$currentConnectionId = $connectionId;
    }

    /**
     * Obtiene el objeto PDO de la conexión activa (o de una específica).
     *
     * @param string|null $connectionId Si se omite, usa la conexión activa.
     * @return \PDO
     * @throws \RuntimeException
     */
    public static function get(?string $connectionId = null): \PDO
    {
        $connectionId = $connectionId ?? self::$currentConnectionId;
        if (!isset(self::$connections[$connectionId])) {
            throw new \RuntimeException("Connection '$connectionId' not available.");
        }
        return self::$connections[$connectionId];
    }

    /**
     * Verifica si hay una transacción activa en la conexión especificada (o activa).
     *
     * @param string|null $connectionId
     * @return bool
     */
    public static function inTransaction(?string $connectionId = null): bool
    {
        try {
            $pdo = self::get($connectionId);
            return $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Obtiene el connectionId de la conexión activa.
     *
     * @return string
     */
    public static function getCurrentConnectionId(): string
    {
        return self::$currentConnectionId;
    }

    /**
     * Obtiene todos los metadatos de una conexión.
     *
     * @param string|null $connectionId
     * @return array
     */
    public static function getMetadata(?string $connectionId = null): array
    {
        $connectionId = $connectionId ?? self::$currentConnectionId;
        return self::$metadata[$connectionId] ?? [];
    }

    /**
     * Obtiene el driver de la conexión activa (útil para ConditionMatrix).
     *
     * @param string|null $connectionId
     * @return string
     */
    public static function getDriver(?string $connectionId = null): string
    {
        return self::getMetadata($connectionId)['driver'] ?? '';
    }

    /**
     * Obtiene el **nombre real** de la base de datos (ej. 'mi_bd').
     *
     * @param string|null $connectionId
     * @return string
     */
    public static function getDatabaseName(?string $connectionId = null): string
    {
        return self::getMetadata($connectionId)['dbname'] ?? '';
    }

    /**
     * Lista todos los connectionId registrados.
     *
     * @return array
     */
    public static function listConnectionIds(): array
    {
        return array_keys(self::$connections);
    }

    /**
     * Cierra una o todas las conexiones.
     *
     * @param string|null $connectionId Si es null, cierra todas; si se especifica, solo esa.
     */
    public static function close(?string $connectionId = null): void
    {
        if ($connectionId === null) {
            self::$connections = [];
            self::$metadata = [];
            self::$currentConnectionId = 'default';
        } else {
            unset(self::$connections[$connectionId], self::$metadata[$connectionId]);
            if (self::$currentConnectionId === $connectionId && !empty(self::$connections)) {
                self::$currentConnectionId = array_key_first(self::$connections);
            }
        }
    }

    /**
     * Extrae el nombre de la base de datos a partir del DSN.
     *
     * @param string $dsn
     * @return string
     */
    private static function extractDbName(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
            return $matches[1];
        }
        if (str_starts_with($dsn, 'sqlite:')) {
            return basename(substr($dsn, 7));
        }
        return '';
    }

    /**
     * Obtiene un atributo PDO de forma segura (captura excepciones).
     *
     * @param \PDO  $pdo
     * @param int   $attribute
     * @param mixed $default
     * @return mixed
     */
    private static function safeGetAttribute(\PDO $pdo, int $attribute, $default = null)
    {
        try {
            return $pdo->getAttribute($attribute);
        } catch (\PDOException $e) {
            return $default;
        }
    }
}