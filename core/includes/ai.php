<?php
if(!defined('ABSPATH')){exit;}

// ============================================================
// Constants
// ============================================================

const AI_MAX_TOOL_ITERATIONS = 5;
const AI_DEFAULT_MODELS = [
    'claude' => 'claude-sonnet-4-20250514',
    'openai' => 'gpt-4o-mini',
    'gemini' => 'gemini-2.0-flash',
];

// ============================================================
// Configuration
// ============================================================

function ai_get_config(): array {
    $provider = strtolower(trim(get_option('AI_PROVIDER', '')));
    $api_key  = trim(get_option('AI_API_KEY', ''));
    $model    = trim(get_option('AI_MODEL', ''));

    if(empty($provider) || empty($api_key)) {
        return ['error' => 'AI is not configured: AI_PROVIDER or AI_API_KEY is missing in Options'];
    }

    if(!in_array($provider, ['claude', 'openai', 'gemini'], true)) {
        return ['error' => 'Unknown AI provider: ' . $provider];
    }

    if(empty($model)) {
        $model = AI_DEFAULT_MODELS[$provider];
    }

    return ['provider' => $provider, 'api_key' => $api_key, 'model' => $model];
}

function ai_is_configured(): bool {
    return !empty(get_option('AI_PROVIDER')) && !empty(get_option('AI_API_KEY'));
}

// ============================================================
// System Prompt
// ============================================================

function ai_get_system_prompt(): string {
    $now = date('Y-m-d H:i');
    $base = HOME_URL . 'note/';
    return <<<PROMPT
You are an AI assistant for managing notes. Reply in English, friendly and concise.
Current time: {$now} (Kyiv time).
Base notes URL: {$base}

## Tools
- **notes_list** - list all notes with metadata
- **notes_search** - search by text
- **notes_get** - read note content
- **notes_create** - create a note
- **notes_update** - update a note
- **notes_delete** - delete a note

## Rules
1. Note paths do not include .json, for example: "proyekty/my-note". Notes can be nested, so the path includes the parent folder.
2. If you do not know the exact path, use notes_search or notes_list to find the note.
3. When creating a note, provide a meaningful title and an appropriate emoji icon.
4. When updating a note, first read the current content with notes_get. Change only what the user asks for and preserve the rest of the content, structure, heading levels, and formatting.
5. Before deleting, ask the user for confirmation.
6. Default visibility is private. Options: private, unlisted, public. Notes can be pinned (pinned: true); pinned notes appear in a separate group at the top of the dashboard.
7. Content uses Markdown (headings, lists, checklists, code, quotes).
8. Do not invent information. Work only with real data.
9. Whenever you mention, create, update, or show a note, always include a Markdown link to it: [Title]({$base}path/). Example: [Classic borshch]({$base}retsepty/borshch/).
10. When creating a child note with the folder parameter, you MUST then update the parent note via notes_get + notes_update, appending a page-link block to the end of its content in this format: [icon Title](note/path). Example: after creating "Genius.Space" with icon 🏆 in the navchannya folder, append: [🏆 Genius.Space](note/navchannya/genius-space). This is not a regular HTML link; it is the special page-block format.
PROMPT;
}

// ============================================================
// Tool Definitions
// ============================================================

function ai_get_tools(): array {
    return [
        [
            'name' => 'notes_list',
            'description' => 'Get a list of all notes with metadata (title, icon, date, path, visibility)',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
                'required' => [],
            ],
        ],
        [
            'name' => 'notes_search',
            'description' => 'Search notes by text query (searches titles and content)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search text (minimum 2 characters)'],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'notes_get',
            'description' => 'Get note content as Markdown by path',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Note path (for example: "proyekty/my-note")'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'notes_create',
            'description' => 'Create a new note',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title'      => ['type' => 'string', 'description' => 'Note title'],
                    'markdown'   => ['type' => 'string', 'description' => 'Content in Markdown format'],
                    'icon'       => ['type' => 'string', 'description' => 'Emoji icon (for example: "📝")'],
                    'folder'     => ['type' => 'string', 'description' => 'Parent folder slug (empty = root)'],
                    'visibility' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public'], 'description' => 'Visibility (default: private)'],
                ],
                'required' => ['title', 'markdown'],
            ],
        ],
        [
            'name' => 'notes_update',
            'description' => 'Update an existing note',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path'       => ['type' => 'string', 'description' => 'Note path'],
                    'title'      => ['type' => 'string', 'description' => 'New title'],
                    'markdown'   => ['type' => 'string', 'description' => 'New Markdown content'],
                    'icon'       => ['type' => 'string', 'description' => 'New emoji icon'],
                    'visibility' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public'], 'description' => 'New visibility'],
                    'pinned'     => ['type' => 'boolean', 'description' => 'Pin/unpin the note'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'notes_delete',
            'description' => 'Delete a note by path',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path of the note to delete'],
                ],
                'required' => ['path'],
            ],
        ],
    ];
}

