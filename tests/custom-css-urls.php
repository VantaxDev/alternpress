<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/settings.php';
if (!function_exists('t')) { function t(string $it, string $en): string { return $en; } }
$before = $AP_CONFIG;
$checks = 0;
try {
    foreach (['', '/blog', '/nested/blog'] as $base) {
        $AP_CONFIG['base_path'] = $base;
        foreach (['//cdn.example.test/image.png', '//example.test:8443/media/photo.jpg?x=1&y=2#photo', 'https://cdn.example.test/photo.jpg', 'images/local.jpg', '#mask', 'data:image/png;base64,AAAA'] as $url) {
            foreach (['', '"', "'"] as $quote) {
                $css = '.hero{background: url( ' . $quote . $url . $quote . ' )}';
                $AP_CONFIG['custom_css'] = $css;
                $actual = ap_custom_css();
                // Existing rewrite normalizes surrounding whitespace for root paths.
                $expected = str_starts_with($url, '//') ? '.hero{background: url(' . $quote . $url . $quote . ')}' : $css;
                if ($actual !== $expected) throw new RuntimeException("$base $url expected $expected, got $actual");
                $checks++;
            }
        }
        foreach (['/media/image.jpg', '/blogger/image.jpg', '/blog/image.jpg'] as $path) {
            $AP_CONFIG['custom_css'] = 'a{background:url("' . $path . '")}';
            $expected = $base !== '' && $path !== $base && !str_starts_with($path, $base . '/') ? $base . $path : $path;
            if (ap_custom_css() !== 'a{background:url("' . $expected . '")}') throw new RuntimeException('Root-relative prefixing changed.');
            $checks++;
        }
        $AP_CONFIG['custom_css'] = 'a{background:url("//cdn.example.test/a.png")}b{background:url(/media/b.jpg)}';
        if (ap_custom_css() !== 'a{background:url("//cdn.example.test/a.png")}b{background:url(' . $base . '/media/b.jpg)}') throw new RuntimeException('Mixed stylesheet rewrite failed.');
        $checks++;
    }
    // Trusted config/legacy CSS may contain these URLs, but admin policy
    // deliberately rejects external resources and must remain unchanged.
    foreach (['//cdn.example.test/image.png', 'https://cdn.example.test/image.png'] as $url) {
        try {
            ap_validate_css('a{background:url("' . $url . '")}');
            throw new LogicException('Admin CSS external-resource policy widened.');
        } catch (RuntimeException $error) { $checks++; }
    }
    echo "$checks custom CSS URL checks passed.\n";
} finally { $AP_CONFIG = $before; }
