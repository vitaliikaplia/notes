<?php

/** base constant */
const ABSPATH = __DIR__;

/** debug */
ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', ABSPATH . DIRECTORY_SEPARATOR . 'debug.log');
error_reporting(E_ALL);

/** session */
$remember_active = !empty($_COOKIE['remember_token']);
$session_lifetime = $remember_active ? 30 * 86400 : 0;

ini_set('session.gc_maxlifetime', $remember_active ? 30 * 86400 : 86400);

session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'secure'   => true,
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();

/** composer */
require_once ABSPATH . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/** let's go! */
require_once ABSPATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'init.php';
