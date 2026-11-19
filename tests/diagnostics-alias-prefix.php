<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Synthetic fixtures only: never access a running site's data or the network.
$root = sys_get_temp_dir() . '/ap-alias-prefix-' . bin2hex(random_bytes(6));
$checks = 0;
function alias_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
try {
    foreach (['content/it/home', 'cache', 'storage', 'media'] as $dir) mkdir($root . '/' . $dir, 0755, true);
    file_put_contents($root . '/media/used.jpg', 'synthetic image');
    file_put_contents($root . '/media/orphan.jpg', 'synthetic image');
    foreach ([
        ['/blog', '/blog/canonical'],
        ['/blog/canonical', '/blog'],
        ['', '/canonical'],
        ['/same', '/same'],
        ['/blog', '/blogger'],
    ] as [$base, $canonical]) {
        foreach (['https://example.test', 'https://example.test:8443'] as $origin) {
            $body = '';
            foreach (array_unique([$base, $canonical]) as $prefix) {
                $body .= "[home]($origin$prefix/it)\n";
                $body .= "![used]($origin$prefix/media/used.jpg)\n";
                $body .= "[missing]($origin$prefix/it/post/missing)\n";
            }
            $body .= "[outside](https://other.test/media/used.jpg)\n";
            file_put_contents($root . '/content/it/home/index.md', $body);
            $options = ['root' => $root, 'base_path' => $base, 'origin' => $origin,
                'site_url' => $origin . $canonical];
            $report = ap_diagnostics($options);
            $count = count(array_unique([$base, $canonical]));
            $label = "$base / $canonical / $origin";
            alias_check($report['complete'], "$label complete");
            alias_check($report['summary']['missing_links'] === $count,
                "$label only missing posts fail: " . json_encode($report['issues']));
            alias_check($report['summary']['links_checked'] === 3 * $count, "$label all local aliases checked");
            alias_check($report['summary']['external_skipped'] === 1, "$label external host skipped");
            alias_check(array_column($report['orphans'], 'path') === ['media/orphan.jpg'], "$label used image retained");
            alias_check(is_file($root . '/media/used.jpg'), "$label no image deletion");
        }
    }
    // PR20 raw entity semantics must survive longest-prefix selection, and
    // PR18 incomplete inventories must still suppress orphan candidates.
    file_put_contents($root . '/media/literal&copy;.jpg', 'entity bytes');
    file_put_contents($root . '/media/literal©.jpg', 'lookalike bytes');
    file_put_contents($root . '/content/it/home/index.md', '![literal](https://example.test/blog/canonical/media/literal%26copy%3B.jpg)');
    $interaction = ['root' => $root, 'base_path' => '/blog', 'origin' => 'https://example.test',
        'site_url' => 'https://example.test/blog/canonical',
        'custom_css' => 'a{background:url("https://example.test/blog/canonical/media/literal&copy;.jpg")}'];
    foreach ([false, true] as $wrongCache) {
        if ($wrongCache) {
            rename($root . '/cache', $root . '/held-cache');
            file_put_contents($root . '/cache', 'wrong type');
        }
        try {
            $mixed = ap_diagnostics($interaction);
            alias_check($mixed['summary']['links_checked'] === 2 && $mixed['summary']['missing_links'] === 0, 'Nested canonical raw/Markdown entity targets resolve consistently.');
            alias_check($mixed['complete'] === !$wrongCache, 'Canonical alias does not change scan completeness.');
            $orphans = array_column($mixed['orphans'], 'path');
            alias_check(!in_array('media/literal&copy;.jpg', $orphans, true), 'Literal referenced image never becomes an orphan.');
            alias_check($wrongCache ? $orphans === [] : in_array('media/literal©.jpg', $orphans, true), 'Lookalike is orphan only on complete inventories.');
        } finally {
            if ($wrongCache) {
                unlink($root . '/cache');
                rename($root . '/held-cache', $root . '/cache');
            }
        }
    }
    echo "$checks alias-prefix checks passed.\n";
} finally {
    foreach (['content/it/home/index.md', 'media/used.jpg', 'media/orphan.jpg', 'media/literal&copy;.jpg', 'media/literal©.jpg'] as $file) {
        if (is_file($root . '/' . $file)) unlink($root . '/' . $file);
    }
    foreach (['content/it/home', 'content/it', 'content', 'cache', 'storage', 'media'] as $dir) {
        if (is_dir($root . '/' . $dir)) rmdir($root . '/' . $dir);
    }
    if (is_dir($root)) rmdir($root);
}
