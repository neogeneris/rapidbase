<?php
/**
 * RapidBase Lite - Minimal Bundle for PoC
 * 
 * This file includes the essential ActiveRecord functionality
 * without requiring a full autoloader. Ideal for testing and prototyping.
 */

namespace RapidBase\Lite;

use PDO;
use Exception;

/**
 * Simple DB Facade for SQLite (PoC Context)
 */
class DB
{
    private static ?PDO $pdo = null;

    public static function connect(string $path = ':memory:'): void
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO("sqlite:$path");
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }

    public static function insert(string $table, array $data): int|string|false
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        
        $stmt = self::pdo()->prepare($sql);
        if ($stmt->execute($data)) {
            return self::pdo()->lastInsertId();
        }
        return false;
    }

    public static function update(string $table, array $data, array $where): int|false
    {
        $set = [];
        foreach ($data as $key => $val) {
            $set[] = "$key = :$key";
        }
        $whereClause = [];
        $params = $data;
        foreach ($where as $key => $val) {
            $whereClause[] = "$key = :where_$key";
            $params["where_$key"] = $val;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $whereClause);
        $stmt = self::pdo()->prepare($sql);
        
        if ($stmt->execute($params)) {
            return $stmt->rowCount();
        }
        return false;
    }

    public static function find(string $table, array $where): ?array
    {
        $conditions = [];
        $params = [];
        foreach ($where as $key => $val) {
            $conditions[] = "$key = :$key";
            $params[$key] = $val;
        }
        
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }

    public static function all(string $table, array $where = []): array
    {
        if (empty($where)) {
            $sql = "SELECT * FROM $table";
            $stmt = self::pdo()->query($sql);
        } else {
            $conditions = [];
            $params = [];
            foreach ($where as $key => $val) {
                $conditions[] = "$key = :$key";
                $params[$key] = $val;
            }
            $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $conditions);
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
        }
        
        return $stmt->fetchAll() ?: [];
    }

    public static function delete(string $table, array $where): bool
    {
        $conditions = [];
        $params = [];
        foreach ($where as $key => $val) {
            $conditions[] = "$key = :$key";
            $params[$key] = $val;
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $conditions);
        $stmt = self::pdo()->prepare($sql);
        return $stmt->execute($params);
    }
}

/**
 * Lightweight ActiveRecord Model
 */
abstract class Model implements \JsonSerializable
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected array $attributes = [];
    protected array $original = [];

    public function __construct(array $attributes = [])
    {
        if (!empty($attributes)) {
            $this->fill($attributes);
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public static function getTable(): string
    {
        return static::$table;
    }

    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function __get($key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    public function save(): bool
    {
        return $this->persist();
    }

    public function persist(): bool
    {
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id !== null && !$this->isDirty()) {
            return true;
        }

        $data = ($id === null) ? $this->attributes : $this->getDirty();
        unset($data[$pk]);

        if (empty($data) && $id !== null) {
            return true;
        }

        if ($id === null) {
            $newId = DB::insert(static::$table, $data);
            if ($newId) {
                $this->attributes[$pk] = $newId;
                $this->syncOriginal();
                return true;
            }
        } else {
            $res = DB::update(static::$table, $data, [$pk => $id]);
            if ($res !== false) {
                $this->syncOriginal();
                return true;
            }
        }
        return false;
    }

    public static function create(array $attributes = []): int|string|bool
    {
        $instance = new static($attributes);
        if ($instance->persist()) {
            return $instance->attributes[static::$primaryKey] ?? true;
        }
        return false;
    }

    public static function read($id): ?static
    {
        $where = is_array($id) ? $id : [static::$primaryKey => $id];
        $data = DB::find(static::$table, $where);

        if ($data) {
            $instance = new static($data);
            $instance->syncOriginal();
            return $instance;
        }
        return null;
    }

    public static function all(): array
    {
        $results = DB::all(static::$table);
        $collection = [];
        foreach ($results as $row) {
            $instance = new static($row);
            $instance->syncOriginal();
            $collection[] = $instance;
        }
        return $collection;
    }

    public static function delete($id): bool
    {
        if ($id === null) {
            throw new \InvalidArgumentException("ID not provided for deletion.");
        }
        return DB::delete(static::$table, [static::$primaryKey => $id]);
    }

    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    public function isDirty(?string $key = null): bool
    {
        if ($key === null) {
            return $this->attributes !== $this->original;
        }
        return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
    }

    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    public function destroy(): bool
    {
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;
        return $id ? static::delete($id) : false;
    }
}
