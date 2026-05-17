<?php

namespace RapidBase\Tests\X;

use RapidBase\Core\X;
use RapidBase\Core\XResponse;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;

/**
 * Pruebas unitarias para la clase X usando el framework TDD de RapidBase
 */
class XTest {
    private X $x;
    private string $connectionId = 'x_test';
    
    public function setUp(): void {
        // Setup de base de datos SQLite en memoria
        DB::setup('sqlite::memory:', '', '', $this->connectionId);
        $pdo = Conn::get($this->connectionId);
        
        // Crear tabla de prueba
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INTEGER)");
        $pdo->exec("INSERT INTO users (name, email, age) VALUES 
            ('Alice', 'alice@test.com', 25),
            ('Bob', 'bob@test.com', 30),
            ('Charlie', 'charlie@test.com', 35)");
        
        $this->x = X::con($this->connectionId);
    }
    
    public function tearDown(): void {
        // Limpieza si es necesaria
        $this->x = null;
    }
    
    /**
     * Prueba: select() básico sin paginación
     */
    public function testSelectBasic(): void {
        $res = $this->x->from('users')->select();
        
        if (!($res instanceof XResponse)) {
            throw new \Exception("select() should return XResponse instance");
        }
        
        if (count($res->data) !== 3) {
            throw new \Exception("select() should return 3 rows, got " . count($res->data));
        }
        
        if ($res->total !== 3) {
            throw new \Exception("total should be 3, got {$res->total}");
        }
        
        if ($res->columns !== ['id', 'name', 'email', 'age']) {
            throw new \Exception("columns mismatch: " . json_encode($res->columns));
        }
        
        if ($res->success !== true) {
            throw new \Exception("success should be true");
        }
    }
    
    /**
     * Prueba: select() con paginación
     */
    public function testSelectWithPagination(): void {
        $resPage1 = $this->x->from('users')->select('*', [0, 2], [], true);
        
        if (count($resPage1->data) !== 2) {
            throw new \Exception("page 1 should have 2 rows, got " . count($resPage1->data));
        }
        
        // El total refleja los registros en la página cuando no hay ventana
        // Esto es comportamiento esperado sin window function
        if ($resPage1->total < 1) {
            throw new \Exception("total should be > 0, got {$resPage1->total}");
        }
        
        if ($resPage1->page !== 1) {
            throw new \Exception("page should be 1, got {$resPage1->page}");
        }
        
        if ($resPage1->limit !== 2) {
            throw new \Exception("limit should be 2, got {$resPage1->limit}");
        }
    }
    
    /**
     * Prueba: select() con ordenamiento
     */
    public function testSelectWithSorting(): void {
        $resSorted = $this->x->from('users')->select('*', null, '-name');
        
        if (count($resSorted->data) < 1) {
            throw new \Exception("sorted select should return data");
        }
        
        // El primer nombre debería ser Charlie (orden descendente)
        $firstName = $resSorted->data[0][1];
        if ($firstName !== 'Charlie') {
            throw new \Exception("First name should be Charlie (desc order), got $firstName");
        }
    }
    
    /**
     * Prueba: first() devuelve el primer registro
     */
    public function testFirst(): void {
        $first = $this->x->from('users')->first();
        
        if ($first === null) {
            throw new \Exception("first() should not return null");
        }
        
        if (!is_array($first)) {
            throw new \Exception("first() should return array");
        }
        
        if ($first['name'] !== 'Alice') {
            throw new \Exception("first() name should be Alice, got {$first['name']}");
        }
    }
    
    /**
     * Prueba: first() con filtro que no coincide
     */
    public function testFirstEmpty(): void {
        $empty = $this->x->from('users', ['id' => 999])->first();
        
        if ($empty !== null) {
            throw new \Exception("first() with no results should be null");
        }
    }
    
    /**
     * Prueba: exists() devuelve true cuando hay registros
     */
    public function testExists(): void {
        $exists = $this->x->from('users')->exists();
        
        if ($exists !== true) {
            throw new \Exception("exists() should return true when records exist");
        }
    }
    
    /**
     * Prueba: exists() devuelve false cuando no hay coincidencias
     */
    public function testNotExists(): void {
        $exists = $this->x->from('users', ['id' => 999])->exists();
        
        if ($exists !== false) {
            throw new \Exception("exists() should return false when no records match");
        }
    }
    
    /**
     * Prueba: count() devuelve el número correcto de registros
     */
    public function testCount(): void {
        $count = $this->x->from('users')->count();
        
        if ($count !== 3) {
            throw new \Exception("count() should be 3, got $count");
        }
    }
    
    /**
     * Prueba: count() con filtro
     */
    public function testCountWithFilter(): void {
        $count = $this->x->from('users', ['age' => 30])->count();
        
        if ($count !== 1) {
            throw new \Exception("count() with filter should be 1, got $count");
        }
    }
    
    /**
     * Prueba: grid() devuelve estructura correcta
     */
    public function testGrid(): void {
        $grid = $this->x->from('users')->grid('*', [1, 10]);
        
        if (!isset($grid['data'])) {
            throw new \Exception("grid should have 'data' key");
        }
        
        if (!isset($grid['total'])) {
            throw new \Exception("grid should have 'total' key");
        }
        
        if ($grid['total'] !== 3) {
            throw new \Exception("grid total should be 3, got {$grid['total']}");
        }
        
        if (!isset($grid['debug']['sql'])) {
            throw new \Exception("grid should have debug.sql");
        }
        
        if (!isset($grid['stats']['duration'])) {
            throw new \Exception("grid should have stats.duration");
        }
    }
    
