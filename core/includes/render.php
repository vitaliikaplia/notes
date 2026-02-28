<?php

if(!defined('ABSPATH')){exit;}

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
    $twig = new \Twig\Environment($loader);

    // custom functions
    $twig->addFunction(new \Twig\TwigFunction('render_blocks', 'render_blocks_to_html', ['is_safe' => ['html']]));
    $twig->addFunction(new \Twig\TwigFunction('date_format_uk', function(string $date): string {
        if(empty($date)) return '';
        $ts = strtotime($date);
        if(!$ts) return $date;

        $months = ['', 'січ', 'лют', 'бер', 'кві', 'тра', 'чер', 'лип', 'сер', 'вер', 'жов', 'лис', 'гру'];
        $d = date('j', $ts);
        $m = $months[(int)date('n', $ts)];
        $y = date('Y', $ts);
        $time = date('H:i', $ts);
        return "{$d} {$m} {$y}, {$time}";
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
