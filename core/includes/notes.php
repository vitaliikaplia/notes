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
    $result = file_put_contents($file, json_encode($slugs, JSON_UNESCAPED_UNICODE)) !== false;
    if($result) {
        cache_delete('tree');
    }
    return $result;
}

function scan_notes($dir = null): array {
    $base = get_notes_path();
    $is_root = ($dir === null);
    $dir = $dir ?? $base;

    // Cache only the root tree
    if($is_root) {
        $cached = cache_get('tree');
        if($cached !== null) return $cached;
    }

    $tree = _scan_notes_uncached($dir);

    if($is_root) {
        cache_set('tree', $tree);
    }

    return $tree;
}

function _scan_notes_uncached($dir): array {
    $base = get_notes_path();
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
                'children' => _scan_notes_uncached($path),
            ];
        } elseif(str_ends_with($item, '.json')) {
            $note = get_note($relative);
            if($note) {
                // Attach child pages if a matching folder exists
                $slug = basename($item, '.json');
                if(in_array($slug, $child_folders)) {
                    $note['_children'] = _scan_notes_uncached($dir . DS . $slug);
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
    $cache_key = 'note:' . $relative_path;
    $cached = cache_get($cache_key);
    if($cached !== null) return $cached;

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

    // Convert legacy raw SVG icons to base64 data URI
    $need_save = false;
    if(!empty($data['meta']['icon']) && str_starts_with($data['meta']['icon'], '<svg')) {
        $data['meta']['icon'] = 'data:image/svg+xml;base64,' . base64_encode($data['meta']['icon']);
        $need_save = true;
    }

    // Migrate legacy /uploads/ URLs to /file/
    if(!empty($data['content']['blocks'])) {
        $uploads_prefix = HOME_URL . 'uploads/';
        foreach($data['content']['blocks'] as &$block) {
            if(($block['type'] ?? '') === 'image' && !empty($block['data']['file']['url']) && str_starts_with($block['data']['file']['url'], $uploads_prefix)) {
                $block['data']['file']['url'] = HOME_URL . 'file/' . substr($block['data']['file']['url'], strlen($uploads_prefix));
                $need_save = true;
            }
        }
        unset($block);
    }

    if($need_save) {
        $save_data = array_filter($data, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
        file_put_contents($file, json_encode($save_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    cache_set($cache_key, $data);

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
    $result = file_put_contents($file, $json) !== false;

    if($result) {
        cache_delete('note:' . $relative_path);
        cache_delete('tree');
    }

    return $result;
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

    // Remove slug from .sort-order.json in current directory
    if($result) {
        $dir = dirname($file);
        $sort_file = $dir . DS . '.sort-order.json';
        if(file_exists($sort_file)) {
            $order = json_decode(file_get_contents($sort_file), true);
            if(is_array($order)) {
                $order = array_values(array_filter($order, fn($s) => $s !== $slug));
                if(empty($order)) {
                    unlink($sort_file);
                } else {
                    file_put_contents($sort_file, json_encode($order, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }

    // Clean up empty parent folder (if it's not the notes root)
    $parent_dir = dirname($file);
    if($result && $parent_dir !== $base) {
        $items = array_diff(scandir($parent_dir), ['.', '..', '.sort-order.json']);
        if(empty($items)) {
            // Remove leftover .sort-order.json before rmdir
            $sort_file = $parent_dir . DS . '.sort-order.json';
            if(file_exists($sort_file)) unlink($sort_file);
            rmdir($parent_dir);
        }
    }

    // Clean up root .sort-order.json if notes root is empty
    if($result) {
        $root_items = array_diff(scandir($base), ['.', '..', '.htaccess', '.sort-order.json']);
        if(empty($root_items)) {
            $root_sort = $base . DS . '.sort-order.json';
            if(file_exists($root_sort)) unlink($root_sort);
        }
    }

    if($result) {
        cache_delete('note:' . $relative_path);
        cache_delete('tree');
    }

    return $result;
}

function move_note(string $source_path, string $target_folder): array {
    $base = get_notes_path();
    $source_path = str_replace('/', DS, $source_path);
    $source_file = $base . DS . $source_path;

    // 1. Validate source exists
    if(!file_exists($source_file)) {
        return ['success' => false, 'error' => 'Source not found'];
    }

    // 2. Determine source folder
    $source_dir = dirname($source_path);
    if($source_dir === '.') $source_dir = '';

    $target_folder = trim(str_replace('/', DS, $target_folder), DS . ' ');

    // 3. Not moving to same folder
    if($source_dir === $target_folder) {
        return ['success' => false, 'error' => 'Already in target folder'];
    }

    // 4. Prevent circular nesting
    $slug = basename($source_path, '.json');
    $source_slug_path = $source_dir ? $source_dir . DS . $slug : $slug;
    if($target_folder === $source_slug_path || str_starts_with($target_folder, $source_slug_path . DS)) {
        return ['success' => false, 'error' => 'Cannot move note into itself'];
    }

    // 5. Create target directory if needed
    $target_dir = $target_folder ? $base . DS . $target_folder : $base;
    if(!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // 6. Resolve slug conflict in target
    $final_slug = $slug;
    $dest_file = $target_dir . DS . $final_slug . '.json';
    $counter = 1;
    while(file_exists($dest_file)) {
        $final_slug = $slug . '-' . $counter;
        $dest_file = $target_dir . DS . $final_slug . '.json';
        $counter++;
    }

    // 7. Move JSON file
    if(!rename($source_file, $dest_file)) {
        return ['success' => false, 'error' => 'Failed to move file'];
    }

    // 8. Move children folder if exists
    $source_children_dir = dirname($source_file) . DS . $slug;
    if(is_dir($source_children_dir)) {
        $dest_children_dir = $target_dir . DS . $final_slug;
        rename($source_children_dir, $dest_children_dir);
    }

    // 9. Remove slug from source sort order
    $source_abs_dir = dirname($source_file);
    $sort_file = $source_abs_dir . DS . '.sort-order.json';
    if(file_exists($sort_file)) {
        $order = json_decode(file_get_contents($sort_file), true);
        if(is_array($order)) {
            $order = array_values(array_filter($order, fn($s) => $s !== $slug));
            if(empty($order)) {
                unlink($sort_file);
            } else {
                file_put_contents($sort_file, json_encode($order, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    // 10. Clean up empty source folder (if not root)
    if($source_abs_dir !== $base) {
        $remaining = array_diff(scandir($source_abs_dir), ['.', '..', '.sort-order.json']);
        if(empty($remaining)) {
            $leftover_sort = $source_abs_dir . DS . '.sort-order.json';
            if(file_exists($leftover_sort)) unlink($leftover_sort);
            if(is_dir($source_abs_dir)) rmdir($source_abs_dir);
        }
    }

    // 11. Clear caches
    $new_relative = $target_folder ? $target_folder . DS . $final_slug . '.json' : $final_slug . '.json';
    cache_delete('note:' . $source_path);
    cache_delete('note:' . $new_relative);
    cache_delete('tree');

    // Return with forward slashes for consistency
    $new_path = str_replace(DS, '/', $new_relative);
    return ['success' => true, 'new_path' => $new_path, 'new_slug' => $final_slug];
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

function update_note_visibility(string $relative_path, string $visibility): bool {
    $file = get_notes_path() . DS . $relative_path;
    if(!file_exists($file)) return false;

    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if(!$data) return false;

    $data['meta']['visibility'] = $visibility;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = file_put_contents($file, $json) !== false;

    if($result) {
        cache_delete('note:' . $relative_path);
        cache_delete('tree');
    }

    return $result;
}

function save_uploaded_image(string $source_path, string $mime): ?string {
    $uploads_dir = ABSPATH . DS . 'uploads';
    $subdir = date('Y') . DS . date('m');
    $target_dir = $uploads_dir . DS . $subdir;

    if(!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $filename = bin2hex(random_bytes(8));

    // SVG — store as-is (no conversion)
    if($mime === 'image/svg+xml') {
        $filepath = $target_dir . DS . $filename . '.svg';
        copy($source_path, $filepath);
        return HOME_URL . 'file/' . str_replace(DS, '/', $subdir) . '/' . $filename . '.svg';
    }

    // Raster images — convert to WebP via Imagick
    $filepath = $target_dir . DS . $filename . '.webp';

    try {
        $imagick = new \Imagick($source_path);

        // Auto-orient based on EXIF
        $imagick->autoOrient();

        // Strip metadata
        $imagick->stripImage();

        // Reject images larger than 10240x10240
        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();
        if($w > 10240 || $h > 10240) {
            $imagick->destroy();
            return null;
        }

        // Convert to WebP
        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality(80);

        // Resize so the shorter side is max 1024px (longer side scales proportionally)
        $minSide = min($w, $h);
        if($minSide > 1024) {
            $ratio = 1024 / $minSide;
            $imagick->resizeImage((int)($w * $ratio), (int)($h * $ratio), \Imagick::FILTER_LANCZOS, 1);
        }

        $imagick->writeImage($filepath);
        $imagick->destroy();
    } catch(\Exception $e) {
        return null;
    }

    return HOME_URL . 'file/' . str_replace(DS, '/', $subdir) . '/' . $filename . '.webp';
}

function minify_svg(string $svg): ?string {
    // Strip XML declaration
    $svg = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $svg);
    // Strip DOCTYPE
    $svg = preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $svg);
    // Strip comments
    $svg = preg_replace('/<!--.*?-->/s', '', $svg);

    // Extract <svg>...</svg>
    if(!preg_match('/<svg[\s\S]*<\/svg>/i', $svg, $m)) {
        return null;
    }
    $svg = $m[0];

    // Remove unnecessary attributes from <svg> tag
    $svg = preg_replace_callback('/<svg([^>]*)>/', function($match) {
        $attrs = $match[1];
        // Remove class, version, xmlns:xlink, xml:space, style
        $attrs = preg_replace('/\s*(class|version|xmlns:xlink|xml:space|style)\s*=\s*"[^"]*"/i', '', $attrs);
        // Remove "px" from width/height
        $attrs = preg_replace('/(width|height)\s*=\s*"([\d.]+)px"/i', '$1="$2"', $attrs);
        return '<svg' . $attrs . '>';
    }, $svg, 1);

    // Collapse whitespace between tags
    $svg = preg_replace('/>\s+</', '><', $svg);
    // Trim whitespace inside tags
    $svg = preg_replace('/\s{2,}/', ' ', $svg);

    return trim($svg);
}

function extract_image_urls(array $blocks): array {
    $urls = [];
    foreach($blocks as $block) {
        if(($block['type'] ?? '') === 'image' && !empty($block['data']['file']['url'])) {
            $urls[] = $block['data']['file']['url'];
        }
    }
    return $urls;
}

function collect_media_from_notes(array $notes): array {
    $media = [];
    foreach($notes as $note) {
        $blocks = $note['content']['blocks'] ?? [];
        if(empty($blocks)) continue;

        $preview = '';
        foreach($blocks as $b) {
            if(($b['type'] ?? '') === 'paragraph' && !empty($b['data']['text'])) {
                $preview = mb_substr(strip_tags($b['data']['text']), 0, 120);
                break;
            }
        }

        foreach($blocks as $block) {
            if(($block['type'] ?? '') === 'image' && !empty($block['data']['file']['url'])) {
                $media[] = [
                    'image'   => $block['data']['file']['url'],
                    'title'   => $note['_title'],
                    'date'    => $note['meta']['updated_at'] ?? '',
                    'preview' => $preview,
                    'url'     => HOME_URL . 'note/' . $note['_url'] . '/',
                ];
            }
        }
    }

    usort($media, function($a, $b) {
        return ($b['date'] ?? '') <=> ($a['date'] ?? '');
    });

    return $media;
}

function delete_upload_by_url(string $url): bool {
    $file_prefix = HOME_URL . 'file/';
    $uploads_prefix = HOME_URL . 'uploads/';

    if(str_starts_with($url, $file_prefix)) {
        $relative = substr($url, strlen($file_prefix));
    } elseif(str_starts_with($url, $uploads_prefix)) {
        $relative = substr($url, strlen($uploads_prefix));
    } else {
        return false;
    }
    if(str_contains($relative, '..')) {
        return false;
    }

    $filepath = ABSPATH . DS . 'uploads' . DS . str_replace('/', DS, $relative);
    if(file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

function collect_note_image_urls(string $relative_path): array {
    $base = get_notes_path();
    $file = $base . DS . $relative_path;
    if(!file_exists($file)) return [];

    $data = json_decode(file_get_contents($file), true);
    $urls = extract_image_urls($data['content']['blocks'] ?? []);

    // Collect from child notes recursively
    $slug = basename($relative_path, '.json');
    $child_dir = dirname($file) . DS . $slug;
    if(is_dir($child_dir)) {
        $urls = array_merge($urls, _collect_images_recursive($child_dir));
    }

    return $urls;
}

function _collect_images_recursive(string $dir): array {
    $urls = [];
    $base = get_notes_path();

    foreach(scandir($dir) as $item) {
        if($item === '.' || $item === '..' || $item === '.sort-order.json') continue;
        $path = $dir . DS . $item;
        if(is_dir($path)) {
            $urls = array_merge($urls, _collect_images_recursive($path));
        } elseif(str_ends_with($item, '.json')) {
            $json = file_get_contents($path);
            $data = json_decode($json, true);
            if($data) {
                $urls = array_merge($urls, extract_image_urls($data['content']['blocks'] ?? []));
            }
        }
    }

    return $urls;
}

function is_upload_referenced_in_public_note(string $filename): bool {
    return _search_upload_in_notes(get_notes_path(), $filename);
}

function _search_upload_in_notes(string $dir, string $filename): bool {
    foreach(scandir($dir) as $item) {
        if($item === '.' || $item === '..' || $item[0] === '.') continue;
        $path = $dir . DS . $item;
        if(is_dir($path)) {
            if(_search_upload_in_notes($path, $filename)) return true;
        } elseif(str_ends_with($item, '.json')) {
            $raw = file_get_contents($path);
            if(str_contains($raw, $filename)) {
                $data = json_decode($raw, true);
                $vis = $data['meta']['visibility'] ?? 'private';
                if($vis === 'public' || $vis === 'unlisted') return true;
            }
        }
    }
    return false;
}

function get_note_excerpt(array $note, int $max_length = 160): string {
    if(empty($note['content']['blocks'])) return '';

    $text = '';
    foreach($note['content']['blocks'] as $block) {
        $d = $block['data'] ?? [];
        if(!empty($d['text'])) {
            $text .= ' ' . strip_tags($d['text']);
        }
        if(mb_strlen($text, 'UTF-8') >= $max_length) break;
    }

    $text = trim($text);
    if(mb_strlen($text, 'UTF-8') > $max_length) {
        $text = mb_substr($text, 0, $max_length, 'UTF-8') . '…';
    }

    return $text;
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

            case 'image':
                $url = htmlspecialchars($data['file']['url'] ?? '', ENT_QUOTES, 'UTF-8');
                $caption = $data['caption'] ?? '';
                $classes = 'image-block';
                if(!empty($data['withBorder'])) $classes .= ' image-border';
                if(!empty($data['withBackground'])) $classes .= ' image-background';
                if(!empty($data['stretched'])) $classes .= ' image-stretched';
                $fig_style = '';
                if(!empty($data['width'])) {
                    $fig_style = ' style="max-width:' . intval($data['width']) . 'px"';
                }
                if($url) {
                    $html .= "<figure class=\"{$classes}\"{$fig_style}>";
                    $html .= "<img src=\"{$url}\" alt=\"" . htmlspecialchars(strip_tags($caption), ENT_QUOTES, 'UTF-8') . "\" loading=\"lazy\">";
                    if($caption) {
                        $html .= "<figcaption>{$caption}</figcaption>";
                    }
                    $html .= "</figure>\n";
                }
                break;

            case 'embed':
                $src = htmlspecialchars($data['embed'] ?? '', ENT_QUOTES, 'UTF-8');
                if($src) {
                    $html .= "<div class=\"embed-block\" style=\"max-width:640px;aspect-ratio:16/9\"><iframe src=\"{$src}\" frameborder=\"0\" allowfullscreen style=\"width:100%;height:100%;border-radius:var(--radius)\"></iframe></div>\n";
                }
                break;

            case 'table':
                $html .= "<table>\n";
                $rows = $data['content'] ?? [];
                $with_headings = !empty($data['withHeadings']);
                foreach($rows as $i => $row) {
                    $html .= "  <tr>\n";
                    $cell_tag = ($with_headings && $i === 0) ? 'th' : 'td';
                    foreach($row as $cell) {
                        $html .= "    <{$cell_tag}>{$cell}</{$cell_tag}>\n";
                    }
                    $html .= "  </tr>\n";
                }
                $html .= "</table>\n";
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
