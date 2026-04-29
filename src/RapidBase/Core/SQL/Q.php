<?php

namespace RapidBase\Core\SQL;

/**
 * Q: Query Builder de alto rendimiento (Arquitectura Flat).
 * 
 * Patrón: from(config) -> build(TYPE, payload)
 * Sin cadena fluente. Máxima velocidad.
 * 
 * Uso:
 *   Q::from('users', ['status' => 'active', '_order' => 'name'])
 *    ->build(QType::SELECT, 'id, name');
 */
class Q
{
    private array $state = [];
    private ?JoinStrategy $joinStrategy = null;
    private SqlCompiler $compiler;

    public function __construct()
    {
        $this->compiler = new SqlCompiler();
        // Por defecto usamos la estrategia determinista (más rápida)
        $this->joinStrategy = new DeterministicJoin();
        
        // Inicializar estado con valores por defecto para evitar warnings
        $this->state = [
            'table' => '',
            'tables' => [],
            'where_sql' => '',
            'params' => [],
            'order' => '',
            'limit_sql' => '',
            'group' => '',
            'having_sql' => '',
            'join' => ''
        ];
    }

    /**
     * Inicializa la consulta.
     * @param string|array $table Nombre de tabla o array de tablas para join.
     * @param array $config Configuración plana: filtros + metadatos (_order, _limit, etc.)
     */
    public static function from($table, array $config = []): self
    {
        $instance = new self();
        
        // Normalizar tabla a array
        $tables = is_array($table) ? $table : [$table];
        $instance->state['table'] = is_array($table) ? $table[0] : $table;
        $instance->state['tables'] = $tables;
        
        // Separar filtros de metadatos
        $filters = [];
        foreach ($config as $key => $value) {
            if ($key[0] === '_') {
                // Metadatos internos
                $instance->state[$key] = $value;
            } else {
                $filters[$key] = $value;
            }
        }
        
        // Procesar filtros inmediatamente
        list($whereSql, $params) = ConditionParser::parse($filters);
        $instance->state['where_sql'] = $whereSql;
        $instance->state['params'] = $params;
        
        // Procesar metadatos opcionales
        $instance->processMetadata();
        
        return $instance;
    }

    /**
     * Genera el SQL final según el tipo de operación.
     * @param int $type Constante de QType (SELECT, INSERT, etc.)
     * @param mixed $payload Datos adicionales (campos para SELECT, rows para INSERT, data para UPDATE)
     * @return array [sql, params]
     */
    public function build(int $type, $payload = null): array
    {
        switch ($type) {
            case QType::SELECT:
                $fields = is_string($payload) ? $payload : '*';
                return $this->compiler->compileSelect($this->state, $fields);
            
            case QType::DELETE:
                return $this->compiler->compileDelete($this->state);
            
            case QType::COUNT:
                return $this->compiler->compileCount($this->state);
            
            case QType::EXISTS:
                return $this->compiler->compileExists($this->state);
            
            case QType::UPDATE:
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('UPDATE requiere un array de datos');
                }
                return $this->compiler->compileUpdate($this->state, $payload);
            
            case QType::INSERT:
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('INSERT requiere un array de filas');
                }
                return $this->compiler->compileInsertMulti($this->state, $payload);
            
            default:
                throw new \InvalidArgumentException('Tipo de consulta no válido');
        }
    }

    /**
     * Procesa metadatos de configuración (_order, _limit, _group, _having).
     */
    private function processMetadata(): void
    {
        // Order
        if (isset($this->state['_order'])) {
            $order = $this->state['_order'];
            if (is_string($order)) {
                $dir = strpos($order, '-') === 0 ? 'DESC' : 'ASC';
                $field = ltrim($order, '-');
                $this->state['order'] = "$field $dir";
            }
        }

        // Limit (puede ser entero o [offset, limit])
        if (isset($this->state['_limit'])) {
            $limit = $this->state['_limit'];
            if (is_array($limit)) {
                $this->state['limit_sql'] = (int)$limit[1] . ' OFFSET ' . (int)$limit[0];
            } else {
                $this->state['limit_sql'] = (int)$limit;
            }
        }

        // Group
        if (isset($this->state['_group'])) {
            $group = $this->state['_group'];
            $this->state['group'] = is_array($group) ? implode(', ', $group) : $group;
        }

        // Having
        if (isset($this->state['_having'])) {
            list($havingSql, $havingParams) = ConditionParser::parse($this->state['_having']);
            $this->state['having_sql'] = $havingSql;
            $this->state['params'] = array_merge($this->state['params'], $havingParams);
        }

        // Joins (si hay más de una tabla o se especifica estrategia)
        if (count($this->state['tables']) > 1 && $this->joinStrategy) {
            $this->state['join'] = $this->joinStrategy->build($this->state['tables']);
        } else {
            $this->state['join'] = '';
        }
    }
}
