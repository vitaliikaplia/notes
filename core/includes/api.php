<?php

if(!defined('ABSPATH')){exit;}

// ============================================================
// Auth
// ============================================================

function api_auth(): bool {
    $env = get_env();
    $token = $env['API_TOKEN'] ?? '';

    if (empty($token)) return false;

    // 1. Standard Authorization header
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return hash_equals($token, trim($m[1]));
    }

    // 2. Fallback: apache_request_headers()
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return hash_equals($token, trim($m[1]));
        }
    }

    // 3. Fallback: getallheaders()
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization' && preg_match('/^Bearer\s+(.+)$/i', $value, $m)) {
                return hash_equals($token, trim($m[1]));
            }
        }
    }

    return false;
}

function api_require_auth(): void {
    if (!api_auth()) {
        api_error(401, 'Unauthorized');
    }
}

function api_error(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => ['code' => $status, 'message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// Dispatcher
// ============================================================

function api_dispatch(array $segments): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    api_require_auth();

    $method   = $_SERVER['REQUEST_METHOD'];
    $resource = $segments[0] ?? '';

    if ($resource === 'notes') {
        $path_parts = array_slice($segments, 1);
        $path = implode('/', array_filter($path_parts));

        // Security: block directory traversal
        if (preg_match('/\.\./', $path)) {
            api_error(400, 'Invalid path');
        }

        if (empty($path)) {
            match ($method) {
                'GET'  => api_list_notes(),
                'POST' => api_create_note(),
                default => api_error(405, 'Method not allowed'),
            };
        } else {
            match ($method) {
                'GET'    => api_get_note($path),
                'PUT'    => api_update_note($path),
                'PATCH'  => api_patch_note($path),
                'DELETE' => api_delete_note($path),
                default  => api_error(405, 'Method not allowed'),
            };
        }
    } elseif ($resource === 'search') {
        if ($method === 'GET') {
            api_search_notes();
        }
        api_error(405, 'Method not allowed');
    }

    api_error(404, 'Unknown resource');
}

// ============================================================
// Endpoints
// ============================================================

function api_list_notes(): void {
    $all = collect_all_notes();

    usort($all, function($a, $b) {
        return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? '');
    });

    $notes = [];
    foreach ($all as $note) {
        $notes[] = [
            'path'       => preg_replace('/\.json$/', '', $note['_file']),
            'title'      => $note['_title'],
            'icon'       => $note['meta']['icon'] ?? '',
            'visibility' => $note['meta']['visibility'] ?? 'private',
            'created_at' => $note['meta']['created_at'] ?? null,
            'updated_at' => $note['meta']['updated_at'] ?? null,
        ];
    }

    api_json(['notes' => $notes]);
}

function api_get_note(string $path): void {
    $relative = str_replace('/', DS, $path) . '.json';
    $note = get_note($relative);

    if (!$note) {
        api_error(404, 'Note not found');
    }

    $blocks = $note['content']['blocks'] ?? [];

    api_json([
        'path'       => $path,
        'title'      => $note['_title'],
        'icon'       => $note['meta']['icon'] ?? '',
        'visibility' => $note['meta']['visibility'] ?? 'private',
        'created_at' => $note['meta']['created_at'] ?? null,
        'updated_at' => $note['meta']['updated_at'] ?? null,
        'markdown'   => blocks_to_markdown($blocks),
        'content'    => $note['content'],
    ]);
}

function api_create_note(): void {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input)) {
        api_error(400, 'Invalid JSON body');
    }

    $title = strip_tags(trim($input['title'] ?? ''));
    if (empty($title)) {
        api_error(400, 'Title is required');
    }

    $markdown   = $input['markdown'] ?? '';
    $folder     = trim($input['folder'] ?? '', '/ ');
    $icon       = $input['icon'] ?? '';
    $visibility = $input['visibility'] ?? 'private';

    if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        api_error(400, 'Invalid visibility. Must be: private, unlisted, public');
    }

    $slug = generate_slug($title);
    $dir_prefix = $folder ? $folder . '/' : '';
    $relative = $dir_prefix . $slug . '.json';

    // Unique slug
    $base_slug = $slug;
    $counter = 1;
    while (file_exists(get_notes_path() . DS . str_replace('/', DS, $relative))) {
        $slug = $base_slug . '-' . $counter;
        $relative = $dir_prefix . $slug . '.json';
        $counter++;
    }

    $now = date('c');
    $blocks = !empty($markdown) ? markdown_to_blocks($markdown) : [];

    $note_data = [
        'meta' => [
            'title'      => $title,
            'icon'       => $icon,
            'visibility' => $visibility,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        'content' => [
            'time'    => round(microtime(true) * 1000),
            'blocks'  => $blocks,
            'version' => '2.31.0-rc.7',
        ],
    ];

    if (!save_note(str_replace('/', DS, $relative), $note_data)) {
        api_error(500, 'Failed to save note');
    }

    $path = preg_replace('/\.json$/', '', $relative);

    api_json([
        'path'       => $path,
        'title'      => $title,
        'icon'       => $icon,
        'visibility' => $visibility,
        'created_at' => $now,
        'updated_at' => $now,
    ], 201);
}

