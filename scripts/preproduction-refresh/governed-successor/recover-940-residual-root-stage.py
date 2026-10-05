#!/usr/bin/python3 -I
"""Fixed #943 PREPROD residual root-stage PLAN and cleanup capability."""
from __future__ import annotations

import hashlib
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
from pathlib import Path
from typing import Final

REQUEST_ID: Final = 'apply-940-first-real-s2s-r1'
EXPECTED_FAILED_MAIN: Final = '0b61d56264ad0163cd3bdbd5ea6e07253a155fbb'
DEPLOY_USER: Final = 'agency-preprod'
JOB_ROOT: Final = Path('/var/www/agency-preprod/shared/refresh-jobs')
REQUEST_DIR: Final = JOB_ROOT / REQUEST_ID
ROOT_STAGE: Final = Path('/run/agency-preprod-refresh/412fc11485c5')
BOOTSTRAP_LOG: Final = ROOT_STAGE / 'bootstrap.log'
PROD_READ_KEY: Final = ROOT_STAGE / 'prod-read.key'
REFRESH_LOCK: Final = Path('/run/lock/agency-preprod-refresh.lock')
DEPLOY_LOCK: Final = Path('/var/www/agency-preprod/shared/deploy.lock')
STAGING_HELPER: Final = Path('/usr/local/sbin/agency-preprod-staging-db')
EXPECTED_STAGING_HELPER_SHA256: Final = 'a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
LSLOCKS: Final = '/usr/bin/lslocks'
CLEAN_ENV: Final = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root', 'LC_ALL': 'C'}
MAX_BOOTSTRAP_LOG_BYTES: Final = 16 * 1024 * 1024

# The exact tree staged by run-server-to-server-apply.sh before the detached
# worker starts. bootstrap.log is created by the fixed nohup redirection.
EXPECTED_INVENTORY: Final = {
    'bootstrap.log': 'file',
    'prod-read.key': 'file',
    'remote-apply-worker.sh': 'file',
    'remote-server-to-server-worker.py': 'file',
    'scripts': 'dir',
    'scripts/production-readonly-snapshot': 'dir',
    'scripts/production-readonly-snapshot/remote-stream.sh': 'file',
    'trust-repo': 'dir',
    'trust-repo/scripts': 'dir',
    'trust-repo/scripts/production-ssh-trust': 'dir',
    'trust-repo/scripts/production-ssh-trust/manage-known-host.sh': 'file',
    'trust-repo/scripts/production-ssh-trust/prod-ed25519.pub': 'file',
    'trust-repo/scripts/production-ssh-trust/prod-ed25519.sha256': 'file',
}
EXPECTED_FIXED_MODES: Final = {
    'prod-read.key': 0o600,
    'remote-apply-worker.sh': 0o700,
    'remote-server-to-server-worker.py': 0o700,
    'scripts/production-readonly-snapshot/remote-stream.sh': 0o700,
    'trust-repo/scripts/production-ssh-trust/manage-known-host.sh': 0o700,
    'trust-repo/scripts/production-ssh-trust/prod-ed25519.pub': 0o600,
    'trust-repo/scripts/production-ssh-trust/prod-ed25519.sha256': 0o600,
}


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def bounded_mode(metadata: os.stat_result) -> str:
    return f'{stat.S_IMODE(metadata.st_mode):04o}'


def worker_state() -> str:
    """Match only the fixed consumed request in process command lines."""
    try:
        proc = Path('/proc')
        matched = 0
        for entry in proc.iterdir():
            if not entry.name.isdigit():
                continue
            try:
                raw = (entry / 'cmdline').read_bytes()
            except (FileNotFoundError, PermissionError, ProcessLookupError, OSError):
                continue
            if REQUEST_ID.encode('ascii') in raw:
                matched += 1
                if matched > 8:
                    return 'UNOBSERVABLE'
        return 'ALIVE' if matched else 'DEAD'
    except OSError:
        return 'UNOBSERVABLE'


def request_dir_state(deploy_uid: int, deploy_gid: int) -> str:
    try:
        metadata = os.lstat(REQUEST_DIR)
    except FileNotFoundError:
        return 'ABSENT'
    except OSError:
        return 'UNSAFE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        return 'UNSAFE'
    if metadata.st_uid != deploy_uid or metadata.st_gid != deploy_gid:
        return 'UNSAFE'
    if stat.S_IMODE(metadata.st_mode) != 0o700:
        return 'UNSAFE'
    return 'PRESENT'


