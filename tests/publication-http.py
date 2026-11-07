"""Deterministic scheduled visibility and private previews on disposable sites."""
from pathlib import Path
import base64
import gzip
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

source = Path(__file__).resolve().parents[1]
checks = 0
boundary = 1901149200


def expect(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS ' + label, flush=True)


with tempfile.TemporaryDirectory(prefix='alternpress-publication-http-') as directory:
    folder = Path(directory)
    root = folder / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    brotli = shutil.which('brotli')
    assert brotli, 'The publication cache regression requires brotli.'
    (root / 'config.local.php').write_text("<?php return ['base_path'=>'/installed','timezone'=>'Europe/Rome','brotli_binary'=>" + repr(brotli) + "];")
    clock = folder / 'clock'
    clock.write_text(str(boundary - 1))
    # Test-only clock is outside the site. No HTTP/header/query time override is
    # present in application code; every request reads the fixture's fixed clock.
    router = folder / 'router.php'
    router.write_text('''<?php
$_SERVER['REQUEST_TIME']=(int)file_get_contents(__DIR__.'/clock');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/installed/admin/') {$_SERVER['SCRIPT_NAME']='/installed/admin/index.php';require __DIR__.'/site/admin/index.php';return;}
$_SERVER['SCRIPT_NAME']='/installed/index.php';require __DIR__.'/site/index.php';
''')

    def document(language, slug, title, extra, kind='post'):
        path = root / 'content' / language / (('post/' if kind == 'post' else '') + slug) / 'index.md'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text('---\ntitle: ' + title + '\ntags: [timedtopic]\n' + extra + '\n---\n\n' + title + ' body.\n')
        return path

    italian = document('it', 'scheduled-fixture', 'SCHEDULED-ITALIAN', 'status: published\npublish_at: "2030-03-31T03:00:00+02:00"')
    english = document('en', 'scheduled-fixture', 'SCHEDULED-ENGLISH', 'status: published\npublish_at: "2030-03-31T04:00:00+02:00"')
    document('it', 'draft-fixture', 'PRIVATE-DRAFT', 'status: draft\npublish_at: "2000-01-01T00:00:00Z"')
    document('it', 'invalid-fixture', 'PRIVATE-INVALID', 'status: published\npublish_at: "2030-02-30T01:00:00Z"')
    document('it', 'legacy-fixture', 'PUBLIC-LEGACY', 'date: "2099-01-01"')
    document('it', 'about', 'SCHEDULED-PAGE', 'status: published\npublish_at: "2030-03-31T03:00:00+02:00"', 'page')
    attachment = italian.parent / 'private.png'
    attachment.write_bytes(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4mQAAAAASUVORK5CYII='))
    password = 'Fixture-only-password-2030'
    subprocess.run(['php', str(root / 'alt-cli'), 'password', '--stdin'],
                   input=(password + '\n').encode(), check=True, stdout=subprocess.DEVNULL)
    status = subprocess.run(['php', '-r', '$cli=$argv[1]; $_SERVER["REQUEST_TIME"]=(int)$argv[2]; $argv=[$cli,"status"]; require $cli;',
                             str(root / 'alt-cli'), str(boundary - 1)], capture_output=True, text=True, check=True)
    expect('2 scheduled translations' in status.stdout and '1 invalid schedules (private)' in status.stdout,
           'CLI status separates scheduled, draft and invalid translations')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port) + '/installed'
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    with open(folder / 'server.log', 'w') as log:
        server = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', '127.0.0.1:' + str(port),
                                   '-t', str(root), str(router)], stdout=log, stderr=log)

        def request(path, fields=None, auth=False, headers=None, method=None):
            data = urllib.parse.urlencode(fields).encode() if fields is not None else None
            req = urllib.request.Request(base + path, data=data, headers=headers or {}, method=method)
            try:
                response = (client if auth else urllib.request.build_opener()).open(req, timeout=20)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                body = response.read()
                if response.headers.get('Content-Encoding') == 'gzip':
                    body = gzip.decompress(body)
                elif response.headers.get('Content-Encoding') == 'br':
                    body = subprocess.run([brotli, '-d', '-c'], input=body, capture_output=True, check=True).stdout
                return response.status, body.decode(errors='replace'), response.headers

        routes = ['/it', '/it/archive', '/it/tags', '/it/tag/timedtopic',
                  '/sitemap.xml', '/sitemap-it.xml', '/sitemap-en.xml',
                  '/llms.txt', '/robots.txt', '/knowledge-graph.jsonld']
        try:
            for attempt in range(50):
                try:
                    request('/it')
                    break
                except OSError:
                    time.sleep(.1)
            for route in routes:
                status, body, _ = request(route)
                expect(status == 200, 'Public route works before boundary: ' + route)
                expect(all(marker not in body for marker in ['SCHEDULED-ITALIAN', 'SCHEDULED-ENGLISH', 'PRIVATE-DRAFT', 'PRIVATE-INVALID', 'SCHEDULED-PAGE']),
                       'No private metadata before boundary: ' + route)
            expect('PUBLIC-LEGACY' in request('/it/archive')[1], 'Legacy future editorial date stays public')
            for route in ['/feed.xml', '/rss.xml', '/search?q=PRIVATE-DRAFT']:
                status, body, _ = request(route)
                expect(status == 404 and 'PRIVATE-DRAFT' not in body, 'No implicit feed/search exposes private data: ' + route)
            for route in ['/it/post/scheduled-fixture', '/en/post/scheduled-fixture', '/it/post/draft-fixture',
                          '/it/post/invalid-fixture', '/it/post/scheduled-fixture/private.png', '/it/about']:
                expect(request(route)[0] == 404, 'Private direct route: ' + route)
            for route in ['/it', '/it/archive', '/it/tags']:
                expect(request(route)[2].get('X-AlternPress-Cache') == 'HIT', 'Cache actually warmed before boundary: ' + route)
            old_status, _, old_headers = request('/it/archive')
            for encoding in ['gzip', 'br']:
                _, body, headers = request('/it/archive', headers={'Accept-Encoding': encoding})
                expect(headers.get('Content-Encoding') == encoding and 'SCHEDULED-ITALIAN' not in body,
                       'Real compressed generation is private before boundary: ' + encoding)
            expect('no-cache' in old_headers.get('Cache-Control', ''), 'Clients must revalidate at boundary')
            before_hash = hashlib.sha256(italian.read_bytes()).hexdigest()
            clock.write_text(str(boundary))
            status, body, headers = request('/it/archive', headers={'If-None-Match': old_headers['ETag']})
            expect(status == 200 and 'SCHEDULED-ITALIAN' in body, 'Boundary invalidates archive and old ETag without edits')
            expect(headers.get('X-AlternPress-Cache') == 'MISS', 'Old HTML generation not reused at boundary')
            expect(hashlib.sha256(italian.read_bytes()).hexdigest() == before_hash, 'Publication requires no file write')
            status, body, _ = request('/it/post/scheduled-fixture')
            expect(status == 200 and 'SCHEDULED-ITALIAN body.' in body, 'Exact boundary makes article public')
            expect('2030-03-31T03:00:00+02:00' in body, 'JSON-LD carries actual publish timestamp')
            expect('hreflang="en"' not in body, 'Future translation not advertised')
            expect(request('/it/about')[0] == 200, 'Scheduled page becomes public')
            expect(request('/it/post/scheduled-fixture/private.png')[0] == 200, 'Article attachment follows visibility')
            for route in ['/it', '/it/archive', '/it/tag/timedtopic', '/sitemap-it.xml', '/llms.txt', '/knowledge-graph.jsonld']:
                body = request(route)[1]
                expected = 'scheduled-fixture' if route == '/sitemap-it.xml' else 'SCHEDULED-ITALIAN'
                expect(expected in body, 'Published item appears at boundary: ' + route)
                expect(all(marker not in body for marker in ['SCHEDULED-ENGLISH', 'PRIVATE-DRAFT', 'PRIVATE-INVALID']),
                       'Remaining private metadata excluded: ' + route)
            expect(request('/en/post/scheduled-fixture')[0] == 404, 'English translation remains private')
            expect('scheduled-fixture' not in request('/sitemap-en.xml')[1], 'English sitemap excludes future translation')
            expect(request('/it/post/scheduled-fixture', method='HEAD')[0] == 200, 'HEAD at publication boundary')
            expect('SCHEDULED-ITALIAN' in request('/it/archive', headers={'Accept-Encoding': 'gzip'})[1], 'Compressed output respects boundary')
            _, body, headers = request('/it/archive', headers={'Accept-Encoding': 'br'})
            expect(headers.get('Content-Encoding') == 'br' and 'SCHEDULED-ITALIAN' in body, 'Brotli generation refreshes at boundary')
            clock.write_text(str(boundary + 3600))
            expect(request('/en/post/scheduled-fixture')[0] == 200, 'Translation publishes at its own boundary')
            expect('hreflang="en"' in request('/it/post/scheduled-fixture')[1], 'Cached alternates refresh at translation boundary')
            clock.write_text(str(boundary - 1))
            expect(request('/it/post/scheduled-fixture')[0] == 404, 'Clock rollback cannot serve cached future article')
            expect('SCHEDULED-ITALIAN' not in request('/it/archive')[1], 'Clock rollback invalidates cached indexes')

            _, login, _ = request('/admin/', auth=True)
            csrf = html.unescape(re.search(r'name="csrf" value="([^"]+)"', login).group(1))
            expect(request('/admin/', {'action': 'preview', 'csrf': csrf, 'body': 'PRIVATE-DRAFT'}, auth=True)[0] == 401, 'Anonymous preview denied')
            # Fetch CSRF into the authenticated client's own session, then sign in.
            _, login, _ = request('/admin/', auth=True)
            csrf = html.unescape(re.search(r'name="csrf" value="([^"]+)"', login).group(1))
            _, dashboard, _ = request('/admin/', {'action': 'login', 'csrf': csrf, 'password': password}, auth=True)
            csrf = html.unescape(re.search(r'name="csrf" value="([^"]+)"', dashboard).group(1))
            expect('scheduled' in request('/admin/?ui=en', auth=True)[1], 'Admin distinguishes scheduled from drafts')
            status, preview, headers = request('/admin/', {'action': 'preview', 'csrf': csrf, 'body': '# PRIVATE-DRAFT\n\n<script>alert(1)</script>\n\n```html\n<script>literal</script>\n```'}, auth=True)
            expect(status == 200 and '<h1>PRIVATE-DRAFT</h1>' in json.loads(preview)['html'], 'Authenticated draft preview works')
            expect('no-store' in headers.get('Cache-Control', ''), 'Preview cannot enter shared cache')
            expect('<script>' not in json.loads(preview)['html'], 'Preview preserves safe Markdown and code fencing')
            expect(request('/admin/', {'action': 'preview', 'csrf': 'wrong', 'body': 'PRIVATE-DRAFT'}, auth=True)[0] == 403, 'Preview requires CSRF')

            fields = {'action': 'save', 'csrf': csrf, 'kind': 'post', 'id': 'scheduled-fixture', 'date': '2030-03-31'}
            for lang, path in [('it', italian), ('en', english)]:
                fields.update({f'translation[{lang}][title]': 'Saved ' + lang,
                               f'translation[{lang}][body]': 'Saved body',
                               f'translation[{lang}][status]': 'published',
                               f'translation[{lang}][revision]': hashlib.sha256(path.read_bytes()).hexdigest(),
                               f'translation[{lang}][publish_at]': '2030-03-31T03:00:00+02:00'})
            original = italian.read_bytes()
            fields['translation[it][publish_at]'] = '2030-02-30T00:00:00Z'
            status, body, _ = request('/admin/', fields, auth=True)
            expect(status == 422 and 'Invalid publication time' in body, 'Editor rejects invalid schedule with explanation')
            expect(italian.read_bytes() == original, 'Invalid schedule never modifies original article')
            fields['translation[it][publish_at]'] = '2030-03-31T03:00:00+02:00'
            fields['translation[en][publish_at]'] = '2030-02-30T00:00:00Z'
            expect(request('/admin/', fields, auth=True)[0] == 422, 'Invalid second translation schedule rejected')
            expect(italian.read_bytes() == original, 'Invalid second translation cannot partially save the first')
            fields['translation[en][publish_at]'] = '2030-03-31T03:00:00+02:00'
            status, body, _ = request('/admin/', fields, auth=True)
            expect(status == 200 and 'Saved.' in body, 'Editor saves valid explicit schedule')
            expect('publish_at: "2030-03-31T03:00:00+02:00"' in italian.read_text(), 'Schedule persisted in Markdown front matter')
            expect(request('/it/post/scheduled-fixture')[0] == 404, 'Saving published future article does not publish early')
            for lang, path in [('it', italian), ('en', english)]:
                fields[f'translation[{lang}][revision]'] = hashlib.sha256(path.read_bytes()).hexdigest()
            fields['translation[it][publish_at]'] = ''
            expect(request('/admin/', fields, auth=True)[0] == 200, 'Editor can clear schedule for immediate publication')
            expect('publish_at:' not in italian.read_text() and request('/it/post/scheduled-fixture')[0] == 200,
                   'Cleared schedule persists and is immediately visible')
        finally:
            server.terminate()
            server.wait(timeout=10)
    expect('Fatal error' not in (folder / 'server.log').read_text(), 'No PHP fatal errors')
print(f'{checks} scheduled publication HTTP checks passed.')
