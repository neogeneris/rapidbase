<?php

declare(strict_types=1);

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use ReflectionClass;
use ReflectionMethod;

/**
 * SystemInfo Endpoint
 * Provides metadata about the API itself (catalog, method details, etc.)
 */
class SystemInfo extends BaseEndpoint
{
    /**
     * Returns a catalog of all available endpoints and their methods.
     */
    public function catalog(): array
    {
        $endpointsDir = __DIR__;
        $files = glob($endpointsDir . '/*.php');
        $catalog = [];

        foreach ($files as $file) {
            $fileName = basename($file, '.php');
            
            // Skip BaseEndpoint and self
            if ($fileName === 'BaseEndpoint' || $fileName === 'SystemInfo') {
                continue;
            }

            $className = __NAMESPACE__ . '\\' . $fileName;
            
            if (class_exists($className)) {
                $instance = new $className();
                $instance->setContext($this->context);
                
                if (method_exists($instance, 'describe')) {
                    $catalog[$fileName] = $instance->describe();
                }
            }
        }

        return $catalog;
    }

    /**
     * Returns detailed information about a specific endpoint method.
     * 
     * @param string $target_ep The endpoint class name
     * @param string $method The method name
     */
    public function method(string $target_ep, string $method): array
    {
        $className = __NAMESPACE__ . '\\' . $target_ep;
        
        if (!class_exists($className)) {
            return ['error' => "Endpoint '{$target_ep}' not found"];
        }

        $instance = new $className();
        $instance->setContext($this->context);

        $reflection = new ReflectionMethod($instance, $method);
        
        $docComment = $reflection->getDocComment();
        $description = trim(preg_replace('/(\/\*\*|\*\/|\*)/', '', (string)$docComment));

        $parameters = [];
        foreach ($reflection->getParameters() as $param) {
            $parameters[] = [
                'name'     => $param->getName(),
                'type'     => $param->hasType() ? $param->getType()->getName() : 'mixed',
                'optional' => $param->isOptional()
            ];
        }

        return [
            'endpoint'   => $target_ep,
            'method'     => $method,
            'description'=> $description,
            'parameters' => $parameters,
            'visibility' => $reflection->isPublic() ? 'public' : 'protected'
        ];
    }

    /**
     * Returns version information of the API.
     */
    public function version(): array
    {
        return [
            'version' => '1.0.0',
            'name' => 'RapidBase QueryBrowser API',
            'endpoints_count' => count(glob(__DIR__ . '/*.php')) - 2 // Exclude BaseEndpoint and self
        ];
    }
}
