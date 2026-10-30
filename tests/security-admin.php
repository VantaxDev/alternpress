<?php
declare(strict_types=1);
// Run: php tests/security-admin.php. All writes are confined to a temporary site.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/alternpress-admin-security-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
foreach (['lib', 'storage', 'media', 'cache', 'sessions', 'content/it/post', 'content/en/post'] as $directory) mkdir($fixture . '/' . $directory, 0700, true);
copy($source . '/config.php', $fixture . '/config.php');
foreach (['bootstrap.php', 'settings.php', 'admin.php', 'page-cache.php'] as $file) copy($source . '/lib/' . $file, $fixture . '/lib/' . $file);
require $fixture . '/lib/bootstrap.php';
require $fixture . '/lib/page-cache.php';
require $fixture . '/lib/admin.php';
require $source . '/admin/maintenance-actions.php';
function t(string $it, string $en): string { return $en; }
$checks = 0;
function security_expect(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "PASS $label\n"; }
function security_reject(callable $action, string $label): void {
    try { $action(); } catch (RuntimeException) { security_expect(true, $label); return; }
    security_expect(false, $label);
}
try {
    session_save_path($fixture . '/sessions'); ap_session();
    security_expect(strlen(ap_csrf()) === 64, 'session creates a random CSRF token');
    $_POST = ['csrf' => ap_csrf()]; ap_check_csrf();
    $_POST = ['csrf' => []]; security_reject(fn() => ap_check_csrf(), 'array CSRF is rejected');
    $_SESSION['csrf'] = ''; $_POST = ['csrf' => '']; security_reject(fn() => ap_check_csrf(), 'missing session token cannot match empty input');
    ap_session();
    security_reject(fn() => ap_input_string(['password' => []], 'password'), 'array password is rejected without coercion');
    security_reject(fn() => ap_validate_document_input(['translation' => 'text']), 'malformed translation structure is rejected');
    security_reject(fn() => ap_validate_document_input(['translation' => ['it' => ['title' => []]]]), 'nested array is rejected before form redisplay');
    security_reject(fn() => ap_upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => []]), 'malformed upload rejected without type error');
    security_reject(fn() => ap_clean_line("\xff"), 'invalid metadata encoding rejected');
    security_reject(fn() => ap_set_password("abcdefghijk\0lmn"), 'null-byte password rejected');
    $password = str_repeat('A', 72); ap_set_password($password);
    $auth = ap_auth_data(); security_expect(isset($auth['version'], $auth['hash']), 'valid credential record accepted');
    security_expect(!ap_login($password . 'B'), 'bcrypt truncation cannot authenticate a longer password');
    security_expect(ap_login($password), 'exact password authenticates and rotates session');
    security_expect(ap_logged_in(), 'fresh authenticated session accepted');
    $_SESSION['last_seen'] = time() + 86400; security_expect(!ap_logged_in(), 'future session timestamp fails closed');
    $_SESSION['last_seen'] = []; $_SESSION['auth_version'] = $auth['version']; security_expect(!ap_logged_in(), 'malformed timestamp fails closed');
    $_SESSION['last_seen'] = time(); $_SESSION['auth_version'] = []; security_expect(!ap_logged_in(), 'malformed auth version fails closed');
    $_SESSION['last_seen'] = time() - 86400 * 366; $_SESSION['auth_version'] = $auth['version']; security_expect(!ap_logged_in(), 'expired session rejected');
    security_reject(fn() => ap_admin_maintenance_action('restore_apply'), 'maintenance independently rejects unauthenticated requests');
    security_reject(fn() => ap_admin_download_backup('not-a-backup'), 'backup download independently checks authentication');
    $_SESSION['last_seen'] = time(); $_SESSION['auth_version'] = $auth['version']; $_POST = ['csrf' => 'wrong'];
    security_reject(fn() => ap_admin_maintenance_action('restore_apply'), 'maintenance independently checks CSRF');
    ap_private_write('auth', ['version' => $auth['version'], 'hash' => []]);
    security_expect(!ap_logged_in(), 'malformed credential hash invalidates session');
    ap_private_write('auth', ['version' => [], 'hash' => $auth['hash']]); security_expect(ap_auth_data() === [], 'malformed credential version rejected');
    file_put_contents(AP_STORAGE . '/auth.php', 'corrupt'); security_expect(ap_auth_data() === [], 'corrupt private credentials fail closed');
    if (function_exists('symlink')) {
        symlink(AP_STORAGE, AP_ROOT . '/content/it/post/linked');
        security_reject(fn() => ap_post_path('it', 'linked'), 'document path rejects symlinked ancestor');
        unlink(AP_ROOT . '/content/it/post/linked');
        file_put_contents(AP_STORAGE . '/untouched', 'sentinel'); symlink(AP_STORAGE . '/untouched', AP_ROOT . '/cache/posts-linked.json');
        security_reject(fn() => ap_clear_post_cache(), 'cache clear rejects linked file');
        security_expect(file_get_contents(AP_STORAGE . '/untouched') === 'sentinel', 'cache cleanup leaves outside target unchanged');
    }
    ap_logout(); security_expect($_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE, 'logout destroys session state');
    echo "Admin security: $checks checks passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (!preg_match('~/alternpress-admin-security-[a-f0-9]{12}\z~', $fixture) || is_link($fixture)) throw new RuntimeException('Invalid fixture cleanup path.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture); ob_end_flush();
}
