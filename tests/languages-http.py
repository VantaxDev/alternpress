"""Config-only multilingual routing/editor/discovery/cache tests on disposable data."""
from pathlib import Path
import hashlib
import html
import http.cookiejar
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

SOURCE = Path(__file__).resolve().parents[1]
checks = 0


def expect(condition, label):
    global checks
    assert condition, label
    checks += 1
    print('PASS ' + label, flush=True)


def php(value):
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if isinstance(value, list):
        return '[' + ','.join(php(item) for item in value) + ']'
    if isinstance(value, dict):
        return '[' + ','.join(php(key) + '=>' + php(item) for key, item in value.items()) + ']'
    raise TypeError(value)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


with tempfile.TemporaryDirectory(prefix='alternpress-languages-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(SOURCE, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    core = {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in root.rglob('*.php') if not str(path.relative_to(root)).startswith('storage/')}
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    prefix = '/blog'
    base = origin + prefix

    def config(languages, default):
        values = {'languages': languages, 'default_language': default, 'origin': origin, 'base_path': prefix, 'site_url': base}
        (root / 'config.local.php').write_text('<?php\nreturn ' + php(values) + ';\n')

    def document(relative, title, body, status='published'):
        path = root / 'content' / relative / 'index.md'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f'---\ntitle: {title}\nstatus: {status}\ndate: 2026-01-02\n---\n\n{body}\n', encoding='utf-8')
        return path

    config(['en', 'it', 'de'], 'en')
    password = 'Only-A-Language-Test-2026!'
    subprocess.run(['php', str(root / 'alt-cli'), 'password', '--stdin'], input=(password + '\n').encode(), capture_output=True, check=True)
    router = Path(directory) / 'router.php'
    router.write_text("""<?php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(!str_starts_with($path,'/blog/')){http_response_code(404);exit;}
$path=substr($path,5);
if(preg_match('#^/(?:storage|content|cache|lib|bin|tests)(?:/|$)|^/(?:config(?:\\.local)?\\.php|alt-cli|\\.)#',$path)){http_response_code(403);exit;}
if($path==='/admin/' || $path==='/admin/index.php'){$_SERVER['SCRIPT_NAME']='/blog/admin/index.php';require __DIR__.'/site/admin/index.php';return;}
$_SERVER['SCRIPT_NAME']='/blog/index.php';require __DIR__.'/site/index.php';
""")
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    no_redirect = urllib.request.build_opener(NoRedirect)
    log = open(Path(directory) / 'http.log', 'w')
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', f'127.0.0.1:{port}', '-t', str(root), str(router)], stdout=log, stderr=log)

    def request(path, fields=None, headers=None, opener=client):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(base + path, data=data, headers=headers or {}), timeout=25) as response:
                return response.status, response.read().decode(), response.headers
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(), error.headers

    def field(body, name):
        return html.unescape(re.search('name="' + re.escape(name) + '" value="([^"]*)"', body).group(1))

    def switch(body):
        match = re.search(r'<nav class="language-switch"[^>]*>(.*?)</nav>', body, re.S)
        return re.findall(r'href="([^"]+)"', match.group(1)) if match else []

    def alternates(body):
        return set(re.findall(r'hreflang="([^"]+)"', body))

    def location(headers=None):
        status, _, returned = request('/', headers=headers, opener=no_redirect)
        assert status == 302
        return returned['Location']

    def cli(*args):
        result = subprocess.run(['php', str(root / 'alt-cli'), *args], capture_output=True, text=True, timeout=35)
        assert result.returncode == 0, result.stderr
        return result.stdout

    def save_settings(languages, default):
        _, body, _ = request('/admin/?page=settings')
        fields = {'action': 'settings', 'csrf': field(body, 'csrf'), 'settings_revision': field(body, 'settings_revision'), 'name': 'Language fixture', 'logo_mark': '>_', 'email': '', 'legal': '', 'site_url': base, 'default_language': default, 'posts_per_page': '12', 'search_indexing': '1', 'custom_css': ''}
        for language in languages:
            fields[f'description[{language}]'] = 'description-' + language
            fields[f'tagline[{language}]'] = 'footer-' + language
        status, body, _ = request('/admin/?page=settings', fields)
        assert status == 200 and 'Settings saved' in body, body[:300]

    try:
        for _ in range(80):
            try:
                if request('/en')[0] == 200:
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise AssertionError('Fixture server unavailable')

        expect(request('/de')[0] == 404, 'enabled language without a Home returns 404, not a server error or invented content')
        expect(location({'Accept-Language': 'de'}) == prefix + '/en', 'root does not negotiate to a missing language Home')
        body = request('/en')[1]
        expect(switch(body) == [prefix + '/en', prefix + '/it'] and 'de' not in alternates(body), 'switcher/hreflang omit enabled languages with missing content')
        document('de/home', 'Deutsch', 'Eigenständiger deutscher Inhalt. [Sitemap](/blog/sitemap-de.xml) [Disabled](/blog/fr)')
        status, body, headers = request('/de')
        expect(status == 200 and '<html lang="de">' in body and 'deutscher Inhalt' in body, 'adding de through config and Markdown needs no PHP source changes')
        expect('>Articles<' in body and 'Warning:' not in body, 'untranslated public UI falls back to English without translating German content')
        expect(switch(body) == [prefix + '/en', prefix + '/it', prefix + '/de'], 'language selector follows the single configured list and order')
        expect(alternates(body) == {'en', 'it', 'de', 'x-default'}, 'all existing Home translations have reciprocal hreflang')
        expect(prefix + '/de/about' not in body and prefix + '/de/skills' not in body, 'navigation omits missing pages')
        expect(request('/de/about')[0] == 404, 'missing translated page does not fall back to English content')
        expect(request('/fr')[0] == 404, 'unconfigured language returns 404 directly')
        expect(location() == prefix + '/en', 'explicit configured default controls fresh root visits')
        expect(location({'Accept-Language': 'it;q=0.3, de-DE;q=0.9'}) == prefix + '/de', 'Accept-Language respects quality and primary-language matching')
        expect(location({'Accept-Language': 'it;q=0, de;q=0.8'}) == prefix + '/de', 'zero-quality language is not selected')
        expect(location({'Cookie': 'alternpress_lang=it', 'Accept-Language': 'de'}) == prefix + '/it', 'enabled cookie preference wins over browser language')
        for path in ['/../de', '/de/%2e%2e/en', '/%2e%2e/en', '/de%2fen', '/de/%2fetc']:
            status, _, returned = request(path, opener=no_redirect)
            expect(status in (403, 404), 'malformed/traversal language route is rejected: ' + path + ' status=' + str(status) + ' location=' + returned.get('Location', ''))

        _, login, _ = request('/admin/?ui=de')
        expect('<html lang="en">' in login and 'Sign in' in login and 'Warning:' not in login, 'German selection uses documented English admin UI')
        status, dashboard, _ = request('/admin/?ui=de', {'action': 'login', 'csrf': field(login, 'csrf'), 'password': password})
        expect(status == 200 and 'Sign out' in dashboard, 'admin authentication works with the extra configured language')
        _, editor, _ = request('/admin/?page=edit&id=2026-01-02-shared&ui=de')
        expect(re.findall(r'data-tab="([^"]+)"', editor) == ['en', 'it', 'de'], 'editor creates one content tab per configured language')
        expect('data-tab="de">DE</button>' in editor and 'name="translation[de][body]"' in editor, 'new-language label and writable content fields are not hardcoded English/Italian')
        fields = {'action': 'save', 'csrf': field(editor, 'csrf'), 'id': '2026-01-02-shared', 'kind': 'post', 'date': '2026-01-02', 'translation[de][title]': 'Deutscher Artikel', 'translation[de][body]': 'Nur deutscher Text.', 'translation[de][status]': 'published', 'translation[de][revision]': hashlib.sha256(b'').hexdigest()}
        status, saved, _ = request('/admin/?ui=de', fields)
        expect(status == 200 and 'Saved.' in saved, 'editor publishes German without creating phantom translations')
        post = '/de/post/2026-01-02-shared'
        status, body, _ = request(post)
        expect(status == 200 and 'Nur deutscher Text.' in body and alternates(body) == {'de', 'x-default'}, 'German-only article advertises only its actual published version')
        expect(not switch(body), 'article with no translations has no fictional language-switch destinations')
        translated = document('en/post/2026-01-02-shared', 'English article', 'Real English translation.')
        status, body, _ = request(post)
        expect(alternates(body) == {'en', 'de', 'x-default'} and len(switch(body)) == 2, 'adding a real translation updates switcher/hreflang across three configured languages')
        stamp = translated.stat().st_mtime_ns
        translated.write_text(translated.read_text().replace('status: published', 'status: draft'))
        __import__('os').utime(translated, ns=(stamp, stamp))
        _, body, _ = request(post)
        expect(alternates(body) == {'de', 'x-default'} and not switch(body), 'same-timestamp withdrawal refreshes cached alternate-language links')
        for _ in range(2):
            status, _, cached = request('/de')
        expect(status == 200 and cached.get('X-AlternPress-Cache') == 'HIT', 'German pages use the existing file page cache')
        expect('sitemap-de.xml' in request('/sitemap.xml')[1], 'sitemap index follows configured languages')
        german_map = request('/sitemap-de.xml')[1]
        expect(base + post in german_map and '/it/post/2026-01-02-shared' not in german_map, 'German sitemap lists published German articles without nonexistent translations')
        graph = json.loads(request('/knowledge-graph.jsonld')[1])
        expect(any(node.get('@type') == 'BlogPosting' and node.get('inLanguage') == 'de' for node in graph['@graph']), 'knowledge graph describes the added language')
        expect(base + post in request('/llms.txt')[1], 'llms directory includes added-language public content')
        expect(request('/sitemap-fr.xml')[0] == 404, 'unconfigured language sitemap returns 404')
        expect(request('/feed.xml')[0] == 404, 'no nonexistent RSS/Atom feed is advertised or synthesized')
        report = subprocess.run(['php', str(root / 'alt-cli'), 'check', '--json'], capture_output=True, text=True, timeout=15)
        diagnostics = json.loads(report.stdout)
        expect(not any(issue.get('url') == prefix + '/sitemap-de.xml' for issue in diagnostics['issues']), 'diagnostics recognizes configured generated language sitemap')
        expect(any(issue.get('url') == prefix + '/fr' for issue in diagnostics['issues']), 'diagnostics flags a link to an unconfigured language')
        expect('OK   Valid languages' in cli('doctor'), 'doctor accepts a three-language configuration')
        save_settings(['en', 'it', 'de'], 'en')
        german_bytes = (root / 'content/de/post/2026-01-02-shared/index.md').read_bytes()
        config(['en', 'it'], 'en')
        for path in ['/de', post, '/sitemap-de.xml']:
            status, _, returned = request(path)
            expect(status == 404 and 'Location' not in returned, 'disabled language bypasses old cache and returns direct 404: ' + path)
        expect('sitemap-de.xml' not in request('/sitemap.xml')[1] and base + post not in request('/llms.txt')[1], 'disabling language removes public discovery entries')
        expect((root / 'content/de/post/2026-01-02-shared/index.md').read_bytes() == german_bytes, 'disabling a language never deletes its content')
        status, rejected, _ = request('/admin/?ui=de', dict(fields, csrf=field(request('/admin/')[1], 'csrf')))
        expect(status == 422 and (root / 'content/de/post/2026-01-02-shared/index.md').read_bytes() == german_bytes, 'stale editor cannot write a disabled language')
        save_settings(['en', 'it'], 'en')
        config(['en', 'it', 'de'], 'de')
        expect(location() == prefix + '/de', 'explicit local-file default overrides the old saved panel default')
        _, settings, _ = request('/admin/?page=settings&ui=de')
        expect('name="description[de]"' in settings and 'description-de' in settings and 'footer-de' in settings, 'disabled-language metadata survives settings saves and re-enabling')
        expect('name="default_language" disabled' in settings and 'name="default_language" value="de"' in settings, 'dashboard explains file-authoritative default instead of pretending it can overwrite it')
        config(['de'], 'de')
        inactive = {str(p.relative_to(root)): p.read_bytes() for language in ['en', 'it'] for p in (root / 'content' / language).rglob('*.md')}
        status, body, _ = request('/de')
        expect(status == 200 and not switch(body) and alternates(body) == {'de', 'x-default'}, 'single-language mode has no hardcoded English/Italian switch')
        expect(request('/en')[0] == 404 and request('/it')[0] == 404, 'single-language mode disables default legacy language routes')
        expect('sitemap-en.xml' not in request('/sitemap.xml')[1] and 'sitemap-de.xml' in request('/sitemap.xml')[1], 'single-language discovery contains only the enabled language')
        _, editor, _ = request('/admin/?page=edit&id=single&ui=de')
        expect(re.findall(r'data-tab="([^"]+)"', editor) == ['de'], 'single-language editor has exactly one tab')
        save_settings(['de'], 'de')
        result = subprocess.run(['php', '-r', """define('AP_LIBRARY',true);require $argv[1].'/index.php';require $argv[1].'/lib/admin.php';require $argv[1].'/lib/backups.php';$b=ap_backup_create();$p=ap_restore_prepare(ap_backup_path($b['name']));ap_restore_apply($p['token']);echo json_encode(array_keys(ap_backup_snapshot()['files']));""", str(root)], capture_output=True, text=True, timeout=20)
        assert result.returncode == 0, result.stderr
        paths = json.loads(result.stdout)
        expect(any(path.startswith('content/en/') for path in paths) and any(path.startswith('content/it/') for path in paths), 'data backups retain disabled-language Markdown instead of silently omitting it')
        expect(all((root / name).read_bytes() == data for name, data in inactive.items()), 'backup/restore with a smaller active list preserves disabled content')
        document('en-GB/home', 'Regional home', 'Regional content.')
        config(['en-GB'], 'en-GB')
        expect(request('/en-GB')[0] == 200 and request('/sitemap-en-GB.xml')[0] == 200, 'regional language tags work consistently in routes and sitemap')
        expect(location({'Accept-Language': 'en-gb'}) == prefix + '/en-GB', 'negotiation preserves canonical configured tag case')
        save_settings(['en-GB'], 'en-GB')
        expect('Prepared ' in cli('cache:warm'), 'cache warmer accepts configured regional tags')
        expect(request('/en-GB')[2].get('X-AlternPress-Cache') == 'HIT', 'regional cache-warm worker creates a usable cached public page')
        for languages, default in [([], 'en'), (['en', '../outside'], 'en'), (['en', 'en'], 'en'), (['en-GB', 'en-gb'], 'en-GB'), (['en_US'], 'en_US'), (['%2fetc'], '%2fetc'), (['en'], 'de'), ({'x': 'en'}, 'en'), (['d' * 36], 'd' * 36)]:
            config(languages, default)
            rejected = subprocess.run(['php', str(root / 'alt-cli'), 'doctor'], capture_output=True, timeout=5)
            expect(rejected.returncode != 0, 'invalid list/default/tag is rejected before content paths: ' + repr(languages))
        config(['en-GB'], 'en-GB')
        expect(all(hashlib.sha256((root / relative).read_bytes()).hexdigest() == digest for relative, digest in core.items()), 'all language changes used configuration/content only: no PHP core source was edited')
        print(f'{checks} configurable-language HTTP checks passed.')
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
