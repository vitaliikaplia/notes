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
        $context['page']['title'] = 'Нотатки';
        $context['recent_notes'] = get_recent_notes();
        $body_classes[] = 'page-index';

    } elseif($url_segments[0] === 'login') {
        // Login page
        if(auth_check()) {
            header('Location: ' . HOME_URL);
            exit;
        }

        $env = get_env();
        $captcha_site_key = $env['CAPTCHA_SITE_KEY'] ?? '';
        $captcha_secret_key = $env['CAPTCHA_SECRET_KEY'] ?? '';
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
                    $context['error'] = 'Перевірка CAPTCHA не пройдена';
                }
            }

            if($captcha_ok) {
                if(auth_login($user, $pass)) {
                    header('Location: ' . HOME_URL);
                    exit;
                } else {
                    $context['error'] = 'Невірний логін або пароль';
                }
            }
        }

        $template = 'login.twig';
        $context['page']['title'] = 'Вхід';
        $body_classes[] = 'page-login';

    } elseif($url_segments[0] === 'logout') {
        auth_logout();

    } elseif($url_segments[0] === 'new') {
        // New note
        auth_require();
        $template = 'editor.twig';
        $context['page']['title'] = 'Нова нотатка';
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
        // Open note in editor (Notion-style: view = edit)
        auth_require();

        $path_parts = array_slice($url_segments, 1);
        $relative_path = implode(DS, $path_parts) . '.json';
        $note = get_note($relative_path);

        if($note) {
            // Enrich page blocks with child note icons
            if(!empty($note['content']['blocks'])) {
                foreach($note['content']['blocks'] as &$block) {
                    if($block['type'] === 'page' && !empty($block['data']['pagePath'])) {
                        $child_file = get_notes_path() . DS . $block['data']['pagePath'];
                        if(file_exists($child_file)) {
                            $child_data = json_decode(file_get_contents($child_file), true);
                            $block['data']['icon'] = $child_data['meta']['icon'] ?? '';
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
            $body_classes[] = 'page-editor';
        } else {
            header("HTTP/1.1 404 Not Found");
            $template = '404.twig';
            $context['page']['title'] = '404';
            $context['authenticated'] = false;
            $body_classes[] = 'page-404';
        }

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

            $title = strip_tags($input['title'] ?? 'Без назви');
            $folder = trim($input['folder'] ?? '', '/ ');
            $old_path = $input['old_path'] ?? '';
            $content = $input['content'] ?? ['blocks' => []];
            $icon = $input['icon'] ?? '';

            $slug = generate_slug($title);
            $relative_path = ($folder ? $folder . '/' : '') . $slug . '.json';

            $now = date('c');
            $note_data = [
                'meta' => [
                    'title' => $title,
                    'icon' => $icon,
                    'created_at' => $input['created_at'] ?? $now,
                    'updated_at' => $now,
                ],
                'content' => $content,
            ];

            // if slug changed, rename child folder and update page block references
            if($old_path && $old_path !== $relative_path) {
                $old_slug = basename($old_path, '.json');
                $new_slug = basename($relative_path, '.json');
                $old_dir = dirname(get_notes_path() . DS . $old_path);
                $old_child_dir = $old_dir . DS . $old_slug;
                $new_child_dir = $old_dir . DS . $new_slug;

                // Rename child folder if exists
                if(is_dir($old_child_dir) && !is_dir($new_child_dir)) {
                    rename($old_child_dir, $new_child_dir);
                }

                // Update page block references in content
                $old_prefix = ($folder ? $folder . '/' : '') . $old_slug . '/';
                $new_prefix = ($folder ? $folder . '/' : '') . $new_slug . '/';
                if(!empty($note_data['content']['blocks'])) {
                    foreach($note_data['content']['blocks'] as &$block) {
                        if($block['type'] === 'page' && !empty($block['data']['pagePath'])) {
                            $block['data']['pagePath'] = str_replace($old_prefix, $new_prefix, $block['data']['pagePath']);
                            $block['data']['pageUrl'] = 'note/' . preg_replace('/\.json$/', '', $block['data']['pagePath']);
                        }
                    }
                    unset($block);
                }

                // Update parent note's page blocks that reference this child
                $parent_dir = dirname($old_path);
                if($parent_dir !== '.') {
                    // child is inside a subfolder — parent note is one level up
                    $parent_note_path = $parent_dir . '.json';
                    $parent_full = get_notes_path() . DS . $parent_note_path;
                    if(file_exists($parent_full)) {
                        $parent_data = json_decode(file_get_contents($parent_full), true);
                        if(!empty($parent_data['content']['blocks'])) {
                            $changed = false;
                            foreach($parent_data['content']['blocks'] as &$pblock) {
                                if($pblock['type'] === 'page' && !empty($pblock['data']['pagePath']) && $pblock['data']['pagePath'] === $old_path) {
                                    $pblock['data']['pagePath'] = $relative_path;
                                    $pblock['data']['pageUrl'] = 'note/' . preg_replace('/\.json$/', '', $relative_path);
                                    $pblock['data']['title'] = $title;
                                    $changed = true;
                                }
                            }
                            unset($pblock);
                            if($changed) {
                                file_put_contents($parent_full, json_encode($parent_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            }
                        }
                    }
                }

                // Delete old note file (without cascading to children)
                $old_file = get_notes_path() . DS . $old_path;
                if(file_exists($old_file)) {
                    unlink($old_file);
                }
            }

            $success = save_note($relative_path, $note_data);

            // Always sync parent's page block with current title/icon
            $current_dir = dirname($relative_path);
            if($current_dir !== '.') {
                $parent_note_path = $current_dir . '.json';
                $parent_full = get_notes_path() . DS . $parent_note_path;
                if(file_exists($parent_full)) {
                    $parent_data = json_decode(file_get_contents($parent_full), true);
                    if(!empty($parent_data['content']['blocks'])) {
                        $synced = false;
                        foreach($parent_data['content']['blocks'] as &$pblock) {
                            if($pblock['type'] === 'page' && !empty($pblock['data']['pagePath']) && $pblock['data']['pagePath'] === $relative_path) {
                                if(($pblock['data']['title'] ?? '') !== $title || ($pblock['data']['icon'] ?? '') !== $icon) {
                                    $pblock['data']['title'] = $title;
                                    $pblock['data']['icon'] = $icon;
                                    $synced = true;
                                }
                            }
                        }
                        unset($pblock);
                        if($synced) {
                            file_put_contents($parent_full, json_encode($parent_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }
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

            $success = delete_note($path);
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
            while(file_exists(get_notes_path() . DS . $child_relative_path)) {
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

        } elseif($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if(empty($input) || !isset($input['order']) || !is_array($input['order'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid input']);
                exit;
            }

            $folder = trim($input['folder'] ?? '');
            $order = $input['order'];

            $base = get_notes_path();
            $dir = $folder ? $base . DS . str_replace('/', DS, $folder) : $base;

            if(!is_dir($dir)) {
                echo json_encode(['success' => false, 'error' => 'Directory not found']);
                exit;
            }

            // Sanitize slugs
            $clean_order = array_map(function($slug) {
                return preg_replace('/[^a-z0-9\-]/', '', $slug);
            }, $order);
            $clean_order = array_filter($clean_order);

            $success = save_sort_order($dir, array_values($clean_order));
            echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
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
                            $pjson = (count($labels) ? implode('/', array_slice($parts, 0, array_search($part, $parts))) . '/' : '') . $part . '.json';
                            $pfile = get_notes_path() . DS . str_replace('/', DS, $pjson);
                            if(file_exists($pfile)) {
                                $pdata = json_decode(file_get_contents($pfile), true);
                                $labels[] = $pdata['meta']['title'] ?? $part;
                            } else {
                                $labels[] = $part;
                            }
                        }
                        $path_label = implode(' / ', $labels);
                    }

                    $results[] = [
                        'title' => $title,
                        'icon' => $icon,
                        'url' => HOME_URL . 'note/' . $note['_url'] . '/',
                        'snippet' => $snippet,
                        'path' => $path_label,
                    ];
                }

                if(count($results) >= 20) break;
            }

            echo json_encode($results, JSON_UNESCAPED_UNICODE);
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
                $title = 'Імпортована нотатка';
            }

            $slug = generate_slug($title);
            $relative = $slug . '.json';

            $base_slug = $slug;
            $counter = 1;
            while (file_exists(get_notes_path() . DS . $relative)) {
                $slug = $base_slug . '-' . $counter;
                $relative = $slug . '.json';
                $counter++;
            }

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

        } else {
            echo json_encode(['error' => 'Unknown action']);
            exit;
        }

    } else {
        // 404
        header("HTTP/1.1 404 Not Found");
        $template = '404.twig';
        $context['page']['title'] = '404';
        $context['authenticated'] = false;
        $body_classes[] = 'page-404';
    }

    $context['body_classes'] = implode(' ', $body_classes);
    $context['html_title'] = $context['page']['title'] . ' — ' . SITE_NAME;

    return [$template, $context];
}

// --- Main execution ---
if ($url_segments = get_url_segments()) {

    // trailing slash enforcement (skip for API and static)
    if(
        !empty($url_segments[0])
        && $url_segments[0] !== 'api'
        && empty($_GET)
        && substr($_SERVER['REQUEST_URI'], -1) !== '/'
    ){
        header("Location: " . HOME_URL . trim($_SERVER['REQUEST_URI'], '/') . '/', true, 301);
        exit;
    }

    list($template, $context) = router($url_segments);

    echo get_template($template, $context);
}
