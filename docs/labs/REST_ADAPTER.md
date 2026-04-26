# RESTAdapter Implementation

## Overview

The `RESTAdapter` provides a standard REST API interface for RapidBase, allowing direct consumption of query results without grid-specific transformations. Unlike `GridjsAdapter` or `DataTableAdapter`, this adapter returns data in a universal JSON format suitable for any client (mobile apps, SPAs, third-party integrations).

## Location

- **Adapter Class**: `src/Infrastructure/Ui/Adapter/RESTAdapter.php`
- **Interactive Demo**: `examples/rest/index.php`
- **Proof of Concept**: `tests/PoC/RESTAdapterPoC.php`

## URL Parameter Specification

### Pagination

Control page number and items per page using the `page` parameter.

| Format | Example | Description |
|--------|---------|-------------|
| Simple | `?page=2` | Page 2 with default limit (20) |
| Advanced | `?page=2:50` | Page 2 with 50 items per page |

**Default**: Page 1, 20 items per page  
**Maximum**: 1000 items per page (safety limit)

### Sorting

Use the `sort` parameter with comma-separated fields. Prefix with `-` for descending order.

| Format | Example | Result |
|--------|---------|--------|
| Ascending | `?sort=name` | ORDER BY name ASC |
| Descending | `?sort=-created_at` | ORDER BY created_at DESC |
| Multiple | `?sort=-created_at,id` | ORDER BY created_at DESC, id ASC |

### Global Search

Use the `search` parameter for full-text search across configured columns.

```
?search=john
```

Searches for "john" in all columns defined in the adapter constructor's `$searchableColumns` array.

### Advanced Filters

Use the `filter` parameter with JSON-encoded conditions for complex WHERE clauses.

| Operation | Format | Example |
|-----------|--------|---------|
| Equality | `"field": "value"` | `{"status":"active"}` |
| Greater Than | `"field": ">value"` | `{"age":">18"}` |
| Less Than | `"field": "<value"` | `{"score":"<100"}` |
| Not Equal | `"field": "!=value"` | `{"role":"!=admin"}` |
| Like | `"field": "%pattern%"` | `{"name":"%John%"}` |

**Example URL**:
```
/api/users?filter={"age":">18","status":"active","name":"%Smith%"}
```

## Response Format

The adapter returns a standardized JSON structure:

```json
{
  "data": [
    {"id": 1, "name": "Alice", "email": "alice@example.com"},
    {"id": 2, "name": "Bob", "email": "bob@example.com"}
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 150,
    "total_pages": 8
  }
}
```

## Usage Example

### Basic Setup

```php
use RapidBase\Infrastructure\Ui\Adapter\RESTAdapter;
use RapidBase\Core\QueryExecutor;

$executor = new QueryExecutor($connection);
$adapter = new RESTAdapter($executor, ['name', 'email', 'title']);

// Parse request parameters
$params = $_GET; // or however you get query params
$response = $adapter->handle($params);

// Return JSON
header('Content-Type: application/json');
echo json_encode($response);
```

### Custom Default Page Size

```php
$adapter->setDefaultPerPage(50);
$response = $adapter->handle($_GET);
```

## Interactive Demo

Visit `examples/rest/index.php` to test the adapter interactively. The demo includes:

- Search box for global filtering
- Page number and items-per-page controls
- Sort column selector with ASC/DESC toggle
- Advanced filter JSON input
- Real-time URL generation
- JSON response preview
- Data table visualization

## Running Tests

Execute the proof of concept to see how parameters are parsed:

```bash
php tests/PoC/RESTAdapterPoC.php
```

This will demonstrate:
1. Basic pagination parsing
2. Custom page:limit syntax
3. Ascending and descending sort
4. Global search functionality
5. Equality filters
6. Operator-based filters (>, <, >=, <=, !=, LIKE)
7. Combined parameter handling

## Comparison with Grid Adapters

| Feature | RESTAdapter | GridjsAdapter | DataTableAdapter |
|---------|-------------|---------------|------------------|
| Output Format | Standard JSON | Grid.js specific | DataTables specific |
| Pagination | page:N or page:N:M | offset/limit | start/length |
| Sorting | -field syntax | column/order | order[i][column] |
| Use Case | General APIs | Grid.js frontend | DataTables frontend |
| Flexibility | High | Medium | Medium |

## Benefits

1. **Universal Compatibility**: Works with any client that consumes JSON
2. **Clean URLs**: Intuitive parameter syntax
3. **Flexible Filtering**: JSON-based advanced filters
4. **Standard Metadata**: Includes pagination info in every response
5. **No Framework Lock-in**: Not tied to specific frontend libraries

## Security Considerations

- Always validate and sanitize input parameters in production
- Implement rate limiting for public APIs
- Use authentication/authorization middleware
- Be cautious with filter parameters to prevent SQL injection (handled by QueryExecutor internally)
- Set reasonable limits on `per_page` to prevent resource exhaustion

## Future Enhancements

Potential improvements for future versions:

- Support for nested JSON filters (AND/OR groups)
- Field selection (`?fields=id,name,email`)
- Related resource inclusion (`?include=posts,comments`)
- Cursor-based pagination for large datasets
- ETag support for caching
- HATEOAS links in response
