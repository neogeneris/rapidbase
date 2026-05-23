<?php
return array (
  'key' => 'schema_6c1050e4',
  'expires_at' => 1779498435,
  'data' => 
  array (
    'tables' => 
    array (
      'connections' => 
      array (
        'id' => 
        array (
          'type' => 'INTEGER',
          'primary' => true,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'name' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => false,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'driver' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => false,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'host' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'port' => 
        array (
          'type' => 'INTEGER',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'database' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'username' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'password' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'description' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => NULL,
          'references' => NULL,
          'description' => NULL,
        ),
        'environment' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => '\'development\'',
          'references' => NULL,
          'description' => NULL,
        ),
        'status' => 
        array (
          'type' => 'TEXT',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => '\'active\'',
          'references' => NULL,
          'description' => NULL,
        ),
        'created_at' => 
        array (
          'type' => 'DATETIME',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => 'CURRENT_TIMESTAMP',
          'references' => NULL,
          'description' => NULL,
        ),
        'updated_at' => 
        array (
          'type' => 'DATETIME',
          'primary' => false,
          'foreign' => false,
          'nullable' => true,
          'default' => 'CURRENT_TIMESTAMP',
          'references' => NULL,
          'description' => NULL,
        ),
      ),
    ),
    'relationships' => 
    array (
      'from' => 
      array (
      ),
      'to' => 
      array (
      ),
    ),
    'driver' => 'sqlite',
    'features' => 
    array (
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
    ),
    'checksum' => '',
  ),
);