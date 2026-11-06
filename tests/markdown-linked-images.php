<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$root = sys_get_temp_dir() . '/ap-linked-images-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
function linked_image_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
// Expectations are literal HTML, not a second Markdown parser.
$image = '<img src="/qa-image.png" alt="alt" loading="lazy">';
$linked = '<a href="/qa-link" rel="noopener noreferrer">' . $image . '</a>';
$cases = [
    ['[![alt](/qa-image.png)](/qa-link)', '<p>' . $linked . '</p>', 2, 'exact linked-image reproducer'],
    ['[![Read ``a`b``](/qa-image.png)](/qa-link)', '<p><a href="/qa-link" rel="noopener noreferrer"><img src="/qa-image.png" alt="Read a`b" loading="lazy"></a></p>', 2, 'code alt is plain text inside linked image'],
    ['[![`<img src="/qa-fake" onerror="x"> & \'q\'`](/qa-image.png)](/qa-link)', '<p><a href="/qa-link" rel="noopener noreferrer"><img src="/qa-image.png" alt="&lt;img src=&quot;/qa-fake&quot; onerror=&quot;x&quot;&gt; &amp; &#039;q&#039;" loading="lazy"></a></p>', 2, 'code alt escapes quotes HTML and ampersand once'],
    ['[!["quoted" & &lt;x&gt;](/qa-image.png)](/qa-link)', '<p><a href="/qa-link" rel="noopener noreferrer"><img src="/qa-image.png" alt="&quot;quoted&quot; &amp; &amp;lt;x&amp;gt;" loading="lazy"></a></p>', 2, 'plain alt quoting and entities unchanged'],
    ['[![alt](/qa-image.png "image title")](/qa-link "outer title")', '<p>' . $linked . '</p>', 2, 'existing ignored titles accepted for image and outer link'],
    ['[![alt](/qa-image.png)](javascript:alert)', '<p><a href="#" rel="noopener noreferrer">' . $image . '</a></p>', 1, 'unsafe outer URL rejected'],
    ['[![alt](/qa-image.png)](java&#x73;cript:alert)', '<p><a href="#" rel="noopener noreferrer">' . $image . '</a></p>', 1, 'entity-obfuscated outer URL rejected'],
    ['[![alt](//example.invalid/a.png)](/qa-link)', '<p><a href="/qa-link" rel="noopener noreferrer"><img src="#" alt="alt" loading="lazy"></a></p>', 1, 'unsafe image URL rejected'],
    ['[![alt](data:text/html,x)](//example.invalid/link)', '<p><a href="#" rel="noopener noreferrer"><img src="#" alt="alt" loading="lazy"></a></p>', 0, 'both unsafe URLs rejected'],
    ['[![alt](/qa-image.png)](/qa-link?a=1&b=2)', '<p><a href="/qa-link?a=1&amp;b=2" rel="noopener noreferrer">' . $image . '</a></p>', 2, 'outer URL attribute escaping'],
    ['`before` [![alt](/qa-image.png)](/qa-link) [Read `x`](/qa-other) ``after``', '<p><code>before</code> ' . $linked . ' <a href="/qa-other" rel="noopener noreferrer">Read <code>x</code></a> <code>after</code></p>', 3, 'adjacent code and normal link tokens preserved'],
    ['[![`[hidden](/qa-hidden)`](/qa-image.png)](/qa-link)', '<p><a href="/qa-link" rel="noopener noreferrer"><img src="/qa-image.png" alt="[hidden](/qa-hidden)" loading="lazy"></a></p>', 2, 'code alt cannot add a third target'],
    ['A `[![alt](/qa-image.png)](/qa-link)` Z', '<p>A <code>[![alt](/qa-image.png)](/qa-link)</code> Z</p>', 0, 'inline code is immutable'],
    ["```\n[![alt](/qa-image.png)](/qa-link)\n```", '<pre><code>[![alt](/qa-image.png)](/qa-link)</code></pre>', 0, 'backtick fence is immutable'],
    ["~~~\n[![alt](/qa-image.png)](/qa-link)\n~~~\n[![alt](/qa-image.png)](/qa-link)", '<pre><code>[![alt](/qa-image.png)](/qa-link)</code></pre>' . "\n<p>" . $linked . '</p>', 2, 'tilde fence and following linked image'],
    ['[![alt](/qa-image.png)](/qa-link', '<p>[' . $image . '](/qa-link</p>', 1, 'unclosed outer URL stays literal'],
    ['[![alt](/qa-image.png)](/qa-link(bad))', '<p>[' . $image . '](/qa-link(bad))</p>', 1, 'unsupported URL parentheses stay literal'],
    ['[text ![alt](/qa-image.png)](/qa-link)', '<p>[text ' . $image . '](/qa-link)</p>', 1, 'mixed image and text label deliberately unsupported'],
    ['[[text](/qa-inner)](/qa-link)', '<p>[<a href="/qa-inner" rel="noopener noreferrer">text</a>](/qa-link)</p>', 1, 'anchor token cannot become a nested anchor'],
    ['[[![alt](/qa-image.png)](/qa-link)](/qa-outer)', '<p>[' . $linked . '](/qa-outer)</p>', 2, 'only one linked-image layer consumed'],
    ['![![alt](/qa-image.png)](/qa-link)', '<p>![' . $image . '](/qa-link)</p>', 1, 'outer image syntax is not reinterpreted as a link'],
    ['[![alt](/qa-image.png) ![alt](/qa-two.png)](/qa-link)', '<p>[' . $image . ' <img src="/qa-two.png" alt="alt" loading="lazy">](/qa-link)</p>', 2, 'multiple-image label deliberately unsupported'],
    ["[\x1A0\x1A](/qa-link) ![alt](/qa-image.png)", '<p><a href="/qa-link" rel="noopener noreferrer">0</a> ' . $image . '</p>', 2, 'caller sentinels cannot forge an image token'],
];
try {
    foreach (['content', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir);
    mkdir($root . '/content/it/home', 0755, true);
    $renderer = new Parsedown();
    foreach ($cases as [$body, $expected, $targets, $label]) {
        $html = $renderer->text($body);
        linked_image_check($html === $expected, "$label exact HTML; actual=" . json_encode($html));
        linked_image_check(!str_contains($html, "\x1A"), "$label no token leak");
        linked_image_check(!preg_match('~<a\b[^>]*>(?:(?!</a>).)*<a\b~s', $html), "$label no nested anchors");
        file_put_contents($root . '/content/it/home/index.md', $body);
        $report = ap_diagnostics(['root' => $root]);
        linked_image_check($report['complete'], "$label diagnostics complete");
        linked_image_check($report['summary']['links_checked'] === $targets, "$label checked targets agree");
        linked_image_check($report['summary']['missing_links'] === $targets, "$label missing targets agree");
    }
    $many = '';
    for ($i = 0; $i < 256; $i++) $many .= "[![`alt$i`](/qa-image-$i.png)](/qa-link-$i) ";
    $html = $renderer->text($many);
    linked_image_check(substr_count($html, '<a href=') === 256 && substr_count($html, '<img src=') === 256, '256 distinct linked image tokens');
    for ($i = 0; $i < 256; $i++) {
        linked_image_check(str_contains($html, '<a href="/qa-link-' . $i . '" rel="noopener noreferrer"><img src="/qa-image-' . $i . '.png" alt="alt' . $i . '" loading="lazy"></a>'), "distinct token $i correct binding");
    }
    $html = $renderer->text(str_repeat('[', 50000) . '[![alt](/qa-image.png)](/qa-link)');
    linked_image_check($html === '<p>' . str_repeat('[', 50000) . $linked . '</p>', 'large malformed bracket prefix remains bounded and literal');
    $html = $renderer->text(str_repeat('[![alt](/qa-image.png)](/qa-link) ', 1500));
    linked_image_check(substr_count($html, $linked) === 1500 && !str_contains($html, "\x1A"), '1500 linked images have no dropped or reparsed tokens');
    echo "$checks linked-image Markdown checks passed.\n";
} finally {
    if (is_file($root . '/content/it/home/index.md')) unlink($root . '/content/it/home/index.md');
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) rmdir($root . '/' . $dir);
    rmdir($root);
}
