<?php
declare(strict_types=1);

/**
 * Portable data archives, without PHP deserialization or archive extraction.
 * Public entry points: ap_backup_create/list/path, ap_restore_prepare/pending/apply/cancel.
 * The admin owns the preview token in its authenticated session and checks CSRF.
 * Restores replace Markdown, raster images and editable settings; all code, access
 * credentials, protected dotfiles, unsupported files and backup history stay intact.
 */
const AP_BACKUP_MAX_ARCHIVE = 67108864;
const AP_BACKUP_MAX_TOTAL = 268435456;
const AP_BACKUP_MAX_FILES = 10000;
const AP_BACKUP_MAX_FILE = 33554432;

function ap_backup_message(string $it, string $en): string { return function_exists('t') ? t($it, $en) : $en; }
function ap_backup_error(string $it, string $en): never { throw new RuntimeException(ap_backup_message($it, $en)); }
function ap_backup_json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); }

function ap_backup_safe_path(string $path): bool {
    if ($path === '' || strlen($path) > 240 || !mb_check_encoding($path, 'UTF-8') || preg_match('~[\\\\\x00-\x1f\x7f:]~', $path)) return false;
    foreach (explode('/', $path) as $part) if ($part === '' || $part[0] === '.' || trim($part) !== $part) return false;
    // Apache can select a handler from an earlier extension (shell.php.jpg).
    // Keep archives portable even when the destination server is misconfigured.
    if (preg_match('~\.(?:php[0-9]*|phtml|pht|phar|cgi|fcgi|pl|py|shtml|shtm|asp|aspx|jsp)(?:[.;/]|$)~i', $path)) return false;
    return true;
}
function ap_backup_kind(string $path): ?string {
    if (!ap_backup_safe_path($path)) return null;
    $parts = explode('/', $path);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($parts[0] === 'content' && count($parts) >= 3 && in_array($parts[1], SUPPORTED_LANGUAGES, true)) {
        if ($extension === 'md') return 'document';
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'image';
    }
    if ($parts[0] === 'media' && count($parts) >= 2 && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'image';
    return null;
}
/** Reject a symlink in any existing parent as well as the leaf. */
function ap_backup_assert_path(string $absolute, string $root = AP_ROOT): void {
    if ($absolute !== $root && !str_starts_with($absolute, $root . '/')) throw new RuntimeException('Path outside data directory.');
    $cursor = $root;
    if (is_link($cursor)) throw new RuntimeException('Symlink data directory.');
    foreach (explode('/', substr($absolute, strlen($root) + 1)) as $part) {
        if ($part === '') continue;
        if ($part === '.' || $part === '..' || str_contains($part, "\0") || str_contains($part, '\\')) throw new RuntimeException('Invalid data path.');
        $cursor .= '/' . $part;
        if (is_link($cursor)) ap_backup_error('Un collegamento simbolico impedisce il backup o il ripristino.', 'A symbolic link prevents backup or restore.');
    }
}
function ap_backup_owner(string $path): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $owner = stat(AP_STORAGE);
        if ($owner === false || !chown($path, $owner['uid']) || !chgrp($path, $owner['gid'])) throw new RuntimeException('Cannot preserve data ownership.');
    }
}
function ap_backup_mkdir(string $path, int $mode = 0700): void {
    ap_backup_assert_path($path);
    $missing = []; $cursor = $path;
    while (!is_dir($cursor)) { $missing[] = $cursor; $cursor = dirname($cursor); }
    foreach (array_reverse($missing) as $directory) {
        if (!mkdir($directory, $mode) && !is_dir($directory)) throw new RuntimeException('Cannot create backup directory.');
        ap_backup_owner($directory);
    }
    // Earlier root-run versions may have left a private folder root-owned.
    if (str_starts_with($path, AP_STORAGE . '/') && in_array(basename($path), ['backups', 'restore'], true)) ap_backup_owner($path);
}
function ap_backup_settings(array $values): array {
    if (array_diff(array_keys($values), AP_EDITABLE_SETTINGS) || array_diff(AP_EDITABLE_SETTINGS, array_keys($values))) throw new RuntimeException('Invalid backup settings schema.');
    $result = [];
    foreach (['name' => 120, 'logo_mark' => 12, 'email' => 254, 'legal' => 1000, 'site_url' => 500, 'default_language' => 2] as $key => $limit) {
        if (!is_string($values[$key]) || !mb_check_encoding($values[$key], 'UTF-8')) throw new RuntimeException('Invalid backup setting: ' . $key);
        $result[$key] = ap_clean_line($values[$key], $limit);
    }
    if ($result['name'] === '' || ($result['email'] !== '' && !filter_var($result['email'], FILTER_VALIDATE_EMAIL)) || !in_array($result['default_language'], SUPPORTED_LANGUAGES, true)) throw new RuntimeException('Invalid backup identity settings.');
    $url = $result['site_url'];
    if ($url !== '') {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Invalid backup site URL.');
    }
    if (!is_int($values['posts_per_page']) || $values['posts_per_page'] < 1 || $values['posts_per_page'] > 100 || !is_bool($values['search_indexing'])) throw new RuntimeException('Invalid backup publishing settings.');
    $result['posts_per_page'] = $values['posts_per_page'];
    $result['search_indexing'] = $values['search_indexing'];
    foreach (['description' => 500, 'tagline' => 1000] as $key => $limit) {
        if (!is_array($values[$key]) || array_diff(array_keys($values[$key]), SUPPORTED_LANGUAGES)) throw new RuntimeException('Invalid backup language settings.');
        foreach (SUPPORTED_LANGUAGES as $language) {
            if (!is_string($values[$key][$language] ?? null) || !mb_check_encoding($values[$key][$language], 'UTF-8')) throw new RuntimeException('Missing backup language setting.');
            $result[$key][$language] = ap_clean_line($values[$key][$language], $limit);
        }
    }
    if (!is_string($values['custom_css'])) throw new RuntimeException('Invalid backup CSS.');
    $result['custom_css'] = ap_validate_css($values['custom_css']);
    return $result;
}
function ap_backup_effective_settings(): array {
    // Re-read disk: a preview must notice another request changing settings.
    ap_backup_assert_path(AP_STORAGE . '/settings.php');
    $config = require AP_ROOT . '/config.php';
    if (is_file(AP_ROOT . '/config.local.php')) $config = array_replace($config, require AP_ROOT . '/config.local.php');
    $config = array_replace($config, ap_private_read('settings')['values'] ?? []);
    return ap_backup_settings(array_intersect_key($config, array_flip(AP_EDITABLE_SETTINGS)));
}
function ap_backup_snapshot(): array {
    $files = []; $skipped = []; $total = 0;
    foreach (['content', 'media'] as $scope) {
        $base = AP_ROOT . '/' . $scope;
        ap_backup_assert_path($base);
        if (!is_dir($base)) throw new RuntimeException('Missing data directory: ' . $scope);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen(AP_ROOT) + 1));
            if ($file->isLink()) ap_backup_error('Rimuovi i collegamenti simbolici dalle cartelle dei contenuti prima di procedere.', 'Remove symbolic links from the data folders before continuing.');
            if (!$file->isFile()) continue;
            if (!ap_backup_kind($path)) {
                if (!preg_match('~(?:^|/)\.~', $path)) $skipped[] = $path;
                continue;
            }
            if (!$file->isReadable()) throw new RuntimeException('Unreadable data file: ' . $path);
            $size = $file->getSize();
            $limit = ap_backup_kind($path) === 'document' ? 2097152 : AP_BACKUP_MAX_FILE;
            if ($size > $limit || ($total += $size) > AP_BACKUP_MAX_TOTAL - 4194304 || count($files) >= AP_BACKUP_MAX_FILES) ap_backup_error('I dati superano i limiti del backup: 2 MB per testo, 32 MB per foto, 252 MB totali, 10.000 file.', 'Data exceeds backup limits: 2 MB per text file, 32 MB per photo, 252 MB total, 10,000 files.');
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) throw new RuntimeException('Cannot hash data file.');
            $files[$path] = ['size' => $size, 'sha256' => $hash];
        }
    }
    ksort($files, SORT_STRING); sort($skipped, SORT_STRING);
    $settings = ap_backup_effective_settings();
    return ['files' => $files, 'settings' => $settings, 'skipped' => $skipped, 'bytes' => $total,
        'fingerprint' => hash('sha256', ap_backup_json([$files, $settings, ap_settings_revision()]))];
}
function ap_backup_counts(array $files): array {
    $counts = ['documents' => 0, 'images' => 0];
    foreach (array_keys($files) as $path) { $kind = ap_backup_kind($path); if ($kind === 'document') $counts['documents']++; elseif ($kind === 'image') $counts['images']++; }
    return $counts;
}
function ap_backup_tar_header(string $name, int $size, int $time): string {
    $prefix = '';
    if (strlen($name) > 100) {
        $slash = strrpos(substr($name, 0, 156), '/');
        if ($slash === false || strlen(substr($name, $slash + 1)) > 100) throw new RuntimeException('Backup path is too long.');
        $prefix = substr($name, 0, $slash); $name = substr($name, $slash + 1);
    }
    $header = str_pad($name, 100, "\0") . sprintf('%07o', 0600) . "\0" . str_repeat("0000000\0", 2)
        . sprintf('%011o', $size) . "\0" . sprintf('%011o', $time) . "\0" . str_repeat(' ', 8) . '0' . str_repeat("\0", 100)
        . "ustar\00000" . str_repeat("\0", 80) . str_pad($prefix, 155, "\0") . str_repeat("\0", 12);
    if (strlen($header) !== 512) throw new RuntimeException('Invalid generated tar header.');
    $sum = array_sum(unpack('C*', $header));
    return substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
}
function ap_backup_gzwrite($handle, string $data): void {
    $offset = 0;
    while ($offset < strlen($data)) { $written = gzwrite($handle, substr($data, $offset)); if ($written === false || $written === 0) throw new RuntimeException('Cannot write backup archive.'); $offset += $written; }
}
function ap_backup_tar_add($handle, string $path, string $data, int $time): void {
    ap_backup_gzwrite($handle, ap_backup_tar_header($path, strlen($data), $time));
    ap_backup_gzwrite($handle, $data);
    $padding = (512 - strlen($data) % 512) % 512;
    if ($padding) ap_backup_gzwrite($handle, str_repeat("\0", $padding));
}
function ap_backup_create_unlocked(?array $snapshot = null): array {
    $snapshot ??= ap_backup_snapshot();
    $dir = AP_STORAGE . '/backups'; ap_backup_mkdir($dir);
    $name = 'alternpress-data-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.tar.gz';
    $path = $dir . '/' . $name; $temporary = $path . '.tmp';
    $settings = ap_backup_json(['values' => $snapshot['settings']]);
    $entries = $snapshot['files']; $entries['settings.json'] = ['size' => strlen($settings), 'sha256' => hash('sha256', $settings)];
    $created = gmdate('c');
    $manifest = ['format' => 'alternpress-data', 'version' => 1, 'created_at' => $created, 'files' => $entries];
    $manifestData = ap_backup_json($manifest);
    if (strlen($settings) > 200000 || strlen($manifestData) > 4194304 || $snapshot['bytes'] + strlen($settings) + strlen($manifestData) > AP_BACKUP_MAX_TOTAL) throw new RuntimeException('Backup metadata exceeds the restore limits.');
    $handle = gzopen($temporary, 'wb6');
    if (!$handle) throw new RuntimeException('Cannot create backup archive.');
    chmod($temporary, 0600);
    ap_backup_owner($temporary);
    try {
        ap_backup_tar_add($handle, 'manifest.json', $manifestData, time());
        ap_backup_tar_add($handle, 'settings.json', $settings, time());
        foreach ($snapshot['files'] as $file => $expected) {
            ap_backup_assert_path(AP_ROOT . '/' . $file);
            $data = file_get_contents(AP_ROOT . '/' . $file);
            if ($data === false || strlen($data) !== $expected['size'] || !hash_equals($expected['sha256'], hash('sha256', $data))) throw new RuntimeException('Data changed during backup: ' . $file);
            if (ap_backup_kind($file) === 'document' && (!mb_check_encoding($data, 'UTF-8') || str_contains($data, "\0"))) throw new RuntimeException('Markdown must contain UTF-8 text: ' . $file);
            if (ap_backup_kind($file) === 'image') {
                $image = @getimagesizefromstring($data);
                $types = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];
                if (!$image || ($image[2] ?? 0) !== $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))]) throw new RuntimeException('Invalid raster image: ' . $file);
            }
            ap_backup_tar_add($handle, $file, $data, time());
        }
        ap_backup_gzwrite($handle, str_repeat("\0", 1024));
        if (!gzclose($handle)) throw new RuntimeException('Cannot close backup archive.');
        $handle = null;
        if (filesize($temporary) > AP_BACKUP_MAX_ARCHIVE) ap_backup_error('Il backup compresso supera 64 MB.', 'The compressed backup exceeds 64 MB.');
        if (!rename($temporary, $path)) throw new RuntimeException('Cannot save backup archive.');
        $meta = array_merge(['name' => $name, 'created_at' => $created, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'skipped' => $snapshot['skipped']], ap_backup_counts($snapshot['files']));
        ap_atomic_write($path . '.json', ap_backup_json($meta), 0600);
        return $meta;
    } finally { if (is_resource($handle)) gzclose($handle); if (is_file($temporary)) unlink($temporary); }
}
function ap_backup_create(): array {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    return ap_lock(static fn() => ap_backup_create_unlocked());
}
function ap_backup_path(string $name): string {
    if (!preg_match('/\Aalternpress-data-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.tar\.gz\z/D', $name)) throw new RuntimeException('Invalid backup name.');
    $path = AP_STORAGE . '/backups/' . $name; ap_backup_assert_path($path);
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Backup not found.');
    return $path;
}
function ap_backup_list(): array {
    $items = [];
    foreach (glob(AP_STORAGE . '/backups/alternpress-data-*.tar.gz') ?: [] as $path) {
        try {
            $path = ap_backup_path(basename($path));
            $metaPath = $path . '.json'; ap_backup_assert_path($metaPath);
            $meta = is_file($metaPath) && filesize($metaPath) < 200000 ? json_decode((string) file_get_contents($metaPath), true, 32, JSON_THROW_ON_ERROR) : [];
            $items[] = array_merge(['documents' => null, 'images' => null], is_array($meta) ? $meta : [], ['name' => basename($path), 'created_at' => gmdate('c', filemtime($path)), 'bytes' => filesize($path)]);
        } catch (Throwable) { continue; }
    }
    usort($items, static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $items;
}
function ap_restore_dir(string $token): string {
    if (!preg_match('/\A[a-f0-9]{48}\z/D', $token)) throw new RuntimeException('Invalid restore token.');
    $dir = AP_STORAGE . '/restore/' . $token; ap_backup_assert_path($dir);
    return $dir;
}
function ap_restore_remove_tree(string $dir): void {
    $parent = AP_STORAGE . '/restore/';
    if (!str_starts_with($dir, $parent) || !preg_match('~\A[a-f0-9]{48}(?:/[a-z-]+)?\z~D', substr($dir, strlen($parent)))) throw new RuntimeException('Invalid restore cleanup path.');
    ap_backup_assert_path($dir);
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isLink() || $file->isFile()) { if (!unlink($file->getPathname())) throw new RuntimeException('Cannot remove restore temporary file.'); }
        elseif ($file->isDir() && !rmdir($file->getPathname())) throw new RuntimeException('Cannot remove restore temporary folder.');
    }
    if (!rmdir($dir)) throw new RuntimeException('Cannot remove restore temporary folder.');
}
function ap_restore_read($handle, int $length, int &$total): string {
    if ($length < 0 || $total + $length > AP_BACKUP_MAX_TOTAL + 16777216) throw new RuntimeException('Archive expands beyond the restore limit.');
    $result = '';
    while (strlen($result) < $length) {
        $chunk = gzread($handle, min(65536, $length - strlen($result)));
        if ($chunk === false || $chunk === '') throw new RuntimeException('Truncated or corrupt backup archive.');
        $result .= $chunk;
    }
    $total += $length;
    return $result;
}
function ap_restore_octal(string $value): int {
    if (!preg_match('/\A[0-7]+[\x00 ]*\z/D', $value)) throw new RuntimeException('Invalid archive numeric field.');
    $result = octdec(rtrim($value, "\0 "));
    if (!is_int($result) && $result > PHP_INT_MAX) throw new RuntimeException('Archive numeric overflow.');
    return (int) $result;
}
function ap_restore_text(string $field): string {
    $end = strpos($field, "\0");
    if ($end === false) return $field;
    if (trim(substr($field, $end), "\0") !== '') throw new RuntimeException('Invalid archive path field.');
    return substr($field, 0, $end);
}
/** A deliberately narrow ustar reader. Extended headers and links are forbidden. */
function ap_restore_unpack(string $archive, string $destination): array {
    ap_backup_assert_path($archive); ap_backup_mkdir($destination);
    if (!is_file($archive) || filesize($archive) > AP_BACKUP_MAX_ARCHIVE) throw new RuntimeException('Invalid backup archive size.');
    $signature = fopen($archive, 'rb');
    if (!$signature) throw new RuntimeException('Cannot read backup archive.');
    $magic = fread($signature, 2); fclose($signature);
    if ($magic !== "\x1f\x8b") throw new RuntimeException('Upload an AlternPress data backup in .tar.gz format.');
    $handle = gzopen($archive, 'rb');
    if (!$handle) throw new RuntimeException('Cannot read compressed backup.');
    $total = 0; $entries = []; $manifest = null; $settings = null; $dataBytes = 0;
    try {
        while (true) {
            $header = ap_restore_read($handle, 512, $total);
            if ($header === str_repeat("\0", 512)) {
                if (ap_restore_read($handle, 512, $total) !== str_repeat("\0", 512)) throw new RuntimeException('Invalid archive terminator.');
                $tail = gzread($handle, 10241);
                if ($tail === false || strlen($tail) > 10240 || trim($tail, "\0") !== '' || !gzeof($handle)) throw new RuntimeException('Unexpected data after archive terminator.');
                break;
            }
            if (count($entries) >= AP_BACKUP_MAX_FILES + 2) throw new RuntimeException('Too many files in backup.');
            if (substr($header, 257, 8) !== "ustar\00000" || !in_array($header[156], ['0', "\0"], true) || trim(substr($header, 157, 100), "\0") !== '') throw new RuntimeException('Only regular ustar files are allowed.');
            $expectedChecksum = ap_restore_octal(substr($header, 148, 8));
            if ($expectedChecksum !== array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)))) throw new RuntimeException('Invalid archive header checksum.');
            $name = ap_restore_text(substr($header, 0, 100));
            $prefix = ap_restore_text(substr($header, 345, 155));
            if ($prefix !== '') $name = $prefix . '/' . $name;
            if (!ap_backup_safe_path($name) || (ap_backup_kind($name) === null && !in_array($name, ['manifest.json', 'settings.json'], true))) throw new RuntimeException('Unexpected or unsafe archive entry.');
            if (isset($entries[$name])) throw new RuntimeException('Duplicate archive entry.');
            $size = ap_restore_octal(substr($header, 124, 12));
            $limit = $name === 'manifest.json' ? 4194304 : ($name === 'settings.json' ? 200000 : (ap_backup_kind($name) === 'document' ? 2097152 : AP_BACKUP_MAX_FILE));
            if ($size > $limit || ($dataBytes += $size) > AP_BACKUP_MAX_TOTAL) throw new RuntimeException('Backup file or expanded data exceeds the restore limit.');
            $data = ap_restore_read($handle, $size, $total);
            $padding = (512 - $size % 512) % 512;
            if ($padding && ap_restore_read($handle, $padding, $total) !== str_repeat("\0", $padding)) throw new RuntimeException('Invalid archive padding.');
            $entries[$name] = ['size' => $size, 'sha256' => hash('sha256', $data)];
            if ($name === 'manifest.json') { $manifest = json_decode($data, true, 16, JSON_THROW_ON_ERROR); continue; }
            if ($name === 'settings.json') {
                $decoded = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || array_keys($decoded) !== ['values'] || !is_array($decoded['values'])) throw new RuntimeException('Invalid settings payload.');
                $settings = ap_backup_settings($decoded['values']); continue;
            }
            if (ap_backup_kind($name) === 'document' && (!mb_check_encoding($data, 'UTF-8') || str_contains($data, "\0"))) throw new RuntimeException('Markdown must contain UTF-8 text.');
            if (ap_backup_kind($name) === 'image') {
                $image = @getimagesizefromstring($data);
                $extensions = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];
                if (!$image || ($image[2] ?? 0) !== $extensions[strtolower(pathinfo($name, PATHINFO_EXTENSION))]) throw new RuntimeException('Invalid raster image in backup.');
            }
            ap_backup_mkdir(dirname($destination . '/' . $name));
            ap_atomic_write($destination . '/' . $name, $data, 0600);
        }
    } finally { gzclose($handle); }
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'alternpress-data' || ($manifest['version'] ?? null) !== 1 || !is_string($manifest['created_at'] ?? null) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}\+00:00\z/D', $manifest['created_at']) || !is_array($manifest['files'] ?? null) || $settings === null) throw new RuntimeException('Missing or invalid AlternPress data manifest.');
    unset($entries['manifest.json']);
    $declared = $manifest['files']; ksort($entries, SORT_STRING); ksort($declared, SORT_STRING);
    if ($declared !== $entries) throw new RuntimeException('Archive contents do not match the SHA-256 manifest.');
    unset($entries['settings.json']);
    return ['files' => $entries, 'settings' => $settings, 'created_at' => $manifest['created_at'], 'bytes' => $dataBytes];
}
function ap_restore_prepare(string $upload): array {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    if (!is_file($upload) || is_link($upload) || filesize($upload) < 32 || filesize($upload) > AP_BACKUP_MAX_ARCHIVE) ap_backup_error('Scegli un backup dati AlternPress .tar.gz, massimo 64 MB.', 'Choose an AlternPress .tar.gz data backup, up to 64 MB.');
    ap_lock(static fn() => ap_restore_cleanup());
    $token = bin2hex(random_bytes(24)); $dir = ap_restore_dir($token); ap_backup_mkdir($dir);
    try {
        if (!copy($upload, $dir . '/archive.tar.gz')) throw new RuntimeException('Cannot stage uploaded backup.');
        chmod($dir . '/archive.tar.gz', 0600);
        ap_backup_owner($dir . '/archive.tar.gz');
        $archive = ap_restore_unpack($dir . '/archive.tar.gz', $dir . '/data');
        $snapshot = ap_lock(static fn() => ap_backup_snapshot());
        $removed = array_diff_key($snapshot['files'], $archive['files']);
        $added = array_diff_key($archive['files'], $snapshot['files']);
        $changed = 0;
        foreach ($archive['files'] as $name => $file) if (isset($snapshot['files'][$name]) && $snapshot['files'][$name] !== $file) $changed++;
        $counts = ap_backup_counts($archive['files']); $removeCounts = ap_backup_counts($removed);
        $preview = array_merge($counts, ['token' => $token, 'created_at' => $archive['created_at'], 'bytes' => $archive['bytes'], 'archive_sha256' => hash_file('sha256', $dir . '/archive.tar.gz'), 'fingerprint' => $snapshot['fingerprint'], 'expires_at' => time() + 1800, 'remove_documents' => $removeCounts['documents'], 'remove_images' => $removeCounts['images'], 'added' => count($added), 'changed' => $changed, 'skipped' => $snapshot['skipped']]);
        ap_atomic_write($dir . '/preview.php', "<?php exit; ?>\n" . ap_backup_json($preview), 0600);
        return $preview;
    } catch (Throwable $e) { ap_restore_remove_tree($dir); throw $e; }
}
function ap_restore_pending(string $token): array {
    $dir = ap_restore_dir($token); $path = $dir . '/preview.php'; ap_backup_assert_path($path);
    if (!is_file($path) || filesize($path) > 2000000) throw new RuntimeException('Restore preview not found.');
    $raw = (string) file_get_contents($path);
    if (!str_starts_with($raw, "<?php exit; ?>\n")) throw new RuntimeException('Invalid restore preview.');
    $preview = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($preview) || ($preview['token'] ?? '') !== $token || ($preview['expires_at'] ?? 0) < time()) ap_backup_error('Anteprima scaduta. Carica nuovamente il backup.', 'Preview expired. Upload the backup again.');
    return $preview;
}
/** Clean abandoned previews on the next upload; never touch a live transaction. */
function ap_restore_cleanup(): void {
    $root = AP_STORAGE . '/restore'; ap_backup_assert_path($root);
    if (!is_dir($root)) return;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match('/\A[a-f0-9]{48}\z/D', basename($dir)) || is_link($dir)) continue;
        $previewPath = $dir . '/preview.php';
        if (is_link($previewPath)) continue;
        $expired = false;
        if (is_file($previewPath) && filesize($previewPath) < 2000000) {
            $raw = (string) file_get_contents($previewPath);
            if (str_starts_with($raw, "<?php exit; ?>\n")) {
                $preview = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true);
                $expired = is_array($preview) && is_int($preview['expires_at'] ?? null) && $preview['expires_at'] < time();
            }
        } elseif (!is_file($previewPath)) $expired = filemtime($dir) < time() - 3600;
        // A rollback directory may be needed for manual recovery after an interruption.
        if ($expired && !is_dir($dir . '/rollback') && !file_exists($dir . '/transaction.php')) ap_restore_remove_tree($dir);
    }
}
function ap_restore_apply(string $token): array {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    ignore_user_abort(true);
    return ap_lock(static function() use ($token): array {
        $preview = ap_restore_pending($token); $dir = ap_restore_dir($token);
        // A killed PHP process may have stopped between two atomic file writes.
        // Reusing its staging directory would overwrite the only rollback copy.
        if (file_exists($dir . '/transaction.php') || is_dir($dir . '/rollback')) ap_backup_error('Questo ripristino è stato interrotto. Conserva la cartella di recupero e usa il backup automatico precedente per recuperare i dati.', 'This restore was interrupted. Preserve its recovery folder and use the preceding automatic backup to recover the data.');
        $archivePath = $dir . '/archive.tar.gz'; ap_backup_assert_path($archivePath);
        if (!is_file($archivePath) || !hash_equals($preview['archive_sha256'], hash_file('sha256', $archivePath))) throw new RuntimeException('The staged backup changed. Upload it again.');
        if (is_dir($dir . '/verified')) ap_restore_remove_tree($dir . '/verified');
        $archive = ap_restore_unpack($archivePath, $dir . '/verified');
        $snapshot = ap_backup_snapshot();
        if (!hash_equals($preview['fingerprint'], $snapshot['fingerprint'])) ap_backup_error('Il sito è cambiato dopo l’anteprima. Ricarica il backup per rivedere il ripristino.', 'The site changed after the preview. Upload the backup again to review the restore.');
        $targets = array_unique(array_merge(array_keys($snapshot['files']), array_keys($archive['files']), ['storage/settings.php']));
        foreach ($targets as $relative) {
            $path = AP_ROOT . '/' . $relative; ap_backup_assert_path($path);
            if (file_exists($path) && !is_file($path)) throw new RuntimeException('Restore target is not a file: ' . $relative);
            $parent = dirname($path);
            while (!is_dir($parent) && $parent !== AP_ROOT) { if (file_exists($parent)) throw new RuntimeException('Restore target parent is not a folder.'); $parent = dirname($parent); }
            if (!is_writable($parent)) throw new RuntimeException('Restore folder is not writable: ' . $relative);
        }
        $backup = ap_backup_create_unlocked($snapshot);
        $rollback = $dir . '/rollback'; ap_backup_mkdir($rollback);
        $existing = []; $modified = [];
        foreach ($targets as $relative) {
            $path = AP_ROOT . '/' . $relative;
            if (is_file($path)) {
                $data = file_get_contents($path);
                if ($data === false) throw new RuntimeException('Cannot prepare restore rollback.');
                ap_backup_mkdir(dirname($rollback . '/' . $relative));
                ap_atomic_write($rollback . '/' . $relative, $data, 0600);
                $existing[$relative] = fileperms($path) & 0777;
            }
        }
        ap_atomic_write($dir . '/transaction.php', "<?php exit; ?>\n" . ap_backup_json([
            'backup' => $backup['name'], 'started_at' => gmdate('c'), 'targets' => $targets,
            'existing_modes' => $existing, 'original_fingerprint' => $snapshot['fingerprint'],
        ]), 0600);
        try {
            foreach ($targets as $relative) {
                $path = AP_ROOT . '/' . $relative; ap_backup_assert_path($path); $modified[] = $relative;
                if ($relative === 'storage/settings.php') ap_atomic_write($path, "<?php exit; ?>\n" . ap_backup_json(['values' => $archive['settings']]));
                elseif (isset($archive['files'][$relative])) {
                    $data = file_get_contents($dir . '/verified/' . $relative);
                    if ($data === false) throw new RuntimeException('Cannot read staged content.');
                    ap_backup_mkdir(dirname($path), 0750);
                    ap_atomic_write($path, $data, $existing[$relative] ?? 0640);
                } elseif (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot remove later content: ' . $relative);
            }
        } catch (Throwable $e) {
            $failed = [];
            foreach (array_reverse($modified) as $relative) {
                try {
                    $path = AP_ROOT . '/' . $relative;
                    ap_backup_assert_path($path);
                    if (isset($existing[$relative])) {
                        $previous = file_get_contents($rollback . '/' . $relative);
                        if ($previous === false) throw new RuntimeException('Cannot read rollback data.');
                        ap_atomic_write($path, $previous, $existing[$relative]);
                    }
                    elseif (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot remove new file.');
                } catch (Throwable) { $failed[] = $relative; }
            }
            if ($failed) throw new RuntimeException(ap_backup_message('Ripristino interrotto. Recupero manuale necessario dal backup: ', 'Restore interrupted. Manual recovery is required from backup: ') . $backup['name']);
            // Rollback succeeded; cancellation can now discard the preview.
            ap_restore_remove_tree($rollback);
            if (!unlink($dir . '/transaction.php')) throw new RuntimeException('Previous data recovered; cannot clear the restore transaction marker.');
            throw new RuntimeException(ap_backup_message('Ripristino annullato: dati precedenti recuperati. ', 'Restore cancelled: previous data recovered. ') . $e->getMessage(), 0, $e);
        }
        try {
            ap_assert_path(AP_ROOT . '/cache');
            foreach (glob(AP_ROOT . '/cache/*.json') ?: [] as $cache) { ap_assert_path($cache); if (is_file($cache)) @unlink($cache); }
        } catch (Throwable $cacheError) { error_log('AlternPress restore succeeded; cache cleanup failed: ' . $cacheError->getMessage()); }
        $result = array_merge(['backup' => $backup['name']], ap_backup_counts($archive['files']));
        try { ap_restore_remove_tree($dir); }
        catch (Throwable $cleanupError) { error_log('AlternPress restore succeeded; temporary cleanup failed: ' . $cleanupError->getMessage()); }
        return $result;
    });
}
function ap_restore_cancel(string $token): void {
    ap_lock(static function() use ($token): void {
        $dir = ap_restore_dir($token);
        if (file_exists($dir . '/transaction.php') || is_dir($dir . '/rollback')) ap_backup_error('I dati di recupero di un ripristino interrotto non possono essere cancellati dal pannello.', 'Recovery data from an interrupted restore cannot be deleted from the dashboard.');
        ap_restore_remove_tree($dir);
    });
}
