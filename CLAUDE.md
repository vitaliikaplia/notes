# CLAUDE.md

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
core/includes/passkeys.php Passkeys (WebAuthn via lbuchs/webauthn): registration, sign-in, challenges
core/includes/ai.php       AI module for Claude/OpenAI/Gemini tool calling
core/includes/mcp.php      MCP server (Streamable HTTP, /mcp) + hashed token management
core/includes/render.php   Twig rendering and shared context
core/includes/markdown.php Markdown <-> Editor.js conversion
core/includes/pdf.php      PDF export (Dompdf, reuses render_blocks_to_html)
core/includes/docx.php     Word export (hand-written OOXML + ZIP, walks the blocks directly)
core/includes/cache.php    Redis cache with graceful fallback
views/index.twig           Dashboard tabs and page-level JS
views/editor.twig          Note editor
views/overall/base.twig    Base layout and sidebar shell
views/overall/options.twig Options popup template
views/overall/popup.twig   Shared popup shell
assets/css/style.css       Styles
assets/js/app.js           Editor and dashboard client logic
assets/js/sidebar.js       Sidebar interactions
assets/js/options.js       Options popup behavior
assets/js/passkey.js       Passkey registration (Options → Account) and passkey sign-in (login page)
assets/js/popup.js         Shared popup engine
assets/js/page-tool.js     Editor.js page-link tool
assets/js/gallery-tool.js  Editor.js gallery tool (thumbnail grid, 2–4 per row)
assets/js/video-tool.js    Editor.js video tool (chunked upload to /api/upload-video/, native <video> player)
assets/js/lightbox.js      Global lightbox for gallery thumbnails (loaded from base.twig)
```

## Configuration

- `config.php` (gitignored, returns an array, read via `get_env()`) holds ONLY the DB connection (`DB_HOST/PORT/NAME/USER/PASS/CHARSET`); copy `config.example.php` -> `config.php` to set up
- Every other setting — admin login (`AUTH_USER/AUTH_PASS`), MCP token hash (`MCP_TOKEN_HASH`, SHA-256, plaintext never stored), CAPTCHA, AI provider/key, Redis socket — lives in the DB `options` table; read/write with `get_option()` / `set_option()` from `core/includes/db.php`, never `get_env()`
- `AUTH_PASS` is a bcrypt hash (`password_hash`/`password_verify`); set it via `auth_set_password($plain)` in `auth.php`. A plaintext value is accepted once and auto-upgraded to a hash on first login
- Do not use `getenv()` or `$_ENV`; `config.php` is served as PHP so it never leaks as plaintext
- Important constants: `ABSPATH`, `HOME_URL`, `SITE_NAME`
- Timezone: `Europe/Kyiv`

## Conventions

- Notes live in the `notes` table as an adjacency list (`parent_id` + a unique `path`); `path` (e.g. `parent/child`, no `.json`) is the URL identifier and the stable key used across the app
- The Editor.js document is stored in the `content` column (JSON); meta fields (title, icon, cover, color, pinned, visibility, graph_x/y) are columns; SVG/emoji icons are stored inline in `icon`
- Settings live in the `options` table (key/value); remember-me tokens live in `remember_tokens` (sha256 token hash + user + expiry)
- Passkeys live in the `passkeys` table (name, raw `credential_id`, PEM `public_key`, `sign_count`, timestamps) and belong to the single admin: a successful assertion authenticates the session as `AUTH_USER`. `passkey_register_options()` / `passkey_register_finish()` (session auth, Options → Account) and `passkey_login_options()` / `passkey_login_finish()` (public `/api/passkey-login-options/` + `/api/passkey-login/`, handled before `auth_require()` in the `api` branch) wrap `lbuchs\WebAuthn` with `none` attestation, resident key + user verification required, RP id = host of `HOME_URL` (so local and prod passkeys are separate). Challenges are stored in `$_SESSION['passkey_challenge']` with a purpose and a 5-minute TTL and are consumed on first use. The stable WebAuthn user handle is the `PASSKEY_USER_HANDLE` option. The login page shows the passkey button only when at least one passkey exists (`passkeys_enabled`); the CAPTCHA applies to the password form only
- The schema self-creates on the first DB connect (`db_init_schema()` is called from `get_db()`)
- The setup/database fatal page is self-contained, returns `503`, and is marked `noindex`
- Slugs use Ukrainian transliteration through `ukr_to_lat()`
- Gallery block: type `gallery`, data `{items: [{url, caption}], columns}`; Markdown form is `::: gallery cols=N` … `:::` with one `![caption](url)` per line (markdown.php); rendered by `render_blocks_to_html()` as `.gallery.gallery-cols-N`, converted to a table for PDF in `pdf_adapt_html()` and to a borderless table in `docx_gallery()`. Keep `extract_image_urls()` / `collect_media_from_notes()` aware of gallery items so uploads are not treated as orphans
- Video block: type `video`, data `{url, caption}`; Markdown form is the image tag with a video file URL, `![caption](/file/….mp4)` (`.mp4`/`.webm`, see `is_video_upload_url()`); rendered by `render_blocks_to_html()` as `figure.video-block > video[controls]` and replaced by a link in `pdf_adapt_html()` and in the Word export. Videos are stored as-is (no transcoding) via `save_uploaded_video()`; uploads arrive in chunks (`video_upload_append()` / `video_upload_finish()`, temp parts in the system temp dir) from both `/api/upload-video/` and the MCP tool `notes_upload_video`. `/file/` serving goes through `serve_upload_file()`, which supports HTTP Range (required for seeking and for Safari playback) and closes the session before streaming
- `extract_image_urls()` returns every upload a note references (images, gallery items, videos) and drives orphan cleanup through `delete_orphaned_uploads()` — used by the editor save route and by the AI/MCP `notes_update`; keep new media block types listed there or their files get deleted as orphans
- Upload/image URLs are stored host-relative (`/file/...`) via `normalize_upload_url()` / `upload_url_to_relative_path()`; never bake `HOME_URL` into stored note data, so notes stay portable across hosts
- Admin settings are edited from the Options popup (`/api/options`, `/api/save-options`) and cache clearing goes through `/api/clear-cache`, which bumps `assets_version`; `asset_ver()` also appends the asset file's mtime, so a deploy busts browser caches for changed JS/CSS on its own
- Editor saves carry `expected_updated_at` (the version the tab loaded); `/api/save/` refuses with `{conflict: true}` when the stored note is newer (MCP/AI/another tab), and the editor stops autosaving until reload. Markdown/PDF/Word exports send the note `path` and are rendered from the stored note, not from the tab's blocks
- Word export (`/api/export-docx/`, `note_export_docx()` in `core/includes/docx.php`) has no library: it maps each block type to WordprocessingML itself (inline HTML is parsed with DOMDocument into runs), packs the parts with its own deflate ZIP writer and embeds local uploads as media (WebP/SVG are converted to JPEG/PNG through Imagick, GD fallback for WebP; remote or unreadable images become links). Every list gets its own `abstractNum` so numbering restarts in every reader; code blocks are one-cell tables. When adding a block type, extend `docx_blocks()` alongside `render_blocks_to_html()` and `blocks_to_markdown()`
- Each PHP include starts with `if(!defined('ABSPATH')){exit;}`
- Light/dark themes use custom properties such as `var(--bg)` and `var(--text)`
- JavaScript is IIFE-style, without modules or a bundler
- Editor.js, its plugins, force-graph, and air-datepicker are self-hosted in `assets/vendor/` with the version in the filename (update = download new file + change the template reference); the only external script is Cloudflare Turnstile, which cannot be self-hosted

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
- The external integration surface is the MCP server at `/mcp` (`core/includes/mcp.php`, stateless Streamable HTTP, JSON-RPC 2.0); its tools are shared with the AI assistant (`ai_get_tools()` / `ai_execute_tool()`). REST API v1 was removed. A legacy plaintext `API_TOKEN` option is auto-migrated to `MCP_TOKEN_HASH` on first use. MCP-only tools (uploads, `notes_clear_cache`) are declared in `mcp_extra_tools()` and dispatched in `mcp_method_tools_call()`; the AI chat never sees them.
- Production sits behind Cloudflare with bot protection on: MCP requests from non-browser clients can be blocked with `HTTP 403, Cloudflare error 1010` ("banned based on browser signature") before reaching the app — this is a Cloudflare block, not an MCP error. Send a browser-like `User-Agent`, or add a Cloudflare WAF skip rule for `/mcp`. See `MCP.md`.
