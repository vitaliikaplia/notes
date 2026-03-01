<?php
if(!defined('ABSPATH')){exit;}

/**
 * Check if string starts with an emoji character (Unicode ranges)
 */
function is_emoji_char(string $str): bool {
    return (bool) preg_match('/^[\x{1F600}-\x{1F64F}' .
        '\x{1F300}-\x{1F5FF}' .
        '\x{1F680}-\x{1F6FF}' .
        '\x{1F1E0}-\x{1F1FF}' .
        '\x{2600}-\x{26FF}' .
        '\x{2700}-\x{27BF}' .
        '\x{1F900}-\x{1F9FF}' .
        '\x{1FA00}-\x{1FA6F}' .
        '\x{1FA70}-\x{1FAFF}' .
        '\x{200D}' .
        '\x{231A}-\x{231B}' .
        '\x{23E9}-\x{23F3}' .
        '\x{23F8}-\x{23FA}' .
        '\x{25AA}-\x{25AB}' .
        '\x{25B6}\x{25C0}' .
        '\x{25FB}-\x{25FE}' .
        '\x{2934}-\x{2935}' .
        '\x{3030}\x{303D}' .
        '\x{3297}\x{3299}' .
        '\x{2B05}-\x{2B07}' .
        '\x{2B1B}-\x{2B1C}' .
        '\x{2B50}\x{2B55}' .
        '\x{00A9}\x{00AE}' .
        '\x{2122}\x{2139}' .
        '\x{203C}\x{2049}' .
        '\x{20E3}' .
        '\x{FE0F}' .
        '\x{2194}-\x{2199}' .
        '\x{21A9}-\x{21AA}' .
        '\x{23CF}' .
        '\x{24C2}' .
        '\x{25AA}-\x{25AB}' .
        '\x{2660}\x{2663}\x{2665}\x{2666}' .
        '\x{267B}\x{267E}\x{267F}' .
        '\x{2692}-\x{2697}' .
        '\x{269B}-\x{269C}' .
        '\x{26A0}-\x{26A1}' .
        '\x{26B0}-\x{26B1}' .
        '\x{26C4}-\x{26C5}' .
        '\x{26CE}-\x{26CF}' .
        '\x{26D1}\x{26D3}-\x{26D4}' .
        '\x{26E9}-\x{26EA}' .
        '\x{26F0}-\x{26F5}' .
        '\x{26F7}-\x{26FA}' .
        '\x{26FD}' .
        ']/u', $str);
}

/**
 * Load and merge EN + UK CLDR emoji annotations
 * Returns: ['😀' => ['keywords' => [...], 'name' => 'grinning face'], ...]
 */
function emoji_load_annotations(): array {
    static $cache = null;
    if($cache !== null) return $cache;

    $dir = ABSPATH . DS . 'data' . DS . 'emoji' . DS;
    $en_file = $dir . 'annotations-en.json';
    $uk_file = $dir . 'annotations-uk.json';

    if(!file_exists($en_file)) {
        $cache = [];
        return $cache;
    }

    $en_data = json_decode(file_get_contents($en_file), true);
    $en_annotations = $en_data['annotations']['annotations'] ?? [];

    $uk_annotations = [];
    if(file_exists($uk_file)) {
        $uk_data = json_decode(file_get_contents($uk_file), true);
        $uk_annotations = $uk_data['annotations']['annotations'] ?? [];
    }

    $merged = [];
    $all_keys = array_unique(array_merge(array_keys($en_annotations), array_keys($uk_annotations)));

    foreach($all_keys as $emoji) {
        if(!is_emoji_char($emoji)) continue;

        $en = $en_annotations[$emoji] ?? [];
        $uk = $uk_annotations[$emoji] ?? [];

        $keywords = array_unique(array_merge(
            $en['default'] ?? [],
            $uk['default'] ?? []
        ));

        $name = $en['tts'][0] ?? ($uk['tts'][0] ?? '');

        $merged[$emoji] = [
            'keywords' => array_values($keywords),
            'name'     => $name,
        ];
    }

    $cache = $merged;
    return $cache;
}

/**
 * Search emojis by keyword query
 * Returns: [['emoji' => '🐱', 'name' => 'cat face'], ...]
 */
function emoji_search(string $query, int $limit = 50): array {
    $annotations = emoji_load_annotations();
    $q = mb_strtolower(trim($query), 'UTF-8');

    if($q === '') return [];

    $results = [];

    foreach($annotations as $emoji => $data) {
        $best_score = 0;

        foreach($data['keywords'] as $kw) {
            $kw_lower = mb_strtolower($kw, 'UTF-8');

            if($kw_lower === $q) {
                $best_score = max($best_score, 3);
                break;
            } elseif(mb_strpos($kw_lower, $q, 0, 'UTF-8') === 0) {
                $best_score = max($best_score, 2);
            } elseif(mb_strpos($kw_lower, $q, 0, 'UTF-8') !== false) {
                $best_score = max($best_score, 1);
            }
        }

        // Also check name (tts)
        if($best_score < 3 && $data['name']) {
            $name_lower = mb_strtolower($data['name'], 'UTF-8');
            if($name_lower === $q) {
                $best_score = 3;
            } elseif(mb_strpos($name_lower, $q, 0, 'UTF-8') === 0) {
                $best_score = max($best_score, 2);
            } elseif(mb_strpos($name_lower, $q, 0, 'UTF-8') !== false) {
                $best_score = max($best_score, 1);
            }
        }

        if($best_score > 0) {
            $results[] = [
                'emoji' => $emoji,
                'name'  => $data['name'],
                'score' => $best_score,
            ];
        }
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

    return array_map(
        fn($r) => ['emoji' => $r['emoji'], 'name' => $r['name']],
        array_slice($results, 0, $limit)
    );
}
