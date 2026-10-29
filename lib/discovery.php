<?php
declare(strict_types=1);

/** Public, generated discovery documents. Never scan storage, caches or raw uploads. */
function ap_discovery_base_url(): string {
    $configured = (string) ap_config('site_url');
    return rtrim($configured !== '' ? $configured : SITE_ORIGIN . ap_base(), '/');
}

function ap_discovery_url(string $path = '/'): string {
    return ap_discovery_base_url() . '/' . ltrim($path, '/');
}

function ap_discovery_text(mixed $value, int $limit = 500): string {
    if (!is_string($value)) return '';
    // Front matter is plain text, not HTML. Keep literal angle brackets and
    // entities consistent with the visible escaped title; encode at output.
    $value = preg_replace('/[\x00-\x1f\x7f]+|\s+/u', ' ', $value) ?? '';
    return mb_substr(trim($value), 0, $limit);
}

function ap_discovery_languages(): array {
    return array_values(array_filter(SUPPORTED_LANGUAGES, static fn($language): bool =>
        is_string($language) && preg_match('/\A[a-z]{2}(?:-[A-Za-z]{2,8})?\z/D', $language) === 1));
}

function ap_discovery_enabled(): bool { return (bool) ap_config('search_indexing'); }

/** Only explicit, valid dates are asserted as publication dates. */
function ap_discovery_date(string $value): ?string {
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function ap_discovery_document(string $language, string $relativePath): ?array {
    global $CONTENT_ROOT;
    if (!in_array($language, ap_discovery_languages(), true)) return null;
    $file = null;
    $id = null;
    if ($relativePath === '/') $file = $CONTENT_ROOT . '/' . $language . '/home/index.md';
    elseif (in_array($relativePath, ['/skills', '/about'], true)) $file = $CONTENT_ROOT . '/' . $language . $relativePath . '/index.md';
    elseif (preg_match('~\A/post/([a-z0-9]+(?:-[a-z0-9]+)*)\z~D', $relativePath, $match) && ap_id_valid($match[1])) {
        $id = $match[1];
        $file = $CONTENT_ROOT . '/' . $language . '/post/' . $id . '/index.md';
    }
    if ($file === null) return null;
    $raw = public_markdown($file);
    if ($raw === null) return null;
    [$meta, $body] = parse_front_matter($raw);
    if (!ap_published($meta)) return null;
    $title = ap_discovery_text($meta['title'] ?? '', 250);
    if ($title === '' && preg_match('/^#\s+(.+)$/mu', $body, $heading)) $title = ap_discovery_text($heading[1], 250);
    if ($title === '') $title = $id ?? (labels($language)[match ($relativePath) { '/skills' => 'skills', '/about' => 'contact', default => 'home' }] ?? ap_config('name'));
    $description = ap_discovery_text($meta['excerpt'] ?? '', 500);
    if ($description === '') {
        $plain = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $body) ?? $body;
        $plain = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $plain) ?? $plain;
        $description = ap_discovery_text(preg_replace('/[#>*`_~]+/u', ' ', $plain) ?? $plain, 160);
    }
    $date = ap_discovery_date(is_string($meta['date'] ?? null) ? $meta['date'] : ($id === null ? '' : substr($id, 0, 10)));
    clearstatcache(true, $file);
    $mtime = filemtime($file);
    $modified = is_int($mtime) && $mtime > 0 ? gmdate('c', $mtime) : null;
    // A manually future-dated article must not claim a modification before publication.
    if ($date !== null && is_int($mtime) && $mtime < strtotime($date)) $modified = null;
    return [
        'language' => $language, 'path' => $relativePath, 'id' => $id,
        'url' => ap_canonical_url($language, $relativePath), 'title' => $title,
        'description' => $description, 'date' => $date, 'modified' => $modified,
        'tags' => array_values(array_filter($meta['tags'] ?? [], 'is_string')),
        'cover' => is_string($meta['cover'] ?? null) ? $meta['cover'] : '',
    ];
}

