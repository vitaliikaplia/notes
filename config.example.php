<?php

// Copy this file to config.php and fill in your database connection:
//   cp config.example.php config.php
//
// config.php holds ONLY the database connection. It is served as PHP (a direct
// request executes it to nothing), so nothing leaks as plaintext. Every other
// setting — the admin login (AUTH_USER/AUTH_PASS), REST API token, CAPTCHA, AI
// provider/key, Redis socket — lives in the DB `options` table; read/write it with
// get_option() / set_option() from core/includes/db.php.

return [
    'DB_HOST'    => '127.0.0.1',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'notes',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_CHARSET' => 'utf8mb4',
];
