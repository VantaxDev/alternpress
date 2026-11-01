<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/Parsedown.php';
$markdown = new Parsedown();
$checks = 0;
function fence_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS $label\n";
}
function fence_html(string $body): string {
    return '<pre><code>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
}
// Reproduce premature closing of a four-backtick example by a shorter run.
$body = "```php\n<script>alert(1)</script>\n```";
fence_check($markdown->text("````markdown\n$body\n````\nAfter") === fence_html($body) . "\n<p>After</p>", 'short inner backticks remain inside a longer fenced example');
foreach (['`', '~'] as $marker) {
    foreach ([3, 4, 12] as $length) {
        $opening = str_repeat($marker, $length);
        $closing = str_repeat($marker, $length + 2);
        fence_check($markdown->text("$opening info\nExample\n$closing\nAfter") === fence_html('Example') . "\n<p>After</p>", "$marker fence length $length accepts longer matching closer");
    }
    $open = str_repeat($marker, 4);
    $wrong = $marker === '`' ? '~~~~' : '````';
    $body = "$wrong\n" . str_repeat($marker, 3) . "\n" . $open . " trailing text\n# literal\n[link](javascript:alert)";
    fence_check($markdown->text("$open\n$body\n$open") === fence_html($body), "$marker mismatched/short/suffixed closer cannot terminate code");
    fence_check($markdown->text("$open\n**literal**\n\n- item") === fence_html("**literal**\n\n- item"), "$marker unclosed block remains literal through EOF");
    fence_check($markdown->text("$open\n$open") === fence_html(''), "$marker empty block");
    fence_check($markdown->text("$open\n  spaced  \t\n\tindented\n$open \t") === fence_html("  spaced  \t\n\tindented"), "$marker preserves code whitespace and accepts closer trailing spaces/tabs");
    fence_check($markdown->text("  $open\n   one\n two\n\tthree\n   $open") === fence_html(" one\ntwo\n\tthree"), "$marker removes at most the opener indentation from code");
    fence_check($markdown->text("$open\n    $open\nlast\n$open") === fence_html("    $open\nlast"), "$marker four-space-indented closer stays literal");
    fence_check($markdown->text("Paragraph\n$open\ncode\n$open\n# Heading") === "<p>Paragraph</p>\n" . fence_html('code') . "\n<h1>Heading</h1>", "$marker interrupts paragraph and resumes blocks after closer");
    $payload = '<img src=x onerror=alert(1)> & "quote" </code></pre><script>alert(1)</script>';
    fence_check($markdown->text($open . "\n" . $payload . "\n" . $open) === fence_html($payload), "$marker HTML/XSS payload escaped rather than executed");
    $info = $marker === '`' ? 'php" onclick="alert(1)' : '<img src=x onerror=alert(1)>';
    fence_check($markdown->text($open . $info . "\ncode\n" . $open) === fence_html('code'), "$marker info is ignored and never becomes HTML attributes");
}
fence_check($markdown->text("```bad`info\nplain") === '<p>``<code>bad</code>info plain</p>', 'backtick in opening info is not accepted as a code-block opener');
fence_check(!str_contains($markdown->text("    ```\nplain"), '<pre>'), 'four-space-indented opening is not a supported top-level fence');
fence_check(!str_contains($markdown->text("\t```\nplain"), '<pre>'), 'tab-indented opening is not a supported top-level fence');
fence_check($markdown->text("~~~literal`info\ncode\n~~~") === fence_html('code'), 'tilde opening info may contain a backtick');
fence_check($markdown->text("```\r\nline  \r\n```\r\nAfter") === fence_html('line  ') . "\n<p>After</p>", 'CRLF normalized without trimming code spaces');
fence_check($markdown->text("~~~\rline\r~~~") === fence_html('line'), 'CR line endings supported');
fence_check($markdown->text("```\nA\n```\n~~~\nB\n~~~") === fence_html('A') . "\n" . fence_html('B'), 'adjacent differently delimited blocks remain separate');
fence_check($markdown->text("```\n| A | B |\n|---|---|\n**bold**\n![x](javascript:alert)\n```") === fence_html("| A | B |\n|---|---|\n**bold**\n![x](javascript:alert)"), 'tables/formatting/images inside code remain literal');
fence_check($markdown->text('**bold** and `code`') === '<p><strong>bold</strong> and <code>code</code></p>', 'existing inline formatting unchanged');
fence_check($markdown->text("```\n\x1A0\x1A\n```") === fence_html("\x1A0\x1A"), 'inline placeholder bytes in fenced code are not substituted');
// Large near-closing runs exercise a bounded single pass, not a dynamic backreference.
$run = str_repeat('`', 12000);
$many = str_repeat($run . "x\n", 64);
fence_check($markdown->text($run . "\n" . $many . $run) === fence_html(rtrim($many, "\n")), 'large near-closers remain literal without rescanning the document');
foreach ([str_repeat('x', Parsedown::MAX_BYTES + 1), str_repeat("\n", 40000)] as $input) {
    $rejected = false;
    try { $markdown->text($input); } catch (RuntimeException) { $rejected = true; }
    fence_check($rejected, 'existing document size/line limits remain enforced');
}
// Public pages and authenticated editor preview both use this wrapper.
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
$example = "~~~~\n[mail](mailto:writer@example.org)\n</code><script>alert(1)</script>\n~~~~";
fence_check(markdown_html($markdown, $example) === fence_html("[mail](mailto:writer@example.org)\n</code><script>alert(1)</script>"), 'public/editor rendering wrapper preserves literal email and escaped XSS');
echo "$checks Markdown fence checks passed.\n";
