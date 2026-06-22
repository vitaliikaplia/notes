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

    $socket = get_option('REDIS_SOCKET', '');
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

// --- Assets version + cache reset ---

/**
 * Increment the assets version (e.g. 1.00 -> 1.01). Static JS/CSS are tagged with
 * this value (?v=...), so bumping it busts browser caches for everyone.
 */
function bump_assets_version(): string {
    $current = str_replace(',', '.', (string)(get_option('assets_version', '1')));
    $cents = is_numeric($current) ? (int)round(((float)$current) * 100) : 100;
    $next = number_format(($cents + 1) / 100, 2, '.', '');
    set_option('assets_version', $next);
    return $next;
}

/** Clear server-side caches: Redis (notes_* keys), the Twig disk cache, and OPcache. */
function clear_app_cache(): array {
    $result = ['redis' => false, 'redis_keys' => 0, 'twig_disk' => 0, 'opcache' => false];

    $r = get_redis();
    if($r) {
        $keys = $r->keys(CACHE_PREFIX . '*');
        $result['redis_keys'] = is_array($keys) ? count($keys) : 0;
        if(!empty($keys)) {
            $r->del($keys);
        }
        $result['redis'] = true;
    }

    $twig_dir = ABSPATH . DS . '.twig-cache';
    if(is_dir($twig_dir)) {
        $result['twig_disk'] = cache_clear_directory($twig_dir);
    }

    if(function_exists('opcache_reset')) {
        $result['opcache'] = (bool)@opcache_reset();
    }

    return $result;
}

/** Recursively delete the contents of a directory; returns the number of files removed. */
function cache_clear_directory(string $dir): int {
    $deleted = 0;
    foreach(scandir($dir) as $item) {
        if($item === '.' || $item === '..') continue;
        $path = $dir . DS . $item;
        if(is_dir($path)) {
            $deleted += cache_clear_directory($path);
            @rmdir($path);
        } elseif(@unlink($path)) {
            $deleted++;
        }
    }
    return $deleted;
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
