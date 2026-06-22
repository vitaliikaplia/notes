<?php

// Copy this file to config.php and fill in real values:
//   cp config.example.php config.php
//
// config.php is gitignored and served as PHP (executes to nothing), so secrets
// are never exposed as plaintext the way a web-served .env would be.

return [
    // Admin login
    'AUTH_USER' => 'changeme',
    'AUTH_PASS' => 'changeme',

    // Cloudflare Turnstile CAPTCHA (leave empty to disable)
    'CAPTCHA_SITE_KEY'   => '',
    'CAPTCHA_SECRET_KEY' => '',

    // REST API bearer token (leave empty to disable the API)
    'API_TOKEN' => '',

    // AI assistant: claude | openai | gemini (leave provider empty to disable)
    'AI_PROVIDER'      => '',
    'AI_API_KEY'       => '',
    'AI_MODEL'         => '',
    'AI_HISTORY_LIMIT' => '20',

    // Optional Redis Unix socket (leave empty to use in-memory/no cache)
    'REDIS_SOCKET' => '',
];
