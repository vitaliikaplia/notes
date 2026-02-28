<?php

if(!defined('ABSPATH')){exit;}

function get_notes_path(): string {
    return ABSPATH . DS . NOTES_DIR;
}

function ukr_to_lat($str) {
    $map = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'h',  'ґ' => 'g',
        'д' => 'd',  'е' => 'e',  'є' => 'ye', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y',  'і' => 'i',  'ї' => 'yi', 'й' => 'y',  'к' => 'k',
        'л' => 'l',  'м' => 'm',  'н' => 'n',  'о' => 'o',  'п' => 'p',
        'р' => 'r',  'с' => 's',  'т' => 't',  'у' => 'u',  'ф' => 'f',
        'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ь' => '',   'ю' => 'yu', 'я' => 'ya', 'ъ' => '',
        'А' => 'A',  'Б' => 'B',  'В' => 'V',  'Г' => 'H',  'Ґ' => 'G',
        'Д' => 'D',  'Е' => 'E',  'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z',
        'И' => 'Y',  'І' => 'I',  'Ї' => 'Yi', 'Й' => 'Y',  'К' => 'K',
        'Л' => 'L',  'М' => 'M',  'Н' => 'N',  'О' => 'O',  'П' => 'P',
        'Р' => 'R',  'С' => 'S',  'Т' => 'T',  'У' => 'U',  'Ф' => 'F',
        'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch',
        'Ь' => '',   'Ю' => 'Yu', 'Я' => 'Ya', 'Ъ' => '',
    ];
    $str = strtr($str, $map);
    $str = mb_strtolower($str, 'UTF-8');
    $str = preg_replace('/[^\p{L}\p{N}]+/u', '-', $str);
    $str = trim($str, '-');
    return $str;
}

function generate_slug($title): string {
    $slug = ukr_to_lat($title);
    return $slug ?: 'untitled-' . time();
}

function get_sort_order($dir): array {
    $file = $dir . DS . '.sort-order.json';
    if(!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_sort_order($dir, array $slugs): bool {
    $file = $dir . DS . '.sort-order.json';
    return file_put_contents($file, json_encode($slugs, JSON_UNESCAPED_UNICODE)) !== false;
}

function scan_notes($dir = null): array {
    $base = get_notes_path();
    $dir = $dir ?? $base;
    $tree = ['folders' => [], 'notes' => []];

    if(!is_dir($dir)) return $tree;

    $items = scandir($dir);
    $dir_names = [];
    $note_slugs = [];

    // First pass: collect folder names and note slugs
    foreach($items as $item) {
        if($item === '.' || $item === '..' || $item === '.sort-order.json') continue;
        $path = $dir . DS . $item;
        if(is_dir($path)) {
            $dir_names[] = $item;
        } elseif(str_ends_with($item, '.json')) {
            $note_slugs[] = basename($item, '.json');
        }
    }

    // Find folders that are child-page containers (matching a note slug)
    $child_folders = array_intersect($dir_names, $note_slugs);

    foreach($items as $item) {
        if($item === '.' || $item === '..' || $item === '.sort-order.json') continue;

        $path = $dir . DS . $item;
        $relative = ltrim(str_replace($base, '', $path), DS . '/');

        if(is_dir($path)) {
            if(in_array($item, $child_folders)) {
                // Skip — will be attached as children of the matching note
                continue;
            }
            $tree['folders'][] = [
                'name' => $item,
                'path' => $relative,
                'children' => scan_notes($path),
            ];
        } elseif(str_ends_with($item, '.json')) {
            $note = get_note($relative);
            if($note) {
                // Attach child pages if a matching folder exists
                $slug = basename($item, '.json');
                if(in_array($slug, $child_folders)) {
                    $note['_children'] = scan_notes($dir . DS . $slug);
                }
                $tree['notes'][] = $note;
            }
        }
    }

    // sort folders alphabetically
    usort($tree['folders'], fn($a, $b) => strcasecmp($a['name'], $b['name']));

    // sort notes by custom order, then by updated_at descending
    $order = get_sort_order($dir);
    if(!empty($order)) {
        $order_map = array_flip($order);
        $max = count($order);
        usort($tree['notes'], function($a, $b) use ($order_map, $max) {
            $posA = $order_map[$a['_slug']] ?? $max;
            $posB = $order_map[$b['_slug']] ?? $max;
            if($posA !== $posB) return $posA <=> $posB;
            return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? '');
        });
    } else {
        usort($tree['notes'], function($a, $b) {
            return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? '');
        });
    }

    return $tree;
}

