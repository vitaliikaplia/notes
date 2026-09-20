<?php

if(!defined('ABSPATH')){exit;}

// ============================================================
// Word (.docx) export — writes a note's Editor.js blocks straight to
// WordprocessingML. No library: the OOXML parts are assembled by hand
// and packed by a small deflate-based ZIP writer, so the only runtime
// needs are zlib and Imagick/GD (WebP uploads are converted to PNG/JPEG
// because not every Word reader can display WebP).
// ============================================================

// A4 portrait with 2 cm margins. Twips = 1/20 pt; EMU = 635 per twip, 9525 per CSS px (96 dpi).
const DOCX_PAGE_W = 11906;
const DOCX_PAGE_H = 16838;
const DOCX_MARGIN = 1134;
const DOCX_CONTENT_W = DOCX_PAGE_W - 2 * DOCX_MARGIN;   // 9638 twips ≈ 17 cm
const DOCX_EMU_PER_TWIP = 635;
const DOCX_EMU_PER_PX = 9525;
const DOCX_MAX_IMAGE_H_EMU = 7200000;                   // 20 cm: a tall image still fits on one page

const DOCX_NS_W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const DOCX_NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const DOCX_NS_WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
const DOCX_NS_A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
const DOCX_NS_PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
const DOCX_REL_BASE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

/** Render a note title + blocks to .docx bytes. */
function note_export_docx(string $title, array $blocks): string {
    $ctx = docx_new_context();

    $body = docx_p(docx_text_run($title), '<w:pStyle w:val="Title"/>');
    $body .= docx_blocks($blocks, $ctx);

    $sect = '<w:sectPr>'
        . '<w:footerReference w:type="default" r:id="' . $ctx['footer_rid'] . '"/>'
        . '<w:pgSz w:w="' . DOCX_PAGE_W . '" w:h="' . DOCX_PAGE_H . '"/>'
        . '<w:pgMar w:top="' . DOCX_MARGIN . '" w:right="' . DOCX_MARGIN . '" w:bottom="' . DOCX_MARGIN . '" w:left="' . DOCX_MARGIN . '" w:header="708" w:footer="600" w:gutter="0"/>'
        . '<w:cols w:space="708"/>'
        . '</w:sectPr>';

    $document = docx_xml_header()
        . '<w:document xmlns:w="' . DOCX_NS_W . '" xmlns:r="' . DOCX_NS_R . '" xmlns:wp="' . DOCX_NS_WP . '" xmlns:a="' . DOCX_NS_A . '" xmlns:pic="' . DOCX_NS_PIC . '">'
        . '<w:body>' . $body . $sect . '</w:body></w:document>';

    $files = [
        '[Content_Types].xml'         => docx_content_types_xml($ctx),
        '_rels/.rels'                 => docx_root_rels_xml(),
        'docProps/core.xml'           => docx_core_xml($title),
        'docProps/app.xml'            => docx_app_xml(),
        'word/document.xml'           => $document,
        'word/_rels/document.xml.rels' => docx_document_rels_xml($ctx),
        'word/styles.xml'             => docx_styles_xml(),
        'word/numbering.xml'          => docx_numbering_xml($ctx),
        'word/footer1.xml'            => docx_footer_xml(),
    ];
    foreach($ctx['media'] as $name => $bytes) {
        $files['word/media/' . $name] = $bytes;
    }

    return docx_zip($files);
}

function docx_new_context(): array {
    $ctx = [
        'rels'       => [],   // rId => [type, target, external]
        'rel_index'  => [],   // "type|target" => rId (hyperlinks and images are shared)
        'media'      => [],   // media file name => bytes
        'images'     => [],   // upload path => [rid, w, h] cache
        'nums'       => [],   // numId - 1 => [kind, start]; every list gets its own definition
        'drawing_id' => 0,
    ];
    docx_add_rel($ctx, 'styles', 'styles.xml');
    docx_add_rel($ctx, 'numbering', 'numbering.xml');
    $ctx['footer_rid'] = docx_add_rel($ctx, 'footer', 'footer1.xml');
    return $ctx;
}

function docx_add_rel(array &$ctx, string $type, string $target, bool $external = false): string {
    $key = $type . '|' . $target;
    if(isset($ctx['rel_index'][$key])) return $ctx['rel_index'][$key];
    $rid = 'rId' . (count($ctx['rels']) + 1);
    $ctx['rels'][$rid] = ['type' => $type, 'target' => $target, 'external' => $external];
    $ctx['rel_index'][$key] = $rid;
    return $rid;
}

// ------------------------------------------------------------
// Blocks
// ------------------------------------------------------------

