<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * SQLiteMemoryCacheAdapter - In-Memory SQLite Table Cache Adapter
 * 
 * Uses SQLite :memory: database for caching with SQL query capabilities.
 * Good for complex cache invalidation patterns and structured data.
 */
class SQLiteMemoryCacheAdapter
{
    /**
     * @var \PDO SQLite in-memory database connection
     */
    private \PDO $db;
    
    /**
     * @var string Key prefix
     */
    private string $prefix;

    public function __construct(array $config = [])
    {
        // Create in-memory SQLite database
        $this->db = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
        
        // Create cache table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");
        
        // Create index for expiration cleanup
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_expires ON cache(expires)");
        
        $this->prefix = $config['prefix'] ?? 'rb_sqlite_';
    }

    /**
     * Retrieve value from SQLite memory
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        $stmt = $this->db->prepare("SELECT value FROM cache WHERE key = ? AND (expires = 0 OR expires > ?)");
        $stmt->execute([$fullKey, time()]);
        $result = $stmt->fetchColumn();
        
        if ($result === false) {
            return null;
        }
        
        return unserialize($result);
    }

    /**
     * Store value in SQLite memory with optional TTL
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $fullKey = $this->prefix . $key;
        $serialized = serialize($value);
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO cache (key, value, expires)
            VALUES (?, ?, ?)
        ");
        
        return $stmt->execute([$fullKey, $serialized, $expires]);
    }

    /**
     * Check if key exists in SQLite memory
     */
    public function has(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM cache 
            WHERE key = ? AND (expires = 0 OR expires > ?)
        ");
        $stmt->execute([$fullKey, time()]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Delete key from SQLite memory
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        $stmt = $this->db->prepare("DELETE FROM cache WHERE key = ?");
        return $stmt->execute([$fullKey]);
    }

    /**
     * Clear all keys with current prefix
     */
    public function flush(): bool
    {
        $stmt = $this->db->prepare("DELETE FROM cache WHERE key LIKE ?");
        return $stmt->execute([$this->prefix . '%']);
    }

    /**
     * Get multiple keys from SQLite memory
     */
    public function getMultiple(array $keys): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        
        $stmt = $this->db->prepare("
            SELECT key, value FROM cache 
            WHERE key IN ($placeholders) AND (expires = 0 OR expires > ?)
        ");
        
        $stmt->execute([...$fullKeys, time()]);
        $results = $stmt->fetchAll();
        
        $return = [];
        foreach ($keys as $key) {
            $fullKey = $this->prefix . $key;
            foreach ($results as $row) {
                if ($row['key'] === $fullKey) {
                    $return[$key] = unserialize($row['value']);
                    break;
                }
            }
            if (!isset($return[$key])) {
                $return[$key] = null;
            }
        }
        
        return $return;
    }

    /**
     * Set multiple keys in SQLite memory
     */
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO cache (key, value, expires)
                VALUES (?, ?, ?)
            ");
            
            foreach ($values as $key => $value) {
                $stmt->execute([$this->prefix . $key, serialize($value), $expires]);
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Delete multiple keys from SQLite memory
     */
    public function deleteMultiple(array $keys): bool
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        
        $stmt = $this->db->prepare("DELETE FROM cache WHERE key IN ($placeholders)");
        return $stmt->execute($fullKeys);
    }

    /**
     * Get SQLite memory stats
     */
    public function getStats(): array
    {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN expires > 0 THEN 1 ELSE 0 END) as expirable_items,
                SUM(length(value)) as total_size_bytes
            FROM cache 
            WHERE key LIKE '{$this->prefix}%'
        ");
        $stats = $stmt->fetch();
        
        return [
            'adapter' => 'SQLiteMemoryCacheAdapter',
            'items' => (int)$stats['total_items'],
            'expirable_items' => (int)$stats['expirable_items'],
            'total_size_bytes' => (int)$stats['total_size_bytes'],
            'type' => 'volatile_memory_sql'
        ];
    }

    /**
     * Clean expired entries
     */
    public function cleanExpired(): int
    {
        $stmt = $this->db->prepare("DELETE FROM cache WHERE expires > 0 AND expires < ?");
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    /**
     * Search cache by pattern (SQL LIKE)
     */
    public function search(string $pattern): array
    {
        $stmt = $this->db->prepare("
            SELECT key, value, created_at, expires 
            FROM cache 
            WHERE key LIKE ? AND (expires = 0 OR expires > ?)
        ");
        $stmt->execute([$this->prefix . $pattern, time()]);
        
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $results[str_replace($this->prefix, '', $row['key'])] = [
                'value' => unserialize($row['value']),
                'created_at' => $row['created_at'],
                'expires' => $row['expires']
            ];
        }
        
        return $results;
    }
}
