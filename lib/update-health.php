<?php
declare(strict_types=1);

/** A short-lived, private challenge allows the updater through maintenance. */
function ap_update_probe(): string { return $GLOBALS['AP_UPDATE_PROBE'] ?? ''; }
function ap_update_gate(): void {
    if (PHP_SAPI === 'cli') return;
    $token = $_GET['__ap_update_probe'] ?? '';
    if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        $challenge = ap_private_read('update-probe');
        if (is_string($challenge['token'] ?? null) && ($challenge['expires'] ?? 0) >= time() && hash_equals($challenge['token'], $token)) $GLOBALS['AP_UPDATE_PROBE'] = $token;
    }
    ap_assert_path(AP_STORAGE . '/update-maintenance.php');
    if (is_file(AP_STORAGE . '/update-maintenance.php') && ap_update_probe() === '') {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 30');
        exit('AlternPress: update in progress.');
    }
}
function ap_update_probe_headers(): void {
    if (ap_update_probe() === '') return;
    $ready = PHP_VERSION_ID >= 80200;
    foreach (['mbstring', 'fileinfo', 'gd', 'session', 'json', 'zlib'] as $extension) $ready = $ready && extension_loaded($extension);
    // Do not claim new bytecode is running with an unobservable/stale opcode cache.
    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status(false);
        if (is_array($status) && !empty($status['opcache_enabled'])) $ready = $ready && (bool) ini_get('opcache.validate_timestamps') && (int) ini_get('opcache.revalidate_freq') === 0;
    } elseif (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) $ready = false;
    $release = require __DIR__ . '/update-release.php';
    if (!is_string($release) || !preg_match('/\A(?:source|[a-f0-9]{64})\z/D', $release)) throw new RuntimeException('Invalid code release identity.');
    header('X-AlternPress-Update-Protocol: 1');
    header('X-AlternPress-Update-Ready: ' . ($ready ? '1' : '0'));
    header('X-AlternPress-PHP: ' . PHP_VERSION_ID);
    header('X-AlternPress-Release: ' . $release);
}
