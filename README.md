# Нотатки

Персональний сервіс для нотаток, натхненний Notion. Побудований на PHP + Twig без фреймворків, з Editor.js як блоковим редактором. Має REST API для інтеграції з ШІ-агентами (N8N, Telegram-боти тощо).

## Можливості

- Блоковий редактор (заголовки, списки, чеклисти, код, цитати, таблиці, роздільники, посилання)
- Блоки коду з підсвіткою синтаксису (One Dark тема, 18 мов, пошук по мовах)
- Inline-інструменти: жирний, курсив, підкреслення, закреслення, маркер, код
- Кольорові блоки-сповіщення (info, success, warning, danger)
- Розгортувані блоки (toggle)
- Drag-and-drop перетягування блоків у редакторі
- Undo/Redo (Ctrl+Z / Ctrl+Y)
- Вкладені сторінки (батьківські/дочірні нотатки)
- Емоджі та SVG іконки для нотаток
- Деревоподібний сайдбар з drag-and-drop сортуванням
- Автозбереження при редагуванні
- Хлібні крихти для навігації
- Перегляд плиткою та списком на головній
- Темна та світла тема (автоматично за системною)
- Мобільна адаптивна верстка з бургер-меню
- PWA — встановлюється як застосунок на телефон та десктоп
- Авторизація за логіном та паролем
- Cloudflare Turnstile CAPTCHA (опціонально)
- Експорт нотатки в Markdown (кнопка в хлібних крихтах)
- Імпорт .md файлів через drag-and-drop
- REST API v1 з токен-авторизацією

![Головна — перегляд плиткою, сайдбар, темна тема](screenshots/screencapture-notes-kaplia-pro-2026-02-28-05_21_07.png)

## Пошук

Швидкий пошук по заголовках та вмісту нотаток (Ctrl+K).

![Пошук по нотатках](screenshots/screencapture-notes-kaplia-pro-2026-02-28-05_21_29.png)

## Стек

- **Backend:** PHP, Twig 3
- **Редактор:** Editor.js (CDN) + плагіни (codecup, table, underline, strikethrough, alert, toggle, drag-drop, undo)
- **Сховище:** JSON-файли (без бази даних)
- **Стилі:** CSS з кастомними змінними
- **JS:** Vanilla JavaScript

## Структура

```
core/           — ядро: роутер, авторизація, робота з нотатками
views/          — Twig-шаблони
assets/css/     — стилі
assets/js/      — клієнтська логіка (app.js, page-tool.js)
.notes/         — сховище нотаток (JSON-файли)
.env            — конфігурація (логін, пароль, CAPTCHA-ключі)
```

## Запуск

Потрібен PHP 8+ та веб-сервер (Apache/Nginx/Laravel Herd). Точка входу — `index.php`.

### Налаштування `.env`

```
AUTH_USER=admin
AUTH_PASS=yourpassword

# Cloudflare Turnstile (залишити порожнім для вимкнення)
CAPTCHA_SITE_KEY=
CAPTCHA_SECRET_KEY=

# REST API (залишити порожнім для вимкнення)
API_TOKEN=your-secret-token
```

## REST API

Токен-авторизація через заголовок `Authorization: Bearer <API_TOKEN>`. Обмін даними у форматі Markdown з автоматичною конвертацією в Editor.js блоки.

### Ендпоінти

| Метод | URL | Опис |
|-------|-----|------|
| `GET` | `/api/v1/notes/` | Список усіх нотаток |
| `GET` | `/api/v1/notes/{path}` | Одна нотатка (markdown + JSON) |
| `POST` | `/api/v1/notes/` | Створити нотатку |
| `PUT` | `/api/v1/notes/{path}` | Оновити нотатку |
| `DELETE` | `/api/v1/notes/{path}` | Видалити нотатку |
| `GET` | `/api/v1/search/?q=` | Пошук по нотатках |

### Приклади

**Список нотаток:**
```bash
curl -H "Authorization: Bearer TOKEN" https://domain/api/v1/notes/
```

**Створити нотатку:**
```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Назва","markdown":"## Заголовок\n\nТекст","icon":"📝","folder":"parent-slug"}' \
  https://domain/api/v1/notes/
```

**Оновити нотатку:**
```bash
curl -X PUT -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Нова назва","markdown":"Новий текст"}' \
  https://domain/api/v1/notes/slug
```

**Видалити нотатку:**
```bash
curl -X DELETE -H "Authorization: Bearer TOKEN" https://domain/api/v1/notes/slug
```

**Пошук:**
```bash
curl -H "Authorization: Bearer TOKEN" "https://domain/api/v1/search/?q=запит"
```

### Формат відповідей

Список: `{"notes": [{"path", "title", "icon", "created_at", "updated_at"}]}`

Одна нотатка: `{"path", "title", "icon", "created_at", "updated_at", "markdown", "content"}`

Пошук: `{"results": [{"path", "title", "icon", "snippet"}]}`

Помилки: `{"error": {"code": 400, "message": "..."}}`

### ШІ-агент

API дозволяє підключити нотатки до ШІ-агента (наприклад, через N8N + Telegram-бот) для створення, пошуку та редагування нотаток голосом або текстом.

![Керування нотатками через Telegram-бот з ШІ](screenshots/Screenshot%202026-02-28%20at%2005.21.51.png)
