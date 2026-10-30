<?php
declare(strict_types=1);

/** Private, validated HTML generations. The web server never serves these files directly. */
const AP_PAGE_CACHE_MAX_BODY = 8388608;
const AP_PAGE_CACHE_MAX_PAGES = 512;
const AP_PAGE_CACHE_MAX_SOURCE = 67108864;

function ap_page_cache_warming(): bool {
    return PHP_SAPI === 'cli' && defined('AP_CACHE_WARM') && AP_CACHE_WARM === true;
}

function ap_page_cache_directory(): string {
    $directory = AP_ROOT . '/cache/pages';
    ap_assert_path($directory);
    return $directory;
}

function ap_page_cache_route(string $language, string $path): bool {
    global $CONTENT_ROOT;
    if (!in_array($language, SUPPORTED_LANGUAGES, true) || strlen($path) > 512) return false;
    if (in_array($path, ['/', '/skills', '/about', '/archive', '/tags'], true)) return route_content_exists($CONTENT_ROOT, $language, $path);
    if (preg_match('~\A/post/([a-z0-9]+(?:-[a-z0-9]+)*)\z~D', $path, $match)) return ap_id_valid($match[1]) && route_content_exists($CONTENT_ROOT, $language, $path);
    if (preg_match('~\A/tag/([^/]+)\z~uD', $path, $match)) {
        $tag = rawurldecode($match[1]);
        if ($tag === '' || !preg_match('/\A[\p{L}\p{N}_-]+\z/uD', $tag) || $path !== '/tag/' . rawurlencode($tag)) return false;
        // Only actual public topics can allocate entries; arbitrary tag URLs cannot fill the cache.
        foreach (build_posts_index($CONTENT_ROOT . '/' . $language, $language) as $post) if (in_array($tag, $post['tags'], true)) return true;
    }
    return false;
}

function ap_page_cache_eligible(string $language, string $path): bool {
    if (!ap_config('page_cache') || (PHP_SAPI === 'cli' && !ap_page_cache_warming())) return false;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return false;
    if (($_SERVER['QUERY_STRING'] ?? '') !== '' || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '?')) return false;
    if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER'])) return false;
    $expected = ap_url('/' . $language . ($path === '/' ? '' : $path));
    if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) !== $expected) return false;
    $status = http_response_code();
    return ($status === false || $status === 200) && ap_page_cache_route($language, $path);
}

function ap_page_cache_key(string $language, string $path): string {
    return hash('sha256', json_encode([SITE_ORIGIN, ap_base(), ap_config('site_url'), $language, $path], JSON_THROW_ON_ERROR));
}

