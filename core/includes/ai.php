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
    $env = get_env();
    $provider = strtolower(trim($env['AI_PROVIDER'] ?? ''));
    $api_key  = trim($env['AI_API_KEY'] ?? '');
    $model    = trim($env['AI_MODEL'] ?? '');

    if(empty($provider) || empty($api_key)) {
        return ['error' => 'AI не налаштовано: відсутній AI_PROVIDER або AI_API_KEY в .env'];
    }

    if(!in_array($provider, ['claude', 'openai', 'gemini'], true)) {
        return ['error' => 'Невідомий AI провайдер: ' . $provider];
    }

    if(empty($model)) {
        $model = AI_DEFAULT_MODELS[$provider];
    }

    return ['provider' => $provider, 'api_key' => $api_key, 'model' => $model];
}

function ai_is_configured(): bool {
    $env = get_env();
    return !empty($env['AI_PROVIDER']) && !empty($env['AI_API_KEY']);
}

// ============================================================
// System Prompt
// ============================================================

function ai_get_system_prompt(): string {
    $now = date('Y-m-d H:i');
    $base = HOME_URL . 'note/';
    return <<<PROMPT
Ти — AI-асистент для управління нотатками. Відповідай українською, дружньо та стисло.
Поточний час: {$now} (Київський час).
Базова URL нотаток: {$base}

## Інструменти
- **notes_list** — список усіх нотаток
- **notes_search** — пошук за текстом
- **notes_get** — прочитати вміст нотатки
- **notes_create** — створити нотатку
- **notes_update** — оновити нотатку
- **notes_delete** — видалити нотатку

## Правила
1. Шляхи нотаток без .json (наприклад: "proyekty/my-note"). Нотатки бувають вкладені — шлях включає батьківську папку.
2. Якщо не знаєш точний шлях — використай notes_search або notes_list щоб знайти нотатку.
3. При створенні — давай змістовний заголовок та відповідну emoji іконку.
4. При оновленні — спочатку прочитай поточний вміст через notes_get. Зміни лише те, що просить користувач, зберігаючи решту вмісту, структуру, рівні заголовків та форматування без змін.
5. Перед видаленням — запитай підтвердження у користувача.
6. Видимість за замовчуванням: private. Варіанти: private, unlisted, public.
7. Контент у форматі Markdown (заголовки, списки, чеклісти, код, цитати).
8. Не вигадуй — працюй тільки з реальними даними.
9. Коли згадуєш, створюєш, оновлюєш або показуєш нотатку — завжди додавай посилання на неї у форматі markdown: [Назва]({$base}path/). Наприклад: [Борщ класичний]({$base}retsepty/borshch/).
10. Коли створюєш дочірню нотатку (з параметром folder) — після створення ОБОВ'ЯЗКОВО оновити батьківську нотатку через notes_get + notes_update, додавши в кінець її вмісту блок-посилання на створену нотатку у форматі: [іконка Назва](note/шлях). Наприклад: створив нотатку "Genius.Space" з іконкою 🏆 у папці navchannya → додай в кінець батьківської нотатки рядок: [🏆 Genius.Space](note/navchannya/genius-space). Це НЕ звичайне HTML-посилання, а спеціальний формат блоку-сторінки.
PROMPT;
}

// ============================================================
// Tool Definitions
// ============================================================

