#!/usr/bin/env python3
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'recovery-maintenance-reopen.sh'

def fake_drush(release: Path, state: Path, log: Path, executable: bool = True) -> None:
    vendor = release / 'vendor/bin'
    vendor.mkdir(parents=True, exist_ok=True)
    drush = vendor / 'drush'
    drush.write_text(
        '#!/usr/bin/env bash\n'
        'set -euo pipefail\n'
        'printf "%s\\n" "$*" >> "${FAKE_LOG}"\n'
        'if [[ "$1 ${2:-}" == "state:get system.maintenance_mode" ]]; then cat "${FAKE_STATE}"; exit 0; fi\n'
        'if [[ "$1 ${2:-} ${3:-}" == "state:set system.maintenance_mode 0" ]]; then printf 0 > "${FAKE_STATE}"; exit 0; fi\n'
        'if [[ "$1" == "cr" ]]; then exit 0; fi\n'
        'exit 99\n',
        encoding='utf-8',
    )
    drush.chmod(0o700 if executable else 0o600)

def run_helper(current: Path, maintenance: str, state: Path, log: Path) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env.update({'FAKE_STATE': str(state), 'FAKE_LOG': str(log)})
    return subprocess.run(['bash', str(HELPER), str(current), maintenance], env=env, text=True, capture_output=True, check=False)

def prepare(base: Path, name: str = '20260922-abc', with_drush: bool = True, executable: bool = True):
    releases = base / 'releases'
    releases.mkdir(parents=True)
    release = releases / name
    release.mkdir()
    state = base / 'state'; state.write_text('1', encoding='utf-8')
    log = base / 'log'; log.write_text('', encoding='utf-8')
    if with_drush:
        fake_drush(release, state, log, executable=executable)
    current = base / 'current'
    current.symlink_to(release, target_is_directory=True)
    return current, release, state, log

with tempfile.TemporaryDirectory(prefix='agency-1304-maintenance-') as tmp:
    root = Path(tmp)
    current, _, state, log = prepare(root / 'canonical')
    one = run_helper(current, '1', state, log)
    assert one.returncode == 0, one
    assert one.stdout.strip() == 'MAINTENANCE_MODE_CHANGE=1_TO_0'
    calls = log.read_text(encoding='utf-8').splitlines()
    assert sum(line.startswith('state:set system.maintenance_mode 0') for line in calls) == 1
    assert sum(line == 'cr' for line in calls) == 1
    print('CANONICAL_CURRENT_SYMLINK_SAFE_RELEASE=PASS')
    print('MAINTENANCE_1=EXACTLY_ONE_STATE_SET_AND_ONE_CR')

    log.write_text('', encoding='utf-8'); state.write_text('0', encoding='utf-8')
    zero = run_helper(current, '0', state, log)
    assert zero.returncode == 0, zero
    assert zero.stdout.strip() == 'MAINTENANCE_MODE_CHANGE=NONE'
    assert log.read_text(encoding='utf-8') == ''
    print('MAINTENANCE_0=NO_MUTATION')

    unknown = run_helper(current, 'UNKNOWN', state, log)
    assert unknown.returncode == 65, unknown
    print('MAINTENANCE_UNKNOWN=FAIL_CLOSED')

    regular_base = root / 'regular'; release = regular_base / 'releases/safe-release'; release.mkdir(parents=True)
    state_r = regular_base / 'state'; state_r.write_text('0', encoding='utf-8')
    log_r = regular_base / 'log'; log_r.write_text('', encoding='utf-8'); fake_drush(release, state_r, log_r)
    regular = regular_base / 'current'; regular.mkdir()
    assert run_helper(regular, '0', state_r, log_r).returncode != 0
    print('CURRENT_REGULAR_DIRECTORY=REJECTED')

    broken_base = root / 'broken'; (broken_base / 'releases').mkdir(parents=True)
    broken = broken_base / 'current'; broken.symlink_to(broken_base / 'releases/missing', target_is_directory=True)
    state_b = broken_base / 'state'; state_b.write_text('0', encoding='utf-8')
    log_b = broken_base / 'log'; log_b.write_text('', encoding='utf-8')
    assert run_helper(broken, '0', state_b, log_b).returncode != 0
    print('BROKEN_CURRENT_SYMLINK=REJECTED')

    escape_base = root / 'escape'; (escape_base / 'releases').mkdir(parents=True)
    outside = escape_base / 'outside'; outside.mkdir()
    state_e = escape_base / 'state'; state_e.write_text('0', encoding='utf-8')
    log_e = escape_base / 'log'; log_e.write_text('', encoding='utf-8'); fake_drush(outside, state_e, log_e)
    escaping = escape_base / 'current'; escaping.symlink_to(outside, target_is_directory=True)
    assert run_helper(escaping, '0', state_e, log_e).returncode != 0
    print('ESCAPING_CURRENT_SYMLINK=REJECTED')

    malformed, _, state_m, log_m = prepare(root / 'malformed', name='bad release name')
    assert run_helper(malformed, '0', state_m, log_m).returncode != 0
    print('MALFORMED_RELEASE_TARGET=REJECTED')

    missing, _, state_d, log_d = prepare(root / 'missing-drush', with_drush=False)
    assert run_helper(missing, '0', state_d, log_d).returncode != 0
    print('MISSING_DRUSH=REJECTED')

    nonexec, _, state_x, log_x = prepare(root / 'nonexec-drush', executable=False)
    assert run_helper(nonexec, '0', state_x, log_x).returncode != 0
    print('NON_EXECUTABLE_DRUSH=REJECTED')
