<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
$checks = 0;
function cache_expect(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo 'PASS ' . $message . "\n";
}
$available = ['br', 'gzip', 'identity'];
cache_expect(ap_page_cache_encoding(null, $available) === 'identity', 'absent Accept-Encoding selects uncompressed content');
cache_expect(ap_page_cache_encoding('', $available) === 'identity', 'empty Accept-Encoding selects uncompressed content');
cache_expect(ap_page_cache_encoding('br, gzip', $available) === 'br', 'Brotli wins an equally weighted compression preference');
cache_expect(ap_page_cache_encoding('gzip;q=1, br;q=0.5, identity;q=0', $available) === 'gzip', 'explicit encoding quality selects gzip');
cache_expect(ap_page_cache_encoding('br;q=0, gzip;q=1', $available) === 'gzip', 'explicit Brotli refusal is respected');
cache_expect(ap_page_cache_encoding('gzip;q=0, br;q=1', $available) === 'br', 'explicit gzip refusal is respected');
cache_expect(ap_page_cache_encoding('br;q=0, gzip;q=0', $available) === 'identity', 'identity remains available when compression is refused');
cache_expect(ap_page_cache_encoding('br;q=0, gzip;q=0, identity;q=0', $available) === null, 'no acceptable representation is reported');
cache_expect(ap_page_cache_encoding('*;q=0', $available) === null, 'wildcard refusal also excludes implicit identity');
cache_expect(ap_page_cache_encoding('br;q=0, *;q=1, identity;q=0', $available) === 'gzip', 'specific refusal overrides a wildcard preference');
cache_expect(ap_page_cache_encoding('*;q=1, identity;q=0', $available) === 'br', 'wildcard accepts supported compression');
cache_expect(ap_page_cache_encoding('identity;q=1, br;q=0, gzip;q=0', $available) === 'identity', 'explicit identity works');
cache_expect(ap_page_cache_encoding('BR;Q=1, GZIP;Q=0, identity;q=0', $available) === 'br', 'encoding names and quality parameters are case-insensitive');
cache_expect(ap_page_cache_encoding('compress, deflate', $available) === 'identity', 'unsupported encoding names do not prevent identity fallback');
cache_expect(ap_page_cache_encoding('br, gzip', ['gzip', 'identity']) === 'gzip', 'unavailable Brotli uses an acceptable fallback');
cache_expect(ap_page_cache_encoding('br, identity;q=0', ['identity']) === null, 'missing encoder never returns a forbidden representation');
echo "$checks page-cache negotiation checks passed.\n";
