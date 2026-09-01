<?php

if(!defined('ABSPATH')){exit;}

// ============================================================
// MCP server — Model Context Protocol over Streamable HTTP.
//
// Stateless JSON-RPC 2.0 endpoint at /mcp. Exposes the same
// notes tools the AI chat uses (ai_get_tools/ai_execute_tool),
// authenticated with a bearer token stored as a SHA-256 hash
// in the options table (MCP_TOKEN_HASH).
// ============================================================

const MCP_SERVER_VERSION = '1.0.0';
const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

// ============================================================
// Token management
// ============================================================

function mcp_token_hint(string $token): string {
    // Short legacy tokens would be fully revealed by first+last chars
    if(strlen($token) < 12) {
        return '••••';
    }
    return substr($token, 0, 5) . '…' . substr($token, -4);
}

/**
 * Current token hash. Transparently migrates the legacy plaintext
 * REST token (API_TOKEN) into a hashed MCP token on first use, so an
 * already-configured token keeps working after the upgrade.
 */
function mcp_get_token_hash(): string {
    $hash = trim((string)get_option('MCP_TOKEN_HASH', ''));
    if($hash !== '') {
        return $hash;
    }

    $legacy = trim((string)get_option('API_TOKEN', ''));
    if($legacy !== '') {
        $hash = hash('sha256', $legacy);
        set_option('MCP_TOKEN_HASH', $hash);
        set_option('MCP_TOKEN_HINT', mcp_token_hint($legacy));
        set_option('MCP_TOKEN_CREATED_AT', date('c'));
        delete_option('API_TOKEN');
        return $hash;
    }

    return '';
}

function mcp_generate_token(): string {
    $token = 'nmcp_' . bin2hex(random_bytes(24));
    set_option('MCP_TOKEN_HASH', hash('sha256', $token));
    set_option('MCP_TOKEN_HINT', mcp_token_hint($token));
    set_option('MCP_TOKEN_CREATED_AT', date('c'));
    delete_option('API_TOKEN');
    return $token;
}

function mcp_revoke_token(): void {
    delete_option('MCP_TOKEN_HASH');
    delete_option('MCP_TOKEN_HINT');
    delete_option('MCP_TOKEN_CREATED_AT');
    delete_option('API_TOKEN');
}

function mcp_token_status(): array {
    $configured = mcp_get_token_hash() !== '';
    return [
        'configured' => $configured,
        'hint'       => $configured ? (string)get_option('MCP_TOKEN_HINT', '') : '',
        'created_at' => $configured ? (string)get_option('MCP_TOKEN_CREATED_AT', '') : '',
    ];
}

// ============================================================
// Auth
// ============================================================

