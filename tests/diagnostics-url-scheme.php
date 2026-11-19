<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$root = sys_get_temp_dir() . '/alternpress-url-scheme-' . bin2hex(random_bytes(6));
$checks = 0;
function scheme_check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function scheme_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') scheme_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'cache', 'storage', 'media'] as $path) mkdir($root . '/' . $path, 0755, true);
    file_put_contents($root . '/media/used.jpg', 'synthetic-image');
    foreach (['https', 'HTTPS', 'HtTpS', 'http', 'HTTP'] as $configuredScheme) {
        foreach (['https', 'HTTPS', 'hTtPs', 'http', 'HTTP'] as $linkScheme) {
            // Match implicit ports, not just hosts; HTTP and HTTPS are distinct aliases.
            $samePort = strtolower($configuredScheme) === strtolower($linkScheme);
            foreach (['origin', 'site_url'] as $alias) {
                $url = $linkScheme . '://example.test/blog';
                file_put_contents($root . '/content/it/home/index.md', "[missing]($url/missing)\n![used]($url/media/used.jpg)\n");
                $options = ['root' => $root, 'base_path' => '/blog', $alias => $configuredScheme . '://example.test/blog'];
                $report = ap_diagnostics($options);
                $case = "$alias $configuredScheme -> $linkScheme";
                scheme_check($report['complete'], "$case: complete scan");
                scheme_check($report['summary']['missing_links'] === ($samePort ? 1 : 0), "$case: missing local link must be checked");
                scheme_check($report['summary']['external_skipped'] === ($samePort ? 0 : 2), "$case: only unmatched ports are external");
                scheme_check(count($report['orphans']) === ($samePort ? 0 : 1), "$case: locally referenced image must not be orphaned");
            }
        }
    }
    // Explicit ports override scheme defaults, with case-insensitive host matching.
    foreach (['HTTPS', 'https', 'HTTP'] as $scheme) {
        file_put_contents($root . '/content/it/home/index.md', "[missing]($scheme://EXAMPLE.test:8443/blog/missing)\n![used]($scheme://EXAMPLE.test:8443/blog/media/used.jpg)\n[external]($scheme://example.test:9443/blog/missing)\n");
        $report = ap_diagnostics(['root' => $root, 'base_path' => '/blog', 'origin' => 'HTTPS://example.test:8443']);
        scheme_check($report['summary']['missing_links'] === 1, "$scheme: explicit local port");
        scheme_check($report['summary']['external_skipped'] === 1, "$scheme: different explicit port stays external");
        scheme_check($report['orphans'] === [], "$scheme: explicit-port image retained");
    }
    // Interactions: mixed-case implicit HTTPS aliases, longest canonical
    // prefix, raw entities, and wrong-directory orphan suppression.
    file_put_contents($root . '/media/literal&copy;.jpg', 'entity bytes');
    file_put_contents($root . '/media/literal©.jpg', 'lookalike bytes');
    file_put_contents($root . '/content/it/home/index.md', '![literal](https://example.test/blog/canonical/media/literal%26copy%3B.jpg)');
    $interaction = ['root' => $root, 'base_path' => '/blog', 'origin' => 'HTTPS://example.test',
        'site_url' => 'HtTpS://example.test/blog/canonical',
        'custom_css' => 'a{background:url("HTTPS://example.test/blog/canonical/media/literal&copy;.jpg")}'];
    foreach ([false, true] as $wrongCache) {
        if ($wrongCache) {
            rename($root . '/cache', $root . '/held-cache');
            file_put_contents($root . '/cache', 'wrong type');
        }
        try {
            $mixed = ap_diagnostics($interaction);
            scheme_check($mixed['summary']['links_checked'] === 2 && $mixed['summary']['missing_links'] === 0, 'Mixed-case canonical entity targets resolve locally.');
            scheme_check($mixed['summary']['external_skipped'] === 0, 'Implicit HTTPS case never makes these local links external.');
            scheme_check($mixed['complete'] === !$wrongCache, 'Scheme normalization does not change completeness.');
            $orphans = array_column($mixed['orphans'], 'path');
            scheme_check(!in_array('media/literal&copy;.jpg', $orphans, true), 'Referenced literal entity file is retained.');
            scheme_check($wrongCache ? $orphans === [] : in_array('media/literal©.jpg', $orphans, true), 'Lookalike orphan only reported for a complete scan.');
        } finally {
            if ($wrongCache) {
                unlink($root . '/cache');
                rename($root . '/held-cache', $root . '/cache');
            }
        }
    }
    echo "Diagnostics URL schemes: $checks checks passed.\n";
} finally {
    scheme_remove($root);
}
