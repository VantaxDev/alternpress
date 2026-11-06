<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Synthetic local content only. Compare checked links with actual rendering,
// never with a second approximation of Markdown delimiter semantics.
$root = sys_get_temp_dir() . '/ap-diagnostic-markdown-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
function markdown_diagnostic_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
$cases = [
    ['A `[missing](/qa-missing)`` Z', 1, 'unmatched 1/2 exact QA reproducer'],
    ['A ``[missing](/qa-missing)` Z', 1, 'unmatched 2/1'],
    ['A `[missing](/qa-missing)` Z', 0, 'equal single runs hide literal link'],
    ['A ``[missing](/qa-missing)`` Z', 0, 'equal double runs hide literal link'],
    ['A ```[missing](/qa-missing)``` Z', 0, 'equal triple inline runs'],
    ['A ``x`[missing](/qa-missing)`` Z', 0, 'shorter internal run stays in code'],
    ['A `x``[missing](/qa-missing)` Z', 0, 'longer internal run stays in code'],
    ['[left](/qa-left) ``[hidden](/qa-hidden)`` [right](/qa-right)', 2, 'visible links on both code edges'],
    ['[left](/qa-left) `[visible](/qa-visible)`` [right](/qa-right)', 3, 'visible links around unequal runs'],
    ['[left](/qa-left)`ignored`[right](/qa-right)', 2, 'links directly adjacent to a span'],
    ['[not`code`](/qa-label)', 1, 'code inside a link label does not hide its target'],
    ['[not`code`]`(/qa-fake)`', 0, 'removing code must not manufacture a link'],
    ["[not\n\ncode](/qa-fake)", 0, 'separate blocks must not manufacture a link'],
    ["````\n```\n[hidden](/qa-hidden)\n```` trailing\n````\n[right](/qa-right)", 1, 'long fence contains short and suffixed near closers'],
    ["~~~\n```\n[hidden](/qa-hidden)\n~~~\n[right](/qa-right)", 1, 'tilde fence contains backticks'],
    ["```bad`info\n[visible](/qa-visible)", 1, 'invalid fence opener leaves link visible'],
    ['A ``<a href="/qa-hidden">[hidden](/qa-hidden)</a>`` <script>[visible](/qa-visible)</script>', 1, 'escaped HTML never becomes a link inside code'],
];
try {
    foreach (['content', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir);
    mkdir($root . '/content/it/home', 0755, true);
    $renderer = new Parsedown();
    foreach ($cases as [$body, $expected, $label]) {
        file_put_contents($root . '/content/it/home/index.md', $body);
        $html = $renderer->text($body);
        markdown_diagnostic_check(substr_count($html, '<a href=') === $expected, "$label renderer");
        $report = ap_diagnostics(['root' => $root]);
        markdown_diagnostic_check($report['complete'], "$label scan complete");
        markdown_diagnostic_check($report['summary']['links_checked'] === $expected,
            "$label checked links: " . json_encode($report['summary']));
        markdown_diagnostic_check($report['summary']['missing_links'] === $expected, "$label missing links");
    }
    echo "$checks diagnostic Markdown checks passed.\n";
} finally {
    unlink($root . '/content/it/home/index.md');
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) rmdir($root . '/' . $dir);
    rmdir($root);
}
