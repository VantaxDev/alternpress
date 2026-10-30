<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$CONTENT_ROOT = __DIR__ . '/content';
$CACHE_DIR    = __DIR__ . '/cache';

if (!public_path_ok($CONTENT_ROOT, true)) {
    http_response_code(500);
    echo 'Missing content directory.';
    exit;
}

require_once __DIR__ . '/lib/Parsedown.php';
$md = new Parsedown();
$md->setSafeMode(true);


function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render an email without exposing the address or a mailto: URL in the
 * server-generated HTML. The address is Base64-encoded and reconstructed
 * only in the browser when the visitor clicks the link.
 */
function protected_mailto_link(string $target, string $label = 'Email'): string
{
    $target = trim(html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $target = preg_replace('/[\r\n]+/', '', $target) ?? $target;

    $address = rawurldecode(explode('?', $target, 2)[0]);
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        return h($label);
    }

    return '<a href="#" class="js-protected-email" data-e="'
        . h(base64_encode($target))
        . '" rel="nofollow">'
        . h($label)
        . '</a>';
}

function protected_email_link(string $email, string $label = 'Email'): string
{
    return protected_mailto_link($email, $label);
}

/**
 * Protect email addresses that arrive from rendered Markdown too.
 * - mailto: links keep their human-readable label when possible
 * - a label that is itself the email address becomes simply "Email"
 * - plain email addresses become protected links
 * - code/pre/script/style blocks are left untouched
 */
function protect_emails_in_html(string $html): string
{
    // First replace explicit mailto: anchors so the address disappears
    // from both href and visible text when the label is the address itself.
    $html = preg_replace_callback(
        "#<a\\b([^>]*?)href=([\"'])mailto:([^\"']+)\\2([^>]*)>(.*?)</a>#is",
        static function (array $m): string {
            $target = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $email = rawurldecode(explode('?', $target, 2)[0]);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $m[0];
            }

            $visible = trim(html_entity_decode(strip_tags($m[5]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = strcasecmp($visible, $email) === 0 || $visible === '' ? 'Email' : $visible;
            return protected_mailto_link($target, $label);
        },
        $html
    ) ?? $html;

    // Work only on text nodes, and deliberately leave technical code examples alone.
    $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
    $protectedDepth = 0;

    foreach ($parts as &$part) {
        if ($part === '') {
            continue;
        }

        if ($part[0] === '<') {
            if (preg_match('#^</(?:code|pre|script|style|a)\b#i', $part)) {
                $protectedDepth = max(0, $protectedDepth - 1);
            } elseif (preg_match('#^<(?:code|pre|script|style|a)\b#i', $part) && !preg_match('#/\s*>$#', $part)) {
                $protectedDepth++;
            }
            continue;
        }

        if ($protectedDepth > 0) {
            continue;
        }

        $part = preg_replace_callback(
            '/(?<![A-Z0-9._%+\-])([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})(?![A-Z0-9._%+\-])/iu',
            static function (array $m): string {
                if (!filter_var($m[1], FILTER_VALIDATE_EMAIL)) {
                    return $m[0];
                }
                return protected_email_link($m[1], 'Email');
            },
            $part
        ) ?? $part;
    }
    unset($part);

    return implode('', $parts);
}

function public_path_ok(string $path, bool $directory = false): bool
{
    try { ap_assert_path($path); }
    catch (RuntimeException) { return false; }
    return $directory ? is_dir($path) && is_readable($path) : is_file($path) && is_readable($path);
}

/** Read only bounded, regular UTF-8 content inside this installation. */
function public_markdown(string $path): ?string
{
    if (!public_path_ok($path) || filesize($path) > Parsedown::MAX_BYTES) return null;
    $raw = file_get_contents($path, false, null, 0, Parsedown::MAX_BYTES + 1);
    if ($raw === false || strlen($raw) > Parsedown::MAX_BYTES || !mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0") || substr_count($raw, "\n") > 39999) return null;
    return $raw;
}

function public_document_published(string $path): bool
{
    $raw = public_markdown($path);
    return $raw !== null && ap_published(parse_front_matter($raw)[0]);
}

function public_post_asset(string $contentDir, string $id, string $filename): ?string
{
    $filename = rawurldecode($filename);
    if (!ap_id_valid($id) || strlen($filename) > 255 || preg_match('~[\\\\/\x00-\x1f\x7f]~', $filename) || !preg_match('/\A[^.].*\.(?:jpg|jpeg|png|webp|gif)\z/iD', $filename)) return null;
    $directory = $contentDir . '/post/' . $id;
    $path = $directory . '/' . $filename;
    return public_document_published($directory . '/index.md') && public_path_ok($path) ? $path : null;
}

function read_file_or_404(string $path): string
{
    $content = public_markdown($path);
    if ($content === null || !ap_published(parse_front_matter($content)[0])) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    return $content;
}

function normalize_tags(string $value): array
{
    $value = trim($value);
    if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
        $value = trim(substr($value, 1, -1));
    }

    $parts = array_map('trim', explode(',', $value));
    $tags = [];

    foreach ($parts as $part) {
        $part = strtolower(trim($part));
        $part = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $part);
        $part = preg_replace('/\s+/', '-', (string) $part);
        if ($part !== '') {
            $tags[] = $part;
        }
    }

    $tags = array_values(array_unique($tags));
    sort($tags);
    return $tags;
}

function parse_front_matter(string $raw): array
{
    $meta = [];
    $body = $raw;

    if (preg_match('/\A---\s*\R(.*?)\R---\s*\R/s', $raw, $match)) {
        $frontMatter = $match[1];
        $body = substr($raw, strlen($match[0]));
        $lines = preg_split('/\R/', $frontMatter) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $decoded = json_decode($value, true);
                $value = is_string($decoded) ? $decoded : substr($value, 1, -1);
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            if ($key === 'tags') {
                $meta['tags'] = normalize_tags((string) $value);
            } else {
                $meta[$key] = $value;
            }
        }
    }

    if (!isset($meta['tags'])) {
        $meta['tags'] = [];
    }

    return [$meta, $body];
}