// ============================================================
// Tool Execution
// ============================================================

function ai_execute_tool(string $name, array $args): array {
    try {
        $result = match($name) {
            'notes_list'   => ai_tool_notes_list(),
            'notes_search' => ai_tool_notes_search($args),
            'notes_get'    => ai_tool_notes_get($args),
            'notes_create' => ai_tool_notes_create($args),
            'notes_update' => ai_tool_notes_update($args),
            'notes_delete' => ai_tool_notes_delete($args),
            default        => throw new RuntimeException("Unknown tool: {$name}"),
        };
        return ['success' => true, 'result' => $result];
    } catch(\Throwable $e) {
        return ['success' => false, 'result' => $e->getMessage()];
    }
}

function ai_tool_notes_list(): array {
    $all = collect_all_notes();
    $notes = [];
    foreach($all as $note) {
        $notes[] = [
            'path'       => preg_replace('/\.json$/', '', $note['_file']),
            'title'      => $note['_title'],
            'icon'       => $note['meta']['icon'] ?? '',
            'visibility' => $note['meta']['visibility'] ?? 'private',
            'updated_at' => $note['meta']['updated_at'] ?? '',
        ];
    }
    return $notes;
}

function ai_tool_notes_search(array $args): array {
    $q = mb_strtolower(trim($args['query'] ?? ''), 'UTF-8');
    if(mb_strlen($q) < 2) {
        throw new RuntimeException('Query must be at least 2 characters');
    }

    $all = collect_all_notes();
    $results = [];

    foreach($all as $note) {
        $title = $note['_title'];
        $found_in_title = mb_strpos(mb_strtolower($title, 'UTF-8'), $q) !== false;
        $snippet = '';

        if(!$found_in_title && !empty($note['content']['blocks'])) {
            $body = extract_plain_text($note['content']['blocks']);
            $pos = mb_strpos(mb_strtolower($body, 'UTF-8'), $q);
            if($pos !== false) {
                $start = max(0, $pos - 60);
                $raw = mb_substr($body, $start, mb_strlen($q) + 120, 'UTF-8');
                $snippet = ($start > 0 ? '...' : '') . trim($raw) . '...';
            } else {
                continue;
            }
        } elseif(!$found_in_title) {
            continue;
        }

        $results[] = [
            'path'    => preg_replace('/\.json$/', '', $note['_file']),
            'title'   => $title,
            'icon'    => $note['meta']['icon'] ?? '',
            'snippet' => $snippet,
        ];

        if(count($results) >= 20) break;
    }

    return $results;
}

/**
 * Find note by path with fallback to slug/title search.
 * Returns [note, resolved_path] or throws if not found.
 */
function ai_resolve_note(string $path): array {
    $relative = str_replace('/', DS, $path) . '.json';
    $note = get_note($relative);

    if(!$note) {
        $slug = basename($path);
        $query = mb_strtolower($slug, 'UTF-8');
        $all = collect_all_notes();

        foreach($all as $n) {
            $file = preg_replace('/\.json$/', '', $n['_file']);
            if(basename($file) === $slug) {
                $note = $n;
                $path = str_replace(DS, '/', $file);
                break;
            }
        }

        if(!$note) {
            foreach($all as $n) {
                $title_lower = mb_strtolower($n['_title'], 'UTF-8');
                if($title_lower === $query || str_replace(['-', '_'], ' ', $query) === str_replace(['-', '_'], ' ', $title_lower)) {
                    $note = $n;
                    $path = str_replace(DS, '/', preg_replace('/\.json$/', '', $n['_file']));
                    break;
                }
            }
        }
    }

    if(!$note) {
        throw new RuntimeException("Note not found: {$path}");
    }

    return [$note, $path];
}

