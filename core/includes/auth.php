<?php

if(!defined('ABSPATH')){exit;}

function get_env() {
    static $env = null;
    if($env === null) {
        // Config lives in config.php (gitignored). It is served as PHP, so a direct
        // HTTP request executes it to nothing instead of leaking secrets like .env did.
        $file = ABSPATH . DS . 'config.php';
        $env = is_file($file) ? require $file : [];
        if(!is_array($env)) $env = [];
    }
    return $env;
}

// --- Remember me ---

define('REMEMBER_COOKIE', 'remember_token');
define('REMEMBER_DAYS', 30);

function remember_generate_token(string $user): void {
    $token   = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $token);
    $expires = time() + (REMEMBER_DAYS * 86400);

    // Store only the token hash, never the raw token.
    get_db()->prepare(
        "INSERT INTO remember_tokens (token_hash, user, expires) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE user = VALUES(user), expires = VALUES(expires)"
    )->execute([$hash, $user, $expires]);

    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $expires,
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
    $stmt = get_db()->prepare("SELECT user, expires FROM remember_tokens WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if(!$row) return false;

    if((int)$row['expires'] < time()) {
        get_db()->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$hash]);
        remember_clear_cookie();
        return false;
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['user'] = $row['user'];
    return true;
}

function remember_clear(): void {
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if($token) {
        $hash = hash('sha256', $token);
        get_db()->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$hash]);
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

/** Hash a plaintext admin password for storage (bcrypt, like modern WordPress). */
function auth_hash_password(string $plain): string {
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** Persist a new admin password (hashed) into the options table. */
function auth_set_password(string $plain): bool {
    return set_option('AUTH_PASS', auth_hash_password($plain));
}

/**
 * Verify a password against the stored AUTH_PASS option.
 * Transparent upgrade: a legacy/plaintext value is accepted once and immediately
 * re-saved as a hash, and an outdated hash is rehashed on a successful login.
 */
function auth_verify_password(string $pass, string $stored): bool {
    if($stored === '') return false;

    $is_hash = (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';

    if($is_hash) {
        if(!password_verify($pass, $stored)) return false;
        if(password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            set_option('AUTH_PASS', auth_hash_password($pass));
        }
        return true;
    }

    // Legacy plaintext value — accept once, then upgrade to a hash
    if(hash_equals($stored, $pass)) {
        set_option('AUTH_PASS', auth_hash_password($pass));
        return true;
    }

    return false;
}

function auth_login(string $user, string $pass, bool $remember = false): bool {
    $valid_user = get_option('AUTH_USER', '');
    $valid_hash = get_option('AUTH_PASS', '');

    if($valid_user === '' || $valid_hash === '') return false;

    // hash_equals on the username avoids leaking it via timing; password is verified with bcrypt
    if(hash_equals($valid_user, $user) && auth_verify_password($pass, $valid_hash)) {
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
