<?php

namespace RapidBase\Core;

/**
 * Clase Conn - Administra un pool de conexiones PDO.
 */
class Conn {
    private static array $pool = [];
    private static string $default = 'main';
	private static array $dbNames = [];

    public static function setup(string $dsn, string $user, string $pass, string $name = 'main'): void {
        
		if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
			self::$dbNames[$name] = $matches[1];
		} elseif (strpos($dsn, 'sqlite:') === 0) {
			self::$dbNames[$name] = basename(substr($dsn, 7));
		}
		
		try {
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT => true
            ]);
            self::$pool[$name] = $pdo;
            if ($name === 'main' || count(self::$pool) === 1) {
                self::$default = $name;
                
            }
        } catch (\PDOException $e) {
            die("Error de Setup DB [$name]: " . $e->getMessage());
        }
    }

    public static function select(string $name): void {
        if (isset(self::$pool[$name])) {
            
            self::$default = $name;
        } else {
            throw new InvalidArgumentException("Connection '$name' not found in pool.");
        }
    }
	
	public static function getDatabaseName(string $name = 'main'): string {
		return self::$dbNames[$name] ?? '';
	}
    
    public static function get(string $name = null): \PDO {
        if ($name === null) return self::$pool[self::$default];
        return self::$pool[$name] ?? self::$pool[self::$default];
    }

    public static function has(string $name): bool {
        return isset(self::$pool[$name]);
    }

    /**
     * Close a specific connection or all connections
     */
    public static function close(string $name = null): void {
        if ($name === null) {
            // Close all connections
            self::$pool = [];
            self::$dbNames = [];
            self::$default = 'main';
        } else {
            // Close specific connection
            unset(self::$pool[$name]);
            unset(self::$dbNames[$name]);
            if (self::$default === $name) {
                self::$default = !empty(self::$pool) ? array_key_first(self::$pool) : 'main';
            }
        }
    }
}

