<?php

namespace RapidBase\Api;

use ReflectionClass;
use ReflectionMethod;

abstract class BaseEndpoint {
    protected ApiContext $context;

    /**
     * Inyecta el contexto (fundamental para pruebas unitarias)
     */
    public function setContext(ApiContext $context): void {
        $this->context = $context;
    }

    /**
     * Extrae información de la clase para el Tester automático
     */
    public function describe(): array {
        $reflection = new ReflectionClass($this);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $manifest = [];

        foreach ($methods as $method) {
            // Ignorar métodos heredados de la base o mágicos
            if ($method->class === self::class || str_starts_with($method->name, '__')) {
                continue;
            }

            $manifest[$method->name] = [
                'description' => $this->parseDoc($method->getDocComment()),
                'parameters'  => array_map(fn($p) => [
                    'name'     => $p->getName(),
                    'type'     => $p->hasType() ? $p->getType()->getName() : 'mixed',
                    'optional' => $p->isOptional()
                ], $method->getParameters())
            ];
        }

        return $manifest;
    }

    /**
     * Devuelve la versión del endpoint
     */
    public function version(): array {
        return [
            'endpoint' => static::class,
            'version' => '1.0.0'
        ];
    }

    /**
     * Catálogo de todos los métodos disponibles en este endpoint
     */
    public function catalog(): array {
        return [
            'endpoint' => static::class,
            'methods' => array_keys($this->describe())
        ];
    }

    private function parseDoc($doc): string {
        return trim(preg_replace('/(\/\*\*|\*\/|\*)/', '', (string)$doc));
    }
}
