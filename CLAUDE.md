# CLAUDE.md

## Project

Self-hosted notes on PHP 8+ / Twig 3 / Editor.js. No framework and no database; JSON files are the storage layer.

The product UI is English. Notes may contain any language. Ukrainian transliteration remains part of slug generation.

## Architecture

- Entry point: `index.php` -> `core/init.php` -> includes -> router
- Procedural PHP
- Functions use `snake_case`
- Constants use `UPPER_CASE`
- Twig 3 templates live in `views/*.twig`
- Vanilla JavaScript
- CSS uses custom properties

## Key Files

```text
core/includes/router.php   Routing and all internal routes
core/includes/notes.php    Note CRUD, tree scanning, slug generation, uploads
core/includes/auth.php     Sessions, login, remember-me tokens, .env parser
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

- `.env` is parsed by `get_env()` from `core/includes/auth.php`
- Do not use `getenv()` or `$_ENV`
- Important constants: `ABSPATH`, `HOME_URL`, `NOTES_DIR` (`.notes`), `SITE_NAME`
- Timezone: `Europe/Kyiv`

## Conventions

- Root notes are stored as `.notes/{slug}.json`
- Nested notes are stored as `.notes/{parent}/{child}.json`
- Slugs use Ukrainian transliteration through `ukr_to_lat()`
- Each PHP include starts with `if(!defined('ABSPATH')){exit;}`
- Light/dark themes use custom properties such as `var(--bg)` and `var(--text)`
- JavaScript is IIFE-style, without modules or a bundler
- Editor.js and plugins are loaded from CDN

## Commands

- Install dependencies: `composer install`
- Web server: Apache, Nginx, or Laravel Herd pointing to `index.php`
- Required PHP extension for image conversion: Imagick

## Notes For Agents

- Avoid introducing a framework or database.
- Preserve existing JSON note format: `meta` plus Editor.js `content`.
- Clear note/tree cache after note mutations.
- Keep public UI text in English.
- Do not remove the Ukrainian transliteration table in `core/includes/notes.php`; it is part of slug compatibility.