function docx_blocks(array $blocks, array &$ctx): string {
    $out = '';
    foreach($blocks as $block) {
        $type = $block['type'] ?? '';
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        switch($type) {
            case 'header':
                $level = max(1, min(6, (int)($data['level'] ?? 2)));
                $out .= docx_p(docx_inline((string)($data['text'] ?? ''), $ctx), '<w:pStyle w:val="Heading' . $level . '"/>');
                break;

            case 'paragraph':
                $out .= docx_p(docx_inline((string)($data['text'] ?? ''), $ctx));
                break;

            case 'list':
                $style = (string)($data['style'] ?? 'unordered');
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                if($style === 'checklist') {
                    $out .= docx_check_items($items, 0, $ctx);
                } else {
                    $kind = $style === 'ordered' ? docx_counter_kind((string)($data['meta']['counterType'] ?? '')) : 'bullet';
                    $start = max(1, (int)($data['meta']['start'] ?? 1));
                    $num_id = docx_list_num($ctx, $kind, $start);
                    $out .= docx_list_items($items, $num_id, 0, $ctx);
                }
                break;

            case 'checklist':
                $out .= docx_check_items(is_array($data['items'] ?? null) ? $data['items'] : [], 0, $ctx);
                break;

            case 'code':
                $out .= docx_code_block((string)($data['code'] ?? ''));
                break;

            case 'quote':
                $out .= docx_p(docx_inline((string)($data['text'] ?? ''), $ctx), '<w:pStyle w:val="Quote"/>');
                $caption = (string)($data['caption'] ?? '');
                if(trim(strip_tags($caption)) !== '') {
                    $out .= docx_p(docx_text_run('— ') . docx_inline($caption, $ctx), '<w:pStyle w:val="QuoteCaption"/>');
                }
                break;

            case 'image':
                $url = (string)($data['file']['url'] ?? '');
                if($url === '') break;
                $wanted_px = !empty($data['width']) ? (int)$data['width'] : null;
                $out .= docx_figure($url, (string)($data['caption'] ?? ''), DOCX_CONTENT_W * DOCX_EMU_PER_TWIP, $wanted_px, $ctx);
                break;

            case 'gallery':
                $out .= docx_gallery($data, $ctx);
                break;

            case 'video':
                $url = (string)($data['url'] ?? '');
                if($url === '') break;
                $label = trim((string)($data['caption'] ?? ''));
                if($label === '') $label = basename((string)parse_url($url, PHP_URL_PATH));
                $out .= docx_p(docx_text_run('▶ Video: ') . docx_link($url, $label, $ctx));
                break;

            case 'embed':
                $url = (string)($data['source'] ?? $data['embed'] ?? '');
                if($url === '') break;
                $caption = trim(strip_tags((string)($data['caption'] ?? '')));
                $out .= docx_p(docx_text_run('▶ ') . docx_link($url, $caption !== '' ? $caption : $url, $ctx));
                break;

            case 'table':
                $out .= docx_table($data, $ctx);
                break;

            case 'delimiter':
                $out .= docx_p('', '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="CCCCCC"/></w:pBdr><w:spacing w:before="120" w:after="240"/>');
                break;

            case 'page':
                $page_url = (string)($data['pageUrl'] ?? '');
                if($page_url === '') break;
                $label = (string)($data['title'] ?? 'Page');
                $icon = (string)($data['icon'] ?? '');
                // Emoji icons travel as plain text; SVG / data-URI icons are dropped
                if($icon !== '' && !str_contains($icon, '<') && !str_starts_with($icon, 'data:') && mb_strlen($icon, 'UTF-8') <= 4) {
                    $label = $icon . ' ' . $label;
                }
                $out .= docx_p(
                    docx_link(HOME_URL . ltrim($page_url, '/') . '/', $label, $ctx),
                    docx_box_ppr('DDDDDD', 'FAFAFA')
                );
                break;

            case 'alert':
                [$border, $fill] = docx_alert_colors((string)($data['type'] ?? 'info'));
                $out .= docx_p(docx_inline((string)($data['message'] ?? ''), $ctx), docx_box_ppr($border, $fill));
                break;

            case 'toggle':
                $out .= docx_p(docx_text_run('▸ ') . docx_inline((string)($data['text'] ?? ''), $ctx, ['b' => true]));
                break;

            case 'linkTool':
                $link = (string)($data['link'] ?? '');
                if($link === '') break;
                $label = trim((string)($data['meta']['title'] ?? ''));
                $out .= docx_p(docx_link($link, $label !== '' ? $label : $link, $ctx));
                $desc = trim((string)($data['meta']['description'] ?? ''));
                if($desc !== '') {
                    $out .= docx_p(docx_text_run($desc, ['color' => '888888', 'sz' => 18]));
                }
                break;

            default:
                if(!empty($data['text'])) {
                    $out .= docx_p(docx_inline((string)$data['text'], $ctx));
                }
                break;
        }
    }
    return $out;
}

