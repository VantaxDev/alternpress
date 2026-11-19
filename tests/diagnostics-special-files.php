<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Unix special files are not portable to Windows; fixtures never use site data.
if (DIRECTORY_SEPARATOR === '\\') {
    echo "SKIP diagnostics special files: Unix fixtures required.\n";
    exit;
}
$root = sys_get_temp_dir() . '/alternpress-special-files-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
function special_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function special_remove(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') special_remove($path . '/' . $name);
    rmdir($path);
}
try {
    foreach (['content', 'cache', 'storage', 'media', 'assets'] as $directory) mkdir($root . '/' . $directory);
    file_put_contents($root . '/media/unused.jpg', 'synthetic image');
    $options = ['root' => $root, 'max_seconds' => 30];
    $healthy = ap_diagnostics($options);
    special_check($healthy['complete'] && $healthy['summary']['orphans'] === 1, 'Normal inventory offers orphan candidates.');
    foreach (['content', 'cache', 'storage', 'media', 'assets'] as $directory) {
        foreach (['fifo', 'socket'] as $type) {
            $relative = $directory . '/special.' . ($directory === 'content' ? 'md' : 'css');
            $path = $root . '/' . $relative;
            $socket = null;
            if ($type === 'fifo') {
                special_check(posix_mkfifo($path, 0600), 'FIFO fixture created.');
            } else {
                $socket = stream_socket_server('unix://' . $path, $errorCode, $errorMessage);
                special_check($socket !== false, 'Socket fixture created.');
            }
            try {
                $report = ap_diagnostics($options);
                special_check(!$report['complete'], "$type in $directory must make the scan incomplete.");
                special_check($report['orphans'] === [] && $report['summary']['orphans'] === 0, 'Partial inventory suppresses orphan candidates.');
                $issues = array_values(array_filter($report['issues'], static fn($issue) => $issue['code'] === 'unsupported_file_type'));
                special_check(count($issues) === 1 && $issues[0]['path'] === $relative && $issues[0]['severity'] === 'warning', 'One warning identifies the unsupported path.');
                special_check($report['summary']['warnings'] === 1 && $report['summary']['errors'] === 0, 'Special-file warning is counted once.');
                special_check(!in_array($relative, array_column($report['permissions'], 'path'), true), 'Special files are not inventoried as ordinary files.');
                special_check($report['summary']['markdown'] === 0, 'Special Markdown paths are never read.');
                special_check(in_array('Incomplete scan: no reliable orphan-photo list is available.', $report['notes'], true), 'Partial inventory is explained.');
                clearstatcache();
                special_check(filetype($path) === $type, 'Diagnostics preserves the special file.');
            } finally {
                if (is_resource($socket)) fclose($socket);
                unlink($path);
            }
            $restored = ap_diagnostics($options);
            special_check($restored['complete'] && $restored['summary']['orphans'] === 1, 'Removing special file restores complete inventory.');
        }
    }
    // Interaction with PR18: required-path type errors take precedence over
    // the new nested-entry warning; a separate special entry is still reported.
    foreach (['content', 'cache', 'storage', 'media'] as $directory) {
        rename($root . '/' . $directory, $root . '/held');
        special_check(posix_mkfifo($root . '/' . $directory, 0600), 'Required-path FIFO fixture created.');
        special_check(posix_mkfifo($root . '/assets/nested.css', 0600), 'Adjacent nested FIFO fixture created.');
        try {
            $mixed = ap_diagnostics($options);
            special_check(!$mixed['complete'] && $mixed['orphans'] === [] && $mixed['summary']['orphans'] === 0, 'Mixed incomplete inventory suppresses candidates.');
            $errors = array_values(array_filter($mixed['issues'], static fn($issue) => $issue['code'] === 'not_directory'));
            $warnings = array_values(array_filter($mixed['issues'], static fn($issue) => $issue['code'] === 'unsupported_file_type'));
            special_check(count($errors) === 1 && $errors[0]['path'] === $directory, 'Required-path FIFO retains directory-type error.');
            special_check(count($warnings) === 1 && $warnings[0]['path'] === 'assets/nested.css', 'Only nested FIFO gets the unsupported-entry warning.');
            special_check($mixed['summary']['errors'] === 1 && $mixed['summary']['warnings'] === 1 && $mixed['summary']['permission_issues'] === 1, 'Mixed failures are each counted once.');
            special_check(filetype($root . '/' . $directory) === 'fifo' && filetype($root . '/assets/nested.css') === 'fifo', 'Mixed diagnostics preserves special entries.');
        } finally {
            unlink($root . '/' . $directory);
            unlink($root . '/assets/nested.css');
            rename($root . '/held', $root . '/' . $directory);
        }
        $recovered = ap_diagnostics($options);
        special_check($recovered['complete'] && $recovered['summary']['orphans'] === 1, 'Mixed fixture recovery restores complete inventory.');
    }
    file_put_contents($root . '/storage/ordinary.txt', 'normal file');
    $normal = ap_diagnostics($options);
    special_check($normal['complete'] && $normal['summary']['warnings'] === 0, 'Ordinary files remain supported.');
    special_check(file_get_contents($root . '/media/unused.jpg') === 'synthetic image', 'Image bytes remain unchanged.');
    echo "Diagnostics special files: $checks checks passed.\n";
} finally {
    special_remove($root);
}
