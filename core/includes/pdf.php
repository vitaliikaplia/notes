<?php

if(!defined('ABSPATH')){exit;}

// ============================================================
// PDF export — renders a note's Editor.js blocks to a PDF via
// Dompdf, reusing render_blocks_to_html() from notes.php.
// ============================================================

/**
 * Inline local upload images (/file/... or legacy /uploads/...) as data URIs
 * so Dompdf can render them without filesystem or network access.
 */
function pdf_embed_local_images(string $html): string {
    return preg_replace_callback('/src="([^"]+)"/', function($m) {
        $src = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $relative = upload_url_to_relative_path($src);
        if($relative === null || str_contains($relative, '..')) {
            return $m[0];
        }

        $filepath = ABSPATH . DS . 'uploads' . DS . str_replace('/', DS, $relative);
        if(!is_file($filepath)) {
            return $m[0];
        }

        $mime_map = [
            'webp' => 'image/webp',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
        ];
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime = $mime_map[$ext] ?? 'application/octet-stream';

        return 'src="data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($filepath)) . '"';
    }, $html);
}

/**
 * Adapt the shared block HTML to what Dompdf can actually draw:
 * checkbox inputs -> glyphs, iframes -> plain links, inline SVG stripped.
 */
function pdf_adapt_html(string $html): string {
    // Checklist checkboxes: form inputs render poorly, use DejaVu glyphs
    $html = preg_replace('/<input type="checkbox" disabled checked>/', '<span class="cb">&#9745;</span>', $html);
    $html = preg_replace('/<input type="checkbox" disabled>/', '<span class="cb">&#9744;</span>', $html);

    // Embeds: Dompdf cannot render iframes — show the source URL as a link
    $html = preg_replace_callback('/<div class="embed-block"[^>]*><iframe src="([^"]+)"[^>]*><\/iframe><\/div>/', function($m) {
        return '<p class="embed-link"><a href="' . $m[1] . '">' . $m[1] . '</a></p>';
    }, $html);

    // Inline SVG icons (page links) are not worth the rendering trouble
    $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/s', '', $html);

    return pdf_embed_local_images($html);
}

function pdf_render_note_html(string $title, array $blocks): string {
    $body = pdf_adapt_html(render_blocks_to_html($blocks));
    $safe_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $css = <<<CSS
        * { margin: 0; padding: 0; }
        /* This dompdf build ignores @page rules; body margin is what sets the page margins */
        body { margin: 22mm 18mm 20mm; font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.45; color: #1a1a1a; }
        h1 { font-size: 15pt; margin: 0 0 12pt; }
        h2 { font-size: 12pt; margin: 14pt 0 7pt; }
        h3 { font-size: 10.5pt; margin: 12pt 0 5pt; }
        h4, h5, h6 { font-size: 9.5pt; margin: 10pt 0 5pt; }
        p { margin: 0 0 7pt; }
        ul, ol { margin: 0 0 7pt 16pt; }
        li { margin: 0 0 2.5pt; }
        a { color: #1a6fb0; text-decoration: none; }
        mark { background: #fff3a3; }
        code { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; background: #f2f2f2; padding: 1pt 3pt; }
        pre { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; background: #f5f5f5; border: 0.5pt solid #ddd; padding: 7pt; margin: 0 0 9pt; white-space: pre-wrap; word-wrap: break-word; }
        pre code { background: none; padding: 0; }
        blockquote { border-left: 2pt solid #ccc; padding: 3pt 0 3pt 9pt; margin: 0 0 9pt; color: #444; }
        blockquote cite { display: block; margin-top: 3pt; font-size: 8pt; color: #888; }
        table { border-collapse: collapse; width: 100%; margin: 0 0 9pt; }
        th, td { border: 0.5pt solid #bbb; padding: 3pt 5pt; font-size: 8.5pt; text-align: left; }
        th { background: #f2f2f2; }
        hr { border: none; border-top: 0.5pt solid #ccc; margin: 10pt 0; }
        figure { margin: 0 0 9pt; }
        img { max-width: 100%; }
        figcaption { font-size: 8pt; color: #888; margin-top: 3pt; }
        .checklist { margin: 0 0 7pt; }
        .checklist-item { margin: 0 0 2.5pt; }
        .cb { font-family: "DejaVu Sans", sans-serif; }
        .cdx-page-link { margin: 0 0 7pt; padding: 4pt 7pt; border: 0.5pt solid #ddd; background: #fafafa; }
        .embed-link { font-size: 8pt; word-wrap: break-word; }
        .note-footer { margin-top: 18pt; padding-top: 5pt; border-top: 0.5pt solid #ddd; font-size: 7.5pt; color: #999; }
    CSS;

    return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><style>{$css}</style></head><body>"
        . "<h1>{$safe_title}</h1>\n"
        . $body
        . "<div class=\"note-footer\">" . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . ' — ' . date('Y-m-d') . "</div>"
        . "</body></html>";
}

/** Render a note title + blocks to PDF bytes. */
function note_export_pdf(string $title, array $blocks): string {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(pdf_render_note_html($title, $blocks), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return (string)$dompdf->output();
}