function route_path(): string
{
    $request = $_SERVER['REQUEST_URI'] ?? '/';
    if (!is_string($request) || strlen($request) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $request)) return '/invalid-route';
    $uri = parse_url($request, PHP_URL_PATH);
    if (!is_string($uri)) return '/invalid-route';
    $uri = '/' . ltrim($uri, '/');
    $base = ap_base();
    if ($base !== '' && ($uri === $base || str_starts_with($uri, $base . '/'))) $uri = substr($uri, strlen($base)) ?: '/';
    $uri = rtrim($uri, '/');
    return $uri === '' ? '/' : $uri;
}

function preferred_language(): string
{
    $cookieLanguage = is_string($_COOKIE['alternpress_lang'] ?? null) ? strtolower($_COOKIE['alternpress_lang']) : '';
    if (in_array($cookieLanguage, SUPPORTED_LANGUAGES, true)) {
        return $cookieLanguage;
    }

    $header = substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 4096);
    foreach (explode(',', $header) as $candidate) {
        $languageTag = strtolower(trim(explode(';', $candidate, 2)[0]));
        $primary = explode('-', $languageTag, 2)[0];
        if (in_array($primary, SUPPORTED_LANGUAGES, true)) {
            return $primary;
        }
    }

    return DEFAULT_LANGUAGE;
}

function redirect_to(string $location, int $status = 302): never
{
    header('Location: ' . $location, true, $status);
    exit;
}

function language_url(string $language, string $relativePath = '/'): string
{
    $relativePath = '/' . ltrim($relativePath, '/');
    if ($relativePath !== '/') {
        $relativePath = rtrim($relativePath, '/');
    }
    return ap_url('/' . $language . ($relativePath === '/' ? '' : $relativePath));
}