/** Content bytes (not just timestamps) detect same-second and preserved-mtime edits. */
function ap_page_cache_fingerprint(): ?string {
    global $AP_CONFIG, $CONTENT_ROOT;
    try {
        $context = hash_init('sha256');
        hash_update($context, "AlternPress page cache v1\0" . json_encode([$AP_CONFIG, SITE_ORIGIN, ap_base(), date('Y')], JSON_THROW_ON_ERROR));
        $binary = ap_config('brotli_binary');
        $encoder = ['php-brotli' => function_exists('brotli_compress') ? phpversion('brotli') ?: 'available' : null,
            'gzip' => function_exists('gzencode') ? (defined('ZLIB_VERSION') ? ZLIB_VERSION : 'available') : null];
        if (is_string($binary) && $binary !== '' && !str_contains($binary, "\0")) {
            clearstatcache(true, $binary);
            $encoder['binary'] = is_file($binary) && is_executable($binary) ? [realpath($binary), filesize($binary), filemtime($binary)] : null;
        }
        hash_update($context, json_encode($encoder, JSON_THROW_ON_ERROR));
        $paths = [];
        foreach (SUPPORTED_LANGUAGES as $language) {
            if (!is_string($language) || !preg_match('/\A[a-z]{2}(?:-[A-Za-z]{2,8})?\z/D', $language)) return null;
            $root = $CONTENT_ROOT . '/' . $language;
            foreach (['home', 'skills', 'about'] as $page) $paths[] = $root . '/' . $page . '/index.md';
            // Directory presence also affects empty archives and language navigation.
            hash_update($context, $root . '/post:' . (public_path_ok($root . '/post', true) ? 'present' : 'absent'));
            foreach (list_post_dirs($root . '/post') as $directory) $paths[] = $directory . '/index.md';
        }
        foreach (['index.php', 'config.php', 'config.local.php', '.htaccess', 'storage/settings.php'] as $file) $paths[] = AP_ROOT . '/' . $file;
        foreach (['lib', 'assets'] as $directory) {
            $root = AP_ROOT . '/' . $directory;
            ap_assert_path($root);
            if (!is_dir($root)) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            $count = 0;
            foreach ($iterator as $file) {
                // Photo contents do not change generated HTML; their URLs are already in Markdown.
                $extensions = $directory === 'lib' ? ['php'] : ['css', 'js', 'svg'];
                if (!in_array(strtolower($file->getExtension()), $extensions, true)) continue;
                if (++$count > 2048) return null;
                ap_assert_path($file->getPathname());
                if ($file->isFile()) $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);
        $total = 0;
        foreach (array_unique($paths) as $path) {
            ap_assert_path($path);
            clearstatcache(true, $path);
            hash_update($context, "\0" . $path . "\0");
            if (!is_file($path)) { hash_update($context, 'absent'); continue; }
            $size = filesize($path);
            if (!is_int($size) || $size > AP_PAGE_CACHE_MAX_BODY || ($total += $size) > AP_PAGE_CACHE_MAX_SOURCE) return null;
            $bytes = file_get_contents($path, false, null, 0, AP_PAGE_CACHE_MAX_BODY + 1);
            if (!is_string($bytes) || strlen($bytes) !== $size) return null;
            hash_update($context, (string) filemtime($path) . "\0" . $bytes);
        }
        return hash_final($context);
    } catch (Throwable) { return null; }
}

/** Highest acceptable quality; explicit exclusions override wildcard offers. */
function ap_page_cache_encoding(?string $header, array $available): ?string {
    if ($header === null || trim($header) === '') return in_array('identity', $available, true) ? 'identity' : null;
    if (strlen($header) > 8192) return in_array('identity', $available, true) ? 'identity' : null;
    $quality = [];
    foreach (explode(',', strtolower($header)) as $item) {
        $parts = array_map('trim', explode(';', trim($item)));
        $coding = array_shift($parts);
        if (!in_array($coding, ['br', 'gzip', 'identity', '*'], true)) continue;
        $q = 1.0;
        if ($parts !== []) {
            if (count($parts) !== 1 || !preg_match('/\Aq\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/D', $parts[0], $match)) $q = 0.0;
            else $q = (float) $match[1];
        }
        $quality[$coding] = isset($quality[$coding]) ? min($quality[$coding], $q) : $q;
    }
    $winner = null; $best = 0.0;
    foreach (['br', 'gzip', 'identity'] as $coding) {
        if (!in_array($coding, $available, true)) continue;
        $q = $quality[$coding] ?? ($coding === 'identity' ? (($quality['*'] ?? 1.0) === 0.0 ? 0.0 : 1.0) : ($quality['*'] ?? 0.0));
        if ($q > $best) { $winner = $coding; $best = $q; }
    }
    return $winner;
}

function ap_page_cache_etag_matches(string $header, string $etag): bool {
    if (strlen($header) > 8192) return false;
    foreach (explode(',', $header) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*') return true;
        if (str_starts_with($candidate, 'W/')) $candidate = substr($candidate, 2);
        if (hash_equals($etag, $candidate)) return true;
    }
    return false;
}

function ap_page_cache_manifest(string $key, string $fingerprint): ?array {
    try {
        $file = ap_page_cache_directory() . '/' . $key . '.json';
        ap_assert_path($file);
        if (!is_file($file) || filesize($file) > 65536) return null;
        $manifest = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ($manifest['fingerprint'] ?? '') !== $fingerprint || ($manifest['key'] ?? '') !== $key) return null;
        if (!is_string($manifest['csp'] ?? null) || strlen($manifest['csp']) > 32768 || preg_match('/[\r\n\0]/', $manifest['csp'])) return null;
        if (!is_array($manifest['representations'] ?? null) || !isset($manifest['representations']['identity'])) return null;
        foreach ($manifest['representations'] as $encoding => $representation) {
            $extension = ['identity' => 'html', 'br' => 'br', 'gzip' => 'gz'][$encoding] ?? null;
            if ($extension === null || !is_array($representation) || !is_string($representation['hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $representation['hash']) || ($representation['file'] ?? '') !== $representation['hash'] . '.' . $extension || !is_int($representation['size'] ?? null) || $representation['size'] < 1 || $representation['size'] > AP_PAGE_CACHE_MAX_BODY + 65536) return null;
        }
        return $manifest;
    } catch (Throwable) { return null; }
}

function ap_page_cache_representation(array $manifest, string $encoding): ?string {
    try {
        $representation = $manifest['representations'][$encoding] ?? null;
        if (!is_array($representation)) return null;
        $file = ap_page_cache_directory() . '/' . $representation['file'];
        ap_assert_path($file);
        if (!is_file($file) || filesize($file) !== $representation['size']) return null;
        $bytes = file_get_contents($file, false, null, 0, AP_PAGE_CACHE_MAX_BODY + 65537);
        return is_string($bytes) && strlen($bytes) === $representation['size'] && hash_equals($representation['hash'], hash('sha256', $bytes)) ? $bytes : null;
    } catch (Throwable) { return null; }
}

function ap_page_cache_emit(string $bytes, string $encoding, string $csp, string $status): void {
    if (ap_page_cache_warming()) return;
    if (function_exists('apache_setenv')) { apache_setenv('no-brotli', '1'); apache_setenv('no-gzip', '1'); }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . $csp);
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('Vary: Accept-Encoding', false);
    header('X-AlternPress-Cache: ' . $status);
    if ($encoding !== 'identity') header('Content-Encoding: ' . $encoding);
    if ($encoding === 'br') header('X-AlternPress-Encoding: br; quality=11');
    $etag = '"' . hash('sha256', $bytes) . '-' . $encoding . '"';
    header('ETag: ' . $etag);
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && (http_response_code() === false || http_response_code() === 200) && ap_page_cache_etag_matches((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
        http_response_code(304); header_remove('Content-Length'); return;
    }
    // Range is intentionally ignored: full representations avoid ambiguous compressed byte offsets.
    header('Accept-Ranges: none');
    header('Content-Length: ' . strlen($bytes));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $bytes;
}

function ap_page_cache_unacceptable(): void {
    if (ap_page_cache_warming()) return;
    if (function_exists('apache_setenv')) { apache_setenv('no-brotli', '1'); apache_setenv('no-gzip', '1'); }
    http_response_code(406);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Vary: Accept-Encoding', false);
    header('Content-Length: 0');
    header('X-AlternPress-Cache: BYPASS');
}

function ap_page_cache_try_serve(string $language, string $path): void {
    $GLOBALS['AP_PAGE_CACHE_REQUEST'] = null;
    try {
        if (!ap_page_cache_eligible($language, $path)) return;
        $fingerprint = ap_page_cache_fingerprint();
        if ($fingerprint === null) return;
        $key = ap_page_cache_key($language, $path);
        $GLOBALS['AP_PAGE_CACHE_REQUEST'] = ['language' => $language, 'path' => $path, 'key' => $key, 'fingerprint' => $fingerprint];
        $manifest = ap_page_cache_manifest($key, $fingerprint);
        if ($manifest === null) return;
        $encoding = ap_page_cache_encoding($_SERVER['HTTP_ACCEPT_ENCODING'] ?? null, array_keys($manifest['representations']));
        if ($encoding === null) { ap_page_cache_unacceptable(); exit; }
        $bytes = ap_page_cache_representation($manifest, $encoding);
        if ($bytes === null) return;
        $current = ap_page_cache_fingerprint();
        if ($current === null || !hash_equals($fingerprint, $current) || !ap_page_cache_route($language, $path)) return;
        ap_page_cache_emit($bytes, $encoding, $manifest['csp'], 'HIT');
        exit;
    } catch (Throwable) { $GLOBALS['AP_PAGE_CACHE_REQUEST'] = null; }
}

/** Independent cache lock: never recursively takes the content/storage write lock. */
function ap_page_cache_lock(): mixed {
    $directory = ap_page_cache_directory();
    ap_make_directory($directory);
    $path = $directory . '/write.lock'; ap_assert_path($path);
    $handle = fopen($path, 'c');
    if ($handle === false) return null;
    @chmod($path, 0640);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $owner = stat($directory);
        if (!chown($path, $owner['uid']) || !chgrp($path, $owner['gid'])) { fclose($handle); return null; }
    }
    $deadline = microtime(true) + 2.0;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        usleep(10000);
    } while (microtime(true) < $deadline);
    fclose($handle);
    return null;
}

/** Execute an argument array directly, with no shell and a bounded lifetime. */
function ap_page_cache_process(array $arguments, string $output, float $timeout): bool {
    if (!function_exists('proc_open') || !function_exists('proc_get_status')) return false;
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = @proc_open($arguments, [0 => ['file', $null, 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $null, 'w']], $pipes, AP_ROOT, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return false;
    $deadline = microtime(true) + $timeout;
    $exit = -1;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        if (microtime(true) >= $deadline) { proc_terminate($process); usleep(50000); $status = proc_get_status($process); if ($status['running']) proc_terminate($process, 9); break; }
        usleep(10000);
    } while (true);
    $closed = proc_close($process);
    return $exit === 0 || ($exit === -1 && $closed === 0);
}

function ap_page_cache_brotli(string $html, string $sourceFile): ?string {
    try {
        if (function_exists('brotli_compress')) {
            $encoded = brotli_compress($html, 11);
            if (is_string($encoded) && $encoded !== '' && strlen($encoded) <= AP_PAGE_CACHE_MAX_BODY + 65536) return $encoded;
        }
        $binary = ap_config('brotli_binary');
        if (!is_string($binary) || $binary === '' || str_contains($binary, "\0") || !preg_match('~\A(?:/|[A-Za-z]:[\\\\/])~', $binary) || !is_file($binary) || !is_executable($binary)) return null;
        $temporary = tempnam(ap_page_cache_directory(), '.ap-br-');
        if ($temporary === false) return null;
        try {
            ap_assert_path($temporary);
            if (!ap_page_cache_process([$binary, '--quality=11', '--stdout', $sourceFile], $temporary, 12.0)) return null;
            clearstatcache(true, $temporary);
            if (!is_file($temporary) || filesize($temporary) < 1 || filesize($temporary) > AP_PAGE_CACHE_MAX_BODY + 65536) return null;
            $encoded = file_get_contents($temporary, false, null, 0, AP_PAGE_CACHE_MAX_BODY + 65537);
            return is_string($encoded) && $encoded !== '' ? $encoded : null;
        } finally { if (isset($temporary) && is_file($temporary) && !is_link($temporary)) unlink($temporary); }
    } catch (Throwable) { return null; }
}

function ap_page_cache_store_bytes(string $bytes, string $extension): array {
    $hash = hash('sha256', $bytes);
    $file = $hash . '.' . $extension;
    $path = ap_page_cache_directory() . '/' . $file;
    ap_assert_path($path);
    // Replacing a corrupt content-addressed object is safe and atomic.
    if (!is_file($path) || filesize($path) !== strlen($bytes) || hash_file('sha256', $path) !== $hash) ap_atomic_write($path, $bytes, 0640);
    return ['file' => $file, 'hash' => $hash, 'size' => strlen($bytes)];
}

/** Called only with the cache write lock held; never follows filesystem links. */
function ap_page_cache_prune(): void {
    $directory = ap_page_cache_directory(); $used = [];
    foreach (glob($directory . '/*.json') ?: [] as $file) {
        ap_assert_path($file);
        if (!is_file($file) || filesize($file) > 65536) continue;
        $manifest = json_decode((string) file_get_contents($file), true);
        $representations = is_array($manifest['representations'] ?? null) ? $manifest['representations'] : [];
        foreach ($representations as $representation) if (is_array($representation) && is_string($representation['file'] ?? null)) $used[$representation['file']] = true;
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        $name = basename($file);
        if (!preg_match('/\A[a-f0-9]{64}\.(?:html|br|gz)\z/D', $name) || isset($used[$name])) continue;
        ap_assert_path($file);
        if (is_file($file)) unlink($file);
    }
}

function ap_page_cache_finish(string $language, string $path, string $html, string $csp): void {
    $request = $GLOBALS['AP_PAGE_CACHE_REQUEST'] ?? null;
    $representations = ['identity' => $html]; $status = 'BYPASS'; $lock = null;
    try {
        $eligible = ap_page_cache_eligible($language, $path) && strlen($html) > 0 && strlen($html) <= AP_PAGE_CACHE_MAX_BODY;
        if ($eligible && is_array($request) && $request['language'] === $language && $request['path'] === $path && !preg_match('/[\r\n\0]/', $csp) && strlen($csp) <= 32768) {
            $fingerprint = ap_page_cache_fingerprint();
            if ($fingerprint !== null && hash_equals($request['fingerprint'], $fingerprint)) {
                $lock = ap_page_cache_lock();
                if (is_resource($lock)) {
                    $manifest = ap_page_cache_manifest($request['key'], $fingerprint);
                    if ($manifest !== null) {
                        $available = [];
                        foreach ($manifest['representations'] as $encoding => $_) {
                            $bytes = ap_page_cache_representation($manifest, $encoding);
                            if ($bytes !== null) $available[$encoding] = $bytes;
                        }
                        if (isset($available['identity']) && $available['identity'] === $html && count($available) === count($manifest['representations'])) { $representations = $available; $status = 'HIT'; }
                    }
                    if ($status !== 'HIT') {
                        $directory = ap_page_cache_directory();
                        $manifestFile = $directory . '/' . $request['key'] . '.json'; ap_assert_path($manifestFile);
                        if (is_file($manifestFile) || count(glob($directory . '/*.json') ?: []) < AP_PAGE_CACHE_MAX_PAGES) {
                            $entries = ['identity' => ap_page_cache_store_bytes($html, 'html')];
                            $brotli = ap_page_cache_brotli($html, $directory . '/' . $entries['identity']['file']);
                            if ($brotli !== null) { $representations['br'] = $brotli; $entries['br'] = ap_page_cache_store_bytes($brotli, 'br'); }
                            if (function_exists('gzencode')) {
                                $gzip = gzencode($html, 9);
                                if (is_string($gzip) && $gzip !== '') { $representations['gzip'] = $gzip; $entries['gzip'] = ap_page_cache_store_bytes($gzip, 'gz'); }
                            }
                            // If content changed while encoding, publish no stale generation.
                            $current = ap_page_cache_fingerprint();
                            if ($current !== null && hash_equals($fingerprint, $current)) {
                                $manifest = ['version' => 1, 'key' => $request['key'], 'fingerprint' => $fingerprint, 'csp' => $csp, 'representations' => $entries];
                                ap_atomic_write($manifestFile, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0640);
                                $status = 'MISS';
                            }
                            ap_page_cache_prune();
                        }
                    }
                }
            }
        }
    } catch (Throwable) {
        // Read-only, corrupt or unavailable cache must not take the public site down.
        $status = 'BYPASS';
    } finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    if (is_array($request) && $request['language'] === $language && $request['path'] === $path && !ap_page_cache_route($language, $path)) {
        // A concurrent publication change must not transmit a newly private article.
        http_response_code(404); $representations = ['identity' => '404 Not Found']; $status = 'BYPASS';
    }
    if (!isset($representations['gzip']) && function_exists('gzencode')) {
        $gzip = gzencode($representations['identity'], 9);
        if (is_string($gzip)) $representations['gzip'] = $gzip;
    }
    $encoding = ap_page_cache_encoding($_SERVER['HTTP_ACCEPT_ENCODING'] ?? null, array_keys($representations));
    if ($encoding === null) { ap_page_cache_unacceptable(); return; }
    ap_page_cache_emit($representations[$encoding], $encoding, $csp, $status);
}

function ap_page_cache_clear(): int {
    $lock = null; $count = 0;
    try {
        $directory = ap_page_cache_directory();
        if (!is_dir($directory)) return 0;
        $lock = ap_page_cache_lock();
        if (!is_resource($lock)) return 0;
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!preg_match('/\A[a-f0-9]{64}\.(?:json|html|br|gz)\z/D', basename($file))) continue;
            ap_assert_path($file);
            if (is_file($file) && unlink($file)) $count++;
        }
    } catch (Throwable) { /* Source fingerprints still invalidate any entries that could not be removed. */ }
    finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    return $count;
}

