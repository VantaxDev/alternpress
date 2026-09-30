<?php
declare(strict_types=1);

require_once __DIR__ . '/Parsedown.php';

/**
 * Read-only site diagnostics. No network requests, chmod, deletion or cache writes.
 * Call after bootstrap (and after defining t() for translated messages).
 * Options are for trusted callers/tests, never pass request parameters directly.
 */
function ap_diagnostics(array $options = []): array
{
    return (new AP_Diagnostics($options))->run();
}

final class AP_Diagnostics
{
    private string $root;
    private string $base;
    private string $defaultLanguage;
    private array $languages;
    private array $options;
    private array $aliases = [];
    private array $files = [];
    private array $directories = [];
    private array $documents = [];
    private array $usedImages = [];
    private array $report;
    private float $started;
    private int $entries = 0;
    private int $bytes = 0;
    private bool $limited = false;

    public function __construct(array $options)
    {
        $config = static fn(string $name, mixed $fallback = null): mixed => function_exists('ap_config') ? (ap_config($name) ?? $fallback) : $fallback;
        $this->options = $options + [
            'root' => defined('AP_ROOT') ? AP_ROOT : dirname(__DIR__),
            'base_path' => function_exists('ap_base') ? ap_base() : '',
            'origin' => defined('SITE_ORIGIN') ? SITE_ORIGIN : $config('origin', ''),
            'site_url' => $config('site_url', ''), 'custom_css' => $config('custom_css', ''),
            'languages' => $config('languages', ['it', 'en']), 'default_language' => $config('default_language', 'it'),
            'max_files' => 25000, 'max_seconds' => 15, 'max_bytes' => 32 * 1024 * 1024,
        ];
        $root = realpath((string) $this->options['root']);
        if (!$root || !is_dir($root) || is_link((string) $this->options['root'])) throw new RuntimeException('Invalid diagnostics root.');
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->base = rtrim((string) $this->options['base_path'], '/');
        $this->languages = $this->options['languages'];
        $this->defaultLanguage = (string) $this->options['default_language'];
        $this->started = microtime(true);
        $this->report = [
            'generated_at' => gmdate('c'), 'complete' => true,
            'summary' => ['directories' => 0, 'files' => 0, 'markdown' => 0, 'links_checked' => 0, 'missing_links' => 0, 'external_skipped' => 0, 'orphans' => 0, 'permission_issues' => 0, 'warnings' => 0, 'errors' => 0],
            'issues' => [], 'orphans' => [], 'permissions' => [], 'notes' => [],
        ];
        foreach (['origin', 'site_url'] as $setting) {
            $url = parse_url((string) $this->options[$setting]);
            if (!is_array($url) || empty($url['host'])) continue;
            $prefix = $setting === 'origin' ? $this->base : rtrim($url['path'] ?? '', '/');
            $key = strtolower($url['host']) . ':' . ($url['port'] ?? (($url['scheme'] ?? 'https') === 'https' ? 443 : 80));
            $this->aliases[$key][] = $prefix;
        }
    }

    private function text(string $it, string $en): string { return function_exists('t') ? t($it, $en) : $en; }

    private function issue(string $severity, string $code, string $it, string $en, string $path = '', string $url = ''): void
    {
        $this->report['issues'][] = ['severity' => $severity, 'code' => $code, 'message' => $this->text($it, $en), 'path' => $path, 'url' => $url];
        $this->report['summary'][$severity === 'error' ? 'errors' : 'warnings']++;
    }

    private function withinBudget(): bool
    {
        if ($this->limited) return false;
        if ($this->entries > (int) $this->options['max_files'] || $this->bytes > (int) $this->options['max_bytes'] || microtime(true) - $this->started > (float) $this->options['max_seconds']) {
            $this->limited = true;
            $this->report['complete'] = false;
            $this->issue('warning', 'scan_limit', 'Scansione interrotta al limite di tempo o dimensione. I risultati sono parziali; le foto orfane non sono determinate.', 'Scan stopped at its time or size limit. Results are partial; orphan photos cannot be determined.');
            return false;
        }
        return true;
    }

