<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\Cache\Adapters\MemoryCacheAdapter;
use RapidBase\Core\Contracts\KeyValueInterface;


// Bootstrap centralizado para pruebas unitarias
require_once __DIR__ . '/../bootstrap.php';
/**
 * Test Suite for Translator class
 * Pruebas reales que validan la funcionalidad de la clase Translator
 */
class TranslatorTest extends TestCase
{
    private KeyValueInterface $cacheAdapter;
    private Translator $translator;

    public function setUp(): void
    {
        // Crear adaptador de caché en memoria para las pruebas
        $this->cacheAdapter = new MemoryCacheAdapter();
        
        // Crear instancia de Translator con el adaptador
        $this->translator = new Translator($this->cacheAdapter);
    }

    public function tearDown(): void
    {
        // Limpiar caché después de cada prueba
        $this->cacheAdapter->clear();
        unset($this->cacheAdapter);
        unset($this->translator);
    }

    public function testSetAndGet(): void
    {
        $this->env()->test('should set and get a simple translation', function($db) {
            $this->translator->set('en/messages/welcome', 'Welcome');
            $result = $this->translator->get('en/messages/welcome');
            $this->assertEquals('Welcome', $result);
        });
    }

    public function testTransAlias(): void
    {
        $this->env()->test('should use trans() as alias of get()', function($db) {
            $this->translator->set('es/messages/hello', 'Hola');
            $result = $this->translator->trans('es/messages/hello');
            $this->assertEquals('Hola', $result);
        });
    }

    public function testGetWithDefaultValue(): void
    {
        $this->env()->test('should return default value when key does not exist', function($db) {
            $result = $this->translator->get('nonexistent/key', 'Default Text');
            $this->assertEquals('Default Text', $result);
        });
    }

    public function testHas(): void
    {
        $this->env()->test('should check if a translation exists', function($db) {
            $this->translator->set('en/errors/not_found', 'Not Found');
            
            $this->assertTrue($this->translator->has('en/errors/not_found'));
            $this->assertFalse($this->translator->has('en/errors/server_error'));
        });
    }

    public function testDelete(): void
    {
        $this->env()->test('should delete a translation', function($db) {
            $this->translator->set('temp/message', 'To be deleted');
            $this->assertTrue($this->translator->has('temp/message'));
            
            $this->translator->delete('temp/message');
            $this->assertFalse($this->translator->has('temp/message'));
            $this->assertNull($this->translator->get('temp/message'));
        });
    }

    public function testAll(): void
    {
        $this->env()->test('should return all translations with prefix', function($db) {
            $this->translator->set('en/messages/welcome', 'Welcome');
            $this->translator->set('en/messages/goodbye', 'Goodbye');
            $this->translator->set('en/errors/not_found', 'Not Found');
            $this->translator->set('es/messages/welcome', 'Bienvenido');
            
            $all = $this->translator->all();
            
            $this->assertCount(4, $all);
            $this->assertEquals('Welcome', $all['en/messages/welcome']);
            $this->assertEquals('Goodbye', $all['en/messages/goodbye']);
            $this->assertEquals('Bienvenido', $all['es/messages/welcome']);
        });
    }

    public function testClear(): void
    {
        $this->env()->test('should clear all translations', function($db) {
            $this->translator->set('en/messages/welcome', 'Welcome');
            $this->translator->set('en/messages/goodbye', 'Goodbye');
            
            $this->translator->clear();
            
            $all = $this->translator->all();
            $this->assertCount(0, $all);
        });
    }

    public function testSeparatorNormalization(): void
    {
        $this->env()->test('should normalize backslash and dot separators to slash', function($db) {
            // Probar con backslash
            $this->translator->set('en\\messages\\hello', 'Hello Backslash');
            $this->assertEquals('Hello Backslash', $this->translator->get('en/messages/hello'));
            
            // Probar con punto
            $this->translator->set('en.messages.goodbye', 'Goodbye Dot');
            $this->assertEquals('Goodbye Dot', $this->translator->get('en/messages/goodbye'));
            
            // Probar combinación
            $this->translator->set('es\\errors.not_found', 'No Encontrado');
            $this->assertEquals('No Encontrado', $this->translator->get('es/errors/not_found'));
        });
    }

    public function testHierarchicalKeys(): void
    {
        $this->env()->test('should handle hierarchical keys correctly', function($db) {
            $this->translator->set('en/forms/login/title', 'Login');
            $this->translator->set('en/forms/login/username', 'Username');
            $this->translator->set('en/forms/login/password', 'Password');
            $this->translator->set('en/forms/register/title', 'Register');
            
            $this->assertEquals('Login', $this->translator->get('en/forms/login/title'));
            $this->assertEquals('Username', $this->translator->get('en/forms/login/username'));
            $this->assertEquals('Password', $this->translator->get('en/forms/login/password'));
            $this->assertEquals('Register', $this->translator->get('en/forms/register/title'));
        });
    }