function ai_get_tools(): array {
    return [
        [
            'name' => 'notes_list',
            'description' => 'Отримати список усіх нотаток з метаданими (назва, іконка, дата, шлях, видимість)',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
                'required' => [],
            ],
        ],
        [
            'name' => 'notes_search',
            'description' => 'Пошук нотаток за текстовим запитом (шукає в заголовках та вмісті)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Текст для пошуку (мінімум 2 символи)'],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'notes_get',
            'description' => 'Отримати вміст нотатки у форматі markdown за її шляхом',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Шлях до нотатки (наприклад: "proyekty/my-note")'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'notes_create',
            'description' => 'Створити нову нотатку',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title'      => ['type' => 'string', 'description' => 'Заголовок нотатки'],
                    'markdown'   => ['type' => 'string', 'description' => 'Вміст у форматі markdown'],
                    'icon'       => ['type' => 'string', 'description' => 'Emoji іконка (наприклад: "📝")'],
                    'folder'     => ['type' => 'string', 'description' => 'Slug батьківської папки (порожнє = корінь)'],
                    'visibility' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public'], 'description' => 'Видимість (за замовчуванням: private)'],
                ],
                'required' => ['title', 'markdown'],
            ],
        ],
        [
            'name' => 'notes_update',
            'description' => 'Оновити існуючу нотатку',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path'       => ['type' => 'string', 'description' => 'Шлях до нотатки'],
                    'title'      => ['type' => 'string', 'description' => 'Новий заголовок'],
                    'markdown'   => ['type' => 'string', 'description' => 'Новий вміст у markdown'],
                    'icon'       => ['type' => 'string', 'description' => 'Нова emoji іконка'],
                    'visibility' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public'], 'description' => 'Нова видимість'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'notes_delete',
            'description' => 'Видалити нотатку за шляхом',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Шлях до нотатки для видалення'],
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
            default        => throw new RuntimeException("Невідомий інструмент: {$name}"),
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
        throw new RuntimeException('Запит має містити мінімум 2 символи');
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

function ai_tool_notes_get(array $args): array {
    $path = $args['path'] ?? '';
    $relative = str_replace('/', DS, $path) . '.json';
    $note = get_note($relative);

    // Fallback: search by slug or title among all notes
    if(!$note) {
        $slug = basename($path);
        $query = mb_strtolower($slug, 'UTF-8');
        $all = collect_all_notes();

        foreach($all as $n) {
            $file = preg_replace('/\.json$/', '', $n['_file']);
            // Match by slug (end of path)
            if(basename($file) === $slug) {
                $note = $n;
                $path = str_replace(DS, '/', $file);
                break;
            }
        }

        // Still not found — try fuzzy title match
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
        throw new RuntimeException("Нотатку не знайдено: {$path}");
    }

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
        throw new RuntimeException('Заголовок обов\'язковий');
    }
    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        $visibility = 'private';
    }

    $slug = generate_slug($title);
    $dir_prefix = $folder ? $folder . '/' : '';
    $relative = $dir_prefix . $slug . '.json';

    $base_slug = $slug;
    $counter = 1;
    while(file_exists(get_notes_path() . DS . str_replace('/', DS, $relative))) {
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
        throw new RuntimeException('Не вдалося зберегти нотатку');
    }

    $path = preg_replace('/\.json$/', '', $relative);
    return ['path' => $path, 'title' => $title, 'icon' => $icon, 'visibility' => $visibility];
}

function ai_tool_notes_update(array $args): array {
    $path = $args['path'] ?? '';
    $relative = str_replace('/', DS, $path) . '.json';
    $existing = get_note($relative);

    if(!$existing) {
        throw new RuntimeException("Нотатку не знайдено: {$path}");
    }

    $title      = isset($args['title']) ? strip_tags(trim($args['title'])) : $existing['_title'];
    $markdown   = $args['markdown'] ?? null;
    $icon       = $args['icon'] ?? ($existing['meta']['icon'] ?? '');
    $visibility = $args['visibility'] ?? ($existing['meta']['visibility'] ?? 'private');

    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
        $visibility = 'private';
    }

    $blocks = $markdown !== null
        ? markdown_to_blocks($markdown)
        : ($existing['content']['blocks'] ?? []);

    $note_data = [
        'meta' => [
            'title'      => $title,
            'icon'       => $icon,
            'visibility' => $visibility,
            'created_at' => $existing['meta']['created_at'] ?? '',
            'updated_at' => date('c'),
        ],
        'content' => [
            'time'    => round(microtime(true) * 1000),
            'blocks'  => $blocks,
            'version' => $existing['content']['version'] ?? '2.31.0-rc.7',
        ],
    ];

    if(!save_note($relative, $note_data)) {
        throw new RuntimeException('Не вдалося оновити нотатку');
    }

    return ['path' => $path, 'title' => $title, 'icon' => $icon, 'visibility' => $visibility];
}

function ai_tool_notes_delete(array $args): array {
    $path = $args['path'] ?? '';
    $relative = str_replace('/', DS, $path) . '.json';

    if(!file_exists(get_notes_path() . DS . $relative)) {
        throw new RuntimeException("Нотатку не знайдено: {$path}");
    }

    if(!delete_note($relative)) {
        throw new RuntimeException('Не вдалося видалити нотатку');
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
    $env = get_env();
    $history_limit = intval($env['AI_HISTORY_LIMIT'] ?? 20);
    if($history_limit > 0 && count($messages) > $history_limit) {
        $messages = array_slice($messages, -$history_limit);
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

    $fallback = 'Вибачте, виконано максимальну кількість операцій. Спробуйте уточнити запит.';
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
        return ['_error' => 'Помилка з\'єднання: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if($data === null) {
        return ['_error' => 'Невалідна відповідь від AI'];
    }

    if($http_code !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
        return ['_error' => 'AI API помилка: ' . $msg];
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
