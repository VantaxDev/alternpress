<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function(Throwable $error): void {
        error_log('AlternPress: ' . get_class($error) . ': ' . $error->getMessage());
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); }
        echo 'AlternPress: service unavailable. Check the server error log.';
    });
}

define('AP_ROOT', dirname(__DIR__));
define('AP_STORAGE', AP_ROOT . '/storage');
const AP_EDITABLE_SETTINGS = ['name', 'logo_mark', 'email', 'legal', 'tagline', 'description', 'default_language', 'posts_per_page', 'search_indexing', 'site_url', 'custom_css'];
require_once __DIR__ . '/update-health.php';
ap_update_gate();
ap_assert_path(AP_ROOT . '/config.php');
$AP_CONFIG = require AP_ROOT . '/config.php';
ap_assert_path(AP_ROOT . '/config.local.php');
if (is_file(AP_ROOT . '/config.local.php')) {
    $AP_CONFIG = array_replace($AP_CONFIG, require AP_ROOT . '/config.local.php');
}
$AP_CONFIG = array_replace($AP_CONFIG, array_intersect_key(ap_private_read('settings')['values'] ?? [], array_flip(AP_EDITABLE_SETTINGS)));
date_default_timezone_set($AP_CONFIG['timezone']);
define('SUPPORTED_LANGUAGES', $AP_CONFIG['languages']);
define('DEFAULT_LANGUAGE', $AP_CONFIG['default_language']);
define('SITE_ORIGIN', ap_origin());

