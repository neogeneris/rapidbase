# REST API Example

Quick example showing how to implement a REST API using RapidBase RestAdapter.

## Overview

The RestAdapter transforms QueryResponse data (FETCH_NUM format) into a standard JSON response for REST APIs. It reads column definitions from schema_map.php and returns a compact structure with column names, titles, and paginated data.

## File Structure

```
examples/rest/
├── api.php          # REST API endpoint
├── index.php        # Frontend grid demo
├── schema_map.php   # Column definitions
└── README.md        # This file
```

## Implementation Steps

### 1. Create schema_map.php

Define your table columns with optional descriptions:

```php
<?php
return [
    'tables' => [
        'users' => [
            'id' => ['type' => 'int', 'description' => 'Id'],
            'name' => ['type' => 'string', 'description' => 'Name'],
            'email' => ['type' => 'string', 'description' => 'Email'],
            'role' => ['type' => 'string', 'description' => 'Role'],
            'created_at' => ['type' => 'datetime', 'description' => 'Created At']
        ]
    ]
];
```

### 2. Create api.php

Set up the REST endpoint using DB::grid() and RestAdapter:

```php
<?php
require_once '../../src/RapidBase/Core/DB.php';
require_once '../../src/Infrastructure/Ui/Adapter/RESTAdapter.php';

use RapidBase\Core\DB;
use RapidBase\Infrastructure\Ui\Adapter\RESTAdapter;

// Setup database
DB::setup('sqlite:database.sqlite', '', '', 'main');

// Load schema map
$schemaMap = require 'schema_map.php';

// Parse URL parameters
$pageParam = $_GET['page'] ?? 0;
$sort = $_GET['sort'] ?? [];
$search = $_GET['search'] ?? null;
$filter = $_GET['filter'] ?? null;

// Build conditions
$conditions = [];
if ($search) {
    $conditions[] = "name LIKE '%$search%' OR email LIKE '%$search%'";
}

// Parse pagination: page=pageNum,recordsPerPage (e.g., page=1,25)
$parsedPage = [];
if (is_string($pageParam) && strpos($pageParam, ',') !== false) {
    $parts = explode(',', $pageParam);
    $pageNum = max(1, (int)($parts[0] ?? 1));
    $perPage = (int)($parts[1] ?? 10);
    $parsedPage = [$pageNum, $perPage];
} else {
    $parsedPage = [1, 10];
}

// Execute query with DB::grid() - uses FETCH_NUM by default
$response = DB::grid(
    table: 'users',
    conditions: $conditions,
    page: $parsedPage,
    sort: $sort
);

// Use RESTAdapter to transform response
$adapter = new RESTAdapter(
    response: $response,
    searchableColumns: ['name', 'email'],
    schemaMap: $schemaMap,
    tableName: 'users'
);

// Generate REST response
$result = $adapter->handle($_GET);

// Return JSON
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
```

### 3. Frontend Consumption

The API returns a compact JSON structure:

```json
{
  "head": {
    "columns": ["id", "name", "email", "role", "created_at"],
    "titles": ["Id", "Name", "Email", "Role", "Created At"]
  },
  "data": [
    [1, "John Doe", "john@example.com", "admin", "2026-02-25 22:47:41"],
    [2, "Jane Smith", "jane@example.com", "user", "2026-02-25 22:47:42"]
  ],
  "page": {
    "current": 1,
    "total": 5,
    "limit": 25,
    "records": 50
  },
  "stats": {
    "execution_time": 0.002
  }
}
```

Frontend example (JavaScript):

```javascript
async function loadData(page = 1, perPage = 25) {
    const response = await fetch(`api.php?page=${page},${perPage}`);
    const result = await response.json();
    
    // Access column names
    const columns = result.head.columns;
    const titles = result.head.titles;
    
    // Access data (numeric arrays)
    const data = result.data;
    
    // Render table...
}
```

## URL Parameters

| Parameter | Format | Description |
|-----------|--------|-------------|
| page | `page=1,25` | Page number and records per page |
| sort | `sort=name` or `sort=-created_at` | Sort field (prefix `-` for DESC) |
| search | `search=john` | Global search text |
| filter | `filter={"id":"1"}` | JSON encoded filters |

## Response Format

- **head.columns**: Array of column names from schema_map.php
- **head.titles**: Array of human-readable titles
- **data**: Array of numeric arrays (FETCH_NUM format for performance)
- **page**: Pagination info (current, total, limit, records)
- **stats**: Query statistics (execution_time, etc.)

## Notes

- Uses SQLite for simplicity (file: `database.sqlite`)
- Automatically creates table on first run
- Email must be unique
- Roles: `user`, `admin`, `moderator`
- Data is returned in FETCH_NUM format (numeric indices) for maximum performance
- Column definitions are read from schema_map.php
