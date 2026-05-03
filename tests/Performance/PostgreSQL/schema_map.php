<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'connection' => 'postgresql',
  'driver' => 'pgsql',
  'checksum' => '9d88226755e034db3eae19ddf46caca4',
  'generated_at' => '2026-05-03 10:52:04',
  'features' => 
  [
    'driver' => 'pgsql',
    'driver_version' => '15.16 (Debian 15.16-0+deb12u1)',
    'window_functions' => true,
    'get_column_meta' => false,
    'named_parameters' => true,
    'native_json_column' => true,
    'atomic_upsert' => false,
    'cte' => true,
    'returning' => true,
    'transactions' => true,
    'savepoints' => false,
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
        'type' => 'character varying(255)',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'email' => 
      [
        'type' => 'character varying(255)',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
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
    ],
    'posts' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'posts_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'user_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'users',
          'column' => 'id',
        ],
      ],
      'title' => 
      [
        'type' => 'character varying(255)',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'content' => 
      [
        'type' => 'text',
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
    ],
    'post_categories' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'post_categories_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'post_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'posts',
          'column' => 'id',
        ],
      ],
      'category_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'categories',
          'column' => 'id',
        ],
      ],
    ],
    'categories' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'categories_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'character varying(255)',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'description' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
    'post_tags' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'post_tags_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'post_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'posts',
          'column' => 'id',
        ],
      ],
      'tag_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'tags',
          'column' => 'id',
        ],
      ],
    ],
    'comments' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'comments_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'post_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'posts',
          'column' => 'id',
        ],
      ],
      'user_id' => 
      [
        'type' => 'integer',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'users',
          'column' => 'id',
        ],
      ],
      'content' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
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
    ],
    'tags' => 
    [
      'id' => 
      [
        'type' => 'integer',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => 'nextval(\'tags_id_seq\'::regclass)',
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'character varying(255)',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
  ],
];
