<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
ob_start();
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
// Test the actual layout/cache functions with a namespaced clock, without changing
// the server clock, exposing a production clock override, or editing production code.
$layout = file_get_contents(dirname(__DIR__) . '/index.php');
$layout = substr($layout, strpos($layout, 'function render_layout('));
$layout = substr($layout, 0, strpos($layout, "\nrequire_once"));
$cache = file_get_contents(dirname(__DIR__) . '/lib/page-cache.php');
$cache = substr($cache, strpos($cache, 'function ap_page_cache_fingerprint('));
$cache = substr($cache, 0, strpos($cache, "\nfunction "));
eval('namespace FooterClock; use RecursiveIteratorIterator; use RecursiveDirectoryIterator; use FilesystemIterator; use Throwable; function date($format) { return \\date($format, $GLOBALS["footer_time"]); } ' . $layout . $cache);
$checks = 0;
function footer_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
function footer_render(): string {
    ob_start(); \FooterClock\render_layout('it', '/', 'Test', '<p>Content</p>'); return ob_get_clean();
}
date_default_timezone_set('Europe/Rome');
$AP_CONFIG['legal'] = 'Example Owner - P.IVA ' . implode('', ['043', '706', '00274']);
$AP_CONFIG['email'] = 'owner@example.test';
$GLOBALS['footer_time'] = strtotime('2030-12-31T23:59:59+01:00');
$before = footer_render();
$fingerprint = \FooterClock\ap_page_cache_fingerprint();
$GLOBALS['footer_time'] = strtotime('2031-01-01T00:00:00+01:00');
$after = footer_render();
footer_check(str_contains($before, '© 2030 <span') && str_contains($after, '© 2031 <span'), 'server-local year changes exactly at midnight');
footer_check($fingerprint !== null && $fingerprint !== \FooterClock\ap_page_cache_fingerprint(), 'warm page cache fingerprint changes at year rollover');
footer_check(str_contains($after, '>Example Owner - P.IVA 04370600274</span>'), 'configured identity and complete VAT are present without JavaScript');
footer_check(str_contains($after, 'js-protected-email') && str_contains($after, 'Content'), 'existing email link and content preserved');
footer_check(!str_contains($after, 'SERVER TAMER') && !str_contains($after, 'const info ='), 'third-party installs have no imposed business identity or legacy legal script');
$AP_CONFIG['legal'] = '<img src=x onerror=alert(1)>';
footer_check(str_contains(footer_render(), '&lt;img src=x onerror=alert(1)&gt;'), 'business identity is HTML-escaped');
$AP_CONFIG['legal'] = '';
footer_check(str_contains(footer_render(), 'class="footer-credits"></span>'), 'empty default business identity remains supported');
echo "$checks footer checks passed.\n";
ob_end_flush();
