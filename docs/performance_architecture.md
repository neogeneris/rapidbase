# RapidBase Performance-First Architecture

## Core Philosophy: Efficiency over Verbosity

RapidBase is designed for high-performance applications where data volume and execution speed are critical. To achieve this, the engine prioritizes compact data structures and minimizes memory overhead at every layer.

### 1. Data Fetching Strategy: `PDO::FETCH_NUM`

By default, all bulk data queries (via `DB::grid()` or `Gateway::select()`) use `PDO::FETCH_NUM`. 

#### Why `FETCH_NUM`?
*   **Memory Efficiency**: PHP associative arrays (`FETCH_ASSOC`) store keys for every single row. In a result set of 1,000 rows with 20 columns, the string keys are repeated 20,000 times in memory. `FETCH_NUM` uses integer indices, reducing memory usage by 40-60%.
*   **CPU Cycles**: Generating hash table keys for each row is more expensive than filling a simple indexed array.
*   **Compact JSON**: When data is sent over the network (API), using numeric arrays results in a significantly smaller payload.

**Example Comparison:**

*Associative (Inefficient):*
```json
[
  {"id": 1, "name": "Alice", "role": "admin"},
  {"id": 2, "name": "Bob", "role": "user"}
]
```

*Numeric (RapidBase Standard):*
```json
{
  "head": {"columns": ["id", "name", "role"]},
  "data": [
    [1, "Alice", "admin"],
    [2, "Bob", "user"]
  ]
}
```

### 2. The Metadata Layer: `projectionMap`

To maintain developer productivity without sacrificing performance, RapidBase provides a **Metadata Layer**.
*   The `QueryResponse` object includes a `projectionMap` and `head.columns`.
*   Adapters (like `GridjsAdapter` or `RESTAdapter`) use this metadata to reconstruct the context for the frontend.
*   The frontend only needs to know that `row[0]` is the ID, `row[1]` is the Name, etc., which is managed automatically by our UI adapters.

### 3. Single Record Hydration: `PDO::FETCH_OBJ`

For individual records and ActiveRecord (ORM) operations, RapidBase uses `PDO::FETCH_OBJ`.
*   Objects (`stdClass`) are more memory-efficient than associative arrays in modern PHP versions.
*   Provides a clean `$user->name` syntax for business logic.
*   We strictly avoid `FETCH_ASSOC` even in single records to maintain architectural consistency and performance.

### 4. Zero-Overhead "All" Queries

When using `DB::all()`, RapidBase returns a collection of objects (`stdClass`). This allows you to loop through records with property access while keeping the engine lightweight.

---

## Best Practices for Developers

1.  **Direct Loops**: When using `DB::many()` or `DB::fetch()`, access columns by index:
    ```php
    $users = DB::many("SELECT name, email FROM users");
    foreach ($users as $u) {
        echo $u[0]; // Name
    }
    ```
2.  **API Consumption**: Use the provided `GridjsAdapter` or `RESTAdapter`. They are designed to consume the compact `FETCH_NUM` format and provide the necessary column mapping to the client.
3.  **ActiveRecord**: Use `User::read($id)` for standard ORM property access. It uses the efficient `FETCH_OBJ` hydration path.

> [!IMPORTANT]
> RapidBase is not a traditional "convenience-first" ORM. It is a "performance-first" engine. Always assume data is numeric unless you are working within the ActiveRecord/Model layer.
