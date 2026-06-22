# AGENTS.md

## Project

Self-hosted notes on PHP 8.5 / Twig 3 / Editor.js. No framework and no ORM; notes and settings live in a MySQL/MariaDB database (utf8mb4), accessed with plain PDO.

The product UI is English. Notes may contain any language. Ukrainian transliteration remains part of slug generation.

## Architecture

- Entry point: `index.php` -> `core/init.php` -> includes -> router
- Procedural PHP; storage is MySQL/MariaDB via plain PDO (`get_db()` in `core/includes/db.php`)
- Functions use `snake_case`
- Constants use `UPPER_CASE`
- Twig 3 templates live in `views/*.twig`
- Vanilla JavaScript
- CSS uses custom properties

## Key Files

```text
core/includes/router.php   Routing and all internal routes
core/includes/db.php       PDO connection, schema (db_init_schema), options + remember-me tokens
core/includes/notes.php    Note CRUD (MySQL), tree build, slug generation, image uploads
core/includes/auth.php     Sessions, login, remember-me tokens, config loader (get_env)
core/includes/ai.php       AI module for Claude/OpenAI/Gemini tool calling
core/includes/api.php      REST API v1
core/includes/render.php   Twig rendering and shared context
core/includes/markdown.php Markdown <-> Editor.js conversion
core/includes/cache.php    Redis cache with graceful fallback
views/index.twig           Dashboard tabs and page-level JS
views/editor.twig          Note editor
views/overall/base.twig    Base layout and sidebar shell
assets/css/style.css       Styles
assets/js/app.js           Editor and dashboard client logic
assets/js/sidebar.js       Sidebar interactions
assets/js/page-tool.js     Editor.js page-link tool
```

## Configuration

- `config.php` (gitignored, returns an array, read via `get_env()`) holds ONLY the DB connection (`DB_HOST/PORT/NAME/USER/PASS/CHARSET`); copy `config.example.php` -> `config.php` to set up
- Every other setting — admin login (`AUTH_USER/AUTH_PASS`), API token, CAPTCHA, AI provider/key, Redis socket — lives in the DB `options` table; read/write with `get_option()` / `set_option()` from `core/includes/db.php`, never `get_env()`
- `AUTH_PASS` is a bcrypt hash (`password_hash`/`password_verify`); set it via `auth_set_password($plain)` in `auth.php`. A plaintext value is accepted once and auto-upgraded to a hash on first login
- Do not use `getenv()` or `$_ENV`; `config.php` is served as PHP so it never leaks as plaintext
- Important constants: `ABSPATH`, `HOME_URL`, `SITE_NAME`
- Timezone: `Europe/Kyiv`

## Conventions

- Notes live in the `notes` table as an adjacency list (`parent_id` + a unique `path`); `path` (e.g. `parent/child`, no `.json`) is the URL identifier and the stable key used across the app
- The Editor.js document is stored in the `content` column (JSON); meta fields (title, icon, cover, color, pinned, visibility, graph_x/y) are columns; SVG/emoji icons are stored inline in `icon`
- Settings live in the `options` table (key/value); remember-me tokens live in `remember_tokens` (sha256 token hash + user + expiry)
- The schema self-creates on the first DB connect (`db_init_schema()` is called from `get_db()`)
- Slugs use Ukrainian transliteration through `ukr_to_lat()`
- Upload/image URLs are stored host-relative (`/file/...`) via `normalize_upload_url()` / `upload_url_to_relative_path()`; never bake `HOME_URL` into stored note data, so notes stay portable across hosts
- Each PHP include starts with `if(!defined('ABSPATH')){exit;}`
- Light/dark themes use custom properties such as `var(--bg)` and `var(--text)`
- JavaScript is IIFE-style, without modules or a bundler
- Editor.js and plugins are loaded from CDN

## Commands

- Requires PHP 8.5 — pinned in `composer.json` (`require.php` = `8.5.*` and `config.platform.php` = `8.5`)
- Dependencies are committed in `vendor/` (no `composer install` needed); update them with `herd composer update`
- On Laravel Herd, isolate the site to 8.5: `herd isolate 8.5`
- Web server: Apache, Nginx, or Laravel Herd pointing to `index.php`
- Needs a MySQL/MariaDB database (utf8mb4); the schema self-creates on first run
- Required PHP extension for image conversion: Imagick

## Notes For Agents

- No ORM or framework: use plain PDO via `get_db()`; keep the schema in `db_init_schema()` (`core/includes/db.php`).
- Preserve the note shape returned by `get_note()` / `db_row_to_note()` (`meta` + Editor.js `content` + `_file`/`_slug`/`_url`/`_title`) so router/api/ai/templates keep working.
- Clear note/tree cache after note mutations (`cache_delete('note:'.$path)`, `cache_delete('tree')`).
- Notes live in the DB, not the filesystem — there is no `.notes/` store anymore.
- Keep public UI text in English.
- Do not remove the Ukrainian transliteration table in `core/includes/notes.php`; it is part of slug compatibility.
- Store upload/image URLs host-relative (`/file/...`); `get_note()` normalizes any absolute host on read. Do not reintroduce `HOME_URL`-prefixed image URLs.
- `.htaccess` is honored on Apache but ignored on nginx/Herd; never rely on it to protect `config.php`, `.notes/`, or `uploads/`. The durable protection is moving the docroot to a `public/` dir.