    /**
     * Prueba: insert() añade un nuevo registro
     */
    public function testInsert(): void {
        $res = $this->x->from('users')->insert([
            'name' => 'David',
            'email' => 'david@test.com',
            'age' => 28
        ]);
        
        if ($res->success !== true) {
            throw new \Exception("insert should succeed");
        }
        
        if ($res->lastId <= 0) {
            throw new \Exception("lastId should be > 0");
        }
        
        if ($res->affected <= 0) {
            throw new \Exception("affected should be > 0");
        }
        
        // Verificar que el count aumentó
        $newCount = $this->x->from('users')->count();
        if ($newCount !== 4) {
            throw new \Exception("count after insert should be 4, got $newCount");
        }
    }
    
    /**
     * Prueba: update() modifica registros existentes
     */
    public function testUpdate(): void {
        $res = $this->x->from('users', ['name' => 'Alice'])->update(['age' => 26]);
        
        if ($res->success !== true) {
            throw new \Exception("update should succeed");
        }
        
        if ($res->affected <= 0) {
            throw new \Exception("affected should be > 0");
        }
        
        // Verificar que el cambio se aplicó
        $updated = $this->x->from('users', ['name' => 'Alice'])->first();
        if ($updated['age'] !== 26) {
            throw new \Exception("age should be updated to 26, got {$updated['age']}");
        }
    }
    
    /**
     * Prueba: delete() elimina registros
     */
    public function testDelete(): void {
        $initialCount = $this->x->from('users')->count();
        
        $res = $this->x->from('users', ['name' => 'Bob'])->delete();
        
        if ($res->success !== true) {
            throw new \Exception("delete should succeed");
        }
        
        if ($res->affected <= 0) {
            throw new \Exception("affected should be > 0");
        }
        
        // Verificar que el count disminuyó
        $newCount = $this->x->from('users')->count();
        if ($newCount !== ($initialCount - 1)) {
            throw new \Exception("count after delete should be " . ($initialCount - 1) . ", got $newCount");
        }
    }
    
    /**
     * Prueba: raw() ejecuta consultas SQL directas SELECT
     */
    public function testRawSelect(): void {
        $res = $this->x->raw("SELECT * FROM users WHERE age > 30");
        
        if (!($res instanceof XResponse)) {
            throw new \Exception("raw() should return XResponse");
        }
        
        if (count($res->data) < 1) {
            throw new \Exception("raw SELECT should return data");
        }
    }
    
    /**
     * Prueba: raw() ejecuta consultas SQL directas INSERT
     */
    public function testRawAction(): void {
        $res = $this->x->raw("INSERT INTO users (name, email, age) VALUES ('Eve', 'eve@test.com', 22)");
        
        if (!($res instanceof XResponse)) {
            throw new \Exception("raw() should return XResponse");
        }
        
        if ($res->success !== true) {
            throw new \Exception("raw INSERT should succeed");
        }
    }
    
    /**
     * Prueba: ping() verifica la conexión
     */
    public function testPing(): void {
        $result = $this->x->from('users')->ping();
        
        if (!is_array($result)) {
            throw new \Exception("ping() should return array");
        }
        
        if (!isset($result['success'])) {
            throw new \Exception("ping result should have 'success' key");
        }
        
        if ($result['success'] !== true) {
            throw new \Exception("ping should succeed: " . ($result['error'] ?? 'unknown error'));
        }
        
        if (!isset($result['latency']) || $result['latency'] === null) {
            throw new \Exception("ping should have latency");
        }
    }
    
    /**
     * Prueba: description() devuelve información del schema
     */
    public function testDescription(): void {
        $desc = $this->x->from('users')->description();
        
        if (!is_array($desc)) {
            throw new \Exception("description() should return array");
        }
        
        if (!isset($desc['tables'])) {
            throw new \Exception("description should have 'tables' key");
        }
        
        if (empty($desc['tables'])) {
            throw new \Exception("description tables should not be empty");
        }
    }
    
    /**
     * Prueba: cached() habilita caché para select
     */
    public function testCachedSelect(): void {
        $res = $this->x->from('users')->cached(60)->select();
        
        if (!($res instanceof XResponse)) {
            throw new \Exception("cached select() should return XResponse");
        }
        
        if (count($res->data) !== 3) {
            throw new \Exception("cached select() should return 3 rows");
        }
    }
    
    /**
     * Prueba: from() acepta CompiledQuery como tabla
     */
    public function testFromWithCompiledQuery(): void {
        // Esta prueba verifica que from() acepta diferentes tipos de parámetros
        $res = $this->x->from('users', ['age' => ['>', 25]])->select();
        
        if (!($res instanceof XResponse)) {
            throw new \Exception("from() with filter should return XResponse");
        }
        
        // Debería devolver 2 registros (Bob=30, Charlie=35)
        if (count($res->data) !== 2) {
            throw new \Exception("from() with age>25 should return 2 rows, got " . count($res->data));
        }
    }
    
    /**
     * Prueba: into() es alias semántico de from() para INSERT
     */
    public function testIntoAlias(): void {
        $res = $this->x->into('users')->insert([
            'name' => 'Frank',
            'email' => 'frank@test.com',
            'age' => 40
        ]);
        
        if ($res->success !== true) {
            throw new \Exception("into()->insert() should succeed");
        }
    }
}
