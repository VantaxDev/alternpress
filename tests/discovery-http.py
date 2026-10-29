"""HTTP discovery regressions on disposable fixtures; never target a live site."""
from pathlib import Path
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

source = Path(__file__).resolve().parents[1]
checks = 0


def expect(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS ' + label, flush=True)


with tempfile.TemporaryDirectory(prefix='alternpress-discovery-http-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    # The PHP development server does not interpret .htaccess. Apache access
    # restrictions are additionally verified on an isolated Apache deployment.
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
$_SERVER['SCRIPT_NAME']='/installed/index.php';
require __DIR__.'/site/index.php';
''')
    config = root / 'config.local.php'

    def settings(indexing=True):
        config.write_text("<?php return ['base_path'=>'/installed','site_url'=>'https://canonical.example/blog','search_indexing'=>" + ('true' if indexing else 'false') + "];", encoding='utf-8')

    settings()
    draft = root / 'content/it/post/discovery-draft/index.md'
    draft.parent.mkdir(parents=True)
    draft.write_text('---\ntitle: DISCOVERY-PRIVATE-TITLE\nstatus: draft\n---\nDISCOVERY-PRIVATE-BODY\n', encoding='utf-8')
    article = root / 'content/it/post/discovery-public/index.md'
    article.parent.mkdir(parents=True)
    article.write_text('---\ntitle: Public discovery fixture\ndate: 2026-01-02\ntags: [linux]\nstatus: published\n---\nVisible text.\n', encoding='utf-8')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    log = open(Path(directory) / 'server.log', 'w')
    server = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', '127.0.0.1:' + str(port), '-t', str(root), str(router)], stdout=log, stderr=log)

    def request(path):
        try:
            with urllib.request.urlopen(base + '/installed' + path, timeout=15) as response:
                return response.status, response.read().decode('utf-8'), response.headers, response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode('utf-8'), error.headers, error.url

    try:
        for attempt in range(50):
            try:
                request('/sitemap.xml')
                break
            except OSError:
                time.sleep(.1)
        responses = {}
        for path, content_type in [('/sitemap.xml', 'xml'), ('/sitemap-it.xml', 'xml'), ('/sitemap-en.xml', 'xml'), ('/llms.txt', 'text/plain'), ('/knowledge-graph.jsonld', 'application/ld+json'), ('/robots.txt', 'text/plain')]:
            status, body, headers, final_url = request(path)
            responses[path] = (body, headers)
            expect(status == 200 and final_url == base + '/installed' + path, 'discovery endpoint serves directly: ' + path)
            expect(content_type in headers.get('Content-Type', ''), 'correct response type: ' + path)
            expect('DISCOVERY-PRIVATE' not in body, 'draft is absent from HTTP response: ' + path)
            expect(headers.get('Set-Cookie') is None, 'discovery endpoint avoids visitor cookies: ' + path)
            cache = headers.get('Cache-Control', '')
            expect('no-cache' in cache or 'no-store' in cache or ('max-age=0' in cache and 'must-revalidate' in cache), 'discovery response requires freshness: ' + path)
        sitemap_ns = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9', 'x': 'http://www.w3.org/1999/xhtml'}
        index = ET.fromstring(responses['/sitemap.xml'][0])
        expect(index.tag == '{http://www.sitemaps.org/schemas/sitemap/0.9}sitemapindex', 'sitemap index is valid namespaced XML')
        for language in ['it', 'en']:
            sitemap = ET.fromstring(responses['/sitemap-' + language + '.xml'][0])
            expect(sitemap.tag == '{http://www.sitemaps.org/schemas/sitemap/0.9}urlset', 'language sitemap is valid namespaced XML: ' + language)
            for location in sitemap.findall('s:url/s:loc', sitemap_ns):
                expect(location.text.startswith('https://canonical.example/blog/' + language), 'sitemap URL respects configured canonical folder')
        graph = json.loads(responses['/knowledge-graph.jsonld'][0])
        expect(graph['@context'] == 'https://schema.org' and isinstance(graph['@graph'], list), 'standalone graph is valid JSON-LD')
        status, body, headers, _ = request('/it/post/discovery-public')
        scripts = re.findall(r'<script\b([^>]*)type="application/ld\+json"([^>]*)>(.*?)</script>', body, re.S)
        expect(status == 200 and len(scripts) == 1, 'article serves a single embedded JSON-LD graph')
        embedded = json.loads(scripts[0][2])
        expect(any(node.get('@type') == 'BlogPosting' for node in embedded['@graph']), 'embedded graph describes the article')
        nonce = re.search(r"script-src 'nonce-([^']+)'", headers.get('Content-Security-Policy', '')).group(1)
        expect('nonce="' + nonce + '"' in scripts[0][0] + scripts[0][1], 'JSON-LD carries the matching CSP nonce')
        # Editing a published source in the same second must affect every route.
        article.write_text(article.read_text(encoding='utf-8').replace('status: published', 'status: draft'), encoding='utf-8')
        for path in ['/sitemap-it.xml', '/llms.txt', '/knowledge-graph.jsonld']:
            expect('/it/post/discovery-public' not in request(path)[1], 'HTTP discovery immediately removes withdrawn article: ' + path)
        settings(False)
        for path in ['/sitemap.xml', '/sitemap-it.xml', '/knowledge-graph.jsonld', '/llms.txt']:
            status, body, headers, _ = request(path)
            expect(status == 200 and 'noindex' in headers.get('X-Robots-Tag', ''), 'noindex applies to machine endpoint: ' + path)
            if path.endswith('.xml'):
                expect(len(ET.fromstring(body)) == 0, 'noindex XML contains no discovery entries: ' + path)
            elif path.endswith('.jsonld'):
                expect(json.loads(body)['@graph'] == [], 'noindex graph is empty')
        expect('Disallow: /' in request('/robots.txt')[1], 'noindex robots blocks crawlers')
        print(str(checks) + ' discovery HTTP checks passed.')
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