function ap_page_cache_php(): ?string {
    foreach ([PHP_BINARY, PHP_BINDIR . '/php'] as $candidate) {
        if (preg_match('/\Aphp(?:[0-9.]+)?(?:\.exe)?\z/iD', basename($candidate)) && is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    return null;
}

function ap_page_cache_warm_route(string $language, string $path): bool {
    $php = ap_page_cache_php();
    if ($php === null || !ap_config('page_cache') || !ap_page_cache_route($language, $path)) return false;
    $origin = SITE_ORIGIN; $base = ap_base();
    if (PHP_SAPI === 'cli' && !ap_page_cache_warming() && (string) ap_config('origin') === '' && (string) ap_config('site_url') === '') return false;
    if (PHP_SAPI === 'cli' && !ap_page_cache_warming() && (string) ap_config('origin') === '' && (string) ap_config('site_url') !== '') {
        $canonical = parse_url((string) ap_config('site_url'));
        if (is_array($canonical) && isset($canonical['scheme'], $canonical['host'])) {
            $origin = $canonical['scheme'] . '://' . $canonical['host'] . (isset($canonical['port']) ? ':' . $canonical['port'] : '');
            if (ap_config('base_path') === null) $base = rtrim($canonical['path'] ?? '', '/');
        }
    }
    $worker = AP_ROOT . '/bin/cache-warm.php';
    try { ap_assert_path($worker); if (!is_file($worker)) return false; }
    catch (Throwable) { return false; }
    return ap_page_cache_process([$php, $worker, '--render', $base, $origin, $language, $path], PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 25.0);
}

function ap_page_cache_warm_document(string $id, string $kind): void {
    if (!ap_config('page_cache') || !ap_id_valid($id)) return;
    $path = $kind === 'page' ? ($id === 'home' ? '/' : '/' . $id) : '/post/' . $id;
    $deadline = microtime(true) + 90.0;
    foreach (SUPPORTED_LANGUAGES as $language) {
        foreach (array_unique([$path, '/', '/archive', '/tags']) as $route) {
            if (microtime(true) > $deadline) return;
            try { if (ap_page_cache_route($language, $route)) ap_page_cache_warm_route($language, $route); }
            catch (Throwable) { /* A warmer failure leaves ordinary on-request regeneration available. */ }
        }
    }
}

function ap_page_cache_warm_all(): int {
    global $CONTENT_ROOT;
    if (PHP_SAPI !== 'cli' || !ap_config('page_cache')) return 0;
    $count = 0; $attempts = 0; $deadline = microtime(true) + 300.0;
    foreach (SUPPORTED_LANGUAGES as $language) {
        $routes = ['/', '/skills', '/about', '/archive', '/tags'];
        foreach (build_posts_index($CONTENT_ROOT . '/' . $language, $language) as $post) {
            $routes[] = '/post/' . $post['id'];
            foreach ($post['tags'] as $tag) $routes[] = '/tag/' . rawurlencode($tag);
        }
        foreach (array_unique($routes) as $path) {
            if (++$attempts > AP_PAGE_CACHE_MAX_PAGES || microtime(true) > $deadline) return $count;
            try { if (ap_page_cache_warm_route($language, $path)) $count++; }
            catch (Throwable) { /* Continue with other public pages. */ }
        }
    }
    return $count;
}
