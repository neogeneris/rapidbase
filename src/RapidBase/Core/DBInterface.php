<?php

namespace RapidBase\Core;

use Generator;

/**
 * Interface DBInterface
 * Contrato estandarizado para el motor de base de datos de RapidBase.
 * Define los métodos disponibles en la clase DB para configuración, 
 * gestión de estado, operaciones CRUD, consultas y streaming.
 */
interface DBInterface {

    // ========== CONFIGURACIÓN Y CONEXIÓN ==========
    
    /** 
     * Inicializa la conexión principal a la base de datos.
     * @param string $dsn Data Source Name (ej: mysql:host=localhost;dbname=test)
     * @param string $user Usuario de la base de datos
     * @param string $pass Contraseña de la base de datos
     * @param string $name Nombre de la conexión (por defecto 'main')
     */
    public static function setup(string $dsn, string $user, string $pass, string $name = 'main'): void;

    /** 
     * Obtiene la instancia de la conexión PDO actual.
     * @return \PDO|null
     */
    public static function getConnection(): ?\PDO;

    /** 
     * Establece el mapa de relaciones para JOINs automáticos entre tablas.
     * @param array $map
     */
    public static function setRelationsMap(array $map): void;

    // ========== GESTIÓN DE ESTADO Y METADATOS ==========
    
    /** 
     * Retorna el estado detallado de la última ejecución.
     * @return array
     */
    public static function status(): array;

    /** 
     * Obtiene el último mensaje de error registrado, si existe.
     * @return string|null
     */
    public static function getLastError(): ?string;

    /** 
     * Retorna la cantidad de filas afectadas en la última operación de escritura.
     * @return int
     */
    public static function getAffectedRows(): int;

    /** 
     * Obtiene el ID generado por la última inserción.
     * @return string|int
     */
    public static function lastInsertId(): string|int;

    // ========== CONSULTAS EXPRESIVAS (SQL DIRECTO) ==========
    
    /** 
     * Ejecuta una sentencia SQL directa (INSERT, UPDATE, DELETE, etc.).
     * @param string $sql
     * @param array $params
     * @return array Resultado de la ejecución
     */
    public static function exec(string $sql, array $params = []): array;

    /** 
     * Ejecuta una consulta SQL de lectura y retorna el PDOStatement.
     * @param string $sql
     * @param array $params
     * @return \PDOStatement|false
     */
    public static function query(string $sql, array $params = []): \PDOStatement|false;

    /** 
     * Obtiene una única fila como array asociativo.
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public static function one(string $sql, array $params = []): array|false;

    /** 
     * Obtiene múltiples filas como array de arrays asociativos.
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function many(string $sql, array $params = []): array;

    /** 
     * Obtiene un valor escalar (COUNT, SUM, una columna).
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    public static function value(string $sql, array $params = []): mixed;

    /** 
     * Versión simplificada de SELECT para obtener arrays rápidos.
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function fetch(string $sql, array $params = []): array;

    // ========== ABSTRACCIÓN FLUIDA (LECTURA) ==========
    
    /** 
     * Encuentra un único registro por condiciones.
     * @param string $table
     * @param array $conditions
     * @return array|false
     */
    public static function find(string $table, array $conditions): array|false;

    /** 
     * Cuenta registros de una tabla.
     * @param string|array $table
     * @param array $conditions
     * @return int
     */
    public static function count(string|array $table, array $conditions = []): int;

    /** 
     * Verifica si existe un registro que cumpla las condiciones.
     * @param string $table
     * @param array $conditions
     * @return bool
     */
    public static function exists(string $table, array $conditions): bool;

    /** 
     * Alias de find(). Lee un único registro.
     * @param string|array $table
     * @param array $where
     * @param array $sort
     * @return array|false
     */
    public static function read(string|array $table, array $where = [], array $sort = []): array|false;

    /** 
     * Lee un registro y lo mapea a una instancia de la clase dada.
     * @param string $class
     * @param array $where
     * @param string|null $table
     * @return object|false
     */
    public static function readAs(string $class, array $where, ?string $table = null): object|false;

    /** 
     * Obtiene todos los registros de una tabla (sin paginación).
     * @param string|array $table
     * @param array $conditions
     * @param array $sort
     * @return array
     */
    public static function all(string|array $table, array $conditions = [], array $sort = []): array;

    /** 
     * Versión paginada y cacheada. Retorna lista plana o pares llave-valor.
     * @param string|array $table
     * @param array $where
     * @param array $sort
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public static function list(
        string|array $table, 
        array $where = [], 
        array $sort = [], 
        int $page = 1, 
        int $perPage = 50
    ): array;

    // ========== OPERACIONES CRUD (ESCRITURA) ==========
    
    /** 
     * Inserta un registro y retorna el último ID insertado (o 0 si falla).
     * @param string $table
     * @param array $data
     * @return int|string
     */
    public static function insert(string $table, array $data): int|string;

    /** 
     * Inserta un registro y retorna el último ID, o false si falla.
     * @param string $table
     * @param array $data
     * @return string|int|false
     */
    public static function create(string $table, array $data): string|int|false;

    /** 
     * Actualiza registros que cumplan las condiciones.
     * @param string $table
     * @param array $data
     * @param array $conditions
     * @return bool
     */
    public static function update(string $table, array $data, array $conditions): bool;

    /** 
     * Elimina registros que cumplan las condiciones.
     * @param string $table
     * @param array $conditions
     * @return bool
     */
    public static function delete(string $table, array $conditions): bool;

    /** 
     * Lógica de "Insertar o Actualizar" (Upsert).
     * @param string $table
     * @param array $data
     * @param array $identifier
     * @return int|string|bool
     */
    public static function upsert(string $table, array $data, array $identifier): int|string|bool;

    // ========== RESULTADOS ESTRUCTURADOS Y GRID ==========
    
    /** 
     * Motor para DHTMLX que retorna un objeto QueryResponse con datos y total.
     * @param string|array|object $table
     * @param array $conditions
     * @param mixed $page Página (int), array [page, perPage], o sort (string/array) por compatibilidad.
     * @param mixed $sort Campo(s) de ordenamiento o perPage (int).
     * @param int $perPage Registros por página. Default: 10.
     * @return QueryResponse
     */
    public static function grid(
        string|array|object $table, 
        array $conditions = [], 
        mixed $page = 0, 
        mixed $sort = [], 
        int $perPage = 10
    ): QueryResponse;

    // ========== STREAMING ==========
    
    /** 
     * Retorna un generador para iterar sobre filas de una consulta.
     * @param string $sql
     * @param array $params
     * @return Generator
     */
    public static function stream(string $sql, array $params = []): Generator;

    // ========== TRANSACCIONES Y UTILIDADES ==========
    
    /** 
     * Envuelve una serie de operaciones en una transacción atómica.
     * @param callable $callback
     * @return mixed
     */
    public static function transaction(callable $callback): mixed;

    /** 
     * Crea un objeto de expresión SQL cruda para evitar el escape de caracteres.
     * Útil para funciones SQL o nombres de columnas reservados.
     * @param string $value
     * @return mixed
     */
    public static function raw(string $value): mixed;
}