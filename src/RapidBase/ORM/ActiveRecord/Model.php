<?php

namespace RapidBase\ORM\ActiveRecord;

use RapidBase\Core\DB;
use InvalidArgumentException;

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

    /**
     * Especifica qué datos deben serializarse a JSON.
     * Esto permite que json_encode($user) funcione automáticamente devolviendo los atributos.
     */
    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }

    /**
     * Útil para obtener el modelo como array explícitamente
     */
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

    // ========== ACCESORES MÁGICOS ==========
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

    // ========== PERSISTENCIA (INSTANCIA) ==========

    public function save(): bool
    {
        return $this->persist();
    }

    public function persist(): bool
    {
        $pk = static::$primaryKey;
        $this->syncFromHydration();
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

    // ========== MÉTODOS ESTÁTICOS ==========

    public static function __callStatic($name, $arguments)
    {
        if ($name === 'save') {
            return static::create($arguments[0] ?? []);
        }
    }

    public static function create(array $attributes = []): int|string|bool
    {
        $instance = new static($attributes);
        if ($instance->persist()) {
            return $instance->attributes[static::$primaryKey] ?? true;
        }
        return false;
    }

    /**
     * CORREGIDO: Usa DB::find() que sí existe en tu clase Core\DB
     */
    public static function read($id): ?static
    {
        $where = is_array($id) ? $id : [static::$primaryKey => $id];
        
        // DB::find devuelve un array asociativo del registro
        $data = DB::find(static::$table, $where);

        if ($data) {
            $instance = new static($data);
            $instance->syncOriginal();
            return $instance;
        }
        return null;
    }

    /**
     * CORREGIDO: Usa DB::all() de tu fachada
     */
    public static function all(): array
    {
        // DB::all(tabla, where, sort, class)
        // Pasamos static::class para que DB lo hidrate automáticamente si puede,
        // o lo hidratamos manualmente aquí para asegurar consistencia del ORM.
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
            throw new InvalidArgumentException("ID no proporcionado para eliminación.");
        }
        return DB::delete(static::$table, [static::$primaryKey => $id]);
    }

    // ========== GESTIÓN DE ESTADO ==========
    
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

    public function syncFromHydration(): void
    {
        $objectVars = get_object_vars($this);
        foreach ($objectVars as $key => $value) {
            if (!in_array($key, ['attributes', 'original', 'table', 'primaryKey', 'fillable', 'hidden', 'casts', 'guarded', 'timestamps', 'connection'], true)) {
                $this->attributes[$key] = $value;
                unset($this->$key);
            }
        }
    }

    public function destroy(): bool
    {
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;
        return $id ? static::delete($id) : false;
    }
}