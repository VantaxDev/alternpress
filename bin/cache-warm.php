<?php
declare(strict_types=1);

// Private CLI renderer. Apache denies /bin; this guard also covers other servers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv) !== 6 || $argv[1] !== '--render') { fwrite(STDERR, "Internal AlternPress cache worker. Use alt-cli cache:warm.\n"); exit(2); }
[$script, $operation, $base, $origin, $language, $path] = $argv;
if (($base !== '' && (!str_starts_with($base, '/') || str_ends_with($base, '/') || str_contains($base, '//') || preg_match('~[\x00-\x20\x7f?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $base)))
    || !preg_match('/\A[a-z]{2}(?:-[A-Za-z]{2,8})?\z/D', $language)
    || strlen($path) > 512 || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) exit(2);
$parts = parse_url($origin);
if (!is_array($parts) || !filter_var($origin, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/')) exit(2);

define('AP_CACHE_WARM', true);
define('AP_CACHE_BASE_PATH', $base);
$_SERVER['HTTP_HOST'] = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$_SERVER['HTTPS'] = $parts['scheme'] === 'https' ? 'on' : 'off';
$_SERVER['SCRIPT_NAME'] = $base . '/index.php';
$_SERVER['REQUEST_URI'] = $base . '/' . $language . ($path === '/' ? '' : $path);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_ACCEPT_ENCODING'] = 'identity';
$_GET = $_POST = $_COOKIE = [];

// CLI output is suppressed even if rendering aborts before the cache finishing hook.
ob_start(static fn(string $output): string => '');
register_shutdown_function(static function() use ($language, $path): void {
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    try {
        if (!function_exists('ap_page_cache_fingerprint')) exit(1);
        $fingerprint = ap_page_cache_fingerprint();
        if ($fingerprint === null) exit(1);
        $manifest = ap_page_cache_manifest(ap_page_cache_key($language, $path), $fingerprint);
        if ($manifest === null || ap_page_cache_representation($manifest, 'identity') === null) exit(1);
        exit(0);
    } catch (Throwable) { exit(1); }
});
require dirname(__DIR__) . '/index.php';
