<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

$temp = sys_get_temp_dir() . '/alternpress-missing-dirs-' . bin2hex(random_bytes(6));
$checks = 0;
function missing_dir_assert(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
mkdir($temp, 0700);
try {
    foreach (['content', 'cache', 'storage', 'media'] as $directory) mkdir($temp . '/' . $directory);
    file_put_contents($temp . '/media/unused.jpg', 'synthetic image');
    $options = ['root' => $temp, 'max_seconds' => 30];
    $complete = ap_diagnostics($options);
    missing_dir_assert($complete['complete'], 'Empty required trees must form a complete scan.');
    missing_dir_assert(array_column($complete['orphans'], 'path') === ['media/unused.jpg'], 'Complete scan must still find an unused image.');
    foreach (['content', 'cache', 'storage', 'media'] as $directory) {
        // Move rather than delete: the same source bytes must survive every scan.
        rename($temp . '/' . $directory, $temp . '/held');
        try {
            $report = ap_diagnostics($options);
            missing_dir_assert(!$report['complete'], "Missing $directory must make the scan incomplete.");
            missing_dir_assert($report['orphans'] === [], "Missing $directory must suppress orphan candidates.");
            missing_dir_assert($report['summary']['orphans'] === 0, 'Summary must match the suppressed list.');
            $issues = array_values(array_filter($report['issues'], static fn($issue) => $issue['code'] === 'missing_directory'));
            missing_dir_assert(count($issues) === 1 && $issues[0]['path'] === $directory, 'Missing directory must retain its exact error path.');
            missing_dir_assert($report['summary']['permission_issues'] === 1, 'Missing directory must retain the permission error count.');
            missing_dir_assert(in_array('Incomplete scan: no reliable orphan-photo list is available.', $report['notes'], true), 'Incomplete scan must explain why candidates are unavailable.');
        } finally {
            rename($temp . '/held', $temp . '/' . $directory);
        }
        $restored = ap_diagnostics($options);
        missing_dir_assert($restored['complete'], 'Restoring a required directory must restore completeness.');
        missing_dir_assert(array_column($restored['orphans'], 'path') === ['media/unused.jpg'], 'Restoring the tree must restore valid candidates.');
        missing_dir_assert(file_get_contents($temp . '/media/unused.jpg') === 'synthetic image', 'Diagnostics must not modify media.');
    }
    // Exhaust every combination with at least two absent trees (11 subsets).
    $directories = ['content', 'cache', 'storage', 'media'];
    for ($mask = 1; $mask < 16; $mask++) {
        $missing = [];
        foreach ($directories as $index => $directory) {
            if ($mask & (1 << $index)) $missing[] = $directory;
        }
        if (count($missing) < 2) continue;
        foreach ($missing as $directory) rename($temp . '/' . $directory, $temp . '/held-' . $directory);
        try {
            $report = ap_diagnostics($options);
            missing_dir_assert(!$report['complete'], 'Multiple missing trees must make the scan incomplete.');
            missing_dir_assert($report['orphans'] === [] && $report['summary']['orphans'] === 0, 'Multiple missing trees must suppress candidates and their count.');
            $paths = array_column(array_filter($report['issues'], static fn($issue) => $issue['code'] === 'missing_directory'), 'path');
            sort($paths);
            $expected = $missing;
            sort($expected);
            missing_dir_assert($paths === $expected, 'Every absent tree must retain its own error, without duplicates.');
            missing_dir_assert($report['summary']['permission_issues'] === count($missing), 'All missing-tree errors must be counted.');
        } finally {
            foreach ($missing as $directory) rename($temp . '/held-' . $directory, $temp . '/' . $directory);
        }
        $restored = ap_diagnostics($options);
        missing_dir_assert($restored['complete'], 'Restoring all trees must recover completeness.');
        missing_dir_assert(array_column($restored['orphans'], 'path') === ['media/unused.jpg'], 'Restoring all trees must recover the orphan inventory.');
        missing_dir_assert(file_get_contents($temp . '/media/unused.jpg') === 'synthetic image', 'Multi-tree scans must preserve media bytes.');
    }
    echo "Missing-directory diagnostics: $checks checks passed.\n";
} finally {
    unlink($temp . '/media/unused.jpg');
    foreach (['content', 'cache', 'storage', 'media'] as $directory) rmdir($temp . '/' . $directory);
    rmdir($temp);
}
