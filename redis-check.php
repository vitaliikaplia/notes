<?php
// Quick Redis diagnostic — delete after checking
$socket = '/home/kapliavv/.system/redis.sock';

try {
    $r = new Redis();
    $r->connect($socket);
    echo "Connected: " . $r->ping() . "\n";

    $keys = $r->keys('notes_*');
    echo "Cached keys (" . count($keys) . "):\n";
    foreach($keys as $key) {
        $ttl = $r->ttl($key);
        $size = strlen($r->get($key));
        echo "  {$key} — {$size} bytes, TTL: {$ttl}s\n";
    }
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