function api_update_note(string $path): void {
    $relative = str_replace('/', DS, $path) . '.json';
    $existing = get_note($relative);

    if (!$existing) {
        api_error(404, 'Note not found');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        api_error(400, 'Invalid JSON body');
    }

    $title      = isset($input['title']) ? strip_tags(trim($input['title'])) : $existing['_title'];
    $markdown   = $input['markdown'] ?? null;
    $icon       = $input['icon'] ?? ($existing['meta']['icon'] ?? '');
    $visibility = $input['visibility'] ?? ($existing['meta']['visibility'] ?? 'private');

    if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        api_error(400, 'Invalid visibility. Must be: private, unlisted, public');
    }

    $now = date('c');
    $blocks = $markdown !== null
        ? markdown_to_blocks($markdown)
        : ($existing['content']['blocks'] ?? []);

    $note_data = [
        'meta' => [
            'title'      => $title,
            'icon'       => $icon,
            'visibility' => $visibility,
            'created_at' => $existing['meta']['created_at'] ?? '',
            'updated_at' => $now,
        ],
        'content' => [
            'time'    => round(microtime(true) * 1000),
            'blocks'  => $blocks,
            'version' => $existing['content']['version'] ?? '2.31.0-rc.7',
        ],
    ];

    if (!save_note($relative, $note_data)) {
        api_error(500, 'Failed to update note');
    }

    api_json([
        'path'       => $path,
        'title'      => $title,
        'icon'       => $icon,
        'visibility' => $visibility,
        'created_at' => $note_data['meta']['created_at'],
        'updated_at' => $now,
        'markdown'   => blocks_to_markdown($blocks),
        'content'    => $note_data['content'],
    ]);
}

function api_patch_note(string $path): void {
    $relative = str_replace('/', DS, $path) . '.json';
    $note = get_note($relative);

    if (!$note) {
        api_error(404, 'Note not found');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        api_error(400, 'Invalid JSON body');
    }

    $visibility = $input['visibility'] ?? null;

    if ($visibility !== null) {
        if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
            api_error(400, 'Invalid visibility. Must be: private, unlisted, public');
        }
        if (!update_note_visibility($relative, $visibility)) {
            api_error(500, 'Failed to update visibility');
        }
    }

    // Re-read note after changes
    $note = get_note($relative);

    api_json([
        'path'       => $path,
        'title'      => $note['_title'],
        'icon'       => $note['meta']['icon'] ?? '',
        'visibility' => $note['meta']['visibility'] ?? 'private',
        'created_at' => $note['meta']['created_at'] ?? null,
        'updated_at' => $note['meta']['updated_at'] ?? null,
    ]);
}

function api_delete_note(string $path): void {
    $relative = str_replace('/', DS, $path) . '.json';

    if (!file_exists(get_notes_path() . DS . $relative)) {
        api_error(404, 'Note not found');
    }

    if (!delete_note($relative)) {
        api_error(500, 'Failed to delete note');
    }

    api_json(['deleted' => true, 'path' => $path]);
}

function api_search_notes(): void {
    $q = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');

    if (mb_strlen($q) < 2) {
        api_error(400, 'Query must be at least 2 characters');
    }

    $all = collect_all_notes();
    $results = [];

    foreach ($all as $note) {
        $title = $note['_title'];
        $icon  = $note['meta']['icon'] ?? '';
        $found_in_title = mb_strpos(mb_strtolower($title, 'UTF-8'), $q) !== false;

        $snippet = '';
        $found_in_content = false;

        if (!$found_in_title && !empty($note['content']['blocks'])) {
            $body = extract_plain_text($note['content']['blocks']);
            $pos  = mb_strpos(mb_strtolower($body, 'UTF-8'), $q);

            if ($pos !== false) {
                $found_in_content = true;
                $start = max(0, $pos - 60);
                $len   = mb_strlen($q) + 120;
                $raw   = mb_substr($body, $start, $len, 'UTF-8');
                $snippet = ($start > 0 ? '...' : '') . trim($raw) . '...';
            }
        }

        if ($found_in_title || $found_in_content) {
            $results[] = [
                'path'    => preg_replace('/\.json$/', '', $note['_file']),
                'title'   => $title,
                'icon'    => $icon,
                'snippet' => $snippet,
            ];
        }

        if (count($results) >= 20) break;
    }

    api_json(['results' => $results]);
}
