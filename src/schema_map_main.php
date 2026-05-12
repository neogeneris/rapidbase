<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'connection' => 'main',
  'driver' => 'sqlite',
  'checksum' => '215736c1742380b6fae41471c219e38f',
  'generated_at' => '2026-05-12 04:10:24',
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
    'post_tag' => 
    [
      'post_id' => 
      [
        'type' => 'INTEGER',
        'primary' => true,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'tag_id' => 
      [
        'type' => 'INTEGER',
        'primary' => true,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
    ],
    'posts' => 
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
      'user_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'title' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'content' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
    ],
    'tags' => 
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
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
    ],
    'users' => 
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
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'email' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
    ],
  ],
];
