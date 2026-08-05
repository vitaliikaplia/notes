# REST API

The REST API uses bearer-token authentication and exchanges note content as Markdown, automatically converting Markdown to Editor.js blocks on write.

```http
Authorization: Bearer <API_TOKEN>
```

`API_TOKEN` is stored in the database `options` table (manage it under Options -> System) and read through `get_option()`.

## Cloudflare

In production the site is fronted by Cloudflare with bot protection enabled. API requests sent with a non-browser `User-Agent` (for example `Python-urllib` or other default HTTP-library agents) can be blocked by Cloudflare with `HTTP 403, error code 1010` ("banned based on browser signature") **before the request reaches the app** — this is a Cloudflare block, not an API error (API errors use the JSON shape documented under [Errors](#errors)).

To call the API from scripts or integrations, either send a browser-like `User-Agent` header, or add a Cloudflare WAF **Skip** rule for the `/api/*` path (recommended).

## Endpoints

| Method | URL | Description |
| --- | --- | --- |
| `GET` | `/api/v1/notes/` | List all notes |
| `GET` | `/api/v1/notes/{path}` | Get one note, including Markdown and raw Editor.js content |
| `POST` | `/api/v1/notes/` | Create a note |
| `PUT` | `/api/v1/notes/{path}` | Replace/update a note |
| `PATCH` | `/api/v1/notes/{path}` | Patch note visibility |
| `DELETE` | `/api/v1/notes/{path}` | Delete a note |
| `GET` | `/api/v1/search/?q=` | Search notes |

Additional query parameters for `GET /api/v1/notes/` and `GET /api/v1/search/?q=`:

| Parameter | Type | Description |
| --- | --- | --- |
| `limit` | int | Optional result limit. Search defaults to 20 and caps at 100. |
| `folder` | string | Optional path/folder filter, for example `recipes` or `projects/client-a`. |
| `visibility` | string | Optional visibility filter: `private`, `unlisted`, or `public`. |

## Paths

Paths never include `.json`.

Examples:

```text
recipes
recipes/classic-borsch
projects/client-a/kickoff
```

Internally, notes are stored in the database (the `notes` table), keyed by their `path`.

## Create Note

`POST /api/v1/notes/`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `title` | string | yes | Note title |
| `markdown` | string | no | Markdown content |
| `icon` | string | no | Emoji icon |
| `folder` | string | no | Parent folder/path. Empty means root. |
| `visibility` | string | no | `private` by default, or `unlisted`, `public` |

Example:

```bash
curl -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Classic Borsch","markdown":"## Ingredients\n\n- Beetroot\n- Potato","icon":"🍲","folder":"recipes"}' \
  https://domain/api/v1/notes/
```

Response:

```json
{
    "path": "recipes/classic-borsch",
    "title": "Classic Borsch",
    "icon": "🍲",
    "visibility": "private",
    "created_at": "2026-01-15T10:00:00+02:00",
    "updated_at": "2026-01-15T10:00:00+02:00"
}
```

## Get Notes

`GET /api/v1/notes/`

```bash
curl -H "Authorization: Bearer TOKEN" https://domain/api/v1/notes/
```

Response:

```json
{
    "notes": [
        {
            "path": "recipes",
            "title": "Recipes",
            "icon": "🍽️",
            "visibility": "private",
            "created_at": "2026-01-15T10:00:00+02:00",
            "updated_at": "2026-01-20T14:30:00+02:00"
        }
    ]
}
```

`GET /api/v1/notes/{path}`

```bash
curl -H "Authorization: Bearer TOKEN" https://domain/api/v1/notes/recipes/classic-borsch
```

Response:

```json
{
    "path": "recipes/classic-borsch",
    "title": "Classic Borsch",
    "icon": "🍲",
    "visibility": "unlisted",
    "url": "https://domain/note/recipes/classic-borsch/",
    "created_at": "2026-01-15T10:00:00+02:00",
    "updated_at": "2026-01-20T14:30:00+02:00",
    "markdown": "## Ingredients\n\n- Beetroot\n- Potato",
    "content": {
        "time": 1234567890,
        "blocks": [],
        "version": "2.31.0-rc.7"
    }
}
```

`url` is returned only when visibility is `unlisted` or `public`.

## Update Note

`PUT /api/v1/notes/{path}`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `title` | string | no | New title. Existing title is preserved if omitted. |
| `markdown` | string | no | New Markdown content. Existing content is preserved if omitted. |
| `icon` | string | no | New icon |
| `visibility` | string | no | `private`, `unlisted`, or `public` |

Example:

```bash
curl -X PUT \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated title","markdown":"Updated text"}' \
  https://domain/api/v1/notes/recipes/classic-borsch
```

Response:

```json
{
    "path": "recipes/classic-borsch",
    "title": "Updated title",
    "icon": "🍲",
    "visibility": "unlisted",
    "created_at": "2026-01-15T10:00:00+02:00",
    "updated_at": "2026-01-20T14:30:00+02:00",
    "url": "https://domain/note/recipes/classic-borsch/",
    "markdown": "Updated text",
    "content": {
        "time": 1234567890,
        "blocks": [],
        "version": "2.31.0-rc.7"
    }
}
```

## Patch Visibility

`PATCH /api/v1/notes/{path}`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `visibility` | string | yes | `private`, `unlisted`, or `public` |

Example:

```bash
curl -X PATCH \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"visibility":"unlisted"}' \
  https://domain/api/v1/notes/recipes/classic-borsch
```

## Delete Note

`DELETE /api/v1/notes/{path}`

```bash
curl -X DELETE \
  -H "Authorization: Bearer TOKEN" \
  https://domain/api/v1/notes/recipes/classic-borsch
```

Response:

```json
{
    "deleted": true,
    "path": "recipes/classic-borsch"
}
```

Deleting a parent note also deletes all of its child notes (the database cascades the delete).

## Search

`GET /api/v1/search/?q=`

```bash
curl -H "Authorization: Bearer TOKEN" "https://domain/api/v1/search/?q=borsch"
```

Response:

```json
{
    "results": [
        {
            "path": "recipes/classic-borsch",
            "title": "Classic Borsch",
            "icon": "🍲",
            "visibility": "unlisted",
            "updated_at": "2026-01-20T14:30:00+02:00",
            "url": "https://domain/note/recipes/classic-borsch/",
            "snippet": "...matching text..."
        }
    ]
}
```

Results are sorted by:

1. Title matches first
2. Newest `updated_at` first

## Visibility

| Value | Description |
| --- | --- |
| `private` | Default. Available only to authenticated users. |
| `unlisted` | Available by direct link and marked `noindex, nofollow`. |
| `public` | Public and indexable. |

## Errors

Errors use this shape:

```json
{
    "error": {
        "code": 404,
        "message": "Note not found"
    }
}
```

## Internal Browser API

The app also exposes session-authenticated `/api/*` routes from `core/includes/router.php` for the web UI. They require the regular logged-in browser session, not a bearer token, and are intentionally separate from `/api/v1/*`.

Important internal routes include note autosave/delete/move/reorder, image upload/fetch, graph positions, AI chat, Markdown import/export, Options load/save, and cache clearing. External integrations should use `/api/v1/*` unless they intentionally run inside an authenticated browser session.
