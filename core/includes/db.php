<?php

if(!defined('ABSPATH')){exit;}

/**
 * PDO connection to the MySQL/MariaDB database (singleton).
 * Connection settings come from config.php via get_env().
 */
function get_db(): PDO {
    static $pdo = null;
    if($pdo !== null) return $pdo;

    $env     = get_env();
    $host    = $env['DB_HOST']    ?? '127.0.0.1';
    $port    = $env['DB_PORT']    ?? '3306';
    $name    = $env['DB_NAME']    ?? 'notes';
    $user    = $env['DB_USER']    ?? 'root';
    $pass    = $env['DB_PASS']    ?? '';
    $charset = $env['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Self-healing schema: create the tables on first connect if they don't exist.
    try { db_init_schema(); } catch(\Throwable $e) { /* tables exist or DDL not permitted */ }

    return $pdo;
}

/**
 * Create the schema if it does not exist yet.
 * Safe to call repeatedly (CREATE TABLE IF NOT EXISTS).
 */
function db_init_schema(): void {
    $db = get_db();

    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id      BIGINT UNSIGNED NULL,
        slug           VARCHAR(255) NOT NULL,
        path           VARCHAR(512) NOT NULL,
        title          VARCHAR(512) NOT NULL DEFAULT 'Untitled',
        icon           MEDIUMTEXT NULL,
        cover          VARCHAR(1024) NULL,
        cover_position SMALLINT NULL,
        color          VARCHAR(32) NULL,
        pinned         TINYINT(1) NOT NULL DEFAULT 0,
        visibility     ENUM('private','unlisted','public') NOT NULL DEFAULT 'private',
        content        LONGTEXT NOT NULL,
        graph_x        DOUBLE NULL,
        graph_y        DOUBLE NULL,
        sort_order     INT NOT NULL DEFAULT 0,
        created_at     DATETIME NOT NULL,
        updated_at     DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_path (path),
        UNIQUE KEY uniq_parent_slug (parent_id, slug),
        KEY idx_parent (parent_id),
        KEY idx_visibility (visibility),
        KEY idx_updated (updated_at),
        KEY idx_pinned (pinned),
        CONSTRAINT fk_note_parent FOREIGN KEY (parent_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS options (
        name  VARCHAR(191) NOT NULL,
        value LONGTEXT NULL,
        PRIMARY KEY (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        token_hash CHAR(64) NOT NULL,
        user       VARCHAR(255) NOT NULL,
        expires    INT UNSIGNED NOT NULL,
        PRIMARY KEY (token_hash),
        KEY idx_expires (expires)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ============================================================
// Options (settings) — replaces the old config.php feature keys
// ============================================================

/**
 * Request-level options cache. Pass $set to replace it, $reset to clear it.
 * Loaded once from the DB; never cached in Redis (Redis settings live here too).
 */
function options_cache(?array $set = null, bool $reset = false): array {
    static $cache = null;

    if($reset) { $cache = null; return []; }
    if($set !== null) { $cache = $set; return $cache; }

    if($cache === null) {
        $cache = [];
        try {
            foreach(get_db()->query("SELECT name, value FROM options") as $row) {
                $cache[$row['name']] = $row['value'];
            }
        } catch(\Throwable $e) {
            $cache = [];
        }
    }

    return $cache;
}

function get_all_options(): array {
    return options_cache();
}

function get_option(string $name, $default = null) {
    $opts = options_cache();
    return array_key_exists($name, $opts) ? $opts[$name] : $default;
}

function set_option(string $name, $value): bool {
    $ok = get_db()
        ->prepare("INSERT INTO options (name, value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([$name, (string)$value]);

    if($ok) {
        $opts = options_cache();
        $opts[$name] = (string)$value;
        options_cache($opts);
    }

    return $ok;
}

function delete_option(string $name): bool {
    $ok = get_db()->prepare("DELETE FROM options WHERE name = ?")->execute([$name]);
    if($ok) {
        $opts = options_cache();
        unset($opts[$name]);
        options_cache($opts);
    }
    return $ok;
}