function mcp_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if($header === '' && function_exists('getallheaders')) {
        foreach(getallheaders() as $name => $value) {
            if(strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }

    if(preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    return '';
}

function mcp_auth(): bool {
    $hash = mcp_get_token_hash();
    if($hash === '') {
        return false;
    }

    $token = mcp_bearer_token();
    if($token === '') {
        return false;
    }

    return hash_equals($hash, hash('sha256', $token));
}

// ============================================================
// HTTP transport
// ============================================================

function mcp_http_error(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => ['code' => $status, 'message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

function mcp_output(mixed $body): never {
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_dispatch(): never {
    // CORS for browser-based clients (e.g. MCP Inspector)
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');

    $method = $_SERVER['REQUEST_METHOD'];

    if($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if($method !== 'POST') {
        // Stateless server: no SSE stream to GET, no session to DELETE
        header('Allow: POST, OPTIONS');
        mcp_http_error(405, 'Method not allowed. MCP messages go over POST (Streamable HTTP).');
    }

    if(!mcp_auth()) {
        header('WWW-Authenticate: Bearer realm="MCP"');
        mcp_http_error(401, 'Unauthorized');
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if(!is_array($payload)) {
        mcp_output(mcp_error_response(null, -32700, 'Parse error: invalid JSON'));
    }

    // A batch (JSON array) is processed message by message
    $is_batch = array_is_list($payload);
    if($is_batch && empty($payload)) {
        mcp_output(mcp_error_response(null, -32600, 'Invalid request: empty batch'));
    }
    $messages = $is_batch ? $payload : [$payload];

    $responses = [];
    foreach($messages as $message) {
        $response = mcp_handle_message(is_array($message) ? $message : null);
        if($response !== null) {
            $responses[] = $response;
        }
    }

    if(empty($responses)) {
        // Notifications only — acknowledge with no body
        http_response_code(202);
        exit;
    }

    mcp_output($is_batch ? $responses : $responses[0]);
}

// ============================================================
// JSON-RPC handling
// ============================================================

function mcp_error_response(mixed $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcp_result_response(mixed $id, mixed $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** Handle one JSON-RPC message; returns a response array or null (notification/response). */
function mcp_handle_message(?array $message): ?array {
    if($message === null || !isset($message['method']) || !is_string($message['method'])) {
        // A client response (result/error) needs no reply; anything else is invalid
        if(is_array($message) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            return null;
        }
        return mcp_error_response($message['id'] ?? null, -32600, 'Invalid request');
    }

    $method = $message['method'];
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    $is_notification = !array_key_exists('id', $message);
    $id = $message['id'] ?? null;

    if(str_starts_with($method, 'notifications/')) {
        return null;
    }

    $result = match($method) {
        'initialize'                => mcp_method_initialize($params),
        'ping'                      => (object)[],
        'tools/list'                => mcp_method_tools_list(),
        'tools/call'                => mcp_method_tools_call($params),
        'resources/list'            => ['resources' => []],
        'resources/templates/list'  => ['resourceTemplates' => []],
        'prompts/list'              => ['prompts' => []],
        default                     => mcp_error_response($id, -32601, 'Method not found: ' . $method),
    };

    if($is_notification) {
        return null;
    }

    // mcp_method_* may return a ready error response (jsonrpc key set)
    if(is_array($result) && isset($result['jsonrpc'])) {
        $result['id'] = $id;
        return $result;
    }

    return mcp_result_response($id, $result);
}

// ============================================================
// Methods
// ============================================================

function mcp_method_initialize(array $params): array {
    $requested = (string)($params['protocolVersion'] ?? '');
    $version = in_array($requested, MCP_PROTOCOL_VERSIONS, true) ? $requested : MCP_PROTOCOL_VERSIONS[0];

    return [
        'protocolVersion' => $version,
        'capabilities' => [
            'tools' => ['listChanged' => false],
        ],
        'serverInfo' => [
            'name'    => 'notes',
            'title'   => SITE_NAME,
            'version' => MCP_SERVER_VERSION,
        ],
        'instructions' => 'Self-hosted notes. Note paths never include .json and can be nested, e.g. "projects/my-note". '
            . 'Content is exchanged as Markdown. Use notes_search or notes_list to find a path before reading or updating a note. '
            . 'Default visibility is private; options are private, unlisted, public.',
    ];
}

function mcp_tool_annotations(string $name): array {
    return match($name) {
        'notes_list', 'notes_search', 'notes_get' => ['readOnlyHint' => true],
        'notes_create' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'notes_update' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        'notes_delete' => ['readOnlyHint' => false, 'destructiveHint' => true],
        default        => [],
    };
}

function mcp_method_tools_list(): array {
    $tools = [];
    foreach(ai_get_tools() as $tool) {
        $tools[] = [
            'name'        => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $tool['parameters'],
            'annotations' => mcp_tool_annotations($tool['name']),
        ];
    }
    return ['tools' => $tools];
}

function mcp_method_tools_call(array $params): array {
    $name = (string)($params['name'] ?? '');
    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

    $known = array_column(ai_get_tools(), 'name');
    if(!in_array($name, $known, true)) {
        return mcp_error_response(null, -32602, 'Unknown tool: ' . $name);
    }

    $execution = ai_execute_tool($name, $args);

    $text = is_string($execution['result'])
        ? $execution['result']
        : json_encode($execution['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return [
        'content' => [
            ['type' => 'text', 'text' => (string)$text],
        ],
        'isError' => !$execution['success'],
    ];
}
