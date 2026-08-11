# Notes

Self-hosted notes with full control over your data: no framework, no ORM, no subscriptions. The app is built with PHP, Twig, Editor.js, and a MySQL/MariaDB database, with a built-in AI assistant that can search, read, create, and update notes from chat.

The interface is in English. Notes can still use any language, and slugs keep Ukrainian transliteration support through `ukr_to_lat()`.

![Home grid view, sidebar, dark theme](screenshots/Screenshot%202026-03-01%20at%2015.03.08.png)

## Features

### Editor

- Block editor powered by Editor.js: headings, paragraphs, lists, checklists, code, quotes, tables, delimiters, links, alerts, toggles, images, embeds, and page links
- Syntax-highlighted code blocks with a searchable language dropdown
- Inline tools: bold, italic, underline, strikethrough, marker, inline code, and links
- Cover images with vertical repositioning
- Note background colors with automatic contrast handling
- Pinning notes, shown as a separate dashboard group
- Drag-and-drop block ordering
- Drag notes from the sidebar into the editor to create page-link blocks
- Undo/redo
- Autosave
- Child-note sync popup for adding missing page links and removing broken child links
- Export to Markdown
- Drag-and-drop Markdown import

### Media

- Upload images, paste from clipboard, drag and drop, or fetch from URL
- Raster images are converted to WebP with Imagick
- Images are resized so the shorter side is at most 1024px
- SVG note icons are sanitized, minified, and stored inline as Base64 data URIs
- Image files are cleaned up when removed from notes or covers
- Image references are stored as host-relative URLs (`/file/...`), so notes stay portable across hosts (local, production, backups) and never break when moved
- YouTube and Vimeo embeds are detected from standalone URLs

### Navigation

- Nested notes through a parent/child hierarchy
- Child-note tiles rendered below a note for quick access to its sub-pages
- Tree sidebar with drag-and-drop sorting and cross-level moves
- Breadcrumbs
- Emoji, SVG, and website favicon note icons
- Quick search by title and content with `Ctrl+K`, with matching terms highlighted in the results
- Private, unlisted, and public note visibility

### Dashboard

- Masonry-style note cards
- Pinned notes section
- Infinite scroll
- Grid/list views with sorting and parent-group filtering
- Image gallery
- Timeline with date-range filtering
- Interactive graph view powered by force-graph / d3-force
- AI chat tab when AI is configured
- Options popup for admin login/password, Redis, REST API token, Cloudflare Turnstile, and AI settings
- Clear-cache control that flushes app caches and bumps the browser asset version

### Public View

- Read-only rendering for unlisted and public notes
- Rendered blocks include headings, lists, checklists, code, quotes, tables, delimiters, images, alerts, embeds, and page links
- Cover images and note colors are preserved
- Schema.org Article metadata

### Security

- Login/password authentication; the admin password is stored as a bcrypt hash
- Remember-me tokens stored hashed in the database
- Optional Cloudflare Turnstile CAPTCHA
- Uploaded files are served through `/file/` and are only available to authenticated users or when referenced from a public/unlisted note
- REST API v1 uses bearer-token authentication
- The DB connection lives in `config.php` (served as PHP, never exposed as plaintext); all other settings and secrets live in the DB `options` table
- Login and setup/database error pages are marked `noindex`

## AI Assistant

The dashboard includes an AI chat tab when `AI_PROVIDER` and `AI_API_KEY` are configured. Supported providers:

- Claude
- OpenAI
- Gemini

The assistant can use these tools:

- `notes_list` - list all notes
- `notes_search` - search notes by text
- `notes_get` - read note content as Markdown
- `notes_create` - create a note
- `notes_update` - update a note
- `notes_delete` - delete a note

The tool loop supports up to 5 tool iterations per request. The assistant is instructed to answer in English, preserve note formatting during updates, ask before deleting, and include links to notes it mentions or changes.

![AI assistant chat](screenshots/Screenshot%202026-03-01%20at%2015.01.51.png)

## Stack

- Backend: PHP 8.5 (Composer platform pinned to 8.5)
- Templates: Twig 3
- Editor: Editor.js from CDN with plugins
- Storage: MySQL/MariaDB (utf8mb4) via plain PDO — `notes`, `options`, and `remember_tokens` tables
- Frontend: Vanilla JavaScript and CSS custom properties
- Optional cache: Redis through a Unix socket
- Image processing: Imagick

## Project Structure

```text
core/           Core includes: router, auth, notes, AI, API, rendering, cache
views/          Twig templates
assets/css/     Styles
assets/js/      Client-side behavior
uploads/        Uploaded images organized by year/month
config.php      DB connection (gitignored)
vendor/         Committed Composer dependencies
```

Important files:

