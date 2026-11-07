<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$table = "| A | B |\n|---|---|\n| 1 | 2 |";
$head = "<div class=\"overflow-auto\"><table><thead><tr>\n<th>A</th>\n<th>B</th>\n</tr></thead><tbody>\n";
$row = "<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n";
$end = '</tbody></table></div>';
$cases = [
    ['# Title | extra', '<h1>Title | extra</h1>'],
    ['###### Title | extra', '<h6>Title | extra</h6>'],
    ["#\tTitle | extra", '<h1>Title | extra</h1>'],
    ['- One | two', "<ul>\n<li>One | two</li>\n</ul>"],
    ['* One | two', "<ul>\n<li>One | two</li>\n</ul>"],
    ['+ One | two', "<ul>\n<li>One | two</li>\n</ul>"],
    ['3. One | two', "<ol start=\"3\">\n<li>One | two</li>\n</ol>"],
    ['0. One | two', "<ol start=\"0\">\n<li>One | two</li>\n</ol>"],
    ['    - One | two', "<ul>\n<li>One | two</li>\n</ul>"],
    ["\t3. One | two", "<ol start=\"3\">\n<li>One | two</li>\n</ol>"],
    ['> One | two', '<blockquote>One | two</blockquote>'],
    ['>One | two', '<blockquote>One | two</blockquote>'],
    ['    > One | two', '<blockquote>One | two</blockquote>'],
    ['# <script> | `x`', '<h1>&lt;script&gt; | <code>x</code></h1>'],
];
$checks = 0;
function table_block_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
$renderer = new Parsedown();
foreach ($cases as [$block, $expected]) {
    // The same block grammar applies alone, after body rows, and after headers.
    table_block_check($renderer->text($block) === $expected, 'standalone ' . json_encode($block));
    foreach ([true, false] as $body) {
        $source = $body ? $table : "| A | B |\n|---|---|";
        $expectedTable = $head . ($body ? $row : '') . $end;
        $actual = $renderer->text($source . "\n" . $block . "\n\nAfter");
        table_block_check($actual === $expectedTable . "\n" . $expected . "\n<p>After</p>", 'adjacent ' . json_encode($block) . '; actual=' . json_encode($actual));
    }
}
// Cell content and unsupported block lookalikes must not end a table.
foreach (['| # Title | extra |', '| - One | two |', '| > One | two |',
          '####### Title | extra', '#Title | extra', ' # Title | extra',
          '-One | two', '3.One | two', '3) One | two', 'ordinary | row'] as $line) {
    $actual = $renderer->text($table . "\n" . $line);
    table_block_check(substr_count($actual, '<tr>') === 3 && str_ends_with($actual, $end), 'row retained ' . json_encode($line));
}
$mixed = "# Title | extra\n- One | two\n3. Three | four\n> Five | six";
$expectedMixed = "<h1>Title | extra</h1>\n<ul>\n<li>One | two</li>\n</ul>\n<ol start=\"3\">\n<li>Three | four</li>\n</ol>\n<blockquote>Five | six</blockquote>";
table_block_check($renderer->text($table . "\n" . $mixed) === $head . $row . $end . "\n" . $expectedMixed, 'mixed adjacent blocks at EOF');
table_block_check($renderer->text(str_replace("\n", "\r\n", $table . "\n" . $mixed)) === $head . $row . $end . "\n" . $expectedMixed, 'CRLF block boundaries');
table_block_check($renderer->text("~~~\n" . $table . "\n" . $mixed . "\n~~~") === '<pre><code>' . htmlspecialchars($table . "\n" . $mixed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>', 'fenced examples remain literal');
$many = $renderer->text(str_repeat($table . "\n" . $mixed . "\n", 500));
table_block_check(substr_count($many, '<table>') === 500 && substr_count($many, '<h1>') === 500 && substr_count($many, '<blockquote>') === 500, '500 alternating tables and blocks');

$root = sys_get_temp_dir() . '/ap-table-blocks-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    foreach (['content/it/home', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir, 0700, true);
    $link = '<a href="/qa-target" rel="noopener noreferrer">target</a>';
    define('AP_LIBRARY', true);
    require_once dirname(__DIR__) . '/index.php';
    foreach ([['# ', '<h1>', '</h1>'], ['- ', "<ul>\n<li>", "</li>\n</ul>"], ['> ', '<blockquote>', '</blockquote>']] as [$prefix, $open, $close]) {
        // Previously the third cell (and thus this real link) was discarded.
        $source = $table . "\n" . $prefix . 'One | two | [target](/qa-target)';
        $expected = $head . $row . $end . "\n" . $open . 'One | two | ' . $link . $close;
        file_put_contents($root . '/content/it/home/index.md', $source);
        $report = ap_diagnostics(['root' => $root]);
        table_block_check($report['complete'] && $report['summary']['links_checked'] === 1 && $report['summary']['missing_links'] === 1, 'diagnostics retains block target ' . $prefix);
        table_block_check(markdown_html($renderer, $source) === $expected, 'public/editor exact block HTML ' . $prefix);
    }
} finally {
    if (is_file($root . '/content/it/home/index.md')) unlink($root . '/content/it/home/index.md');
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) rmdir($root . '/' . $dir);
    rmdir($root);
}
echo "$checks table/block boundary checks passed.\n";