    private function walk(string $relative, bool $permissions): void
    {
        if (!$this->withinBudget()) return;
        $path = $this->root . '/' . $relative;
        $this->entries++;
        if (is_link($path)) {
            $this->report['complete'] = false;
            $this->issue('warning', 'symlink_skipped', 'Collegamento simbolico escluso: nessun percorso esterno viene seguito.', 'Symbolic link skipped: outside paths are never followed.', $relative);
            return;
        }
        if (!file_exists($path)) {
            $this->issue('error', 'missing_directory', 'Cartella necessaria mancante.', 'Required directory is missing.', $relative);
            $this->report['summary']['permission_issues']++;
            return;
        }
        $directory = is_dir($path);
        if (!$directory && !is_file($path)) return;
        $readable = is_readable($path);
        $writable = is_writable($path);
        $searchable = !$directory || DIRECTORY_SEPARATOR === '\\' || is_executable($path);
        $mode = @fileperms($path);
        // Protection files need reading, not editing. Complete CLI backups may
        // deliberately belong only to the SSH administrator; the panel uses
        // alternpress-data-* archives instead.
        $protectionFile = !$directory && in_array(basename($relative), ['.htaccess', '.gitkeep'], true);
        $privateCliBackup = !$directory && (bool) preg_match('~^storage/backups/alternpress-[0-9]{8}-[0-9]{6}-[a-f0-9]+\.tar\.gz(?:\.sha256)?$~D', $relative);
        if ($permissions) {
            $this->report['summary'][$directory ? 'directories' : 'files']++;
            $this->report['permissions'][] = ['path' => $relative, 'type' => $directory ? 'directory' : 'file', 'readable' => $readable, 'writable' => $writable, 'searchable' => $searchable, 'mode' => $mode === false ? '?' : sprintf('%04o', $mode & 07777)];
            foreach (['readable' => $readable, 'writable' => $writable, 'searchable' => $searchable] as $kind => $ok) {
                if ($ok) continue;
                if ($privateCliBackup || ($protectionFile && $kind === 'writable')) continue;
                $labels = ['readable' => ['Lettura non consentita al processo PHP.', 'PHP cannot read this path.'], 'writable' => ['Scrittura non consentita al processo PHP.', 'PHP cannot write this path.'], 'searchable' => ['Il processo PHP non può attraversare questa cartella.', 'PHP cannot traverse this directory.']];
                $this->issue('error', 'not_' . $kind, $labels[$kind][0], $labels[$kind][1], $relative);
                $this->report['summary']['permission_issues']++;
            }
            if ($mode !== false && DIRECTORY_SEPARATOR !== '\\' && ($mode & 0002)) {
                $this->issue('warning', 'world_writable', 'Percorso scrivibile da tutti gli utenti del server.', 'Path is writable by every server user.', $relative);
                $this->report['summary']['permission_issues']++;
            }
        }
        if (!$directory) { $this->files[$relative] = $path; return; }
        $this->directories[$relative] = true;
        if (!$readable || !$searchable) { $this->report['complete'] = false; return; }
        $children = @scandir($path);
        if ($children === false) {
            $this->report['complete'] = false;
            $this->issue('error', 'directory_unreadable', 'Impossibile elencare questa cartella.', 'Cannot list this directory.', $relative);
            return;
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') continue;
            $this->walk($relative . '/' . $child, $permissions);
            if (!$this->withinBudget()) break;
        }
    }

    private function read(string $relative): ?string
    {
        if (!$this->withinBudget() || !isset($this->files[$relative]) || is_link($this->files[$relative])) return null;
        $size = @filesize($this->files[$relative]);
        if ($size === false || $size > 2 * 1024 * 1024) {
            $this->report['complete'] = false;
            $this->issue('warning', 'source_too_large', 'Sorgente non leggibile o oltre 2 MB: non analizzata.', 'Source is unreadable or larger than 2 MB: not analyzed.', $relative);
            return null;
        }
        $this->bytes += $size;
        if (!$this->withinBudget()) return null;
        $raw = @file_get_contents($this->files[$relative]);
        if ($raw === false) {
            $this->report['complete'] = false;
            $this->issue('error', 'source_unreadable', 'Impossibile leggere la sorgente.', 'Cannot read source.', $relative);
            return null;
        }
        return $raw;
    }

