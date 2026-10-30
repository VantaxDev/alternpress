"""Real HTTP and fault injection against disposable installations; no live site."""
from pathlib import Path
import contextlib
import fcntl
import gzip
import hashlib
import json
import os
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

SOURCE = Path(__file__).resolve().parents[1]
checks = 0


def expect(condition, label):
    global checks
    assert condition, label
    checks += 1
    print('PASS ' + label, flush=True)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


@contextlib.contextmanager
def installation(opcache_freq=0):
    with tempfile.TemporaryDirectory(prefix='alternpress-updater-') as directory:
        base = Path(directory)
        site, release = base / 'site', base / 'release'
        for target in (site, release):
            shutil.copytree(SOURCE, target, ignore=shutil.ignore_patterns('.git', '__pycache__'))
        runner = base / 'trusted-update.php'
        shutil.copyfile(SOURCE / 'bin/update.php', runner)
        mode = base / 'mode'
        mode.write_text('normal')
        router = base / 'router.php'
        router.write_text("""<?php
$site=__DIR__.'/site';
$mode=trim(file_get_contents(__DIR__.'/mode'));
if(isset($_GET['__ap_update_probe']) && is_file($site.'/storage/update-maintenance.php')) {
    if($mode==='fail-all'){http_response_code(503);exit('simulated unavailable service');}
    if($mode==='fake-cache' && !str_contains(file_get_contents($site.'/lib/update-release.php'), "return 'source';")){header('X-AlternPress-Cache: HIT');echo '<html>old cached 200</html>';exit;}
    if($mode==='timeout' && !str_contains(file_get_contents($site.'/lib/update-release.php'), "return 'source';")) sleep(3);
    if($mode==='retry-once' && !is_file(__DIR__.'/retried')){file_put_contents(__DIR__.'/retried','1');http_response_code(503);exit;}
}
$_SERVER['SCRIPT_NAME']='/index.php';
require $site.'/index.php';
""")
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        url = f'http://127.0.0.1:{port}/it'
        environment = dict(os.environ, PHP_CLI_SERVER_WORKERS='4')
        with (base / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'opcache.enable=1', '-d', f'opcache.revalidate_freq={opcache_freq}', '-S', f'127.0.0.1:{port}', str(router)], stdout=log, stderr=log, env=environment, start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with urllib.request.urlopen(url, timeout=1) as response:
                            if response.status == 200:
                                break
                    except (OSError, urllib.error.URLError):
                        time.sleep(.05)
                else:
                    raise AssertionError((base / 'server.log').read_text())
                yield base, site, release, runner, mode, url
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    os.killpg(server.pid, signal.SIGKILL)
                    server.wait(timeout=3)


def package(base, release, runner, broken=None):
    index = release / 'index.php'
    replacement = 'declare(strict_types=1); // candidate-release'
    if broken == 'runtime':
        replacement += "\nthrow new RuntimeException('simulated broken release');"
    elif broken == 'syntax':
        replacement += '\nfunction broken( {'
    index.write_text(index.read_text().replace('declare(strict_types=1);', replacement, 1))
    archive = base / 'release.ap.gz'
    result = subprocess.run(['php', str(runner), 'pack', '--source', str(release), '--output', str(archive)], capture_output=True, text=True, timeout=10)
    assert result.returncode == 0, result.stderr
    return archive, result.stdout.strip()


def command(base, site, runner, url, action='apply', archive=None, sha=None, state=None):
    args = ['php', str(runner), action, '--site', str(site), '--state', str(state or base / 'private-update-state'), '--url', url, '--timeout', '1', '--attempts', '1']
    if action == 'apply':
        args += ['--package', str(archive), '--sha256', sha]
    return args


def run(args, expected):
    result = subprocess.run(args, capture_output=True, text=True, timeout=35)
    assert result.returncode == expected, f'expected exit {expected}, got {result.returncode}: {result.stderr}\n{result.stdout}'
    return result


def protected_files(site):
    paths = ['content/it/home/index.md', 'media/new-upload.png', 'config.php', 'config.local.php', 'assets/theme.css', 'assets/favicon.local.svg', 'storage/editor-new.php', 'lib/local-extra.php', '.htaccess']
    return {path: (site / path).read_bytes() for path in paths if (site / path).is_file()}


