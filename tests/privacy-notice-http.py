"""Optional file-backed notices and owner-only draft payloads; no live requests."""
from pathlib import Path
import http.cookies
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

with tempfile.TemporaryDirectory(prefix='alternpress-privacy-http-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    (root / 'config.local.php').write_text("<?php return ['base_path'=>'/installed','origin'=>'https://fixture.example','page_cache'=>true];")
    subprocess.run(['php', str(root/'alt-cli'), 'password', '--stdin'], input=b'Fixture-Only-Notice-2026!\n', check=True, stdout=subprocess.DEVNULL)
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/installed/admin/') { $_SERVER['SCRIPT_NAME']='/installed/admin/index.php'; require __DIR__.'/site/admin/index.php'; return; }
$_SERVER['SCRIPT_NAME']='/installed/index.php'; require __DIR__.'/site/index.php';
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    log = open(Path(directory)/'server.log', 'w')
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', f'127.0.0.1:{port}', str(router)], stdout=log, stderr=log)
    def request(path, headers=None):
        try: response = urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/installed'+path, headers=headers or {}), timeout=20)
        except urllib.error.HTTPError as error: response = error
        return response.status, response.read().decode(), response.headers
    try:
        for _ in range(50):
            try: request('/robots.txt'); break
            except OSError: time.sleep(.1)
        for path in ['/it', '/en', '/admin/']:
            status, html, _ = request(path)
            expect(status == 200 and '/privacy' not in html, 'no owner notice or broken policy link imposed on default install: ' + path)
        status, _, _ = request('/it/privacy')
        expect(status == 404, 'notice route requires an existing published file')
        request('/it')
        _, _, warm = request('/it')
        expect(warm.get('X-AlternPress-Cache') == 'HIT', 'home is cached before optional notice is installed')
        for lang in ['it', 'en']:
            target = root/f'content/{lang}/privacy/index.md'
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source/f'website/notices/vantax.eu/{lang}/index.md', target)
        for lang in ['it', 'en']:
            status, home, headers = request('/'+lang)
            expect(status == 200 and f'href="/installed/{lang}/privacy"' in home, lang+' home footer links to the installed notice')
            expect(headers.get('X-AlternPress-Cache') == 'MISS', lang+' notice installation invalidates cached footer generation')
            status, html, headers = request('/'+lang+'/privacy')
            expect(status == 200 and 'SERVER TAMER DI DANIELE VANTAGGIATO' in html, lang+' owner notice renders')
            expect('Accept-Language' in html and 'Apache' in html and 'IMMUTION' in html and 'alternpress_eadbca56ea89' in html, lang+' notice discloses request processing, logs and necessary admin session')
            expect('14' in html and '30' in html and 'backup' in html.lower(), lang+' verified rotation counts are distinguished from backup retention')
            expect(not headers.get_all('Set-Cookie'), lang+' fresh notice page creates no public cookies')
            expect('data-e=' in html and '<script>alert' not in html, lang+' contact is protected by existing renderer, not an external service')
            other = 'en' if lang == 'it' else 'it'
            expect(f'href="/installed/{other}/privacy"' in html, lang+' notice language switch preserves legal route')
            status, admin, headers = request('/admin/?ui='+lang)
            expect(status == 200 and f'href="/installed/{lang}/privacy"' in admin and 'name="csrf"' in admin and 'name="password"' in admin, lang+' login keeps auth/CSRF and links to the correct notice')
            values = http.cookies.SimpleCookie()
            for line in headers.get_all('Set-Cookie') or []: values.load(line)
            expect(len(values) == 1 and 'alternpress_lang' not in values and next(iter(values.values()))['httponly'], lang+' login still has only its protected admin session')
        status, _, headers = request('/it/privacy', {'Cookie':'alternpress_lang=en'})
        expect(status == 200 and 'Max-Age=0' in headers.get('Set-Cookie','') and 'no-store' in headers.get('Cache-Control',''), 'notice route only expires legacy language state, never creates it')
        # Published policy content is still untrusted Markdown, not executable HTML.
        it = root/'content/it/privacy/index.md'
        it.write_text(it.read_text()+'\n<script>alert(1)</script>\n')
        _, html, _ = request('/it/privacy')
        expect('&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html, 'notice content uses the existing safe Markdown renderer')
        en = root/'content/en/privacy/index.md'
        en.write_text(en.read_text().replace('status: published','status: draft')+'\nPRIVATE-NOTICE-DRAFT\n')
        for path in ['/en', '/admin/?ui=en']:
            status, html, _ = request(path)
            expect(status == 200 and 'href="/installed/en/privacy"' not in html, 'draft notice has no public/login link: '+path)
        status, html, _ = request('/en/privacy')
        expect(status == 404 and 'PRIVATE-NOTICE-DRAFT' not in html, 'draft notice body does not leak through direct URL')
        _, html, _ = request('/it/privacy')
        expect('href="/installed/en/privacy"' not in html, 'alternate language never points to draft notice')
        en.unlink()
        for path in ['/en', '/admin/?ui=en']:
            status, html, _ = request(path)
            expect(status == 200 and 'href="/installed/en/privacy"' not in html, 'removed fixture notice has no broken public/login link: '+path)
        expect('info@vantax.eu' in (source/'website/notice_pages.py').read_text(), 'marketing notice uses the verified public owner contact')
        for path in ['privacy/index.html','it/privacy/index.html']:
            html = (source/'website/dist'/path).read_text()
            expect('Accept-Language' not in html and 'Apache' in html and '14' in html and 'vantax.eu' in html, 'marketing copy is scoped to the static website: '+path)
            expect('<script' not in html and '<iframe' not in html and 'fonts.googleapis' not in html and 'localStorage.' not in html, 'static notice adds no executable script, embed, remote font or storage: '+path)
            expect('<!--#echo var="DATE_LOCAL" -->' in html and 'MIT' in html, 'static notice preserves dynamic business year and upstream attribution: '+path)
    finally:
        server.terminate(); server.wait(timeout=10); log.close()
print(f'{checks} privacy notice HTTP checks passed.')
