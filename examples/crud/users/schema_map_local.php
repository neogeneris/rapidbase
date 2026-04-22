<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'checksum' => 'ea4a7a9a05e680ab6c5a39867b599baa',
  'generated_at' => '2026-04-22 23:40:58',
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
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'email' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'role' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'user\'',
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
