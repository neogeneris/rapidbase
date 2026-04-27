# RapidBase 

**RapidBase** is a minimalist PHP meta-framework designed for high-performance data interaction. It eliminates manual model configuration by treating your database schema as the single "source of truth."

##  Architecture

The project is split into two specialized layers that separate database intelligence from execution:

### 1. Meta (The Discovery Engine)
Located in `Meta/`, this module performs deep database introspection to generate a high-performance static map.
- **Discovery Engine:** Automated analysis of tables, columns, and data types via `MySQLDiscovery`.
- **Auto-Relationship Mapping:** Automatically detects `belongsTo` and `hasMany` relationships by analyzing Foreign Keys (FK) in the Information Schema.
- **Schema Mapping:** Consolidates all DB knowledge into a single, optimized PHP file (`schema_map.php`), preventing redundant data dictionary queries at runtime.
- **Checksum Validation:** Implements MD5 schema signatures to detect structural changes and trigger automatic map regeneration.

### 2. Core (The Execution Heart)
Located in `Core/`, this layer handles secure and efficient data interaction.
- **DB & Auth:** Clean connection abstraction via `DBInterface` and security utilities in `Auth\Password`.
- **ActiveRecord Model:** A base `Model` class designed to consume the metadata map, allowing fluid interaction with tables without manually defining properties.
- **Minimal Overhead:** Built for environments where every millisecond of execution time matters.

##  Getting Started

### Generate the Metadata Map
```php
use Meta\SchemaMapper;

// Analyzes the database and generates the project's "DNA"
SchemaMapper::generate($pdo, 'your_database_name');
```

### Basic Usage
Once the map is generated, you can interact with your database tables effortlessly:

```php
use Core\Model;

// Extend the base Model (no properties needed)
class User extends Model {}

// Fetch data
$users = User::all();
$user = User::find(1);

// Create new records
User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

// Update records
$user->update(['name' => 'Alice Smith']);

// Delete records
$user->delete();
```

## Configuration

### Database Connection
Configure your database connection in your bootstrap file or environment configuration:

```php
$pdo = new PDO(
    'mysql:host=localhost;dbname=your_database;charset=utf8mb4',
    'username',
    'password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

### Environment Variables
For production environments, consider using environment variables:

```env
DB_HOST=localhost
DB_NAME=your_database
DB_USER=username
DB_PASSWORD=password
```

## Key Features

- **Zero Configuration Models**: No need to define table names, columns, or relationships manually
- **Auto-Detected Relationships**: Foreign keys are automatically discovered and mapped
- **Performance Optimized**: Schema map caching eliminates redundant database introspection
- **Security First**: Built-in password hashing utilities and prepared statements
- **Lightweight**: Minimal footprint designed for high-performance applications

## Project Structure

```
RapidBase/
├── Meta/           # Database introspection and schema mapping
│   ├── MySQLDiscovery.php
│   └── SchemaMapper.php
├── Core/           # Runtime execution layer
│   ├── Model.php
│   ├── DBInterface.php
│   └── Auth/
│       └── Password.php
└── README.md
```

## Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.2+
- PDO MySQL extension

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/rapidbase.git
cd rapidbase
```

2. Configure your database connection

3. Generate the schema map:
```php
// Run once or when database structure changes
SchemaMapper::generate($pdo, 'your_database_name');
```

4. Start building your models!

## License

MIT License - feel free to use RapidBase in your projects.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues and questions, please open an issue on the GitHub repository.