function split_language_route(string $path): array
{
    if (!preg_match('#^/(' . implode('|', array_map(static fn($lang) => preg_quote($lang, '#'), SUPPORTED_LANGUAGES)) . ')(/.*)?$#', $path, $match)) {
        return ['', $path];
    }

    $language = $match[1];
    $relativePath = $match[2] ?? '/';
    return [$language, $relativePath === '' ? '/' : $relativePath];
}

function route_content_exists(string $contentRoot, string $language, string $relativePath): bool
{
    $contentDir = rtrim($contentRoot, '/\\') . '/' . $language;
    if (!public_path_ok($contentDir, true)) {
        return false;
    }

    if ($relativePath === '/' || $relativePath === '/home') {
        return public_document_published($contentDir . '/home/index.md');
    }

    if ($relativePath === '/skills' || $relativePath === '/about') {
        return public_document_published($contentDir . $relativePath . '/index.md');
    }

    if ($relativePath === '/archive' || $relativePath === '/tags') {
        return public_path_ok($contentDir . '/post', true);
    }

    if (preg_match('#^/post/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $relativePath, $match)) {
        $file = $contentDir . '/post/' . $match[1] . '/index.md';
        return ap_id_valid($match[1]) && public_document_published($file);
    }

    if (preg_match('#^/tag/[^/]+$#u', $relativePath)) {
        return public_path_ok($contentDir . '/post', true);
    }

    return false;
}

function list_post_dirs(string $postsRoot): array
{
    if (!public_path_ok($postsRoot, true)) return [];
    $dirs = glob($postsRoot . '/*', GLOB_ONLYDIR) ?: [];
    $valid = [];

    foreach ($dirs as $dir) {
        if (ap_id_valid(basename($dir)) && public_path_ok($dir, true)) {
            $valid[] = $dir;
        }
    }

    rsort($valid);
    return array_slice($valid, 0, 10000);
}

function build_posts_index(string $contentDir, string $language): array
{
    $postsRoot = $contentDir . '/post';
    $dirs = is_dir($postsRoot) ? list_post_dirs($postsRoot) : [];
    $posts = [];

    foreach ($dirs as $dir) {
        $id = basename($dir);
        $date = substr($id, 0, 10);
        $markdownPath = $dir . '/index.md';
        $raw = public_markdown($markdownPath);
        if ($raw === null) {
            continue;
        }

        [$meta, $body] = parse_front_matter($raw);
        if (!ap_published($meta)) continue;
        $date = $meta['date'] ?? $date;
        $title = $meta['title'] ?? $date;
        $excerpt = $meta['excerpt'] ?? '';

        if ($excerpt === '') {
            $plain = trim((string) preg_replace('/[#>*`\[\]\(\)_!-]+/', ' ', $body));
            $plain = (string) preg_replace('/\s+/', ' ', $plain);
            $excerpt = mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '…' : '');
        }

        $posts[] = [
            'id' => $id,
            'date' => $date,
            'title' => $title,
            'tags' => $meta['tags'] ?? [],
            'cover' => $meta['cover'] ?? null,
            'excerpt' => $excerpt,
            'url' => language_url($language, '/post/' . $id),
        ];
    }

    usort($posts, static fn($a, $b) => strcmp($b['date'] . $b['id'], $a['date'] . $a['id']));
    return $posts;
}

function posts_source_mtime(string $postsRoot): int
{
    $latest = public_path_ok($postsRoot, true) ? (filemtime($postsRoot) ?: 0) : 0;
    foreach (list_post_dirs($postsRoot) as $dir) {
        $path = $dir . '/index.md';
        if (public_path_ok($path)) {
            $latest = max($latest, filemtime($path) ?: 0);
        }
    }
    return $latest;
}

