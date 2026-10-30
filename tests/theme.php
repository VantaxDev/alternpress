<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
// Always exercise a disposable copy; never overwrite an owner's custom files.
$root = sys_get_temp_dir() . '/alternpress-theme-' . bin2hex(random_bytes(6));
mkdir($root);
function theme_copy(string $from, string $to): void {
    mkdir($to);
    foreach (new DirectoryIterator($from) as $item) {
        if ($item->isDot() || $item->isLink() || in_array($item->getFilename(), ['.git', 'config.local.php', 'theme.css', 'favicon.local.svg', 'settings.php'], true)) continue;
        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) theme_copy($item->getPathname(), $target);
        else copy($item->getPathname(), $target);
    }
}
function theme_remove(string $path): void {
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) theme_remove($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}
register_shutdown_function(static fn() => theme_remove($root));
theme_copy(dirname(__DIR__), $root . '/site');
define('AP_LIBRARY', true);
require $root . '/site/index.php';
function theme_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function theme_render(): string {
    ob_start(); render_layout('en', '/', 'Theme test', '<p>Test</p>'); return ob_get_clean();
}
$AP_CONFIG['base_path'] = '/installed';
$html = theme_render();
theme_check(!str_contains($html, '/assets/theme.css') && str_contains($html, '/installed/assets/favicon.svg?v='), 'defaults and subfolder URLs');
copy($root . '/site/assets/theme.example.css', $root . '/site/assets/theme.css');
copy($root . '/site/assets/favicon.svg', $root . '/site/assets/favicon.local.svg');
$AP_CONFIG['custom_css'] = 'body { color: red; }';
$html = theme_render();
theme_check(str_contains($html, '/installed/assets/favicon.local.svg?v='), 'custom favicon selected');
theme_check(strpos($html, '/assets/site.css?') < strpos($html, '/assets/theme.css?') && strpos($html, '/assets/theme.css?') < strpos($html, '?ap-css=custom'), 'custom CSS remains last');
$before = ap_page_cache_fingerprint();
$url = public_asset_url('/assets/theme.css');
file_put_contents($root . '/site/assets/theme.css', '
/* edit */', FILE_APPEND);
theme_check($url !== public_asset_url('/assets/theme.css') && $before !== ap_page_cache_fingerprint(), 'theme edit refreshes URL and page fingerprint');
$before = ap_page_cache_fingerprint();
$url = public_asset_url('/assets/favicon.local.svg');
file_put_contents($root . '/site/assets/favicon.local.svg', '
<!-- edit -->', FILE_APPEND);
theme_check($url !== public_asset_url('/assets/favicon.local.svg') && $before !== ap_page_cache_fingerprint(), 'favicon edit refreshes URL and page fingerprint');
unlink($root . '/site/assets/theme.css');
unlink($root . '/site/assets/favicon.local.svg');
$html = theme_render();
theme_check(!str_contains($html, '/assets/theme.css') && str_contains($html, '/installed/assets/favicon.svg?v='), 'removing overrides restores defaults');
symlink($root . '/site/assets/site.css', $root . '/site/assets/theme.css');
symlink($root . '/site/assets/favicon.svg', $root . '/site/assets/favicon.local.svg');
$html = theme_render();
theme_check(!str_contains($html, '/assets/theme.css') && !str_contains($html, '/assets/favicon.local.svg'), 'symlink overrides rejected');
echo "7 theme checks passed.\n";
