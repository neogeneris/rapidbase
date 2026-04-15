<?php

namespace Tests\Unit\SQL;

/**
 * 1. Definimos la ruta raíz del proyecto de forma absoluta.
 * __DIR__ es C:\xampp\htdocs\RapidBase\tests\Unit\SQL
 */
$rootDir = realpath(__DIR__ . '/../../../');

// 2. Incluimos las dependencias necesarias de la carpeta src
require_once $rootDir . '/src/Core/Conn.php';
require_once $rootDir . '/src/Core/SQL.php';

// 3. Importamos para poder usar SQL:: directamente
use Core\SQL;
SQL::setDriver('mysql');
class BuildCountTest 
{
    public function run() 
    {
        echo "--- ??? Iniciando Pruebas: SQL::buildCount ---\n";
        
        try {
            $this->testNamedParameters();
            echo "\n? Test completado con éxito.\n";
        } catch (\Throwable $e) {
            echo "\n? Error: " . $e->getMessage() . "\n";
        }
    }

private function testNamedParameters() 
{
    echo "Validando parámetros nombrados (:p0)... ";
    
    $table = 'applicants';
    $where = ['status' => 'active'];
    
    [$sql, $params] = SQL::buildCount($table, $where);

    // DEBUG: Si falla, queremos ver el contenido real
    if (!isset($params['p0']) || $params['p0'] !== 'active') {
        echo "\n--- DEBUG DE PARÁMETROS ---\n";
        echo "SQL obtenido: $sql\n";
        echo "Array de params recibido:\n";
        print_r($params);
        echo "---------------------------\n";
        
        throw new \Exception("El mapeo de :p0 falló. Revisa si el array usa ':p0' o 'p0'.");
    }
    
    echo "OK\n";
}
}

// Ejecución manual
(new BuildCountTest())->run();