def refresh_jobs_root_state(deploy_uid: int) -> tuple[str, str, str]:
    try:
        metadata = os.lstat(JOB_ROOT)
    except FileNotFoundError:
        return 'ABSENT', 'UNOBSERVABLE', 'UNOBSERVABLE'
    except OSError:
        return 'UNSAFE', 'UNOBSERVABLE', 'UNOBSERVABLE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        return 'UNSAFE', 'UNOBSERVABLE', 'UNOBSERVABLE'
    owner = 'AGENCY_PREPROD' if metadata.st_uid == deploy_uid else 'OTHER'
    return 'PRESENT', owner, bounded_mode(metadata)


def root_stage_state() -> tuple[str, str]:
    try:
        metadata = os.lstat(ROOT_STAGE)
    except FileNotFoundError:
        return 'ABSENT', 'UNOBSERVABLE'
    except OSError:
        return 'UNSAFE', 'UNOBSERVABLE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        return 'UNSAFE', 'UNOBSERVABLE'
    owner_mode = (
        'EXPECTED'
        if metadata.st_uid == 0 and metadata.st_gid == 0 and stat.S_IMODE(metadata.st_mode) == 0o700
        else 'MISMATCH'
    )
    return 'PRESENT', owner_mode


def stage_inventory_state(stage_state: str) -> str:
    if stage_state != 'PRESENT':
        return 'UNOBSERVABLE'
    actual: dict[str, str] = {}
    try:
        for current, dirs, files in os.walk(ROOT_STAGE, topdown=True, followlinks=False):
            current_path = Path(current)
            for name in dirs + files:
                path = current_path / name
                relative = path.relative_to(ROOT_STAGE).as_posix()
                metadata = os.lstat(path)
                if stat.S_ISLNK(metadata.st_mode):
                    actual[relative] = 'symlink'
                elif stat.S_ISDIR(metadata.st_mode):
                    actual[relative] = 'dir'
                elif stat.S_ISREG(metadata.st_mode):
                    actual[relative] = 'file'
                else:
                    actual[relative] = 'other'
                if len(actual) > len(EXPECTED_INVENTORY):
                    return 'UNEXPECTED'
        if actual != EXPECTED_INVENTORY:
            return 'UNEXPECTED'

        for relative, expected_type in EXPECTED_INVENTORY.items():
            path = ROOT_STAGE / relative
            metadata = os.lstat(path)
            if metadata.st_uid != 0 or metadata.st_gid != 0:
                return 'UNEXPECTED'
            mode = stat.S_IMODE(metadata.st_mode)
            if expected_type == 'dir':
                if mode & 0o022:
                    return 'UNEXPECTED'
            elif relative == 'bootstrap.log':
                if mode & 0o022 or metadata.st_size > MAX_BOOTSTRAP_LOG_BYTES:
                    return 'UNEXPECTED'
            elif mode != EXPECTED_FIXED_MODES.get(relative):
                return 'UNEXPECTED'
        return 'EXACT_EXPECTED_SET'
    except (FileNotFoundError, PermissionError, OSError, ValueError):
        return 'UNOBSERVABLE'


def prod_read_identity_state(stage_state: str) -> str:
    if stage_state == 'ABSENT':
        return 'ABSENT'
    if stage_state != 'PRESENT':
        return 'UNOBSERVABLE'
    try:
        metadata = os.lstat(PROD_READ_KEY)
    except FileNotFoundError:
        return 'ABSENT'
    except OSError:
        return 'UNOBSERVABLE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        return 'UNSAFE'
    if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != 0o600:
        return 'UNSAFE'
    return 'PRESENT_SAFE_METADATA'


def bootstrap_log_state(stage_state: str) -> tuple[str, str]:
    if stage_state == 'ABSENT':
        return 'ABSENT', 'NONE'
    if stage_state != 'PRESENT':
        return 'UNOBSERVABLE', 'NONE'
    try:
        metadata = os.lstat(BOOTSTRAP_LOG)
    except FileNotFoundError:
        return 'ABSENT', 'NONE'
    except OSError:
        return 'UNOBSERVABLE', 'NONE'
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        return 'UNOBSERVABLE', 'NONE'
    if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) & 0o022:
        return 'UNOBSERVABLE', 'NONE'
    if metadata.st_size < 0 or metadata.st_size > MAX_BOOTSTRAP_LOG_BYTES:
        return 'UNOBSERVABLE', 'NONE'
    return 'PRESENT', str(metadata.st_size)


