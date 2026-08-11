<?php

if(!defined('ABSPATH')){exit;}

const FAVICON_MAX_HTML_BYTES = 1048576;
const FAVICON_MAX_MANIFEST_BYTES = 262144;
const FAVICON_MAX_IMAGE_BYTES = 1572864;

/** Accept a full website URL or a bare hostname and normalize it to HTTP(S). */
function favicon_normalize_website_url(string $url): ?string {
    $url = trim($url);
    if($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) return null;

    if(!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if(!is_array($parts)) return null;

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if(!in_array($scheme, ['http', 'https'], true)) return null;
    if(isset($parts['user']) || isset($parts['pass'])) return null;

    $host = strtolower(rtrim(trim((string)($parts['host'] ?? ''), '[]'), '.'));
    if($host === '') return null;

    if(function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host)) {
        $ascii_host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if($ascii_host === false) return null;
        $host = strtolower($ascii_host);
    }

    if(filter_var($host, FILTER_VALIDATE_IP) === false
        && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) {
        return null;
    }

    $default_port = $scheme === 'https' ? 443 : 80;
    $port = isset($parts['port']) ? (int)$parts['port'] : $default_port;
    if(!in_array($port, [80, 443], true)) return null;

    $host_for_url = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $authority = $host_for_url;
    if($port !== $default_port) $authority .= ':' . $port;

    $path = (string)($parts['path'] ?? '/');
    if($path === '') $path = '/';
    if(!str_starts_with($path, '/')) $path = '/' . $path;

    $normalized = $scheme . '://' . $authority . $path;
    if(isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }

    return filter_var($normalized, FILTER_VALIDATE_URL) !== false ? $normalized : null;
}

