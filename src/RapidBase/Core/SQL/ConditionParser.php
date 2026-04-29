<?php

namespace RapidBase\Core\SQL;

/**
 * Parser de condiciones robusto (Compatible con SQL.php original).
 * Soporta: '=', '>', '<', '>=', '<=', '!=', '<>', '~' (LIKE %val%), IN, IS NULL.
 */
class ConditionParser {
    private int $paramIndex = 0;

    public function parse(array $conditions): array {
        $sqlParts = [];
        $params = [];
        $this->paramIndex = 0; // Reset index per call

        foreach ($conditions as $field => $value) {
            if ($value === null) {
                // Manejo de NULL
                $sqlParts[] = "`$field` IS NULL";
                continue;
            }

            if (is_array($value)) {
                // Verificar si es un operador explícito ej: ['age' => ['>' => 50]]
                $keys = array_keys($value);
                if (count($keys) === 1 && in_array($keys[0], ['=', '>', '<', '>=', '<=', '!=', '<>', '~', 'LIKE'])) {
                    $op = $keys[0];
                    $val = $value[$op];
                    
                    if ($op === '~' || $op === 'LIKE') {
                        // Operador Like automático con %
                        $sqlParts[] = "`$field` LIKE ?";
                        $params[] = "%$val%";
                    } else {
                        $sqlParts[] = "`$field` $op ?";
                        $params[] = $val;
                    }
                    continue;
                }

                // Si llega aquí, es un array de valores para IN
                if (!empty($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $sqlParts[] = "`$field` IN ($placeholders)";
                    $params = array_merge($params, array_values($value));
                }
            } else {
                // Caso estándar igual
                $sqlParts[] = "`$field` = ?";
                $params[] = $value;
            }
        }

        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }
}