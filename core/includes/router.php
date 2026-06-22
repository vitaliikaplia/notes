<?php

if(!defined('ABSPATH')){exit;}

function get_url_segments(): ?array {
    if(!isset($_SERVER['REQUEST_URI']) || !$_SERVER['REQUEST_URI']){
        return null;
    }

    if(!($parsed_url = parse_url($_SERVER['REQUEST_URI']))){
        return null;
    }

    if(empty($url_segments = explode('/', trim($parsed_url['path'], '/')))){
        return null;
    }

    $url_segments = array_map('urldecode', $url_segments);

    return $url_segments;
}

function router($url_segments = []): array {
    $context = get_context();
    $body_classes = [];

    if(empty($url_segments[0])) {
        // Dashboard
        auth_require();
        $template = 'index.twig';
        $context['page']['title'] = 'Notes';
        $context['html_title'] = SITE_NAME;
        $context['recent_notes'] = get_recent_notes(35);
        $context['pinned_notes'] = array_values(array_filter($context['recent_notes'], fn($n) => !empty($n['meta']['pinned'])));
        $context['recent_notes'] = array_values(array_filter($context['recent_notes'], fn($n) => empty($n['meta']['pinned'])));
        // Timeline: all notes sorted by date, pinned first within each day
        $context['timeline_notes'] = array_merge($context['pinned_notes'], $context['recent_notes']);
        usort($context['timeline_notes'], function($a, $b) {
            $da = substr($a['meta']['updated_at'] ?? '', 0, 10);
            $db = substr($b['meta']['updated_at'] ?? '', 0, 10);
            if($da !== $db) return $db <=> $da; // newest day first
            $pa = !empty($a['meta']['pinned']) ? 0 : 1;
            $pb = !empty($b['meta']['pinned']) ? 0 : 1;
            if($pa !== $pb) return $pa - $pb; // pinned first within day
            return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? ''); // then by time
        });
        $context['media_items'] = collect_media_from_notes(array_merge($context['pinned_notes'], $context['recent_notes']));
        $context['ai_configured'] = ai_is_configured();

        // Collect unique parent groups for the filter
        $parent_groups = [];
        foreach(array_merge($context['pinned_notes'], $context['recent_notes']) as $note) {
            $dir = dirname($note['_file']);
            if($dir !== '.') {
                $parent_file = $dir . '.json';
                if(!isset($parent_groups[$parent_file])) {
                    $parent_note = get_note($parent_file);
                    $parent_groups[$parent_file] = [
                        'file' => $parent_file,
                        'title' => $parent_note ? $parent_note['_title'] : $dir,
                    ];
                }
            }
        }
        usort($parent_groups, fn($a, $b) => strcasecmp($a['title'], $b['title']));
        $context['parent_groups'] = $parent_groups;

        $body_classes[] = 'page-index';

    } elseif($url_segments[0] === 'login') {
        // Login page
        if(auth_check()) {
            header('Location: ' . HOME_URL);
            exit;
        }

        $captcha_site_key = get_option('CAPTCHA_SITE_KEY', '');
        $captcha_secret_key = get_option('CAPTCHA_SECRET_KEY', '');
        $context['captcha_site_key'] = $captcha_site_key;
        $context['error'] = '';

        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = $_POST['user'] ?? '';
            $pass = $_POST['pass'] ?? '';
            $captcha_ok = true;

            // Verify Cloudflare Turnstile CAPTCHA if configured
            if($captcha_site_key && $captcha_secret_key) {
                $cf_token = $_POST['cf-turnstile-response'] ?? '';
                $captcha_ok = $cf_token && verify_turnstile($captcha_secret_key, $cf_token);
                if(!$captcha_ok) {
                    $context['error'] = 'CAPTCHA verification failed';
                }
            }

            if($captcha_ok) {
                $remember = !empty($_POST['remember']);
                if(auth_login($user, $pass, $remember)) {
                    header('Location: ' . HOME_URL);
                    exit;
                } else {
                    $context['error'] = 'Invalid username or password';
                }
            }
        }

        $template = 'login.twig';
        $context['page']['title'] = 'Log In';
        $context['page']['description'] = 'Sign in to access your notes';
        $context['robots'] = 'noindex, nofollow';
        $body_classes[] = 'page-login';

    } elseif($url_segments[0] === 'logout') {
        auth_logout();

    } elseif($url_segments[0] === 'new') {
        // New note
        auth_require();
        $template = 'editor.twig';
        $context['page']['title'] = 'New Note';
        $context['note'] = null;
        $context['note_folder'] = '';
        $context['breadcrumbs'] = [];
        $body_classes[] = 'page-editor';

        // folder path from URL: /new/folder/subfolder/
        if(!empty($url_segments[1])) {
            $folder_parts = array_slice($url_segments, 1);
            $context['note_folder'] = implode('/', $folder_parts);
        }

    } elseif($url_segments[0] === 'note') {
        // Open note
        $path_parts = array_slice($url_segments, 1);
        $relative_path = implode(DS, $path_parts) . '.json';
        $note = get_note($relative_path);

        if($note) {
            $visibility = $note['meta']['visibility'] ?? 'private';
            $is_authenticated = auth_check();

            // Private notes: 404 for unauthenticated users
            if($visibility === 'private' && !$is_authenticated) {
                header("HTTP/1.1 404 Not Found");
                $template = '404.twig';
                $context['page']['title'] = '404';
                $context['authenticated'] = false;
                $context['robots'] = 'noindex, nofollow';
                $body_classes[] = 'page-404';
                $context['body_classes'] = implode(' ', $body_classes);
                $context['html_title'] = $context['page']['title'] . ' — ' . SITE_NAME;
                return [$template, $context];
            }

            if($is_authenticated) {
                // Authenticated user — show editor as usual
                // Enrich page blocks with child note icons
                if(!empty($note['content']['blocks'])) {
                    foreach($note['content']['blocks'] as &$block) {
                        if($block['type'] === 'page' && !empty($block['data']['pagePath'])) {
                            $child_note = get_note($block['data']['pagePath']);
                            if($child_note) {
                                $block['data']['icon'] = $child_note['meta']['icon'] ?? '';
                            }
                        }
                    }
                    unset($block);
                }

                $template = 'editor.twig';
                $context['page']['title'] = $note['_title'];
                $context['note'] = $note;
                $context['note_folder'] = dirname($note['_file']);
                if($context['note_folder'] === '.') {
                    $context['note_folder'] = '';
                }
                $context['breadcrumbs'] = get_breadcrumbs($note['_file']);

                // Collect direct child notes (ordered)
                $context['children'] = [];
                $child_stmt = get_db()->prepare("SELECT * FROM notes WHERE parent_id = ? ORDER BY sort_order ASC, updated_at DESC");
                $child_stmt->execute([$note['_id']]);
                foreach($child_stmt as $crow) {
                    $child_note = db_row_to_note($crow);
                    $context['children'][] = [
                        'title' => $child_note['_title'],
                        'icon'  => $child_note['meta']['icon'] ?? '',
                        'path'  => $child_note['_file'],
                        'url'   => 'note/' . $child_note['_url'],
                    ];
                }
                $context['children_count'] = count($context['children']);

                $body_classes[] = 'page-editor';
            } else {
                // Public/unlisted — read-only view
                // Enrich page blocks with child note icons
                if(!empty($note['content']['blocks'])) {
                    foreach($note['content']['blocks'] as &$block) {
                        if($block['type'] === 'page' && !empty($block['data']['pagePath'])) {
                            $child_note = get_note($block['data']['pagePath']);
                            if($child_note) {
                                $block['data']['icon'] = $child_note['meta']['icon'] ?? '';
                            }
                        }
                    }
                    unset($block);
                }

                $template = 'public-note.twig';
                $context['page']['title'] = $note['_title'];
                $context['page']['description'] = get_note_excerpt($note);
                $context['note'] = $note;
                $body_classes[] = 'page-public-note';

                $context['note_color'] = $note['meta']['color'] ?? '';
                $context['note_text_color'] = $context['note_color'] ? contrast_color($context['note_color']) : '';

                $note_url = HOME_URL . 'note/' . $note['_url'] . '/';
                $context['canonical'] = $note_url;
                $context['og'] = [
                    'title' => $note['_title'],
                    'description' => get_note_excerpt($note),
                    'type' => 'article',
                    'url' => $note_url,
                ];

                if($visibility === 'unlisted') {
                    $context['robots'] = 'noindex, nofollow';
                }
            }
        } else {
            header("HTTP/1.1 404 Not Found");
            $template = '404.twig';
            $context['page']['title'] = '404';
            $context['authenticated'] = false;
            $body_classes[] = 'page-404';
        }

    } elseif($url_segments[0] === 'manifest.json' && $_SERVER['REQUEST_URI'] === '/manifest.json') {
        header('Content-Type: application/manifest+json; charset=UTF-8');

        $icons_dir = ABSPATH . DS . 'assets' . DS . 'img';
        $icon_files = [
            ['file' => 'icon-48.png',          'sizes' => '48x48',   'purpose' => 'any'],
            ['file' => 'icon-72.png',          'sizes' => '72x72',   'purpose' => 'any'],
            ['file' => 'icon-96.png',          'sizes' => '96x96',   'purpose' => 'any'],
            ['file' => 'icon-144.png',         'sizes' => '144x144', 'purpose' => 'any'],
            ['file' => 'icon-192.png',         'sizes' => '192x192', 'purpose' => 'any'],
            ['file' => 'icon-512.png',         'sizes' => '512x512', 'purpose' => 'any'],
            ['file' => 'icon-maskable-192.png','sizes' => '192x192', 'purpose' => 'maskable'],
            ['file' => 'icon-maskable-512.png','sizes' => '512x512', 'purpose' => 'maskable'],
        ];

        $icons = [];
        foreach ($icon_files as $icon) {
            $path = $icons_dir . DS . $icon['file'];
            $ver = file_exists($path) ? '?v=' . filemtime($path) : '';
            $icons[] = [
                'src'     => '/assets/img/' . $icon['file'] . $ver,
                'sizes'   => $icon['sizes'],
                'type'    => 'image/png',
                'purpose' => $icon['purpose'],
            ];
        }

        echo json_encode([
            'name'             => 'Notes',
            'short_name'       => 'Notes',
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => '#191919',
            'theme_color'      => '#202020',
            'icons'            => $icons,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    } elseif($url_segments[0] === 'api') {

        // REST API v1 — token auth
        if (isset($url_segments[1]) && $url_segments[1] === 'v1') {
            api_dispatch(array_slice($url_segments, 2));
            exit;
        }

        // Internal API — session auth
        auth_require();
        header('Content-Type: application/json; charset=UTF-8');

        $action = $url_segments[1] ?? '';

        if($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if(empty($input)) {
                echo json_encode(['success' => false, 'error' => 'Empty input']);
                exit;
            }

            $title = strip_tags($input['title'] ?? 'Untitled');
            $folder = trim($input['folder'] ?? '', '/ ');
            $old_path_raw = $input['old_path'] ?? '';
            $content = $input['content'] ?? ['blocks' => []];
            $cover = $input['cover'] ?? null;
            $cover_position = isset($input['cover_position']) ? intval($input['cover_position']) : null;
            $color = $input['color'] ?? null;
            $pinned = isset($input['pinned']) ? (bool)$input['pinned'] : null;

            // Existing note (for preserving fields and orphan-image cleanup)
            $old_path  = $old_path_raw ? note_path_from_relative($old_path_raw) : '';
            $old_note  = $old_path !== '' ? get_note($old_path) : null;
            $old_image_urls = [];
            if($old_note) {
                $old_image_urls = extract_image_urls($old_note['content']['blocks'] ?? []);
                if(!empty($old_note['meta']['cover'])) {
                    $old_image_urls[] = $old_note['meta']['cover'];
                }
            }

            $slug = generate_slug($title);
            $new_path = ($folder ? $folder . '/' : '') . $slug;
            $relative_path = $new_path . '.json';

            $now = date('c');

            // Preserve existing fields when re-saving
            $existing_visibility = $old_note['meta']['visibility'] ?? 'private';
            $existing_icon       = $old_note['meta']['icon'] ?? '';
            $existing_cover      = $old_note['meta']['cover'] ?? '';
            $existing_cover_pos  = $old_note['meta']['cover_position'] ?? 50;
            $existing_color      = $old_note['meta']['color'] ?? '';
            $existing_pinned     = $old_note['meta']['pinned'] ?? false;
            $existing_created    = $old_note['meta']['created_at'] ?? null;
            $icon = resolve_note_icon_value($input, $existing_icon);

            $note_data = [
                'meta' => [
                    'title' => $title,
                    'icon' => $icon,
                    'cover' => $cover !== null ? $cover : $existing_cover,
                    'cover_position' => $cover_position !== null ? $cover_position : $existing_cover_pos,
                    'color' => $color !== null ? $color : $existing_color,
                    'pinned' => $pinned !== null ? $pinned : $existing_pinned,
                    'visibility' => $existing_visibility,
                    'created_at' => $input['created_at'] ?? ($existing_created ?? $now),
                    'updated_at' => $now,
                ],
                'content' => $content,
            ];

            // Slug/path changed -> rename in place (keeps child rows + their parent links)
            if($old_path !== '' && $old_path !== $new_path && note_exists($old_path)) {
                // Update page-link blocks inside this note that point at its own children
                $old_prefix = $old_path . '/';
                $new_prefix = $new_path . '/';
                if(!empty($note_data['content']['blocks'])) {
                    foreach($note_data['content']['blocks'] as &$block) {
                        if($block['type'] === 'page' && !empty($block['data']['pagePath'])) {
                            $block['data']['pagePath'] = str_replace($old_prefix, $new_prefix, $block['data']['pagePath']);
                            $block['data']['pageUrl'] = 'note/' . preg_replace('/\.json$/', '', $block['data']['pagePath']);
                        }
                    }
                    unset($block);
                }

                // Update the parent note's page block that referenced the old path
                $parent_dir = strpos($old_path, '/') !== false ? substr($old_path, 0, strrpos($old_path, '/')) : '';
                if($parent_dir !== '') {
                    $parent_note = get_note($parent_dir);
                    if($parent_note && !empty($parent_note['content']['blocks'])) {
                        $changed = false;
                        foreach($parent_note['content']['blocks'] as &$pblock) {
                            if($pblock['type'] === 'page' && ($pblock['data']['pagePath'] ?? '') === $old_path . '.json') {
                                $pblock['data']['pagePath'] = $relative_path;
                                $pblock['data']['pageUrl'] = 'note/' . $new_path;
                                $pblock['data']['title'] = $title;
                                $changed = true;
                            }
                        }
                        unset($pblock);
                        if($changed) update_note_content_blocks($parent_dir, $parent_note['content']['blocks']);
                    }
                }

                // Move the row (and its descendants) to the new path in place
                rename_note($old_path, $new_path);
            }

            $success = save_note($relative_path, $note_data);

            // Always sync parent's page block with current title/icon
            $current_dir = strpos($new_path, '/') !== false ? substr($new_path, 0, strrpos($new_path, '/')) : '';
            if($current_dir !== '') {
                $parent_note = get_note($current_dir);
                if($parent_note && !empty($parent_note['content']['blocks'])) {
                    $synced = false;
                    foreach($parent_note['content']['blocks'] as &$pblock) {
                        if($pblock['type'] === 'page' && ($pblock['data']['pagePath'] ?? '') === $relative_path) {
                            if(($pblock['data']['title'] ?? '') !== $title || ($pblock['data']['icon'] ?? '') !== $icon) {
                                $pblock['data']['title'] = $title;
                                $pblock['data']['icon'] = $icon;
                                $synced = true;
                            }
                        }
                    }
                    unset($pblock);
                    if($synced) update_note_content_blocks($current_dir, $parent_note['content']['blocks']);
                }
            }

            // Delete orphaned images (blocks + cover)
            if($success && !empty($old_image_urls)) {
                $new_image_urls = extract_image_urls($content['blocks'] ?? []);
                $new_cover = $note_data['meta']['cover'] ?? '';
                if($new_cover) {
                    $new_image_urls[] = $new_cover;
                }
                // Normalize both sides (host-relative) so an absolute old URL is not
                // treated as orphaned when the same image is now stored relative.
                $orphaned = array_diff(
                    array_map('normalize_upload_url', $old_image_urls),
                    array_map('normalize_upload_url', $new_image_urls)
                );
                foreach($orphaned as $url) {
                    delete_upload_by_url($url);
                }
            }

            echo json_encode([
                'success' => $success,
                'path' => $relative_path,
                'url' => 'note/' . preg_replace('/\.json$/', '', $relative_path),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $path = $input['path'] ?? '';

            if(empty($path)) {
                echo json_encode(['success' => false, 'error' => 'No path']);
                exit;
            }

            // Collect all image URLs before deletion (including children)
            $note_images = collect_note_image_urls($path);

            $success = delete_note($path);

            // Delete orphaned image files
            if($success && !empty($note_images)) {
                foreach($note_images as $url) {
                    delete_upload_by_url($url);
                }
            }

            echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $path = trim($input['path'] ?? '', '/ ');

            if(empty($path)) {
                echo json_encode(['success' => false, 'error' => 'No path']);
                exit;
            }

            $success = create_folder($path);
            echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'create-page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if(empty($input)) {
                echo json_encode(['success' => false, 'error' => 'Empty input']);
                exit;
            }

            $title = strip_tags(trim($input['title'] ?? ''));
            $parent_path = trim($input['parent_path'] ?? '');

            if(empty($title)) {
                echo json_encode(['success' => false, 'error' => 'Title is required']);
                exit;
            }

            if(empty($parent_path)) {
                echo json_encode(['success' => false, 'error' => 'Parent path is required']);
                exit;
            }

            // Derive subfolder from parent path
            $parent_folder = dirname($parent_path);
            if($parent_folder === '.') {
                $parent_folder = '';
            }
            $parent_slug = basename($parent_path, '.json');
            $child_folder = ($parent_folder ? $parent_folder . '/' : '') . $parent_slug;

            // Generate child slug and ensure uniqueness
            $child_slug = generate_slug($title);
            $child_relative_path = $child_folder . '/' . $child_slug . '.json';

            $base_slug = $child_slug;
            $counter = 1;
            while(note_exists($child_relative_path)) {
                $child_slug = $base_slug . '-' . $counter;
                $child_relative_path = $child_folder . '/' . $child_slug . '.json';
                $counter++;
            }

            // Create child note with header block
            $now = date('c');
            $child_data = [
                'meta' => [
                    'title' => $title,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'content' => [
                    'time' => round(microtime(true) * 1000),
                    'blocks' => [],
                    'version' => '2.30.8',
                ],
            ];

            $success = save_note($child_relative_path, $child_data);
            $url_path = 'note/' . preg_replace('/\.json$/', '', $child_relative_path);

            echo json_encode([
                'success' => $success,
                'path' => $child_relative_path,
                'url' => $url_path,
                'title' => $title,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'visibility' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $path = $input['path'] ?? '';
            $visibility = $input['visibility'] ?? '';

            if(empty($path) || !in_array($visibility, ['private', 'unlisted', 'public'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid input']);
                exit;
            }

            $success = update_note_visibility($path, $visibility);
            echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if(empty($input) || !isset($input['order']) || !is_array($input['order'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid input']);
                exit;
            }

            $folder = trim($input['folder'] ?? '');
            $order = $input['order'];

            // Sanitize slugs
            $clean_order = array_map(function($slug) {
                return preg_replace('/[^a-z0-9\-]/', '', $slug);
            }, $order);
            $clean_order = array_filter($clean_order);

            $success = save_sort_order($folder, array_values($clean_order));
            echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if(empty($input) || empty($input['source_path'])) {
                echo json_encode(['success' => false, 'error' => 'Missing source_path']);
                exit;
            }

            $source_path = $input['source_path'];
            $target_folder = trim($input['target_folder'] ?? '', '/ ');
            $position = $input['position'] ?? null;

            // Validate path safety
            if(str_contains($source_path, '..') || str_starts_with($source_path, '/')) {
                echo json_encode(['success' => false, 'error' => 'Invalid path']);
                exit;
            }

            $result = move_note($source_path, $target_folder);

            // After successful move, insert into sort order at the right position
            if($result['success'] && $position !== null) {
                $order = get_sort_order($target_folder);
                $new_slug = $result['new_slug'];

                // Remove if already present (safety)
                $order = array_values(array_filter($order, fn($s) => $s !== $new_slug));

                if(!empty($position['after_slug'])) {
                    $idx = array_search($position['after_slug'], $order);
                    if($idx !== false) {
                        array_splice($order, $idx + 1, 0, [$new_slug]);
                    } else {
                        $order[] = $new_slug;
                    }
                } elseif(!empty($position['before_slug'])) {
                    $idx = array_search($position['before_slug'], $order);
                    if($idx !== false) {
                        array_splice($order, $idx, 0, [$new_slug]);
                    } else {
                        array_unshift($order, $new_slug);
                    }
                } else {
                    $order[] = $new_slug;
                }

                save_sort_order($target_folder, $order);
            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $q = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');

            if(mb_strlen($q) < 2) {
                echo json_encode([]);
                exit;
            }

            $all_notes = collect_all_notes();
            $results = [];

            foreach($all_notes as $note) {
                $title = $note['_title'];
                $icon = $note['meta']['icon'] ?? '';
                $meta_text = mb_strtolower($title, 'UTF-8');
                $found_in_title = mb_strpos($meta_text, $q) !== false;

                $snippet = '';
                $found_in_content = false;

                if(!$found_in_title && !empty($note['content']['blocks'])) {
                    // Extract plain text from all blocks
                    $body = '';
                    foreach($note['content']['blocks'] as $block) {
                        $d = $block['data'] ?? [];
                        if(!empty($d['text'])) {
                            $body .= ' ' . strip_tags($d['text']);
                        }
                        if(!empty($d['code'])) {
                            $body .= ' ' . $d['code'];
                        }
                        if(!empty($d['items'])) {
                            foreach($d['items'] as $item) {
                                $body .= ' ' . strip_tags(is_array($item) ? ($item['content'] ?? $item['text'] ?? '') : $item);
                            }
                        }
                        if(!empty($d['title'])) {
                            $body .= ' ' . $d['title'];
                        }
                    }
                    $body_lower = mb_strtolower($body, 'UTF-8');
                    $pos = mb_strpos($body_lower, $q);
                    if($pos !== false) {
                        $found_in_content = true;
                        $start = max(0, $pos - 40);
                        $len = mb_strlen($q) + 80;
                        $raw = mb_substr($body, $start, $len, 'UTF-8');
                        $snippet = ($start > 0 ? '...' : '') . trim($raw) . '...';
                    }
                }

                if($found_in_title || $found_in_content) {
                    // Build breadcrumb path
                    $dir = dirname($note['_file']);
                    $path_label = '';
                    if($dir !== '.') {
                        $parts = explode('/', $dir);
                        $labels = [];
                        foreach($parts as $part) {
                            // Try to find parent note title
                            $ppath = (count($labels) ? implode('/', array_slice($parts, 0, array_search($part, $parts))) . '/' : '') . $part;
                            $pnote = get_note($ppath);
                            $labels[] = $pnote ? $pnote['_title'] : $part;
                        }
                        $path_label = implode(' / ', $labels);
                    }

                    $results[] = [
                        'title' => $title,
                        'icon' => $icon,
                        'url' => HOME_URL . 'note/' . $note['_url'] . '/',
                        'file' => $note['_file'],
                        'snippet' => $snippet,
                        'path' => $path_label,
                    ];
                }

                if(count($results) >= 20) break;
            }

            echo json_encode($results, JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'graph' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $all_notes = collect_all_notes();
            $nodes = [];
            $edges = [];

            foreach($all_notes as $note) {
                $id = $note['_file'];
                $node = [
                    'id'    => $id,
                    'title' => $note['_title'],
                    'icon'  => $note['meta']['icon'] ?? '',
                    'color' => $note['meta']['color'] ?? '',
                    'url'   => HOME_URL . 'note/' . $note['_url'] . '/',
                ];
                // Only restore saved position for parent notes (no "/" in path)
                $is_parent = strpos($note['_file'], '/') === false;
                if($is_parent && isset($note['meta']['graph_x']) && isset($note['meta']['graph_y'])) {
                    $node['fx'] = (float) $note['meta']['graph_x'];
                    $node['fy'] = (float) $note['meta']['graph_y'];
                }
                $nodes[] = $node;

                // Parent-child edge (filesystem hierarchy)
                $dir = dirname($note['_file']);
                if($dir !== '.') {
                    $edges[] = [
                        'source' => $dir . '.json',
                        'target' => $id,
                        'type'   => 'child',
                    ];
                }

                // Page-block edges (inline links to other notes)
                if(!empty($note['content']['blocks'])) {
                    foreach($note['content']['blocks'] as $block) {
                        if(($block['type'] ?? '') === 'page' && !empty($block['data']['pagePath'])) {
                            $edges[] = [
                                'source' => $id,
                                'target' => $block['data']['pagePath'],
                                'type'   => 'page-link',
                            ];
                        }
                    }
                }
            }

            // Deduplicate edges
            $seen = [];
            $unique_edges = [];
            foreach($edges as $edge) {
                $key = $edge['source'] . '>' . $edge['target'];
                if(!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique_edges[] = $edge;
                }
            }

            // Filter edges where both nodes exist
            $node_ids = array_flip(array_column($nodes, 'id'));
            $valid_edges = array_values(array_filter($unique_edges, function($e) use ($node_ids) {
                return isset($node_ids[$e['source']]) && isset($node_ids[$e['target']]);
            }));

            echo json_encode(['nodes' => $nodes, 'edges' => $valid_edges], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'graph' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Save node position: { id: "file.json", x: 123.4, y: 567.8 }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? '';
            $x = $input['x'] ?? null;
            $y = $input['y'] ?? null;

            if(empty($id) || $x === null || $y === null) {
                http_response_code(400);
                echo json_encode(['success' => 0, 'error' => 'Missing id, x or y']);
                exit;
            }

            if(!note_exists($id)) {
                http_response_code(404);
                echo json_encode(['success' => 0, 'error' => 'Note not found']);
                exit;
            }

            $ok = update_note_graph_position($id, $x, $y);

            echo json_encode(['success' => $ok ? 1 : 0]);
            exit;

        } elseif($action === 'graph' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
            // Reset all saved node positions
            $count = reset_graph_positions();
            echo json_encode(['success' => 1, 'reset' => $count]);
            exit;

        } elseif($action === 'fetch-url' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $url = $_GET['url'] ?? '';

            if(empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => 0, 'error' => 'Invalid URL']);
                exit;
            }

            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'Mozilla/5.0 (compatible; NotesApp/1.0)',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $html = @file_get_contents($url, false, $ctx);

            if($html === false) {
                echo json_encode(['success' => 0, 'error' => 'Could not fetch URL']);
                exit;
            }

            // Detect encoding and convert to UTF-8
            if(preg_match('/<meta[^>]+charset=["\']?([^"\'\s;>]+)/i', $html, $cm)) {
                $charset = strtolower($cm[1]);
                if($charset !== 'utf-8') {
                    $html = @mb_convert_encoding($html, 'UTF-8', $charset);
                }
            }

            $title = '';
            $description = '';
            $image = '';

            // og:title or <title>
            if(preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
                $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            } elseif(preg_match('/<title[^>]*>([^<]+)/i', $html, $m)) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // og:description or meta description
            if(preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            } elseif(preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }

            // og:image
            if(preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
                $image = $m[1];
            }

            echo json_encode([
                'success' => 1,
                'link' => $url,
                'meta' => [
                    'title' => $title,
                    'description' => $description,
                    'image' => $image ? ['url' => $image] : (object)[],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;

        } elseif($action === 'export-md' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $blocks = $input['blocks'] ?? [];
            $title = strip_tags(trim($input['title'] ?? ''));
            $md = '';
            if ($title) {
                $md .= '# ' . $title . "\n\n";
            }
            $md .= blocks_to_markdown($blocks);
            echo json_encode(['markdown' => $md], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'import-md' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $title = strip_tags(trim($input['title'] ?? ''));
            $markdown = $input['markdown'] ?? '';

            if (empty($title)) {
                $title = 'Imported note';
            }

            $slug = generate_slug($title) . '_imported_' . date('Ymd_His');
            $relative = $slug . '.json';

            $now = date('c');
            $blocks = !empty($markdown) ? markdown_to_blocks($markdown) : [];

            $note_data = [
                'meta' => [
                    'title'      => $title,
                    'icon'       => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'content' => [
                    'time'    => round(microtime(true) * 1000),
                    'blocks'  => $blocks,
                    'version' => '2.31.0-rc.7',
                ],
            ];

            $success = save_note($relative, $note_data);
            $path = preg_replace('/\.json$/', '', $relative);
            echo json_encode([
                'success' => $success,
                'url'     => 'note/' . $path,
                'path'    => $path,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'process-svg' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $raw_svg = $input['svg'] ?? '';

            if(empty($raw_svg)) {
                echo json_encode(['success' => false, 'error' => 'No SVG provided']);
                exit;
            }

            $minified = minify_svg($raw_svg);
            if(!$minified) {
                echo json_encode(['success' => false, 'error' => 'Invalid SVG']);
                exit;
            }

            $data_uri = 'data:image/svg+xml;base64,' . base64_encode($minified);
            echo json_encode(['success' => true, 'data_uri' => $data_uri]);
            exit;

        } elseif($action === 'emoji-search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $q = trim($_GET['q'] ?? '');
            if(mb_strlen($q, 'UTF-8') < 1) {
                echo '[]';
                exit;
            }
            echo json_encode(emoji_search($q, 50), JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'upload-image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if(empty($_FILES['image'])) {
                echo json_encode(['success' => 0]);
                exit;
            }

            $file = $_FILES['image'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if(!in_array($mime, $allowed)) {
                echo json_encode(['success' => 0]);
                exit;
            }

            $result = save_uploaded_image($file['tmp_name'], $mime);
            if(!$result) {
                echo json_encode(['success' => 0]);
                exit;
            }

            echo json_encode(['success' => 1, 'file' => ['url' => $result]]);
            exit;

        } elseif($action === 'fetch-image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $url = $input['url'] ?? '';

            if(empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => 0]);
                exit;
            }

            $ctx = stream_context_create([
                'http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"],
                'ssl' => ['verify_peer' => false]
            ]);

            $data = @file_get_contents($url, false, $ctx);
            if(!$data) {
                echo json_encode(['success' => 0]);
                exit;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_buffer($finfo, $data);
            finfo_close($finfo);

            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            if(!in_array($mime, $allowed)) {
                echo json_encode(['success' => 0]);
                exit;
            }

            // Save to temp file for processing
            $tmp = tempnam(sys_get_temp_dir(), 'img_');
            file_put_contents($tmp, $data);

            $result = save_uploaded_image($tmp, $mime);
            @unlink($tmp);

            if(!$result) {
                echo json_encode(['success' => 0]);
                exit;
            }

            echo json_encode(['success' => 1, 'file' => ['url' => $result]]);
            exit;

        } elseif($action === 'notes-page' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $offset = max(0, intval($_GET['offset'] ?? 0));
            $limit = min(100, max(1, intval($_GET['limit'] ?? 35)));
            $sort = $_GET['sort'] ?? 'recent';
            $all = collect_all_notes();
            // Separate pinned notes
            $pinned_all = array_values(array_filter($all, fn($n) => !empty($n['meta']['pinned'])));
            $all = array_values(array_filter($all, fn($n) => empty($n['meta']['pinned'])));
            usort($all, function($a, $b) use ($sort) {
                if ($sort === 'oldest') return ($a['meta']['updated_at'] ?? '') <=> ($b['meta']['updated_at'] ?? '');
                if ($sort === 'az') return strcasecmp($a['_title'] ?? '', $b['_title'] ?? '');
                if ($sort === 'za') return strcasecmp($b['_title'] ?? '', $a['_title'] ?? '');
                return ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? '');
            });
            usort($pinned_all, fn($a, $b) => ($b['meta']['updated_at'] ?? '') <=> ($a['meta']['updated_at'] ?? ''));
            $slice = array_slice($all, $offset, $limit);
            $build_card = function($note) {
                $preview = '';
                if(!empty($note['content']['blocks'])) {
                    foreach(array_slice($note['content']['blocks'], 0, 2) as $block) {
                        if(($block['type'] ?? '') === 'paragraph') {
                            $text = strip_tags($block['data']['text'] ?? '');
                            $preview .= mb_substr($text, 0, 100);
                        }
                    }
                }
                $file = $note['_file'];
                $parts = explode('/', $file);
                $parent = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) . '.json' : '';
                return [
                    'url'      => $note['_url'],
                    'title'    => $note['_title'],
                    'date'     => $note['meta']['updated_at'] ?? '',
                    'date_fmt' => date_format_uk($note['meta']['updated_at'] ?? ''),
                    'icon'     => $note['meta']['icon'] ?? '',
                    'cover'    => $note['meta']['cover'] ?? '',
                    'cover_position' => $note['meta']['cover_position'] ?? 50,
                    'color'    => $note['meta']['color'] ?? '',
                    'preview'  => $preview,
                    'parent'   => $parent,
                    'pinned'   => !empty($note['meta']['pinned']),
                ];
            };
            $cards = array_map($build_card, $slice);
            $pinned_cards = $offset === 0 ? array_map($build_card, $pinned_all) : [];
            echo json_encode([
                'cards' => $cards,
                'pinned' => $pinned_cards,
                'total' => count($all),
                'has_more' => ($offset + $limit) < count($all),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'toggle-pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $url = $input['url'] ?? '';
            $pinned = toggle_note_pinned($url);
            if($pinned === null) {
                echo json_encode(['success' => false, 'error' => 'Note not found']);
                exit;
            }
            echo json_encode(['success' => true, 'pinned' => $pinned]);
            exit;

        } elseif($action === 'chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $messages = $input['messages'] ?? [];
            $result = ai_chat($messages);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'clear-cache' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $version = bump_assets_version();
            $result = clear_app_cache();
            echo json_encode(['success' => true, 'assets_version' => $version, 'result' => $result]);
            exit;

        } elseif($action === 'options' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode([
                'REDIS_SOCKET'       => get_option('REDIS_SOCKET', ''),
                'API_TOKEN'          => get_option('API_TOKEN', ''),
                'CAPTCHA_SITE_KEY'   => get_option('CAPTCHA_SITE_KEY', ''),
                'CAPTCHA_SECRET_KEY' => get_option('CAPTCHA_SECRET_KEY', ''),
                'AI_PROVIDER'        => get_option('AI_PROVIDER', ''),
                'AI_API_KEY'         => get_option('AI_API_KEY', ''),
                'AI_MODEL'           => get_option('AI_MODEL', ''),
                'AI_HISTORY_LIMIT'   => get_option('AI_HISTORY_LIMIT', '20'),
                'login'              => get_option('AUTH_USER', ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif($action === 'save-options' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];

            // Password change — only when a new password is supplied
            $new_password = (string)($input['new_password'] ?? '');
            if($new_password !== '') {
                $current = (string)($input['current_password'] ?? '');
                $confirm = (string)($input['confirm_password'] ?? '');

                if(!auth_verify_password($current, get_option('AUTH_PASS', ''))) {
                    echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                    exit;
                }
                if($new_password !== $confirm) {
                    echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
                    exit;
                }
                auth_set_password($new_password);
            }

            // Admin login
            if(isset($input['login'])) {
                $login = trim((string)$input['login']);
                if($login !== '') {
                    set_option('AUTH_USER', $login);
                }
            }

            // AI provider (validated enum)
            if(isset($input['AI_PROVIDER'])) {
                $provider = (string)$input['AI_PROVIDER'];
                if(in_array($provider, ['', 'claude', 'openai', 'gemini'], true)) {
                    set_option('AI_PROVIDER', $provider);
                }
            }

            // Plain settings (whitelisted)
            $allowed = ['REDIS_SOCKET', 'API_TOKEN', 'CAPTCHA_SITE_KEY', 'CAPTCHA_SECRET_KEY', 'AI_API_KEY', 'AI_MODEL', 'AI_HISTORY_LIMIT'];
            foreach($allowed as $key) {
                if(array_key_exists($key, $input)) {
                    set_option($key, (string)$input[$key]);
                }
            }

            echo json_encode(['success' => true, 'ai_configured' => ai_is_configured()]);
            exit;

        } else {
            echo json_encode(['error' => 'Unknown action']);
            exit;
        }

    } elseif($url_segments[0] === 'file') {
        // Serve uploaded images with auth check
        $relative = implode('/', array_slice($url_segments, 1));
        $serve = false;

        if(!empty($relative) && !str_contains($relative, '..')) {
            $filepath = ABSPATH . DS . 'uploads' . DS . str_replace('/', DS, $relative);
            if(file_exists($filepath) && is_file($filepath)) {
                if(auth_check()) {
                    $serve = true;
                } else {
                    $filename = basename($relative);
                    if(is_upload_referenced_in_public_note($filename)) {
                        $serve = true;
                    }
                }
            }
        }

        if(!$serve) {
            // Always 404 — never reveal whether file exists
            header("HTTP/1.1 404 Not Found");
            $template = '404.twig';
            $context['page']['title'] = '404';
            $context['authenticated'] = false;
            $context['robots'] = 'noindex, nofollow';
            $body_classes[] = 'page-404';
            $context['body_classes'] = implode(' ', $body_classes);
            $context['html_title'] = '404 — ' . SITE_NAME;
            echo get_template($template, $context);
            exit;
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_map = [
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
        ];
        header('Content-Type: ' . ($mime_map[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($filepath);
        exit;

    } else {
        // 404
        header("HTTP/1.1 404 Not Found");
        $template = '404.twig';
        $context['page']['title'] = '404';
        $context['authenticated'] = false;
        $context['robots'] = 'noindex, nofollow';
        $body_classes[] = 'page-404';
    }

    $context['body_classes'] = implode(' ', $body_classes);
    if(empty($context['html_title'])) {
        $context['html_title'] = $context['page']['title'] . ' — ' . SITE_NAME;
    }

    return [$template, $context];
}

// --- Main execution ---
if ($url_segments = get_url_segments()) {

    // trailing slash enforcement (skip for API and static)
    if(
        !empty($url_segments[0])
        && $url_segments[0] !== 'api'
        && $url_segments[0] !== 'file'
        && $url_segments[0] !== 'manifest.json'
        && empty($_GET)
        && substr($_SERVER['REQUEST_URI'], -1) !== '/'
    ){
        header("Location: " . HOME_URL . trim($_SERVER['REQUEST_URI'], '/') . '/', true, 301);
        exit;
    }

    list($template, $context) = router($url_segments);

    echo get_template($template, $context);
}
