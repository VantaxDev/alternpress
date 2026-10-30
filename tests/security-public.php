<?php
declare(strict_types=1);
// Isolated fixtures only. Run: php tests/security-public.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/alternpress-public-security-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
foreach (['lib', 'cache', 'storage', 'media', 'content/it/home', 'content/en/home', 'content/it/post/published', 'content/it/post/draft'] as $directory) mkdir($fixture . '/' . $directory, 0700, true);
foreach (['index.php', 'config.php', 'lib/bootstrap.php', 'lib/languages.php', 'lib/Parsedown.php', 'lib/discovery.php', 'lib/page-cache.php'] as $file) copy($source . '/' . $file, $fixture . '/' . $file);
define('AP_LIBRARY', true);
require $fixture . '/index.php';
$checks = 0;
function public_expect(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $message); $checks++; echo 'PASS ' . $message . "\n"; }
function public_reject(callable $action, string $message): void { try { $action(); } catch (RuntimeException) { public_expect(true, $message); return; } public_expect(false, $message); }
try {
    file_put_contents(AP_ROOT . '/content/it/post/published/index.md', "---\ntitle: Public title\nstatus: published\n---\n\nVisible.");
    file_put_contents(AP_ROOT . '/content/it/post/draft/index.md', "---\ntitle: Secret title\nstatus: draft\n---\n\nPrivate draft.");
    file_put_contents(AP_ROOT . '/content/it/home/index.md', "---\nstatus: draft\n---\n\nPrivate homepage.");
    file_put_contents(AP_ROOT . '/content/en/home/index.md', "# Published homepage");
    file_put_contents(AP_ROOT . '/content/it/post/published/photo.png', 'image fixture');
    file_put_contents(AP_ROOT . '/content/it/post/draft/photo.png', 'draft image');
    file_put_contents(AP_ROOT . '/storage/private.md', 'Private outside content');
    public_expect(public_document_published(AP_ROOT . '/content/it/post/published/index.md'), 'published document is available');
    public_expect(!public_document_published(AP_ROOT . '/content/it/post/draft/index.md'), 'draft article is unavailable');
    public_expect(!route_content_exists(AP_ROOT . '/content', 'it', '/') && route_content_exists(AP_ROOT . '/content', 'en', '/'), 'draft pages excluded from routes and language alternatives');
    public_expect(public_post_asset(AP_ROOT . '/content/it', 'draft', 'photo.png') === null, 'draft article images unavailable');
    public_expect(public_post_asset(AP_ROOT . '/content/it', 'published', 'photo.png') !== null, 'published local image available');
    foreach (['%2e%2e%2fphoto.png', '..%5cphoto.png', 'photo%00.png', '.private.png', '%2fphoto.png'] as $filename) public_expect(public_post_asset(AP_ROOT . '/content/it', 'published', $filename) === null, 'encoded or hidden asset path rejected');
    public_expect(count(build_posts_index(AP_ROOT . '/content/it', 'it')) === 1, 'public index contains no draft metadata');
    file_put_contents(AP_ROOT . '/content/it/post/published/oversized.md', str_repeat('x', Parsedown::MAX_BYTES + 1));
    public_expect(public_markdown(AP_ROOT . '/content/it/post/published/oversized.md') === null, 'oversized file is not read publicly');
    file_put_contents(AP_ROOT . '/content/it/post/published/binary.md', "bad\0content");
    public_expect(public_markdown(AP_ROOT . '/content/it/post/published/binary.md') === null, 'binary Markdown rejected');
    public_expect(!public_cached_posts_valid([['id' => 'published', 'date' => '', 'title' => '', 'excerpt' => '', 'tags' => [], 'url' => 'javascript:alert(1)']], 'it'), 'cache cannot introduce arbitrary navigation URL');
    if (function_exists('symlink') && @symlink(AP_ROOT . '/storage/private.md', AP_ROOT . '/content/it/post/published/linked.md')) {
        public_expect(public_markdown(AP_ROOT . '/content/it/post/published/linked.md') === null, 'symlinked Markdown rejected');
        symlink(AP_ROOT . '/storage', AP_ROOT . '/content/it/post/linked');
        public_expect(!in_array(AP_ROOT . '/content/it/post/linked', list_post_dirs(AP_ROOT . '/content/it/post'), true), 'symlinked article folder excluded');
        symlink(AP_ROOT . '/content/it/post/published/photo.png', AP_ROOT . '/content/it/post/published/linked.png');
        public_expect(public_post_asset(AP_ROOT . '/content/it', 'published', 'linked.png') === null, 'symlinked image rejected even within same folder');
        symlink(AP_ROOT . '/storage/private.md', AP_ROOT . '/cache/posts-it.json');
        public_expect(count(load_posts(AP_ROOT . '/content/it', AP_ROOT . '/cache', 'it')) === 1 && file_get_contents(AP_ROOT . '/storage/private.md') === 'Private outside content', 'unusable cache neither blocks rendering nor overwrites link target');
    }
    $html = markdown_html($md, '[Email](mailto:writer@example.org) `ALTERNPRESSMAILTOKEN0X` ![ALTERNPRESSMAILTOKEN0X](photo.png)');
    public_expect(str_contains($html, '<code>ALTERNPRESSMAILTOKEN0X</code>') && str_contains($html, 'alt="ALTERNPRESSMAILTOKEN0X"'), 'email placeholder text cannot become HTML in attributes or code');
    public_expect(str_contains($html, 'class="js-protected-email"') && !str_contains($html, 'href="mailto:'), 'ordinary email link remains protected');
    $html = markdown_html($md, '`[Email](mailto:writer@example.org)`');
    public_expect(str_contains($html, '<code>[Email](mailto:writer@example.org)</code>') && !str_contains($html, '<a '), 'email example in code remains literal');
    $html = protect_emails_in_html('<img alt="@@ALTERNPRESS_EMAIL_0@@"> writer@example.org');
    public_expect(str_contains($html, 'alt="@@ALTERNPRESS_EMAIL_0@@"'), 'email postprocessing cannot replace text inside attributes');
    foreach (['javascript:alert(1)', 'java&#x73;cript:alert', 'data:text/html,x', '//example.org/a', 'https:\\example.org', "java\tscript:x"] as $url) {
        $html = markdown_html($md, '[click](' . $url . ')');
        public_expect(!preg_match('/href="(?:javascript:|data:|\/\/|https:\\\\)/i', $html), 'unsafe Markdown scheme rejected');
    }
    public_expect(!str_contains(markdown_html($md, '<img src=x onerror=alert(1)>'), '<img'), 'raw active HTML escaped');
    public_reject(fn() => $md->text(str_repeat('x', Parsedown::MAX_BYTES + 1)), 'renderer enforces maximum input size');
    public_reject(fn() => $md->text(str_repeat("x\n", 40001)), 'renderer bounds line count');
    public_expect($md->text(str_repeat('[a](', 10000)) !== '', 'malformed repeated link openers stay renderable');
    $_COOKIE['alternpress_lang'] = ['it'];
    public_expect(in_array(preferred_language(), SUPPORTED_LANGUAGES, true), 'array language cookie cannot crash public routing');
    $_SERVER['REQUEST_URI'] = '/it/post/a%0d%0aHeader:injected';
    public_expect(!str_contains(route_path(), "\r") && !str_contains(route_path(), "\n"), 'encoded routing input cannot introduce raw header delimiters');
    echo "$checks public security checks passed.\n";
} finally {
    if (!preg_match('~/alternpress-public-security-[a-f0-9]{12}\z~D', $fixture) || is_link($fixture)) throw new RuntimeException('Invalid test cleanup path.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
}
