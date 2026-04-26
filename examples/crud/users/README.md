# Users CRUD Example

Complete CRUD (Create, Read, Update, Delete) example using RapidBase with DataTables frontend.

## Features

- **DataTables Integration**: Server-side pagination, sorting, and search
- **Optimized Performance**: Uses `DB::grid()` with `FETCH_NUM` for fast data retrieval
- **Security**: Parameterized queries, input validation via Model
- **Modern UI**: Responsive design with modal forms
- **RESTful API**: Clean separation between frontend and backend

## Installation

1. **Navigate to the example directory**:
   ```bash
   cd examples/crud/users
   ```

2. **Seed the database** (creates table and 50 sample users):
   ```bash
   php seed.php
   ```

3. **Open in browser**:
   ```
   http://localhost/RapidBase/examples/crud/users/index.php
   ```

## File Structure

```
examples/crud/users/
├── config.php       # Database and autoloader configuration
├── User.php         # ActiveRecord model for users
├── api.php          # RESTful API endpoints
├── index.php        # Frontend with DataTables
├── seed.php         # Database seeder script
└── README.md        # This file
```

## Usage

### View List
- Open `index.php` to see all users in a paginated table
- Use search box to filter users
- Click column headers to sort

### Create User
- Click "+ New User" button
- Fill in name, email, and role
- Click "Save"

### Edit User
- Click "Edit" button on any row
- Modify fields in the modal
- Click "Save"

### Delete User
- Click "Delete" button on any row
- Confirm deletion

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `api.php?action=list&page=1&per_page=10` | Get paginated list |
| GET | `api.php?action=get&id=1` | Get single user |
| POST | `api.php?action=create` | Create new user |
| POST | `api.php?action=update` | Update user |
| POST | `api.php?action=delete` | Delete user |

## Customization

### Change Fields
Edit `User.php` to add/remove fields:
```php
protected array $fillable = ['name', 'email', 'role', 'phone', 'address'];
```

### Add Validation
Override `beforeSave()` in `User.php`:
```php
public function beforeSave(): bool {
    if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    return true;
}
```

### Change Pagination
Modify default values in `api.php`:
```php
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25; // 25 per page
```

## Notes

- Uses SQLite for simplicity (file: `database.sqlite`)
- Automatically creates table on first run
- Email must be unique
- Roles: `user`, `admin`, `moderator`
