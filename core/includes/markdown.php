<?php

if(!defined('ABSPATH')){exit;}

// ============================================================
// Editor.js Blocks → Markdown
// ============================================================

function blocks_to_markdown(array $blocks): string {
    $lines = [];

    foreach ($blocks as $block) {
        $type = $block['type'] ?? '';
        $data = $block['data'] ?? [];

        switch ($type) {
            case 'header':
                $level = $data['level'] ?? 2;
                $prefix = str_repeat('#', $level);
                $lines[] = $prefix . ' ' . html_to_md_inline($data['text'] ?? '');
                $lines[] = '';
                break;

            case 'paragraph':
                $lines[] = html_to_md_inline($data['text'] ?? '');
                $lines[] = '';
                break;

            case 'list':
                $style = $data['style'] ?? 'unordered';
                $items = $data['items'] ?? [];
                $list_lines = render_list_items_md($items, $style, 0);
                array_push($lines, ...$list_lines);
                $lines[] = '';
                break;

            case 'checklist':
                foreach ($data['items'] ?? [] as $item) {
                    $checked = !empty($item['checked']) ? 'x' : ' ';
                    $text = html_to_md_inline($item['text'] ?? '');
                    $lines[] = "- [{$checked}] {$text}";
                }
                $lines[] = '';
                break;

            case 'code':
                $lang = $data['language'] ?? '';
                $lines[] = '```' . $lang;
                $lines[] = $data['code'] ?? '';
                $lines[] = '```';
                $lines[] = '';
                break;

            case 'quote':
                $text = html_to_md_inline($data['text'] ?? '');
                $caption = $data['caption'] ?? '';
                $lines[] = '> ' . $text;
                if ($caption) {
                    $lines[] = '> -- ' . html_to_md_inline($caption);
                }
                $lines[] = '';
                break;

            case 'delimiter':
                $lines[] = '---';
                $lines[] = '';
                break;

            case 'alert':
                $alert_type = $data['type'] ?? 'info';
                $message = html_to_md_inline($data['message'] ?? '');
                $lines[] = "> [!{$alert_type}]";
                $lines[] = "> {$message}";
                $lines[] = '';
                break;

            case 'toggle':
                $title = html_to_md_inline($data['text'] ?? '');
                $lines[] = '<details>';
                $lines[] = "<summary>{$title}</summary>";
                $lines[] = '';
                $lines[] = '</details>';
                $lines[] = '';
                break;

            case 'table':
                $content = $data['content'] ?? [];
                $with_headings = !empty($data['withHeadings']);
                if (!empty($content)) {
                    foreach ($content as $i => $row) {
                        $cells = array_map('html_to_md_inline', $row);
                        $lines[] = '| ' . implode(' | ', $cells) . ' |';
                        if ($i === 0) {
                            $lines[] = '| ' . implode(' | ', array_fill(0, count($cells), '---')) . ' |';
                        }
                    }
                }
                $lines[] = '';
                break;

            case 'page':
                $page_title = $data['title'] ?? 'Page';
                $page_icon  = $data['icon'] ?? '';
                $page_path  = preg_replace('/\.json$/', '', $data['pagePath'] ?? '');
                $display = $page_icon ? "{$page_icon} {$page_title}" : $page_title;
                $lines[] = "[{$display}](note/{$page_path})";
                $lines[] = '';
                break;

            case 'linkTool':
                $link  = $data['link'] ?? '';
                $title = $data['meta']['title'] ?? $link;
                $lines[] = "[{$title}]({$link})";
                $lines[] = '';
                break;

            default:
                if (!empty($data['text'])) {
                    $lines[] = html_to_md_inline($data['text']);
                    $lines[] = '';
                }
                break;
        }
    }

    while (!empty($lines) && $lines[count($lines) - 1] === '') {
        array_pop($lines);
    }

    return implode("\n", $lines);
}

function render_list_items_md(array $items, string $style, int $depth): array {
    $lines = [];
    $counter = 1;

    foreach ($items as $item) {
        $indent = str_repeat('  ', $depth);
        $prefix = $style === 'ordered' ? "{$counter}." : '-';

        if (is_array($item)) {
            $content = html_to_md_inline($item['content'] ?? '');
            $lines[] = "{$indent}{$prefix} {$content}";
            $counter++;
            if (!empty($item['items'])) {
                $child_lines = render_list_items_md($item['items'], $style, $depth + 1);
                array_push($lines, ...$child_lines);
            }
        } else {
            $lines[] = "{$indent}{$prefix} " . html_to_md_inline($item);
            $counter++;
        }
    }

    return $lines;
}