    public function testLocaleManagement(): void
    {
        $this->env()->test('should manage locale correctly', function($db) {
            // Verificar locale por defecto
            $this->assertEquals('en', $this->translator->getLocale());
            $this->assertEquals('en', $this->translator->getDefaultLocale());
            
            // Cambiar locale
            $this->translator->setLocale('es');
            $this->assertEquals('es', $this->translator->getLocale());
            
            // Cambiar a otro locale
            $this->translator->setLocale('fr');
            $this->assertEquals('fr', $this->translator->getLocale());
            
            // Resetear a default
            $this->translator->setLocale();
            $this->assertEquals('en', $this->translator->getLocale());
        });
    }

    public function testTranslationWithFallback(): void
    {
        $this->env()->test('should fallback to default locale when translation not found', function($db) {
            // Establecer traducción solo en inglés
            $this->translator->set('en/messages/welcome', 'Welcome');
            
            // Cambiar a español
            $this->translator->setLocale('es');
            
            // Debería hacer fallback al inglés
            $result = $this->translator->get('messages/welcome');
            $this->assertEquals('Welcome', $result);
        });
    }

    public function testParameterInterpolation(): void
    {
        $this->env()->test('should interpolate parameters in translations', function($db) {
            $this->translator->set('en/messages/greeting', 'Hello, {name}!');
            
            $result = $this->translator->get('en/messages/greeting', null, ['name' => 'John']);
            $this->assertEquals('Hello, John!', $result);
            
            // Múltiples parámetros
            $this->translator->set('en/messages/info', 'User {user} has {count} messages.');
            $result = $this->translator->get('en/messages/info', null, ['user' => 'Alice', 'count' => 5]);
            $this->assertEquals('User Alice has 5 messages.', $result);
        });
    }

    public function testTransWithParameters(): void
    {
        $this->env()->test('should support parameters in trans() method', function($db) {
            $this->translator->set('es/messages/welcome', 'Bienvenido, {name}!');
            
            $result = $this->translator->trans('es/messages/welcome', null, ['name' => 'María']);
            $this->assertEquals('Bienvenido, María!', $result);
        });
    }

    public function testDifferentDataTypes(): void
    {
        $this->env()->test('should store and retrieve different data types', function($db) {
            $this->translator->set('types/string', 'hello');
            $this->translator->set('types/integer', 42);
            $this->translator->set('types/float', 3.14);
            $this->translator->set('types/boolean_true', true);
            $this->translator->set('types/boolean_false', false);
            $this->translator->set('types/array', ['key' => 'value']);
            
            $this->assertEquals('hello', $this->translator->get('types/string'));
            $this->assertEquals(42, $this->translator->get('types/integer'));
            $this->assertEquals(3.14, $this->translator->get('types/float'));
            $this->assertTrue($this->translator->get('types/boolean_true'));
            $this->assertFalse($this->translator->get('types/boolean_false'));
            $this->assertEquals(['key' => 'value'], $this->translator->get('types/array'));
        });
    }

    public function testPrefixIsolation(): void
    {
        $this->env()->test('should isolate translations by prefix', function($db) {
            $translator1 = new Translator(new MemoryCacheAdapter(), 'translations1/');
            $translator2 = new Translator(new MemoryCacheAdapter(), 'translations2/');
            
            $translator1->set('en/hello', 'Hello 1');
            $translator2->set('en/hello', 'Hello 2');
            
            $this->assertEquals('Hello 1', $translator1->get('en/hello'));
            $this->assertEquals('Hello 2', $translator2->get('en/hello'));
            
            // Verificar que all() solo retorna los del prefijo correcto
            $this->assertCount(1, $translator1->all());
            $this->assertCount(1, $translator2->all());
        });
    }

    public function testOverwrite(): void
    {
        $this->env()->test('should overwrite existing translations', function($db) {
            $this->translator->set('en/messages/welcome', 'First Welcome');
            $this->assertEquals('First Welcome', $this->translator->get('en/messages/welcome'));
            
            $this->translator->set('en/messages/welcome', 'Second Welcome');
            $this->assertEquals('Second Welcome', $this->translator->get('en/messages/welcome'));
        });
    }

    public function testComplexKeyStructure(): void
    {
        $this->env()->test('should handle complex key structures', function($db) {
            $this->translator->set('en/validation/email/required', 'Email is required');
            $this->translator->set('en/validation/email/invalid', 'Invalid email format');
            $this->translator->set('en/validation/password/min_length', 'Password must be at least {min} characters');
            $this->translator->set('en/validation/password/requirements', 'Password must contain uppercase, lowercase and numbers');
            
            $this->assertEquals('Email is required', $this->translator->get('en/validation/email/required'));
            $this->assertEquals('Invalid email format', $this->translator->get('en/validation/email/invalid'));
            
            $result = $this->translator->get('en/validation/password/min_length', null, ['min' => 8]);
            $this->assertEquals('Password must be at least 8 characters', $result);
        });
    }
}
