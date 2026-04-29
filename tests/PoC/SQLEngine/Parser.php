<?php

namespace RapidBase\SQLEngine;

/**
 * Parser - Convierte condiciones de múltiples formatos a estructura interna.
 * 
 * Soporta:
 * - Arrays asociativos tradicionales
 * - Strings tipo URL query params (?status=active&role=admin)
 * - JSON strings
 * - Expresiones complejas con operadores
 */
class Parser
{
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'IN', 'NOT IN'];
    
    /**
     * Parsea condiciones desde múltiples formatos.
     * 
     * @param mixed $input Puede ser:
     *   - array: ['status' => 'active', 'role' => 'admin']
     *   - string URL: '?status=active&role=admin' o 'status=active&role=admin'
     *   - JSON: '{"status":"active","role":"admin"}'
     * @return array Estructura normalizada [campo => valor, ...]
     */
    public static function parseConditions($input): array
    {
        if (is_array($input)) {
            return self::parseArray($input);
        }
        
        if (is_string($input)) {
            // Detectar formato
            if (self::isJson($input)) {
                return self::parseJson($input);
            }
            
            // Asumir formato URL/query string
            return self::parseQueryString($input);
        }
        
        throw new \InvalidArgumentException("Input must be array or string");
    }
    
    /**
     * Parsea un array tradicional.
     */
    private static function parseArray(array $input): array
    {
        $result = [];
        
        foreach ($input as $key => $value) {
            // Normalizar clave (quitar prefijos como ?, $, etc.)
            $cleanKey = ltrim($key, '?$');
            
            // Manejar valores especiales
            if (is_array($value)) {
                // Operador explícito: ['age' => ['>' => 18]]
                $result[$cleanKey] = $value;
            } elseif (is_string($value) && self::containsOperator($value)) {
                // String con operador: 'age > 18'
                $result[$cleanKey] = self::parseOperatorString($value);
            } else {
                // Valor simple
                $result[$cleanKey] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Parsea query string tipo URL.
     */
    private static function parseQueryString(string $input): array
    {
        // Quitar ? inicial si existe
        $input = ltrim($input, '?');
        
        $result = [];
        $pairs = explode('&', $input);
        
        foreach ($pairs as $pair) {
            if (strpos($pair, '=') === false) {
                continue;
            }
            
            [$key, $value] = explode('=', $pair, 2);
            $key = urldecode(trim($key));
            $value = urldecode(trim($value));
            
            // Manejo especial para arrays en URL: ids[]=1&ids[]=2
            if (substr($key, -2) === '[]') {
                $cleanKey = substr($key, 0, -2);
                if (!isset($result[$cleanKey])) {
                    $result[$cleanKey] = [];
                }
                $result[$cleanKey][] = $value;
            } else {
                $result[$key] = $value;
            }
        }
        
        return self::parseArray($result);
    }
    
    /**
     * Parsea JSON string.
     */
    private static function parseJson(string $input): array
    {
        $decoded = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }
        
        return self::parseArray($decoded);
    }
    
    /**
     * Verifica si un string es JSON válido.
     */
    private static function isJson(string $string): bool
    {
        $trimmed = trim($string);
        return ($trimmed[0] === '{' || $trimmed[0] === '[');
    }
    
    /**
     * Verifica si un string contiene operadores SQL.
     */
    private static function containsOperator(string $value): bool
    {
        // Solo verificar si parece una expresión con operador
        // Ej: "> 18", "!= admin", "LIKE %test%"
        foreach (self::OPERATORS as $op) {
            if (stripos($value, $op) !== false && strlen($value) > strlen($op)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Parsea string con operador: 'age > 18' → ['>' => 18]
     */
    private static function parseOperatorString(string $value): array
    {
        foreach (self::OPERATORS as $op) {
            $pattern = '/\s*' . preg_quote($op, '/') . '\s*/i';
            if (preg_match($pattern, $value, $matches)) {
                $parts = preg_split($pattern, $value, 2);
                if (count($parts) === 2 && trim($parts[1]) !== '') {
                    return [strtoupper($op) => trim($parts[1])];
                }
            }
        }
        
        // Si no se encontró operador válido, retornar como igualdad
        return ['=' => $value];
    }
    
    /**
     * Parsea condiciones complejas con AND/OR.
     * Ej: '(status=active OR role=admin) AND active=1'
     */
    public static function parseComplex(string $expression): array
    {
        $result = [];
        $expression = trim($expression);
        
        // Remover paréntesis externos
        while ($expression[0] === '(' && substr($expression, -1) === ')') {
            $expression = trim(substr($expression, 1, -1));
        }
        
        // Dividir por AND (nivel superior)
        $andParts = self::splitByOperator($expression, 'AND');
        
        foreach ($andParts as $part) {
            $part = trim($part);
            
            // Verificar si hay OR
            if (stripos($part, ' OR ') !== false) {
                $orParts = self::splitByOperator($part, 'OR');
                $orConditions = [];
                
                foreach ($orParts as $orPart) {
                    $orConditions[] = self::parseSimpleCondition(trim($orPart));
                }
                
                $result[] = ['OR' => $orConditions];
            } else {
                $result[] = self::parseSimpleCondition($part);
            }
        }
        
        return $result;
    }
    
    /**
     * Divide expresión por operador lógico.
     */
    private static function splitByOperator(string $expression, string $operator): array
    {
        $parts = [];
        $current = '';
        $parenDepth = 0;
        $opLength = strlen($operator);
        
        for ($i = 0; $i < strlen($expression); $i++) {
            $char = $expression[$i];
            
            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            }
            
            // Verificar operador solo si estamos en nivel 0 de paréntesis
            if ($parenDepth === 0 && strtoupper(substr($expression, $i, $opLength)) === $operator) {
                // Verificar que sea palabra completa
                $before = $i > 0 ? $expression[$i - 1] : ' ';
                $after = isset($expression[$i + $opLength]) ? $expression[$i + $opLength] : ' ';
                
                if (!ctype_alpha($before) && !ctype_alpha($after)) {
                    $parts[] = $current;
                    $current = '';
                    $i += $opLength - 1;
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
    }
    
    /**
     * Parsea condición simple: 'field = value'
     */
    private static function parseSimpleCondition(string $condition): array
    {
        foreach (self::OPERATORS as $op) {
            $pattern = '/\s*' . preg_quote($op, '/') . '\s*/i';
            if (preg_match($pattern, $condition, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];
                $field = trim(substr($condition, 0, $offset));
                $value = trim(substr($condition, $offset + strlen($matches[0][0])));
                
                return [$field => [strtoupper($op) => $value]];
            }
        }
        
        // Sin operador, asumir igualdad
        if (strpos($condition, '=') !== false) {
            [$field, $value] = explode('=', $condition, 2);
            return [trim($field) => trim($value)];
        }
        
        throw new \InvalidArgumentException("Cannot parse condition: {$condition}");
    }
}