function load_posts(string $contentDir, string $cacheDir, string $language): array
{
    $fingerprint = hash_init('sha256');
    hash_update($fingerprint, ap_base());
    foreach (list_post_dirs($contentDir . '/post') as $directory) {
        $source = $directory . '/index.md';
        $raw = public_markdown($source);
        if ($raw !== null) { hash_update($fingerprint, $source); hash_update($fingerprint, $raw); }
    }
    $signature = hash_final($fingerprint);
    $cacheFile = $cacheDir . '/posts-' . $language . '.json';
    $cached = public_path_ok($cacheFile) && filesize($cacheFile) < 8388608 ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && ($cached['signature'] ?? '') === $signature && public_cached_posts_valid($cached['posts'] ?? null, $language)) return $cached['posts'];
    $posts = build_posts_index($contentDir, $language);
    if (public_path_ok($cacheDir, true) && is_writable($cacheDir)) {
        try { ap_atomic_write($cacheFile, json_encode(['signature' => $signature, 'posts' => $posts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        catch (Throwable $error) { error_log('AlternPress: public index cache unavailable.'); }
    }
    return $posts;
}

function public_cached_posts_valid(mixed $posts, string $language): bool
{
    if (!is_array($posts) || !array_is_list($posts) || count($posts) > 10000) return false;
    foreach ($posts as $post) {
        if (!is_array($post)) return false;
        foreach (['id', 'date', 'title', 'excerpt', 'url'] as $key) if (!is_string($post[$key] ?? null)) return false;
        if (!ap_id_valid($post['id']) || $post['url'] !== language_url($language, '/post/' . $post['id']) || !is_array($post['tags'] ?? null)) return false;
        if (isset($post['cover']) && !is_string($post['cover'])) return false;
        foreach ($post['tags'] as $tag) if (!is_string($tag)) return false;
    }
    return true;
}

function markdown_html(Parsedown $markdown, string $body): string
{
    // Transform generated nodes only. Placeholders in user text or attributes
    // must never become HTML, and code examples remain literal.
    return ap_rewrite_html(protect_emails_in_html($markdown->text($body)));
}

function labels(string $language): array
{
    $translations = [
        'en' => [
            'home' => 'Home',
            'skills' => 'Skills',
            'articles' => 'Articles',
            'contact' => 'Contact',
            'technical_articles' => 'Technical articles',
            'main_navigation' => 'Main navigation',
            'language_navigation' => 'Language selector',
            'site_description' => '',
            'footer' => '',
            'posts' => 'articles',
            'page_not_found' => 'Page not found.',
        ],
        'it' => [
            'home' => 'Home',
            'skills' => 'Competenze',
            'articles' => 'Articoli',
            'contact' => 'Contatti',
            'technical_articles' => 'Articoli tecnici',
            'main_navigation' => 'Navigazione principale',
            'language_navigation' => 'Selettore lingua',
            'site_description' => '',
            'footer' => '',
            'posts' => 'articoli',
            'page_not_found' => 'Pagina non trovata.',
        ],
    ];

    $text = $translations[$language] ?? $translations[DEFAULT_LANGUAGE];
    $text['site_description'] = ap_config('description')[$language] ?? '';
    $text['footer'] = ap_config('tagline')[$language] ?? '';
    $text['technical_articles'] = $language === 'it' ? 'Articoli' : 'Articles';
    return $text;
}

function tag_label(string $tag, string $language): string
{
    $translations = [
        'it' => [
            'security' => 'sicurezza',
        ],
    ];

    return $translations[$language][$tag] ?? $tag;
}

function nav_active(string $relativePath, string $target): string
{
    if ($target === '/' && $relativePath === '/') {
        return ' aria-current="page" class="active"';
    }
    if ($target !== '/' && ($relativePath === $target || str_starts_with($relativePath, $target . '/'))) {
        return ' aria-current="page" class="active"';
    }
    return '';
}

function ap_public_language_cookie(string $language): void
{
    if (PHP_SAPI === 'cli' || !in_array($language, SUPPORTED_LANGUAGES, true)) return;
    if (($_COOKIE['alternpress_lang'] ?? '') !== $language) {
        setcookie('alternpress_lang', $language, [
            'expires' => time() + 31536000,
            'path' => ap_url('/'),
            'secure' => ap_https(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

function render_layout(string $language, string $relativePath, string $title, string $contentHtml): void
{
    global $CONTENT_ROOT;

    $text = labels($language);
    $contentHtml = protect_emails_in_html($contentHtml);
    $siteTitle = ap_config('name');
    $fullTitle = $title ? ($title . ' · ' . $siteTitle) : $siteTitle;
    $currentUrl = ap_canonical_url($language, $relativePath);
    $otherLanguage = $language === 'it' ? 'en' : 'it';
    $otherRelativePath = route_content_exists($CONTENT_ROOT, $otherLanguage, $relativePath)
        ? $relativePath
        : '/';
    $otherUrl = language_url($otherLanguage, $otherRelativePath);
    $alternateUrls = [];
    foreach (SUPPORTED_LANGUAGES as $alternateLanguage) {
        if (route_content_exists($CONTENT_ROOT, $alternateLanguage, $relativePath)) {
            $alternateUrls[$alternateLanguage] = ap_canonical_url($alternateLanguage, $relativePath);
        }
    }
    $xDefaultUrl = $alternateUrls[DEFAULT_LANGUAGE]
        ?? ap_canonical_url($language, $relativePath);
    $structuredData = ap_discovery_graph($language, $relativePath);
    $description = ap_discovery_description($language, $relativePath, $text['site_description']);
    ap_public_language_cookie($language);
    ob_start();
?><!doctype html>
<html lang="<?= h($language) ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($fullTitle) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($structuredData !== []): ?>
  <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <?php endif; ?>
  <link rel="alternate" type="application/ld+json" href="<?= h(ap_url('/knowledge-graph.jsonld')) ?>" title="Knowledge graph">
  <link rel="sitemap" type="application/xml" href="<?= h(ap_url('/sitemap.xml')) ?>">
  <link rel="alternate" type="text/plain" href="<?= h(ap_url('/llms.txt')) ?>" title="LLM content index">
  <link rel="icon" href="<?= h(ap_url('/assets/favicon.svg')) ?>" type="image/svg+xml">
  <meta name="theme-color" content="#111827">
  <link rel="canonical" href="<?= h($currentUrl) ?>">
  <?php foreach ($alternateUrls as $alternateLanguage => $alternateUrl): ?>
  <link rel="alternate" hreflang="<?= h($alternateLanguage) ?>" href="<?= h($alternateUrl) ?>">
  <?php endforeach; ?>
  <link rel="alternate" hreflang="x-default" href="<?= h($xDefaultUrl) ?>">
  <link rel="stylesheet" href="<?= h(ap_url('/assets/base.css')) ?>">
  <link rel="stylesheet" href="<?= h(ap_url('/assets/site.css')) ?>">
  <?php if (ap_config('custom_css') !== ''): ?>
  <link rel="stylesheet" href="<?= h(ap_url('/index.php') . '?ap-css=custom&v=' . substr(hash('sha256', (string) ap_config('custom_css')), 0, 16)) ?>">
  <?php endif; ?>
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a href="<?= h(language_url($language)) ?>" class="logo" aria-label="<?= h($siteTitle) ?> · <?= h($text['home']) ?>">
      <span class="logo-mark"><?= h(ap_config('logo_mark')) ?></span>
      <span class="logo-text"><?= h($siteTitle) ?></span>
    </a>

    <div class="header-actions">
      <nav class="main-nav" aria-label="<?= h($text['main_navigation']) ?>">
        <?php foreach (ap_config('navigation') as $target => $label): ?>
        <a href="<?= h(language_url($language, $target)) ?>"<?= nav_active($relativePath, $target) ?>><?= h($text[$label] ?? $label) ?></a>
        <?php endforeach; ?>
      </nav>

      <nav class="language-switch" aria-label="<?= h($text['language_navigation']) ?>">
        <a href="<?= h($language === 'en' ? language_url('en', $relativePath) : $otherUrl) ?>" lang="en"<?= $language === 'en' ? ' aria-current="page" class="active"' : '' ?>>EN</a>
        <span aria-hidden="true">/</span>
        <a href="<?= h($language === 'it' ? language_url('it', $relativePath) : $otherUrl) ?>" lang="it"<?= $language === 'it' ? ' aria-current="page" class="active"' : '' ?>>IT</a>
      </nav>
    </div>
  </div>
</header>

<main class="container">
  <?= $contentHtml ?>
</main>

<footer class="container footer">

  <small>© <?= date('Y') ?> <div id="legal-info" class="footer-credits"> </div> <?= h($text['footer']) ?><?php if (ap_config('email') !== ''): ?> · <?= protected_email_link(ap_config('email'), 'Email') ?><?php endif; ?></small>
</footer>
<script>
        // Offuscamento base per impedire il facile scraping testuale della P.IVA
        const info = <?= json_encode(ap_config('legal'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.getElementById('legal-info').textContent = info;
    </script>
<script>
(() => {
  document.querySelectorAll('.js-protected-email[data-e]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      try {
        const address = atob(link.dataset.e || '');
        if (!address) return;
        window.location.href = 'mai' + 'lto:' + address;
      } catch (_) {
        // Invalid token: leave the link inert rather than exposing anything.
      }
    });
  });
})();
</script>
</body>
</html><?php
    // HTML parsers normalize line endings before checking inline-script hashes.
    $html = str_replace(["\r\n", "\r"], "\n", (string) ob_get_clean());
    // Hash the exact trusted script bodies. Unlike a cached random nonce,
    // these hashes remain valid without granting permission to other scripts.
    preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $html, $scriptBodies);
    $hashes = array_unique(array_map(static fn(string $body): string => "'sha256-" . base64_encode(hash('sha256', $body, true)) . "'", $scriptBodies[1]));
    $csp = "default-src 'self'; script-src " . ($hashes ? implode(' ', $hashes) : "'none'") . "; style-src 'self'; img-src 'self' https: http:; connect-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
    if (defined('AP_LIBRARY') && AP_LIBRARY) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, max-age=0, must-revalidate');
        header('Content-Security-Policy: ' . $csp);
        echo $html;
        return;
    }
    ap_page_cache_finish($language, $relativePath, $html, $csp);
}

require_once __DIR__ . '/lib/discovery.php';
require_once __DIR__ . '/lib/page-cache.php';

if (defined('AP_LIBRARY') && AP_LIBRARY) return;

if (($_GET['ap-css'] ?? null) === 'custom') {
    $css = ap_custom_css();
    $etag = '"' . hash('sha256', $css) . '"';
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    echo $css; exit;
}


$path = route_path();
ap_discovery_dispatch($path);

if ($path === '/') {
    redirect_to(language_url(preferred_language()));
}

[$language, $relativePath] = split_language_route($path);

if ($language === '') {
    // Backward-compatible redirects for links from the previous monolingual site.
    redirect_to(language_url(DEFAULT_LANGUAGE, $relativePath), 301);
}

$contentDir = $CONTENT_ROOT . '/' . $language;
if (!public_path_ok($contentDir, true)) {
    http_response_code(500);
    echo 'Missing language content directory.';
    exit;
}

if (preg_match('#^/post/([a-z0-9]+(?:-[a-z0-9]+)*)/([^/]+\.(?:jpg|jpeg|png|webp|gif))$#i', $relativePath, $asset)) {
    $file = public_post_asset($contentDir, $asset[1], $asset[2]);
    if ($file !== null) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($file)); header('Cache-Control: no-cache, max-age=0, must-revalidate'); readfile($file); exit;
        }
    }
    http_response_code(404); exit;
}

ap_public_language_cookie($language);
ap_page_cache_try_serve($language, $relativePath);
$posts = load_posts($contentDir, $CACHE_DIR, $language);
$text = labels($language);

$special = [
    '/home' => $contentDir . '/home/index.md',
    '/about' => $contentDir . '/about/index.md',
    '/skills' => $contentDir . '/skills/index.md',
];

if ($relativePath === '/') {
    $raw = read_file_or_404($special['/home']);
    [$meta, $body] = parse_front_matter($raw);
    $introHtml = markdown_html($md, $body);
    $latest = array_slice($posts, 0, (int) ap_config('posts_per_page'));

    ob_start(); ?>
<section class="hero">
  <div class="prose prose-home">
    <?= $introHtml ?>
  </div>
</section>

<section class="articles-section">
  <h2><?= h($text['technical_articles']) ?></h2>
  <div class="grid cards">
    <?php foreach ($latest as $post): ?>
      <article class="card">
        <?php if (!empty($post['cover'])): ?>
          <a href="<?= h($post['url']) ?>" class="cover"><img src="<?= h(ap_image_url($post['cover'], $language, $post['id'])) ?>" alt="" loading="lazy"></a>
        <?php endif; ?>
        <h3><a href="<?= h($post['url']) ?>"><?= h($post['title']) ?></a></h3>
        <p class="muted"><?= h($post['date']) ?></p>
        <p><?= h($post['excerpt']) ?></p>
        <p class="tags">
          <?php foreach (($post['tags'] ?? []) as $tag): ?>
            <a href="<?= h(language_url($language, '/tag/' . $tag)) ?>" class="tag">#<?= h(tag_label($tag, $language)) ?></a>
          <?php endforeach; ?>
        </p>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php
    render_layout($language, $relativePath, $meta['title'] ?? $text['home'], ob_get_clean());
    exit;
}

if (isset($special[$relativePath])) {
    $raw = read_file_or_404($special[$relativePath]);
    [$meta, $body] = parse_front_matter($raw);
    render_layout(
        $language,
        $relativePath,
        $meta['title'] ?? trim($relativePath, '/'),
        '<div class="prose">' . markdown_html($md, $body) . '</div>'
    );
    exit;
}

if (preg_match('#^/post/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $relativePath, $match)) {
    $id = $match[1];
    if (!ap_id_valid($id)) { http_response_code(404); exit; }
    $date = substr($id, 0, 10);
    $postDir = $contentDir . '/post/' . $id;
    $raw = read_file_or_404($postDir . '/index.md');
    [$meta, $body] = parse_front_matter($raw);
    if (!ap_published($meta)) { http_response_code(404); render_layout($language, $relativePath, '404', '<h1>404</h1>'); exit; }
    $date = $meta['date'] ?? $date;
    $html = markdown_html($md, $body);

    $base = language_url($language, '/post/' . $id) . '/';
    $html = preg_replace_callback('/(<img[^>]+src=")([^":]+)("[^>]*>)/i', static function (array $parts) use ($base): string {
        $src = $parts[2];
        if (str_starts_with($src, '/') || preg_match('#^https?://#i', $src)) {
            return $parts[0];
        }
        return $parts[1] . h($base . $src) . $parts[3];
    }, $html);
    $html = preg_replace_callback('/(<a[^>]+href=")([^":#]+)("[^>]*>)/i', static function (array $parts) use ($base): string {
        $href = $parts[2];
        if (str_starts_with($href, '/') || preg_match('#^https?://#i', $href)) {
            return $parts[0];
        }
        return $parts[1] . h($base . $href) . $parts[3];
    }, $html);

    $tags = $meta['tags'] ?? [];
    ob_start(); ?>
<article class="post">
  <p class="muted"><?= h($date) ?></p>
  <h1><?= h($meta['title'] ?? $date) ?></h1>
  <?php if (!empty($meta['cover'])): ?>
    <figure class="post-hero"><img src="<?= h(ap_image_url($meta['cover'], $language, $id)) ?>" alt=""></figure>
  <?php endif; ?>
  <p class="tags">
    <?php foreach ($tags as $tag): ?>
      <a href="<?= h(language_url($language, '/tag/' . $tag)) ?>" class="tag">#<?= h(tag_label($tag, $language)) ?></a>
    <?php endforeach; ?>
  </p>
  <div class="prose"><?= $html ?></div>
</article>
<?php
    render_layout($language, $relativePath, $meta['title'] ?? $date, ob_get_clean());
    exit;
}

if (preg_match('#^/tag/([^/]+)$#u', $relativePath, $match)) {
    $tag = strtolower((string) preg_replace('/[^\p{L}\p{N}\-_]/u', '', rawurldecode($match[1])));
    $filtered = array_values(array_filter(
        $posts,
        static fn(array $post): bool => in_array($tag, $post['tags'] ?? [], true)
    ));

    $tagDisplay = tag_label($tag, $language);

    ob_start(); ?>
<h1>Tag: #<?= h($tagDisplay) ?></h1>
<p class="muted"><?= count($filtered) ?> <?= h($text['posts']) ?></p>
<div class="grid cards">
  <?php foreach ($filtered as $post): ?>
    <article class="card">
      <?php if (!empty($post['cover'])): ?>
        <a href="<?= h($post['url']) ?>" class="cover"><img src="<?= h(ap_image_url($post['cover'], $language, $post['id'])) ?>" alt="" loading="lazy"></a>
      <?php endif; ?>
      <h3><a href="<?= h($post['url']) ?>"><?= h($post['title']) ?></a></h3>
      <p class="muted"><?= h($post['date']) ?></p>
      <p><?= h($post['excerpt']) ?></p>
    </article>
  <?php endforeach; ?>
</div>
<?php
    render_layout($language, $relativePath, 'Tag #' . $tagDisplay, ob_get_clean());
    exit;
}

if ($relativePath === '/tags') {
    $counts = [];
    foreach ($posts as $post) {
        foreach (($post['tags'] ?? []) as $tag) {
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
    }
    arsort($counts);

    ob_start(); ?>
<h1>Tags</h1>
<p class="tags">
  <?php foreach ($counts as $tag => $count): ?>
    <a class="tag" href="<?= h(language_url($language, '/tag/' . $tag)) ?>">#<?= h(tag_label($tag, $language)) ?> (<?= (int) $count ?>)</a>
  <?php endforeach; ?>
</p>
<?php
    render_layout($language, $relativePath, 'Tags', ob_get_clean());
    exit;
}

if ($relativePath === '/archive') {
    ob_start(); ?>
<h1><?= h($text['technical_articles']) ?></h1>
<table>
  <thead><tr><th><?= h($language === 'it' ? 'Data' : 'Date') ?></th><th><?= h($language === 'it' ? 'Titolo' : 'Title') ?></th><th>Tags</th></tr></thead>
  <tbody>
  <?php foreach ($posts as $post): ?>
    <tr>
      <td class="muted"><?= h($post['date']) ?></td>
      <td><a href="<?= h($post['url']) ?>"><?= h($post['title']) ?></a></td>
      <td>
        <?php foreach (($post['tags'] ?? []) as $tag): ?>
          <a href="<?= h(language_url($language, '/tag/' . $tag)) ?>" class="tag">#<?= h(tag_label($tag, $language)) ?></a>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php
    render_layout($language, $relativePath, $text['technical_articles'], ob_get_clean());
    exit;
}

http_response_code(404);
render_layout(
    $language,
    $relativePath,
    '404',
    '<h1>404</h1><p>' . h($text['page_not_found']) . '</p>'
);
