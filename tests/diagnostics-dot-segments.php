<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';
$root = sys_get_temp_dir() . '/ap-network-path-' . bin2hex(random_bytes(6));
$checks = 0;
function dot_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function dot_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') dot_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'cache', 'storage', 'media', 'assets'] as $dir) mkdir($root . '/' . $dir, 0700, true);
    file_put_contents($root . '/media/used.jpg', 'synthetic bytes');
    foreach (['https://example.test', '//example.test', ''] as $authority) {
        foreach (['/unused/../blog/media/used.jpg', '/blog/./media/used.jpg', '/blog/assets/../media/used.jpg', '/blog/../blog/media/used.jpg'] as $path) {
            $url = $authority . $path;
            $report = ap_diagnostics(['root' => $root, 'base_path' => '/blog', 'origin' => 'https://example.test', 'custom_css' => 'a{background:url("' . $url . '")}']);
            dot_check($report['complete'], 'Complete inventory');
            dot_check($report['summary']['links_checked'] === 1 && $report['summary']['missing_links'] === 0, 'Browser-normalized local URL resolves: ' . $url);
            dot_check($report['orphans'] === [], 'Referenced image is retained: ' . $url);
        }
    }
    foreach (['https://example.test/blog/../media/used.jpg', '//example.test/blog/../media/used.jpg', 'https://remote.test/unused/../blog/media/used.jpg'] as $url) {
        $report = ap_diagnostics(['root' => $root, 'base_path' => '/blog', 'origin' => 'https://example.test', 'custom_css' => 'a{background:url("' . $url . '")}']);
        dot_check($report['summary']['external_skipped'] === 1, 'Outside alias stays external: ' . $url);
        dot_check(count($report['orphans']) === 1, 'Outside alias does not retain local image');
    }
    echo "$checks dot-segment diagnostic checks passed.\n";
} finally { dot_remove($root); }
