<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
require dirname(__DIR__) . '/lib/admin.php';
function t(string $it, string $en): string { return $en; }
$count = 0;
function check(bool $ok, string $message): void { global $count; if (!$ok) throw new RuntimeException($message); $count++; echo "PASS $message\n"; }
$html = $md->text('<script>alert(1)</script>');
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'raw HTML escaped');
foreach (['javascript:alert', 'java&#x73;cript:alert', "java\tscript:alert", 'data:text/html,x', '//example.com/x'] as $url) {
    $html = $md->text('[click](' . $url . ')');
    check(!preg_match('/href="(?:javascript|data|\/\/)/i', $html), 'unsafe URL rejected');
}
$html = $md->text('![**alt**](https://example.com/a_**b**.png)');
check(str_contains($html, 'src="https://example.com/a_**b**.png"') && !str_contains($html, '<strong>'), 'formatting cannot modify image attributes');
check(str_contains($md->text('**Caffè & tè**'), '<strong>Caffè &amp; tè</strong>'), 'Unicode and ampersands preserved');
check(str_contains($md->text("| A | B |\n|---|---|\n| 1 | 2 |"), '<th>A</th>'), 'simple table rendered');
check(str_contains($md->text('`<img onerror=x>`'), '<code>&lt;img onerror=x&gt;</code>'), 'inline code escaped');
check(str_contains($md->text("1. First\n2. Second"), '<ol>'), 'ordered lists rendered');
check(!ap_id_valid('../outside') && !ap_id_valid('a/b') && ap_id_valid('2026-09-29-two-stories'), 'slug path validation');
check(!ap_published(['status'=>'draft']) && ap_published([]), 'drafts hidden, legacy articles published');
[$meta, $body] = parse_front_matter("---\ntitle: A story\nstatus: draft\ntags: [one, two]\n---\n\nHello");
check($meta['title'] === 'A story' && $meta['tags'] === ['one','two'] && trim($body) === 'Hello', 'front matter compatible');
[$quoted] = parse_front_matter("---\ntitle: " . json_encode('"A title" and \\ a path') . "\n---\n\nBody");
check($quoted['title'] === '"A title" and \\ a path', 'quoted metadata round trip');
$AP_CONFIG['base_path'] = '/moved';
check(language_url('it', '/post/one') === '/moved/it/post/one', 'language URLs portable');
check(ap_image_url('@media/' . str_repeat('a', 32) . '.png', 'it', 'one') === '/moved/media/' . str_repeat('a',32) . '.png', 'media URLs portable');
$html = ap_rewrite_html('<img src="/assets/pic.jpg"><a href="/moved/it">link</a>');
check(str_contains($html, 'src="/moved/assets/pic.jpg"') && !str_contains($html, '/moved/moved/'), 'root assets rebased once');
check(ap_validate_css('body { color: #123456; }') === 'body { color: #123456; }', 'local CSS accepted');
foreach (['@import "remote.css";', 'body{background:url(https://example.org/a)}', '@\\69mport "remote.css";'] as $css) {
    $blocked = false;
    try { ap_validate_css($css); } catch (RuntimeException $error) { $blocked = true; }
    check($blocked, 'external CSS resource blocked');
}
$AP_CONFIG['site_url'] = 'https://example.org/blog';
check(ap_canonical_url('it', '/post/story') === 'https://example.org/blog/it/post/story', 'configured canonical URL');
check(language_url('it') === '/moved/it', 'canonical URL does not change local navigation');
$AP_CONFIG['site_url'] = '';
check(ap_canonical_url('en') === SITE_ORIGIN . '/moved/en', 'automatic canonical URL restored');
echo "$count checks passed.\n";
