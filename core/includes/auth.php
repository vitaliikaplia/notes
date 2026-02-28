<?php

if(!defined('ABSPATH')){exit;}

function parse_env($path) {
    if(!file_exists($path)) return [];

    $data = [];
    $lines = explode("\n", trim(file_get_contents($path)));

    foreach($lines as $line) {
        $line = trim($line);
        if($line === '' || str_starts_with($line, '#')) continue;
        if(preg_match('/^([A-Z_]+)\s*=\s*(.+)$/', $line, $m)) {
            $data[$m[1]] = trim($m[2], '"\'');
        }
    }

    return $data;
}

function get_env() {
    static $env = null;
    if($env === null) {
        $env = parse_env(ABSPATH . DS . '.env');
    }
    return $env;
}

// --- Remember me ---

define('REMEMBER_DIR', ABSPATH . DS . '.remember_tokens');
define('REMEMBER_COOKIE', 'remember_token');
define('REMEMBER_DAYS', 30);

function remember_generate_token(string $user): void {
    if(!is_dir(REMEMBER_DIR)) {
        mkdir(REMEMBER_DIR, 0700, true);
    }

    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $file  = REMEMBER_DIR . DS . $hash . '.json';

    file_put_contents($file, json_encode([
        'user'    => $user,
        'expires' => time() + (REMEMBER_DAYS * 86400),
    ]));

    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => time() + (REMEMBER_DAYS * 86400),
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

function remember_validate(): bool {
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if(!$token) return false;

    $hash = hash('sha256', $token);
    $file = REMEMBER_DIR . DS . $hash . '.json';

    if(!file_exists($file)) return false;

    $data = json_decode(file_get_contents($file), true);
    if(!$data || ($data['expires'] ?? 0) < time()) {
        // Протермінований — видаляємо
        @unlink($file);
        remember_clear_cookie();
        return false;
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['user'] = $data['user'];
    return true;
}

function remember_clear(): void {
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if($token) {
        $hash = hash('sha256', $token);
        $file = REMEMBER_DIR . DS . $hash . '.json';
        if(file_exists($file)) {
            @unlink($file);
        }
    }
    remember_clear_cookie();
}

function remember_clear_cookie(): void {
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

// --- Auth ---

function auth_check(): bool {
    if(!empty($_SESSION['authenticated'])) {
        return true;
    }
    return remember_validate();
}

function auth_login(string $user, string $pass, bool $remember = false): bool {
    $env = get_env();
    $valid_user = $env['AUTH_USER'] ?? '';
    $valid_pass = $env['AUTH_PASS'] ?? '';

    if($user === $valid_user && $pass === $valid_pass) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = $user;

        if($remember) {
            remember_generate_token($user);
        }

        return true;
    }

    return false;
}

function auth_logout(): void {
    remember_clear();
    session_destroy();
    header('Location: ' . HOME_URL . 'login/');
    exit;
}

function auth_require(): void {
    if(!auth_check()) {
        header('Location: ' . HOME_URL . 'login/');
        exit;
    }
}

function verify_turnstile(string $secret, string $token): bool {
    $response = file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
            'timeout' => 5,
        ]
    ]));

    if(!$response) return false;

    $result = json_decode($response, true);
    return !empty($result['success']);
}