/** Code block as a shaded one-cell table: renders the same in Word, Pages, Google Docs and Quick Look. */
function docx_code_block(string $code): string {
    $code = str_replace("\r", '', $code);
    $lines = '';
    foreach(explode("\n", $code) as $line) {
        $lines .= docx_p(docx_text_run($line), '<w:pStyle w:val="CodeBlock"/>');
    }
    $border = 'w:val="single" w:sz="4" w:space="0" w:color="DDDDDD"';
    return '<w:tbl><w:tblPr>'
        . '<w:tblW w:w="' . DOCX_CONTENT_W . '" w:type="dxa"/>'
        . '<w:tblBorders><w:top ' . $border . '/><w:left ' . $border . '/><w:bottom ' . $border . '/><w:right ' . $border . '/></w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:left w:w="140" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:right w:w="140" w:type="dxa"/></w:tblCellMar>'
        . '</w:tblPr><w:tblGrid><w:gridCol w:w="' . DOCX_CONTENT_W . '"/></w:tblGrid>'
        . '<w:tr><w:tc><w:tcPr><w:tcW w:w="' . DOCX_CONTENT_W . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F5F5F5"/></w:tcPr>'
        . $lines
        . '</w:tc></w:tr></w:tbl>' . docx_spacer();
}

/** Paragraph with a light box around it (page links, alerts). */
function docx_box_ppr(string $border, string $fill): string {
    $b = 'w:val="single" w:sz="4" w:space="4" w:color="' . $border . '"';
    return '<w:pBdr><w:top ' . $b . '/><w:left ' . $b . '/><w:bottom ' . $b . '/><w:right ' . $b . '/></w:pBdr>'
        . '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>'
        . '<w:spacing w:before="60" w:after="200"/><w:ind w:left="100" w:right="100"/>';
}

function docx_alert_colors(string $type): array {
    return match($type) {
        'success'           => ['A7D7B4', 'E8F6EC'],
        'warning'           => ['F1CF8A', 'FFF6E3'],
        'danger'            => ['F0A9A9', 'FDECEC'],
        'primary', 'info'   => ['A9C8EE', 'E9F1FC'],
        'dark'              => ['999999', 'EDEDED'],
        default             => ['D9D9D9', 'F4F4F4'],
    };
}

/** Tiny empty paragraph that keeps a gap after code blocks and tables. */
function docx_spacer(): string {
    return '<w:p><w:pPr><w:spacing w:before="0" w:after="120"/><w:rPr><w:sz w:val="8"/></w:rPr></w:pPr></w:p>';
}

// ------------------------------------------------------------
// Lists
// ------------------------------------------------------------

function docx_counter_kind(string $counter_type): string {
    return match($counter_type) {
        'lower-alpha', 'lower-latin' => 'lowerLetter',
        'upper-alpha', 'upper-latin' => 'upperLetter',
        'lower-roman'                => 'lowerRoman',
        'upper-roman'                => 'upperRoman',
        default                      => 'decimal',
    };
}

/**
 * Register a numbering definition. Every list gets its own abstractNum (not a shared one with
 * a start override) so ordered lists restart in every reader, not just in Word.
 */
function docx_list_num(array &$ctx, string $kind, int $start = 1): int {
    $ctx['nums'][] = ['kind' => $kind, 'start' => $start];
    return count($ctx['nums']);
}

function docx_list_items(array $items, int $num_id, int $depth, array &$ctx): string {
    $out = '';
    $ilvl = min(8, $depth);
    foreach($items as $item) {
        $content = is_array($item) ? (string)($item['content'] ?? '') : (string)$item;
        $out .= docx_p(
            docx_inline($content, $ctx),
            '<w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="' . $ilvl . '"/><w:numId w:val="' . $num_id . '"/></w:numPr>'
        );
        if(is_array($item) && !empty($item['items']) && is_array($item['items'])) {
            $out .= docx_list_items($item['items'], $num_id, $depth + 1, $ctx);
        }
    }
    return $out;
}

/** Checklist items (both the Checklist tool and List v2 in checklist mode) as ☐ / ☑ paragraphs. */
function docx_check_items(array $items, int $depth, array &$ctx): string {
    $out = '';
    $left = 720 + 360 * min(8, $depth);
    foreach($items as $item) {
        if(!is_array($item)) $item = ['text' => (string)$item];
        $text = (string)($item['text'] ?? $item['content'] ?? '');
        $checked = !empty($item['checked']) || !empty($item['meta']['checked']);
        $out .= docx_p(
            docx_text_run($checked ? '☑' : '☐') . '<w:r><w:tab/></w:r>' . docx_inline($text, $ctx),
            '<w:tabs><w:tab w:val="left" w:pos="' . $left . '"/></w:tabs><w:spacing w:after="60"/><w:ind w:left="' . $left . '" w:hanging="360"/>'
        );
        if(!empty($item['items']) && is_array($item['items'])) {
            $out .= docx_check_items($item['items'], $depth + 1, $ctx);
        }
    }
    return $out;
}

