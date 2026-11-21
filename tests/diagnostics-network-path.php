<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';
$root = sys_get_temp_dir() . '/ap-network-path-' . bin2hex(random_bytes(6));
$checks = 0;
function network_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function network_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') network_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content/it/home', 'cache', 'storage', 'media', 'assets'] as $dir) mkdir($root . '/' . $dir, 0700, true);
    file_put_contents($root . '/media/used.jpg', 'synthetic bytes');
    foreach ([['http', ''], ['HTTPS', ''], ['http', ':8443'], ['https', ':8443']] as [$scheme, $port]) {
        foreach (['custom-css', 'stylesheet'] as $source) {
            // Custom CSS is served unchanged for root installs. Subdirectory
            // custom-CSS rewriting is a separate existing concern.
            $base = $source === 'custom-css' ? '' : '/blog';
            $url = '//example.test' . $port . $base . '/media/used.jpg';
            $body = 'Home'; $css = '';
            if ($source === 'custom-css') $css = 'a{background:url("' . $url . '")}';
            file_put_contents($root . '/assets/site.css', $source === 'stylesheet' ? 'a{background:url("' . $url . '")}' : '');
            file_put_contents($root . '/content/it/home/index.md', $body);
            $report = ap_diagnostics(['root' => $root, 'base_path' => $base, 'origin' => $scheme . '://example.test' . $port, 'custom_css' => $css]);
            network_check($report['complete'], "$scheme $source complete");
            network_check($report['summary']['links_checked'] === 1, "$scheme $port $source network-path reference stays local");
            network_check($report['summary']['external_skipped'] === 0 && $report['summary']['missing_links'] === 0, 'Local target resolves.');
            network_check($report['orphans'] === [], 'Network-path reference retains image.');
        }
    }
    // A network-path reference inherits the scheme, never the origin port.
    file_put_contents($root . '/content/it/home/index.md', 'Home');
    file_put_contents($root . '/assets/site.css', 'a{background:url("//example.test/blog/media/used.jpg")}');
    $report = ap_diagnostics(['root' => $root, 'base_path' => '/blog', 'origin' => 'http://example.test:8443']);
    network_check($report['summary']['external_skipped'] === 1, 'Implicit port80 does not inherit explicit8443.');
    network_check(count($report['orphans']) === 1, 'Different-port reference does not retain local image.');
    // Explicit schemes keep their own port defaults, regardless of current origin.
    file_put_contents($root . '/assets/site.css', 'a{background:url("https://example.test/blog/media/used.jpg")}');
    $report = ap_diagnostics(['root' => $root, 'base_path' => '/blog', 'origin' => 'http://example.test']);
    network_check($report['summary']['external_skipped'] === 1, 'Explicit HTTPS remains external to HTTP alias.');
    // CLI diagnostics may only know the canonical site URL.
    file_put_contents($root . '/content/it/home/index.md', 'Home');
    file_put_contents($root . '/assets/site.css', 'a{background:url("//example.test/blog/media/used.jpg")}');
    $report = ap_diagnostics(['root' => $root, 'site_url' => 'http://example.test/blog']);
    network_check($report['summary']['links_checked'] === 1 && $report['orphans'] === [], 'Canonical scheme fallback when origin unavailable.');
    echo "$checks network-path diagnostic checks passed.\n";
} finally { network_remove($root); }