function html_to_md_inline(string $html): string {
    if (empty($html)) return '';

    $html = preg_replace_callback(
        '/<a\s+href="([^"]*)"[^>]*>(.*?)<\/a>/is',
        function($m) {
            $url  = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $text = strip_tags($m[2]);
            return "[{$text}]({$url})";
        },
        $html
    );

    $html = preg_replace('/<(?:b|strong)>(.*?)<\/(?:b|strong)>/is', '**$1**', $html);
    $html = preg_replace('/<(?:i|em)>(.*?)<\/(?:i|em)>/is', '*$1*', $html);
    $html = preg_replace('/<code>(.*?)<\/code>/is', '`$1`', $html);
    $html = preg_replace('/<mark[^>]*>(.*?)<\/mark>/is', '==$1==', $html);
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    $html = str_replace("\xC2\xA0", ' ', $html);

    return $html;
}

// ============================================================
// Markdown → Editor.js Blocks
// ============================================================

function markdown_to_blocks(string $markdown): array {
    $blocks = [];
    $lines = explode("\n", $markdown);
    $i = 0;
    $total = count($lines);

    while ($i < $total) {
        $line = $lines[$i];

        // Blank lines
        if (trim($line) === '') {
            $i++;
            continue;
        }

        // Header
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $blocks[] = make_block('header', [
                'text'  => md_inline_to_html(trim($m[2])),
                'level' => strlen($m[1]),
            ]);
            $i++;
            continue;
        }

        // Fenced code block
        if (preg_match('/^```(.*)$/', $line, $code_match)) {
            $lang = trim($code_match[1]);
            $code_lines = [];
            $i++;
            while ($i < $total && !preg_match('/^```/', $lines[$i])) {
                $code_lines[] = $lines[$i];
                $i++;
            }
            $i++;
            $block_data = ['code' => implode("\n", $code_lines)];
            if ($lang) {
                $block_data['language'] = $lang;
            }
            $blocks[] = make_block('code', $block_data);
            continue;
        }

        // Delimiter
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', trim($line))) {
            $blocks[] = make_block('delimiter', []);
            $i++;
            continue;
        }

        // Alert: > [!type]
        if (preg_match('/^>\s*\[!(\w+)\]\s*$/', $line, $m)) {
            $alert_type = strtolower($m[1]);
            $message_lines = [];
            $i++;
            while ($i < $total && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                if (preg_match('/^\[!\w+\]/', $qm[1])) break;
                $message_lines[] = $qm[1];
                $i++;
            }
            $blocks[] = make_block('alert', [
                'type'    => $alert_type,
                'align'   => 'left',
                'message' => md_inline_to_html(implode(' ', $message_lines)),
            ]);
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $quote_lines = [$m[1]];
            $caption = '';
            $i++;
            while ($i < $total && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                if (preg_match('/^--\s*(.+)$/', $qm[1], $cm)) {
                    $caption = trim($cm[1]);
                } else {
                    $quote_lines[] = $qm[1];
                }
                $i++;
            }
            $blocks[] = make_block('quote', [
                'text'    => md_inline_to_html(implode(' ', $quote_lines)),
                'caption' => md_inline_to_html($caption),
            ]);
            continue;
        }

        // Toggle: <details>
        if (preg_match('/^<details>/', trim($line))) {
            $i++;
            $summary = '';
            if ($i < $total && preg_match('/<summary>(.*?)<\/summary>/i', $lines[$i], $sm)) {
                $summary = $sm[1];
                $i++;
            }
            while ($i < $total && !preg_match('/^<\/details>/', trim($lines[$i]))) {
                $i++;
            }
            $i++;
            $blocks[] = make_block('toggle', [
                'text'   => $summary,
                'status' => 'closed',
                'items'  => 0,
            ]);
            continue;
        }

        // Table
        if (preg_match('/^\|.+\|$/', trim($line))) {
            $table_rows = [];
            $with_headings = false;
            while ($i < $total && preg_match('/^\|(.+)\|$/', trim($lines[$i]), $tm)) {
                $row_content = $tm[1];
                if (preg_match('/^[\s\-|:]+$/', $row_content)) {
                    $with_headings = true;
                    $i++;
                    continue;
                }
                $cells = array_map(function($cell) {
                    return md_inline_to_html(trim($cell));
                }, explode('|', $row_content));
                $table_rows[] = $cells;
                $i++;
            }
            if (!empty($table_rows)) {
                $blocks[] = make_block('table', [
                    'withHeadings' => $with_headings,
                    'stretched'    => false,
                    'content'      => $table_rows,
                ]);
            }
            continue;
        }

        // Checklist
        if (preg_match('/^-\s*\[([ xX])\]\s+(.+)$/', $line)) {
            $items = [];
            while ($i < $total && preg_match('/^-\s*\[([ xX])\]\s+(.+)$/', $lines[$i], $cm)) {
                $items[] = [
                    'text'    => md_inline_to_html(trim($cm[2])),
                    'checked' => strtolower($cm[1]) === 'x',
                ];
                $i++;
            }
            $blocks[] = make_block('checklist', ['items' => $items]);
            continue;
        }

        // Unordered list
        if (preg_match('/^[-*]\s+(.+)$/', $line)) {
            $items = parse_md_list_items($lines, $i, 'unordered');
            $blocks[] = make_block('list', [
                'style' => 'unordered',
                'meta'  => [],
                'items' => $items,
            ]);
            continue;
        }

        // Ordered list
        if (preg_match('/^\d+\.\s+(.+)$/', $line)) {
            $items = parse_md_list_items($lines, $i, 'ordered');
            $blocks[] = make_block('list', [
                'style' => 'ordered',
                'meta'  => [],
                'items' => $items,
            ]);
            continue;
        }

        // Paragraph (default)
        $para_lines = [];
        while ($i < $total && trim($lines[$i]) !== '' && !is_md_block_start($lines[$i])) {
            $para_lines[] = $lines[$i];
            $i++;
        }
        $blocks[] = make_block('paragraph', [
            'text' => md_inline_to_html(implode(' ', $para_lines)),
        ]);
    }

    return $blocks;
}

