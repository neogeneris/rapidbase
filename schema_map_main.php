<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'connection' => 'main',
  'driver' => 'sqlite',
  'checksum' => '46cb946b90b663109563ca1a63c6f30b',
  'generated_at' => '2026-05-11 19:42:58',
  'features' => 
  [
    'driver' => 'sqlite',
    'driver_version' => '3.40.1',
    'window_functions' => true,
    'get_column_meta' => true,
    'named_parameters' => true,
    'native_json_column' => true,
    'atomic_upsert' => true,
    'cte' => true,
    'returning' => true,
    'transactions' => true,
    'savepoints' => true,
    'limit_on_update' => false,
  ],
  'relationships' => 
  [
    'from' => 
    [
    ],
    'to' => 
    [
    ],
  ],
  'tables' => 
  [
    'connections' => 
    [
      'id' => 
      [
        'type' => 'INTEGER',
        'primary' => true,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'name' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'driver' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'host' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'port' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'database' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'username' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'password' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'description' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'status' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'dev\'',
        'references' => NULL,
        'description' => NULL,
      ],
      'created_at' => 
      [
        'type' => 'DATETIME',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'CURRENT_TIMESTAMP',
        'references' => NULL,
        'description' => NULL,
      ],
    ],
  ],
];
