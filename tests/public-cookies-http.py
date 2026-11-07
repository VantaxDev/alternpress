"""Cookie-free public rendering and legacy cleanup on loopback fixtures only."""
from pathlib import Path
import http.cookies
import http.cookiejar
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

source = Path(__file__).resolve().parents[1]
checks = 0

def expect(ok, label):
    global checks
    if not ok: raise AssertionError(label)
    checks += 1
    print('PASS ' + label, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None

with tempfile.TemporaryDirectory(prefix='alternpress-no-public-cookie-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    (root / 'config.local.php').write_text("<?php return ['base_path'=>'/installed','origin'=>'https://fixture.example','page_cache'=>true];")
    story = root / 'content/it/post/cookie-fixture/index.md'
    story.parent.mkdir(parents=True)
    subprocess.run(['php', str(root / 'alt-cli'), 'password', '--stdin'], input=b'Fixture-Only-Login-2026!\n', check=True, stdout=subprocess.DEVNULL)
    story.write_text('---\ntitle: Cookie-free fixture\nstatus: published\ntags: [example]\n---\nPublic story.\n')
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
// Test-only HTTPS simulation, never installed in production.
if (($_SERVER['HTTP_X_FIXTURE_HTTPS'] ?? '') === '1') $_SERVER['HTTPS']='on';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/installed/admin/') { $_SERVER['SCRIPT_NAME']='/installed/admin/index.php'; require __DIR__.'/site/admin/index.php'; return; }
$_SERVER['SCRIPT_NAME']='/installed/index.php'; require __DIR__.'/site/index.php';
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    log = open(Path(directory) / 'server.log', 'w')
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', f'127.0.0.1:{port}', str(router)], stdout=log, stderr=log)
    client = urllib.request.build_opener(NoRedirect())
    def request(path, cookie=None, language=None, extra=None, method='GET'):
        headers = {'Accept-Encoding': 'identity'}
        if cookie is not None: headers['Cookie'] = cookie
        if language is not None: headers['Accept-Language'] = language
        headers.update(extra or {})
        try:
            response = client.open(urllib.request.Request(f'http://127.0.0.1:{port}/installed'+path, headers=headers, method=method), timeout=20)
        except urllib.error.HTTPError as error: response = error
        return response.status, response.read(), response.headers
    def expiry(headers, label, secure=False):
        lines = headers.get_all('Set-Cookie') or []
        expect(len(lines) == 1, label + ' sends exactly one deletion header')
        values = http.cookies.SimpleCookie(); values.load(lines[0])
        expect(list(values) == ['alternpress_lang'], label + ' deletes only the legacy public cookie')
        cookie = values['alternpress_lang']
        expect(cookie['max-age'] == '0' and '1970' in cookie['expires'], label + ' has immediate expiry, not a new persistent preference')
        expect(cookie['path'] == '/installed/' and not cookie['domain'], label + ' preserves host-only cookie scope and install path')
        expect(cookie['samesite'] == 'Lax' and bool(cookie['secure']) == secure, label + ' preserves HTTPS/SameSite deletion attributes')
        expect('private' in headers.get('Cache-Control', '') and 'no-store' in headers.get('Cache-Control', ''), label + ' prevents shared caches retaining the deletion header')
    try:
        for attempt in range(50):
            try:
                request('/robots.txt'); break
            except OSError: time.sleep(.1)
        paths = ['/it', '/en', '/it/post/cookie-fixture', '/it/archive', '/it/tags', '/it/about', '/it/tag/example', '/sitemap.xml', '/sitemap-it.xml', '/llms.txt', '/robots.txt', '/knowledge-graph.jsonld']
        for path in paths:
            status, body, headers = request(path)
            expect(status == 200 and not headers.get_all('Set-Cookie'), 'fresh public route has no cookie: ' + path)
        status, body, headers = request('/it/post/cookie-fixture')
        expect(headers.get('X-AlternPress-Cache') == 'HIT', 'cookie-free public cache is warm')
        for method, extra in [('GET', {}), ('HEAD', {}), ('GET', {'If-None-Match': headers['ETag']})]:
            status, result, response = request('/it/post/cookie-fixture', method=method, extra=extra)
            expect(status == (304 if extra else 200) and not response.get_all('Set-Cookie'), 'fresh ' + method + (' conditional' if extra else '') + ' cached response has no cookie')
        for cookie in ['alternpress_lang=it', 'alternpress_lang=en', 'alternpress_lang=invalid', 'alternpress_lang=']:
            status, result, response = request('/it/post/cookie-fixture', cookie=cookie)
            expect(status == 200 and result == body, 'legacy cookie does not alter explicit-language content: ' + cookie)
            expiry(response, cookie)
        status, result, response = request('/it/post/cookie-fixture', cookie='alternpress_lang=en', extra={'If-None-Match': headers['ETag']})
        expect(status == 304 and result == b'', 'conditional cache response still supports legacy cleanup')
        expiry(response, '304 cleanup')
        status, _, response = request('/it', cookie='alternpress_lang=it', extra={'X-Fixture-Https': '1'})
        expiry(response, 'HTTPS cleanup', secure=True)
        status, _, response = request('/sitemap.xml', cookie='alternpress_lang=en')
        expect(status == 200, 'discovery response remains available during migration')
        expiry(response, 'discovery cleanup')
        for cookie, language, destination in [(None, 'en-US,en;q=0.9', '/installed/en'), ('alternpress_lang=it', 'en-US', '/installed/en'), ('alternpress_lang=en', 'it-IT', '/installed/it'), (None, 'fr-FR', '/installed/it'), (None, None, '/installed/it')]:
            status, _, response = request('/', cookie=cookie, language=language)
            expect(status == 302 and response['Location'] == destination, 'root uses header/default, never legacy preference')
            expect('accept-language' in response.get('Vary', '').lower() and 'no-store' in response.get('Cache-Control', ''), 'negotiated root redirect cannot be reused for another visitor')
            if cookie: expiry(response, 'root cleanup')
            else: expect(not response.get_all('Set-Cookie'), 'fresh root redirect has no cookie')
        status, _, response = request('/it', cookie='unrelated=keep')
        expect(not response.get_all('Set-Cookie'), 'unrelated visitor cookies are neither removed nor replaced')
        status, _, response = request('/it', cookie='alternpress_lang[]=en', language='en')
        expect(status == 200, 'malformed legacy array cookie cannot break rendering')
        expiry(response, 'malformed legacy cleanup')
        jar = http.cookiejar.CookieJar()
        jar.set_cookie(http.cookiejar.Cookie(version=0, name='alternpress_lang', value='en', port=None, port_specified=False, domain='127.0.0.1', domain_specified=False, domain_initial_dot=False, path='/installed/', path_specified=True, secure=False, expires=int(time.time()) + 31536000, discard=False, comment=None, comment_url=None, rest={}))
        jar_client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        with jar_client.open(f'http://127.0.0.1:{port}/installed/it') as response:
            expect(response.status == 200 and response.headers.get('Set-Cookie'), 'returning visitor receives legacy expiration')
        expect(list(jar) == [], 'browser-style cookie jar actually removes the stored legacy preference')
        with jar_client.open(f'http://127.0.0.1:{port}/installed/it') as response:
            expect(not response.headers.get_all('Set-Cookie'), 'after browser cleanup no deletion header is repeated')
        status, login, response = request('/admin/', extra={'X-Fixture-Https': '1'})
        values = http.cookies.SimpleCookie()
        lines = response.get_all('Set-Cookie') or []
        for line in lines: values.load(line)
        expect(status == 200 and b'name="csrf"' in login and b'name="password"' in login, 'admin login and CSRF form remain available')
        expect(len(values) == 1 and next(iter(values)).startswith('alternpress_') and 'alternpress_lang' not in values, 'fresh admin keeps only its necessary session cookie')
        session = next(iter(values.values()))
        expect(session['path'] == '/installed/admin/' and session['secure'] and session['httponly'] and session['samesite'] == 'Strict' and not session['expires'] and not session['max-age'], 'admin session attributes and lifetime remain protected')
        expect(not any(token in body for token in [b'localStorage', b'sessionStorage', b'indexedDB', b'serviceWorker']), 'no replacement browser storage added to public HTML')
        expect(not any(token in body.lower() for token in [b'cookie-banner', b'cookie-consent']), 'no consent banner introduced')
    finally:
        server.terminate(); server.wait(timeout=10); log.close()
print(f'{checks} public cookie HTTP checks passed.')
