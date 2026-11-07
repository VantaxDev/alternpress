"""Offline CLI link checks against disposable sites, never installed content."""
from pathlib import Path
import shutil
import subprocess
import tempfile

source = Path(__file__).resolve().parents[1]
checks = 0


def expect(value, label):
    global checks
    assert value, label
    checks += 1


with tempfile.TemporaryDirectory(prefix='alternpress-links-cli-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    shutil.rmtree(root / 'content')
    document = root / 'content/it/home/index.md'
    document.parent.mkdir(parents=True)
    valid = ['/sitemap.xml', '/sitemap-it.xml', '/sitemap-en.xml', '/llms.txt',
             '/robots.txt', '/knowledge-graph.jsonld', '/sitemap.xml?source=cli#top',
             '/sitemap-it.xml/']
    invalid = ['/sitemap-fr.xml', '/it/sitemap.xml', '/sitemap.xml.bak',
               '/sitemap-it.xml/extra', '/sitemap-it.xml.bak', '/SITEMAP.xml']
    for base in ['', '/installed']:
        for indexing in ['true', 'false']:
            (root / 'config.local.php').write_text(
                "<?php return ['base_path'=>'" + base + "','search_indexing'=>" + indexing + "];"
            )

            def run(paths):
                document.write_text('---\ntitle: Fixture\n---\n' + ''.join(
                    '[index](' + base + path + ')\n' for path in paths
                ))
                result = subprocess.run(['php', str(root / 'alt-cli'), 'links:check'],
                                        capture_output=True, text=True, timeout=15)
                expect(result.stderr == '', 'No runtime errors: ' + result.stderr)
                return result

            result = run(valid)
            expect(result.returncode == 0, 'Generated discovery routes resolve: ' + result.stdout)
            expect('8 checked · 0 to review · 0 skipped.' in result.stdout,
                   'All generated links are checked offline, not skipped')
            result = run(invalid)
            expect(result.returncode == 1, 'Invalid discovery routes fail')
            expect('6 checked · 6 to review · 0 skipped.' in result.stdout,
                   'Unsupported languages and route lookalikes remain missing')
            for path in invalid:
                expect('MISSING ' + base + path + ' in content/it/home/index.md' in result.stdout,
                       'Invalid route retains source context: ' + path)

print(f'{checks} CLI discovery link checks passed.')
