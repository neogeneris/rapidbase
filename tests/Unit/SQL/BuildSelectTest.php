<?php



include_once "../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildSelectTest.php (Ensamblaje Final) ---\n";

function assert_select($name, $expectedSql, $expectedParams, $actual) {
    $actualSql = preg_replace('/\s+/', ' ', trim($actual[0]));
    $expectedSql = preg_replace('/\s+/', ' ', trim($expectedSql));

    if ($actualSql === $expectedSql && $actual[1] === $expectedParams) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        echo "  Esperado SQL: '$expectedSql'\n";
        echo "  Obtenido SQL: '$actualSql'\n";
        echo "  Params OK: " . ($actual[1] === $expectedParams ? 'SÍ' : 'NO') . "\n";
        exit(1);
    }
}

SQL::setRelationsMap([
    'from' => [
        'users' => [
            'drivers' => ['local_key' => 'id', 'foreign_key' => 'user_id']
        ]
    ]
]);

// CASO 1: Ensamblaje Maestro
SQL::reset(); 
$actual = SQL::buildSelect(
    ['u.name', 'd.license'],           
    ['users', 'drivers'],              
    ['u.active' => 1],                 
    ['d.category'],                    
    ['total' => ['>' => 5]],           
    ['u.name'],                   
    2,                                 
    15                                 
);

$expectedSql = "SELECT `u`.`name`, `d`.`license` FROM `users` LEFT JOIN `drivers` ON `users`.`id` = `drivers`.`user_id` WHERE `u`.`active` = :p0 GROUP BY `d`.`category` HAVING `total` > :p1 ORDER BY `u`.`name` ASC LIMIT 15 OFFSET 15";
$expectedParams = ["p0" => 1, "p1" => 5];

assert_select("Ensamblaje Maestro", $expectedSql, $expectedParams, $actual);

echo "\n\033[32m[SUCCESS]\033[0m BuildSelectTest completado.\n";
