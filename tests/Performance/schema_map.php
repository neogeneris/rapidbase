<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'checksum' => 'd66a0c91fb16ef2b5985fe7bcb50a578',
  'generated_at' => '2026-05-02 10:03:21',
  'relationships' => 
  [
    'from' => 
    [
      'comments' => 
      [
        'users' => 
        [
          'user_id' => 'id',
        ],
        'posts' => 
        [
          'post_id' => 'id',
        ],
      ],
      'post_categories' => 
      [
        'categories' => 
        [
          'category_id' => 'id',
        ],
        'posts' => 
        [
          'post_id' => 'id',
        ],
      ],
      'post_tags' => 
      [
        'tags' => 
        [
          'tag_id' => 'id',
        ],
        'posts' => 
        [
          'post_id' => 'id',
        ],
      ],
      'posts' => 
      [
        'users' => 
        [
          'user_id' => 'id',
        ],
      ],
    ],
    'to' => 
    [
      'users' => 
      [
        'comments' => 
        [
          'id' => 'user_id',
        ],
        'posts' => 
        [
          'id' => 'user_id',
        ],
      ],
      'posts' => 
      [
        'comments' => 
        [
          'id' => 'post_id',
        ],
        'post_categories' => 
        [
          'id' => 'post_id',
        ],
        'post_tags' => 
        [
          'id' => 'post_id',
        ],
      ],
      'categories' => 
      [
        'post_categories' => 
        [
          'id' => 'category_id',
        ],
      ],
      'tags' => 
      [
        'post_tags' => 
        [
          'id' => 'tag_id',
        ],
      ],
    ],
  ],
  'tables' => 
  [
    'categories' => 
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
    ],
    'comments' => 
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
      'post_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'posts.id',
        'description' => NULL,
      ],
      'user_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'users.id',
        'description' => NULL,
      ],
      'content' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
        'description' => NULL,
      ],
      'created_at' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'CURRENT_TIMESTAMP',
        'references' => NULL,
        'description' => NULL,
      ],
    ],
    'post_categories' => 
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
      'post_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'posts.id',
        'description' => NULL,
      ],
      'category_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'categories.id',
        'description' => NULL,
      ],
    ],
    'post_tags' => 
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
      'post_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'posts.id',
        'description' => NULL,
      ],
      'tag_id' => 
      [
        'type' => 'INTEGER',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'tags.id',
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
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 'users.id',
        'description' => NULL,
      ],
      'title' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
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
      'created_at' => 
      [
        'type' => 'TEXT',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'CURRENT_TIMESTAMP',
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
        'nullable' => false,
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
      'created_at' => 
      [
        'type' => 'TEXT',
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
