# RapidBase TDD Runner Guide

This guide explains how to use the **RapidBase TDD Runner**, a lightweight testing framework designed to work seamlessly with RapidBase Models and Endpoints without external dependencies.

## Table of Contents
1. [Introduction](#introduction)
2. [Installation & Setup](#installation--setup)
3. [Generating Test Skeletons](#generating-test-skeletons)
4. [Running Tests](#running-tests)
5. [Viewing Reports](#viewing-reports)
6. [Workflow Example](#workflow-example)

---

## Introduction

The **TDD Runner** is a command-line tool that allows you to:
- Automatically discover Models and Endpoints in your project.
- Generate test skeletons (informal and formal) based on class structure.
- Execute tests with intelligent failure detection.
- Generate visual reports of test history and performance.

It works out-of-the-box with:
- **Models**: Classes extending `RapidBase\ORM\ActiveRecord\Model`.
- **Endpoints**: Classes extending `RapidBase\Api\BaseEndpoint`.

---

## Installation & Setup

No installation is required. The runner is located in your PoC directory:

```bash
cd tests/PoC/RapidBase
```

Ensure your database file (`rapidbase_tdd.sqlite`) is writable. It will be created automatically on the first run.

---

## Generating Test Skeletons

The runner can automatically generate test files for your classes. It detects the type of class (Model or Endpoint) and creates appropriate test structures.

### Command Syntax
```bash
php tdd_runner.php --skeleton <output_directory>
```

### Example: Generate Tests for All Classes
```bash
php tdd_runner.php --skeleton ../tests_informales
```

This command will:
1. Scan `Models/` and `Endpoints/` directories.
2. Detect class types via Reflection.
3. Create a test file for each class in `../tests_informales`.

### Generated Structure
For a model `Connection.php`:
- Creates `ConnectionTest.php`.
- Includes tests for `save()`, `delete()`, `find()`, and validation rules.

For an endpoint `ConnectionManager.php`:
- Creates `ConnectionManagerTest.php`.
- Includes tests for `list()`, `create()`, `connect()`, etc.
- Mocks the `ApiContext` automatically.

---

## Running Tests

### Run All Tests
Executes all discovered tests (written or auto-generated skeletons).
```bash
php tdd_runner.php --all
```

### Verbose Mode
Shows detailed output for each test, including dumps on failure.
```bash
php tdd_runner.php --all -v
```

### Stop on First Failure
Useful for TDD workflow. Stops immediately when a test fails.
```bash
php tdd_runner.php --first
```

### Run Only Failing Tests
Re-runs only the tests that failed in the last execution.
```bash
php tdd_runner.php --failing
```

### Scan Mode
Detects new classes and runs basic syntax/validation checks without executing logic.
```bash
php tdd_runner.php --scan
```

---

## Viewing Reports

Generate a visual report of test history, success rates, and performance metrics.

### Command
```bash
php tdd_report.php
```

### Options
- `--limit <N>`: Show only the last N records (default: 50).
- `--json`: Output report in JSON format.

### Report Content
- **Summary**: Total tests, pass rate, trend.
- **History**: List of recent executions with status and duration.
- **Slowest Tests**: Top 5 slowest tests for optimization.
- **System Status**: Database and cache health.

---

## Workflow Example

### Step 1: Create a New Endpoint
Create `Endpoints/UserService.php`:
```php
namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;

class UserService extends BaseEndpoint {
    /**
     * Returns a list of users.
     */
    public function list(): array {
        return [['id' => 1, 'name' => 'John']];
    }
}
```

### Step 2: Generate Test Skeleton
```bash
php tdd_runner.php --skeleton ../tests_informales
```
This creates `tests_informales/UserServiceTest.php` with a basic test for `list()`.

### Step 3: Run Tests
```bash
php tdd_runner.php --all -v
```
Output:
```
_________________________________________________
Running: UserService::list ... ✓ PASS
_________________________________________________
Total: 1  Pass: 1  Fail: 0
```

### Step 4: Implement Logic & Refine Test
Edit `UserServiceTest.php` to add specific assertions:
```php
public function testList(): void {
    $result = $this->endpoint->list();
    SimpleTester::check(
        count($result) > 0, 
        "User list should not be empty", 
        $result
    );
}
```

### Step 5: View Report
```bash
php tdd_report.php
```

---

## Tips & Best Practices

1. **Informal vs Formal Tests**:
   - Use `tests_informales/` for quick, iterative testing during development.
   - Use `tests_formales/` (PHPUnit) for stable, CI/CD-ready tests.

2. **Auto-Discovery**:
   - The runner automatically detects new classes. No configuration needed.

3. **Debugging Failures**:
   - Use `-v` flag to see detailed data dumps on failure.
   - Check `tdd_report.php` for historical trends.

4. **Performance**:
   - Monitor the "Slowest Tests" section in the report to optimize bottlenecks.

---

## Troubleshooting

**Issue**: Tests not found.
- **Solution**: Ensure classes are in `Models/` or `Endpoints/` and extend the correct base class.

**Issue**: Database locked.
- **Solution**: Close other processes accessing `rapidbase_tdd.sqlite`.

**Issue**: Skeleton generation fails.
- **Solution**: Check PHP syntax of the target class (`php -l ClassName.php`).

---

For more details, see the source code in `tests/PoC/RapidBase/Tdd/Runner.php`.