/** Fresh reads keep manual edits, publication and deletion visible immediately. */
function ap_discovery_documents(string $language): array {
    global $CONTENT_ROOT;
    if (!in_array($language, ap_discovery_languages(), true)) return [];
    $documents = [];
    foreach (['/', '/skills', '/about'] as $path) {
        $document = ap_discovery_document($language, $path);
        if ($document !== null) $documents[] = $document;
    }
    foreach (list_post_dirs($CONTENT_ROOT . '/' . $language . '/post') as $directory) {
        $document = ap_discovery_document($language, '/post/' . basename($directory));
        if ($document !== null) $documents[] = $document;
    }
    return $documents;
}

function ap_discovery_common_nodes(): array {
    $root = ap_discovery_url();
    $name = ap_discovery_text(ap_config('name'), 250);
    $nodes = [
        ['@type' => 'WebSite', '@id' => $root . '#website', 'url' => $root, 'name' => $name, 'inLanguage' => ap_discovery_languages(), 'publisher' => ['@id' => $root . '#publisher']],
        ['@type' => 'Organization', '@id' => $root . '#publisher', 'name' => $name, 'url' => $root],
    ];
    $author = ap_discovery_text(ap_config('author_name'), 250);
    if ($author !== '') {
        $node = ['@type' => 'Person', '@id' => $root . '#author', 'name' => $author];
        $authorUrl = ap_config('author_url');
        if (is_string($authorUrl) && ap_discovery_http_url($authorUrl)) $node['url'] = $authorUrl;
        $nodes[] = $node;
    }
    return $nodes;
}

function ap_discovery_http_url(string $value): bool {
    $parts = parse_url($value);
    return filter_var($value, FILTER_VALIDATE_URL) !== false && is_array($parts)
        && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        && !isset($parts['user']) && !isset($parts['pass'])
        && !preg_match('/[\x00-\x20\x7f]/', $value);
}

function ap_discovery_blog_node(string $language): array {
    return ['@type' => 'Blog', '@id' => ap_canonical_url($language, '/archive') . '#blog',
        'url' => ap_canonical_url($language, '/archive'), 'name' => ap_discovery_text(ap_config('name'), 250),
        'inLanguage' => $language, 'isPartOf' => ['@id' => ap_discovery_url() . '#website'],
        'publisher' => ['@id' => ap_discovery_url() . '#publisher']];
}

/** The same stable entity IDs are shared by HTML and the downloadable graph. */
function ap_discovery_document_nodes(array $document, bool $compact = false): array {
    $root = ap_discovery_url();
    $url = $document['url'];
    $language = $document['language'];
    $page = ['@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url,
        'name' => $document['title'], 'inLanguage' => $language,
        'isPartOf' => ['@id' => $root . '#website']];
    if ($document['description'] !== '' && !$compact) $page['description'] = $document['description'];
    if ($document['modified'] !== null) $page['dateModified'] = $document['modified'];
    if ($document['id'] === null) return [$page];
    $page['mainEntity'] = ['@id' => $url . '#article'];
    $article = ['@type' => 'BlogPosting', '@id' => $url . '#article', 'url' => $url,
        'headline' => $document['title'], 'inLanguage' => $language,
        'mainEntityOfPage' => ['@id' => $url . '#webpage'],
        'isPartOf' => ['@id' => ap_canonical_url($language, '/archive') . '#blog'],
        'publisher' => ['@id' => $root . '#publisher']];
    if ($document['description'] !== '' && !$compact) $article['description'] = $document['description'];
    if ($document['date'] !== null) $article['datePublished'] = $document['date'];
    if ($document['modified'] !== null) $article['dateModified'] = $document['modified'];
    if (ap_discovery_text(ap_config('author_name'), 250) !== '') $article['author'] = ['@id' => $root . '#author'];
    foreach (ap_discovery_languages() as $other) {
        if ($other === $language || ap_discovery_document($other, $document['path']) === null) continue;
        // AlternPress pairs translations by slug. The default-language version
        // is the reference work only when it is actually published.
        if ($language === DEFAULT_LANGUAGE) $article['workTranslation'][] = ['@id' => ap_canonical_url($other, $document['path']) . '#article'];
        elseif ($other === DEFAULT_LANGUAGE) $article['translationOfWork'] = ['@id' => ap_canonical_url($other, $document['path']) . '#article'];
    }
    if ($document['cover'] !== '') {
        $image = ap_image_url($document['cover'], $language, $document['id']);
        if (str_starts_with($image, '/') && !str_starts_with($image, '//')) {
            // Media paths use the installation folder; canonical URLs may use a different folder.
            $base = ap_base();
            if ($base !== '' && ($image === $base || str_starts_with($image, $base . '/'))) $image = substr($image, strlen($base));
            $image = ap_discovery_url($image);
        }
        if (ap_discovery_http_url($image)) $article['image'] = $image;
    }
    $topics = [];
    foreach ($document['tags'] as $tag) {
        $termId = $root . '#topic-' . rawurlencode($tag);
        $topics[] = ['@type' => 'DefinedTerm', '@id' => $termId, 'name' => $tag];
        $article['about'][] = ['@id' => $termId];
    }
    if ($document['tags'] !== []) $article['keywords'] = $document['tags'];
    return array_merge([$page, $article], $topics);
}

