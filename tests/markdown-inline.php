<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/Parsedown.php';
$md = new Parsedown();
$count = 0;
function inline_check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException($label);
    $count++;
    echo "PASS $label\n";
}
$cases = [
    ['Use ``a`b`` safely.', '<p>Use <code>a`b</code> safely.</p>', 'double delimiter includes a literal backtick'],
    ['Use ```a``b`c``` safely.', '<p>Use <code>a``b`c</code> safely.</p>', 'triple delimiter includes shorter runs'],
    ['Use ``a```b`` safely.', '<p>Use <code>a```b</code> safely.</p>', 'longer run does not close a shorter delimiter'],
    ['Use ``unclosed` literal.', '<p>Use ``unclosed` literal.</p>', 'unmatched runs are literal rather than partial matches'],
    ['Use `a``b` safely.', '<p>Use <code>a``b</code> safely.</p>', 'single delimiter accepts a longer internal run'],
    ['Use `````` literal.', '<p>Use `````` literal.</p>', 'one contiguous run is not empty code'],
    ['A `` spaced `` B', '<p>A <code>spaced</code> B</p>', 'one padding space removed from both ends'],
    ['A ``  spaced  `` B', '<p>A <code> spaced </code> B</p>', 'additional padding preserved'],
    ['A ``   `` B', '<p>A <code>   </code> B</p>', 'all-space code preserved'],
    ['A `` left`` B', '<p>A <code> left</code> B</p>', 'one-sided leading space preserved'],
    ["A ``\t tab \t`` B", "<p>A <code>\t tab \t</code> B</p>", 'tabs are not ASCII-space padding'],
    ['A ``**not bold** ~~not deleted~~ [not a link](/x)`` B', '<p>A <code>**not bold** ~~not deleted~~ [not a link](/x)</code> B</p>', 'code is not reinterpreted as inline markup'],
    ['**before** ``a`b`` *after*', '<p><strong>before</strong> <code>a`b</code> <em>after</em></p>', 'surrounding formatting still works'],
    ['Use ``caffè ` tè`` & `x`.', '<p>Use <code>caffè ` tè</code> &amp; <code>x</code>.</p>', 'unicode ampersands and legacy single spans'],
    ['# Title ``a`b``', '<h1>Title <code>a`b</code></h1>', 'headings use matching code spans'],
    ['- Item ``a`b``', "<ul>\n<li>Item <code>a`b</code></li>\n</ul>", 'list items use matching code spans'],
    ['> Quote ``a`b``', '<blockquote>Quote <code>a`b</code></blockquote>', 'quotes use matching code spans'],
    ['[Read ``a`b``](/guide)', '<p><a href="/guide" rel="noopener noreferrer">Read <code>a`b</code></a></p>', 'code tokens survive link label rendering'],
    ['[Read `x`](/guide)', '<p><a href="/guide" rel="noopener noreferrer">Read <code>x</code></a></p>', 'legacy code in link labels is preserved'],
    ['[``<img src=x onerror=alert(1)>``](/guide)', '<p><a href="/guide" rel="noopener noreferrer"><code>&lt;img src=x onerror=alert(1)&gt;</code></a></p>', 'code in link label cannot introduce executable HTML'],
    ['A ``<script>`x`</script> & "quoted"`` B', '<p>A <code>&lt;script&gt;`x`&lt;/script&gt; &amp; &quot;quoted&quot;</code> B</p>', 'raw HTML and attributes escaped inside code'],
    ['A ``&lt;script&gt;`` B', '<p>A <code>&amp;lt;script&amp;gt;</code> B</p>', 'code entities remain literal'],
    ["A \x1A0\x1A ``safe`` B", '<p>A 0 <code>safe</code> B</p>', 'external token sentinels cannot inject generated markup'],
];
foreach ($cases as [$input, $expected, $label]) inline_check($md->text($input) === $expected, $label);
$html = $md->text("| Code | Plain |\n|---|---|\n| ``a`b`` | ok |");
inline_check(str_contains($html, '<td><code>a`b</code></td>'), 'table cells use matching spans');
$html = $md->text("```\n``a`b`` <script>\n```");
inline_check(str_contains($html, '<pre><code>``a`b`` &lt;script&gt;</code></pre>') && substr_count($html, '<code>') === 1, 'fenced code bypasses inline rendering');
$html = $md->text('[x](javascript:alert) ``[x](javascript:alert)``');
inline_check(!str_contains($html, 'href="javascript:') && str_contains($html, '<code>[x](javascript:alert)</code>'), 'unsafe link remains rejected outside literal code');
// Many paired and unmatched delimiters test the single-pass lookup design.
$paired = 'Start ' . str_repeat('``a`b`` ', 30000);
$start = hrtime(true);
$html = $md->text($paired);
inline_check(substr_count($html, '<code>a`b</code>') === 30000, 'large paired input has no dropped or reparsed spans');
$html = $md->text(str_repeat('[Read ``a`b``](/guide) ', 5000));
inline_check(substr_count($html, '<code>a`b</code></a>') === 5000 && !str_contains($html, "\x1A"), 'many code-bearing links restore only their own placeholders');
$unique = 'Start ';
for ($i = 1; $i <= 1100; $i++) $unique .= str_repeat('`', $i) . 'x';
$html = $md->text($unique);
inline_check(!str_contains($html, '<code>') && str_contains($html, $unique), 'many distinct unmatched lengths stay literal');
$elapsed = (hrtime(true) - $start) / 1e9;
echo 'Stress time (informational): ' . number_format($elapsed, 3) . "s\n";
$blocked = false;
try { $md->text(str_repeat('x', Parsedown::MAX_BYTES + 1)); } catch (RuntimeException $error) { $blocked = true; }
inline_check($blocked, 'existing input byte limit preserved');
// Public HTML and authenticated editor preview use the same escaping wrapper.
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
inline_check(markdown_html($md, 'Snippet ``[mail](mailto:reader@example.org) <script>``.') === '<p>Snippet <code>[mail](mailto:reader@example.org) &lt;script&gt;</code>.</p>', 'public/editor wrapper leaves code emails and HTML literal');
echo "$count inline code checks passed.\n";
