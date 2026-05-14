<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'connection' => 'bench',
  'driver' => 'mysql',
  'checksum' => '108a0966ff6724cd91a314e6ccb55f2a',
  'generated_at' => '2026-05-05 21:42:58',
  'features' => 
  [
    'driver' => 'mysql',
    'driver_version' => '10.4.32-MariaDB',
    'window_functions' => true,
    'get_column_meta' => true,
    'named_parameters' => true,
    'native_json_column' => true,
    'atomic_upsert' => true,
    'cte' => true,
    'returning' => false,
    'transactions' => true,
    'savepoints' => false,
    'limit_on_update' => true,
  ],
  'relationships' => 
  [
    'from' => 
    [
      'rb_test_categories' => 
      [
        'rb_test_categories' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'parent_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_orders' => 
      [
        'rb_test_users' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_order_items' => 
      [
        'rb_test_orders' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'order_id',
          'foreign_key' => 'id',
        ],
        'rb_test_products' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_product_categories' => 
      [
        'rb_test_categories' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'category_id',
          'foreign_key' => 'id',
        ],
        'rb_test_products' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_product_tags' => 
      [
        'rb_test_products' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
        'rb_test_tags' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'tag_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_user_profiles' => 
      [
        'rb_test_users' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
      ],
    ],
    'to' => 
    [
      'rb_test_categories' => 
      [
        'rb_test_categories' => 
        [
          'type' => 'hasMany',
          'local_key' => 'parent_id',
          'foreign_key' => 'id',
        ],
        'rb_test_product_categories' => 
        [
          'type' => 'hasMany',
          'local_key' => 'category_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_users' => 
      [
        'rb_test_orders' => 
        [
          'type' => 'hasMany',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
        'rb_test_user_profiles' => 
        [
          'type' => 'hasMany',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_orders' => 
      [
        'rb_test_order_items' => 
        [
          'type' => 'hasMany',
          'local_key' => 'order_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_products' => 
      [
        'rb_test_order_items' => 
        [
          'type' => 'hasMany',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
        'rb_test_product_categories' => 
        [
          'type' => 'hasMany',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
        'rb_test_product_tags' => 
        [
          'type' => 'hasMany',
          'local_key' => 'product_id',
          'foreign_key' => 'id',
        ],
      ],
      'rb_test_tags' => 
      [
        'rb_test_product_tags' => 
        [
          'type' => 'hasMany',
          'local_key' => 'tag_id',
          'foreign_key' => 'id',
        ],
      ],
    ],
  ],
  'tables' => 
  [
    'rb_test_all_types' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'col_tinyint' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_smallint' => 
      [
        'type' => 'smallint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_int' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_bigint' => 
      [
        'type' => 'bigint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_decimal' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_float' => 
      [
        'type' => 'float',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_double' => 
      [
        'type' => 'double',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_char' => 
      [
        'type' => 'char',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_varchar' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_text' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_date' => 
      [
        'type' => 'date',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_time' => 
      [
        'type' => 'time',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_datetime' => 
      [
        'type' => 'datetime',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_timestamp' => 
      [
        'type' => 'timestamp',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_json' => 
      [
        'type' => 'longtext',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_enum' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'col_boolean' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '0',
        'references' => NULL,
      ],
    ],
    'rb_test_bench_posts' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'user_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'title' => 
      [
        'type' => 'varchar',
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
        'default' => 'NULL',
        'references' => NULL,
      ],
    ],
    'rb_test_bench_post_tag' => 
    [
      'post_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'tag_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
    'rb_test_bench_tags' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
    'rb_test_bench_users' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'email' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
    'rb_test_categories' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'slug' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'parent_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => true,
        'default' => 'NULL',
        'references' => 
        [
          'table' => 'rb_test_categories',
          'column' => 'id',
        ],
      ],
      'sort_order' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '0',
        'references' => NULL,
      ],
    ],
    'rb_test_orders' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'order_number' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'user_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_users',
          'column' => 'id',
        ],
      ],
      'total_amount' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '0.00',
        'references' => NULL,
      ],
      'status' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'pending\'',
        'references' => NULL,
      ],
      'ordered_at' => 
      [
        'type' => 'timestamp',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => 'current_timestamp()',
        'references' => NULL,
      ],
      'shipped_at' => 
      [
        'type' => 'datetime',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'delivered_at' => 
      [
        'type' => 'datetime',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
    ],
    'rb_test_order_items' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'order_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_orders',
          'column' => 'id',
        ],
      ],
      'product_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_products',
          'column' => 'id',
        ],
      ],
      'quantity' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '1',
        'references' => NULL,
      ],
      'unit_price' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
    ],
    'rb_test_products' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'sku' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
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
        'default' => 'NULL',
        'references' => NULL,
      ],
      'price' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '0.00',
        'references' => NULL,
      ],
      'stock' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '0',
        'references' => NULL,
      ],
      'is_active' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '1',
        'references' => NULL,
      ],
      'weight' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'metadata' => 
      [
        'type' => 'longtext',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'created_at' => 
      [
        'type' => 'timestamp',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => 'current_timestamp()',
        'references' => NULL,
      ],
    ],
    'rb_test_product_categories' => 
    [
      'product_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_products',
          'column' => 'id',
        ],
      ],
      'category_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_categories',
          'column' => 'id',
        ],
      ],
    ],
    'rb_test_product_tags' => 
    [
      'product_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_products',
          'column' => 'id',
        ],
      ],
      'tag_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_tags',
          'column' => 'id',
        ],
      ],
    ],
    'rb_test_tags' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'color' => 
      [
        'type' => 'char',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'#000000\'',
        'references' => NULL,
      ],
    ],
    'rb_test_users' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'email' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'role' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '\'user\'',
        'references' => NULL,
      ],
      'status' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => '1',
        'references' => NULL,
      ],
      'credits' => 
      [
        'type' => 'decimal',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '0.00',
        'references' => NULL,
      ],
      'created_at' => 
      [
        'type' => 'timestamp',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => 'current_timestamp()',
        'references' => NULL,
      ],
      'updated_at' => 
      [
        'type' => 'timestamp',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => 'current_timestamp()',
        'references' => NULL,
      ],
    ],
    'rb_test_user_profiles' => 
    [
      'id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'user_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'rb_test_users',
          'column' => 'id',
        ],
      ],
      'bio' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'phone' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'birthdate' => 
      [
        'type' => 'date',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
    ],
  ],
];
