<?php

if(!defined('ABSPATH')){exit;}

// Sort value for notes without an explicit custom order; they tie-break by updated_at.
const NOTE_DEFAULT_SORT = 9999;

function get_notes_path(): string {
    return ABSPATH . DS . NOTES_DIR;
}

/**
 * Reduce an upload URL to its path under the uploads dir (e.g. "2026/03/x.webp"),
 * regardless of host or whether it points at /file/ or the legacy /uploads/.
 * Returns null for external or non-upload URLs.
 */
function upload_url_to_relative_path(string $url): ?string {
    if($url === '') return null;
    // strip optional scheme + host so any copy's hostname is ignored
    $path = preg_replace('#^https?://[^/]+#i', '', $url);
    if(preg_match('#^/(?:file|uploads)/(.+)$#', $path, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Normalize an upload URL to a host-relative /file/ URL so notes stay portable
 * across hosts (local, prod, future copies). External URLs are returned unchanged.
 */
function normalize_upload_url(string $url): string {
    $rel = upload_url_to_relative_path($url);
    return $rel !== null ? '/file/' . $rel : $url;
}

function ukr_to_lat($str) {
    $map = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'h',  'ґ' => 'g',
        'д' => 'd',  'е' => 'e',  'є' => 'ye', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y',  'і' => 'i',  'ї' => 'yi', 'й' => 'y',  'к' => 'k',
        'л' => 'l',  'м' => 'm',  'н' => 'n',  'о' => 'o',  'п' => 'p',
        'р' => 'r',  'с' => 's',  'т' => 't',  'у' => 'u',  'ф' => 'f',
        'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ь' => '',   'ю' => 'yu', 'я' => 'ya', 'ъ' => '',  'ы' => 'y',  'э' => 'e',  'ё' => 'yo',
        'А' => 'A',  'Б' => 'B',  'В' => 'V',  'Г' => 'H',  'Ґ' => 'G',
        'Д' => 'D',  'Е' => 'E',  'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z',
        'И' => 'Y',  'І' => 'I',  'Ї' => 'Yi', 'Й' => 'Y',  'К' => 'K',
        'Л' => 'L',  'М' => 'M',  'Н' => 'N',  'О' => 'O',  'П' => 'P',
        'Р' => 'R',  'С' => 'S',  'Т' => 'T',  'У' => 'U',  'Ф' => 'F',
        'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch',
        'Ь' => '',   'Ю' => 'Yu', 'Я' => 'Ya', 'Ъ' => '',  'Ы' => 'Y',  'Э' => 'E',  'Ё' => 'Yo',
        "\xCA\xBC" => '', '\'' => '', "\xE2\x80\x99" => '', '`' => '',
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

// ============================================================
// Path / row helpers
// ============================================================

/** Normalize a caller-supplied identifier ("parent/child.json", DS or /) to a clean path. */
function note_path_from_relative($relative): string {
    $p = str_replace(DS, '/', (string)$relative);
    $p = preg_replace('#\.json$#', '', $p);
    return trim($p, '/');
}

function to_db_datetime($value): string {
    $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
    return date('Y-m-d H:i:s', $ts ?: time());
}

/** DATETIME -> ISO 8601 (preserves the previous meta.created_at/updated_at contract). */
function note_iso(?string $datetime): string {
    if(!$datetime) return '';
    $ts = strtotime($datetime);
    return $ts ? date('c', $ts) : '';
}

function note_id_by_path(string $path): ?int {
    $stmt = get_db()->prepare("SELECT id FROM notes WHERE path = ? LIMIT 1");
    $stmt->execute([$path]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function note_exists($path_or_relative): bool {
    return note_id_by_path(note_path_from_relative($path_or_relative)) !== null;
}

/** Map a DB row to the note array shape the rest of the app expects. */
function db_row_to_note(array $row): array {
    $path = $row['path'];

    $content = json_decode($row['content'] ?? '', true);
    if(!is_array($content)) $content = ['blocks' => []];

    $meta = [
        'title'      => $row['title'],
        'icon'       => $row['icon'] ?? '',
        'visibility' => $row['visibility'],
        'pinned'     => (bool)$row['pinned'],
        'created_at' => note_iso($row['created_at']),
        'updated_at' => note_iso($row['updated_at']),
    ];
    if(($row['cover'] ?? '') !== '' && $row['cover'] !== null)         $meta['cover'] = $row['cover'];
    if($row['cover_position'] !== null)                                $meta['cover_position'] = (int)$row['cover_position'];
    if(($row['color'] ?? '') !== '' && $row['color'] !== null)         $meta['color'] = $row['color'];
    if($row['graph_x'] !== null)                                       $meta['graph_x'] = (float)$row['graph_x'];
    if($row['graph_y'] !== null)                                       $meta['graph_y'] = (float)$row['graph_y'];

    return [
        'meta'    => $meta,
        'content' => $content,
        '_file'   => str_replace('/', DS, $path) . '.json',
        '_slug'   => $row['slug'],
        '_url'    => $path,
        '_title'  => $row['title'],
        '_id'     => (int)$row['id'],
    ];
}

// ============================================================
// Tree / listing
// ============================================================

function scan_notes($dir = null): array {
    $cached = cache_get('tree');
    if($cached !== null) return $cached;

    $tree = build_note_tree();
    cache_set('tree', $tree);
    return $tree;
}

function build_note_tree(): array {
    $rows = get_db()->query("SELECT * FROM notes ORDER BY sort_order ASC, updated_at DESC")->fetchAll();

    $byParent = [];
    foreach($rows as $r) {
        $byParent[$r['parent_id'] ?? 0][] = $r;
    }

    $build = function($parentId) use (&$build, $byParent) {
        $node = ['folders' => [], 'notes' => []];
        foreach(($byParent[$parentId ?? 0] ?? []) as $r) {
            $n = db_row_to_note($r);
            $children = $build($r['id']);
            if(!empty($children['notes'])) {
                $n['_children'] = $children;
            }
            $node['notes'][] = $n;
        }
        return $node;
    };

    return $build(null);
}

function collect_all_notes($dir = null): array {
    $rows = get_db()->query("SELECT * FROM notes")->fetchAll();
    return array_map('db_row_to_note', $rows);
}

function get_recent_notes($limit = 20): array {
    $stmt = get_db()->prepare("SELECT * FROM notes ORDER BY updated_at DESC LIMIT ?");
    $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_map('db_row_to_note', $stmt->fetchAll());
}

// ============================================================
// Single note read
// ============================================================

function get_note($relative_path): ?array {
    $path = note_path_from_relative($relative_path);
    if($path === '') return null;

    $cache_key = 'note:' . $path;
    $cached = cache_get($cache_key);
    if($cached !== null) return $cached;

    $stmt = get_db()->prepare("SELECT * FROM notes WHERE path = ? LIMIT 1");
    $stmt->execute([$path]);
    $row = $stmt->fetch();
    if(!$row) return null;

    $note = db_row_to_note($row);
    cache_set($cache_key, $note);
    return $note;
}

function get_note_title($note_data): string {
    if(!empty($note_data['meta']['title'])) {
        return $note_data['meta']['title'];
    }

    if(!empty($note_data['content']['blocks'])) {
        foreach($note_data['content']['blocks'] as $block) {
            if($block['type'] === 'header' && !empty($block['data']['text'])) {
                return strip_tags($block['data']['text']);
            }
        }
    }

    return 'Untitled';
}

function get_breadcrumbs($relative_path): array {
    $path = note_path_from_relative($relative_path);
    $crumbs = [];

    $parts = explode('/', $path);
    array_pop($parts); // drop the note itself

    $accum = '';
    foreach($parts as $seg) {
        $accum = $accum === '' ? $seg : $accum . '/' . $seg;
        $parent = get_note($accum);
        $crumbs[] = [
            'title' => $parent ? $parent['_title'] : $seg,
            'url'   => $parent ? 'note/' . $accum : '',
        ];
    }

    return $crumbs;
}

// ============================================================
// Mutations
// ============================================================

function save_note($relative_path, $data): bool {
    $path = note_path_from_relative($relative_path);
    if($path === '') return false;

    $slug        = basename($path);
    $parent_path = strpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/')) : '';
    $parent_id   = $parent_path !== '' ? note_id_by_path($parent_path) : null;

    $meta    = $data['meta'] ?? [];
    $content = $data['content'] ?? ['blocks' => []];
    $now     = date('Y-m-d H:i:s');

    $visibility = $meta['visibility'] ?? 'private';
    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) $visibility = 'private';

    $params = [
        'parent_id'      => $parent_id,
        'slug'           => $slug,
        'title'          => (string)($meta['title'] ?? 'Untitled'),
        'icon'           => (isset($meta['icon']) && $meta['icon'] !== '') ? $meta['icon'] : null,
        'cover'          => !empty($meta['cover']) ? $meta['cover'] : null,
        'cover_position' => isset($meta['cover_position']) ? (int)$meta['cover_position'] : null,
        'color'          => !empty($meta['color']) ? $meta['color'] : null,
        'pinned'         => !empty($meta['pinned']) ? 1 : 0,
        'visibility'     => $visibility,
        'content'        => json_encode($content, JSON_UNESCAPED_UNICODE),
        'graph_x'        => isset($meta['graph_x']) ? (float)$meta['graph_x'] : null,
        'graph_y'        => isset($meta['graph_y']) ? (float)$meta['graph_y'] : null,
        'updated_at'     => isset($meta['updated_at']) ? to_db_datetime($meta['updated_at']) : $now,
    ];

    $db = get_db();
    $existing_id = note_id_by_path($path);

    if($existing_id) {
        $params['id'] = $existing_id;
        $ok = $db->prepare(
            "UPDATE notes SET parent_id=:parent_id, slug=:slug, title=:title, icon=:icon, cover=:cover,
             cover_position=:cover_position, color=:color, pinned=:pinned, visibility=:visibility,
             content=:content, graph_x=:graph_x, graph_y=:graph_y, updated_at=:updated_at WHERE id=:id"
        )->execute($params);
    } else {
        $params['path']       = $path;
        $params['created_at'] = isset($meta['created_at']) ? to_db_datetime($meta['created_at']) : $now;
        $params['sort_order'] = NOTE_DEFAULT_SORT;
        $ok = $db->prepare(
            "INSERT INTO notes (parent_id, slug, path, title, icon, cover, cover_position, color, pinned,
             visibility, content, graph_x, graph_y, sort_order, created_at, updated_at)
             VALUES (:parent_id,:slug,:path,:title,:icon,:cover,:cover_position,:color,:pinned,
             :visibility,:content,:graph_x,:graph_y,:sort_order,:created_at,:updated_at)"
        )->execute($params);
    }

    if($ok) {
        cache_delete('note:' . $path);
        cache_delete('tree');
    }

    return (bool)$ok;
}

/**
 * Rename/move a note in place: updates its slug, path and parent, plus the path
 * prefix of every descendant. Uses the same row ids so child links (parent_id) and
 * FK cascades stay intact — never delete+insert.
 */
function rename_note(string $old_path, string $new_path): bool {
    $db = get_db();
    $id = note_id_by_path($old_path);
    if(!$id) return false;

    $new_slug        = basename($new_path);
    $new_parent_path = strpos($new_path, '/') !== false ? substr($new_path, 0, strrpos($new_path, '/')) : '';
    $new_parent_id   = $new_parent_path !== '' ? note_id_by_path($new_parent_path) : null;

    $db->prepare("UPDATE notes SET slug=?, path=?, parent_id=? WHERE id=?")
        ->execute([$new_slug, $new_path, $new_parent_id, $id]);

    // Re-path descendants (slug stays, only the path prefix changes)
    $stmt = $db->prepare("SELECT id, path FROM notes WHERE path LIKE ?");
    $stmt->execute([$old_path . '/%']);
    $upd = $db->prepare("UPDATE notes SET path=? WHERE id=?");
    foreach($stmt as $r) {
        $upd->execute([$new_path . substr($r['path'], strlen($old_path)), $r['id']]);
    }

    cache_delete('note:' . $old_path);
    cache_delete('note:' . $new_path);
    cache_delete('tree');
    return true;
}

/** Update only the content blocks of a note (used to keep parent page-link blocks in sync). */
function update_note_content_blocks(string $relative_path, array $blocks): bool {
    $path = note_path_from_relative($relative_path);
    $note = get_note($path);
    if(!$note) return false;

    $content = $note['content'];
    $content['blocks'] = $blocks;

    $ok = get_db()->prepare("UPDATE notes SET content = ? WHERE path = ?")
        ->execute([json_encode($content, JSON_UNESCAPED_UNICODE), $path]);

    if($ok) {
        cache_delete('note:' . $path);
        cache_delete('tree');
    }
    return (bool)$ok;
}

function delete_note($relative_path): bool {
    $path = note_path_from_relative($relative_path);
    $id = note_id_by_path($path);
    if(!$id) return false;

    // Children are removed by the ON DELETE CASCADE foreign key.
    $ok = get_db()->prepare("DELETE FROM notes WHERE id = ?")->execute([$id]);

    if($ok) {
        cache_delete('note:' . $path);
        cache_delete('tree');
    }
    return (bool)$ok;
}

function move_note(string $source_path, string $target_folder): array {
    $src_path = note_path_from_relative($source_path);
    if(note_id_by_path($src_path) === null) {
        return ['success' => false, 'error' => 'Source not found'];
    }

    $slug       = basename($src_path);
    $src_parent = strpos($src_path, '/') !== false ? substr($src_path, 0, strrpos($src_path, '/')) : '';
    $target     = trim(str_replace(DS, '/', $target_folder), '/ ');

    if($src_parent === $target) {
        return ['success' => false, 'error' => 'Already in target folder'];
    }

    // Prevent moving a note into itself or one of its descendants
    if($target === $src_path || str_starts_with($target, $src_path . '/')) {
        return ['success' => false, 'error' => 'Cannot move note into itself'];
    }

    if($target !== '' && note_id_by_path($target) === null) {
        return ['success' => false, 'error' => 'Target folder not found'];
    }

    // Resolve slug conflict in the target folder
    $final_slug = $slug;
    $new_path   = $target !== '' ? $target . '/' . $final_slug : $final_slug;
    $counter    = 1;
    while(note_id_by_path($new_path) !== null) {
        $final_slug = $slug . '-' . $counter;
        $new_path   = $target !== '' ? $target . '/' . $final_slug : $final_slug;
        $counter++;
    }

    rename_note($src_path, $new_path);

    return ['success' => true, 'new_path' => $new_path, 'new_slug' => $final_slug];
}

function update_note_visibility(string $relative_path, string $visibility): bool {
    if(!in_array($visibility, ['private', 'unlisted', 'public'], true)) return false;
    $path = note_path_from_relative($relative_path);

    $ok = get_db()->prepare("UPDATE notes SET visibility = ?, updated_at = ? WHERE path = ?")
        ->execute([$visibility, date('Y-m-d H:i:s'), $path]);

    if($ok) {
        cache_delete('note:' . $path);
        cache_delete('tree');
    }
    return (bool)$ok;
}

/** Toggle pinned state; returns the new state, or null if the note is missing. */
function toggle_note_pinned($relative_path): ?bool {
    $path = note_path_from_relative($relative_path);
    $id = note_id_by_path($path);
    if(!$id) return null;

    $db = get_db();
    $cur = (int)$db->query("SELECT pinned FROM notes WHERE id = " . (int)$id)->fetchColumn();
    $new = $cur ? 0 : 1;
    $db->prepare("UPDATE notes SET pinned = ? WHERE id = ?")->execute([$new, $id]);

    cache_delete('note:' . $path);
    cache_delete('tree');
    return (bool)$new;
}

function update_note_graph_position($relative_path, $x, $y): bool {
    $path = note_path_from_relative($relative_path);
    $ok = get_db()->prepare("UPDATE notes SET graph_x = ?, graph_y = ? WHERE path = ?")
        ->execute([round((float)$x, 2), round((float)$y, 2), $path]);
    if($ok) {
        cache_delete('note:' . $path);
        cache_delete('tree');
    }
    return (bool)$ok;
}

/** Reset all saved graph positions; returns how many notes were reset. */
function reset_graph_positions(): int {
    $db = get_db();
    $count = (int)$db->query("SELECT COUNT(*) FROM notes WHERE graph_x IS NOT NULL OR graph_y IS NOT NULL")->fetchColumn();
    $db->exec("UPDATE notes SET graph_x = NULL, graph_y = NULL WHERE graph_x IS NOT NULL OR graph_y IS NOT NULL");
    cache_delete('tree');
    return $count;
}

// ============================================================
// Custom ordering (replaces .sort-order.json)
// ============================================================

/** Ordered list of slugs in a folder (relative path; '' = root). */
function get_sort_order($folder): array {
    $folder = trim(str_replace(DS, '/', (string)$folder), '/');
    $parent_id = $folder !== '' ? note_id_by_path($folder) : null;

    if($parent_id === null && $folder !== '') return [];

    $where = $parent_id === null ? 'parent_id IS NULL' : 'parent_id = ' . (int)$parent_id;
    return get_db()->query("SELECT slug FROM notes WHERE $where ORDER BY sort_order ASC, updated_at DESC")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function save_sort_order($folder, array $slugs): bool {
    $folder = trim(str_replace(DS, '/', (string)$folder), '/');
    $parent_id = $folder !== '' ? note_id_by_path($folder) : null;
    if($parent_id === null && $folder !== '') return false;

    $db = get_db();
    if($parent_id === null) {
        $upd = $db->prepare("UPDATE notes SET sort_order = ? WHERE slug = ? AND parent_id IS NULL");
        foreach(array_values($slugs) as $i => $slug) $upd->execute([$i, $slug]);
    } else {
        $upd = $db->prepare("UPDATE notes SET sort_order = ? WHERE slug = ? AND parent_id = ?");
        foreach(array_values($slugs) as $i => $slug) $upd->execute([$i, $slug, $parent_id]);
    }

    cache_delete('tree');
    return true;
}

// ============================================================
// Folders — no-op in the DB model (every node is a note; no bare folders)
// ============================================================

function create_folder($relative_path): bool { return true; }
function delete_folder($relative_path): bool { return true; }

function resolve_note_icon_value(array $input, string $existing_icon = ''): string {
    if(!array_key_exists('icon', $input)) {
        return $existing_icon;
    }

    $icon = $input['icon'];

    if($icon === null) {
        return '';
    }

    if(!is_string($icon)) {
        return $existing_icon;
    }

    return $icon;
}

// ============================================================
// Uploads (filesystem) and image-reference scanning (DB)
// ============================================================

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
        return '/file/' . str_replace(DS, '/', $subdir) . '/' . $filename . '.svg';
    }

    // Raster images — convert to WebP via Imagick
    $filepath = $target_dir . DS . $filename . '.webp';

    try {
        $imagick = new \Imagick($source_path);
        $imagick->autoOrient();
        $imagick->stripImage();

        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();
        if($w > 10240 || $h > 10240) {
            $imagick->destroy();
            return null;
        }

        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality(80);

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

    return '/file/' . str_replace(DS, '/', $subdir) . '/' . $filename . '.webp';
}

function minify_svg(string $svg): ?string {
    return sanitize_svg_icon($svg);
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
    $relative = upload_url_to_relative_path($url);
    if($relative === null || str_contains($relative, '..')) {
        return false;
    }

    $filepath = ABSPATH . DS . 'uploads' . DS . str_replace('/', DS, $relative);
    if(file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/** All image URLs referenced by a note and its descendants (blocks + covers). */
function collect_note_image_urls(string $relative_path): array {
    $path = note_path_from_relative($relative_path);
    $stmt = get_db()->prepare("SELECT content, cover FROM notes WHERE path = ? OR path LIKE ?");
    $stmt->execute([$path, $path . '/%']);

    $urls = [];
    foreach($stmt as $r) {
        $data = json_decode($r['content'], true);
        $urls = array_merge($urls, extract_image_urls($data['blocks'] ?? $data['content']['blocks'] ?? []));
        if(!empty($r['cover'])) $urls[] = $r['cover'];
    }
    return $urls;
}

/** True if the upload filename is referenced by any public/unlisted note. */
function is_upload_referenced_in_public_note(string $filename): bool {
    $like = '%' . $filename . '%';
    $stmt = get_db()->prepare(
        "SELECT 1 FROM notes WHERE visibility IN ('public', 'unlisted')
         AND (content LIKE ? OR cover LIKE ?) LIMIT 1"
    );
    $stmt->execute([$like, $like]);
    return (bool)$stmt->fetchColumn();
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
                $page_title = htmlspecialchars($data['title'] ?? 'Page', ENT_QUOTES, 'UTF-8');
                $page_url = $data['pageUrl'] ?? '';
                $page_icon = $data['icon'] ?? '';
                if($page_url) {
                    if($page_icon && !str_starts_with($page_icon, 'data:')) {
                        $icon_html = '<span class="cdx-page-link-icon">' . $page_icon . '</span>';
                    } elseif($page_icon && str_starts_with($page_icon, 'data:')) {
                        $icon_html = '<img class="cdx-page-link-icon" src="' . htmlspecialchars($page_icon, ENT_QUOTES, 'UTF-8') . '" width="16" height="16" alt="">';
                    } else {
                        $icon_html = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                    }
                    $html .= '<div class="cdx-page-link"><a href="' . HOME_URL . htmlspecialchars($page_url, ENT_QUOTES, 'UTF-8') . '/">' . $icon_html . ' <span>' . $page_title . '</span></a></div>' . "\n";
                }
                break;

            default:
                if(!empty($data['text'])) {
                    $html .= "<p>{$data['text']}</p>\n";
                }
                break;
        }
    }

    return $html;
}
