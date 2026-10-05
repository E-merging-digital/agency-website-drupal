#!/usr/bin/env python3
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'stable-ssh-readiness.sh'


def run(sequence: list[int], required: int = 3, attempts: int = 8) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix='agency-1300-ssh-') as tmp:
        root = Path(tmp)
        state = root / 'state'
        values = root / 'values'
        values.write_text('\n'.join(str(x) for x in sequence) + '\n', encoding='utf-8')
        fake = root / 'probe'
        fake.write_text(
            '#!/usr/bin/env bash\n'
            'set -euo pipefail\n'
            'state="${FAKE_STATE}"\n'
            'values="${FAKE_VALUES}"\n'
            'n=0\n'
            '[[ -f "$state" ]] && n="$(cat "$state")"\n'
            'n=$((n + 1))\n'
            'printf "%s" "$n" > "$state"\n'
            'code="$(sed -n "${n}p" "$values")"\n'
            '[[ -n "$code" ]] || code=1\n'
            'exit "$code"\n',
            encoding='utf-8',
        )
        fake.chmod(0o700)
        env = os.environ.copy()
        env.update({'FAKE_STATE': str(state), 'FAKE_VALUES': str(values)})
        return subprocess.run(
            ['bash', str(HELPER), str(required), str(attempts), '0', '--', str(fake)],
            text=True,
            capture_output=True,
            env=env,
            check=False,
        )


race = run([0, 255, 0, 0, 0])
assert race.returncode == 0, race
assert 'SSH_READINESS_PROBES=5' in race.stdout, race.stdout
print('TRANSIENT_SINGLE_SUCCESS_NOT_SUFFICIENT=PASS')

stable = run([255, 0, 0, 0])
assert stable.returncode == 0, stable
assert 'SSH_CONSECUTIVE_SUCCESSES=3' in stable.stdout
print('STABLE_CONSECUTIVE_READINESS=PASS')

timeout = run([255, 0, 255, 0, 255, 0, 255, 0], attempts=8)
assert timeout.returncode == 75, timeout
assert 'STABLE_SSH_READINESS=FAIL' in timeout.stderr
print('READINESS_TIMEOUT_FAIL_CLOSED=PASS')