function ai_tool_notes_get(array $args): array {
    [$note, $path] = ai_resolve_note($args['path'] ?? '');

    return [
        'path'       => $path,
        'title'      => $note['_title'],
        'icon'       => $note['meta']['icon'] ?? '',
        'visibility' => $note['meta']['visibility'] ?? 'private',
        'markdown'   => blocks_to_markdown($note['content']['blocks'] ?? []),
    ];
}

function ai_tool_notes_create(array $args): array {
    $title      = strip_tags(trim($args['title'] ?? ''));
    $markdown   = $args['markdown'] ?? '';
    $folder     = trim($args['folder'] ?? '', '/ ');
    $icon       = $args['icon'] ?? '';
    $visibility = $args['visibility'] ?? 'private';

    if(empty($title)) {
        throw new RuntimeException('Title is required');
    }
    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        $visibility = 'private';
    }

    $slug = generate_slug($title);
    $dir_prefix = $folder ? $folder . '/' : '';
    $relative = $dir_prefix . $slug . '.json';

    $base_slug = $slug;
    $counter = 1;
    while(note_exists($relative)) {
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

    if(!save_note(str_replace('/', DS, $relative), $note_data)) {
        throw new RuntimeException('Failed to save note');
    }

    $path = preg_replace('/\.json$/', '', $relative);
    return ['path' => $path, 'title' => $title, 'icon' => $icon, 'visibility' => $visibility];
}

function ai_tool_notes_update(array $args): array {
    [$existing, $path] = ai_resolve_note($args['path'] ?? '');
    $relative = str_replace('/', DS, $path) . '.json';

    $title      = isset($args['title']) ? strip_tags(trim($args['title'])) : $existing['_title'];
    $markdown   = $args['markdown'] ?? null;
    $icon       = resolve_note_icon_value($args, $existing['meta']['icon'] ?? '');
    $visibility = $args['visibility'] ?? ($existing['meta']['visibility'] ?? 'private');
    $pinned     = isset($args['pinned']) ? (bool)$args['pinned'] : ($existing['meta']['pinned'] ?? false);

    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        $visibility = 'private';
    }

    $blocks = $markdown !== null
        ? markdown_to_blocks($markdown)
        : ($existing['content']['blocks'] ?? []);

    $note_data = [
        'meta' => array_merge($existing['meta'] ?? [], [
            'title'      => $title,
            'icon'       => $icon,
            'visibility' => $visibility,
            'pinned'     => $pinned,
            'updated_at' => date('c'),
        ]),
        'content' => [
            'time'    => round(microtime(true) * 1000),
            'blocks'  => $blocks,
            'version' => $existing['content']['version'] ?? '2.31.0-rc.7',
        ],
    ];

    if(!save_note($relative, $note_data)) {
        throw new RuntimeException('Failed to update note');
    }

    return ['path' => $path, 'title' => $title, 'icon' => $icon, 'visibility' => $visibility];
}

function ai_tool_notes_delete(array $args): array {
    [, $path] = ai_resolve_note($args['path'] ?? '');
    $relative = str_replace('/', DS, $path) . '.json';

    if(!delete_note($relative)) {
        throw new RuntimeException('Failed to delete note');
    }

    return ['deleted' => true, 'path' => $path];
}

// ============================================================
// Main Chat Function
// ============================================================

