#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Standalone on purpose: recovery must not load possibly broken site code. */
class APUpdateBusy extends RuntimeException {}
class APCodeUpdater {
    public const EXTENSIONS = ['mbstring', 'fileinfo', 'gd', 'session', 'json', 'zlib'];
    public const REQUIRED = ['index.php', 'alt-cli', 'lib/bootstrap.php', 'lib/Parsedown.php', 'lib/settings.php', 'lib/admin.php', 'lib/backups.php', 'lib/discovery.php', 'lib/diagnostics.php', 'lib/page-cache.php', 'lib/update-health.php', 'lib/update-release.php', 'admin/index.php', 'admin/settings.php', 'admin/maintenance.php', 'admin/maintenance-actions.php', 'bin/password.php', 'bin/cache-warm.php', 'bin/update.php', 'assets/base.css', 'assets/site.css', 'assets/admin.css', 'assets/admin.js', 'assets/favicon.svg', 'assets/theme.example.css'];
    private array $locks = [];
    private string $site;
    private string $state;
    private string $url;
    private int $minimumPhp = 80200;
    private array $journal = [];
    private string $transaction = '';
    public function __construct(string $site, string $state, string $url, private int $timeout = 3, private int $attempts = 3) {
        self::noLinks($site);
        $this->site = realpath($site) ?: throw new RuntimeException('Site directory is missing.');
        if (str_starts_with(__FILE__, $this->site . '/')) throw new RuntimeException('Keep the trusted updater outside the site being replaced.');
        self::noLinks($state);
        $parent = realpath(dirname($state));
        if (!$parent) throw new RuntimeException('Create a trusted parent for the external state directory first.');
        $this->state = $parent . '/' . basename($state);
        if ($this->state === $this->site || str_starts_with($this->state, $this->site . '/')) throw new RuntimeException('State/backup directory must be outside the webroot.');
        if (!is_dir($this->state) && !mkdir($this->state, 0700)) throw new RuntimeException('Cannot create private state directory.');
        if ((fileperms($this->state) & 0077) !== 0 || !is_writable($this->state)) throw new RuntimeException('State directory must be private (0700) and writable.');
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) throw new RuntimeException('Use an explicit HTTP(S) dynamic page URL, without credentials/query/fragment.');
        if ($timeout < 1 || $timeout > 10 || $attempts < 1 || $attempts > 5) throw new RuntimeException('Timeout must be 1..10 seconds; attempts 1..5.');
        $this->url = $url;
        foreach (['storage/update.lock', 'storage/write.lock'] as $relative) {
            $path = $this->site . '/' . $relative;
            self::noLinks($path);
            $new = !file_exists($path);
            // Close-on-exec: a probe/lint child must never retain locks after
            // the updater is killed, otherwise recovery could remain blocked.
            $lock = fopen($path, 'ce');
            if (!$lock) throw new RuntimeException('Cannot open site lock.');
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new APUpdateBusy('Site update or editorial write is already in progress. Retry later.'); }
            $this->locks[] = $lock;
            if ($new) $this->ownership($path, stat(dirname($path)), 0640);
        }
        $binding = $this->state . '/site.json';
        if (is_file($binding)) {
            $known = $this->readJson($binding);
            if ($known !== ['site' => $this->site, 'url' => $this->url]) throw new RuntimeException('State directory belongs to another site/health URL.');
        } else $this->json($binding, ['site' => $this->site, 'url' => $this->url]);
    }
    public function __destruct() { foreach ($this->locks as $lock) { flock($lock, LOCK_UN); fclose($lock); } }
    public static function noLinks(string $path): void {
        if (!str_starts_with($path, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x1f\x7f]~', $path)) throw new RuntimeException('Use absolute paths without symlinks or dot segments.');
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') throw new RuntimeException('Invalid empty path segment.');
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor) || (file_exists($cursor) && !is_file($cursor) && !is_dir($cursor))) throw new RuntimeException('Symlinks and special files are not supported.');
        }
    }
    public static function codePath(string $path): bool {
        return in_array($path, ['index.php', 'alt-cli', 'assets/base.css', 'assets/site.css', 'assets/admin.css', 'assets/admin.js', 'assets/favicon.svg', 'assets/theme.example.css'], true)
            || (bool) preg_match('~\A(?:lib|admin|bin)/[A-Za-z][A-Za-z0-9-]*\.php\z~D', $path);
    }
    private static function releaseFile(string $id): string { return "<?php\nreturn '" . $id . "';\n"; }
    public static function identity(array $files): string {
        unset($files['lib/update-release.php']); ksort($files, SORT_STRING);
        return hash('sha256', json_encode(array_map(static fn($bytes) => hash('sha256', $bytes), $files), JSON_THROW_ON_ERROR));
    }
    public static function pack(string $source, string $output): string {
        $source = rtrim($source, '/');
        self::noLinks($source); self::noLinks($output);
        if (file_exists($output)) throw new RuntimeException('Package output already exists.');
        $files = []; $total = 0;
        foreach (array_merge(self::REQUIRED, glob($source . '/{lib,admin,bin}/*.php', GLOB_BRACE) ?: []) as $path) {
            $relative = str_starts_with($path, $source . '/') ? substr($path, strlen($source) + 1) : $path;
            if (isset($files[$relative])) continue;
            if (!self::codePath($relative)) throw new RuntimeException('Invalid code path.');
            self::noLinks($source . '/' . $relative);
            if (!is_file($source . '/' . $relative) || filesize($source . '/' . $relative) > 2097152 || count($files) >= 128) throw new RuntimeException('Missing/oversized code file.');
            $bytes = file_get_contents($source . '/' . $relative);
            if (!is_string($bytes) || ($total += strlen($bytes)) > 8388608) throw new RuntimeException('Code package exceeds 8 MiB decoded.');
            $files[$relative] = $bytes;
        }
        $id = self::identity($files); $files['lib/update-release.php'] = self::releaseFile($id);
        $package = ['format' => 'alternpress-code', 'protocol' => 1, 'minimum_php' => 80200, 'id' => $id, 'files' => array_map('base64_encode', $files)];
        $bytes = gzencode(json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 9);
        if (!is_string($bytes) || strlen($bytes) > 4194304) throw new RuntimeException('Code package exceeds 4 MiB compressed.');
        $handle = fopen($output, 'x');
        if (!$handle) throw new RuntimeException('Cannot create code package.');
        try { if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot write code package.'); }
        finally { fclose($handle); }
        return hash('sha256', $bytes);
    }
    private function package(string $path, string $expected): array {
        self::noLinks($path);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $expected) || !is_file($path) || filesize($path) > 4194304) throw new RuntimeException('Require a local code package and independently trusted SHA-256.');
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || !hash_equals($expected, hash('sha256', $bytes))) throw new RuntimeException('Package SHA-256 mismatch.');
        $raw = gzdecode($bytes, 16777216);
        if (!is_string($raw)) throw new RuntimeException('Invalid compressed code package.');
        $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        if (($data['format'] ?? '') !== 'alternpress-code' || ($data['protocol'] ?? null) !== 1 || !is_int($data['minimum_php'] ?? null) || $data['minimum_php'] < 80200 || PHP_VERSION_ID < $data['minimum_php'] || !is_array($data['files'] ?? null) || count($data['files']) > 128) throw new RuntimeException('Unsupported code-package schema/PHP requirement.');
        $files = []; $total = 0;
        foreach ($data['files'] as $relative => $encoded) {
            if (!is_string($relative) || !self::codePath($relative) || !is_string($encoded)) throw new RuntimeException('Package contains a protected/non-code path.');
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || strlen($decoded) > 2097152 || ($total += strlen($decoded)) > 8388608) throw new RuntimeException('Invalid or oversized code file.');
            $files[$relative] = $decoded;
        }
        if (array_diff(self::REQUIRED, array_keys($files)) || !is_string($data['id'] ?? null) || !hash_equals(self::identity($files), $data['id']) || $files['lib/update-release.php'] !== self::releaseFile($data['id'])) throw new RuntimeException('Missing required code or invalid release identity.');
        $this->minimumPhp = $data['minimum_php'];
        return [$data['id'], $files];
    }
    protected function availableBytes(string $path): float { return disk_free_space($path) ?: 0; }
    private function ownership(string $path, array $metadata, int $mode): void {
        if ((fileowner($path) !== $metadata['uid'] && !chown($path, $metadata['uid'])) || (filegroup($path) !== $metadata['gid'] && !chgrp($path, $metadata['gid'])) || !chmod($path, $mode)) throw new RuntimeException('Cannot preserve file owner/group/mode.');
    }
    private function syncDirectory(string $path): void {
        $handle = fopen($path, 'r');
        if (!$handle) throw new RuntimeException('Cannot open directory for durability check.');
        try { if (!fsync($handle)) throw new RuntimeException('Filesystem does not support directory fsync.'); }
        finally { fclose($handle); }
    }
    protected function atomic(string $path, string $bytes, ?array $metadata = null): void {
        self::noLinks($path);
        $temporary = dirname($path) . '/.ap-update-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x');
        if (!$handle) throw new RuntimeException('Cannot create atomic replacement.');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot persist replacement file.');
            $this->ownership($temporary, $metadata ?? stat(dirname($path)), $metadata ? $metadata['mode'] & 0777 : 0600);
            // OPcache validates timestamps to whole seconds. A same-second rename
            // must not leave old bytecode looking current (also during rollback).
            if ((str_ends_with($path, '.php') || basename($path) === 'alt-cli') && is_file($path)) {
                $stamp = time();
                if (filemtime($path) === $stamp) $stamp++;
                if (!touch($temporary, $stamp)) throw new RuntimeException('Cannot advance code timestamp for opcode-cache validation.');
            }
            if (!fsync($handle)) throw new RuntimeException('Cannot persist replacement metadata.');
            fclose($handle); $handle = null;
            if (!rename($temporary, $path)) throw new RuntimeException('Cannot atomically replace code/journal.');
            $this->syncDirectory(dirname($path));
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }
    private function json(string $path, array $data): void { $this->atomic($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"); }
    private function readJson(string $path): array {
        self::noLinks($path);
        if (!is_file($path) || filesize($path) > 1048576) throw new RuntimeException('Missing/oversized update journal.');
        $data = json_decode((string) file_get_contents($path), true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid journal.');
        return $data;
    }
    private function log(string $event, string $message = ''): void {
        $path = $this->state . '/update.log'; self::noLinks($path);
        $line = json_encode(['time' => gmdate('c'), 'event' => $event, 'message' => $message], JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line) || !chmod($path, 0600)) throw new RuntimeException('Cannot write update log.');
        fwrite(STDERR, $event . ($message === '' ? '' : ': ' . $message) . "\n");
    }
    private function control(string $name, ?array $data): void {
        $path = $this->site . '/storage/update-' . $name . '.php'; self::noLinks($path);
        if ($data === null) {
            if (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot remove maintenance/probe marker.');
            $this->syncDirectory(dirname($path));
        } else {
            $owner = stat(dirname($path)); $owner['mode'] = 0640;
            $this->atomic($path, "<?php exit; ?>\n" . json_encode($data, JSON_THROW_ON_ERROR), $owner);
        }
    }
    private function localIdentity(): string {
        $path = $this->site . '/lib/update-release.php'; self::noLinks($path);
        if (!is_file($path) || !preg_match("/return '(source|[a-f0-9]{64})';/", (string) file_get_contents($path), $match)) throw new RuntimeException('Install/review the update-health protocol first; legacy sites cannot be updated blindly.');
        return $match[1];
    }
    /** The isolated CLI worker never loads site code. Parent bounds DNS/header/body time too. */
    public static function httpProbe(array $input): array {
        [$url, $token, $identity, $timeout, $minimumPhp] = $input;
        if (!is_string($url) || strlen($url) > 2048 || !is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token) || !is_string($identity) || !is_int($timeout) || $timeout < 1 || $timeout > 10 || !is_int($minimumPhp)) throw new RuntimeException('Invalid HTTP worker input.');
        $deadline = microtime(true) + $timeout;
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Accept: text/html\r\nAccept-Encoding: identity\r\nCache-Control: no-cache\r\nConnection: close\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($url . '?__ap_update_probe=' . $token, 'rb', false, $context);
        if (!is_resource($stream)) return ['ok' => false, 'reason' => 'HTTP connection/headers unavailable'];
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        stream_set_blocking($stream, false); $body = '';
        while (!feof($stream) && microtime(true) < $deadline && strlen($body) <= 8388608) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) break;
            $body .= $chunk;
            if ($chunk === '') usleep(10000);
        }
        $complete = feof($stream) && microtime(true) <= $deadline;
        fclose($stream);
        $get = static function(string $name) use ($headers): string {
            foreach ($headers as $header) if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
            return '';
        };
        $diagnostic = ['status' => $headers[0] ?? '', 'complete' => $complete, 'protocol' => $get('X-AlternPress-Update-Protocol') === '1', 'ready' => $get('X-AlternPress-Update-Ready') === '1', 'php' => (int) $get('X-AlternPress-PHP') >= $minimumPhp, 'identity' => hash_equals($identity, $get('X-AlternPress-Release')), 'no_cache' => $get('X-AlternPress-Cache') === 'BYPASS' && $get('Cache-Control') === 'no-store', 'challenge' => str_contains($body, '<meta name="alternpress-update-probe" content="' . $token . '">') && str_contains($body, '</html>')];
        $ok = (bool) preg_match('~^HTTP/\S+ 200(?: |$)~', $diagnostic['status']);
        foreach (array_diff_key($diagnostic, ['status' => true]) as $value) $ok = $ok && $value;
        return ['ok' => $ok, 'checks' => $diagnostic];
    }
    private function boundedProbe(string $token, string $identity): array {
        $process = proc_open([PHP_BINARY, __FILE__, '__http_probe'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return ['ok' => false, 'reason' => 'Cannot start HTTP worker'];
        $output = ''; $finished = false; $deadline = hrtime(true) + $this->timeout * 1000000000;
        try {
            fwrite($pipes[0], json_encode([$this->url, $token, $identity, $this->timeout, $this->minimumPhp], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            do {
                $output .= stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { $finished = true; $output .= stream_get_contents($pipes[1]); break; }
                usleep(10000);
            } while (hrtime(true) < $deadline && strlen($output) <= 65536);
        } finally {
            if (!$finished) { proc_terminate($process); usleep(20000); if (proc_get_status($process)['running']) proc_terminate($process, 9); }
            if (is_resource($pipes[0])) fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if (!$finished) return ['ok' => false, 'reason' => 'HTTP total deadline exceeded'];
        $result = json_decode($output, true);
        return is_array($result) && isset($result['ok']) ? $result : ['ok' => false, 'reason' => 'HTTP worker failed'];
    }
    /** Real HTTP with random short-lived challenges, bounded attempts and no redirect following. */
    protected function health(string $identity): bool {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $token = bin2hex(random_bytes(32));
            $this->control('probe', ['token' => $token, 'expires' => time() + $this->timeout + 15]);
            $result = $this->boundedProbe($token, $identity);
            if ($result['ok'] === true) return true;
            $this->log('health_response', json_encode($result, JSON_THROW_ON_ERROR));
            $this->log('health_retry', 'attempt ' . $attempt . '/' . $this->attempts);
            if ($attempt < $this->attempts) usleep(250000);
        }
        return false;
    }
    private function persist(string $phase): void {
        $this->journal['phase'] = $phase;
        $this->json($this->transaction . '/journal.json', $this->journal);
        $this->log($phase);
    }
    private function load(): bool {
        if (!is_file($this->state . '/current.json')) return false;
        $pointer = $this->readJson($this->state . '/current.json');
        if (!is_string($pointer['transaction'] ?? null) || !preg_match('/\A[a-f0-9]{24}\z/D', $pointer['transaction'])) throw new RuntimeException('Invalid transaction pointer.');
        $this->transaction = $this->state . '/' . $pointer['transaction'];
        $this->journal = $this->readJson($this->transaction . '/journal.json');
        if (($this->journal['site'] ?? '') !== $this->site || !is_array($this->journal['files'] ?? null) || count($this->journal['files']) > 256) throw new RuntimeException('Invalid transaction journal.');
        return true;
    }
    private function runtimePreflight(): void {
        if (PHP_VERSION_ID < 80200 || PHP_OS_FAMILY === 'Windows' || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) throw new RuntimeException('Need PHP >=8.2 on Unix and allow_url_fopen.');
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'fsync'] as $function) if (!function_exists($function)) throw new RuntimeException('Required PHP function is disabled: ' . $function);
        foreach (self::EXTENSIONS as $extension) if (!extension_loaded($extension)) throw new RuntimeException('Missing PHP extension: ' . $extension);
        if (ini_get('memory_limit') !== '-1' && ini_parse_quantity(ini_get('memory_limit')) < 100663296) throw new RuntimeException('CLI updater needs at least 96 MiB memory_limit, or -1.');
    }
    private function preflight(array $files): void {
        $bytes = array_sum(array_map('strlen', $files));
        $old = 0;
        foreach ($files as $relative => $_) {
            $path = $this->site . '/' . $relative; self::noLinks($path);
            if (!is_dir(dirname($path)) || !is_writable(dirname($path)) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Code directory is not writable or is invalid: ' . $relative);
            if (is_file($path)) $old += filesize($path);
        }
        $needed = 67108864 + 2 * ($bytes + $old);
        if ($this->availableBytes($this->site) < $needed || $this->availableBytes($this->state) < $needed) throw new RuntimeException('Insufficient free space (code copies plus 64 MiB reserve).');
        $this->syncDirectory($this->site); $this->syncDirectory($this->state);
    }
    public function apply(string $package, string $sha): int {
        $published = false;
        try {
            if ($this->load() && !in_array($this->journal['phase'] ?? '', ['committed', 'rolled_back'], true)) throw new RuntimeException('Unfinished update: run recover with the same site/state/URL first.');
            if (is_file($this->site . '/storage/update-maintenance.php')) throw new RuntimeException('Maintenance marker present: recover before starting another update.');
            $this->runtimePreflight();
            [$identity, $files] = $this->package($package, $sha);
            $this->preflight($files);
            $oldIdentity = $this->localIdentity();
            if (!$this->health($oldIdentity)) throw new RuntimeException('Current dynamic page/protocol/runtime is not healthy; no code changed. Check PHP web extensions and OPcache settings.');
            $name = bin2hex(random_bytes(12)); $this->transaction = $this->state . '/' . $name;
            if (!mkdir($this->transaction, 0700) || !mkdir($this->transaction . '/old', 0700) || !mkdir($this->transaction . '/stage', 0700)) throw new RuntimeException('Cannot create transaction staging/backup.');
            // Only packages and our previous journal own code. Never claim arbitrary local files.
            $inventory = array_unique(array_merge(array_keys($files), array_keys($this->journal['files'] ?? [])));
            $entries = [];
            foreach ($inventory as $relative) {
                if (!self::codePath($relative)) throw new RuntimeException('Invalid managed code path.');
                $path = $this->site . '/' . $relative; self::noLinks($path);
                if (is_file($path) && filesize($path) > 8388608) throw new RuntimeException('Current managed code file exceeds backup limit.');
                $oldBytes = is_file($path) ? file_get_contents($path) : null;
                if ($oldBytes !== null && !is_string($oldBytes)) throw new RuntimeException('Unreadable current code.');
                $metadata = $oldBytes !== null ? stat($path) : stat(dirname($path));
                if ($oldBytes === null) $metadata['mode'] = 0644;
                $key = hash('sha256', $relative);
                if ($oldBytes !== null) $this->atomic($this->transaction . '/old/' . $key, $oldBytes);
                if (isset($files[$relative])) {
                    $staged = $this->transaction . '/stage/' . $key;
                    $this->atomic($staged, $files[$relative]);
                    if (str_ends_with($relative, '.php') || $relative === 'alt-cli') {
                        $process = proc_open([PHP_BINARY, '-l', $staged], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                        if (!is_resource($process)) throw new RuntimeException('Cannot start staging lint.');
                        fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                        if (proc_close($process) !== 0) throw new RuntimeException('Staging PHP lint failed: ' . $relative . ' ' . trim($output));
                    }
                }
                $entries[$relative] = ['key' => $key, 'old' => $oldBytes === null ? null : hash('sha256', $oldBytes), 'new' => isset($files[$relative]) ? hash('sha256', $files[$relative]) : null, 'metadata' => ['uid' => $metadata['uid'], 'gid' => $metadata['gid'], 'mode' => $metadata['mode']]];
                // Exercise the actual destination's write/metadata/fsync permissions before activation.
                $test = dirname($path) . '/.ap-update-preflight-' . bin2hex(random_bytes(8));
                try { $this->atomic($test, '', $metadata); } finally { if (is_file($test)) unlink($test); }
            }
            $this->journal = ['site' => $this->site, 'old_identity' => $oldIdentity, 'new_identity' => $identity, 'files' => $entries, 'phase' => 'prepared'];
            $this->json($this->transaction . '/journal.json', $this->journal);
            $this->json($this->state . '/current.json', ['transaction' => $name]);
            $published = true;
            $this->persist('activating');
            $this->control('maintenance', ['started' => gmdate('c'), 'state' => $this->state]);
            try {
                foreach ($entries as $relative => $entry) $this->install($relative, $entry, 'new');
                $this->persist('verifying');
                if (!$this->health($identity)) throw new RuntimeException('New dynamic release failed HTTP health checks.');
                $this->control('maintenance', null);
                $this->persist('committed');
                return 0;
            } catch (Throwable $error) {
                throw $error;
            }
        } catch (Throwable $error) {
            try { $this->log($published ? 'update_failed' : 'preflight_failed', $error->getMessage()); }
            catch (Throwable) { fwrite(STDERR, "cannot persist failure log\n"); }
            return $published ? ($this->restore() ? 2 : 3) : 1;
        }
        finally {
            if (in_array($this->journal['phase'] ?? '', ['committed', 'rolled_back', 'prepared', ''], true)) {
                try { $this->control('probe', null); }
                catch (Throwable) { fwrite(STDERR, "warning: probe cleanup incomplete; short-lived challenge will expire\n"); }
            }
        }
    }
    private function install(string $relative, array $entry, string $which, bool $validateOnly = false): void {
        if (!self::codePath($relative) || ($entry['key'] ?? '') !== hash('sha256', $relative)) throw new RuntimeException('Invalid code journal entry.');
        $path = $this->site . '/' . $relative; self::noLinks($path);
        $current = is_file($path) ? hash_file('sha256', $path) : null;
        if ($current !== $entry['old'] && $current !== $entry['new']) throw new RuntimeException('Concurrent code change; refusing to overwrite: ' . $relative);
        $expected = $entry[$which];
        if ($current === $expected) return;
        if ($expected === null) {
            if ($validateOnly) return;
            if (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot remove obsolete managed code.');
            $this->syncDirectory(dirname($path)); return;
        }
        $source = $this->transaction . '/' . ($which === 'old' ? 'old' : 'stage') . '/' . $entry['key']; self::noLinks($source);
        $bytes = file_get_contents($source);
        if (!is_string($bytes) || !hash_equals($expected, hash('sha256', $bytes))) throw new RuntimeException('Backup/staging checksum mismatch: ' . $relative);
        if ($validateOnly) return;
        $this->atomic($path, $bytes, $entry['metadata']);
    }
    private function restore(): bool {
        try {
            $this->persist('rolling_back');
            $this->control('maintenance', ['recovery' => true, 'state' => $this->state]);
            foreach ($this->journal['files'] as $relative => $entry) $this->install($relative, $entry, 'old', true);
            foreach ($this->journal['files'] as $relative => $entry) $this->install($relative, $entry, 'old');
            $this->minimumPhp = 80200;
            if (!$this->health($this->journal['old_identity'])) throw new RuntimeException('Previous code restored but its dynamic HTTP health check failed.');
            $this->control('maintenance', null);
            $this->persist('rolled_back');
            $this->control('probe', null);
            return true;
        } catch (Throwable $error) {
            try { $this->persist('recovery_required'); $this->log('rollback_failed', $error->getMessage()); }
            catch (Throwable) { fwrite(STDERR, 'recovery_required: ' . $error->getMessage() . "\n"); }
            return false;
        }
    }
    public function recover(bool $manualRollback = false): int {
        try {
            if (!$this->load()) {
                if (is_file($this->site . '/storage/update-maintenance.php')) throw new RuntimeException('Maintenance has no matching journal: use its original state directory.');
                $this->log('no_transaction'); return 0;
            }
            if (($this->journal['phase'] ?? '') === 'committed' && !$manualRollback) {
                if (is_file($this->site . '/storage/update-maintenance.php')) throw new RuntimeException('Unexpected maintenance marker: inspect it before choosing rollback.');
                $this->log('already_committed'); return 0;
            }
            return $this->restore() ? 0 : 3;
        } catch (Throwable $error) { fwrite(STDERR, 'recovery_required: ' . $error->getMessage() . "\n"); return 3; }
    }
}

function ap_update_main(array $argv): int {
    $command = $argv[1] ?? 'help';
    if ($command === '__http_probe') {
        $input = json_decode((string) stream_get_contents(STDIN, 16384), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !array_is_list($input) || count($input) !== 5) throw new RuntimeException('Invalid worker request.');
        echo json_encode(APCodeUpdater::httpProbe($input), JSON_THROW_ON_ERROR);
        return 0;
    }
    if ($command === 'help') {
        echo "Code-only updater (Unix/PHP 8.2+). Never run from the site's code being replaced.\n"
            . "  php /trusted/update.php pack --source /trusted/release --output /trusted/release.ap.gz\n"
            . "  php /trusted/update.php apply --site /var/www/blog --state /var/backups/blog-update --url https://example.org/it --package /trusted/release.ap.gz --sha256 TRUSTED_SHA256\n"
            . "  php /trusted/update.php recover|rollback --site /var/www/blog --state /var/backups/blog-update --url https://example.org/it\n"
            . "Options: --timeout 1..10 (default 3), --attempts 1..5 (default 3).\nExit: 0 success, 1 preflight failure, 2 update failed/previous code verified, 3 recovery required, 4 busy.\n";
        return 0;
    }
    $options = [];
    for ($i = 2; $i < count($argv); $i += 2) {
        if (!preg_match('/\A--([a-z0-9-]+)\z/D', $argv[$i], $match) || !isset($argv[$i + 1]) || isset($options[$match[1]])) throw new RuntimeException('Expected unique --option value pairs.');
        $options[$match[1]] = $argv[$i + 1];
    }
    $allowed = $command === 'pack' ? ['source', 'output'] : ['site', 'state', 'url', 'timeout', 'attempts', ...($command === 'apply' ? ['package', 'sha256'] : [])];
    if (array_diff(array_keys($options), $allowed)) throw new RuntimeException('Unknown option.');
    $need = static fn(string $key): string => $options[$key] ?? throw new RuntimeException('Missing --' . $key);
    if ($command === 'pack') { echo APCodeUpdater::pack($need('source'), $need('output')) . "\n"; return 0; }
    if (!in_array($command, ['apply', 'recover', 'rollback'], true)) throw new RuntimeException('Unknown command; run help.');
    $updater = new APCodeUpdater($need('site'), $need('state'), $need('url'), (int) ($options['timeout'] ?? 3), (int) ($options['attempts'] ?? 3));
    return $command === 'apply' ? $updater->apply($need('package'), $need('sha256')) : $updater->recover($command === 'rollback');
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(ap_update_main($argv)); }
    catch (APUpdateBusy $error) { fwrite(STDERR, 'busy: ' . $error->getMessage() . "\n"); exit(4); }
    catch (Throwable $error) { fwrite(STDERR, 'failed: ' . $error->getMessage() . "\n"); exit(1); }
}
