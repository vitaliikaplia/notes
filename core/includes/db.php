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

// ============================================================
// Boot gate — fail loudly, not silently
// ============================================================

/**
 * Ensure the database is reachable before the app renders anything.
 *
 * Without this, a missing config.php or a wrong/unreachable database makes
 * options_cache() swallow the error and return an empty set, so the app falls
 * through to the login form with no credentials to check against — confusing.
 * Instead we show a human-readable error page (à la WordPress' "Error
 * establishing a database connection"). Self-contained: no Twig, Redis or DB.
 */
function app_require_database(): void {
    try {
        get_db(); // opens the PDO connection; throws if it cannot
    } catch(\Throwable $e) {
        $config_missing = !is_file(ABSPATH . DS . 'config.php');
        write_log('[DB] connection failed (' . ($config_missing ? 'config.php missing' : 'config present') . '): ' . $e->getMessage());
        db_render_fatal_page($config_missing);
        exit;
    }
}

/** Render the database-unavailable page (HTML, or JSON for /api/ requests) and stop. */
function db_render_fatal_page(bool $config_missing): void {
    if(!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
    }

    $path   = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $is_api = ($path === 'api' || strpos($path, 'api/') === 0);

    if($is_api) {
        if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'database_unavailable']);
        return;
    }

    $title = $config_missing ? 'Configuration required' : 'Database connection error';
    $lead  = $config_missing
        ? "The application can’t find its database configuration."
        : "The site is temporarily unavailable because the database can’t be reached.";
    $hint  = $config_missing
        ? "config.php is missing from the project root. Create it with your database credentials, then reload."
        : "Check the credentials in config.php and that the database server is running.";

    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    if(!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$e($title)}</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: #f6f7f9; color: #1f2328; padding: 24px;
  }
  .card {
    width: 100%; max-width: 440px; background: #fff; border: 1px solid #e6e8eb;
    border-radius: 16px; padding: 32px; text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,.06);
  }
  .icon {
    width: 52px; height: 52px; margin: 0 auto 18px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    background: #fdecec; color: #d33;
  }
  h1 { margin: 0 0 10px; font-size: 19px; font-weight: 650; letter-spacing: -.01em; }
  p { margin: 0 0 8px; font-size: 14.5px; line-height: 1.55; color: #4a5159; }
  .hint { margin-top: 16px; padding: 12px 14px; border-radius: 10px; background: #f3f4f6;
          font-size: 13px; color: #5a6068; text-align: left; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
  @media (prefers-color-scheme: dark) {
    body { background: #16181c; color: #e6e8eb; }
    .card { background: #1f2227; border-color: #2c3036; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
    .icon { background: #3a1f22; color: #ff6b6b; }
    p { color: #aab1ba; }
    .hint { background: #2a2e34; color: #99a0a8; }
  }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
        <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"></path>
      </svg>
    </div>
    <h1>{$e($title)}</h1>
    <p>{$e($lead)}</p>
    <div class="hint">{$e($hint)}</div>
  </div>
</body>
</html>
HTML;
}