```text
index.php                    Entry point
core/init.php                Bootstrap, constants, includes
core/includes/router.php     Routes and internal session API
core/includes/db.php         PDO connection, schema, options + remember-me tokens
core/includes/notes.php      Note CRUD (MySQL), tree, uploads, block rendering
core/includes/auth.php       Sessions, login, remember-me tokens
core/includes/ai.php         AI provider integrations and tool calling
core/includes/api.php        REST API v1
core/includes/render.php     Twig setup and global template context
core/includes/markdown.php   Markdown <-> Editor.js conversion
core/includes/cache.php      Redis cache and Twig cache adapter
views/index.twig             Dashboard
views/editor.twig            Editor
views/overall/base.twig      Base layout and sidebar shell
views/overall/options.twig   Options popup template
views/overall/popup.twig     Shared popup shell
assets/js/app.js             Editor and dashboard client logic
assets/js/page-tool.js       Editor.js page-link tool
assets/js/options.js         Options popup behavior
assets/js/popup.js           Shared popup engine
assets/js/sidebar.js         Sidebar interactions and theme switching
assets/css/style.css         Styles
```

## Setup

Requirements:

- PHP 8.5
- MySQL or MariaDB (a utf8mb4 database)
- Imagick PHP extension for image conversion
- Apache, Nginx, Laravel Herd, or another web server pointing to `index.php`

PHP dependencies are committed in `vendor/`, so there is no install step.

Create a database (utf8mb4) and copy the example config:

```bash
cp config.example.php config.php
```

Example `config.php` — only the database connection lives here:

```php
<?php

return [
    'DB_HOST'    => '127.0.0.1',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'notes',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_CHARSET' => 'utf8mb4',
];
```

`config.php` is gitignored and is served as PHP (a direct request executes it to nothing), so it never leaks as plaintext the way a web-served `.env` would. The app reads it through `get_env()` from `core/includes/auth.php`; do not rely on `getenv()` or `$_ENV`.

The database schema is created automatically on the first successful DB connection. If `config.php` is missing or the database cannot be reached, the app returns a self-contained `503` setup/error page (`noindex`) instead of falling through to the login form.

Every other setting lives in the DB `options` table (key/value), read via `get_option()`. Set your admin login there. `AUTH_PASS` is stored as a bcrypt hash (`password_hash` / `password_verify`, like modern WordPress) — generate one and insert it:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
INSERT INTO options (name, value) VALUES
  ('AUTH_USER', 'admin'),
  ('AUTH_PASS', '$2y$12$...the-hash-from-above...');
```

(If you insert a plaintext password instead, it is accepted once and transparently re-saved as a hash on first login.)

The same table holds the REST API token (`API_TOKEN`), Cloudflare Turnstile CAPTCHA (`CAPTCHA_SITE_KEY` / `CAPTCHA_SECRET_KEY`), the AI assistant (`AI_PROVIDER` / `AI_API_KEY` / `AI_MODEL` / `AI_HISTORY_LIMIT`), an optional `REDIS_SOCKET`, and `assets_version`; leaving a setting unset disables that feature.

After the first login, these values can be managed from the footer Options popup:

- System: Redis socket and REST API token
- Cloudflare: Turnstile site and secret keys
- AI: provider, API key, model, and history limit
- Account: login and password change

The footer clear-cache button calls the internal session API to flush Redis/Twig/OPcache where available and increments `assets_version`, which is appended to static assets as a cache-busting query string.

## REST API

REST API v1 is available under `/api/v1/` and uses:

```http
Authorization: Bearer <API_TOKEN>
```

Markdown is converted to Editor.js blocks automatically.

> In production behind Cloudflare, non-browser API clients may be blocked with `HTTP 403, Cloudflare error 1010` before reaching the app. Send a browser-like `User-Agent`, or add a Cloudflare WAF skip rule for `/api/*` (see [API.md](API.md)).

| Method | URL | Description |
| --- | --- | --- |
| `GET` | `/api/v1/notes/` | List notes |
| `GET` | `/api/v1/notes/{path}` | Get one note |
| `POST` | `/api/v1/notes/` | Create a note |
| `PUT` | `/api/v1/notes/{path}` | Update a note |
| `PATCH` | `/api/v1/notes/{path}` | Update visibility |
| `DELETE` | `/api/v1/notes/{path}` | Delete a note |
| `GET` | `/api/v1/search/?q=` | Search notes |

See [API.md](API.md) for request and response examples.

## Internal Session API

Authenticated browser sessions use `/api/*` routes from `core/includes/router.php` for editor and dashboard actions. These are not the bearer-token REST API:

- `POST /api/save/`, `/api/delete/`, `/api/create-page/`, `/api/visibility/`
- `POST /api/reorder/`, `/api/move/`, `/api/toggle-pin/`
- `GET /api/search/`, `/api/graph/`, `/api/notes-page/`
- `POST /api/graph/`, `DELETE /api/graph/`
- `GET /api/fetch-url/`
- `POST /api/export-md/`, `/api/import-md/`
- `POST /api/process-svg/`, `/api/fetch-favicon/`, `/api/upload-image/`, `/api/fetch-image/`
- `POST /api/chat/`
- `GET /api/options/`, `POST /api/save-options/`
- `POST /api/clear-cache/`

## License

MIT