// ------------------------------------------------------------
// Tables and galleries
// ------------------------------------------------------------

function docx_table(array $data, array &$ctx): string {
    $rows = is_array($data['content'] ?? null) ? array_values(array_filter($data['content'], 'is_array')) : [];
    if(!$rows) return '';
    $cols = max(1, max(array_map('count', $rows)));
    $col_w = intdiv(DOCX_CONTENT_W, $cols);
    $with_headings = !empty($data['withHeadings']);

    $border = 'w:val="single" w:sz="4" w:space="0" w:color="BBBBBB"';
    $xml = '<w:tbl><w:tblPr>'
        . '<w:tblW w:w="' . ($col_w * $cols) . '" w:type="dxa"/>'
        . '<w:tblBorders><w:top ' . $border . '/><w:left ' . $border . '/><w:bottom ' . $border . '/><w:right ' . $border . '/><w:insideH ' . $border . '/><w:insideV ' . $border . '/></w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>'
        . '</w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $col_w . '"/>', $cols) . '</w:tblGrid>';

    foreach($rows as $i => $row) {
        $is_header = $with_headings && $i === 0;
        $xml .= '<w:tr>' . ($is_header ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        $row = array_values($row);
        for($c = 0; $c < $cols; $c++) {
            $cell = (string)($row[$c] ?? '');
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $col_w . '" w:type="dxa"/>'
                . ($is_header ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '')
                . '</w:tcPr>'
                . docx_p(docx_inline($cell, $ctx, ['sz' => 19, 'b' => $is_header]), '<w:spacing w:before="0" w:after="0"/>')
                . '</w:tc>';
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>' . docx_spacer();
}

function docx_gallery(array $data, array &$ctx): string {
    $items = array_values(array_filter(is_array($data['items'] ?? null) ? $data['items'] : [], fn($i) => is_array($i) && !empty($i['url'])));
    if(!$items) return '';
    $cols = (int)($data['columns'] ?? 3);
    $cols = ($cols >= 2 && $cols <= 4) ? $cols : 3;
    $col_w = intdiv(DOCX_CONTENT_W, $cols);
    $cell_pad = 60;
    $img_w_emu = ($col_w - 2 * $cell_pad) * DOCX_EMU_PER_TWIP;

    $nil = 'w:val="nil"';
    $xml = '<w:tbl><w:tblPr>'
        . '<w:tblW w:w="' . ($col_w * $cols) . '" w:type="dxa"/>'
        . '<w:tblBorders><w:top ' . $nil . '/><w:left ' . $nil . '/><w:bottom ' . $nil . '/><w:right ' . $nil . '/><w:insideH ' . $nil . '/><w:insideV ' . $nil . '/></w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar><w:top w:w="' . $cell_pad . '" w:type="dxa"/><w:left w:w="' . $cell_pad . '" w:type="dxa"/><w:bottom w:w="' . $cell_pad . '" w:type="dxa"/><w:right w:w="' . $cell_pad . '" w:type="dxa"/></w:tblCellMar>'
        . '</w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $col_w . '"/>', $cols) . '</w:tblGrid>';

    foreach(array_chunk($items, $cols) as $row) {
        $xml .= '<w:tr>';
        for($c = 0; $c < $cols; $c++) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $col_w . '" w:type="dxa"/></w:tcPr>';
            if(isset($row[$c])) {
                $xml .= docx_figure((string)$row[$c]['url'], (string)($row[$c]['caption'] ?? ''), $img_w_emu, null, $ctx, true, $img_w_emu);
            } else {
                $xml .= '<w:p/>';
            }
            $xml .= '</w:tc>';
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>' . docx_spacer();
}

// ------------------------------------------------------------
// Images
// ------------------------------------------------------------

/** Image paragraph (+ caption paragraph). Falls back to a link when the file cannot be embedded. */
function docx_figure(string $url, string $caption, int $max_w_emu, ?int $wanted_px, array &$ctx, bool $compact = false, ?int $max_h_emu = null): string {
    $caption_text = trim(strip_tags($caption));
    $img = docx_image_part($url, $ctx);
    $ppr_img = '<w:spacing w:before="0" w:after="' . ($caption_text !== '' ? '60' : ($compact ? '0' : '200')) . '"/><w:jc w:val="center"/>';

    if($img === null) {
        $label = $caption_text !== '' ? $caption_text : basename((string)parse_url($url, PHP_URL_PATH));
        return docx_p(docx_text_run('🖼 ') . docx_link($url, $label, $ctx), $compact ? '<w:spacing w:before="0" w:after="0"/><w:jc w:val="center"/>' : '');
    }

    $out = docx_p(docx_drawing($img, $max_w_emu, $wanted_px, $ctx, $max_h_emu), $ppr_img);
    if($caption_text !== '') {
        $out .= docx_p(docx_inline($caption, $ctx), '<w:pStyle w:val="Caption"/>' . ($compact ? '<w:spacing w:before="0" w:after="0"/>' : ''));
    }
    return $out;
}

function docx_drawing(array $img, int $max_w_emu, ?int $wanted_px, array &$ctx, ?int $max_h_emu = null): string {
    $cx = $img['w'] * DOCX_EMU_PER_PX;
    $cy = $img['h'] * DOCX_EMU_PER_PX;
    if($wanted_px !== null && $wanted_px > 0 && $wanted_px * DOCX_EMU_PER_PX < $cx) {
        $cap = $wanted_px * DOCX_EMU_PER_PX;
        $cy = (int)round($cy * $cap / $cx);
        $cx = $cap;
    }
    if($cx > $max_w_emu) {
        $cy = (int)round($cy * $max_w_emu / $cx);
        $cx = $max_w_emu;
    }
    $max_h_emu = min($max_h_emu ?? DOCX_MAX_IMAGE_H_EMU, DOCX_MAX_IMAGE_H_EMU);
    if($cy > $max_h_emu) {
        $cx = (int)round($cx * $max_h_emu / $cy);
        $cy = $max_h_emu;
    }
    $cx = max(1, $cx);
    $cy = max(1, $cy);
    $id = ++$ctx['drawing_id'];
    $name = 'Picture ' . $id;

    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
        . '<wp:docPr id="' . $id . '" name="' . $name . '"/>'
        . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic><a:graphicData uri="' . DOCX_NS_PIC . '">'
        . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $img['rid'] . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/** Embed a local upload as a media part; returns [rid, w, h] in px or null (remote/unreadable). */
function docx_image_part(string $url, array &$ctx): ?array {
    $relative = upload_url_to_relative_path($url);
    if($relative === null || str_contains($relative, '..')) return null;

    $filepath = ABSPATH . DS . 'uploads' . DS . str_replace('/', DS, $relative);
    if(!is_file($filepath)) return null;
    if(array_key_exists($filepath, $ctx['images'])) return $ctx['images'][$filepath];

    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $result = null;

    if(in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
        $info = @getimagesize($filepath);
        if($info && $info[0] > 0 && $info[1] > 0) {
            $result = [(string)file_get_contents($filepath), $ext === 'jpg' ? 'jpeg' : $ext, $info[0], $info[1]];
        }
    } else {
        $result = docx_rasterize($filepath, $ext);
    }

    if($result === null) {
        return $ctx['images'][$filepath] = null;
    }

    [$bytes, $out_ext, $w, $h] = $result;
    $name = 'image' . (count($ctx['media']) + 1) . '.' . $out_ext;
    $ctx['media'][$name] = $bytes;
    $rid = docx_add_rel($ctx, 'image', 'media/' . $name);
    return $ctx['images'][$filepath] = ['rid' => $rid, 'w' => $w, 'h' => $h];
}

/** WebP / SVG → PNG (when transparency is used) or JPEG, via Imagick with a GD fallback for WebP. */
function docx_rasterize(string $filepath, string $ext): ?array {
    if(class_exists('\Imagick')) {
        try {
            $im = new \Imagick();
            if($ext === 'svg') {
                $im->setBackgroundColor(new \ImagickPixel('transparent'));
                $im->setResolution(144, 144);
            }
            $im->readImage($filepath);
            $im->setIteratorIndex(0);
            $im = $im->getImage();

            $has_alpha = false;
            try {
                if($im->getImageAlphaChannel()) {
                    $range = $im->getImageChannelRange(\Imagick::CHANNEL_ALPHA);
                    $has_alpha = $range['minima'] < $range['maxima'];
                }
            } catch(\Throwable $e) {
                $has_alpha = true;
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if($has_alpha) {
                $im->setImageFormat('png');
                $out_ext = 'png';
            } else {
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(88);
                $out_ext = 'jpeg';
            }
            $bytes = $im->getImageBlob();
            $im->destroy();
            if($w > 0 && $h > 0 && $bytes !== '') {
                return [$bytes, $out_ext, $w, $h];
            }
        } catch(\Throwable $e) {
            write_log('DOCX image conversion failed for ' . basename($filepath) . ': ' . $e->getMessage());
        }
    }

    if($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($filepath);
        if($img) {
            ob_start();
            imagesavealpha($img, true);
            imagepng($img);
            $bytes = (string)ob_get_clean();
            $w = imagesx($img);
            $h = imagesy($img);
            imagedestroy($img);
            if($w > 0 && $h > 0 && $bytes !== '') {
                return [$bytes, 'png', $w, $h];
            }
        }
    }

    return null;
}

// ------------------------------------------------------------
// Inline content: Editor.js inline HTML → runs
// ------------------------------------------------------------

function docx_p(string $runs, string $ppr = ''): string {
    return '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $runs . '</w:p>';
}

function docx_esc(string $text): string {
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Run properties in schema order. Keys: style, code, font, b, i, s, color, sz, mark, u, sup, sub. */
function docx_rpr(array $f): string {
    $x = '';
    if(!empty($f['style'])) $x .= '<w:rStyle w:val="' . $f['style'] . '"/>';
    if(!empty($f['code'])) {
        $x .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/>';
    } elseif(!empty($f['font'])) {
        $x .= '<w:rFonts w:ascii="' . $f['font'] . '" w:hAnsi="' . $f['font'] . '" w:cs="' . $f['font'] . '"/>';
    }
    if(!empty($f['b'])) $x .= '<w:b/><w:bCs/>';
    if(!empty($f['i'])) $x .= '<w:i/><w:iCs/>';
    if(!empty($f['s'])) $x .= '<w:strike/>';
    if(!empty($f['color'])) $x .= '<w:color w:val="' . $f['color'] . '"/>';
    $sz = !empty($f['sz']) ? (int)$f['sz'] : (!empty($f['code']) ? 19 : 0);
    if($sz > 0) $x .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
    if(!empty($f['mark'])) $x .= '<w:highlight w:val="yellow"/>';
    if(!empty($f['u'])) $x .= '<w:u w:val="single"/>';
    if(!empty($f['code'])) $x .= '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>';
    if(!empty($f['sup'])) $x .= '<w:vertAlign w:val="superscript"/>';
    elseif(!empty($f['sub'])) $x .= '<w:vertAlign w:val="subscript"/>';
    return $x === '' ? '' : '<w:rPr>' . $x . '</w:rPr>';
}

/** Plain text → runs; newlines become line breaks, tabs become tab characters. */
function docx_text_run(string $text, array $fmt = []): string {
    $text = str_replace("\r", '', $text);
    if($text === '') return '';
    $rpr = docx_rpr($fmt);
    $out = '';
    foreach(preg_split('/(\n|\t)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        if($part === '') continue;
        if($part === "\n") {
            $inner = '<w:br/>';
        } elseif($part === "\t") {
            $inner = '<w:tab/>';
        } else {
            $inner = '<w:t xml:space="preserve">' . docx_esc($part) . '</w:t>';
        }
        $out .= '<w:r>' . $rpr . $inner . '</w:r>';
    }
    return $out;
}

/** Hyperlink runs; plain text when the URL is not something Word can open. */
function docx_link(string $href, string $label, array &$ctx, array $fmt = []): string {
    $target = docx_link_target($href);
    if($target === null) return docx_text_run($label, $fmt);
    $rid = docx_add_rel($ctx, 'hyperlink', $target, true);
    return '<w:hyperlink r:id="' . $rid . '">' . docx_text_run($label, array_merge($fmt, ['style' => 'Hyperlink'])) . '</w:hyperlink>';
}

function docx_link_target(string $href): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    $href = preg_replace('/[\x00-\x1F\x7F]/', '', $href) ?? '';
    if($href === '' || str_starts_with($href, '#')) return null;

    if(preg_match('#^(https?|mailto|tel):#i', $href)) {
        $url = $href;
    } elseif(str_starts_with($href, '//')) {
        $url = 'https:' . $href;
    } elseif(str_starts_with($href, '/')) {
        $url = rtrim(HOME_URL, '/') . $href;
    } elseif(preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
        return null; // javascript:, data: and friends
    } else {
        $url = HOME_URL . ltrim($href, './');
    }
    // Word wants a plain URI in the relationship target: escape spaces, quotes and non-ASCII bytes
    return preg_replace_callback('/[^\x21-\x7E]|["<>]/', fn($m) => rawurlencode($m[0]), $url) ?? $url;
}

function docx_inline(string $html, array &$ctx, array $fmt = []): string {
    if(trim($html) === '') return '';
    if(!str_contains($html, '<') && !str_contains($html, '&')) {
        return docx_text_run(str_replace("\xC2\xA0", ' ', $html), $fmt);
    }

    $dom = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
    if(!$body) {
        return docx_text_run(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), $fmt);
    }
    return docx_inline_nodes($body->childNodes, $ctx, $fmt);
}

function docx_inline_nodes(\DOMNodeList $nodes, array &$ctx, array $fmt): string {
    $out = '';
    foreach($nodes as $node) {
        if($node instanceof \DOMText) {
            $out .= docx_text_run($node->nodeValue, $fmt);
            continue;
        }
        if(!($node instanceof \DOMElement)) continue;

        switch(strtolower($node->tagName)) {
            case 'br':
                $out .= '<w:r>' . docx_rpr($fmt) . '<w:br/></w:r>';
                break;
            case 'b': case 'strong':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['b' => true]));
                break;
            case 'i': case 'em':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['i' => true]));
                break;
            case 'u':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['u' => true]));
                break;
            case 's': case 'del': case 'strike':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['s' => true]));
                break;
            case 'code': case 'kbd':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['code' => true]));
                break;
            case 'mark':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['mark' => true]));
                break;
            case 'sup':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['sup' => true]));
                break;
            case 'sub':
                $out .= docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['sub' => true]));
                break;
            case 'a':
                $target = docx_link_target($node->getAttribute('href'));
                if($target === null || !empty($fmt['style'])) {
                    $out .= docx_inline_nodes($node->childNodes, $ctx, $fmt);
                    break;
                }
                $inner = docx_inline_nodes($node->childNodes, $ctx, array_merge($fmt, ['style' => 'Hyperlink']));
                if($inner === '') break;
                $rid = docx_add_rel($ctx, 'hyperlink', $target, true);
                $out .= '<w:hyperlink r:id="' . $rid . '">' . $inner . '</w:hyperlink>';
                break;
            case 'img': case 'script': case 'style': case 'svg':
                break;
            default:
                $out .= docx_inline_nodes($node->childNodes, $ctx, $fmt);
                break;
        }
    }
    return $out;
}

