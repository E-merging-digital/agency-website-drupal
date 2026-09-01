#!/usr/bin/python3 -I
"""Bounded root observer for one governed server-to-server refresh request."""
from __future__ import annotations

import os
import pwd
import re
import stat
import sys
from pathlib import Path

DEPLOY_USER = 'agency-preprod'
SHARED = Path('/var/www/agency-preprod/shared')
REQUEST_ROOT = Path('/run/agency-preprod-refresh')
REQUEST_RE = re.compile(r'^apply-[0-9]+-[A-Za-z0-9._-]{8,40}-r1$')
SHA40_RE = re.compile(r'^[0-9a-f]{40}$')
TERMINAL_OUTCOMES = {'COMMITTED', 'ROLLED_BACK', 'HUMAN_RECOVERY_REQUIRED'}
MAX_RESULT_BYTES = 4096
MAX_MATCHED_PROCESSES = 8


class ObservationError(RuntimeError):
    """Raised when worker state cannot be proven safely."""


def parse_result(path: Path, request_id: str, expected_main: str, uid: int, gid: int) -> tuple[str, str]:
    try:
        metadata = os.lstat(path)
    except FileNotFoundError:
        return 'ABSENT', 'NONE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        return 'UNSAFE', 'NONE'
    if metadata.st_uid != uid or metadata.st_gid != gid or stat.S_IMODE(metadata.st_mode) != 0o600:
        return 'UNSAFE', 'NONE'
    if metadata.st_size <= 0 or metadata.st_size > MAX_RESULT_BYTES:
        return 'UNSAFE', 'NONE'
    try:
        raw = path.read_text(encoding='ascii')
    except (OSError, UnicodeError):
        return 'UNSAFE', 'NONE'
    values: dict[str, str] = {}
    for line in raw.splitlines():
        if '=' not in line:
            return 'UNSAFE', 'NONE'
        key, value = line.split('=', 1)
        if key in values:
            return 'UNSAFE', 'NONE'
        values[key] = value
    if values.get('schema_version') != '1':
        return 'UNSAFE', 'NONE'
    if values.get('request_id') != request_id or values.get('main_sha') != expected_main:
        return 'UNSAFE', 'NONE'
    outcome = values.get('outcome', '')
    if outcome not in TERMINAL_OUTCOMES:
        return 'UNSAFE', 'NONE'
    if not re.fullmatch(r'[A-Z0-9_]+', values.get('detail', '')):
        return 'UNSAFE', 'NONE'
    return 'PRESENT', outcome


def process_state(request_id: str, expected_main: str) -> str:
    suffix = __import__('hashlib').sha256(request_id.encode('ascii')).hexdigest()[:12]
    stage_worker = str(REQUEST_ROOT / suffix / 'remote-server-to-server-worker.py')
    activation_worker = str(SHARED / 'refresh-jobs' / request_id / 'worker.sh')
    matched = 0
    try:
        entries = list(Path('/proc').iterdir())
    except OSError:
        return 'UNOBSERVABLE'
    for entry in entries:
        if not entry.name.isdigit():
            continue
        try:
            raw = (entry / 'cmdline').read_bytes()
        except (FileNotFoundError, PermissionError, ProcessLookupError):
            continue
        except OSError:
            return 'UNOBSERVABLE'
        if not raw:
            continue
        try:
            argv = [part.decode('utf-8') for part in raw.rstrip(b'\0').split(b'\0')]
        except UnicodeDecodeError:
            continue
        if request_id not in argv or expected_main not in argv:
            continue
        if stage_worker not in argv and activation_worker not in argv:
            continue
        matched += 1
        if matched > MAX_MATCHED_PROCESSES:
            return 'UNOBSERVABLE'
    return 'ALIVE' if matched else 'DEAD'


def observe(request_id: str, expected_main: str) -> tuple[str, str, str]:
    account = pwd.getpwnam(DEPLOY_USER)
    result = SHARED / 'refresh-jobs' / request_id / 'result.env'
    terminal, outcome = parse_result(result, request_id, expected_main, account.pw_uid, account.pw_gid)
    if terminal == 'PRESENT':
        return terminal, outcome, 'UNOBSERVABLE'
    if terminal == 'UNSAFE':
        return terminal, 'NONE', 'UNOBSERVABLE'
    return terminal, 'NONE', process_state(request_id, expected_main)


def self_test() -> None:
    assert REQUEST_RE.fullmatch('apply-945-example000-r1')
    assert not REQUEST_RE.fullmatch('apply-940-first-real-s2s-r1/reuse')
    assert SHA40_RE.fullmatch('a' * 40)
    assert TERMINAL_OUTCOMES == {'COMMITTED', 'ROLLED_BACK', 'HUMAN_RECOVERY_REQUIRED'}
    print('SELF_TEST=PASS')


def main(argv: list[str]) -> int:
    if argv == ['--self-test']:
        self_test()
        return 0
    if len(argv) != 2:
        raise ObservationError('Observer requires request and exact main only.')
    request_id, expected_main = argv
    if not REQUEST_RE.fullmatch(request_id) or not SHA40_RE.fullmatch(expected_main):
        raise ObservationError('Invalid request/main authority.')
    if os.geteuid() != 0:
        raise ObservationError('Observer requires fixed PREPROD root identity.')
    terminal, outcome, worker = observe(request_id, expected_main)
    print(f'terminal_metadata={terminal}')
    print(f'outcome={outcome}')
    print(f'worker_process={worker}')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main(sys.argv[1:]))
    except (ObservationError, KeyError, OSError):
        print('terminal_metadata=UNOBSERVABLE')
        print('outcome=NONE')
        print('worker_process=UNOBSERVABLE')
        raise SystemExit(78)