function ap_config(string $key): mixed { global $AP_CONFIG; return $AP_CONFIG[$key] ?? null; }
function ap_base(): string {
    if (ap_config('base_path') !== null) return rtrim((string) ap_config('base_path'), '/');
    if (PHP_SAPI === 'cli' && defined('AP_CACHE_WARM') && AP_CACHE_WARM && defined('AP_CACHE_BASE_PATH')) return AP_CACHE_BASE_PATH;
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = preg_replace('#/(?:admin/)?index\.php$#', '', $script);
    return PHP_SAPI === 'cli' ? '' : rtrim($base, '/');
}
function ap_url(string $path = '/'): string { return ap_base() . '/' . ltrim($path, '/'); }
function ap_canonical_url(string $language, string $relativePath = '/'): string {
    $configured = (string) ap_config('site_url');
    if ($configured === '') return SITE_ORIGIN . language_url($language, $relativePath);
    return rtrim($configured, '/') . '/' . $language . ($relativePath === '/' ? '' : '/' . trim($relativePath, '/'));
}
function ap_custom_css(): string {
    return preg_replace_callback('~url\(\s*([\'\"]?)(/[^)\'\"]*)\1\s*\)~i', static function(array $m): string {
        $path = trim($m[2]);
        if (ap_base() !== '' && $path !== ap_base() && !str_starts_with($path, ap_base() . '/')) $path = ap_url($path);
        return 'url(' . $m[1] . $path . $m[1] . ')';
    }, (string) ap_config('custom_css')) ?? '';
}
function ap_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function ap_origin(): string {
    $origin = rtrim((string) ap_config('origin'), '/');
    if ($origin !== '') return $origin;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/\A[a-z0-9.\-]+(?::[0-9]+)?\z/i', $host)) $host = 'localhost';
    return (ap_https() ? 'https://' : 'http://') . $host;
}
function ap_private_read(string $name, array $default = []): array {
    $file = ap_private_path($name);
    if (!is_file($file)) return $default;
    if (filesize($file) > 16777216) throw new RuntimeException('Private data exceeds the size limit.');
    $raw = file_get_contents($file);
    if (!is_string($raw) || !str_starts_with($raw, "<?php exit; ?>\n")) throw new RuntimeException('Invalid private data wrapper.');
    try { $result = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Invalid private JSON data.', 0, $e); }
    if (!is_array($result)) throw new RuntimeException('Invalid private data.');
    return $result;
}
/** Lexical containment plus no symbolic links or special files inside the site. */
function ap_assert_path(string $path, string $root = AP_ROOT): void {
    $base = rtrim(str_replace('\\', '/', AP_ROOT), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    if (($root !== $base && !str_starts_with($root, $base . '/')) || ($path !== $root && !str_starts_with($path, $root . '/')) || preg_match('/[\x00-\x1f\x7f]/', $path)) throw new RuntimeException('Path outside the site data boundary.');
    $relative = substr($path, strlen($base));
    if ($relative !== '' && preg_match('~(?:^|/)(?:\.{1,2})(?:/|$)|//|:$~', $relative)) throw new RuntimeException('Invalid local path.');
    $cursor = $base;
    if (is_link($cursor) || !is_dir($cursor)) throw new RuntimeException('Invalid site directory.');
    $parts = $relative === '' ? [] : explode('/', ltrim($relative, '/'));
    foreach ($parts as $i => $part) {
        if ($part === '' || str_contains($part, ':')) throw new RuntimeException('Invalid local path segment.');
        $cursor .= '/' . $part;
        clearstatcache(true, $cursor);
        if (is_link($cursor) || (file_exists($cursor) && !is_file($cursor) && !is_dir($cursor)) || ($i < count($parts) - 1 && file_exists($cursor) && !is_dir($cursor))) throw new RuntimeException('Symbolic links and special files are not allowed in site data paths.');
    }
}
function ap_private_path(string $name): string {
    if (!preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~D', $name)) throw new RuntimeException('Invalid private data name.');
    $path = AP_STORAGE . '/' . $name . '.php'; ap_assert_path($path, AP_STORAGE); return $path;
}
function ap_make_directory(string $dir): void {
    ap_assert_path($dir);
    if (is_dir($dir)) return;
    $pending = []; $parent = $dir;
    while (!is_dir($parent)) { $pending[] = $parent; $parent = dirname($parent); }
    $owner = stat($parent);
    foreach (array_reverse($pending) as $newDir) {
        if (!mkdir($newDir, 0750) && !is_dir($newDir)) throw new RuntimeException('Cannot create data directory.');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && (!chown($newDir, $owner['uid']) || !chgrp($newDir, $owner['gid']))) throw new RuntimeException('Cannot preserve data directory ownership.');
    }
}
function ap_atomic_write(string $path, string $data, int $mode = 0640): void {
    ap_assert_path($path);
    $dir = dirname($path);
    ap_make_directory($dir);
    $tmp = tempnam($dir, '.ap-');
    if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) { if (is_string($tmp) && is_file($tmp)) unlink($tmp); throw new RuntimeException('Cannot create temporary file in the destination.'); }
    try {
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) throw new RuntimeException('Cannot write file.');
        if (!chmod($tmp, $mode)) throw new RuntimeException('Cannot protect temporary data.');
        // A root-run CLI update must not make existing web data unreadable.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $ownership = stat(is_file($path) ? $path : $dir);
            if (!chown($tmp, $ownership['uid']) || !chgrp($tmp, $ownership['gid'])) throw new RuntimeException('Cannot preserve data ownership.');
        }
        ap_assert_path($path);
        if (!rename($tmp, $path)) throw new RuntimeException('Cannot replace file.');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function ap_private_write(string $name, array $data): void {
    ap_atomic_write(ap_private_path($name), "<?php exit; ?>\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
function ap_lock(callable $action): mixed {
    ap_make_directory(AP_STORAGE);
    ap_assert_path(AP_STORAGE . '/write.lock', AP_STORAGE);
    $handle = fopen(AP_STORAGE . '/write.lock', 'c');
    if (!$handle) throw new RuntimeException('Cannot open storage lock.');
    if (!chmod(AP_STORAGE . '/write.lock', 0640)) { fclose($handle); throw new RuntimeException('Cannot protect storage lock.'); }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $owner = stat(AP_STORAGE);
        if (!chown(AP_STORAGE . '/write.lock', $owner['uid']) || !chgrp(AP_STORAGE . '/write.lock', $owner['gid'])) { fclose($handle); throw new RuntimeException('Cannot preserve lock ownership.'); }
    }
    if (!flock($handle, LOCK_EX)) { fclose($handle); throw new RuntimeException('Cannot lock storage.'); }
    try { return $action(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}
function ap_id_valid(string $id): bool { return (bool) preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $id) && strlen($id) <= 100; }
function ap_published(array $meta): bool { return ($meta['status'] ?? 'published') === 'published'; }
function ap_image_url(string $value, string $language, string $id): string {
    if (preg_match('/\A@media\/([a-f0-9]{32}\.(?:jpg|png|webp))\z/', $value, $m)) return ap_url('/media/' . $m[1]);
    if (preg_match('#\Ahttps?://#i', $value)) return $value;
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return ap_url($value);
    return language_url($language, '/post/' . $id . '/' . rawurlencode(basename($value)));
}
function ap_rewrite_html(string $html): string {
    return preg_replace_callback('/\b(href|src)="([^"]*)"/', static function(array $m): string {
        $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/\A@media\/([a-f0-9]{32}\.(?:jpg|png|webp))\z/', $url, $media)) $url = ap_url('/media/' . $media[1]);
        elseif (str_starts_with($url, '/') && !str_starts_with($url, '//') && ap_base() !== '' && $url !== ap_base() && !str_starts_with($url, ap_base() . '/')) $url = ap_url($url);
        return $m[1] . '="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
    }, $html) ?? $html;
}

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    if (!ap_config('search_indexing')) header('X-Robots-Tag: noindex, nofollow');
}