function ai_chat(array $messages): array {
    $config = ai_get_config();
    if(isset($config['error'])) {
        return ['reply' => null, 'messages' => $messages, 'error' => $config['error']];
    }

    $provider = $config['provider'];
    $api_key  = $config['api_key'];
    $model    = $config['model'];
    $system   = ai_get_system_prompt();
    $tools    = ai_get_tools();

    // Trim history to configured limit
    $history_limit = intval(get_option('AI_HISTORY_LIMIT', 20));
    if($history_limit > 0 && count($messages) > $history_limit) {
        $messages = array_slice($messages, -$history_limit);
        // Ensure we start with a genuine user message, not an orphaned tool result
        while(!empty($messages) && ($messages[0]['role'] !== 'user' || isset($messages[0]['_raw']))) {
            array_shift($messages);
        }
    }

    $working = $messages;
    $iteration = 0;
    $notes_changed = false;
    $modifying_tools = ['notes_create', 'notes_update', 'notes_delete'];

    while($iteration < AI_MAX_TOOL_ITERATIONS) {
        $body     = ai_format_request($provider, $working, $model, $system, $tools);
        $response = ai_send_request($provider, $body, $api_key, $model);

        if(isset($response['_error'])) {
            return ['reply' => null, 'messages' => $messages, 'error' => $response['_error'], 'notes_changed' => $notes_changed];
        }

        $parsed = ai_parse_response($provider, $response);

        if(empty($parsed['tool_calls'])) {
            $reply = $parsed['text'] ?? '';
            $working[] = ['role' => 'assistant', 'content' => $reply];
            return ['reply' => $reply, 'messages' => $working, 'error' => null, 'notes_changed' => $notes_changed];
        }

        // Execute tool calls
        $tool_results = [];
        foreach($parsed['tool_calls'] as $call) {
            if(in_array($call['name'], $modifying_tools, true)) {
                $notes_changed = true;
            }
            $result = ai_execute_tool($call['name'], $call['arguments']);
            $tool_results[$call['id']] = json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        // Append assistant + tool results for next iteration
        $loop_msgs = ai_format_tool_results($provider, $parsed, $tool_results, $response);
        foreach($loop_msgs as $msg) {
            $working[] = $msg;
        }

        $iteration++;
    }

    $fallback = 'Sorry, the maximum number of operations has been reached. Please refine your request.';
    $working[] = ['role' => 'assistant', 'content' => $fallback];
    return ['reply' => $fallback, 'messages' => $working, 'error' => 'max_iterations', 'notes_changed' => $notes_changed];
}

// ============================================================
// Provider: Request Formatting
// ============================================================

function ai_format_request(string $provider, array $messages, string $model, string $system, array $tools): array {
    return match($provider) {
        'claude' => ai_format_request_claude($messages, $model, $system, $tools),
        'openai' => ai_format_request_openai($messages, $model, $system, $tools),
        'gemini' => ai_format_request_gemini($messages, $model, $system, $tools),
    };
}

function ai_format_request_claude(array $messages, string $model, string $system, array $tools): array {
    $claude_messages = [];
    foreach($messages as $msg) {
        if(isset($msg['_raw'])) {
            $claude_messages[] = $msg['_raw'];
        } else {
            $claude_messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }

    $claude_tools = array_map(fn($t) => [
        'name'         => $t['name'],
        'description'  => $t['description'],
        'input_schema' => $t['parameters'],
    ], $tools);

    return [
        'model'      => $model,
        'max_tokens' => 4096,
        'system'     => $system,
        'messages'   => $claude_messages,
        'tools'      => $claude_tools,
    ];
}

function ai_format_request_openai(array $messages, string $model, string $system, array $tools): array {
    $oai_messages = [['role' => 'system', 'content' => $system]];
    foreach($messages as $msg) {
        if(isset($msg['_raw'])) {
            $oai_messages[] = $msg['_raw'];
        } else {
            $oai_messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }

    $oai_tools = array_map(fn($t) => [
        'type'     => 'function',
        'function' => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $t['parameters'],
        ],
    ], $tools);

    return [
        'model'    => $model,
        'messages' => $oai_messages,
        'tools'    => $oai_tools,
    ];
}

function ai_format_request_gemini(array $messages, string $model, string $system, array $tools): array {
    $contents = [];
    foreach($messages as $msg) {
        if(isset($msg['_raw'])) {
            $contents[] = $msg['_raw'];
        } else {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }
    }

    $func_decls = array_map(fn($t) => [
        'name'        => $t['name'],
        'description' => $t['description'],
        'parameters'  => $t['parameters'],
    ], $tools);

    return [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => $contents,
        'tools'              => [['function_declarations' => $func_decls]],
    ];
}

// ============================================================
// Provider: HTTP Transport
// ============================================================

function ai_send_request(string $provider, array $body, string $api_key, string $model): array {
    $url = match($provider) {
        'claude' => 'https://api.anthropic.com/v1/messages',
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key,
    };

    $headers = match($provider) {
        'claude' => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        'openai' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        'gemini' => [
            'Content-Type: application/json',
        ],
    };

    $json_body = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json_body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if($curl_error) {
        return ['_error' => 'Connection error: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if($data === null) {
        return ['_error' => 'Invalid response from AI'];
    }

    if($http_code !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
        return ['_error' => 'AI API error: ' . $msg];
    }

    return $data;
}

// ============================================================
// Provider: Response Parsing
// ============================================================

function ai_parse_response(string $provider, array $response): array {
    return match($provider) {
        'claude' => ai_parse_response_claude($response),
        'openai' => ai_parse_response_openai($response),
        'gemini' => ai_parse_response_gemini($response),
    };
}

function ai_parse_response_claude(array $response): array {
    $text = '';
    $tool_calls = [];

    foreach(($response['content'] ?? []) as $block) {
        if(($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        } elseif(($block['type'] ?? '') === 'tool_use') {
            $tool_calls[] = [
                'id'        => $block['id'],
                'name'      => $block['name'],
                'arguments' => $block['input'] ?? [],
            ];
        }
    }

    return ['text' => $text, 'tool_calls' => $tool_calls];
}

function ai_parse_response_openai(array $response): array {
    $choice = $response['choices'][0] ?? [];
    $message = $choice['message'] ?? [];
    $text = $message['content'] ?? '';
    $tool_calls = [];

    foreach(($message['tool_calls'] ?? []) as $tc) {
        $tool_calls[] = [
            'id'        => $tc['id'],
            'name'      => $tc['function']['name'],
            'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
        ];
    }

    return ['text' => $text, 'tool_calls' => $tool_calls];
}

function ai_parse_response_gemini(array $response): array {
    $parts = $response['candidates'][0]['content']['parts'] ?? [];
    $text = '';
    $tool_calls = [];

    foreach($parts as $part) {
        if(isset($part['text'])) {
            $text .= $part['text'];
        } elseif(isset($part['functionCall'])) {
            $tool_calls[] = [
                'id'        => 'gemini_' . uniqid(),
                'name'      => $part['functionCall']['name'],
                'arguments' => $part['functionCall']['args'] ?? [],
            ];
        }
    }

    return ['text' => $text, 'tool_calls' => $tool_calls];
}

// ============================================================
// Provider: Tool Result Formatting
// ============================================================

function ai_format_tool_results(string $provider, array $parsed, array $tool_results, array $raw_response): array {
    return match($provider) {
        'claude' => ai_format_tool_results_claude($parsed, $tool_results, $raw_response),
        'openai' => ai_format_tool_results_openai($parsed, $tool_results, $raw_response),
        'gemini' => ai_format_tool_results_gemini($parsed, $tool_results, $raw_response),
    };
}

function ai_format_tool_results_claude(array $parsed, array $tool_results, array $raw_response): array {
    // Claude: assistant message with content blocks, then user message with tool_result blocks
    $result_blocks = [];
    foreach($parsed['tool_calls'] as $call) {
        $result_blocks[] = [
            'type'        => 'tool_result',
            'tool_use_id' => $call['id'],
            'content'     => $tool_results[$call['id']] ?? '{}',
        ];
    }

    return [
        ['role' => 'assistant', '_raw' => ['role' => 'assistant', 'content' => $raw_response['content']]],
        ['role' => 'user', '_raw' => ['role' => 'user', 'content' => $result_blocks]],
    ];
}

function ai_format_tool_results_openai(array $parsed, array $tool_results, array $raw_response): array {
    $choice = $raw_response['choices'][0]['message'] ?? [];
    $msgs = [
        ['role' => 'assistant', '_raw' => $choice],
    ];

    foreach($parsed['tool_calls'] as $call) {
        $msgs[] = [
            'role' => 'tool',
            '_raw' => [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => $tool_results[$call['id']] ?? '{}',
            ],
        ];
    }

    return $msgs;
}

function ai_format_tool_results_gemini(array $parsed, array $tool_results, array $raw_response): array {
    // Gemini: model message with functionCall, then user message with functionResponse
    $model_parts = $raw_response['candidates'][0]['content']['parts'] ?? [];

    $response_parts = [];
    foreach($parsed['tool_calls'] as $call) {
        $result_data = json_decode($tool_results[$call['id']] ?? '{}', true);
        $response_parts[] = [
            'functionResponse' => [
                'name'     => $call['name'],
                'response' => $result_data,
            ],
        ];
    }

    return [
        ['role' => 'assistant', '_raw' => ['role' => 'model', 'parts' => $model_parts]],
        ['role' => 'user', '_raw' => ['role' => 'user', 'parts' => $response_parts]],
    ];
}