def write_during_update(site):
    with (site / 'content/it/home/index.md').open('a') as stream:
        stream.write('\nA new editorial write must survive rollback.\n')
    for name, data in [('media/new-upload.png', b'new upload bytes'), ('assets/theme.css', b'body{color:red}'), ('assets/favicon.local.svg', b'<svg/>'), ('storage/editor-new.php', b'new private write'), ('lib/local-extra.php', b'<?php // unowned local code'), ('config.local.php', b'<?php return [];')]:
        (site / name).write_bytes(data)
    return protected_files(site)


with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    saved = write_during_update(site)
    old = digest(site / 'index.php')
    metadata = (site / 'index.php').stat()
    run(command(base, site, runner, url, archive=archive, sha=sha), 0)
    expect(digest(site / 'index.php') != old, 'successful update activates new code')
    journal = json.loads(next((base / 'private-update-state').glob('*/journal.json')).read_text())
    expect(journal['phase'] == 'committed', 'success is journaled after dynamic HTTP verification')
    expect(protected_files(site) == saved, 'update preserves content/config/upload/local overrides')
    after = (site / 'index.php').stat()
    expect((after.st_uid, after.st_gid, after.st_mode) == (metadata.st_uid, metadata.st_gid, metadata.st_mode), 'code owner/group/mode preserved')
    saved = write_during_update(site)
    run(command(base, site, runner, url, 'rollback'), 0)
    expect(digest(site / 'index.php') == old and protected_files(site) == saved, 'manual code-only rollback preserves later editorial writes')
    expect(not (site / 'storage/update-maintenance.php').exists(), 'verified rollback removes maintenance')

