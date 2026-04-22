<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'checksum' => '2a20f5e6aa7cf29ffc29775617a91318',
  'generated_at' => '2026-04-22 22:55:40',
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
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'users_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'character varying',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'email' => 
      [
        'type' => 'character varying',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'username' => 
      [
        'type' => 'character varying',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'phone' => 
      [
        'type' => 'character varying',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
      ],
      'website' => 
      [
        'type' => 'character varying',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
      ],
      'created_at' => 
      [
        'type' => 'timestamp without time zone',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'CURRENT_TIMESTAMP',
        'references' => NULL,
      ],
      'updated_at' => 
      [
        'type' => 'timestamp without time zone',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'CURRENT_TIMESTAMP',
        'references' => NULL,
      ],
    ],
  ],
];
