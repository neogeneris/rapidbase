<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\Cache\Adapters\MemoryCacheAdapter;
use RapidBase\Core\Contracts\KeyValueInterface;

/**
 * Test Suite for Settings class
 * Pruebas reales que validan la funcionalidad de la clase Settings
 */
class SettingsTest extends TestCase
{
    private KeyValueInterface $cacheAdapter;
    private Settings $settings;

    public function setUp(): void
    {
        // Crear adaptador de caché en memoria para las pruebas
        $this->cacheAdapter = new MemoryCacheAdapter();
        
        // Crear instancia de Settings con el adaptador
        $this->settings = new Settings($this->cacheAdapter);
    }

    public function tearDown(): void
    {
        // Limpiar caché después de cada prueba
        $this->cacheAdapter->clear();
        unset($this->cacheAdapter);
        unset($this->settings);
    }

    public function testSetAndGet(): void
    {
        $this->env()->test('should set and get a simple setting', function($db) {
            $this->settings->set('app/name', 'MyApp');
            $result = $this->settings->get('app/name');
            $this->assertEquals('MyApp', $result);
        });
    }

    public function testGetWithDefaultValue(): void
    {
        $this->env()->test('should return default value when key does not exist', function($db) {
            $result = $this->settings->get('nonexistent/key', 'default_value');
            $this->assertEquals('default_value', $result);
        });
    }

    public function testHas(): void
    {
        $this->env()->test('should check if a setting exists', function($db) {
            $this->settings->set('database/host', 'localhost');
            
            $this->assertTrue($this->settings->has('database/host'));
            $this->assertFalse($this->settings->has('database/port'));
        });
    }

    public function testDelete(): void
    {
        $this->env()->test('should delete a setting', function($db) {
            $this->settings->set('temp/value', 'to_be_deleted');
            $this->assertTrue($this->settings->has('temp/value'));
            
            $this->settings->delete('temp/value');
            $this->assertFalse($this->settings->has('temp/value'));
            $this->assertNull($this->settings->get('temp/value'));
        });
    }

    public function testAll(): void
    {
        $this->env()->test('should return all settings with prefix', function($db) {
            $this->settings->set('app/name', 'MyApp');
            $this->settings->set('app/version', '1.0.0');
            $this->settings->set('app/debug', true);
            $this->settings->set('other/setting', 'value');
            
            $all = $this->settings->all();
            
            $this->assertCount(3, $all);
            $this->assertEquals('MyApp', $all['app/name']);
            $this->assertEquals('1.0.0', $all['app/version']);
            $this->assertTrue($all['app/debug']);
        });
    }

    public function testClear(): void
    {
        $this->env()->test('should clear all settings', function($db) {
            $this->settings->set('app/name', 'MyApp');
            $this->settings->set('app/version', '1.0.0');
            
            $this->settings->clear();
            
            $all = $this->settings->all();
            $this->assertCount(0, $all);
        });
    }

    public function testSeparatorNormalization(): void
    {
        $this->env()->test('should normalize backslash and dot separators to slash', function($db) {
            // Probar con backslash
            $this->settings->set('app\\name', 'BackslashApp');
            $this->assertEquals('BackslashApp', $this->settings->get('app/name'));
            
            // Probar con punto
            $this->settings->set('app.version', '2.0.0');
            $this->assertEquals('2.0.0', $this->settings->get('app/version'));
            
            // Probar combinación
            $this->settings->set('db\\connection.host', 'localhost');
            $this->assertEquals('localhost', $this->settings->get('db/connection/host'));
        });
    }

    public function testHierarchicalKeys(): void
    {
        $this->env()->test('should handle hierarchical keys correctly', function($db) {
            $this->settings->set('database/mysql/host', 'mysql.local');
            $this->settings->set('database/mysql/port', 3306);
            $this->settings->set('database/postgres/host', 'postgres.local');
            $this->settings->set('database/postgres/port', 5432);
            
            $this->assertEquals('mysql.local', $this->settings->get('database/mysql/host'));
            $this->assertEquals(3306, $this->settings->get('database/mysql/port'));
            $this->assertEquals('postgres.local', $this->settings->get('database/postgres/host'));
            $this->assertEquals(5432, $this->settings->get('database/postgres/port'));
        });
    }

    public function testDifferentDataTypes(): void
    {
        $this->env()->test('should store and retrieve different data types', function($db) {
            $this->settings->set('types/string', 'hello');
            $this->settings->set('types/integer', 42);
            $this->settings->set('types/float', 3.14);
            $this->settings->set('types/boolean_true', true);
            $this->settings->set('types/boolean_false', false);
            $this->settings->set('types/null', null);
            $this->settings->set('types/array', ['key' => 'value']);
            $this->settings->set('types/object', (object)['prop' => 'value']);
            
            $this->assertEquals('hello', $this->settings->get('types/string'));
            $this->assertEquals(42, $this->settings->get('types/integer'));
            $this->assertEquals(3.14, $this->settings->get('types/float'));
            $this->assertTrue($this->settings->get('types/boolean_true'));
            $this->assertFalse($this->settings->get('types/boolean_false'));
            $this->assertNull($this->settings->get('types/null'));
            $this->assertEquals(['key' => 'value'], $this->settings->get('types/array'));
            $this->assertEquals((object)['prop' => 'value'], $this->settings->get('types/object'));
        });
    }

    public function testSetWithTtl(): void
    {
        $this->env()->test('should support TTL for settings', function($db) {
            $this->settings->set('temp/expiring', 'temporary_value', 1); // 1 segundo
            
            $this->assertEquals('temporary_value', $this->settings->get('temp/expiring'));
            
            // Esperar a que expire
            sleep(2);
            
            // Debería haber expirado o retornado null/default
            $result = $this->settings->get('temp/expiring', 'expired');
            $this->assertEquals('expired', $result);
        });
    }

    public function testPrefixIsolation(): void
    {
        $this->env()->test('should isolate settings by prefix', function($db) {
            $settings1 = new Settings(new MemoryCacheAdapter(), 'prefix1/');
            $settings2 = new Settings(new MemoryCacheAdapter(), 'prefix2/');
            
            $settings1->set('key', 'value1');
            $settings2->set('key', 'value2');
            
            $this->assertEquals('value1', $settings1->get('key'));
            $this->assertEquals('value2', $settings2->get('key'));
            
            // Verificar que all() solo retorna los del prefijo correcto
            $this->assertCount(1, $settings1->all());
            $this->assertCount(1, $settings2->all());
        });
    }

    public function testOverwrite(): void
    {
        $this->env()->test('should overwrite existing settings', function($db) {
            $this->settings->set('app/name', 'FirstApp');
            $this->assertEquals('FirstApp', $this->settings->get('app/name'));
            
            $this->settings->set('app/name', 'SecondApp');
            $this->assertEquals('SecondApp', $this->settings->get('app/name'));
        });
    }
}