for failure in ('runtime', 'syntax', 'sha', 'timeout', 'fake-cache', 'fail-all'):
    with installation() as fixture:
        base, site, release, runner, mode, url = fixture
        archive, sha = package(base, release, runner, failure)
        old = digest(site / 'index.php')
        saved = write_during_update(site)
        if failure in ('timeout', 'fake-cache', 'fail-all'):
            mode.write_text(failure)
        expected = 1 if failure in ('syntax', 'sha') else 3 if failure == 'fail-all' else 2
        started = time.monotonic()
        run(command(base, site, runner, url, archive=archive, sha='0' * 64 if failure == 'sha' else sha), expected)
        if failure == 'timeout':
            expect(time.monotonic() - started < 8, 'HTTP timeout and rollback finish within bounded retry budget')
        expect(digest(site / 'index.php') == old, f'{failure}: previous code intact/restored')
        expect(protected_files(site) == saved, f'{failure}: no data restored from old copies')
        expect((site / 'storage/update-maintenance.php').exists() == (failure == 'fail-all'), f'{failure}: maintenance reflects verified health')
        if failure == 'fail-all':
            try:
                urllib.request.urlopen(url, timeout=2)
                raise AssertionError('broken rollback must stay in maintenance')
            except urllib.error.HTTPError as error:
                expect(error.code == 503, 'failed rollback keeps public traffic behind maintenance')
            mode.write_text('normal')
            run(command(base, site, runner, url, 'recover'), 0)
            expect(not (site / 'storage/update-maintenance.php').exists(), 'recover verifies old code before reopening traffic')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    (site / 'storage/update.lock').touch()
    with (site / 'storage/update.lock').open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        run(command(base, site, runner, url, archive=archive, sha=sha), 4)
        run(command(base, site, runner, url, archive=archive, sha=sha, state=base / 'different-state'), 4)
        expect("return 'source';" in (site / 'lib/update-release.php').read_text(), 'site lock excludes concurrent updates even with different state paths')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    (site / 'storage/write.lock').touch()
    with (site / 'storage/write.lock').open('r+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        run(command(base, site, runner, url, archive=archive, sha=sha), 4)
        expect(not (site / 'storage/update-maintenance.php').exists(), 'in-flight editorial writes prevent activation')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    injected = base / 'low-space.php'
    injected.write_text("""<?php
require __DIR__.'/trusted-update.php';
class LowSpace extends APCodeUpdater { protected function availableBytes(string $path): float { return 0; } }
exit((new LowSpace($argv[1],$argv[2],$argv[3]))->apply($argv[4],$argv[5]));
""")
    run(['php', str(injected), str(site), str(base / 'state-low'), url, str(archive), sha], 1)
    expect(not (site / 'storage/update-maintenance.php').exists(), 'low space fails before activation')
    if os.geteuid() != 0:
        (site / 'lib').chmod(0o555)
        try:
            run(command(base, site, runner, url, archive=archive, sha=sha), 1)
            expect(not (site / 'storage/update-maintenance.php').exists(), 'unwritable code fails before activation')
        finally:
            (site / 'lib').chmod(0o755)
    else:
        print('SKIP real permission denial: root bypasses DAC; run unprivileged', flush=True)

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    data = json.loads(gzip.decompress(archive.read_bytes()))
    data['files']['config.php'] = data['files']['index.php']
    bad = base / 'unsafe.ap.gz'
    bad.write_bytes(gzip.compress(json.dumps(data).encode()))
    run(command(base, site, runner, url, archive=bad, sha=digest(bad)), 1)
    expect(not (site / 'storage/update-maintenance.php').exists(), 'trusted hash cannot authorize protected config/data paths')
    run(command(base, site, runner, url, archive=archive, sha=sha, state=site / 'public-state'), 1)
    expect(not (site / 'public-state').exists(), 'backup/state beneath webroot rejected')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    args = command(base, site, runner, url, archive=archive, sha=sha)
    run(args[:1] + ['-n'] + args[1:], 1)
    expect(not (site / 'storage/update-maintenance.php').exists(), 'missing CLI extensions are rejected before activation')
    data = json.loads(gzip.decompress(archive.read_bytes()))
    data['minimum_php'] = 99999
    future = base / 'future.ap.gz'
    future.write_bytes(gzip.compress(json.dumps(data).encode()))
    run(command(base, site, runner, url, archive=future, sha=digest(future)), 1)
    expect(not (site / 'storage/update-maintenance.php').exists(), 'unsupported PHP version rejected before activation')

opcache_available = subprocess.run(['php', '-r', 'exit(function_exists("opcache_get_status")?0:1);'], capture_output=True).returncode == 0
if opcache_available:
    with installation(opcache_freq=2) as fixture:
        base, site, release, runner, mode, url = fixture
        archive, sha = package(base, release, runner)
        run(command(base, site, runner, url, archive=archive, sha=sha), 1)
        expect(not (site / 'storage/update-maintenance.php').exists(), 'web OPcache with deferred validation is rejected rather than trusting stale code')
else:
    print('SKIP OPcache-specific refusal: extension is not loaded', flush=True)

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    mode.write_text('retry-once')
    args = command(base, site, runner, url, archive=archive, sha=sha)
    args[args.index('--attempts') + 1] = '2'
    result = run(args, 0)
    expect('health_retry: attempt 1/2' in result.stderr, 'transient failed health check is retried and the verified new release can commit')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner, 'runtime')
    saved = write_during_update(site)
    old = digest(site / 'index.php')
    injected = base / 'restore-failure.php'
    injected.write_text("""<?php
require __DIR__.'/trusted-update.php';
class RestoreFailure extends APCodeUpdater {
    public string $target='', $oldHash='';
    protected function atomic(string $path,string $bytes,?array $metadata=null): void {
        if($path===$this->target && hash('sha256',$bytes)===$this->oldHash) throw new RuntimeException('injected rollback write failure');
        parent::atomic($path,$bytes,$metadata);
    }
}
$updater=new RestoreFailure($argv[1],$argv[2],$argv[3],1,1);
$updater->target=$argv[1].'/index.php';$updater->oldHash=hash_file('sha256',$updater->target);
exit($updater->apply($argv[4],$argv[5]));
""")
    run(['php', str(injected), str(site), str(base / 'private-update-state'), url, str(archive), sha], 3)
    expect((site / 'storage/update-maintenance.php').exists() and digest(site / 'index.php') != old, 'rollback write failure is not falsely reported as a healthy old release')
    expect(protected_files(site) == saved, 'failed rollback write never restores old editorial data')
    run(command(base, site, runner, url, 'recover'), 0)
    expect(digest(site / 'index.php') == old and protected_files(site) == saved, 'recover repairs a previously failed rollback after the write fault is gone')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    old = digest(site / 'index.php')
    mode.write_text('timeout')
    process = subprocess.Popen(command(base, site, runner, url, archive=archive, sha=sha), stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    journal = None
    try:
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            paths = list((base / 'private-update-state').glob('*/journal.json'))
            if paths:
                journal = json.loads(paths[0].read_text())
                if journal['phase'] == 'verifying':
                    break
            if process.poll() is not None:
                raise AssertionError(process.communicate())
            time.sleep(.01)
        assert journal and journal['phase'] == 'verifying'
        process.kill()
        process.communicate(timeout=5)
        saved = write_during_update(site)
        expect((site / 'storage/update-maintenance.php').exists(), 'SIGKILL leaves durable maintenance/recovery journal')
        run(command(base, site, runner, url, archive=archive, sha=sha), 1)
        expect(digest(site / 'index.php') != old, 'second apply refuses unfinished transaction')
        mode.write_text('normal')
        run(command(base, site, runner, url, 'recover'), 0)
        expect(digest(site / 'index.php') == old and protected_files(site) == saved, 'crash recovery preserves writes made while updater was dead')
        expect(not (site / 'storage/update-maintenance.php').exists(), 'crash recovery verifies old dynamic page before reopening')
    finally:
        if process.poll() is None:
            process.kill()
            process.communicate(timeout=5)

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    old = (site / 'index.php').read_bytes()
    run(command(base, site, runner, url, archive=archive, sha=sha), 0)
    new = digest(site / 'index.php')
    journal_path = next((base / 'private-update-state').glob('*/journal.json'))
    entry = json.loads(journal_path.read_text())['files']['index.php']
    backup = journal_path.parent / 'old' / entry['key']
    backup.write_bytes(b'corrupt backup')
    run(command(base, site, runner, url, 'rollback'), 3)
    expect(digest(site / 'index.php') == new, 'corrupt backup is detected before restoring any old code')
    expect((site / 'storage/update-maintenance.php').exists(), 'corrupt backup leaves a recoverable maintenance state')
    backup.write_bytes(old)
    run(command(base, site, runner, url, 'recover'), 0)
    expect((site / 'index.php').read_bytes() == old, 'recover succeeds after trusted backup repair')

with installation() as fixture:
    base, site, release, runner, mode, url = fixture
    archive, sha = package(base, release, runner)
    old = digest(site / 'index.php')
    injected = base / 'pause-activation.php'
    injected.write_text("""<?php
require __DIR__.'/trusted-update.php';
class PauseActivation extends APCodeUpdater {
    public string $target='', $marker='';
    protected function atomic(string $path,string $bytes,?array $metadata=null): void {
        if($path===$this->target){file_put_contents($this->marker,'paused');sleep(30);}
        parent::atomic($path,$bytes,$metadata);
    }
}
$updater=new PauseActivation($argv[1],$argv[2],$argv[3],1,1);
$updater->target=$argv[1].'/lib/update-release.php';$updater->marker=$argv[2].'/paused';
exit($updater->apply($argv[4],$argv[5]));
""")
    process = subprocess.Popen(['php', str(injected), str(site), str(base / 'private-update-state'), url, str(archive), sha], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    try:
        deadline = time.monotonic() + 15
        while not (base / 'private-update-state/paused').exists():
            if process.poll() is not None or time.monotonic() > deadline:
                raise AssertionError('activation never paused')
            time.sleep(.01)
        process.kill()
        process.communicate(timeout=5)
        saved = write_during_update(site)
        expect(digest(site / 'index.php') != old and "return 'source';" in (site / 'lib/update-release.php').read_text(), 'SIGKILL can leave a genuinely partial activation, not just an unverified full release')
        run(command(base, site, runner, url, 'recover'), 0)
        expect(digest(site / 'index.php') == old and protected_files(site) == saved, 'journal recovers a partially activated tree without restoring data')
        expect(not (site / 'storage/update-maintenance.php').exists(), 'partial-activation recovery verifies old HTTP before release')
    finally:
        if process.poll() is None:
            process.kill()
            process.communicate(timeout=5)

print(f'{checks} updater HTTP/fault-injection checks passed.')
