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

function auth_check(): bool {
    return !empty($_SESSION['authenticated']);
}

function auth_login($user, $pass): bool {
    $env = get_env();
    $valid_user = $env['AUTH_USER'] ?? '';
    $valid_pass = $env['AUTH_PASS'] ?? '';

    if($user === $valid_user && $pass === $valid_pass) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = $user;
        return true;
    }

    return false;
}

function auth_logout(): void {
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