// ============================================================
// Helpers
// ============================================================

function make_block(string $type, array $data): array {
    return [
        'id'   => generate_block_id(),
        'type' => $type,
        'data' => $data,
    ];
}

function generate_block_id(int $length = 10): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

function md_inline_to_html(string $md): string {
    if (empty($md)) return '';

    // [text](url) → <a>
    $md = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        function($m) {
            $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            return "<a href=\"{$url}\">{$m[1]}</a>";
        },
        $md
    );

    // **text** → <b>
    $md = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $md);

    // *text* → <i>
    $md = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $md);

    // `code` → <code>
    $md = preg_replace('/`([^`]+)`/', '<code>$1</code>', $md);

    // ==text== → <mark>
    $md = preg_replace('/==(.+?)==/', '<mark>$1</mark>', $md);

    return $md;
}

function is_md_block_start(string $line): bool {
    $line = trim($line);
    if ($line === '') return true;
    if (preg_match('/^#{1,6}\s/', $line)) return true;
    if (preg_match('/^```/', $line)) return true;
    if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $line)) return true;
    if (preg_match('/^>\s/', $line)) return true;
    if (preg_match('/^[-*]\s/', $line)) return true;
    if (preg_match('/^\d+\.\s/', $line)) return true;
    if (preg_match('/^-\s*\[[ xX]\]/', $line)) return true;
    if (preg_match('/^\|.+\|$/', $line)) return true;
    if (preg_match('/^<details>/', $line)) return true;
    return false;
}

function parse_md_list_items(array $lines, int &$i, string $style, int $base_indent = 0): array {
    $items = [];
    $total = count($lines);
    $pattern = $style === 'ordered' ? '/^(\s*)\d+\.\s+(.+)$/' : '/^(\s*)[-*]\s+(.+)$/';

    while ($i < $total) {
        if (!preg_match($pattern, $lines[$i], $m)) {
            break;
        }

        $indent = strlen($m[1]);

        if ($indent < $base_indent) {
            break;
        }

        if ($indent === $base_indent) {
            $content = md_inline_to_html(trim($m[2]));
            $i++;

            $children = [];
            if ($i < $total && preg_match($pattern, $lines[$i], $next_m)) {
                $next_indent = strlen($next_m[1]);
                if ($next_indent > $base_indent) {
                    $children = parse_md_list_items($lines, $i, $style, $next_indent);
                }
            }

            $items[] = [
                'content' => $content,
                'meta'    => [],
                'items'   => $children,
            ];
        } else {
            break;
        }
    }

    return $items;
}

function extract_plain_text(array $blocks): string {
    $text = '';
    foreach ($blocks as $block) {
        $d = $block['data'] ?? [];
        if (!empty($d['text']))    $text .= ' ' . strip_tags($d['text']);
        if (!empty($d['code']))    $text .= ' ' . $d['code'];
        if (!empty($d['message'])) $text .= ' ' . strip_tags($d['message']);
        if (!empty($d['title']))   $text .= ' ' . $d['title'];
        if (!empty($d['items']) && is_array($d['items'])) {
            $text .= ' ' . extract_items_text($d['items']);
        }
    }
    return $text;
}

function extract_items_text(array $items): string {
    $text = '';
    foreach ($items as $item) {
        if (is_array($item)) {
            $text .= ' ' . strip_tags($item['content'] ?? $item['text'] ?? '');
            if (!empty($item['items'])) {
                $text .= ' ' . extract_items_text($item['items']);
            }
        } else {
            $text .= ' ' . strip_tags($item);
        }
    }
    return $text;
}
