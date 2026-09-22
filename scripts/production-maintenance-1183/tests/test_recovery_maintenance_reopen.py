#!/usr/bin/env python3
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'recovery-maintenance-reopen.sh'

with tempfile.TemporaryDirectory(prefix='agency-1300-maintenance-') as tmp:
    root = Path(tmp)
    vendor = root / 'vendor/bin'
    vendor.mkdir(parents=True)
    state = root / 'state'
    log = root / 'log'
    state.write_text('1', encoding='utf-8')
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
    drush.chmod(0o700)
    env = os.environ.copy()
    env.update({'FAKE_STATE': str(state), 'FAKE_LOG': str(log)})

    one = subprocess.run(
        ['bash', str(HELPER), str(root), '1'],
        env=env, text=True, capture_output=True, check=False,
    )
    assert one.returncode == 0, one
    assert 'MAINTENANCE_MODE_CHANGE=1_TO_0' in one.stdout
    calls = log.read_text(encoding='utf-8').splitlines()
    assert sum(line.startswith('state:set system.maintenance_mode 0') for line in calls) == 1
    assert sum(line == 'cr' for line in calls) == 1
    print('MAINTENANCE_1_TO_0=PASS')

    log.write_text('', encoding='utf-8')
    zero = subprocess.run(
        ['bash', str(HELPER), str(root), '0'],
        env=env, text=True, capture_output=True, check=False,
    )
    assert zero.returncode == 0, zero
    assert 'MAINTENANCE_MODE_CHANGE=NONE' in zero.stdout
    assert log.read_text(encoding='utf-8') == ''
    print('MAINTENANCE_0_NO_TOGGLE=PASS')

    unknown = subprocess.run(
        ['bash', str(HELPER), str(root), 'UNKNOWN'],
        env=env, text=True, capture_output=True, check=False,
    )
    assert unknown.returncode == 65, unknown
    print('MAINTENANCE_UNKNOWN_FAIL_CLOSED=PASS')