function get_note($relative_path): ?array {
    $file = get_notes_path() . DS . $relative_path;
    if(!file_exists($file)) return null;

    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if(!$data) return null;

    $data['_file'] = $relative_path;
    $data['_slug'] = basename($relative_path, '.json');

    // build URL path from file path
    $url_path = str_replace(DS, '/', $relative_path);
    $url_path = preg_replace('/\.json$/', '', $url_path);
    $data['_url'] = $url_path;

    // extract title
    $data['_title'] = get_note_title($data);

    return $data;
}

function get_note_title($note_data): string {
    if(!empty($note_data['meta']['title'])) {
        return $note_data['meta']['title'];
    }

    // extract from first header block
    if(!empty($note_data['content']['blocks'])) {
        foreach($note_data['content']['blocks'] as $block) {
            if($block['type'] === 'header' && !empty($block['data']['text'])) {
                return strip_tags($block['data']['text']);
            }
        }
    }

    return 'Без назви';
}

function get_breadcrumbs($relative_path): array {
    $crumbs = [];
    $base = get_notes_path();

    // Split path: e.g. "projects/my-project/sub-task.json"
    // → segments: ["projects", "my-project", "sub-task.json"]
    $parts = explode('/', str_replace(DS, '/', $relative_path));
    $current_slug = basename(end($parts), '.json');

    // Walk path segments (excluding the file itself)
    $accumulated = '';
    for($i = 0; $i < count($parts) - 1; $i++) {
        $segment = $parts[$i];
        $accumulated .= ($accumulated ? '/' : '') . $segment;

        // Check if this segment is a parent note (has matching .json)
        $parent_json = ($i > 0 ? implode('/', array_slice($parts, 0, $i)) . '/' : '') . $segment . '.json';
        $parent_file = $base . DS . str_replace('/', DS, $parent_json);

        if(file_exists($parent_file)) {
            $parent_note = get_note($parent_json);
            $crumbs[] = [
                'title' => $parent_note ? $parent_note['_title'] : $segment,
                'url' => 'note/' . preg_replace('/\.json$/', '', $parent_json),
            ];
        } else {
            $crumbs[] = [
                'title' => $segment,
                'url' => '',
            ];
        }
    }

    return $crumbs;
}

