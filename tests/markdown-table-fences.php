<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$root = sys_get_temp_dir() . '/ap-table-fence-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
function table_fence_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
$tableSource = "| A | B |\n|---|---|\n| 1 | 2 |";
$tableHtml = "<div class=\"overflow-auto\"><table><thead><tr>\n<th>A</th>\n<th>B</th>\n</tr></thead><tbody>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody></table></div>";
$literal = '[hidden](/qa-hidden)|![hidden](/qa-image.png)';
$cases = [];
foreach (['```', '~~~~'] as $fence) {
    foreach (['', '   '] as $indent) {
        $body = $tableSource . "\n" . $indent . $fence . "lang|extra\n" . $indent . $literal . "\n" . $fence . "\nAfter";
        $expected = $tableHtml . "\n<pre><code>" . $literal . "</code></pre>\n<p>After</p>";
        $cases[] = [$body, $expected, 0, 'adjacent ' . strlen($indent) . '-space ' . $fence];
    }
}
$cases[] = [$tableSource . "\n~~~lang|extra\n" . $literal, $tableHtml . "\n<pre><code>" . $literal . '</code></pre>', 0, 'unclosed adjacent fence'];
$cases[] = [$tableSource . "\n~~~lang|`extra\n<script>|&\n~~~\n[shown](/qa-shown)", $tableHtml . "\n<pre><code>&lt;script&gt;|&amp;</code></pre>\n<p><a href=\"/qa-shown\" rel=\"noopener noreferrer\">shown</a></p>", 1, 'tilde info accepts backtick and code HTML stays escaped'];
$cases[] = [$tableSource . "\n```lang|extra\n" . $literal . "\n``|short\n```\nAfter", $tableHtml . "\n<pre><code>" . $literal . "\n``|short</code></pre>\n<p>After</p>", 0, 'short near closer stays literal'];
$cases[] = ["| A | B |\n|---|---|\n~~~lang|extra\n" . $literal . "\n~~~", "<div class=\"overflow-auto\"><table><thead><tr>\n<th>A</th>\n<th>B</th>\n</tr></thead><tbody>\n</tbody></table></div>\n<pre><code>" . $literal . '</code></pre>', 0, 'fence after header without body rows'];
$cases[] = [str_replace("\n", "\r\n", $tableSource . "\n~~~|info\n" . $literal . "\n~~~\nAfter"), $tableHtml . "\n<pre><code>" . $literal . "</code></pre>\n<p>After</p>", 0, 'CRLF boundaries'];
try {
    foreach (['content', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir);
    mkdir($root . '/content/it/home', 0755, true);
    $renderer = new Parsedown();
    foreach ($cases as [$body, $expected, $targets, $label]) {
        $html = $renderer->text($body);
        table_fence_check($html === $expected, "$label exact HTML; actual=" . json_encode($html));
        table_fence_check(substr_count($html, '<pre><code>') === 1, "$label one code block");
        file_put_contents($root . '/content/it/home/index.md', $body);
        $report = ap_diagnostics(['root' => $root]);
        table_fence_check($report['complete'], "$label diagnostics complete");
        table_fence_check($report['summary']['links_checked'] === $targets, "$label only actual targets checked");
        table_fence_check($report['summary']['missing_links'] === $targets, "$label only actual targets missing");
    }
    // Invalid/indented candidates remain table rows, not block openings.
    foreach (['```bad`|info', '    ~~~|info', "\t~~~|info", '~~|short'] as $near) {
        $html = $renderer->text($tableSource . "\n" . $near);
        table_fence_check(!str_contains($html, '<pre>') && substr_count($html, '<tr>') === 3, 'non-opener remains row: ' . json_encode($near));
    }
    $html = $renderer->text($tableSource . "\n| [shown](/qa-shown) | `x` |");
    table_fence_check(str_contains($html, '<td><a href="/qa-shown"') && str_contains($html, '<td><code>x</code></td>'), 'ordinary pipe rows still use inline rendering');
    $many = str_repeat($tableSource . "\n~~~|info\n" . $literal . "\n~~~\n", 500);
    $html = $renderer->text($many);
    table_fence_check(substr_count($html, '<table>') === 500 && substr_count($html, '<pre><code>') === 500 && !str_contains($html, '<a '), '500 alternating tables/fences retain boundaries');
    define('AP_LIBRARY', true);
    require dirname(__DIR__) . '/index.php';
    $body = $tableSource . "\n~~~lang|extra\n[mail](mailto:reader@example.org)|<script>\n~~~\nAfter";
    $html = markdown_html($renderer, $body);
    table_fence_check(str_contains($html, '<code>[mail](mailto:reader@example.org)|&lt;script&gt;</code>') && !str_contains($html, 'js-protected-email') && str_ends_with($html, '<p>After</p>'), 'public/editor wrapper leaves mail and HTML examples literal');
    echo "$checks table/fence boundary checks passed.\n";
} finally {
    if (is_file($root . '/content/it/home/index.md')) unlink($root . '/content/it/home/index.md');
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) rmdir($root . '/' . $dir);
    rmdir($root);
}
