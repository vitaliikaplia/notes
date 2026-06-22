# Notes

Self-hosted notes with full control over your data: no framework, no database, no subscriptions. The app is built with PHP, Twig, Editor.js, and JSON files, with a built-in AI assistant that can search, read, create, and update notes from chat.

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
- SVG images are stored as-is after validation/minification when used as icons
- Image files are cleaned up when removed from notes or covers
- Image references are stored as host-relative URLs (`/file/...`), so notes stay portable across hosts (local, production, backups) and never break when moved
- YouTube and Vimeo embeds are detected from standalone URLs

### Navigation

- Nested notes through parent/child JSON paths
- Tree sidebar with drag-and-drop sorting and cross-level moves
- Breadcrumbs
- Emoji and SVG note icons
- Quick search by title and content with `Ctrl+K`
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

### Public View

- Read-only rendering for unlisted and public notes
- Rendered blocks include headings, lists, checklists, code, quotes, tables, delimiters, images, alerts, embeds, and page links
- Cover images and note colors are preserved
- Schema.org Article metadata

### Security

- Login/password authentication
- Remember-me tokens stored as hashed files
- Optional Cloudflare Turnstile CAPTCHA
- Uploaded files are served through `/file/` and are only available to authenticated users or when referenced from a public/unlisted note
- REST API v1 uses bearer-token authentication
- Configuration and secrets live in `config.php`, which is served as PHP and never exposed as plaintext (unlike a web-served `.env`)

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
- Storage: JSON files in `.notes`
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
.notes/         JSON note storage
config.php      Local configuration (gitignored)
```

Important files:

```text
index.php                    Entry point
core/init.php                Bootstrap, constants, includes
core/includes/router.php     Routes and internal session API
core/includes/notes.php      Note CRUD, tree scanning, uploads, block rendering
core/includes/auth.php       Sessions, login, remember-me tokens
core/includes/ai.php         AI provider integrations and tool calling
core/includes/api.php        REST API v1
core/includes/render.php     Twig setup and global template context
core/includes/markdown.php   Markdown <-> Editor.js conversion
core/includes/cache.php      Redis cache and Twig cache adapter
views/index.twig             Dashboard
views/editor.twig            Editor
views/overall/base.twig      Base layout and sidebar shell
assets/js/app.js             Editor and dashboard client logic
assets/js/page-tool.js       Editor.js page-link tool
assets/js/sidebar.js         Sidebar interactions and theme switching
assets/css/style.css         Styles
```

## Setup

Requirements:

- PHP 8.5
- Composer
- Twig dependency from Composer
- Imagick PHP extension for image conversion
- Apache, Nginx, Laravel Herd, or another web server pointing to `index.php`

Install dependencies:

```bash
composer install
```

Copy the example config and fill it in:

```bash
cp config.example.php config.php
```

Example `config.php`:

```php
<?php

return [
    'AUTH_USER' => 'admin',
    'AUTH_PASS' => 'yourpassword',

    // Cloudflare Turnstile, leave empty to disable
    'CAPTCHA_SITE_KEY'   => '',
    'CAPTCHA_SECRET_KEY' => '',

    // REST API, leave empty to disable API access
    'API_TOKEN' => 'your-secret-token',

    // AI assistant: claude / openai / gemini
    'AI_PROVIDER'      => 'openai',
    'AI_API_KEY'       => 'sk-proj-...',
    'AI_MODEL'         => '',
    'AI_HISTORY_LIMIT' => '20',

    // Optional Redis Unix socket
    'REDIS_SOCKET' => '',
];
```

`config.php` is gitignored and is served as PHP (a direct request executes it to nothing), so it never leaks as plaintext the way a web-served `.env` would. The app reads configuration through `get_env()` from `core/includes/auth.php`; do not rely on `getenv()` or `$_ENV`.

## REST API

REST API v1 is available under `/api/v1/` and uses:

```http
Authorization: Bearer <API_TOKEN>
```

Markdown is converted to Editor.js blocks automatically.

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

## License

MIT
