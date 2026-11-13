<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Synthetic fixtures only; never inspects or modifies installed site data.
$temp = sys_get_temp_dir() . '/alternpress-directory-types-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
$assertions = 0;
function directory_type_assert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function directory_type_fixture(string $path, string $body): void {
    global $temp;
    if (!is_dir(dirname($temp . '/' . $path))) mkdir(dirname($temp . '/' . $path), 0755, true);
    file_put_contents($temp . '/' . $path, $body);
}
function directory_type_remove(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') directory_type_remove($path . '/' . $child);
    rmdir($path);
}
try {
    foreach (['content', 'cache', 'storage', 'media'] as $directory) mkdir($temp . '/' . $directory, 0755);
    directory_type_fixture('media/unused.jpg', 'image bytes');
    $options = ['root' => $temp, 'max_seconds' => 30];
    $healthy = ap_diagnostics($options);
    directory_type_assert($healthy['complete'] && $healthy['summary']['orphans'] === 1, 'Healthy directories must allow orphan candidates.');
    foreach (['content', 'cache', 'storage', 'media'] as $directory) {
        // Preserve the tree while simulating a file at the required directory path.
        rename($temp . '/' . $directory, $temp . '/saved-' . $directory);
        file_put_contents($temp . '/' . $directory, 'not a directory');
        $report = ap_diagnostics($options);
        directory_type_assert(!$report['complete'], "File at required $directory must make the scan incomplete.");
        directory_type_assert($report['orphans'] === [] && $report['summary']['orphans'] === 0, 'Wrong directory types must suppress orphan candidates.');
        $issues = array_values(array_filter($report['issues'], static fn($issue) => $issue['code'] === 'not_directory'));
        directory_type_assert(count($issues) === 1 && $issues[0]['path'] === $directory && $issues[0]['severity'] === 'error', 'Wrong directory type must retain the exact path and error severity.');
        directory_type_assert($report['summary']['errors'] === 1 && $report['summary']['permission_issues'] === 1, 'Directory type error must be counted exactly once.');
        directory_type_assert(!in_array($directory, array_column($report['permissions'], 'path'), true), 'Wrong directory type must not be inventoried as a valid runtime file.');
        directory_type_assert(in_array('Incomplete scan: no reliable orphan-photo list is available.', $report['notes'], true), 'Incomplete inventory must explain why orphan candidates are absent.');
        directory_type_assert(file_get_contents($temp . '/' . $directory) === 'not a directory', 'Diagnostics must not modify the invalid path.');
        unlink($temp . '/' . $directory);
        rename($temp . '/saved-' . $directory, $temp . '/' . $directory);
        $restored = ap_diagnostics($options);
        directory_type_assert($restored['complete'] && $restored['summary']['orphans'] === 1, 'Restored directories must restore complete inventory.');
    }
    // Ordinary nested files remain valid; the directory requirement is top-level only.
    directory_type_fixture('storage/ordinary.txt', 'runtime data');
    $nested = ap_diagnostics($options);
    directory_type_assert($nested['complete'] && $nested['summary']['errors'] === 0, 'Ordinary nested files must not become directory type errors.');
    directory_type_assert(file_get_contents($temp . '/media/unused.jpg') === 'image bytes', 'Orphan candidate bytes must remain unchanged.');
    echo "Diagnostics directory types: $assertions assertions passed.\n";
} finally {
    directory_type_remove($temp);
}
