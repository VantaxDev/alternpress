<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/diagnostics.php';

// Standalone temporary fixtures; never loads or modifies the installed site's data.
$temp = sys_get_temp_dir() . '/alternpress-diagnostics-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
$assertions = 0;
function diagnostic_assert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function diagnostic_fixture(string $path, string $body): void {
    global $temp;
    if (!is_dir(dirname($temp . '/' . $path))) mkdir(dirname($temp . '/' . $path), 0755, true);
    file_put_contents($temp . '/' . $path, $body);
}
function diagnostic_remove(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') diagnostic_remove($path . '/' . $child);
    rmdir($path);
}
try {
    foreach (['content', 'cache', 'storage', 'media', 'assets'] as $directory) mkdir($temp . '/' . $directory, 0755);
    $names = [];
    foreach (['cover', 'page', 'draft', 'css', 'custom', 'absolute', 'orphan'] as $index => $name) {
        $names[$name] = str_repeat((string) ($index + 1), 32) . '.jpg';
        diagnostic_fixture('media/' . $names[$name], 'test-image');
    }
    diagnostic_fixture('content/it/home/index.md', "---\ntitle: Home\n---\n![page](@media/{$names['page']})\n[English](/en)\n[Archive](/it/archive)\n[Tag](/it/tag/security)\n");
    diagnostic_fixture('content/en/home/index.md', "---\ntitle: Home\n---\nHello\n");
    diagnostic_fixture('content/it/about/index.md', "---\ntitle: About\n---\n[home](/it)\n[asset](/assets/site.css)\n");
    diagnostic_fixture('content/it/post/published/index.md', "---\ntitle: Published\nstatus: published\ncover: @media/{$names['cover']}\n---\n"
        . "![attachment](local.jpg)\n[other](../other)\n[EN](/en/post/english)\n[default](/post/other)\n"
        . "![base](/alphatest/media/{$names['page']})\n![absolute](https://example.test/alphatest/media/{$names['absolute']})\n"
        . "[canonical](https://blog.example/it/post/other)\n[external](https://external.example/post)\n[private](http://127.0.0.1/admin)\n"
        . "[anchor](#heading)\n`[inline](/missing-code)`\n``[multi](/missing-multicode)``\n"
        . "```md\n![code](/missing-code.jpg)\n```\n~~~\n[tilde](/missing-tilde)\n~~~\n"
        . "![missing](/media/missing.jpg)\n[missing](/it/post/missing)\n[draft](/it/post/draft)\n");
    diagnostic_fixture('content/it/post/published/local.jpg', 'test-image');
    diagnostic_fixture('content/it/post/other/index.md', "---\ntitle: Other\ncover: cover-local.jpg\n---\nOther\n");
    diagnostic_fixture('content/it/post/other/cover-local.jpg', 'test-image');
    diagnostic_fixture('content/it/post/other/css-local.jpg', 'test-image');
    diagnostic_fixture('content/it/post/other/orphan-local.jpg', 'test-image');
    diagnostic_fixture('content/en/post/english/index.md', "---\ntitle: English\n---\nEnglish\n");
    diagnostic_fixture('content/it/post/draft/index.md', "---\ntitle: Draft\nstatus: draft\n---\n![draft](@media/{$names['draft']})\n");
    diagnostic_fixture('assets/site.css', ".hero{background:url('../media/{$names['css']}')}\n.legacy{background:url('/it/post/other/css-local.jpg')}\n/* url(/media/missing-comment.jpg) */\n");
    $options = ['root' => $temp, 'base_path' => '/alphatest', 'origin' => 'https://example.test', 'site_url' => 'https://blog.example', 'custom_css' => '.hero{background:url("/media/' . $names['custom'] . '")}', 'max_seconds' => 30];
    $report = ap_diagnostics($options);
    diagnostic_assert($report['complete'], 'Fixture scan must complete.');
    diagnostic_assert($report['summary']['markdown'] === 7, 'All pages, posts and drafts must be scanned.');
    diagnostic_assert($report['summary']['missing_links'] === 3, 'Only the missing image, missing post and unpublished target should fail: ' . json_encode($report['issues']));
    diagnostic_assert($report['summary']['external_skipped'] === 2, 'Remote and private HTTP URLs must be skipped without network requests.');
    diagnostic_assert(array_column($report['orphans'], 'path') === ['content/it/post/other/orphan-local.jpg', 'media/' . $names['orphan']], 'Only unused media and legacy attachment candidates should be listed; relative images, covers and CSS attachments must be retained.');
    diagnostic_assert(count(array_filter($report['issues'], static fn($row) => $row['path'] === 'content/it/post/published/index.md')) === 3, 'Missing references must retain their source document.');
    diagnostic_assert(count(array_filter($report['permissions'], static fn($row) => $row['path'] === 'content/it/post/published' && $row['type'] === 'directory')) === 1, 'Permissions must include nested directories.');
    diagnostic_assert(count(array_filter($report['permissions'], static fn($row) => $row['path'] === 'content/it/post/published/local.jpg' && $row['type'] === 'file')) === 1, 'Permissions must include nested files.');
    diagnostic_assert(is_file($temp . '/media/' . $names['orphan']), 'Diagnostics must never remove orphan candidates.');
    $small = ap_diagnostics($options + ['max_files' => 2]);
    diagnostic_assert(!$small['complete'] && $small['orphans'] === [], 'Limited scans must not claim photos are orphaned.');
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($temp . '/media/' . $names['orphan'], 0666);
        $perms = ap_diagnostics($options);
        diagnostic_assert(count(array_filter($perms['issues'], static fn($row) => $row['code'] === 'world_writable' && $row['path'] === 'media/' . str_repeat('7', 32) . '.jpg')) === 1, 'World-writable media must be reported.');
        chmod($temp . '/media/' . $names['orphan'], 0644);
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            diagnostic_fixture('storage/.htaccess', 'Require all denied');
            chmod($temp . '/storage/.htaccess', 0444);
            $privateBackup = 'storage/backups/alternpress-20260930-120000-abcdef.tar.gz';
            diagnostic_fixture($privateBackup, 'private CLI backup');
            chmod($temp . '/' . $privateBackup, 0000);
            $protected = ap_diagnostics($options);
            diagnostic_assert(count(array_filter($protected['issues'], static fn($row) => in_array($row['path'], ['storage/.htaccess', $privateBackup], true))) === 0, 'Read-only protections and SSH-only full archives are not runtime permission errors.');
            chmod($temp . '/' . $privateBackup, 0600);
            chmod($temp . '/content/it/post/other/index.md', 0444);
            $perms = ap_diagnostics($options);
            diagnostic_assert(count(array_filter($perms['issues'], static fn($row) => $row['code'] === 'not_writable' && $row['path'] === 'content/it/post/other/index.md')) === 1, 'Read-only content must fail its write permission check.');
            chmod($temp . '/content/it/post/other/index.md', 0644);
        }
    }
    if (function_exists('symlink') && @symlink($temp . '/content', $temp . '/media/linked-content')) {
        $links = ap_diagnostics($options);
        diagnostic_assert(count(array_filter($links['issues'], static fn($row) => $row['code'] === 'symlink_skipped')) === 1, 'Symbolic links must be reported and never followed.');
        diagnostic_assert(!$links['complete'] && $links['orphans'] === [], 'Skipped source trees must suppress orphan claims.');
    }
    echo "Diagnostics: $assertions assertions passed.\n";
} finally {
    diagnostic_remove($temp);
}