// ------------------------------------------------------------
// Package parts
// ------------------------------------------------------------

function docx_xml_header(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
}

function docx_content_types_xml(array $ctx): string {
    $defaults = ['rels' => 'application/vnd.openxmlformats-package.relationships+xml', 'xml' => 'application/xml'];
    foreach(array_keys($ctx['media']) as $name) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $defaults[$ext] = match($ext) { 'png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', default => 'application/octet-stream' };
    }
    $xml = docx_xml_header() . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    foreach($defaults as $ext => $mime) {
        $xml .= '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/>';
    }
    $overrides = [
        '/word/document.xml'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        '/word/styles.xml'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
        '/word/numbering.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
        '/word/footer1.xml'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml',
        '/docProps/core.xml'  => 'application/vnd.openxmlformats-package.core-properties+xml',
        '/docProps/app.xml'   => 'application/vnd.openxmlformats-officedocument.extended-properties+xml',
    ];
    foreach($overrides as $part => $mime) {
        $xml .= '<Override PartName="' . $part . '" ContentType="' . $mime . '"/>';
    }
    return $xml . '</Types>';
}

function docx_root_rels_xml(): string {
    return docx_xml_header() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . DOCX_REL_BASE . 'officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="' . DOCX_REL_BASE . 'extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function docx_document_rels_xml(array $ctx): string {
    $xml = docx_xml_header() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach($ctx['rels'] as $rid => $rel) {
        $xml .= '<Relationship Id="' . $rid . '" Type="' . DOCX_REL_BASE . $rel['type'] . '" Target="' . docx_esc($rel['target']) . '"'
            . ($rel['external'] ? ' TargetMode="External"' : '') . '/>';
    }
    return $xml . '</Relationships>';
}

