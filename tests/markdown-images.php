<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Synthetic content only; no remote links are fetched by diagnostics.
$root = sys_get_temp_dir() . '/ap-markdown-images-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
function image_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
$cases = [
    ['![Read `x`](/qa-image.png)', '<p><img src="/qa-image.png" alt="Read x" loading="lazy"></p>', 1, 'single code in image alt'],
    ['![Read ``a`b``](/qa-image.png)', '<p><img src="/qa-image.png" alt="Read a`b" loading="lazy"></p>', 1, 'matching double code in image alt'],
    ['![`left` and ``right``](/qa-image.png)', '<p><img src="/qa-image.png" alt="left and right" loading="lazy"></p>', 1, 'multiple code tokens'],
    ['![`` spaced `` / `   `](/qa-image.png)', '<p><img src="/qa-image.png" alt="spaced /    " loading="lazy"></p>', 1, 'code padding and all-space alt'],
    ['![Read ``x`](/qa-image.png)', '<p><img src="/qa-image.png" alt="Read ``x`" loading="lazy"></p>', 1, 'unmatched runs stay literal in alt'],
    ['![**plain** & "quoted"](/qa-image.png)', '<p><img src="/qa-image.png" alt="**plain** &amp; &quot;quoted&quot;" loading="lazy"></p>', 1, 'existing plain alt escaping unchanged'],
    ['![`<img src="/qa-fake.png" onerror="x"> & \'quoted\'`](/qa-image.png)', '<p><img src="/qa-image.png" alt="&lt;img src=&quot;/qa-fake.png&quot; onerror=&quot;x&quot;&gt; &amp; &#039;quoted&#039;" loading="lazy"></p>', 1, 'code alt cannot introduce attributes or elements'],
    ['![`&lt;script&gt;`](/qa-image.png)', '<p><img src="/qa-image.png" alt="&amp;lt;script&amp;gt;" loading="lazy"></p>', 1, 'code entities remain literal text'],
    ['![`[hidden](/qa-hidden)`](/qa-image.png)', '<p><img src="/qa-image.png" alt="[hidden](/qa-hidden)" loading="lazy"></p>', 1, 'link syntax in code alt stays text'],
    ['![`x`](/qa-one.png) ![``y``](/qa-two.png)', '<p><img src="/qa-one.png" alt="x" loading="lazy"> <img src="/qa-two.png" alt="y" loading="lazy"></p>', 2, 'independent images use their own tokens'],
    ['`outer` ![``inner``](/qa-image.png) [Read `link`](/qa-link)', '<p><code>outer</code> <img src="/qa-image.png" alt="inner" loading="lazy"> <a href="/qa-link" rel="noopener noreferrer">Read <code>link</code></a></p>', 2, 'image tokens do not disturb adjacent code or link labels'],
    ['A `![not an image](/qa-fake.png)` Z', '<p>A <code>![not an image](/qa-fake.png)</code> Z</p>', 0, 'image inside inline code stays literal'],
    ["```\n![`hidden`](/qa-fake.png)\n```", '<pre><code>![`hidden`](/qa-fake.png)</code></pre>', 0, 'image inside backtick fence stays literal'],
    ["~~~\n![``hidden``](/qa-fake.png)\n~~~\n![`shown`](/qa-image.png)", '<pre><code>![``hidden``](/qa-fake.png)</code></pre>' . "\n" . '<p><img src="/qa-image.png" alt="shown" loading="lazy"></p>', 1, 'tilde fence and following image stay separate'],
    ["![\x1A0\x1A `safe`](/qa-image.png)", '<p><img src="/qa-image.png" alt="0 safe" loading="lazy"></p>', 1, 'caller sentinels cannot impersonate code tokens'],
];
try {
    foreach (['content', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir);
    mkdir($root . '/content/it/home', 0755, true);
    $renderer = new Parsedown();
    foreach ($cases as [$body, $expectedHtml, $expectedLinks, $label]) {
        $html = $renderer->text($body);
        image_check($html === $expectedHtml, "$label exact HTML; actual=" . json_encode($html));
        image_check(!str_contains($html, "\x1A"), "$label no leaked placeholder");
        file_put_contents($root . '/content/it/home/index.md', $body);
        $report = ap_diagnostics(['root' => $root]);
        image_check($report['complete'], "$label diagnostics complete");
        image_check($report['summary']['links_checked'] === $expectedLinks, "$label diagnostics agree with rendered targets");
        image_check($report['summary']['missing_links'] === $expectedLinks, "$label diagnostics missing targets");
    }
    $html = $renderer->text(str_repeat('![`x`](/qa-image.png) ', 5000));
    image_check(substr_count($html, 'alt="x"') === 5000 && !str_contains($html, "\x1A"), 'many code-bearing images restore their own plain text');
    echo "$checks image Markdown checks passed.\n";
} finally {
    if (is_file($root . '/content/it/home/index.md')) unlink($root . '/content/it/home/index.md');
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) rmdir($root . '/' . $dir);
    rmdir($root);
}