function favicon_ip_is_public(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/** Resolve a hostname and reject it when any answer targets a private/reserved network. */
function favicon_resolve_public_ips(string $host): array {
    $host = trim($host, '[]');
    if(filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return favicon_ip_is_public($host) ? [$host] : [];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if(is_array($records)) {
        foreach($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if($ip !== '') $ips[] = $ip;
        }
    }

    if(empty($ips)) {
        $ipv4 = @gethostbynamel($host);
        if(is_array($ipv4)) $ips = array_merge($ips, $ipv4);
    }

    $ips = array_values(array_unique($ips));
    if(empty($ips)) return [];

    foreach($ips as $ip) {
        if(!favicon_ip_is_public($ip)) return [];
    }

    return $ips;
}

function favicon_normalize_path(string $path): string {
    $segments = explode('/', $path);
    $result = [];

    foreach($segments as $segment) {
        if($segment === '' || $segment === '.') continue;
        if($segment === '..') {
            array_pop($result);
            continue;
        }
        $result[] = $segment;
    }

    return '/' . implode('/', $result);
}

/** Resolve an HTML/manifest href against the final document URL. */
function favicon_resolve_url(string $base_url, string $href): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if($href === '' || str_starts_with($href, '#')) return null;

    if(preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        return favicon_normalize_website_url($href);
    }

    $base = parse_url($base_url);
    if(!is_array($base) || empty($base['scheme']) || empty($base['host'])) return null;

    $scheme = strtolower($base['scheme']);
    if(str_starts_with($href, '//')) {
        return favicon_normalize_website_url($scheme . ':' . $href);
    }

    $host = str_contains($base['host'], ':') ? '[' . trim($base['host'], '[]') . ']' : $base['host'];
    $default_port = $scheme === 'https' ? 443 : 80;
    $port = isset($base['port']) ? (int)$base['port'] : $default_port;
    $authority = $host . ($port !== $default_port ? ':' . $port : '');

    $fragment_pos = strpos($href, '#');
    if($fragment_pos !== false) $href = substr($href, 0, $fragment_pos);

    if(str_starts_with($href, '?')) {
        $path = $base['path'] ?? '/';
        return favicon_normalize_website_url($scheme . '://' . $authority . $path . $href);
    }

    $href_parts = parse_url($href);
    if($href_parts === false) return null;

    $href_path = (string)($href_parts['path'] ?? '');
    if(str_starts_with($href_path, '/')) {
        $path = favicon_normalize_path($href_path);
    } else {
        $base_path = (string)($base['path'] ?? '/');
        $base_dir = str_ends_with($base_path, '/') ? $base_path : dirname($base_path) . '/';
        $path = favicon_normalize_path($base_dir . $href_path);
    }

    $resolved = $scheme . '://' . $authority . $path;
    if(isset($href_parts['query']) && $href_parts['query'] !== '') {
        $resolved .= '?' . $href_parts['query'];
    }

    return favicon_normalize_website_url($resolved);
}

function favicon_origin_url(string $url): ?string {
    $parts = parse_url($url);
    if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return null;

    $scheme = strtolower($parts['scheme']);
    $host = str_contains($parts['host'], ':') ? '[' . trim($parts['host'], '[]') . ']' : $parts['host'];
    $default_port = $scheme === 'https' ? 443 : 80;
    $port = isset($parts['port']) ? (int)$parts['port'] : $default_port;

    return $scheme . '://' . $host . ($port !== $default_port ? ':' . $port : '');
}

/**
 * Fetch a public HTTP(S) resource with DNS pinning, manual redirect validation,
 * TLS verification and a strict response-size limit.
 */
function favicon_http_fetch(string $url, int $max_bytes, string $accept, float $timeout_seconds = 10.0): array {
    $current_url = favicon_normalize_website_url($url);
    if($current_url === null) return ['success' => false, 'error' => 'invalid_url'];
    $deadline = microtime(true) + max(0.25, $timeout_seconds);

    for($redirects = 0; $redirects <= 4; $redirects++) {
        $remaining_ms = (int)floor(($deadline - microtime(true)) * 1000);
        if($remaining_ms <= 0) return ['success' => false, 'error' => 'fetch_failed'];

        $parts = parse_url($current_url);
        $host = trim((string)($parts['host'] ?? ''), '[]');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $ips = favicon_resolve_public_ips($host);

        if(empty($ips)) return ['success' => false, 'error' => 'blocked_host'];

        $body = '';
        $headers = [];
        $too_large = false;

        $ch = curl_init($current_url);
        $resolve = [];
        if(filter_var($host, FILTER_VALIDATE_IP) === false) {
            usort($ips, fn($a, $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));
            $pinned_ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
            $resolve[] = $host . ':' . $port . ':' . $pinned_ip;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(4000, $remaining_ms),
            CURLOPT_TIMEOUT_MS => $remaining_ms,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NotesApp-Favicon/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: ' . $accept,
                'Accept-Language: en-US,en;q=0.8',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => function($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if(preg_match('#^HTTP/\S+\s+#i', $line)) {
                    $headers = [];
                    return $length;
                }
                $pos = strpos($line, ':');
                if($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $headers[$name] = trim(substr($line, $pos + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function($curl, string $chunk) use (&$body, &$too_large, $max_bytes): int {
                if(strlen($body) + strlen($chunk) > $max_bytes) {
                    $too_large = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if(!empty($resolve)) $options[CURLOPT_RESOLVE] = $resolve;
        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if($too_large) return ['success' => false, 'error' => 'too_large'];
        if($ok === false) return ['success' => false, 'error' => 'fetch_failed'];

        if(in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
            if($redirects >= 4) return ['success' => false, 'error' => 'too_many_redirects'];
            $next_url = favicon_resolve_url($current_url, $headers['location']);
            if($next_url === null) return ['success' => false, 'error' => 'invalid_redirect'];
            $current_url = $next_url;
            continue;
        }

        if($status < 200 || $status >= 300) {
            return ['success' => false, 'error' => 'http_error', 'status' => $status];
        }

        return [
            'success' => true,
            'body' => $body,
            'content_type' => strtolower(trim(explode(';', $content_type)[0])),
            'url' => $current_url,
        ];
    }

    return ['success' => false, 'error' => 'too_many_redirects'];
}

function favicon_icon_score(string $url, string $type = '', string $sizes = '', string $rel = ''): int {
    $score = 0;
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    $type = strtolower($type);
    $rel = strtolower($rel);

    if($type === 'image/svg+xml' || str_ends_with($path, '.svg')) $score += 100000;
    if($rel === 'icon' || $rel === 'shortcut icon') $score += 20000;
    elseif(str_contains($rel, 'mask-icon')) $score += 18000;
    elseif(str_contains($rel, 'apple-touch-icon')) $score += 10000;
    else $score += 5000;

    if(str_contains(strtolower($sizes), 'any')) $score += 8000;
    if(preg_match_all('/(\d+)x(\d+)/i', $sizes, $matches, PREG_SET_ORDER)) {
        foreach($matches as $match) {
            $score += min(4096, max((int)$match[1], (int)$match[2]));
        }
    }

    return $score;
}

/** Find declared icon and manifest URLs in a website document. */
function favicon_discover_document(string $html, string $document_url): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if(!$loaded) return ['icons' => [], 'manifests' => []];

    $base_url = $document_url;
    $base_nodes = $dom->getElementsByTagName('base');
    if($base_nodes->length > 0) {
        $base_href = $base_nodes->item(0)?->getAttribute('href') ?? '';
        $resolved_base = favicon_resolve_url($document_url, $base_href);
        if($resolved_base !== null) $base_url = $resolved_base;
    }

    $icons = [];
    $manifests = [];

    foreach($dom->getElementsByTagName('link') as $link) {
        $href = trim($link->getAttribute('href'));
        $rel = strtolower(trim(preg_replace('/\s+/', ' ', $link->getAttribute('rel'))));
        if($href === '' || $rel === '') continue;

        $resolved = favicon_resolve_url($base_url, $href);
        if($resolved === null) continue;

        $rel_tokens = preg_split('/\s+/', $rel) ?: [];
        if(in_array('manifest', $rel_tokens, true)) {
            $manifests[] = $resolved;
        }

        if(str_contains($rel, 'icon')) {
            $icons[] = [
                'url' => $resolved,
                'score' => favicon_icon_score(
                    $resolved,
                    $link->getAttribute('type'),
                    $link->getAttribute('sizes'),
                    $rel
                ),
            ];
        }
    }

    usort($icons, fn($a, $b) => $b['score'] <=> $a['score']);

    return [
        'icons' => array_values(array_unique(array_column($icons, 'url'))),
        'manifests' => array_values(array_unique($manifests)),
    ];
}

function favicon_discover_manifest(string $json, string $manifest_url): array {
    $manifest = json_decode($json, true);
    if(!is_array($manifest) || !is_array($manifest['icons'] ?? null)) return [];

    $icons = [];
    foreach($manifest['icons'] as $icon) {
        if(!is_array($icon) || empty($icon['src'])) continue;
        $resolved = favicon_resolve_url($manifest_url, (string)$icon['src']);
        if($resolved === null) continue;
        $icons[] = [
            'url' => $resolved,
            'score' => favicon_icon_score(
                $resolved,
                (string)($icon['type'] ?? ''),
                (string)($icon['sizes'] ?? ''),
                'manifest-icon'
            ),
        ];
    }

    usort($icons, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_values(array_unique(array_column($icons, 'url')));
}

function favicon_svg_has_external_url(string $value): bool {
    if(!preg_match_all('/url\s*\(\s*([^)]+)\)/i', $value, $matches)) return false;

    foreach($matches[1] as $target) {
        $target = trim($target, " \t\n\r\0\x0B\"'");
        if(!str_starts_with($target, '#')) return true;
    }

    return false;
}

/** Remove executable/external SVG features while preserving normal vector artwork. */
function sanitize_svg_icon(string $svg): ?string {
    if($svg === '' || strlen($svg) > FAVICON_MAX_IMAGE_BYTES) return null;
    if(preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) return null;

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if(!$loaded || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
        return null;
    }

    $dangerous_elements = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'image',
        'audio', 'video', 'canvas', 'animate', 'animatemotion',
        'animatetransform', 'set', 'discard',
    ];

    $elements = [];
    foreach($dom->getElementsByTagName('*') as $element) $elements[] = $element;

    foreach($elements as $element) {
        $name = strtolower($element->localName);
        $namespace = (string)$element->namespaceURI;

        if(($namespace !== '' && $namespace !== 'http://www.w3.org/2000/svg')
            || in_array($name, $dangerous_elements, true)) {
            $element->parentNode?->removeChild($element);
            continue;
        }

        if($name === 'style' && (
            preg_match('/@import|expression\s*\(|-moz-binding|behavior\s*:/i', $element->textContent)
            || favicon_svg_has_external_url($element->textContent)
        )) {
            $element->parentNode?->removeChild($element);
            continue;
        }

        $attributes = [];
        foreach($element->attributes ?? [] as $attribute) $attributes[] = $attribute;

        foreach($attributes as $attribute) {
            $attr_name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue);
            $remove = false;

            if(str_starts_with($attr_name, 'on') || in_array($attr_name, ['src', 'xml:base'], true)) {
                $remove = true;
            } elseif(in_array($attr_name, ['href', 'xlink:href'], true) && !preg_match('/^#[A-Za-z_][\w:.-]*$/', $value)) {
                $remove = true;
            } elseif($attr_name === 'style' && (
                preg_match('/@import|expression\s*\(|-moz-binding|behavior\s*:/i', $value)
                || favicon_svg_has_external_url($value)
            )) {
                $remove = true;
            } elseif(preg_match('/javascript\s*:|data\s*:\s*text\/html/i', $value)) {
                $remove = true;
            } elseif(favicon_svg_has_external_url($value)) {
                $remove = true;
            }

            if($remove) $element->removeAttributeNode($attribute);
        }
    }

    $xpath = new DOMXPath($dom);
    $remove_nodes = [];
    foreach($xpath->query('//comment() | //processing-instruction()') ?: [] as $node) {
        $remove_nodes[] = $node;
    }
    foreach($remove_nodes as $node) $node->parentNode?->removeChild($node);

    $clean = $dom->saveXML($dom->documentElement);
    if(!is_string($clean) || $clean === '') return null;
    $clean = preg_replace('/>\s+</', '><', $clean);
    return trim($clean);
}

/** Validate image bytes and encode a browser-renderable favicon as a data URI. */
function favicon_image_data_uri(string $data, string $reported_type = ''): ?array {
    if($data === '' || strlen($data) > FAVICON_MAX_IMAGE_BYTES) return null;

    $trimmed = ltrim($data, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
    $is_svg = preg_match('/^(?:<\?xml[^>]*>\s*)?<svg\b/i', $trimmed) === 1;

    if($is_svg || $reported_type === 'image/svg+xml') {
        $clean_svg = sanitize_svg_icon($data);
        if($clean_svg === null) return null;
        return [
            'data_uri' => 'data:image/svg+xml;base64,' . base64_encode($clean_svg),
            'mime' => 'image/svg+xml',
        ];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_buffer($finfo, $data) : '';

    if(($mime === 'application/octet-stream' || $mime === '')
        && strlen($data) >= 4 && substr($data, 0, 4) === "\x00\x00\x01\x00") {
        $mime = 'image/x-icon';
    }

    $mime_aliases = [
        'image/vnd.microsoft.icon' => 'image/x-icon',
        'image/x-ico' => 'image/x-icon',
    ];
    $mime = $mime_aliases[$mime] ?? $mime;

    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/x-icon'];
    if(!in_array($mime, $allowed, true)) return null;

    $dimensions = @getimagesizefromstring($data);
    if(is_array($dimensions)) {
        $width = (int)($dimensions[0] ?? 0);
        $height = (int)($dimensions[1] ?? 0);
        if($width > 4096 || $height > 4096 || ($width > 0 && $height > 0 && $width * $height > 16777216)) {
            return null;
        }
    }

    return [
        'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($data),
        'mime' => $mime,
    ];
}

/** Discover, download and encode the best favicon exposed by a website. */
function fetch_website_favicon(string $website_input): array {
    $started_at = microtime(true);
    $deadline = $started_at + 18.0;
    $has_explicit_scheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($website_input)) === 1;
    $website_url = favicon_normalize_website_url($website_input);
    if($website_url === null) {
        return ['success' => false, 'error' => 'Enter a valid website URL.'];
    }

    $page = favicon_http_fetch(
        $website_url,
        FAVICON_MAX_HTML_BYTES,
        'text/html,application/xhtml+xml,image/*;q=0.8,*/*;q=0.5',
        min(10.0, $deadline - microtime(true))
    );

    // Bare hostnames default to HTTPS, with HTTP as a compatibility fallback.
    if(!$page['success'] && !$has_explicit_scheme && str_starts_with($website_url, 'https://')) {
        $http_url = 'http://' . substr($website_url, strlen('https://'));
        $http_page = favicon_http_fetch(
            $http_url,
            FAVICON_MAX_HTML_BYTES,
            'text/html,application/xhtml+xml,image/*;q=0.8,*/*;q=0.5',
            max(0.25, min(10.0, $deadline - microtime(true)))
        );
        if($http_page['success']) {
            $website_url = $http_url;
            $page = $http_page;
        }
    }

    $document_url = $page['success'] ? $page['url'] : $website_url;
    $candidates = [];
    $manifests = [];

    if($page['success']) {
        // A direct image URL is also accepted as a convenience.
        $direct = favicon_image_data_uri($page['body'], $page['content_type']);
        if($direct !== null) {
            return array_merge($direct, [
                'success' => true,
                'website_url' => $website_url,
                'source_url' => $page['url'],
            ]);
        }

        $discovered = favicon_discover_document($page['body'], $document_url);
        $candidates = $discovered['icons'];
        $manifests = $discovered['manifests'];
    }

    foreach(array_slice($manifests, 0, 2) as $manifest_url) {
        if(microtime(true) - $started_at > 12) break;
        $manifest = favicon_http_fetch(
            $manifest_url,
            FAVICON_MAX_MANIFEST_BYTES,
            'application/manifest+json,application/json;q=0.9,*/*;q=0.5',
            max(0.25, min(6.0, $deadline - microtime(true)))
        );
        if($manifest['success']) {
            $candidates = array_merge(
                $candidates,
                favicon_discover_manifest($manifest['body'], $manifest['url'])
            );
        }
    }

    $origin = favicon_origin_url($document_url);
    if($origin !== null) $candidates[] = $origin . '/favicon.ico';
    $candidates = array_values(array_unique($candidates));

    foreach(array_slice($candidates, 0, 10) as $candidate_url) {
        if(microtime(true) - $started_at > 18) break;

        $image = favicon_http_fetch(
            $candidate_url,
            FAVICON_MAX_IMAGE_BYTES,
            'image/avif,image/webp,image/svg+xml,image/png,image/*;q=0.9,*/*;q=0.4',
            max(0.25, min(10.0, $deadline - microtime(true)))
        );
        if(!$image['success']) continue;

        $encoded = favicon_image_data_uri($image['body'], $image['content_type']);
        if($encoded === null) continue;

        return array_merge($encoded, [
            'success' => true,
            'website_url' => $website_url,
            'source_url' => $image['url'],
        ]);
    }

    if(($page['error'] ?? '') === 'blocked_host') {
        return ['success' => false, 'error' => 'This website address is not publicly reachable.'];
    }

    return ['success' => false, 'error' => 'No favicon was found for this website.'];
}
