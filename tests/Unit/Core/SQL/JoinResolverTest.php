<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';

use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\JoinResolver;

// 1. Configurar el mapa de relaciones manualmente
$map = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id'
                ]
            ]
        ],
        'to' => [
            'posts' => [
                'users' => [
                    'type' => 'belongsTo',
                    'local_key' => 'user_id',
                    'foreign_key' => 'id'
                ]
            ]
        ]
    ],
    'tables' => [
        'users' => ['id' => ['type' => 'int'], 'name' => ['type' => 'varchar']],
        'posts' => ['id' => ['type' => 'int'], 'user_id' => ['type' => 'int'], 'title' => ['type' => 'varchar']]
    ]
];

SchemaMap::setMap($map, 'default');

// 2. Instanciar JoinResolver
$resolver = new JoinResolver('default');

// 3. Probar la resolución de JOINs
echo "Resolviendo ['users', 'posts']:\n";
$result = $resolver->resolve(['users', 'posts']);
echo $result['from'] . "\n\n";

echo "Resolviendo ['posts', 'users']:\n";
$result2 = $resolver->resolve(['posts', 'users']);
echo $result2['from'] . "\n";