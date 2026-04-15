<?php
// tests/Unit/SQL/BuildCountDeepTest.php

$rootDir = realpath(__DIR__ . '/../../../');
require_once $rootDir . '/src/Core/SQL.php';

use Core\SQL;

class BuildCountDeepTest {
    
    public function run() {
        echo "---  Diagnóstico Profundo: SQL::buildCount ---\n";
        
        try {
            $this->testComplexFilters();
            $this->testEmptyFilters();
            $this->testNullHandling();
            echo "\n SQL.php parece estar sano. Si falla arriba, el problema es la conexión o el DB.php.\n";
        } catch (\Exception $e) {
            echo "\n ERROR DETECTADO: " . $e->getMessage() . "\n";
        }
    }

    private function testComplexFilters() {
        echo "1. Probando filtros múltiples y tipos mixtos... ";
        SQL::setDriver('mysql');
        SQL::reset(); 
        
        $table = 'users';
        $where = [
            'status' => 'active', 
            'role_id' => 2,
            'verified' => 1
        ];

        [$sql, $params] = SQL::buildCount($table, $where);

        $expectedSql = "SELECT COUNT(*) FROM `users` WHERE `status` = :p0 AND `role_id` = :p1 AND `verified` = :p2";
        if (trim($sql) !== $expectedSql) {
            throw new \Exception("SQL Mal formado.\nObtenido: $sql\nEsperado: $expectedSql");
        }

        // Claves sin ':' (coherente con SQL.php)
        $expectedParams = ["p0" => "active", "p1" => 2, "p2" => 1];
        if ($params !== $expectedParams) {
            $this->debug($params, $expectedParams);
            throw new \Exception("Mapeo de parámetros inconsistente.");
        }
        echo "OK\n";
    }

    private function testNullHandling() {
        echo "2. Probando manejo de valores NULL... ";
        SQL::reset();
        
        $where = ['deleted_at' => null, 'active' => 1];
        [$sql, $params] = SQL::buildCount('posts', $where);

        if (!str_contains($sql, "IS NULL")) {
            throw new \Exception("Fallo al generar 'IS NULL' para valores nulos.");
        }
        
        // Esperamos una clave 'p0' (sin ':') para el valor 'active'
        if (count($params) !== 1 || !isset($params['p0'])) {
            throw new \Exception("El conteo de parámetros es incorrecto al usar NULL.");
        }
        echo "OK\n";
    }

    private function testEmptyFilters() {
        echo "3. Probando tabla sin filtros (Count total)... ";
        SQL::reset();
        
        [$sql, $params] = SQL::buildCount('logs', []);
        
        if (trim($sql) !== "SELECT COUNT(*) FROM `logs` WHERE 1") {
            throw new \Exception("Fallo en conteo global sin WHERE.");
        }
        echo "OK\n";
    }

    private function debug($actual, $expected) {
        echo "\n--- DEBUG PARAMS ---\n";
        echo "Obtenido: " . json_encode($actual) . "\n";
        echo "Esperado: " . json_encode($expected) . "\n";
    }
}

(new BuildCountDeepTest())->run();