function save_note($relative_path, $data): bool {
    $file = get_notes_path() . DS . $relative_path;
    $dir = dirname($file);

    if(!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($file, $json) !== false;
}

function delete_note($relative_path): bool {
    $base = get_notes_path();
    $file = $base . DS . $relative_path;
    if(!file_exists($file)) return false;

    // Delete child folder if exists (e.g. notes/my-note.json → notes/my-note/)
    $slug = basename($relative_path, '.json');
    $child_dir = dirname($file) . DS . $slug;
    if(is_dir($child_dir)) {
        delete_directory($child_dir);
    }

    $result = unlink($file);

    // Clean up empty parent folder (if it's not the notes root)
    $parent_dir = dirname($file);
    if($result && $parent_dir !== $base) {
        $items = array_diff(scandir($parent_dir), ['.', '..']);
        if(empty($items)) {
            rmdir($parent_dir);
        }
    }

    return $result;
}

function delete_directory($dir): bool {
    if(!is_dir($dir)) return false;

    $items = scandir($dir);
    foreach($items as $item) {
        if($item === '.' || $item === '..') continue;
        $path = $dir . DS . $item;
        if(is_dir($path)) {
            delete_directory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

function create_folder($relative_path): bool {
    $dir = get_notes_path() . DS . $relative_path;
    if(!is_dir($dir)) {
        return mkdir($dir, 0755, true);
    }
    return true;
}

function delete_folder($relative_path): bool {
    $dir = get_notes_path() . DS . $relative_path;
    if(is_dir($dir)) {
        // only delete if empty
        $items = array_diff(scandir($dir), ['.', '..']);
        if(empty($items)) {
            return rmdir($dir);
        }
    }
    return false;
}

function get_recent_notes($limit = 20): array {
    $all = collect_all_notes();
    usort($all, function($a, $b) {
        return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? '');
    });
    return array_slice($all, 0, $limit);
}

function collect_all_notes($dir = null): array {
    $base = get_notes_path();
    $dir = $dir ?? $base;
    $notes = [];

    if(!is_dir($dir)) return $notes;

    $items = scandir($dir);
    foreach($items as $item) {
        if($item === '.' || $item === '..' || $item[0] === '.') continue;

        $path = $dir . DS . $item;

        if(is_dir($path)) {
            $notes = array_merge($notes, collect_all_notes($path));
        } elseif(str_ends_with($item, '.json')) {
            $relative = ltrim(str_replace($base, '', $path), DS . '/');
            $note = get_note($relative);
            if($note) {
                $notes[] = $note;
            }
        }
    }

    return $notes;
}

function render_blocks_to_html($blocks): string {
    if(empty($blocks)) return '';

    $html = '';
    foreach($blocks as $block) {
        $type = $block['type'] ?? '';
        $data = $block['data'] ?? [];

        switch($type) {
            case 'header':
                $level = $data['level'] ?? 2;
                $text = $data['text'] ?? '';
                $html .= "<h{$level}>{$text}</h{$level}>\n";
                break;

            case 'paragraph':
                $text = $data['text'] ?? '';
                $html .= "<p>{$text}</p>\n";
                break;

            case 'list':
                $style = $data['style'] ?? 'unordered';
                $tag = $style === 'ordered' ? 'ol' : 'ul';
                $html .= "<{$tag}>\n";
                if(!empty($data['items'])) {
                    foreach($data['items'] as $item) {
                        $content = is_array($item) ? ($item['content'] ?? '') : $item;
                        $html .= "  <li>{$content}</li>\n";
                    }
                }
                $html .= "</{$tag}>\n";
                break;

            case 'checklist':
                $html .= "<div class=\"checklist\">\n";
                if(!empty($data['items'])) {
                    foreach($data['items'] as $item) {
                        $checked = !empty($item['checked']) ? ' checked' : '';
                        $text = $item['text'] ?? '';
                        $html .= "  <div class=\"checklist-item\"><input type=\"checkbox\" disabled{$checked}> <span>{$text}</span></div>\n";
                    }
                }
                $html .= "</div>\n";
                break;

            case 'code':
                $code = htmlspecialchars($data['code'] ?? '', ENT_QUOTES, 'UTF-8');
                $html .= "<pre><code>{$code}</code></pre>\n";
                break;

            case 'quote':
                $text = $data['text'] ?? '';
                $caption = $data['caption'] ?? '';
                $html .= "<blockquote><p>{$text}</p>";
                if($caption) {
                    $html .= "<cite>{$caption}</cite>";
                }
                $html .= "</blockquote>\n";
                break;

            case 'delimiter':
                $html .= "<hr>\n";
                break;

            case 'page':
                $page_title = htmlspecialchars($data['title'] ?? 'Сторінка', ENT_QUOTES, 'UTF-8');
                $page_url = $data['pageUrl'] ?? '';
                if($page_url) {
                    $html .= '<div class="cdx-page-link"><a href="' . HOME_URL . htmlspecialchars($page_url, ENT_QUOTES, 'UTF-8') . '/"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> ' . $page_title . '</a></div>' . "\n";
                }
                break;

            default:
                // unknown block — render as paragraph
                if(!empty($data['text'])) {
                    $html .= "<p>{$data['text']}</p>\n";
                }
                break;
        }
    }

    return $html;
}
