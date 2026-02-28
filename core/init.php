<?php

if(!defined('ABSPATH')){exit;}

/** write log */
function write_log($log) {
    if (is_array($log) || is_object($log)) {
        error_log(print_r($log, true));
    } else {
        error_log($log);
    }
}

/** constants */
const DS = DIRECTORY_SEPARATOR;
const CORE_PATH = ABSPATH . DS . 'core';
const SITE_CHARSET = 'UTF-8';
const HTML_LOC = 'uk';
const TWIG_VIEWS_DIRNAME = 'views';
const SITE_NAME = 'Нотатки';
const NOTES_DIR = '.notes';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] . '/';
define("HOME_URL", $protocol . $domain);
define("ASSETS_URL", HOME_URL . 'assets/');

/** including core */
$includes = [
    'auth',
    'notes',
    'render',
    'router',
];

foreach ($includes as $file) {
    require_once CORE_PATH . DS . 'includes' . DS . $file . '.php';
}
