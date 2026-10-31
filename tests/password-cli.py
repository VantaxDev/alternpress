#!/usr/bin/env python3
"""Password recovery integration checks: disposable sites only, no production data."""
import json
import os
from pathlib import Path
import pty
import select
import shutil
import subprocess
import tempfile
import termios
import time

SOURCE = Path(__file__).resolve().parents[1]
CHECKS = 0
OLD = b'<?php exit; ?>\n{"hash":"fixture-only","version":"previous"}'


def expect(ok, label):
    global CHECKS
    if not ok:
        raise AssertionError(label)
    CHECKS += 1


def fixture(root):
    shutil.copytree(SOURCE / 'lib', root / 'lib')
    (root / 'bin').mkdir()
    shutil.copy2(SOURCE / 'bin/password.php', root / 'bin/password.php')
    for name in ['alt-cli', 'config.php']:
        shutil.copy2(SOURCE / name, root / name)
    (root / 'storage').mkdir()
    (root / 'storage/auth.php').write_bytes(OLD)


def run_case(label, data, code, reason, *, stdin=True, setup=None, options=(), secret=None):
    with tempfile.TemporaryDirectory(prefix='alternpress-password-') as folder:
        root = Path(folder)
        fixture(root)
        if setup:
            setup(root)
        result = subprocess.run(['php', *options, str(root / 'alt-cli'), 'password',
                                 *(['--stdin'] if stdin else [])], input=data,
                                capture_output=True, timeout=15)
        output = result.stdout + result.stderr
        expect(result.returncode == code, label + ': exit code')
        expect(reason.encode() in output, label + ': explicit outcome/reason')
        expect(b'PHP Warning' not in output and b'PHP Fatal' not in output, label + ': no raw runtime errors')
        if secret:
            expect(secret not in output, label + ': input never echoed')
        if code:
            expect((root / 'storage/auth.php').read_bytes() == OLD, label + ': previous credential bytes preserved')
            expect(b'Password CHANGED:' not in output, label + ': no false success')
        else:
            auth = (root / 'storage/auth.php').read_bytes()
            record = json.loads(auth.split(b'\n', 1)[1])
            expect(auth != OLD and len(record['version']) == 48, label + ': new credential/session version saved')
            verify = 'require $argv[1]."/lib/bootstrap.php"; require $argv[1]."/lib/admin.php"; exit(password_verify(stream_get_contents(STDIN), ap_auth_data()["hash"]) ? 0 : 1);'
            verified = subprocess.run(['php', '-r', verify, str(root)], input=secret, capture_output=True)
            expect(verified.returncode == 0, label + ': saved password authenticates')
            expect(b'Existing sessions are invalidated.' in output, label + ': session outcome')


for length in [0, 11, 73]:
    run_case('invalid byte length ' + str(length), b'X' * length + b'\n', 2, '12–72 bytes')
run_case('null byte', b'fixture\0password\n', 2, 'null byte', secret=b'fixture\0password')
run_case('first read EOF', b'', 3, 'cannot read new password')
run_case('read operation failure', b'fixture-password\n', 3, 'cannot read password input',
         options=('-d', 'disable_functions=fgets'), secret=b'fixture-password')
run_case('confirmation EOF', b'fixture-password\n', 3, 'cannot read password confirmation', stdin=False, secret=b'fixture-password')
run_case('mismatch', b'fixture-password\nother-password\n', 2, 'passwords do not match', stdin=False, secret=b'fixture-password')
for value in [b'X' * 12, b'Y' * 72, 'é'.encode() * 6, b' fixture password ']:
    run_case('valid saved password', value + b'\n', 0, 'Password CHANGED: saved successfully.', secret=value)
run_case('multibyte exceeds byte limit', 'é'.encode() * 37 + b'\n', 2, '12–72 bytes')
run_case('CRLF input', b'fixture-password\r\n', 0, 'Password CHANGED:', secret=b'fixture-password')
run_case('stdin without final newline', b'fixture-password', 0, 'Password CHANGED:', secret=b'fixture-password')
run_case('confirmed password', b'fixture-password\nfixture-password\n', 0, 'Password CHANGED:', stdin=False, secret=b'fixture-password')
run_case('storage lock failure', b'fixture-password\n', 4, 'cannot save credentials',
         setup=lambda r: (r / 'storage/write.lock').mkdir(), secret=b'fixture-password')
if os.geteuid() != 0:
    def deny_lock(root):
        lock = root / 'storage/write.lock'
        lock.touch()
        lock.chmod(0)
    run_case('storage permission failure', b'fixture-password\n', 4, 'cannot save credentials',
             setup=deny_lock, secret=b'fixture-password')
run_case('temporary write failure', b'fixture-password\n', 4, 'cannot save credentials',
         options=('-d', 'disable_functions=tempnam'), secret=b'fixture-password')
run_case('atomic replacement failure', b'fixture-password\n', 4, 'cannot save credentials',
         options=('-d', 'disable_functions=rename'), secret=b'fixture-password')
run_case('bootstrap failure', b'fixture-password\n', 4, 'cannot save credentials',
         setup=lambda r: (r / 'config.php').unlink(), secret=b'fixture-password')


def terminal_case(disable_exec=False, mismatch=False):
    with tempfile.TemporaryDirectory(prefix='alternpress-password-tty-') as folder:
        root = Path(folder)
        fixture(root)
        master, slave = pty.openpty()
        original = termios.tcgetattr(slave)
        command = ['php', *(['-d', 'disable_functions=exec'] if disable_exec else []),
                   str(root / 'alt-cli'), 'password']
        process = subprocess.Popen(command, stdin=slave, stdout=slave, stderr=slave)
        output = b''
        sent = confirmed = False
        deadline = time.monotonic() + 15
        try:
            while time.monotonic() < deadline:
                if select.select([master], [], [], 0.05)[0]:
                    output += os.read(master, 65536)
                if not disable_exec and not sent and not (termios.tcgetattr(slave)[3] & termios.ECHO):
                    os.write(master, b'fixture-password\n')
                    sent = True
                if not disable_exec and sent and not confirmed and b'Repeat password:' in output:
                    os.write(master, b'other-password\n' if mismatch else b'fixture-password\n')
                    confirmed = True
                if process.poll() is not None:
                    while select.select([master], [], [], 0)[0]:
                        output += os.read(master, 65536)
                    break
            expect(process.poll() is not None, 'terminal: command finishes')
            expect(termios.tcgetattr(slave) == original, 'terminal: exact settings restored')
            expect(b'fixture-password' not in output and b'other-password' not in output, 'terminal: password never echoed')
            expected = 3 if disable_exec else 2 if mismatch else 0
            expect(process.returncode == expected, 'terminal: correct exit code')
            if expected:
                expect(b'Password NOT CHANGED:' in output, 'terminal: explicit failure')
                expect((root / 'storage/auth.php').read_bytes() == OLD, 'terminal: failed reset preserves credential')
            else:
                expect(b'Password CHANGED: saved successfully.' in output, 'terminal: explicit persisted success')
                expect((root / 'storage/auth.php').read_bytes() != OLD, 'terminal: credential saved')
        finally:
            if process.poll() is None:
                process.kill()
            process.wait()
            os.close(master)
            os.close(slave)


terminal_case()
terminal_case(mismatch=True)
terminal_case(disable_exec=True)
print(f'{CHECKS} password CLI checks passed.')
