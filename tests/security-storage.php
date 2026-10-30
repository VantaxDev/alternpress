<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/alternpress-storage-test-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700); mkdir($fixture . '/lib', 0700); mkdir($fixture . '/storage', 0700);
copy($source . '/config.php', $fixture . '/config.php');
foreach (['bootstrap.php', 'languages.php'] as $file) copy($source . '/lib/' . $file, $fixture . '/lib/' . $file);
require $fixture . '/lib/bootstrap.php';
$count = 0;
function storage_check(bool $value, string $label): void { global $count; if (!$value) throw new RuntimeException($label); $count++; echo 'PASS ' . $label . "\n"; }
function storage_reject(callable $action, string $label): void { try { $action(); } catch (RuntimeException) { storage_check(true, $label); return; } storage_check(false, $label); }
try {
    ap_private_write('history/example', ['message' => 'Private data']);
    storage_check(ap_private_read('history/example')['message'] === 'Private data', 'Private data roundtrip');
    storage_reject(fn() => ap_private_read('../config'), 'Private path traversal rejected');
    storage_reject(fn() => ap_private_write('../outside', []), 'Private write traversal rejected');
    storage_reject(fn() => ap_atomic_write(AP_ROOT . '/../outside', 'bad'), 'Atomic write traversal rejected');
    storage_reject(fn() => ap_atomic_write(AP_ROOT . '/storage//bad', 'bad'), 'Ambiguous path rejected');
    storage_reject(fn() => ap_atomic_write(AP_ROOT . '/storage/stream:bad', 'bad'), 'Alternate data stream path rejected');
    file_put_contents(AP_STORAGE . '/invalid.php', str_repeat('x', strlen("<?php exit; ?>\n")) . '{}');
    storage_reject(fn() => ap_private_read('invalid'), 'Unprotected JSON wrapper rejected');
    file_put_contents(AP_STORAGE . '/invalid.php', "<?php exit; ?>\n[invalid");
    storage_reject(fn() => ap_private_read('invalid'), 'Malformed private JSON rejected');
    file_put_contents(AP_STORAGE . '/invalid.php', "<?php exit; ?>\nnull");
    storage_reject(fn() => ap_private_read('invalid'), 'Private scalar JSON rejected');
    ap_lock(static fn() => ap_private_write('locked', ['ok' => true]));
    storage_check(ap_private_read('locked')['ok'], 'Locked writes remain functional');
    if (function_exists('symlink') && @symlink(AP_ROOT . '/config.php', AP_STORAGE . '/linked.php')) {
        $original = hash_file('sha256', AP_ROOT . '/config.php');
        storage_reject(fn() => ap_private_read('linked'), 'Symlinked private read rejected');
        storage_reject(fn() => ap_private_write('linked', ['bad' => true]), 'Symlinked private write rejected');
        symlink(AP_ROOT . '/lib', AP_STORAGE . '/linked-directory');
        storage_reject(fn() => ap_atomic_write(AP_STORAGE . '/linked-directory/injected.php', 'bad'), 'Symlinked parent rejected');
        unlink(AP_STORAGE . '/write.lock'); symlink(AP_ROOT . '/config.php', AP_STORAGE . '/write.lock');
        storage_reject(fn() => ap_lock(static fn() => true), 'Symlinked lock rejected');
        storage_check(hash_file('sha256', AP_ROOT . '/config.php') === $original, 'Targets unchanged after blocked operations');
    }
    echo "$count storage security checks passed.\n";
} finally {
    if (realpath($fixture) === $fixture && preg_match('~/alternpress-storage-test-[a-f0-9]{12}$~', $fixture)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($fixture);
    }
}