    private function parseDocument(string $raw): array
    {
        if (function_exists('parse_front_matter')) return parse_front_matter($raw);
        $meta = []; $body = $raw;
        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R/s', $raw, $match)) {
            $body = substr($raw, strlen($match[0]));
            foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
                if (!preg_match('/^([a-z_]+):\s*(.*)$/i', trim($line), $pair)) continue;
                $value = trim($pair[2]);
                if (str_starts_with($value, '"') && str_ends_with($value, '"')) $value = json_decode($value, true) ?? trim($value, '"');
                elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) $value = substr($value, 1, -1);
                $meta[strtolower($pair[1])] = $value;
            }
        }
        return [$meta, $body];
    }

    private function normalizePath(string $path): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) return null;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') { if (!$segments) return null; array_pop($segments); }
            else $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /** Resolve only URL paths; do not perform DNS, HTTP or access private storage. */
    private function resolve(string $url, string $context): array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(?:mailto|tel|data):/i', $url)) return ['skip', ''];
        if (str_starts_with($url, '@media/')) $url = '/media/' . substr($url, 7);
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return ['invalid', ''];
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return ['skip', ''];
        $path = rawurldecode($parts['path'] ?? '');
        if (isset($parts['host'])) {
            $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $key = strtolower($parts['host']) . ':' . $port;
            foreach ($this->aliases[$key] ?? [] as $prefix) {
                if ($prefix !== '' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) continue;
                return ['local', $this->normalizePath(substr($path, strlen($prefix)) ?: '/')];
            }
            return ['external', ''];
        }
        if (str_starts_with($path, '/')) {
            if ($this->base !== '' && ($path === $this->base || str_starts_with($path, $this->base . '/'))) $path = substr($path, strlen($this->base));
        } else {
            $path = $path === '' ? $context : substr($context, 0, (int) strrpos($context, '/') + 1) . $path;
        }
        return ['local', $this->normalizePath($path ?: '/')];
    }

    private function staticFile(string $relative): bool
    {
        if ($relative === '' || preg_match('~(?:^|/)\.|^(?:content|cache|storage|lib|bin|admin)(?:/|$)|\.php(?:$|/)~i', $relative)) return false;
        $path = $this->root;
        foreach (explode('/', $relative) as $segment) { $path .= '/' . $segment; if (is_link($path)) return false; }
        return is_file($path) && is_readable($path);
    }

    private function exists(string $path): bool
    {
        if ($path === '/index.php') return true;
        if ($this->staticFile(ltrim($path, '/'))) return true;
        $segments = explode('/', trim($path, '/'));
        $language = in_array($segments[0], $this->languages, true) ? array_shift($segments) : $this->defaultLanguage;
        $route = '/' . implode('/', $segments);
        if ($route === '/' || $route === '/home') return isset($this->documents['content/' . $language . '/home/index.md']);
        if (in_array($route, ['/about', '/skills'], true)) return isset($this->documents['content/' . $language . $route . '/index.md']);
        if (in_array($route, ['/archive', '/tags'], true) || preg_match('~^/tag/[^/]+$~u', $route)) return isset($this->directories['content/' . $language]);
        if (preg_match('~^/post/([a-z0-9]+(?:-[a-z0-9]+)*)(?:/([^/]+\.(?:jpg|jpeg|png|webp|gif)))?$~i', $route, $match)) {
            $prefix = 'content/' . $language . '/post/' . $match[1];
            $document = $this->documents[$prefix . '/index.md'] ?? null;
            if (!$document || ($document['meta']['status'] ?? 'published') !== 'published') return false;
            return empty($match[2]) || (isset($this->files[$prefix . '/' . $match[2]]) && is_readable($this->files[$prefix . '/' . $match[2]]));
        }
        return false;
    }

    private function checkLink(string $url, string $source, string $context, bool $image): void
    {
        [$kind, $path] = $this->resolve($url, $context);
        if ($kind === 'skip') return;
        if ($kind === 'external') { $this->report['summary']['external_skipped']++; return; }
        $this->report['summary']['links_checked']++;
        if ($path !== null) {
            if (str_starts_with($path, '/media/')) $this->usedImages[ltrim($path, '/')] = true;
            $segments = explode('/', trim($path, '/'));
            $language = in_array($segments[0], $this->languages, true) ? array_shift($segments) : $this->defaultLanguage;
            $route = '/' . implode('/', $segments);
            if (preg_match('~^/post/([a-z0-9]+(?:-[a-z0-9]+)*)/([^/]+)$~i', $route, $attachment)) $this->usedImages['content/' . $language . '/post/' . $attachment[1] . '/' . $attachment[2]] = true;
        }
        if ($kind === 'local' && $path !== null && $this->exists($path)) return;
        $this->report['summary']['missing_links']++;
        $this->issue('error', $image ? 'missing_image' : 'missing_link', $image ? 'Immagine locale mancante o non accessibile dal sito pubblico.' : 'Collegamento locale mancante o destinazione non pubblicata.', $image ? 'Local image is missing or inaccessible on the public site.' : 'Local link is missing or its target is unpublished.', $source, $url);
    }

    private function css(string $css, string $source, string $context): void
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        preg_match_all('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s)]*))\s*\)~i', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) $this->checkLink($match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')), $source, $context, true);
    }

    private function withoutCode(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $fence = null; $width = 0;
        foreach ($lines as &$line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
                if ($fence === null) { $fence = $match[1][0]; $width = strlen($match[1]); }
                elseif ($fence === $match[1][0] && strlen($match[1]) >= $width) $fence = null;
                $line = ''; continue;
            }
            if ($fence !== null) $line = '';
        }
        unset($line);
        return preg_replace('/(`+)[^\r\n]*?\1/', '', implode("\n", $lines)) ?? '';
    }

    public function run(): array
    {
        clearstatcache();
        foreach (['content', 'cache', 'storage', 'media'] as $directory) $this->walk($directory, true);
        if (is_dir($this->root . '/assets')) $this->walk('assets', false);
        foreach ($this->files as $relative => $_) {
            if (!str_starts_with($relative, 'content/') || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'md') continue;
            $raw = $this->read($relative);
            if ($raw === null) continue;
            [$meta, $body] = $this->parseDocument($raw);
            $this->documents[$relative] = ['meta' => $meta, 'body' => $body];
            $this->report['summary']['markdown']++;
        }
        $renderer = new Parsedown();
        foreach ($this->documents as $relative => $document) {
            if (!$this->withinBudget()) break;
            $route = substr(dirname($relative), strlen('content'));
            $post = preg_match('~^/([^/]+)/post/([^/]+)$~', $route, $postParts) === 1;
            // Posts explicitly rewrite relative links to a directory URL. Pages use the browser's document URL.
            $context = $post ? $route . '/' : $route;
            if (preg_match('~^/([^/]+)/home$~', $route, $home)) $context = '/' . $home[1];
            $html = $renderer->text($this->withoutCode($document['body']));
            preg_match_all('~<(a|img)\b[^>]*\b(?:href|src)="([^"]*)"~i', $html, $links, PREG_SET_ORDER);
            $seen = [];
            foreach ($links as $link) {
                $key = $link[1] . ':' . $link[2];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $this->checkLink($link[2], $relative, $context, $link[1] === 'img');
            }
            $cover = (string) ($document['meta']['cover'] ?? '');
            if ($cover !== '') {
                if ($post && !preg_match('~^(?:https?://|/|@media/)~i', $cover)) $cover = $route . '/' . rawurlencode(basename($cover));
                $this->checkLink($cover, $relative . ' (cover)', $context, true);
            }
        }
        foreach ($this->files as $relative => $_) {
            if (!str_starts_with($relative, 'assets/') || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'css') continue;
            $css = $this->read($relative);
            if ($css !== null) $this->css($css, $relative, '/' . $relative);
        }
        $this->css((string) $this->options['custom_css'], 'settings:custom_css', '/index.php');
        if ($this->report['complete']) {
            foreach ($this->files as $relative => $absolute) {
                $upload = str_starts_with($relative, 'media/');
                $legacy = preg_match('~^content/[^/]+/post/[^/]+/[^/]+\.(?:jpe?g|png|webp|gif|avif)$~i', $relative) === 1;
                if ((!$upload && !$legacy) || !preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg)$/i', $relative) || isset($this->usedImages[$relative])) continue;
                $this->report['orphans'][] = ['path' => $relative, 'size' => (int) filesize($absolute)];
            }
        }
        $this->report['summary']['orphans'] = count($this->report['orphans']);
        $this->report['notes'][] = $this->text('I permessi sono valutati per l’utente che esegue PHP, senza modificarli. Da terminale usare l’utente del sito, per esempio www-data.', 'Permissions are evaluated for the current PHP user without changing them. From the terminal, run as the site user, for example www-data.');
        $this->report['notes'][] = $this->text('Nessuna richiesta di rete: i link esterni sono contati ma non verificati. I frammenti #ancora non vengono controllati.', 'No network requests: external links are counted but not checked. #anchor fragments are not validated.');
        $this->report['notes'][] = $this->text('Le foto orfane sono candidate in media/ e nelle cartelle degli articoli, non referenziate in articoli, bozze, pagine, copertine e CSS locali. Non vengono cancellate; eventuali usi in siti esterni o codice personalizzato vanno verificati manualmente.', 'Orphan photos are candidates in media/ and article folders, unreferenced by articles, drafts, pages, covers and local CSS. Nothing is deleted; use on other sites or in custom code needs manual review.');
        if (!$this->report['complete']) $this->report['notes'][] = $this->text('Scansione incompleta: nessun elenco affidabile di foto orfane disponibile.', 'Incomplete scan: no reliable orphan-photo list is available.');
        return $this->report;
    }
}