function ap_discovery_graph(string $language, string $relativePath): array {
    global $CONTENT_ROOT;
    $graph = ['@context' => 'https://schema.org', '@graph' => []];
    if (!ap_discovery_enabled() || !in_array($language, ap_discovery_languages(), true)) return $graph;
    $document = ap_discovery_document($language, $relativePath);
    if ($document === null && !route_content_exists($CONTENT_ROOT, $language, $relativePath)) return $graph;
    $nodes = ap_discovery_common_nodes();
    if ($document !== null) {
        if ($document['id'] !== null) $nodes[] = ap_discovery_blog_node($language);
        $nodes = array_merge($nodes, ap_discovery_document_nodes($document));
    } else {
        $url = ap_canonical_url($language, $relativePath);
        $nodes[] = ['@type' => 'CollectionPage', '@id' => $url . '#webpage', 'url' => $url,
            'inLanguage' => $language, 'isPartOf' => ['@id' => ap_discovery_url() . '#website']];
        if ($relativePath === '/archive') $nodes[] = ap_discovery_blog_node($language);
    }
    $graph['@graph'] = $nodes;
    return $graph;
}

function ap_discovery_description(string $language, string $relativePath, string $fallback): string {
    $document = ap_discovery_document($language, $relativePath);
    return $document !== null && $document['description'] !== '' ? $document['description'] : $fallback;
}

function ap_discovery_knowledge_graph(): array {
    $graph = ['@context' => 'https://schema.org', '@graph' => []];
    if (!ap_discovery_enabled()) return $graph;
    $nodes = [];
    foreach (ap_discovery_common_nodes() as $node) $nodes[$node['@id']] = $node;
    foreach (ap_discovery_languages() as $language) {
        foreach (ap_discovery_documents($language) as $document) {
            if ($document['id'] !== null) {
                $blog = ap_discovery_blog_node($language);
                $nodes[$blog['@id']] = $blog;
            }
            foreach (ap_discovery_document_nodes($document, true) as $node) $nodes[$node['@id']] = $node;
        }
    }
    $graph['@graph'] = array_values($nodes);
    return $graph;
}

function ap_discovery_xml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_discovery_sitemap_index(): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    if (ap_discovery_enabled()) foreach (ap_discovery_languages() as $language) {
        $xml .= '  <sitemap><loc>' . ap_discovery_xml(ap_discovery_url('/sitemap-' . $language . '.xml')) . '</loc></sitemap>' . "\n";
    }
    return $xml . "</sitemapindex>\n";
}

function ap_discovery_sitemap(string $language): string {
    global $CONTENT_ROOT;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    if (!ap_discovery_enabled() || !in_array($language, ap_discovery_languages(), true)) return $xml . "</urlset>\n";
    $documents = ap_discovery_documents($language);
    foreach (['/archive', '/tags'] as $path) {
        if (route_content_exists($CONTENT_ROOT, $language, $path)) $documents[] = ['path' => $path, 'url' => ap_canonical_url($language, $path), 'modified' => null];
    }
    foreach ($documents as $document) {
        $xml .= '  <url><loc>' . ap_discovery_xml($document['url']) . '</loc>';
        if ($document['modified'] !== null) $xml .= '<lastmod>' . ap_discovery_xml($document['modified']) . '</lastmod>';
        $alternates = [];
        foreach (ap_discovery_languages() as $other) {
            if (route_content_exists($CONTENT_ROOT, $other, $document['path'])) $alternates[$other] = ap_canonical_url($other, $document['path']);
        }
        foreach ($alternates as $other => $url) {
            $xml .= '<xhtml:link rel="alternate" hreflang="' . ap_discovery_xml($other) . '" href="' . ap_discovery_xml($url) . '" />';
        }
        if (isset($alternates[DEFAULT_LANGUAGE])) $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . ap_discovery_xml($alternates[DEFAULT_LANGUAGE]) . '" />';
        $xml .= "</url>\n";
    }
    return $xml . "</urlset>\n";
}