function docx_core_xml(string $title): string {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $creator = docx_esc(SITE_NAME);
    return docx_xml_header()
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . docx_esc($title) . '</dc:title><dc:creator>' . $creator . '</dc:creator><cp:lastModifiedBy>' . $creator . '</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function docx_app_xml(): string {
    return docx_xml_header()
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>' . docx_esc(SITE_NAME) . '</Application></Properties>';
}

function docx_footer_xml(): string {
    $label = docx_esc(SITE_NAME . ' — ' . date('Y-m-d'));
    return docx_xml_header() . '<w:ftr xmlns:w="' . DOCX_NS_W . '" xmlns:r="' . DOCX_NS_R . '">'
        . '<w:p><w:pPr><w:pStyle w:val="Footer"/><w:tabs><w:tab w:val="right" w:pos="' . DOCX_CONTENT_W . '"/></w:tabs></w:pPr>'
        . '<w:r><w:t xml:space="preserve">' . $label . '</w:t></w:r><w:r><w:tab/></w:r>'
        . '<w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple>'
        . '</w:p></w:ftr>';
}

function docx_numbering_xml(array $ctx): string {
    $xml = docx_xml_header() . '<w:numbering xmlns:w="' . DOCX_NS_W . '">';
    foreach($ctx['nums'] as $i => $num) {
        $xml .= '<w:abstractNum w:abstractNumId="' . $i . '"><w:multiLevelType w:val="hybridMultilevel"/>';
        for($l = 0; $l < 9; $l++) {
            if($num['kind'] === 'bullet') {
                $fmt = 'bullet';
                $text = ['•', '◦', '▪'][$l % 3];
            } else {
                $fmt = $num['kind'];
                $text = '%' . ($l + 1) . '.';
            }
            $start = $l === 0 ? $num['start'] : 1;
            $xml .= '<w:lvl w:ilvl="' . $l . '"><w:start w:val="' . $start . '"/><w:numFmt w:val="' . $fmt . '"/><w:lvlText w:val="' . $text . '"/><w:lvlJc w:val="left"/>'
                . '<w:pPr><w:ind w:left="' . (720 + 360 * $l) . '" w:hanging="360"/></w:pPr>'
                . ($num['kind'] === 'bullet' ? '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/></w:rPr>' : '')
                . '</w:lvl>';
        }
        $xml .= '</w:abstractNum>';
    }
    foreach(array_keys($ctx['nums']) as $i) {
        $xml .= '<w:num w:numId="' . ($i + 1) . '"><w:abstractNumId w:val="' . $i . '"/></w:num>';
    }
    return $xml . '</w:numbering>';
}

