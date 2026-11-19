<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';
$root = sys_get_temp_dir() . '/ap-missing-stack-' . bin2hex(random_bytes(6));
$clock = $_SERVER['REQUEST_TIME'] ?? null;
$checks = 0;
function missing_stack_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function missing_stack_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') missing_stack_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'content/it/post/target', 'cache', 'storage', 'media', 'assets'] as $path) mkdir($root . '/' . $path, 0700, true);
    $_SERVER['REQUEST_TIME'] = 1901149199;
    file_put_contents($root . '/content/it/post/target/index.md', "---\nstatus: published\npublish_at: 2030-03-31T01:00:00Z\n---\nFuture");
    file_put_contents($root . '/content/it/home/index.md', "[future](HTTPS://example.test/blog/canonical/it/post/target)\n![literal](https://example.test/blog/canonical/media/literal%26copy%3B.jpg)");
    file_put_contents($root . '/media/literal&copy;.jpg', 'literal bytes');
    file_put_contents($root . '/media/literal©.jpg', 'lookalike bytes');
    $options = ['root' => $root, 'base_path' => '/blog', 'origin' => 'HTTPS://example.test',
        'site_url' => 'HtTpS://example.test/blog/canonical',
        'custom_css' => 'a{background:url("HTTPS://example.test/blog/canonical/media/literal&copy;.jpg")}'];
    foreach ([false, true] as $faults) {
        if ($faults) {
            if (!function_exists('posix_mkfifo')) { echo "SKIP mixed Unix FIFO fixture: POSIX unavailable.\n"; continue; }
            rmdir($root . '/storage');
            rmdir($root . '/cache');
            file_put_contents($root . '/cache', 'wrong type');
            if (!posix_mkfifo($root . '/assets/special.css', 0600)) throw new RuntimeException('Cannot create FIFO fixture.');
        }
        $report = ap_diagnostics($options);
        missing_stack_check($report['complete'] === !$faults, 'Mixed traversal faults mark inventory incomplete.');
        missing_stack_check($report['summary']['missing_links'] === 1, 'Only future publication is missing, raw media exists.');
        missing_stack_check($report['summary']['links_checked'] === 3, 'Canonical scheme/aliases resolve all three references.');
        missing_stack_check($report['summary']['external_skipped'] === 0, 'Mixed-case canonical references remain local.');
        missing_stack_check(array_column($report['orphans'], 'path') === ($faults ? [] : ['media/literal©.jpg']), 'Literal retained and lookalike suggested only for complete inventories.');
        foreach (['missing_directory' => 'storage', 'not_directory' => 'cache', 'unsupported_file_type' => 'assets/special.css'] as $code => $path) {
            $issues = array_values(array_filter($report['issues'], static fn($issue) => $issue['code'] === $code));
            missing_stack_check($faults ? count($issues) === 1 && $issues[0]['path'] === $path : $issues === [], 'Independent traversal issue preserved: ' . $code);
        }
        missing_stack_check(file_get_contents($root . '/media/literal&copy;.jpg') === 'literal bytes', 'Diagnostics preserves referenced bytes.');
    }
    echo "$checks missing-stack interaction checks passed.\n";
} finally {
    if ($clock === null) unset($_SERVER['REQUEST_TIME']); else $_SERVER['REQUEST_TIME'] = $clock;
    missing_stack_remove($root);
}
