<?php

if(!defined('ABSPATH')){exit;}

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

// ============================================================
// Passkeys (WebAuthn / FIDO2): Touch ID, Face ID, Windows Hello, hardware keys.
// Single admin user — every credential in the `passkeys` table signs in as
// AUTH_USER. Registration happens from Options → Account, sign-in from the
// login page. Challenges are kept in the session, single-use, 5 minutes.
// ============================================================

const PASSKEY_CHALLENGE_TTL = 300;

/** Relying party id = the host notes are served from (passkeys are bound to it). */
function passkey_rp_id(): string {
    $host = (string)parse_url(HOME_URL, PHP_URL_HOST);
    return $host !== '' ? $host : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function passkey_webauthn(): WebAuthn {
    // 'none' attestation: the platform authenticator is trusted as-is (no FIDO metadata);
    // base64url JSON so the browser can decode challenge / ids with plain helpers
    return new WebAuthn(SITE_NAME, passkey_rp_id(), ['none'], true);
}

/** Opaque, stable WebAuthn user handle — random once, then kept in options. */
function passkey_user_handle(): string {
    $hex = (string)get_option('PASSKEY_USER_HANDLE', '');
    if(!preg_match('/^[0-9a-f]{32}$/', $hex)) {
        $hex = bin2hex(random_bytes(16));
        set_option('PASSKEY_USER_HANDLE', $hex);
    }
    return (string)hex2bin($hex);
}

function passkey_b64url_decode(string $value): string|false {
    $value = strtr(trim($value), '-_', '+/');
    $pad = strlen($value) % 4;
    if($pad) $value .= str_repeat('=', 4 - $pad);
    return base64_decode($value, true);
}

function passkey_b64url_encode(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** "macOS · Safari" style label derived from the User-Agent of the registering browser. */
function passkey_device_name(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = match(true) {
        str_contains($ua, 'iPhone')                                          => 'iPhone',
        str_contains($ua, 'iPad')                                            => 'iPad',
        str_contains($ua, 'Android')                                         => 'Android',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')      => 'macOS',
        str_contains($ua, 'Windows')                                         => 'Windows',
        str_contains($ua, 'CrOS')                                            => 'ChromeOS',
        str_contains($ua, 'Linux')                                           => 'Linux',
        default                                                              => 'Device',
    };
    $browser = match(true) {
        str_contains($ua, 'Edg/')                                            => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')              => 'Opera',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/')         => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/')          => 'Chrome',
        str_contains($ua, 'Safari/')                                         => 'Safari',
        default                                                              => '',
    };
    return $browser !== '' ? $os . ' · ' . $browser : $os;
}

// ------------------------------------------------------------
// Storage
// ------------------------------------------------------------

function passkeys_list(): array {
    $rows = get_db()->query("SELECT id, name, created_at, last_used_at FROM passkeys ORDER BY created_at DESC, id DESC")->fetchAll();
    return array_map(fn($r) => [
        'id'           => (int)$r['id'],
        'name'         => (string)$r['name'],
        'created_at'   => (string)$r['created_at'],
        'last_used_at' => $r['last_used_at'] !== null ? (string)$r['last_used_at'] : null,
    ], $rows);
}

function passkey_count(): int {
    return (int)get_db()->query("SELECT COUNT(*) FROM passkeys")->fetchColumn();
}

/** @return ByteBuffer[] every registered credential id */
function passkey_credential_ids(): array {
    $ids = [];
    foreach(get_db()->query("SELECT credential_id FROM passkeys") as $row) {
        $ids[] = new ByteBuffer((string)$row['credential_id']);
    }
    return $ids;
}

function passkey_delete(int $id): bool {
    $stmt = get_db()->prepare("DELETE FROM passkeys WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// ------------------------------------------------------------
// Challenges (session, single-use, purpose-bound)
// ------------------------------------------------------------

function passkey_challenge_store(string $purpose, ByteBuffer $challenge): void {
    $_SESSION['passkey_challenge'] = [
        'purpose' => $purpose,
        'hex'     => $challenge->getHex(),
        'expires' => time() + PASSKEY_CHALLENGE_TTL,
    ];
}

/** Take (and forget) the pending challenge; null when missing, for another purpose or expired. */
function passkey_challenge_take(string $purpose): ?ByteBuffer {
    $c = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if(!is_array($c) || ($c['purpose'] ?? '') !== $purpose || (int)($c['expires'] ?? 0) < time()) return null;
    $hex = (string)($c['hex'] ?? '');
    if($hex === '' || !ctype_xdigit($hex)) return null;
    return ByteBuffer::fromHex($hex);
}

// ------------------------------------------------------------
// Registration (Options → Account, authenticated session)
// ------------------------------------------------------------

/** PublicKeyCredentialCreationOptions for navigator.credentials.create(), JSON-ready. */
function passkey_register_options(): object {
    $wa = passkey_webauthn();
    $user = (string)get_option('AUTH_USER', '');
    if($user === '') $user = 'admin';

    // resident (discoverable) key + user verification: the passkey alone is the login,
    // so the authenticator must check the biometric / PIN, and username-less sign-in works
    $args = $wa->getCreateArgs(passkey_user_handle(), $user, $user, 60, true, true, null, passkey_credential_ids());
    passkey_challenge_store('register', $wa->getChallenge());
    return $args;
}

/** Verify the attestation from the browser and store the credential. */
function passkey_register_finish(array $input): array {
    $client = passkey_b64url_decode((string)($input['clientDataJSON'] ?? ''));
    $attestation = passkey_b64url_decode((string)($input['attestationObject'] ?? ''));
    $challenge = passkey_challenge_take('register');

    if($client === false || $attestation === false || $client === '' || $attestation === '' || $challenge === null) {
        return ['success' => false, 'error' => 'Registration expired — try again'];
    }

    try {
        $data = passkey_webauthn()->processCreate($client, $attestation, $challenge, true, true, false);
    } catch(\Throwable $e) {
        write_log('Passkey registration failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Passkey could not be verified'];
    }

    $credential_id = $data->credentialId instanceof ByteBuffer ? $data->credentialId->getBinaryString() : (string)$data->credentialId;
    $public_key = (string)($data->credentialPublicKey ?? '');
    if($credential_id === '' || $public_key === '') {
        return ['success' => false, 'error' => 'Passkey could not be verified'];
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM passkeys WHERE credential_id = ?");
    $stmt->execute([$credential_id]);
    if($stmt->fetch()) {
        return ['success' => false, 'error' => 'This passkey is already registered'];
    }

    $name = trim(strip_tags((string)($input['name'] ?? '')));
    if($name === '') $name = passkey_device_name();
    $name = mb_substr($name, 0, 80, 'UTF-8');

    $db->prepare("INSERT INTO passkeys (name, credential_id, public_key, sign_count, created_at) VALUES (?, ?, ?, ?, ?)")
       ->execute([$name, $credential_id, $public_key, (int)($data->signatureCounter ?? 0), date('Y-m-d H:i:s')]);

    return ['success' => true, 'passkeys' => passkeys_list()];
}

// ------------------------------------------------------------
// Sign-in (login page, no session yet)
// ------------------------------------------------------------

/** PublicKeyCredentialRequestOptions for navigator.credentials.get(); null when no passkey exists. */
function passkey_login_options(): ?object {
    $ids = passkey_credential_ids();
    if(!$ids) return null;

    $wa = passkey_webauthn();
    $args = $wa->getGetArgs($ids, 60, true, true, true, true, true, true);
    passkey_challenge_store('login', $wa->getChallenge());
    return $args;
}

/** Verify the assertion; on success the session is authenticated as the admin user. */
function passkey_login_finish(array $input, bool $remember = false): bool {
    $credential_id = passkey_b64url_decode((string)($input['id'] ?? ''));
    $client = passkey_b64url_decode((string)($input['clientDataJSON'] ?? ''));
    $auth_data = passkey_b64url_decode((string)($input['authenticatorData'] ?? ''));
    $signature = passkey_b64url_decode((string)($input['signature'] ?? ''));
    $challenge = passkey_challenge_take('login');

    foreach([$credential_id, $client, $auth_data, $signature] as $part) {
        if($part === false || $part === '') return false;
    }
    if($challenge === null) return false;

    $db = get_db();
    $stmt = $db->prepare("SELECT id, public_key, sign_count FROM passkeys WHERE credential_id = ?");
    $stmt->execute([$credential_id]);
    $row = $stmt->fetch();
    if(!$row) return false;

    try {
        $wa = passkey_webauthn();
        $wa->processGet($client, $auth_data, $signature, (string)$row['public_key'], $challenge, (int)$row['sign_count'], true, true);
        $counter = (int)($wa->getSignatureCounter() ?? 0);
    } catch(\Throwable $e) {
        write_log('Passkey sign-in failed: ' . $e->getMessage());
        return false;
    }

    $db->prepare("UPDATE passkeys SET sign_count = ?, last_used_at = ? WHERE id = ?")
       ->execute([max($counter, (int)$row['sign_count']), date('Y-m-d H:i:s'), (int)$row['id']]);

    $user = (string)get_option('AUTH_USER', '');
    if(session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['authenticated'] = true;
    $_SESSION['user'] = $user !== '' ? $user : 'admin';

    if($remember) {
        remember_generate_token($_SESSION['user']);
    }
    return true;
}
