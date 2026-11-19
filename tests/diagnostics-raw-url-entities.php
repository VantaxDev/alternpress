<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$root = sys_get_temp_dir() . '/ap-diagnostics-entities-' . bin2hex(random_bytes(6));
$checks = 0;
function entity_check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function entity_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') entity_remove($path . '/' . $child);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'cache', 'storage', 'media', 'assets'] as $dir) mkdir($root . '/' . $dir, 0755, true);
    // Raw CSS and metadata do not have an HTML entity layer. A second,
    // decoded filename makes accidental retargeting observable as an orphan.
    foreach (['&copy;', '&amp;', '&lt;'] as $entity) {
        $name = 'photo' . $entity . '.jpg';
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        file_put_contents($root . '/media/' . $name, 'synthetic');
        file_put_contents($root . '/media/' . $decoded, 'synthetic');
        foreach (['css-quoted', 'css-unquoted', 'custom-css', 'cover', 'markdown', 'percent'] as $source) {
            $url = '/media/' . $name;
            $body = 'Home';
            $css = '';
            $custom = '';
            if ($source === 'css-quoted') $css = '.hero{background:url("' . $url . '")}';
            if ($source === 'css-unquoted') $css = '.hero{background:url(' . $url . ')}';
            if ($source === 'custom-css') $custom = '.hero{background:url("' . $url . '")}';
            if ($source === 'cover') $body = "---\ncover: https://example.test" . $url . "\n---\nHome";
            // Markdown's URL grammar decodes entities once; encode the ampersand
            // to target the literal entity-bearing filename in the browser.
            if ($source === 'markdown') $body = '![photo](' . str_replace('&', '&amp;', $url) . ')';
            if ($source === 'percent') $body = '![photo](/media/' . rawurlencode($name) . ')';
            if ($source === 'markdown') {
                $html = (new Parsedown())->text($body);
                preg_match('~src="([^"]*)"~', $html, $attribute);
                entity_check(isset($attribute[1]), 'Rendered Markdown has an image attribute.');
                entity_check(html_entity_decode($attribute[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $url, 'Exactly one HTML layer yields the literal browser target.');
            }
            file_put_contents($root . '/content/it/home/index.md', $body);
            file_put_contents($root . '/assets/site.css', $css);
            $report = ap_diagnostics(['root' => $root, 'languages' => ['it'], 'origin' => 'https://example.test', 'custom_css' => $custom, 'max_seconds' => 30]);
            entity_check($report['complete'], "$entity $source scan complete");
            entity_check($report['summary']['links_checked'] === 1, "$entity $source checks one reference");
            entity_check($report['summary']['missing_links'] === 0, "$entity $source resolves literal filename");
            entity_check(array_column($report['orphans'], 'path') === ['media/' . $decoded], "$entity $source must retain literal file, not decoded lookalike: " . json_encode($report['orphans']));
        }
        unlink($root . '/media/' . $name);
        unlink($root . '/media/' . $decoded);
    }
    // Stacked traversal fixes must not change raw-URL resolution or restore
    // orphan suggestions when the inventory is incomplete.
    file_put_contents($root . '/media/literal&copy;.jpg', 'synthetic');
    file_put_contents($root . '/assets/site.css', 'a{background:url("/media/literal&copy;.jpg")}');
    file_put_contents($root . '/content/it/home/index.md', 'Home');
    foreach (['wrong-directory', 'nested-fifo'] as $failure) {
        if ($failure === 'nested-fifo' && !function_exists('posix_mkfifo')) continue;
        if ($failure === 'wrong-directory') {
            rename($root . '/cache', $root . '/held-cache');
            file_put_contents($root . '/cache', 'wrong type');
        } else {
            if (!posix_mkfifo($root . '/storage/special.css', 0600)) throw new RuntimeException('Cannot create FIFO fixture.');
        }
        try {
            $mixed = ap_diagnostics(['root' => $root, 'languages' => ['it'], 'max_seconds' => 30]);
            entity_check(!$mixed['complete'] && $mixed['orphans'] === [], 'Stacked incomplete inventory still suppresses orphans.');
            entity_check($mixed['summary']['links_checked'] === 1 && $mixed['summary']['missing_links'] === 0, 'Raw CSS retains its literal target during partial scan.');
            $code = $failure === 'wrong-directory' ? 'not_directory' : 'unsupported_file_type';
            entity_check(count(array_filter($mixed['issues'], static fn($issue) => $issue['code'] === $code)) === 1, 'Traversal failure remains independently reported.');
            entity_check(file_get_contents($root . '/media/literal&copy;.jpg') === 'synthetic', 'Interaction preserves image bytes.');
        } finally {
            if ($failure === 'wrong-directory') {
                unlink($root . '/cache');
                rename($root . '/held-cache', $root . '/cache');
            } else unlink($root . '/storage/special.css');
        }
    }
    echo "Diagnostics raw URL entities: $checks checks passed.\n";
} finally { entity_remove($root); }