/** Prevent document titles from creating new Markdown sections or links. */
function ap_discovery_markdown_text(string $value): string {
    return str_replace(['\\', '[', ']', '*', '_', '`', '<', '>'], ['\\\\', '\\[', '\\]', '\\*', '\\_', '\\`', '&lt;', '&gt;'], ap_discovery_text($value, 250));
}

function ap_discovery_llms(): string {
    $text = '# ' . ap_discovery_markdown_text((string) ap_config('name')) . "\n\n";
    if (!ap_discovery_enabled()) return $text . "> Public discovery is disabled by the site owner.\n";
    $descriptions = ap_config('description');
    $summary = is_array($descriptions) ? ($descriptions[DEFAULT_LANGUAGE] ?? '') : '';
    if (is_string($summary) && $summary !== '') $text .= '> ' . ap_discovery_markdown_text($summary) . "\n\n";
    $text .= "Published pages and articles, generated automatically from this site's public content. Each language has its own canonical URL. Structured metadata is embedded in article HTML; the knowledge graph links articles, the site and their topics.\n\n";
    $text .= "## Site indexes\n\n- [Sitemap index](" . ap_discovery_url('/sitemap.xml') . ")\n- [Knowledge graph](" . ap_discovery_url('/knowledge-graph.jsonld') . ")\n";
    foreach (ap_discovery_languages() as $language) {
        $documents = ap_discovery_documents($language);
        $text .= "\n## " . strtoupper($language) . " pages\n\n";
        foreach ($documents as $document) {
            if ($document['id'] === null) $text .= '- [' . ap_discovery_markdown_text($document['title']) . '](' . $document['url'] . ")\n";
        }
        $text .= "\n## " . strtoupper($language) . " articles\n\n";
        // Titles + URLs, without full text or duplicate excerpts, keep the index bounded.
        foreach ($documents as $document) {
            if ($document['id'] !== null) $text .= '- [' . ap_discovery_markdown_text($document['title']) . '](' . $document['url'] . ")\n";
        }
    }
    return $text;
}

function ap_discovery_robots(): string {
    if (!ap_discovery_enabled()) return "User-agent: *\nDisallow: /\n";
    $text = "User-agent: *\n";
    foreach (['admin', 'storage', 'content', 'cache', 'lib', 'bin', 'tests'] as $directory) $text .= 'Disallow: ' . ap_url('/' . $directory . '/') . "\n";
    return $text . "\nSitemap: " . ap_discovery_url('/sitemap.xml') . "\n";
}

function ap_discovery_dispatch(string $path): void {
    $type = null;
    $body = null;
    if ($path === '/sitemap.xml') { $type = 'application/xml'; $body = ap_discovery_sitemap_index(); }
    elseif (preg_match('~\A/sitemap-([a-z]{2}(?:-[A-Za-z]{2,8})?)\.xml\z~D', $path, $match) && in_array($match[1], ap_discovery_languages(), true)) { $type = 'application/xml'; $body = ap_discovery_sitemap($match[1]); }
    elseif ($path === '/llms.txt') { $type = 'text/plain'; $body = ap_discovery_llms(); }
    elseif ($path === '/robots.txt') { $type = 'text/plain'; $body = ap_discovery_robots(); }
    elseif ($path === '/knowledge-graph.jsonld') { $type = 'application/ld+json'; $body = json_encode(ap_discovery_knowledge_graph(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    if ($body === null) return;
    header('Content-Type: ' . $type . '; charset=utf-8');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    $etag = '"' . hash('sha256', $body) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
    exit;
}
