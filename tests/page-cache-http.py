"""Full-page cache and Brotli regressions on a disposable local installation."""
from pathlib import Path
import base64
import gzip
import hashlib
import json
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

source = Path(__file__).resolve().parents[1]
brotli = shutil.which('brotli')
if not brotli:
    raise SystemExit('The page-cache regression requires the real brotli executable.')
checks = 0


def expect(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS ' + label, flush=True)


def csp_matches(body, headers):
    document = body.decode('utf-8')
    scripts = re.findall(r'<script\b[^>]*>(.*?)</script>', document, re.S)
    expected = {"'sha256-" + base64.b64encode(hashlib.sha256(script.encode('utf-8')).digest()).decode() + "'" for script in scripts}
    csp = headers.get('Content-Security-Policy', '')
    directives = dict(re.findall(r'(?:^|;)\s*(script-src|style-src)\s+([^;]*)', csp))
    actual = set(re.findall(r"'sha256-[^']+'", directives.get('script-src', '')))
    styles = re.findall(r'<style\b[^>]*>(.*?)</style>', document, re.S)
    expected_styles = {"'sha256-" + base64.b64encode(hashlib.sha256(style.encode('utf-8')).digest()).decode() + "'" for style in styles}
    actual_styles = set(re.findall(r"'sha256-[^']+'", directives.get('style-src', '')))
    return bool(scripts) and expected == actual and expected_styles == actual_styles and 'unsafe-inline' not in csp and 'nonce-' not in csp


with tempfile.TemporaryDirectory(prefix='alternpress-page-cache-http-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if(preg_match('#^/installed/(?:storage|content|cache|lib|bin|tests)(?:/|$)|^/installed/(?:config(?:\\.local)?\\.php|alt-cli|\\.)#',$path)){http_response_code(403);exit;}
if($path==='/installed/admin/'||$path==='/installed/admin/index.php'){$_SERVER['SCRIPT_NAME']='/installed/admin/index.php';require __DIR__.'/site/admin/index.php';return;}
$_SERVER['SCRIPT_NAME']='/installed/index.php';
require __DIR__.'/site/index.php';
''', encoding='utf-8')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    config = root / 'config.local.php'

    def settings(name='Cache fixture', enabled=True, encoder=brotli):
        values = {'name': name, 'base_path': '/installed', 'site_url': 'https://canonical.example/blog', 'origin': base, 'page_cache': enabled, 'brotli_binary': encoder}
        encoded = json.dumps(values, ensure_ascii=False)
        config.write_text('<?php return json_decode(' + "'" + encoded.replace('\\', '\\\\').replace("'", "\\'") + "'" + ', true);', encoding='utf-8')

    settings()
    for language in ['it', 'en']:
        for page in ['home', 'about', 'skills']:
            target = root / 'content' / language / page / 'index.md'
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('---\ntitle: ' + language + ' ' + page + '\n---\nFixture public page.\n', encoding='utf-8')
    article = root / 'content/it/post/cache-story/index.md'
    article.parent.mkdir(parents=True, exist_ok=True)
    original = '---\ntitle: Cache original title\ndate: 2026-01-02\ntags: [cache, linux]\nstatus: published\n---\n' + ('Article text for a meaningful compressed response. ' * 30) + '\n'
    article.write_text(original, encoding='utf-8')
    translation = root / 'content/en/post/cache-story/index.md'
    translation.parent.mkdir(parents=True, exist_ok=True)
    translation.write_text('---\ntitle: PRIVATE-TRANSLATION\nstatus: draft\n---\nPrivate.\n', encoding='utf-8')
    log = open(Path(directory) / 'server.log', 'w')
    # Exercise the configured real executable even if a host happens to have a
    # separate Brotli PHP extension; opcode caching is outside these regressions.
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'disable_functions=brotli_compress', '-S', '127.0.0.1:' + str(port), '-t', str(root), str(router)], stdout=log, stderr=log)

    def request(path='/it/post/cache-story', encoding='identity', method='GET', extra=None):
        headers = {'Accept-Encoding': encoding}
        headers.update(extra or {})
        req = urllib.request.Request(base + '/installed' + path, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                return response.status, response.read(), response.headers
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers

    def decoded(body, headers):
        coding = headers.get('Content-Encoding', '')
        if coding == 'br':
            return subprocess.run([brotli, '--decompress', '--stdout'], input=body, capture_output=True, check=True).stdout
        if coding == 'gzip':
            return gzip.decompress(body)
        return body

    def preserve_mtime_write(path, value):
        before = path.stat()
        path.write_text(value, encoding='utf-8')
        os.utime(path, ns=(before.st_atime_ns, before.st_mtime_ns))

    try:
        for attempt in range(50):
            try:
                request('/robots.txt')
                break
            except OSError:
                time.sleep(.1)
        status, body, headers = request()
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'MISS', 'first public article request generates the page cache')
        expect(b'Cache original title' in body and csp_matches(body, headers), 'generated HTML has correct content and exact trusted script hashes')
        status, cached, hit_headers = request(extra={'Cookie': 'alternpress_lang=it'})
        expect(status == 200 and hit_headers.get('X-AlternPress-Cache') == 'HIT' and cached == body, 'repeat request serves identical cached HTML')
        expect(csp_matches(cached, hit_headers) and hit_headers.get('Content-Security-Policy') == headers.get('Content-Security-Policy'), 'cached HTML retains its matching deterministic CSP')
        expect(hit_headers.get('Set-Cookie') is None, 'cached response does not replay an unnecessary visitor cookie')
        status, cookie_body, cookie_headers = request(extra={'Cookie': 'alternpress_lang=en'})
        expect(cookie_body == body and 'alternpress_lang=it' in cookie_headers.get('Set-Cookie', ''), 'cache hit sets language preference for the current visitor')
        expect('accept-encoding' in hit_headers.get('Vary', '').lower(), 'encoding variants declare Vary Accept-Encoding')
        identity_etag = hit_headers.get('ETag')
        expect(bool(identity_etag) and not identity_etag.startswith('W/'), 'cached identity has a strong entity tag')

        variants = {'identity': (body, hit_headers)}
        for coding in ['br', 'gzip']:
            status, compressed, variant_headers = request(encoding=coding)
            expect(status == 200 and variant_headers.get('Content-Encoding') == coding and variant_headers.get('X-AlternPress-Cache') == 'HIT', 'cached ' + coding + ' representation is negotiated')
            expect(decoded(compressed, variant_headers) == body, 'real ' + coding + ' decoder reproduces exact HTML')
            expect(len(compressed) < len(body), coding + ' actually compresses the article')
            expect(variant_headers.get('ETag') != identity_etag, coding + ' has a representation-specific ETag')
            variants[coding] = (compressed, variant_headers)
        expect('quality=11' in variants['br'][1].get('X-AlternPress-Encoding', ''), 'Brotli response identifies configured maximum quality')
        expect(variants['br'][1].get('ETag') != variants['gzip'][1].get('ETag'), 'Brotli and gzip have distinct strong entity tags')

        negotiations = [
            ('br;q=0, gzip;q=1', 'gzip'),
            ('br;q=0, gzip;q=0', None),
            ('gzip;q=1, br;q=0.5, identity;q=0', 'gzip'),
            ('br;q=0, *;q=1, identity;q=0', 'gzip'),
            ('*;q=1, identity;q=0', 'br'),
            ('identity;q=1, br;q=0, gzip;q=0', None),
        ]
        for accept, expected in negotiations:
            status, negotiated, negotiated_headers = request(encoding=accept)
            expect(status == 200 and negotiated_headers.get('Content-Encoding') == expected and decoded(negotiated, negotiated_headers) == body, 'Accept-Encoding respected: ' + accept)
        status, _, _ = request(encoding='br;q=0, gzip;q=0, identity;q=0')
        expect(status == 406, 'all unacceptable encodings return 406')
        status, _, _ = request(encoding='*;q=0')
        expect(status == 406, 'wildcard refusal cannot receive identity')
        for coding, (representation, representation_headers) in variants.items():
            status, head, head_headers = request(encoding=coding, method='HEAD')
            expect(status == 200 and head == b'' and head_headers.get('ETag') == representation_headers.get('ETag') and head_headers.get('Content-Encoding') == representation_headers.get('Content-Encoding'), 'HEAD preserves ' + coding + ' headers without a body')
            expect(int(head_headers.get('Content-Length', '-1')) == len(representation), 'HEAD reports ' + coding + ' representation length')
            etag = representation_headers.get('ETag')
            for condition in [etag, 'W/' + etag, '"other-tag", ' + etag, '*']:
                status, conditional, conditional_headers = request(encoding=coding, extra={'If-None-Match': condition})
                expect(status == 304 and conditional == b'' and conditional_headers.get('ETag') == etag, 'conditional ' + coding + ' request returns a bodyless 304')
        status, _, headers = request(encoding='br', extra={'If-None-Match': identity_etag})
        expect(status == 200 and headers.get('Content-Encoding') == 'br', 'identity validator cannot falsely validate Brotli bytes')

        status, bypass_body, headers = request('/it/post/cache-story?preview=1')
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'BYPASS', 'query requests bypass the shared page cache')
        expect('text/html' in headers.get('Content-Type', '') and 'no-cache' in headers.get('Cache-Control', '') and csp_matches(bypass_body, headers), 'query bypass retains content type, freshness and CSP protection')
        status, post_body, headers = request(method='POST')
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'BYPASS' and csp_matches(post_body, headers), 'non-GET bypass retains the normal public security headers')
        expect(request('/it/home')[0] == 200, 'noncached legacy home alias retains its successful response')
        expect(request('/it/tag/no-matching-article')[0] == 200, 'noncached empty tag listing retains its successful response')
        status, _, headers = request('/admin/')
        expect(status == 200 and headers.get('X-AlternPress-Cache') != 'HIT' and 'no-store' in headers.get('Cache-Control', ''), 'administrator page is never served from public cache')

        preserve_mtime_write(article, original.replace('Cache original title', 'Cache updated title'))
        status, updated, headers = request()
        expect(status == 200 and b'Cache updated title' in updated and b'Cache original title' not in updated and headers.get('ETag') != identity_etag, 'content hashing catches edits with preserved file timestamps')
        expect(headers.get('X-AlternPress-Cache') == 'MISS' and csp_matches(updated, headers), 'changed article regenerates HTML and JSON-LD CSP')
        status, listing, _ = request('/it/archive')
        expect(status == 200 and b'Cache updated title' in listing, 'archive reflects the revised article')
        status, _, _ = request('/it/archive')
        preserve_mtime_write(article, article.read_text(encoding='utf-8').replace('status: published', 'status: draft'))
        expect(request()[0] == 404, 'withdrawing an article cannot serve its cached public page')
        status, listing, headers = request('/it/archive')
        expect(status == 200 and b'Cache updated title' not in listing and headers.get('X-AlternPress-Cache') == 'MISS', 'withdrawing an article invalidates cached listings')
        preserve_mtime_write(article, original.replace('Cache original title', 'Cache updated title'))
        request()
        article.unlink()
        expect(request()[0] == 404, 'deleting an article cannot resurrect cached HTML')
        article.write_text(original.replace('Cache original title', 'Cache updated title'), encoding='utf-8')
        request()

        translation.write_text('---\ntitle: English translation\ndate: 2026-01-02\nstatus: published\n---\nTranslated.\n', encoding='utf-8')
        status, linked, headers = request()
        expect(status == 200 and b'https://canonical.example/blog/en/post/cache-story' in linked and headers.get('X-AlternPress-Cache') == 'MISS', 'publishing a translation refreshes existing language links and JSON-LD')
        preserve_mtime_write(translation, translation.read_text(encoding='utf-8').replace('status: published', 'status: draft'))
        status, linked, _ = request()
        expect(status == 200 and b'https://canonical.example/blog/en/post/cache-story' not in linked, 'withdrawing a translation removes its cached alternate links')
        settings('Changed cache site')
        status, changed, headers = request()
        expect(status == 200 and b'Changed cache site' in changed and headers.get('X-AlternPress-Cache') == 'MISS', 'site settings invalidate rendered HTML and publisher metadata')
        entry = root / 'index.php'
        preserve_mtime_write(entry, entry.read_text(encoding='utf-8') + '\n/* Isolated cache code-change regression. */\n')
        status, changed, headers = request()
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'MISS' and csp_matches(changed, headers), 'code updates invalidate page cache even with preserved timestamps')

        preserve_mtime_write(article, article.read_text(encoding='utf-8') + '\nPrepared by the warmer.\n')
        warmed = subprocess.run(['php', str(root / 'alt-cli'), 'cache:warm'], capture_output=True, text=True, timeout=60, check=True)
        status, warmed_body, headers = request(encoding='br')
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'HIT' and b'Prepared by the warmer.' in decoded(warmed_body, headers), 'CLI warmer prepares the current HTML and Brotli before the first visit')
        subprocess.run(['php', str(root / 'alt-cli'), 'cache:clear'], capture_output=True, text=True, timeout=30, check=True)
        status, _, headers = request()
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'MISS', 'CLI cache clear removes generated page variants')
        save_input = {
            'id': 'cache-story', 'kind': 'post', 'date': '2026-01-02',
            'translation': {
                'it': {'title': 'Cache updated title', 'body': 'Prepared automatically when saving. ' * 30, 'status': 'published', 'tags': 'cache,linux', 'revision': hashlib.sha256(article.read_bytes()).hexdigest()},
                'en': {'title': 'English translation', 'body': 'Draft translation.', 'status': 'draft', 'revision': hashlib.sha256(translation.read_bytes()).hexdigest()},
            },
        }
        save_code = "define('AP_LIBRARY', true); require $argv[1] . '/index.php'; require $argv[1] . '/lib/admin.php'; function t(string $it,string $en): string {return $en;} ap_save_document(json_decode($argv[2],true,64,JSON_THROW_ON_ERROR));"
        subprocess.run(['php', '-r', save_code, str(root), json.dumps(save_input)], capture_output=True, text=True, timeout=60, check=True)
        status, saved_body, headers = request(encoding='br')
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'HIT' and b'Prepared automatically when saving.' in decoded(saved_body, headers), 'saving a publication automatically prepares its compressed page before a visit')

        # Corrupt an actual immutable body; accepting a cached filename alone is unsafe.
        pages = root / 'cache/pages'
        candidates = [path for path in pages.rglob('*.html') if b'Changed cache site' in path.read_bytes() and b'Cache updated title' in path.read_bytes()]
        expect(bool(candidates), 'HTML body is persisted in the private page-cache directory')
        for path in candidates:
            path.write_bytes(b'CACHE-POISONED-BODY')
        status, repaired, headers = request()
        expect(status == 200 and b'Cache updated title' in repaired and b'CACHE-POISONED-BODY' not in repaired and csp_matches(repaired, headers), 'corrupt cached HTML is regenerated safely')
        status, br_body, br_headers = request(encoding='br')
        for path in pages.rglob('*.br'):
            try:
                plain = subprocess.run([brotli, '--decompress', '--stdout', str(path)], capture_output=True, check=True).stdout
            except subprocess.CalledProcessError:
                continue
            if b'Changed cache site' in plain and b'Cache updated title' in plain:
                path.write_bytes(b'INVALID-BROTLI')
        status, repaired_br, repaired_headers = request(encoding='br')
        expect(status == 200 and b'Cache updated title' in decoded(repaired_br, repaired_headers), 'corrupt compressed cache falls back to a valid regenerated representation')
        for path in pages.rglob('*.json'):
            path.write_text('{invalid manifest', encoding='utf-8')
        status, repaired, _ = request()
        expect(status == 200 and b'Cache updated title' in repaired, 'corrupt manifests cannot break public rendering')

        # A symlinked cache directory must neither be read nor written.
        saved_pages = root / 'cache/pages-saved'
        pages.rename(saved_pages)
        outside = Path(directory) / 'outside-cache'
        outside.mkdir()
        sentinel = outside / 'sentinel.txt'
        sentinel.write_text('UNCHANGED', encoding='utf-8')
        pages.symlink_to(outside, target_is_directory=True)
        try:
            status, uncached, headers = request()
            expect(status == 200 and b'Cache updated title' in uncached and headers.get('X-AlternPress-Cache') == 'BYPASS', 'unsafe cache directory gracefully bypasses storage')
            expect(sentinel.read_text(encoding='utf-8') == 'UNCHANGED' and sorted(path.name for path in outside.iterdir()) == ['sentinel.txt'], 'unsafe cache cannot modify its symlink target')
        finally:
            pages.unlink()
            saved_pages.rename(pages)

        settings(enabled=False)
        status, disabled, headers = request()
        expect(status == 200 and headers.get('X-AlternPress-Cache') == 'BYPASS' and b'Cache updated title' in disabled, 'disabled cache serves current content normally (status=' + str(status) + ', cache=' + str(headers.get('X-AlternPress-Cache')) + ', body=' + repr(disabled[:180]) + ')')
        expect('text/html' in headers.get('Content-Type', '') and 'no-cache' in headers.get('Cache-Control', '') and csp_matches(disabled, headers), 'disabled caching retains normal content and security headers')
        settings(encoder='/nonexistent/alternpress-brotli')
        status, fallback, headers = request(encoding='br, gzip')
        expect(status == 200 and headers.get('Content-Encoding') in ['gzip', None] and b'Cache updated title' in decoded(fallback, headers), 'missing Brotli executable leaves the site usable')
        print(str(checks) + ' page-cache HTTP checks passed.')
    except Exception as error:
        if isinstance(error, subprocess.CalledProcessError) and error.stderr:
            print(error.stderr, flush=True)
        log.flush()
        print((Path(directory) / 'server.log').read_text(errors='replace')[-6000:], flush=True)
        raise
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