def raw_staging_scope_state() -> str:
    """Reuse only the installed helper's fixed read-only VERIFY_ABSENCE action."""
    try:
        metadata = os.lstat(STAGING_HELPER)
        if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
            return 'UNOBSERVABLE'
        if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != 0o755:
            return 'UNOBSERVABLE'
        if sha256_file(STAGING_HELPER) != EXPECTED_STAGING_HELPER_SHA256:
            return 'UNOBSERVABLE'
        result = subprocess.run(
            [str(STAGING_HELPER), 'VERIFY_ABSENCE', REQUEST_ID, '0'],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            timeout=5,
            check=False,
            env=CLEAN_ENV,
        )
    except (OSError, subprocess.TimeoutExpired):
        return 'UNOBSERVABLE'
    expected = 'staging_db_present_after_cleanup=NO\nstaging_account_present_after_cleanup=NO\n'
    if result.returncode == 0 and result.stdout == expected:
        return 'ABSENT'
    return 'UNOBSERVABLE'


def lock_state(path: Path) -> str:
    try:
        result = subprocess.run(
            [LSLOCKS, '--noheadings', '--output', 'PATH'],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            timeout=3,
            check=False,
            env=CLEAN_ENV,
        )
    except (OSError, subprocess.TimeoutExpired):
        return 'UNOBSERVABLE'
    if result.returncode != 0:
        return 'UNOBSERVABLE'
    paths = {line.strip() for line in result.stdout.splitlines() if line.strip()}
    return 'HELD' if str(path) in paths else 'FREE'


def cleanup_is_eligible(observation: dict[str, str]) -> bool:
    return all([
        observation['worker_process'] == 'DEAD',
        observation['request_dir'] == 'ABSENT',
        observation['refresh_lock'] == 'FREE',
        observation['deploy_lock'] == 'FREE',
        observation['root_stage'] == 'PRESENT',
        observation['root_stage_owner_mode'] == 'EXPECTED',
        observation['root_stage_inventory'] == 'EXACT_EXPECTED_SET',
        observation['prod_read_identity_file'] == 'PRESENT_SAFE_METADATA',
        observation['raw_staging_scope'] == 'ABSENT',
    ])


def root_cause_class(observation: dict[str, str]) -> str:
    if observation['refresh_jobs_root'] == 'ABSENT':
        return 'REFRESH_JOBS_ROOT_MISSING'
    if observation['refresh_jobs_root'] == 'UNSAFE':
        return 'REFRESH_JOBS_ROOT_UNSAFE'
    if (
        observation['refresh_jobs_root'] == 'PRESENT'
        and observation['worker_process'] == 'DEAD'
        and observation['request_dir'] == 'ABSENT'
    ):
        return 'EARLY_BOOTSTRAP_OTHER'
    return 'UNPROVEN'


def observe() -> dict[str, str]:
    try:
        account = pwd.getpwnam(DEPLOY_USER)
    except KeyError:
        deploy_uid = -1
        deploy_gid = -1
    else:
        deploy_uid = account.pw_uid
        deploy_gid = account.pw_gid

    worker = worker_state()
    request_dir = request_dir_state(deploy_uid, deploy_gid) if deploy_uid >= 0 else 'UNSAFE'
    jobs_root, jobs_owner, jobs_mode = (
        refresh_jobs_root_state(deploy_uid)
        if deploy_uid >= 0
        else ('UNSAFE', 'UNOBSERVABLE', 'UNOBSERVABLE')
    )
    stage, stage_owner_mode = root_stage_state()
    inventory = stage_inventory_state(stage)
    prod_key = prod_read_identity_state(stage)
    bootstrap_log, bootstrap_bytes = bootstrap_log_state(stage)
    raw_scope = raw_staging_scope_state()
    refresh_lock = lock_state(REFRESH_LOCK)
    deploy_lock = lock_state(DEPLOY_LOCK)

    observation = {
        'schema_version': '1',
        'request_id': REQUEST_ID,
        'expected_failed_main': EXPECTED_FAILED_MAIN,
        'worker_process': worker,
        'request_dir': request_dir,
        'refresh_jobs_root': jobs_root,
        'refresh_jobs_root_owner': jobs_owner,
        'refresh_jobs_root_mode': jobs_mode,
        'root_stage': stage,
        'root_stage_owner_mode': stage_owner_mode,
        'root_stage_inventory': inventory,
        'prod_read_identity_file': prod_key,
        'bootstrap_log': bootstrap_log,
        'bootstrap_log_bytes': bootstrap_bytes,
        'raw_staging_scope': raw_scope,
        'refresh_lock': refresh_lock,
        'deploy_lock': deploy_lock,
    }
    observation['cleanup_eligible'] = 'YES' if cleanup_is_eligible(observation) else 'NO'
    observation['root_cause_class'] = root_cause_class(observation)
    return observation


