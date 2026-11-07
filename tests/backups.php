<?php
declare(strict_types=1);
// Isolated integration checks. Run: php tests/backups.php. No site data is changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/alternpress-backup-unit-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
foreach (['lib', 'content/it/post/example', 'content/en/post/example', 'media', 'storage', 'cache'] as $folder) mkdir($fixture . '/' . $folder, 0700, true);
copy($source . '/config.php', $fixture . '/config.php');
foreach (['bootstrap.php', 'update-health.php', 'update-release.php', 'settings.php', 'admin.php', 'page-cache.php'] as $file) copy($source . '/lib/' . $file, $fixture . '/lib/' . $file);
require $fixture . '/lib/bootstrap.php';
require $fixture . '/lib/page-cache.php';
require $fixture . '/lib/admin.php';
require $source . '/lib/backups.php';
function t(string $it, string $en): string { return $en; }
$checks = 0;
function backup_expect(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'OK ' . $label . "\n"; }
function backup_reject(callable $action, string $label): void { try { $action(); } catch (Throwable $e) { backup_expect(true, $label); return; } backup_expect(false, $label); }
function backup_raw_archive(string $path, array $entries): void {
    $handle = gzopen($path, 'wb6');
    foreach ($entries as [$name, $data]) ap_backup_tar_add($handle, $name, $data, time());
    ap_backup_gzwrite($handle, str_repeat("\0", 1024)); gzclose($handle);
}
function backup_cleanup_fixture(string $fixture): void {
    if (!preg_match('~/alternpress-backup-unit-[a-f0-9]{12}\z~', $fixture) || !is_dir($fixture) || is_link($fixture)) throw new RuntimeException('Invalid test cleanup path.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
}
try {
    $original = "---\ntitle: Example\n---\n\nA sentence.\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQkAAAAASUVORK5CYII=');
    file_put_contents(AP_ROOT . '/content/it/post/example/index.md', $original);
    file_put_contents(AP_ROOT . '/content/en/post/example/index.md', $original);
    file_put_contents(AP_ROOT . '/content/it/post/example/photo.png', $png);
    file_put_contents(AP_ROOT . '/media/photo.png', $png);
    file_put_contents(AP_ROOT . '/content/.htaccess', 'Protected');
    file_put_contents(AP_ROOT . '/content/it/notes.txt', 'Unsupported file preserved');
    file_put_contents(AP_ROOT . '/index.php', '<?php echo "engine";');
    ap_private_write('auth', ['hash' => 'test-auth-stays']);
    $authHash = hash_file('sha256', AP_STORAGE . '/auth.php');
    $settings = ap_backup_effective_settings(); $settings['name'] = 'Original site';
    ap_private_write('settings', ['values' => $settings]);
    $backup = ap_backup_create();
    $backupPath = ap_backup_path($backup['name']);
    backup_expect($backup['documents'] === 2 && $backup['images'] === 2, 'Backup counts content and media');
    backup_expect($backup['skipped'] === ['content/it/notes.txt'], 'Unsupported files are reported');
    backup_expect(count(ap_backup_list()) === 1 && hash_file('sha256', $backupPath) === $backup['sha256'], 'Private list and archive checksum');
    backup_reject(fn() => ap_backup_path('../auth.php'), 'Download traversal rejected');
    $preview = ap_restore_prepare($backupPath);
    backup_expect(ap_restore_pending($preview['token'])['documents'] === 2, 'Preview validates generated backup');
    $settings['name'] = 'Changed directly on disk'; ap_private_write('settings', ['values' => $settings]);
    backup_reject(fn() => ap_restore_apply($preview['token']), 'Settings changes after preview reject restore');
    ap_restore_cancel($preview['token']);
    file_put_contents(AP_ROOT . '/content/it/post/example/index.md', 'New content');
    mkdir(AP_ROOT . '/content/it/post/later');
    file_put_contents(AP_ROOT . '/content/it/post/later/index.md', 'Added later');
    file_put_contents(AP_ROOT . '/media/later.png', $png);
    unlink(AP_ROOT . '/content/it/post/example/photo.png');
    file_put_contents(AP_ROOT . '/cache/index-it.json', 'stale');
    $preview = ap_restore_prepare($backupPath);
    backup_expect($preview['remove_documents'] === 1 && $preview['remove_images'] === 1 && $preview['changed'] === 1 && $preview['added'] === 1, 'Preview reports later removals and changed files');
    $result = ap_restore_apply($preview['token']);
    backup_expect(is_file(ap_backup_path($result['backup'])) && count(ap_backup_list()) === 2, 'Automatic backup precedes restore');
    backup_expect(file_get_contents(AP_ROOT . '/content/it/post/example/index.md') === $original && !file_exists(AP_ROOT . '/content/it/post/later/index.md'), 'Markdown snapshot restored exactly');
    backup_expect(file_get_contents(AP_ROOT . '/content/it/post/example/photo.png') === $png && !file_exists(AP_ROOT . '/media/later.png'), 'Photo snapshot restored exactly');
    backup_expect(ap_backup_effective_settings()['name'] === 'Original site', 'Effective settings restored');
    backup_expect(hash_file('sha256', AP_STORAGE . '/auth.php') === $authHash && file_get_contents(AP_ROOT . '/content/.htaccess') === 'Protected' && file_get_contents(AP_ROOT . '/content/it/notes.txt') === 'Unsupported file preserved' && file_get_contents(AP_ROOT . '/index.php') === '<?php echo "engine";', 'Access, code, protections and unsupported files preserved');
    backup_expect(!is_file(AP_ROOT . '/cache/index-it.json') && !is_dir(ap_restore_dir($preview['token'])), 'Cache and temporary restore files cleared');
    $preview = ap_restore_prepare($backupPath);
    file_put_contents(ap_restore_dir($preview['token']) . '/archive.tar.gz', 'changed');
    backup_reject(fn() => ap_restore_apply($preview['token']), 'Changed staged archive rejected');
    ap_restore_cancel($preview['token']);
    $bad = AP_STORAGE . '/bad.tar.gz';
    backup_raw_archive($bad, [['../outside.md', 'bad']]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Tar traversal rejected');
    backup_raw_archive($bad, [['content/it/evil.php', '<?php echo 1;']]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Executable entry rejected');
    foreach (['media/shell.php.jpg', 'media/shell.PHP5.png', 'media/shell.phtml.gif', 'media/shell.phar.webp'] as $name) {
        backup_raw_archive($bad, [[$name, $png]]);
        backup_reject(fn() => ap_restore_prepare($bad), 'Compound executable filename rejected: ' . $name);
    }
    backup_expect(ap_backup_kind('media/trip.photo.png') === 'image', 'Normal dotted image names remain supported');
    backup_reject(fn() => ap_backup_assert_path(AP_ROOT . '/media/../../outside'), 'Filesystem guard rejects traversal segments');
    backup_raw_archive($bad, [['content/it/a.md', 'one'], ['content/it/a.md', 'two']]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Duplicate archive entry rejected');
    backup_raw_archive($bad, [['content/it/a.md', "bad\0text"]]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Binary Markdown rejected');
    backup_raw_archive($bad, [['media/fake.png', 'not an image']]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Fake raster image rejected');
    $unsafeSettings = $settings; $unsafeSettings['custom_css'] = '@import "https://example.com/remote.css";';
    backup_raw_archive($bad, [['settings.json', ap_backup_json(['values' => $unsafeSettings])]]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Remote CSS in archived settings rejected');
    backup_raw_archive($bad, [['content/it/a.md', 'no manifest']]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Missing integrity manifest rejected');
    $manifest = ['format' => 'alternpress-data', 'version' => 1, 'created_at' => gmdate('c'), 'files' => []];
    backup_raw_archive($bad, [['manifest.json', ap_backup_json($manifest)], ['settings.json', ap_backup_json(['values' => $settings])]]);
    backup_reject(fn() => ap_restore_prepare($bad), 'Manifest checksum mismatch rejected');
    $header = ap_backup_tar_header('media/link.png', 0, time());
    $header[156] = '2'; $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
    $handle = gzopen($bad, 'wb6'); ap_backup_gzwrite($handle, $header . str_repeat("\0", 1024)); gzclose($handle);
    backup_reject(fn() => ap_restore_prepare($bad), 'Tar symlink rejected');
    $handle = gzopen($bad, 'wb6'); ap_backup_gzwrite($handle, ap_backup_tar_header('media/large.png', AP_BACKUP_MAX_FILE + 1, time())); gzclose($handle);
    backup_reject(fn() => ap_restore_prepare($bad), 'Oversized file rejected before allocation');
    copy($backupPath, $bad); $handle = fopen($bad, 'r+'); ftruncate($handle, 40); fclose($handle);
    backup_reject(fn() => ap_restore_prepare($bad), 'Truncated gzip rejected');
    if (function_exists('symlink') && @symlink(AP_ROOT . '/config.php', AP_ROOT . '/media/linked.png')) {
        backup_reject(fn() => ap_backup_create(), 'Live symlink blocks backup'); unlink(AP_ROOT . '/media/linked.png');
    }
    $testDocument = AP_ROOT . '/content/it/post/example/oversized.md';
    file_put_contents($testDocument, str_repeat('a', 2097153));
    backup_reject(fn() => ap_backup_create(), 'Backup creation rejects Markdown above restore limit');
    unlink($testDocument);
    file_put_contents($testDocument, "bad\0text");
    backup_reject(fn() => ap_backup_create(), 'Backup creation rejects binary Markdown');
    unlink($testDocument);
    file_put_contents(AP_ROOT . '/media/fake.png', 'not an image');
    backup_reject(fn() => ap_backup_create(), 'Backup creation rejects invalid raster images');
    unlink(AP_ROOT . '/media/fake.png');
    $preview = ap_restore_prepare($backupPath);
    backup_expect($preview['expires_at'] <= time() + 1800 && $preview['expires_at'] > time() + 1790, 'Preview expires after 30 minutes');
    $previewPath = ap_restore_dir($preview['token']) . '/preview.php';
    $preview['expires_at'] = 1;
    ap_atomic_write($previewPath, "<?php exit; ?>\n" . ap_backup_json($preview), 0600);
    backup_reject(fn() => ap_restore_pending($preview['token']), 'Expired preview rejected');
    $nextPreview = ap_restore_prepare($backupPath);
    backup_expect(!is_dir(ap_restore_dir($preview['token'])) && is_dir(ap_restore_dir($nextPreview['token'])), 'Next upload cleans abandoned expired previews');
    ap_restore_cancel($nextPreview['token']);
    $interrupted = ap_restore_prepare($backupPath);
    $interruptedDir = ap_restore_dir($interrupted['token']);
    ap_backup_mkdir($interruptedDir . '/rollback');
    ap_atomic_write($interruptedDir . '/rollback/evidence.txt', 'previous data', 0600);
    backup_reject(fn() => ap_restore_cancel($interrupted['token']), 'Cancellation preserves interrupted rollback data');
    backup_reject(fn() => ap_restore_apply($interrupted['token']), 'Retry cannot overwrite interrupted rollback data');
    backup_expect(file_get_contents($interruptedDir . '/rollback/evidence.txt') === 'previous data', 'Recovery evidence remains intact');
    ap_restore_remove_tree($interruptedDir);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        chown(AP_STORAGE, 65534); chgrp(AP_STORAGE, 65534); clearstatcache();
        $owned = ap_backup_create(); $ownedPath = ap_backup_path($owned['name']); clearstatcache();
        backup_expect(fileowner(dirname($ownedPath)) === 65534 && fileowner($ownedPath) === 65534 && fileowner($ownedPath . '.json') === 65534, 'Root CLI preserves storage owner for private backups');
        $ownedPreview = ap_restore_prepare($ownedPath); clearstatcache();
        backup_expect(fileowner(ap_restore_dir($ownedPreview['token'])) === 65534 && fileowner(ap_restore_dir($ownedPreview['token']) . '/archive.tar.gz') === 65534 && fileowner(ap_restore_dir($ownedPreview['token']) . '/data/content/it/post/example/index.md') === 65534, 'Root CLI preserves storage owner throughout restore staging');
        ap_restore_cancel($ownedPreview['token']);
    }
    echo "$checks backup and restore checks passed.\n";
} finally { backup_cleanup_fixture($fixture); }
