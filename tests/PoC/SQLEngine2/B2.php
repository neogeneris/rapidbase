<?php

namespace RapidBase\SQLEngine2;

/**
 * B2 (Builder 2) - Builder con JoinManager integrado.
 * 
 * Da un paso atrás en la fragmentación: integra JoinManager directamente
 * en lugar de tenerlo como dependencia externa.
 */
class B2
{
    protected array $state = [];
    
    // Índices del estado
    private const T_FRO = 0;
    private const T_TYP = 1;
    private const T_WHR = 2;
    private const T_PRM = 3;
    private const T_SEL = 4;
    private const T_ORD = 5;
    private const T_LIM = 6;
    private const T_OFF = 7;
    private const T_GRP = 8;
    private const T_HAV = 9;
    private const T_JON = 10;
    private const T_ALI = 11;
    
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    private static array $whereCache = [];
    
    public function __construct()
    {
    }
    
    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }
    
    /**
     * Inicializa la consulta con tabla(s) y filtro opcional.
     */
    public static function from($from, array $filter = []): self
    {
        $instance = new self();
        
        // Procesar $from
        if (is_string($from)) {
            $instance->state[self::T_FRO] = self::parseTableString($from);
            $instance->state[self::T_TYP] = 'raw';
            $instance->state[self::T_ALI] = self::extractAlias($from);
            $instance->state[self::T_JON] = '';
        } elseif (is_array($from)) {
            $result = self::parseTableList($from);
            $instance->state[self::T_FRO] = $result['from'];
            $instance->state[self::T_JON] = $result['joins'];
            $instance->state[self::T_TYP] = $result['type'];
            $instance->state[self::T_ALI] = $result['aliases'];
        } else {
            throw new \InvalidArgumentException("El primer parámetro de from() debe ser string o array.");
        }
        
        // Procesar filtro WHERE
        if (!empty($filter)) {
            $whereKey = self::getWhereCacheKey($filter);
            if (isset(self::$whereCache[$whereKey])) {
                $instance->state[self::T_WHR] = self::$whereCache[$whereKey]['sql'];
                $instance->state[self::T_PRM] = self::$whereCache[$whereKey]['params'];
            } else {
                $parsed = self::parseWhere($filter);
                self::$whereCache[$whereKey] = $parsed;
                $instance->state[self::T_WHR] = $parsed['sql'];
                $instance->state[self::T_PRM] = $parsed['params'];
            }
        } else {
            $instance->state[self::T_WHR] = '';
            $instance->state[self::T_PRM] = [];
        }
        
        // Inicializar resto del estado
        $instance->state[self::T_SEL] = '*';
        $instance->state[self::T_ORD] = '';
        $instance->state[self::T_LIM] = null;
        $instance->state[self::T_OFF] = null;
        $instance->state[self::T_GRP] = '';
        $instance->state[self::T_HAV] = '';
        
        return $instance;
    }
    
    public function where(array $filter): self
    {
        $parsed = self::parseWhere($filter);
        
        if ($this->state[self::T_WHR]) {
            $this->state[self::T_WHR] .= ' AND ' . $parsed['sql'];
        } else {
            $this->state[self::T_WHR] = $parsed['sql'];
        }
        
        $this->state[self::T_PRM] = array_merge($this->state[self::T_PRM], $parsed['params']);
        return $this;
    }
    
    public function orderBy($sort): self
    {
        if (is_string($sort)) {
            $direction = 'ASC';
            if ($sort[0] === '-') {
                $direction = 'DESC';
                $sort = substr($sort, 1);
            }
            $this->state[self::T_ORD] = self::quote($sort) . ' ' . $direction;
        }
        return $this;
    }
    
    public function limit($limit): self
    {
        if (is_array($limit)) {
            $this->state[self::T_OFF] = $limit[0];
            $this->state[self::T_LIM] = $limit[1];
        } else {
            $this->state[self::T_LIM] = $limit;
            $this->state[self::T_OFF] = null;
        }
        return $this;
    }
    
    public function groupBy(array $columns): self
    {
        $this->state[self::T_GRP] = implode(', ', array_map(fn($c) => self::quote($c), $columns));
        return $this;
    }
    
    public function having(array $conditions): self
    {
        $parsed = self::parseWhere($conditions);
        $this->state[self::T_HAV] = $parsed['sql'];
        $this->state[self::T_PRM] = array_merge($this->state[self::T_PRM], $parsed['params']);
        return $this;
    }
    
    public function getState(): array
    {
        return $this->state;
    }
    
    private static function parseTableString(string $table): string
    {
        $table = trim($table);
        if (preg_match('/^(\w+)\s+(?:as\s+)?(\w+)$/i', $table, $matches)) {
            return self::quote($matches[1]) . ' AS ' . self::quote($matches[2]);
        }
        return self::quote($table);
    }
    
    private static function extractAlias(string $table): array
    {
        $table = trim($table);
        if (preg_match('/^(\w+)(?:\s+(?:as\s+)?(\w+))?$/i', $table, $matches)) {
            $realName = $matches[1];
            $alias = $matches[2] ?? $realName;
            return [$alias => $realName];
        }
        return [];
    }
    
    private static function parseTableList(array $tables): array
    {
        $fromClause = '';
        $joinClauses = [];
        $aliases = [];
        $type = 'list';
        
        foreach ($tables as $i => $table) {
            if ($i === 0) {
                $aliasData = self::extractAlias($table);
                $alias = !empty($aliasData) ? array_key_first($aliasData) : $table;
                $realName = !empty($aliasData) ? array_values($aliasData)[0] : $table;
                $fromClause = self::quote($realName);
                if ($alias !== $realName) {
                    $fromClause .= ' AS ' . self::quote($alias);
                }
                $aliases[$alias] = $realName;
            } else {
                // Auto-join simple integrado
                $aliasData = self::extractAlias($table);
                $alias = !empty($aliasData) ? array_key_first($aliasData) : $table;
                $realName = !empty($aliasData) ? array_values($aliasData)[0] : $table;
                $singular = rtrim($alias, 's');
                
                foreach ($aliases as $existingAlias => $existingReal) {
                    if ($existingAlias === $singular || $existingReal === $singular) {
                        $quotedAlias = self::quote($alias);
                        $quotedReal = self::quote($realName);
                        $quotedExisting = self::quote($existingAlias);
                        $foreignKey = $singular . '_id';
                        $joinClauses[] = "LEFT JOIN {$quotedReal} AS {$quotedAlias} ON {$quotedExisting}.{$foreignKey} = {$quotedAlias}.id";
                        break;
                    }
                }
                $aliases[$alias] = $realName;
            }
        }
        
        return [
            'from' => $fromClause,
            'joins' => implode(' ', $joinClauses),
            'aliases' => $aliases,
            'type' => $type
        ];
    }
    
    private static function parseWhere(array $filter): array
    {
        $sqlParts = [];
        $params = [];
        
        foreach ($filter as $field => $value) {
            if (is_array($value)) {
                // Operador explícito
                foreach ($value as $op => $val) {
                    $op = strtoupper($op);
                    if ($op === 'IN') {
                        $placeholders = implode(',', array_fill(0, count($val), '?'));
                        $sqlParts[] = self::quote($field) . " IN ({$placeholders})";
                        $params = array_merge($params, $val);
                    } else {
                        $sqlParts[] = self::quote($field) . " {$op} ?";
                        $params[] = $val;
                    }
                }
            } else {
                $sqlParts[] = self::quote($field) . " = ?";
                $params[] = $value;
            }
        }
        
        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }
    
    private static function getWhereCacheKey(array $filter): string
    {
        return md5(json_encode($filter));
    }
    
    private static function quote(string $identifier): string
    {
        return self::$quoteChar . trim($identifier, self::$quoteChar) . self::$quoteChar;
    }
}
