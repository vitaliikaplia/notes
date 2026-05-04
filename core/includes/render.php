<?php

if(!defined('ABSPATH')){exit;}

function contrast_color(string $hex_color): string {
    if(empty($hex_color)) return '';
    $hex = ltrim($hex_color, '#');
    if(strlen($hex) !== 6) return '#302b25';
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
    $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
    $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $luminance > 0.4 ? '#302b25' : '#f5f5f5';
}

function date_format_uk(string $date): string {
    if(empty($date)) return '';
    $ts = strtotime($date);
    if(!$ts) return $date;
    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $d = date('j', $ts);
    $m = $months[(int)date('n', $ts)];
    $y = date('Y', $ts);
    $time = date('H:i', $ts);
    return "{$d} {$m} {$y}, {$time}";
}

function get_context(): array {
    $context = [];

    $context['site'] = [
        'charset' => SITE_CHARSET,
        'url' => HOME_URL,
        'name' => SITE_NAME,
        'html_loc' => HTML_LOC,
    ];
    $context['assets_url'] = ASSETS_URL;
    $context['home_url'] = HOME_URL;
    $context['year'] = date('Y');
    $context['authenticated'] = auth_check();

    // notes tree for sidebar
    if(auth_check()) {
        $context['notes_tree'] = scan_notes();
    }

    $context['page'] = [
        'title' => '',
        'description' => '',
    ];

    return $context;
}

function get_twig(): \Twig\Environment {
    $loader = new \Twig\Loader\FilesystemLoader(ABSPATH . DS . TWIG_VIEWS_DIRNAME);
    // Cache: Redis if available, otherwise disk
    $redis = get_redis();
    if($redis) {
        $cache = new RedisTwigCache($redis);
    } else {
        $cache = ABSPATH . DS . '.twig-cache';
    }

    $twig = new \Twig\Environment($loader, [
        'cache' => $cache,
        'auto_reload' => true,
    ]);

    // custom functions
    $twig->addFunction(new \Twig\TwigFunction('render_blocks', 'render_blocks_to_html', ['is_safe' => ['html']]));
    $twig->addFunction(new \Twig\TwigFunction('date_format_uk', 'date_format_uk'));
    $twig->addFunction(new \Twig\TwigFunction('contrast_color', 'contrast_color'));

    // asset versioning (cache bust)
    $twig->addFunction(new \Twig\TwigFunction('asset_ver', function(string $path): string {
        $file = ABSPATH . DS . 'assets' . DS . str_replace('/', DS, $path);
        return file_exists($file) ? '?v=' . filemtime($file) : '';
    }));

    // filters
    $twig->addFilter(new \Twig\TwigFilter('json_decode', function($str) {
        return json_decode($str, true);
    }));

    return $twig;
}

function get_template($template, $context = []): string {
    $twig = get_twig();
    return $twig->render($template, $context);
}