function docx_styles_xml(): string {
    $heading = function(int $level, int $sz, int $before, int $after, bool $italic = false): string {
        return '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading ' . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="' . $before . '" w:after="' . $after . '"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/>' . ($italic ? '<w:i/><w:iCs/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr></w:style>';
    };
    $quote_border = '<w:pBdr><w:left w:val="single" w:sz="18" w:space="10" w:color="CCCCCC"/></w:pBdr>';

    return docx_xml_header() . '<w:styles xmlns:w="' . DOCX_NS_W . '">'
        . '<w:docDefaults>'
        . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
        . '</w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:rPr><w:color w:val="1A1A1A"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="0" w:after="240"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="40"/><w:szCs w:val="40"/></w:rPr></w:style>'
        . $heading(1, 32, 360, 120)
        . $heading(2, 28, 300, 100)
        . $heading(3, 24, 240, 80)
        . $heading(4, 22, 200, 60)
        . $heading(5, 22, 200, 60, true)
        . $heading(6, 22, 200, 60, true)
        . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="60"/><w:ind w:left="720"/></w:pPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr>' . $quote_border . '<w:spacing w:before="60" w:after="60"/><w:ind w:left="360"/></w:pPr><w:rPr><w:color w:val="444444"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="QuoteCaption"><w:name w:val="Quote Caption"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr>' . $quote_border . '<w:spacing w:before="0" w:after="200"/><w:ind w:left="360"/></w:pPr><w:rPr><w:color w:val="888888"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="CodeBlock"><w:name w:val="Code Block"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Caption"><w:name w:val="caption"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="0" w:after="200"/><w:jc w:val="center"/></w:pPr><w:rPr><w:color w:val="888888"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Normal"/><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="DDDDDD"/></w:pBdr><w:spacing w:after="0"/></w:pPr><w:rPr><w:color w:val="999999"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/></w:style>'
        . '<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:basedOn w:val="DefaultParagraphFont"/><w:rPr><w:color w:val="1A5FD0"/><w:u w:val="single"/></w:rPr></w:style>'
        . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:semiHidden/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
        . '</w:styles>';
}

// ------------------------------------------------------------
// ZIP container (deflate via zlib; entries are stored when zlib is missing)
// ------------------------------------------------------------

function docx_zip(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    $dos_time = (int)date('H') << 11 | (int)date('i') << 5 | (int)date('s') >> 1;
    $dos_date = ((int)date('Y') - 1980) << 9 | (int)date('n') << 5 | (int)date('j');

    foreach($files as $name => $data) {
        $data = (string)$data;
        $crc = crc32($data);
        $method = 0;
        $payload = $data;
        if(function_exists('gzdeflate')) {
            $deflated = gzdeflate($data, 6);
            if($deflated !== false && strlen($deflated) < strlen($data)) {
                $method = 8;
                $payload = $deflated;
            }
        }
        $name_len = strlen($name);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dos_time, $dos_date, $crc, strlen($payload), strlen($data), $name_len, 0) . $name;
        $local .= $header . $payload;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dos_time, $dos_date, $crc, strlen($payload), strlen($data), $name_len, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($header) + strlen($payload);
        $count++;
    }

    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
}
