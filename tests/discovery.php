<?php
declare(strict_types=1);
// Isolated data fixtures; these checks never modify a live installation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/alternpress-discovery-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
foreach (['lib', 'cache', 'storage', 'media', 'content/it/home', 'content/en/home', 'content/it/about', 'content/en/about', 'content/it/skills', 'content/en/skills'] as $directory) mkdir($fixture . '/' . $directory, 0700, true);
foreach (['index.php', 'config.php', 'lib/bootstrap.php', 'lib/update-health.php', 'lib/update-release.php', 'lib/Parsedown.php', 'lib/discovery.php', 'lib/page-cache.php'] as $file) copy($source . '/' . $file, $fixture . '/' . $file);
file_put_contents($fixture . '/config.local.php', "<?php return " . var_export([
    'name' => 'Discovery fixture',
    'origin' => 'https://origin.example',
    'site_url' => 'https://canonical.example/blog',
    'base_path' => '/installed',
    'email' => 'private-settings@example.invalid',
    'legal' => 'PRIVATE-LEGAL-CONFIGURATION',
], true) . ';');
define('AP_LIBRARY', true);
require $fixture . '/index.php';
$checks = [];
function discovery_expect(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks[] = $message;
}
function discovery_write(string $language, string $id, array $meta, string $body = 'Public article body.'): string {
    $directory = AP_ROOT . '/content/' . $language . '/post/' . $id;
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    $front = "---\n";
    foreach ($meta as $key => $value) $front .= $key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $path = $directory . '/index.md';
    file_put_contents($path, $front . "---\n\n" . $body);
    clearstatcache(true, $path);
    return $path;
}
function discovery_nodes(array $graph, string $type): array {
    return array_values(array_filter($graph['@graph'] ?? [], static function(array $node) use ($type): bool {
        $types = (array) ($node['@type'] ?? []);
        return in_array($type, $types, true);
    }));
}
function discovery_json(array $graph): string { return json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function discovery_urls(string $xml): array {
    preg_match_all('~<loc>(.*?)</loc>~s', $xml, $matches);
    return array_map(static fn(string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches[1]);
}
function discovery_url_block(string $xml, string $url): string {
    preg_match_all('~<url>(.*?)</url>~s', $xml, $matches);
    foreach ($matches[1] as $block) if (in_array($url, discovery_urls($block), true)) return $block;
    return '';
}
try {
    foreach (['it', 'en'] as $language) {
        foreach (['home', 'about', 'skills'] as $page) file_put_contents(AP_ROOT . '/content/' . $language . '/' . $page . '/index.md', "---\ntitle: Page " . $page . "\n---\n\nPublished page.");
    }
    file_put_contents(AP_ROOT . '/content/en/about/index.md', "---\ntitle: PRIVATE-ABOUT\nstatus: draft\n---\n\nPRIVATE-PAGE-BODY");
    $title = 'Caffè & "</script><script>alert(1)</script>"';
    $published = discovery_write('it', 'shared', ['title' => $title, 'excerpt' => 'Public summary & details', 'date' => '2026-01-02', 'status' => 'published', 'tags' => '[linux, sicurezza]']);
    touch($published, 1767351845);
    clearstatcache(true, $published);
    discovery_write('en', 'shared', ['title' => 'Shared English', 'date' => '2026-01-02', 'status' => 'published', 'tags' => '[linux]']);
    discovery_write('it', 'italian-only', ['title' => 'Solo italiano', 'date' => '2026-01-03', 'status' => 'published']);
    discovery_write('en', 'italian-only', ['title' => 'PRIVATE-TRANSLATION', 'status' => 'draft']);
    discovery_write('it', 'secret', ['title' => 'PRIVATE-ARTICLE', 'excerpt' => 'PRIVATE-EXCERPT', 'status' => 'draft'], 'PRIVATE-BODY');
    $binary = discovery_write('it', 'binary', ['title' => 'PRIVATE-BINARY']);
    file_put_contents($binary, "bad\0PRIVATE-BINARY");
    $invalid = discovery_write('it', 'invalid-utf8', ['title' => 'PRIVATE-UTF8']);
    file_put_contents($invalid, "\xffPRIVATE-UTF8");
    $oversized = discovery_write('it', 'oversized', ['title' => 'PRIVATE-OVERSIZED']);
    file_put_contents($oversized, str_repeat('x', Parsedown::MAX_BYTES + 1));
    mkdir(AP_ROOT . '/content/it/post/directory/index.md', 0700, true);
    file_put_contents(AP_ROOT . '/storage/private.md', "---\ntitle: PRIVATE-SYMLINK\n---\nPRIVATE-SYMLINK-BODY");
    if (function_exists('symlink') && @symlink(AP_ROOT . '/storage/private.md', AP_ROOT . '/content/it/post/linked.md')) {
        mkdir(AP_ROOT . '/content/it/post/symlinked', 0700);
        symlink(AP_ROOT . '/storage/private.md', AP_ROOT . '/content/it/post/symlinked/index.md');
        symlink(AP_ROOT . '/storage', AP_ROOT . '/content/it/post/linked-directory');
    }

    $graph = ap_discovery_graph('it', '/post/shared');
    discovery_expect(($graph['@context'] ?? '') === 'https://schema.org', 'article graph uses Schema.org');
    $articles = discovery_nodes($graph, 'BlogPosting');
    discovery_expect(count($articles) === 1, 'article page identifies exactly one BlogPosting');
    $article = $articles[0];
    discovery_expect(($article['headline'] ?? '') === $title && ($article['inLanguage'] ?? '') === 'it', 'article title and language come from the published translation');
    discovery_expect(str_starts_with((string) ($article['@id'] ?? ''), 'https://canonical.example/blog/it/post/shared'), 'article has a stable canonical identity');
    discovery_expect(str_starts_with((string) ($article['datePublished'] ?? ''), '2026-01-02'), 'publication date comes from editorial metadata');
    discovery_expect(str_starts_with((string) ($article['dateModified'] ?? ''), '2026-01-02'), 'modification date reflects the source file');
    discovery_expect(!empty($article['mainEntityOfPage']) && !empty($article['isPartOf']), 'article is connected to its webpage and blog');
    discovery_expect(count(discovery_nodes($graph, 'WebSite')) === 1 && count(discovery_nodes($graph, 'Blog')) >= 1, 'graph includes website and blog identities');
    discovery_expect(count(discovery_nodes($graph, 'DefinedTerm')) >= 2 && !empty($article['about']), 'article tags form linked topic entities');
    discovery_expect(empty($article['author']), 'site brand is not invented as a person author');
    discovery_expect(($article['workTranslation'][0]['@id'] ?? '') === 'https://canonical.example/blog/en/post/shared#article', 'published translations are connected in the graph');
    discovery_expect(empty(discovery_nodes(ap_discovery_graph('it', '/post/italian-only'), 'BlogPosting')[0]['workTranslation']), 'draft translations are excluded from graph relationships');
    discovery_expect(discovery_json($graph) === discovery_json(ap_discovery_graph('it', '/post/shared')), 'graph identity and dates remain stable between reads');
    discovery_expect(count(discovery_nodes(ap_discovery_graph('it', '/post/secret'), 'BlogPosting')) === 0, 'direct draft graph contains no article');
    discovery_expect(count(discovery_nodes(ap_discovery_graph('it', '/post/../secret'), 'BlogPosting')) === 0, 'invalid article routes cannot enter the graph');

    $itMap = ap_discovery_sitemap('it');
    $enMap = ap_discovery_sitemap('en');
    $index = ap_discovery_sitemap_index();
    $llms = ap_discovery_llms();
    $whole = ap_discovery_knowledge_graph();
    $all = $itMap . $enMap . $index . $llms . discovery_json($whole);
    foreach (['PRIVATE-ARTICLE', 'PRIVATE-EXCERPT', 'PRIVATE-BODY', 'PRIVATE-TRANSLATION', 'PRIVATE-PAGE-BODY', 'PRIVATE-SYMLINK', 'PRIVATE-UTF8', 'PRIVATE-BINARY', 'PRIVATE-LEGAL-CONFIGURATION', 'private-settings@example.invalid'] as $private) discovery_expect(!str_contains($all, $private), 'discovery excludes ' . $private);
    $itUrls = discovery_urls($itMap);
    $enUrls = discovery_urls($enMap);
    discovery_expect(in_array('https://canonical.example/blog/it/post/shared', $itUrls, true) && in_array('https://canonical.example/blog/en/post/shared', $enUrls, true), 'published articles appear in their language sitemaps');
    discovery_expect(!in_array('https://canonical.example/blog/en/post/italian-only', $enUrls, true) && !in_array('https://canonical.example/blog/en/about', $enUrls, true), 'unpublished translations and pages are absent from sitemap');
    discovery_expect(count($itUrls) === count(array_unique($itUrls)) && !in_array('https://canonical.example/blog/it/home', $itUrls, true), 'sitemap has no duplicate URLs or home aliases');
    foreach ([$itMap, $enMap] as $map) discovery_expect(str_contains($map, 'http://www.sitemaps.org/schemas/sitemap/0.9') && str_contains($map, 'http://www.w3.org/1999/xhtml'), 'sitemap declares required XML namespaces');
    foreach (['it' => $itMap, 'en' => $enMap] as $language => $map) {
        $block = discovery_url_block($map, 'https://canonical.example/blog/' . $language . '/post/shared');
        discovery_expect(str_contains($block, 'hreflang="it"') && str_contains($block, 'hreflang="en"'), 'translated sitemap entry includes self and reciprocal alternate');
    }
    $single = discovery_url_block($itMap, 'https://canonical.example/blog/it/post/italian-only');
    discovery_expect(!str_contains($single, 'hreflang="en"'), 'single-language article does not advertise a missing translation');
    discovery_expect(in_array('https://canonical.example/blog/sitemap-it.xml', discovery_urls($index), true) && in_array('https://canonical.example/blog/sitemap-en.xml', discovery_urls($index), true), 'sitemap index uses canonical folder for language maps');
    discovery_expect(str_starts_with($llms, '# ') && str_contains($llms, 'https://canonical.example/blog/it/post/shared') && str_contains($llms, 'https://canonical.example/blog/en/post/shared'), 'llms.txt lists canonical articles in both languages');
    discovery_expect(count(discovery_nodes($whole, 'BlogPosting')) === 3, 'whole knowledge graph includes only published article translations');
    $ids = array_filter(array_column($whole['@graph'] ?? [], '@id'));
    discovery_expect(count($ids) === count(array_unique($ids)), 'whole graph has unique entity identities');
    $robots = ap_discovery_robots();
    discovery_expect(str_contains($robots, 'Sitemap: https://canonical.example/blog/sitemap.xml') && str_contains($robots, '/installed/admin'), 'robots advertises canonical sitemap and protects installation-relative admin');

    // Render the real layout to detect script-closing injection, not just JSON syntax.
    ob_start();
    render_layout('it', '/post/shared', $title, '<p>Visible fixture</p>');
    $html = (string) ob_get_clean();
    preg_match_all('~<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $scripts);
    discovery_expect(count($scripts[1]) === 1, 'rendered article emits one JSON-LD script');
    $embedded = json_decode($scripts[1][0], true, 64, JSON_THROW_ON_ERROR);
    discovery_expect(discovery_nodes($embedded, 'BlogPosting')[0]['headline'] === $title && !str_contains($html, '<script>alert(1)</script>'), 'JSON-LD serialization cannot close the script element');

    // Mutations happen without time passing: discovery must not rely on an old index.
    discovery_write('it', 'new-publication', ['title' => 'New publication', 'date' => '2026-01-04', 'status' => 'published']);
    discovery_expect(str_contains(ap_discovery_llms(), '/it/post/new-publication') && str_contains(ap_discovery_sitemap('it'), '/it/post/new-publication') && str_contains(discovery_json(ap_discovery_knowledge_graph()), '/it/post/new-publication'), 'publication is immediately present in every discovery index');
    discovery_write('it', 'new-publication', ['title' => 'Updated publication', 'date' => '2026-01-04', 'status' => 'published']);
    discovery_expect(str_contains(ap_discovery_llms(), 'Updated publication') && str_contains(discovery_json(ap_discovery_knowledge_graph()), 'Updated publication'), 'edits immediately refresh article metadata');
    discovery_write('it', 'new-publication', ['title' => 'Withdrawn publication', 'status' => 'draft']);
    discovery_expect(!str_contains(ap_discovery_llms() . ap_discovery_sitemap('it') . discovery_json(ap_discovery_knowledge_graph()), '/it/post/new-publication'), 'withdrawal immediately removes all discovery references');
    unlink(AP_ROOT . '/content/en/post/shared/index.md');
    discovery_expect(!str_contains(discovery_url_block(ap_discovery_sitemap('it'), 'https://canonical.example/blog/it/post/shared'), 'hreflang="en"'), 'deleted translation immediately disappears from alternates');

    $AP_CONFIG['site_url'] = '';
    discovery_expect(str_contains(ap_discovery_sitemap('it'), 'https://origin.example/installed/it/post/shared') && str_contains(ap_discovery_sitemap_index(), 'https://origin.example/installed/sitemap-it.xml'), 'automatic canonical addressing includes actual installation folder once');
    $AP_CONFIG['site_url'] = 'https://canonical.example/blog';
    $AP_CONFIG['search_indexing'] = false;
    discovery_expect(discovery_urls(ap_discovery_sitemap_index()) === [] && discovery_urls(ap_discovery_sitemap('it')) === [], 'noindex site exposes empty sitemap index and language maps');
    discovery_expect(($graph = ap_discovery_knowledge_graph())['@graph'] === [], 'noindex site exposes no whole-site graph');
    discovery_expect(!str_contains(ap_discovery_llms(), '/it/post/shared') && str_contains(ap_discovery_robots(), 'Disallow: /'), 'noindex suppresses article discovery and blocks crawlers');

    foreach ($checks as $message) echo 'PASS ' . $message . "\n";
    echo count($checks) . " discovery checks passed.\n";
} finally {
    if (!preg_match('~/alternpress-discovery-[a-f0-9]{12}\z~D', $fixture) || is_link($fixture)) throw new RuntimeException('Invalid test cleanup path.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
}
