# MCP Server

The app exposes a Model Context Protocol (MCP) server at `/mcp` — a stateless Streamable HTTP endpoint (JSON-RPC 2.0 over POST). It replaces the former REST API v1 and gives MCP clients (Claude Code, Claude Desktop, MCP Inspector, and others) direct tool access to notes: list, search, read, create, update, and delete.

Implementation lives in `core/includes/mcp.php`; the tool definitions and execution are shared with the built-in AI assistant (`ai_get_tools()` / `ai_execute_tool()` in `core/includes/ai.php`).

## Authentication

Every request must carry a bearer token:

```http
Authorization: Bearer <token>
```

The token is managed under **Options -> System -> MCP server**:

- **Generate token** creates a `nmcp_...` token and shows it **once**; only its SHA-256 hash is stored (`MCP_TOKEN_HASH` in the `options` table).
- **Regenerate** invalidates the previous token.
- **Revoke** deletes the token and disables the MCP server entirely.

A legacy plaintext `API_TOKEN` (from the old REST API) is migrated automatically on first use: it is hashed into `MCP_TOKEN_HASH` and the plaintext row is deleted, so an existing token keeps working as the MCP token.

Requests without a valid token get `HTTP 401`.

## Connecting

Claude Code:

```bash
claude mcp add --transport http notes https://domain/mcp --header "Authorization: Bearer <token>"
```

Or in `.mcp.json`:

```json
{
    "mcpServers": {
        "notes": {
            "type": "http",
            "url": "https://domain/mcp",
            "headers": { "Authorization": "Bearer <token>" }
        }
    }
}
```

Any MCP client that supports the Streamable HTTP transport with custom headers works the same way.

## Cloudflare

In production the site is fronted by Cloudflare with bot protection enabled. Requests sent with a non-browser `User-Agent` (most MCP clients and HTTP libraries) can be blocked by Cloudflare with `HTTP 403, error code 1010` ("banned based on browser signature") **before the request reaches the app** — this is a Cloudflare block, not an MCP error (MCP errors are JSON-RPC responses or the JSON error shape below).

To connect from MCP clients, either send a browser-like `User-Agent` header, or add a Cloudflare WAF **Skip** rule for the `/mcp` path (recommended).

## Protocol

- Single endpoint: `POST /mcp`. Responses are plain JSON (`application/json`); the server never opens SSE streams, so `GET` and `DELETE` return `405`.
- Stateless: no `Mcp-Session-Id` is issued and none is required.
- Supported protocol versions: `2025-06-18`, `2025-03-26`, `2024-11-05` (the requested version is echoed when supported, otherwise the newest is returned).
- Declared capabilities: `tools` only. `resources/list` and `prompts/list` return empty lists for clients that probe them anyway.
- JSON-RPC batches (arrays) are accepted; notifications are acknowledged with `202` and no body.

## Tools

| Tool | Arguments | Description |
| --- | --- | --- |
| `notes_list` | — | List all notes with metadata (title, icon, path, visibility, updated_at) |
| `notes_search` | `query` | Search titles and content, returns paths and snippets |
| `notes_get` | `path` | Read one note as Markdown |
| `notes_create` | `title`, `markdown`, `icon?`, `folder?`, `visibility?` | Create a note (Markdown is converted to Editor.js blocks) |
| `notes_update` | `path`, `title?`, `markdown?`, `icon?`, `visibility?`, `pinned?` | Update a note; omitted fields are preserved |
| `notes_delete` | `path` | Delete a note (children are cascade-deleted) |

Read-only tools carry `readOnlyHint: true` annotations; `notes_update` and `notes_delete` are marked destructive.

### Paths

Paths never include `.json` and can be nested:

```text
recipes
recipes/classic-borsch
projects/client-a/kickoff
```

If an exact path is unknown, `notes_get`/`notes_update`/`notes_delete` fall back to resolving by slug or title; prefer finding the real path with `notes_search` or `notes_list` first.

### Visibility

| Value | Description |
| --- | --- |
| `private` | Default. Available only to authenticated users. |
| `unlisted` | Available by direct link and marked `noindex, nofollow`. |
| `public` | Public and indexable. |

## Examples

Initialize:

```bash
curl -X POST https://domain/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

List tools:

```bash
curl -X POST https://domain/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

Call a tool:

```bash
curl -X POST https://domain/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"notes_create","arguments":{"title":"Classic Borsch","markdown":"## Ingredients\n\n- Beetroot\n- Potato","icon":"🍲","folder":"recipes"}}}'
```

Tool results come back as MCP content:

```json
{
    "jsonrpc": "2.0",
    "id": 3,
    "result": {
        "content": [
            { "type": "text", "text": "{\n    \"path\": \"recipes/classic-borsch\",\n    \"title\": \"Classic Borsch\", ... }" }
        ],
        "isError": false
    }
}
```

## Errors

Transport-level errors use HTTP status codes with this JSON shape:

```json
{
    "error": { "code": 401, "message": "Unauthorized" }
}
```

Protocol errors are standard JSON-RPC: `-32700` (parse error), `-32600` (invalid request), `-32601` (method not found), `-32602` (unknown tool). Tool execution failures (e.g. "Note not found") are returned as successful `tools/call` responses with `isError: true` and the message in the text content.

## Internal Browser API

The app also exposes session-authenticated `/api/*` routes from `core/includes/router.php` for the web UI. They require the regular logged-in browser session, not a bearer token, and are intentionally separate from `/mcp`.

Important internal routes include note autosave/delete/move/reorder, image upload/fetch, graph positions, AI chat, Markdown import/export, Options load/save, MCP token generate/revoke, and cache clearing. External integrations should use `/mcp` unless they intentionally run inside an authenticated browser session.
