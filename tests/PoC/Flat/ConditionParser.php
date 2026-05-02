<?php

namespace RapidBase\PoC\Flat;

/**
 * ConditionParser: Traduce arrays de filtros a condiciones SQL seguras.
 * Soporta operadores simples, IN, y comparaciones compuestas.
 */
class ConditionParser
{
    /**
     * Convierte un array de filtros en [sql_fragment, params]
     * Ej: ['status' => 'active', 'id' => [1,2]] -> ["status = ? AND id IN (?,?,?)", [...]]
     */
    public static function parse(array $filter): array
    {
        if (empty($filter)) {
            return ['', []];
        }

        $conditions = [];
        $params = [];

        foreach ($filter as $field => $value) {
            // Ignorar metadatos internos que empiecen con _
            if ($field[0] === '_') {
                continue;
            }

            if (is_array($value)) {
                // Caso IN o operador compuesto
                if (self::isAssociative($value)) {
                    // Operador compuesto: ['age' => ['>' => 18]]
                    foreach ($value as $op => $val) {
                        $conditions[] = "$field $op ?";
                        $params[] = $val;
                    }
                } else {
                    // Lista para IN: ['id' => [1, 2, 3]]
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "$field IN ($placeholders)";
                    $params = array_merge($params, $value);
                }
            } else {
                // Igualdad simple
                $conditions[] = "$field = ?";
                $params[] = $value;
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    private static function isAssociative(array $arr): bool
    {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
