<?php

if(!defined('ABSPATH')){exit;}

define('CACHE_PREFIX', 'notes_');
define('CACHE_TTL', 3600); // 1 hour

/**
 * Get Redis connection (singleton)
 * Returns null if Redis is not configured or unavailable
 */
function get_redis(): ?Redis {
    static $redis = null;
    static $tried = false;

    if($tried) return $redis;
    $tried = true;

    $env = get_env();
    $socket = $env['REDIS_SOCKET'] ?? '';
    if(empty($socket)) return null;

    // Parse unix:///path/to/socket → /path/to/socket
    $path = preg_replace('#^unix://#', '', $socket);

    try {
        $redis = new Redis();
        $redis->connect($path);
        $redis->ping();
    } catch(Exception $e) {
        $redis = null;
    }

    return $redis;
}

// --- General-purpose cache ---

function cache_get(string $key): mixed {
    $r = get_redis();
    if(!$r) return null;

    $data = $r->get(CACHE_PREFIX . $key);
    if($data === false) return null;

    return json_decode($data, true);
}

function cache_set(string $key, mixed $data, int $ttl = CACHE_TTL): bool {
    $r = get_redis();
    if(!$r) return false;

    return $r->setex(CACHE_PREFIX . $key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function cache_delete(string $key): bool {
    $r = get_redis();
    if(!$r) return false;

    return $r->del(CACHE_PREFIX . $key) > 0;
}

function cache_flush(): bool {
    $r = get_redis();
    if(!$r) return false;

    $keys = $r->keys(CACHE_PREFIX . '*');
    if(!empty($keys)) {
        $r->del($keys);
    }
    return true;
}

// --- Twig Redis Cache ---

class RedisTwigCache implements \Twig\Cache\CacheInterface
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
        $this->prefix = CACHE_PREFIX . 'twig:';
    }

    public function generateKey(string $name, string $className): string
    {
        return $this->prefix . $name . ':' . $className;
    }

    public function write(string $key, string $content): void
    {
        $this->redis->set($key, $content);
    }

    public function load(string $key): void
    {
        $script = $this->redis->get($key);
        if($script) {
            eval('?>' . $script);
        }
    }

    public function getTimestamp(string $key): int
    {
        // Return 0 so auto_reload always checks source file mtime
        return 0;
    }
}