def emit(observation: dict[str, str], action: str, cleanup: str = 'NOT_REQUESTED', root_stage_after: str = 'UNOBSERVED') -> None:
    order = [
        'schema_version', 'request_id', 'expected_failed_main', 'worker_process',
        'request_dir', 'refresh_jobs_root', 'refresh_jobs_root_owner',
        'refresh_jobs_root_mode', 'root_stage', 'root_stage_owner_mode',
        'root_stage_inventory', 'prod_read_identity_file', 'bootstrap_log',
        'bootstrap_log_bytes', 'raw_staging_scope', 'refresh_lock', 'deploy_lock',
        'cleanup_eligible', 'root_cause_class',
    ]
    print(f'action={action}')
    for key in order:
        print(f'{key}={observation[key]}')
    print(f'cleanup={cleanup}')
    print(f'root_stage_after={root_stage_after}')


def post_cleanup_stage_state() -> str:
    try:
        os.lstat(ROOT_STAGE)
    except FileNotFoundError:
        return 'ABSENT'
    except OSError:
        return 'UNOBSERVABLE'
    return 'PRESENT'


def cleanup_fixed_stage() -> int:
    first = observe()
    if not cleanup_is_eligible(first):
        emit(first, 'CLEANUP', 'FAIL_CLOSED', post_cleanup_stage_state())
        return 78

    # Re-observe immediately before deletion; no lock is acquired or changed.
    second = observe()
    if not cleanup_is_eligible(second):
        emit(second, 'CLEANUP', 'FAIL_CLOSED', post_cleanup_stage_state())
        return 78

    if str(ROOT_STAGE) != '/run/agency-preprod-refresh/412fc11485c5':
        emit(second, 'CLEANUP', 'FAIL_CLOSED', 'UNOBSERVABLE')
        return 78

    try:
        metadata = os.lstat(ROOT_STAGE)
        if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
            emit(second, 'CLEANUP', 'FAIL_CLOSED', 'PRESENT')
            return 78
        shutil.rmtree(ROOT_STAGE)
    except OSError:
        emit(second, 'CLEANUP', 'FAIL_CLOSED', post_cleanup_stage_state())
        return 79

    after = post_cleanup_stage_state()
    if after != 'ABSENT':
        emit(second, 'CLEANUP', 'FAIL_CLOSED', after)
        return 79
    emit(second, 'CLEANUP', 'COMPLETED', 'ABSENT')
    return 0


def self_test() -> None:
    base = {
        'worker_process': 'DEAD',
        'request_dir': 'ABSENT',
        'refresh_jobs_root': 'ABSENT',
        'refresh_lock': 'FREE',
        'deploy_lock': 'FREE',
        'root_stage': 'PRESENT',
        'root_stage_owner_mode': 'EXPECTED',
        'root_stage_inventory': 'EXACT_EXPECTED_SET',
        'prod_read_identity_file': 'PRESENT_SAFE_METADATA',
        'raw_staging_scope': 'ABSENT',
    }
    assert cleanup_is_eligible(base)
    assert root_cause_class(base) == 'REFRESH_JOBS_ROOT_MISSING'

    for key, value in (
        ('worker_process', 'ALIVE'),
        ('request_dir', 'PRESENT'),
        ('refresh_lock', 'HELD'),
        ('refresh_lock', 'UNOBSERVABLE'),
        ('deploy_lock', 'HELD'),
        ('root_stage_inventory', 'UNEXPECTED'),
        ('raw_staging_scope', 'PRESENT'),
        ('raw_staging_scope', 'UNOBSERVABLE'),
    ):
        candidate = dict(base)
        candidate[key] = value
        assert not cleanup_is_eligible(candidate), (key, value)

    unsafe_root = dict(base)
    unsafe_root['refresh_jobs_root'] = 'UNSAFE'
    assert root_cause_class(unsafe_root) == 'REFRESH_JOBS_ROOT_UNSAFE'

    other = dict(base)
    other['refresh_jobs_root'] = 'PRESENT'
    assert root_cause_class(other) == 'EARLY_BOOTSTRAP_OTHER'


if __name__ == '__main__':
    if sys.argv == [sys.argv[0], '--self-test']:
        self_test()
        print('SELF_TEST=PASS')
        raise SystemExit(0)
    if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] not in {'PLAN', 'CLEANUP'}:
        raise SystemExit(64)
    if sys.argv[1] == 'PLAN':
        current = observe()
        emit(current, 'PLAN')
        raise SystemExit(0)
    raise SystemExit(cleanup_fixed_stage())
