<?php

namespace RapidBase\Tdd;

/**
 * Clase base para casos de prueba
 * Proporciona métodos de aserción similares a PHPUnit
 */
class TestCase
{
    /**
     * Se ejecuta antes de cada test
     */
    public function setUp(): void {}

    /**
     * Se ejecuta después de cada test
     */
    public function tearDown(): void {}

    /**
     * Assert que una condición es verdadera
     */
    protected function assertTrue(bool $condition, string $message = ''): void {
        if (!$condition) {
            throw new \AssertionError($message ?: 'Expected true but got false');
        }
    }

    /**
     * Assert que una condición es falsa
     */
    protected function assertFalse(bool $condition, string $message = ''): void {
        if ($condition) {
            throw new \AssertionError($message ?: 'Expected false but got true');
        }
    }

    /**
     * Assert que un valor es null
     */
    protected function assertNull($value, string $message = ''): void {
        if ($value !== null) {
            throw new \AssertionError($message ?: 'Expected null but got ' . gettype($value));
        }
    }

    /**
     * Assert que un valor no es null
     */
    protected function assertNotNull($value, string $message = ''): void {
        if ($value === null) {
            throw new \AssertionError($message ?: 'Expected not null but got null');
        }
    }

    /**
     * Assert que dos valores son iguales
     */
    protected function assertEquals($expected, $actual, string $message = ''): void {
        if ($expected != $actual) {
            throw new \AssertionError($message ?: "Expected '$expected' but got '$actual'");
        }
    }

    /**
     * Assert que dos valores son idénticos (mismo tipo y valor)
     */
    protected function assertSame($expected, $actual, string $message = ''): void {
        if ($expected !== $actual) {
            throw new \AssertionError($message ?: "Expected '$expected' (".gettype($expected).") but got '$actual' (".gettype($actual).")");
        }
    }

    /**
     * Assert que un valor es de un tipo específico
     */
    protected function assertIsType(string $type, $value, string $message = ''): void {
        $checks = [
            'array' => 'is_array',
            'bool' => 'is_bool',
            'callable' => 'is_callable',
            'float' => 'is_float',
            'int' => 'is_int',
            'numeric' => 'is_numeric',
            'object' => 'is_object',
            'resource' => 'is_resource',
            'string' => 'is_string',
            'scalar' => 'is_scalar',
        ];

        if (!isset($checks[$type])) {
            throw new \InvalidArgumentException("Unknown type: $type");
        }

        if (!$checks[$type]($value)) {
            throw new \AssertionError($message ?: "Expected $type but got " . gettype($value));
        }
    }

    /**
     * Assert que un valor es un array
     */
    protected function assertIsArray($value, string $message = ''): void {
        $this->assertIsType('array', $value, $message);
    }

    /**
     * Assert que un valor es un string
     */
    protected function assertIsString($value, string $message = ''): void {
        $this->assertIsType('string', $value, $message);
    }

    /**
     * Assert que un valor es un int
     */
    protected function assertIsInt($value, string $message = ''): void {
        $this->assertIsType('int', $value, $message);
    }

    /**
     * Assert que un valor es un float
     */
    protected function assertIsFloat($value, string $message = ''): void {
        $this->assertIsType('float', $value, $message);
    }

    /**
     * Assert que un valor es un bool
     */
    protected function assertIsBool($value, string $message = ''): void {
        $this->assertIsType('bool', $value, $message);
    }

    /**
     * Assert que un valor es un objeto
     */
    protected function assertIsObject($value, string $message = ''): void {
        $this->assertIsType('object', $value, $message);
    }

    /**
     * Assert que un valor es una instancia de una clase
     */
    protected function assertInstanceOf(string $className, $object, string $message = ''): void {
        if (!is_object($object) || !($object instanceof $className)) {
            throw new \AssertionError($message ?: "Expected instance of $className but got " . (is_object($object) ? get_class($object) : gettype($object)));
        }
    }

    /**
     * Assert que un array tiene una clave específica
     */
    protected function assertArrayHasKey($key, array $array, string $message = ''): void {
        if (!array_key_exists($key, $array)) {
            throw new \AssertionError($message ?: "Array does not have key '$key'");
        }
    }

    /**
     * Assert que un array no tiene una clave específica
     */
    protected function assertArrayNotHasKey($key, array $array, string $message = ''): void {
        if (array_key_exists($key, $array)) {
            throw new \AssertionError($message ?: "Array should not have key '$key'");
        }
    }

    /**
     * Assert que un array contiene un valor
     */
    protected function assertContains($needle, array $haystack, string $message = ''): void {
        if (!in_array($needle, $haystack, true)) {
            throw new \AssertionError($message ?: "Array does not contain '" . (is_scalar($needle) ? $needle : gettype($needle)) . "'");
        }
    }

    /**
     * Assert que un array no contiene un valor
     */
    protected function assertNotContains($needle, array $haystack, string $message = ''): void {
        if (in_array($needle, $haystack, true)) {
            throw new \AssertionError($message ?: "Array should not contain '" . (is_scalar($needle) ? $needle : gettype($needle)) . "'");
        }
    }

    /**
     * Assert que un array tiene un tamaño específico
     */
    protected function assertCount(int $expectedCount, array $array, string $message = ''): void {
        $actualCount = count($array);
        if ($actualCount !== $expectedCount) {
            throw new \AssertionError($message ?: "Expected count $expectedCount but got $actualCount");
        }
    }

    /**
     * Assert que un string contiene otro string
     */
    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void {
        if (strpos($haystack, $needle) === false) {
            throw new \AssertionError($message ?: "String '$haystack' does not contain '$needle'");
        }
    }

    /**
     * Assert que un string no contiene otro string
     */
    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void {
        if (strpos($haystack, $needle) !== false) {
            throw new \AssertionError($message ?: "String '$haystack' should not contain '$needle'");
        }
    }

    /**
     * Assert que un string empieza con un prefijo
     */
    protected function assertStringStartsWith(string $prefix, string $string, string $message = ''): void {
        if (strpos($string, $prefix) !== 0) {
            throw new \AssertionError($message ?: "String '$string' does not start with '$prefix'");
        }
    }

    /**
     * Assert que un string termina con un sufijo
     */
    protected function assertStringEndsWith(string $suffix, string $string, string $message = ''): void {
        if (substr($string, -strlen($suffix)) !== $suffix) {
            throw new \AssertionError($message ?: "String '$string' does not end with '$suffix'");
        }
    }

    /**
     * Assert que un valor es mayor que otro
     */
    protected function assertGreaterThan($expected, $actual, string $message = ''): void {
        if ($actual <= $expected) {
            throw new \AssertionError($message ?: "Expected $actual to be greater than $expected");
        }
    }

    /**
     * Assert que un valor es menor que otro
     */
    protected function assertLessThan($expected, $actual, string $message = ''): void {
        if ($actual >= $expected) {
            throw new \AssertionError($message ?: "Expected $actual to be less than $expected");
        }
    }

    /**
     * Assert que se lanza una excepción
     */
    protected function assertThrows(callable $callback, string $exceptionClass, string $message = ''): void {
        try {
            $callback();
            throw new \AssertionError($message ?: "Expected exception $exceptionClass was not thrown");
        } catch (\Throwable $e) {
            if (!($e instanceof $exceptionClass)) {
                throw new \AssertionError($message ?: "Expected exception $exceptionClass but got " . get_class($e));
            }
        }
    }

    /**
     * Fail the test unconditionally
     */
    protected function fail(string $message = ''): void {
        throw new \AssertionError($message ?: 'Test failed');
    }
}
