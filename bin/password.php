<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin.php';
if (!function_exists('t')) { function t(string $it, string $en): string { return $en; } }
$stdin = in_array('--stdin', $argv, true);
if (!$stdin) {
    fwrite(STDOUT, "New admin password (12–72 bytes): ");
    if (function_exists('system') && PHP_OS_FAMILY !== 'Windows') system('stty -echo');
}
try {
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (!$stdin) {
        fwrite(STDOUT, "\nRepeat password: ");
        $confirmation = rtrim((string) fgets(STDIN), "\r\n");
        if (!hash_equals($password, $confirmation)) throw new RuntimeException('Passwords do not match.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "\n" . $error->getMessage() . "\n");
    $password = '';
}
finally {
    if (!$stdin) { if (function_exists('system') && PHP_OS_FAMILY !== 'Windows') system('stty echo'); fwrite(STDOUT, "\n"); }
}
try { ap_lock(static fn() => ap_set_password($password)); fwrite(STDOUT, "Password updated. Existing sessions are invalidated.\n"); }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
