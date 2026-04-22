# AGENTS.md

## Проєкт

Self-hosted нотатки на PHP 8+ / Twig 3 / Editor.js. Без фреймворків, без БД — JSON-файли як сховище.

## Архітектура

- Точка входу: `index.php` → `core/init.php` → модулі → роутер
- Процедурний PHP, функції в `snake_case`, константи `UPPER_CASE`
- Шаблони Twig 3 (`views/*.twig`)
- Vanilla JS, CSS з кастомними змінними

## Ключові файли

```
core/includes/router.php   — маршрутизація, всі роути
core/includes/notes.php    — CRUD нотаток, дерево, slug-генерація
core/includes/auth.php     — сесії, remember-me токени
core/includes/ai.php       — ШІ-модуль (Codex/OpenAI/Gemini), tool calling
core/includes/api.php      — REST API v1
core/includes/render.php   — Twig-рендеринг, контекст
core/includes/markdown.php — MD ↔ Editor.js конвертація
core/includes/cache.php    — Redis-кеш з fallback на диск
views/index.twig           — дашборд (5 вкладок + JS логіка)
views/editor.twig          — редактор нотаток
views/overall/base.twig    — базовий layout, сайдбар
assets/css/style.css       — всі стилі
assets/js/app.js           — клієнтська логіка
```

## Конфігурація

- `.env` — кастомний парсер через `get_env()` (НЕ `getenv()` / `$_ENV`)
- Константи: `ABSPATH`, `HOME_URL`, `NOTES_DIR` (`.notes`), `SITE_NAME`
- Часовий пояс: `Europe/Kyiv`

## Конвенції

- Нотатки зберігаються як `.notes/{slug}.json`, вкладені — `.notes/{parent}/{child}.json`
- Slug — транслітерація з української через `ukr_to_lat()`
- Кожен PHP-файл починається з `if(!defined('ABSPATH')){exit;}`
- CSS: темна/світла тема через `prefers-color-scheme` + кастомні змінні `var(--bg)`, `var(--text)` тощо
- JS: IIFE, без модулів/бандлерів, Editor.js з CDN

## Команди

- Веб-сервер: Apache/Nginx/Laravel Herd, точка входу `index.php`
- Залежності: `composer install` (тільки Twig)
- PHP-розширення: Imagick (конвертація зображень у WebP)
