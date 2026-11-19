<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Standalone synthetic site; no bootstrap, installed content or network access.
$root = sys_get_temp_dir() . '/ap-diagnostic-publication-' . bin2hex(random_bytes(6));
$clock = $_SERVER['REQUEST_TIME'] ?? null;
$checks = 0;
function publication_diagnostic_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function publication_diagnostic_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') publication_diagnostic_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'content/it/post/target', 'content/it/privacy', 'cache', 'storage', 'media'] as $directory) mkdir($root . '/' . $directory, 0700, true);
    file_put_contents($root . '/content/it/post/target/photo.jpg', 'synthetic attachment');
    file_put_contents($root . '/media/retained.jpg', 'synthetic upload');
    $links = ['/it/post/target', '/post/target', '/it/post/target/photo.jpg', '/it/privacy', '/privacy'];
    $body = '';
    foreach ($links as $url) $body .= "[target]($url)\n";
    file_put_contents($root . '/content/it/home/index.md', $body);
    $boundary = 1901149200; // 2030-03-31T01:00:00Z.
    $cases = [
        ["status: published\npublish_at: 2030-03-31T03:00:00+02:00", $boundary - 1, false, 'one second before schedule'],
        ["status: published\npublish_at: 2030-03-31T03:00:00+02:00", $boundary, true, 'exact publication boundary'],
        ["status: published\npublish_at: 2030-03-31T01:00:00Z", $boundary + 1, true, 'after publication'],
        ["status: draft\npublish_at: 2000-01-01T00:00:00Z", $boundary, false, 'draft with past schedule'],
        ["status: published\npublish_at: 2030-02-30T01:00:00Z", $boundary, false, 'invalid calendar date'],
        ["status: published\npublish_at: tomorrow", $boundary, false, 'invalid timestamp'],
        ["publish_at: 2030-03-31T01:00:00Z", $boundary - 1, false, 'implicit published status still scheduled'],
        ['status: published', $boundary, true, 'legacy immediate post'],
        ['date: 2099-01-01', $boundary, true, 'editorial date is not schedule'],
        ["status: published\npublish_at: ", $boundary, true, 'empty schedule remains immediate'],
    ];
    foreach ($cases as [$metadata, $now, $public, $label]) {
        $_SERVER['REQUEST_TIME'] = $now;
        $document = "---\n$metadata\n---\n![retain](/media/retained.jpg)\n";
        file_put_contents($root . '/content/it/post/target/index.md', $document);
        file_put_contents($root . '/content/it/privacy/index.md', $document);
        foreach (['', '/blog'] as $base) {
            $source = $base === '' ? $body : str_replace('](/', '](/blog/', $body);
            file_put_contents($root . '/content/it/home/index.md', $source);
            $report = ap_diagnostics(['root' => $root, 'base_path' => $base]);
            publication_diagnostic_check($report['complete'], "$label $base complete scan");
            publication_diagnostic_check($report['summary']['links_checked'] === 7, "$label $base checks all destinations including private source images");
            publication_diagnostic_check($report['summary']['missing_links'] === ($public ? 0 : 5), "$label $base correct public availability: " . json_encode($report['issues']));
            $missingUrls = array_column(array_filter($report['issues'], static fn($issue) => $issue['code'] === 'missing_link'), 'url');
            publication_diagnostic_check($missingUrls === ($public ? [] : array_map(static fn($url) => $base . $url, $links)), "$label $base exact missing targets");
            publication_diagnostic_check($report['orphans'] === [], "$label $base scheduled/draft references still retain images");
        }
    }
    // Mixed-case nested canonical aliases must use the same schedule policy;
    // entity-bearing raw CSS remains retained even when targets are private.
    file_put_contents($root . '/media/literal&copy;.jpg', 'entity bytes');
    file_put_contents($root . '/content/it/post/target/index.md', "---\nstatus: published\npublish_at: 2030-03-31T01:00:00Z\n---\n![retain](/media/retained.jpg)\n");
    file_put_contents($root . '/content/it/home/index.md', '[scheduled](HTTPS://example.test/blog/canonical/it/post/target)');
    $_SERVER['REQUEST_TIME'] = $boundary - 1;
    $options = ['root' => $root, 'base_path' => '/blog', 'origin' => 'HTTPS://example.test',
        'site_url' => 'HtTpS://example.test/blog/canonical',
        'custom_css' => 'a{background:url("HTTPS://example.test/blog/canonical/media/literal&copy;.jpg")}'];
    foreach ([false, true] as $wrongCache) {
        if ($wrongCache) { rename($root . '/cache', $root . '/held-cache'); file_put_contents($root . '/cache', 'wrong type'); }
        try {
            $mixed = ap_diagnostics($options);
            publication_diagnostic_check($mixed['summary']['missing_links'] === 1, 'Only future destination is missing through canonical mixed-case alias.');
            publication_diagnostic_check($mixed['summary']['external_skipped'] === 0, 'All mixed-case canonical references stay local.');
            publication_diagnostic_check($mixed['complete'] === !$wrongCache, 'Publication policy preserves incomplete inventory state.');
            publication_diagnostic_check($wrongCache ? $mixed['orphans'] === [] : array_column($mixed['orphans'], 'path') === ['content/it/post/target/photo.jpg'], 'Referenced media retained, genuinely unused attachment remains a candidate only on complete scans.');
        } finally {
            if ($wrongCache) { unlink($root . '/cache'); rename($root . '/held-cache', $root . '/cache'); }
        }
    }
    echo "$checks diagnostics publication checks passed.\n";
} finally {
    if ($clock === null) unset($_SERVER['REQUEST_TIME']); else $_SERVER['REQUEST_TIME'] = $clock;
    publication_diagnostic_remove($root);
}
