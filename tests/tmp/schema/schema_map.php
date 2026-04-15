<?php
// Auto-generated schema map by Meta\SchemaMapper
return [
  'checksum' => 'e347aefaba5fcbe32cf70e14b540d26b',
  'generated_at' => '2026-04-01 10:09:56',
  'relationships' => 
  [
    'from' => 
    [
      'drivers' => 
      [
        'users' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
      ],
      'driver_partners' => 
      [
        'drivers' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
        'partners' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'partner_id',
          'foreign_key' => 'id',
        ],
      ],
      'partners' => 
      [
        'users' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
      ],
      'practice_sessions' => 
      [
        'drivers' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
      ],
      'race_results' => 
      [
        'drivers' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
      ],
      'user_follows' => 
      [
        'users' => 
        [
          'type' => 'belongsTo',
          'local_key' => 'fan_id',
          'foreign_key' => 'id',
        ],
      ],
    ],
    'to' => 
    [
      'users' => 
      [
        'drivers' => 
        [
          'type' => 'hasOne',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
        'partners' => 
        [
          'type' => 'hasMany',
          'local_key' => 'user_id',
          'foreign_key' => 'id',
        ],
        'user_follows' => 
        [
          'type' => 'hasMany',
          'local_key' => 'fan_id',
          'foreign_key' => 'id',
        ],
      ],
      'drivers' => 
      [
        'driver_partners' => 
        [
          'type' => 'hasMany',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
        'practice_sessions' => 
        [
          'type' => 'hasMany',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
        'race_results' => 
        [
          'type' => 'hasMany',
          'local_key' => 'driver_id',
          'foreign_key' => 'id',
        ],
      ],
      'partners' => 
      [
        'driver_partners' => 
        [
          'type' => 'hasMany',
          'local_key' => 'partner_id',
          'foreign_key' => 'id',
        ],
      ],
    ],
  ],
  'tables' => 
  [
    'applicants' => 
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
      'full_name' => 
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
      'phone' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'role' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'message' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
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
    'driver_partners' => 
    [
      'driver_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'drivers',
          'column' => 'id',
        ],
      ],
      'partner_id' => 
      [
        'type' => 'int',
        'primary' => true,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'partners',
          'column' => 'id',
        ],
      ],
    ],
    'drivers' => 
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
          'table' => 'users',
          'column' => 'id',
        ],
      ],
      'full_name' => 
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
      'category' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'Pro-Am\'',
        'references' => NULL,
      ],
      'bio_short' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'short_bio' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'avatar_img' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'podium_img' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'instagram' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'twitter' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'location' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
    ],
    'partners' => 
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
        'nullable' => true,
        'default' => 'NULL',
        'references' => 
        [
          'table' => 'users',
          'column' => 'id',
        ],
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
      'logo' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'website' => 
      [
        'type' => 'varchar',
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
      'type' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '\'partner\'',
        'references' => NULL,
      ],
      'is_active' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '1',
        'references' => NULL,
      ],
      'telemetry_access' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '0',
        'references' => NULL,
      ],
      'reports_access' => 
      [
        'type' => 'tinyint',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => '1',
        'references' => NULL,
      ],
    ],
    'practice_sessions' => 
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
      'driver_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'drivers',
          'column' => 'id',
        ],
      ],
      'session_date' => 
      [
        'type' => 'date',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'session_type' => 
      [
        'type' => 'enum',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'track_name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'best_lap_time' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'technical_objectives' => 
      [
        'type' => 'text',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'coach_feedback' => 
      [
        'type' => 'text',
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
    'race_results' => 
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
      'driver_id' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => true,
        'nullable' => false,
        'default' => NULL,
        'references' => 
        [
          'table' => 'drivers',
          'column' => 'id',
        ],
      ],
      'race_date' => 
      [
        'type' => 'date',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'event_name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'track_name' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'position' => 
      [
        'type' => 'int',
        'primary' => false,
        'foreign' => false,
        'nullable' => false,
        'default' => NULL,
        'references' => NULL,
      ],
      'car_model' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
    ],
    'user_follows' => 
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
      'fan_id' => 
      [
        'type' => 'int',
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
      'driver_id' => 
      [
        'type' => 'int',
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
    'users' => 
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
      'username' => 
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
      'phone' => 
      [
        'type' => 'varchar',
        'primary' => false,
        'foreign' => false,
        'nullable' => true,
        'default' => 'NULL',
        'references' => NULL,
      ],
      'password' => 
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
        'nullable' => true,
        'default' => '\'driver\'',
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
  ],
];
