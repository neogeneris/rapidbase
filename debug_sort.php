<?php
require_once '/workspace/src/RapidBase/Core/SQL.php';
use RapidBase\Core\SQL;

// Debug del buildSelect - ver que pasa con sort
$sort = ['-name'];
echo 'Input sort: '; var_dump($sort);

// Simular lo que hace buildSelect en lineas 520-540
$sortFields = [];
$isAssociative = !empty($sort) && !is_numeric(key($sort));
echo 'isAssociative: ' . ($isAssociative ? 'TRUE' : 'FALSE') . PHP_EOL;

if ($isAssociative) {
    echo 'Entrando por asociativo' . PHP_EOL;
    foreach ($sort as $field => $dir) {
        echo "  field=$field, dir=$dir" . PHP_EOL;
    }
} else {
    echo 'Entrando por numerico' . PHP_EOL;
    $sortFields = $sort;
}

echo 'sortFields final: '; var_dump($sortFields);

// Ahora probar buildOrderBy directamente
echo PHP_EOL . 'Probando buildOrderBy:' . PHP_EOL;
$result = SQL::buildOrderBy($sortFields);
echo 'Resultado: ' . $result . PHP_EOL;
