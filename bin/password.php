<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Exit codes: 0 saved, 2 invalid input, 3 input/terminal failure, 4 storage failure.
function ap_password_abort(string $reason, int $code): never {
    fwrite(STDERR, 'Password NOT CHANGED: ' . $reason . "\n");
    exit($code);
}
function ap_password_line(string $field): string {
    $line = fgets(STDIN);
    if ($line === false) throw new RuntimeException('cannot read ' . $field . ' (input ended or read failed).', 3);
    // Strip the line ending only; never trim password spaces or other bytes.
    return preg_replace('/\r?\n\z/D', '', $line) ?? $line;
}
$stdin = in_array('--stdin', $argv, true);
$terminalState = null;
$reason = null; $code = 3;
try {
    if (!$stdin) {
        fwrite(STDOUT, "New admin password (12–72 bytes; ASCII: 1 byte/character, UTF-8 may use more): ");
        if (stream_isatty(STDIN)) {
            if (PHP_OS_FAMILY === 'Windows' || !is_callable('exec')) throw new RuntimeException('cannot hide terminal input; use a private --stdin pipe.', 3);
            $state = []; $status = 1;
            exec('stty -g 2>/dev/null', $state, $status);
            if ($status !== 0 || count($state) !== 1 || $state[0] === '') throw new RuntimeException('cannot read terminal settings; use a private --stdin pipe.', 3);
            $terminalState = $state[0];
            exec('stty -echo 2>/dev/null', $ignored, $status);
            if ($status !== 0) throw new RuntimeException('cannot hide terminal input; use a private --stdin pipe.', 3);
        }
    }
    $password = ap_password_line('new password');
    if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('password must contain 12–72 bytes (not necessarily 12–72 characters).', 2);
    if (str_contains($password, "\0")) throw new RuntimeException('password must not contain a null byte.', 2);
    if (!$stdin) {
        fwrite(STDOUT, "\nRepeat password: ");
        $confirmation = ap_password_line('password confirmation');
        if (!hash_equals($password, $confirmation)) throw new RuntimeException('passwords do not match.', 2);
    }
} catch (Throwable $error) {
    // Only fixed messages are emitted; never print input or raw I/O errors.
    $reason = $error instanceof RuntimeException ? $error->getMessage() : 'cannot read password input.';
    $code = in_array($error->getCode(), [2, 3], true) ? $error->getCode() : 3;
} finally {
    if ($terminalState !== null) {
        exec('stty ' . escapeshellarg($terminalState) . ' 2>/dev/null', $ignored, $status);
        if ($status !== 0) { $reason = 'cannot restore terminal settings; run stty sane in your terminal.'; $code = 3; }
    }
    if (!$stdin) fwrite(STDOUT, "\n");
}
if ($reason !== null) ap_password_abort($reason, $code);
// Atomic storage replacement completes before success is reported. No raw
// storage errors are printed or logged by this command.
set_error_handler(static function(): never { throw new RuntimeException('Storage operation failed.'); });
try {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    require_once dirname(__DIR__) . '/lib/admin.php';
    ap_lock(static fn() => ap_set_password($password));
} catch (Throwable) {
    ap_password_abort('cannot save credentials; check storage permissions, lock and available disk space.', 4);
} finally { restore_error_handler(); }
fwrite(STDOUT, "Password CHANGED: saved successfully. Existing sessions are invalidated.\n");
