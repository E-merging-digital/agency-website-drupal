#!/usr/bin/python3 -I
"""Temporary #938 PREPROD server-to-server preparation worker."""
from __future__ import annotations

import hashlib
import importlib.util
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from types import ModuleType
from typing import BinaryIO

HELPER_PATH = Path('/usr/local/sbin/agency-preprod-staging-db')
EXPECTED_HELPER_SHA256 = 'a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
MARIADB_DUMP = '/usr/bin/mariadb-dump'
RUNUSER = '/usr/sbin/runuser'
SHARED = Path('/var/www/agency-preprod/shared')
REQUEST_ROOT = Path('/run/agency-preprod-refresh')
DEPLOY_USER = 'agency-preprod'
REQUEST_RE = re.compile(r'^apply-[0-9]+-[A-Za-z0-9._-]{8,40}-r1$')
SHA40_RE = re.compile(r'^[0-9a-f]{40}$')
HOST_RE = re.compile(r'^[A-Za-z0-9._:-]+$')
USER_RE = re.compile(r'^[A-Za-z_][A-Za-z0-9._-]*$')
CLEAN_ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root', 'LC_ALL': 'C'}


class WorkerError(RuntimeError):
    pass


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def require_root_regular(path: Path, mode: int) -> None:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise WorkerError('Unsafe request-scoped root file.')
    if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != mode:
        raise WorkerError('Invalid request-scoped root file identity.')


def load_installed_helper() -> ModuleType:
    require_root_regular(HELPER_PATH, 0o755)
    if sha256_file(HELPER_PATH) != EXPECTED_HELPER_SHA256:
        raise WorkerError('Installed bounded staging helper identity mismatch.')
    spec = importlib.util.spec_from_file_location('agency_preprod_staging_db', HELPER_PATH)
    if spec is None or spec.loader is None:
        raise WorkerError('Installed bounded staging helper cannot be loaded.')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def controlled_server_to_server_allowed(policy: dict[str, object]) -> bool:
    try:
        paths = policy['execution_boundary']['raw_prod_data']['allowed_paths']  # type: ignore[index]
    except (KeyError, TypeError):
        return False
    return isinstance(paths, list) and any(
        isinstance(entry, dict)
        and entry.get('type') == 'CONTROLLED_SERVER_TO_SERVER'
        and entry.get('requirement')
        == 'RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE'
        for entry in paths
    )


def import_snapshot_stream(helper: ModuleType, scope: object, client_file: str, source: BinaryIO) -> None:
    process = subprocess.Popen(
        [helper.MARIADB_BIN, f'--defaults-file={client_file}', '--protocol=socket', '--local-infile=0', f'--database={scope.database}'],
        stdin=subprocess.PIPE,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=helper.CLEAN_ENV,
    )
    if process.stdin is None:
        raise WorkerError('Restricted staging import stdin unavailable.')
    total = 0
    failed = False
    try:
        while True:
            chunk = source.read(1024 * 1024)
            if not chunk:
                break
            total += len(chunk)
            if total > helper.MAX_SNAPSHOT_BYTES:
                failed = True
                break
            try:
                process.stdin.write(chunk)
            except BrokenPipeError:
                failed = True
                break
    finally:
        try:
            process.stdin.close()
        except BrokenPipeError:
            failed = True
    if failed:
        process.kill()
    if process.wait() != 0 or total <= 0 or failed:
        raise WorkerError('Direct bounded staging import failed.')


def require_semantic_coverage(evidence: dict[str, str]) -> None:
    for key in (
        'user_sanitization', 'auth_material_invalidation', 'webform_submissions_purge',
        'sessions_purge', 'flood_rate_limit_purge', 'dblog_watchdog_purge',
        'batch_temp_state_purge', 'queues_purge', 'cache_purge',
        'runtime_state_reset', 'credential_state_removal',
    ):
        if evidence.get(key) != 'PASS':
            raise WorkerError('Sanitization semantic coverage assertion failed.')


def deploy_identity() -> tuple[int, int]:
    account = pwd.getpwnam(DEPLOY_USER)
    metadata = os.lstat(SHARED)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != account.pw_uid:
        raise WorkerError('PREPROD shared root identity is unsafe.')
    return account.pw_uid, account.pw_gid


def prepare_job(request_id: str, uid: int, gid: int) -> Path:
    jobs = SHARED / 'refresh-jobs'
    metadata = os.lstat(jobs)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        raise WorkerError('Refresh jobs root is unsafe.')
    job = jobs / request_id
    if job.exists() and (job.is_symlink() or not job.is_dir()):
        raise WorkerError('Refresh job path is unsafe.')
    job.mkdir(mode=0o700, exist_ok=True)
    os.chown(job, uid, gid)
    os.chmod(job, 0o700)
    if (job / 'result.env').exists():
        raise WorkerError('Request already has terminal metadata.')
    return job


def write_result(job: Path, request_id: str, expected_main: str, outcome: str, detail: str, uid: int, gid: int) -> None:
    if outcome not in {'ROLLED_BACK', 'HUMAN_RECOVERY_REQUIRED'} or not re.fullmatch(r'[A-Z0-9_]+', detail):
        raise WorkerError('Invalid bounded terminal metadata.')
    result = job / 'result.env'
    if result.exists():
        return
    tmp = job / f'result.env.tmp.{os.getpid()}'
    tmp.write_text(
        f'schema_version=1\nrequest_id={request_id}\nmain_sha={expected_main}\n'
        f'outcome={outcome}\ndetail={detail}\n'
        f"finished_at={datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')}\n",
        encoding='utf-8',
    )
    os.chown(tmp, uid, gid)
    os.chmod(tmp, 0o600)
    os.replace(tmp, result)


def remove_activation_inputs(job: Path) -> None:
    for name in ('sanitized.sql.tmp', 'sanitized.sql', 'worker.sh'):
        try:
            (job / name).unlink()
        except FileNotFoundError:
            pass


def cleanup_raw_scope(helper: ModuleType, scope: object) -> bool:
    for _ in range(2):
        try:
            helper.cleanup_scope(scope)
            helper.require_absent(scope)
            return True
        except Exception:
            pass
    return False


def cleanup_root_stage(stage: Path) -> bool:
    for _ in range(2):
        try:
            if os.path.lexists(stage):
                metadata = os.lstat(stage)
                if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
                    return False
                shutil.rmtree(stage)
            if not os.path.lexists(stage):
                return True
        except OSError:
            pass
    return False


def terminal_before_activation(
    job: Path,
    request_id: str,
    expected_main: str,
    stage: Path,
    uid: int,
    gid: int,
    raw_cleanup_proven: bool,
    ordinary_detail: str,
) -> int:
    remove_activation_inputs(job)
    root_cleanup_proven = cleanup_root_stage(stage)
    if not raw_cleanup_proven:
        outcome, detail, status = 'HUMAN_RECOVERY_REQUIRED', 'RAW_STAGING_CLEANUP_UNPROVEN', 90
    elif not root_cleanup_proven:
        outcome, detail, status = 'HUMAN_RECOVERY_REQUIRED', 'PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN', 91
    else:
        outcome, detail, status = 'ROLLED_BACK', ordinary_detail, 80
    write_result(job, request_id, expected_main, outcome, detail, uid, gid)
    return status


def main(argv: list[str]) -> int:
    if os.geteuid() != 0 or len(argv) != 6:
        raise WorkerError('Invalid fixed root invocation.')
    request_id, expected_main, prod_release, prod_host, prod_user = argv[1:]
    if not REQUEST_RE.fullmatch(request_id) or not SHA40_RE.fullmatch(expected_main):
        raise WorkerError('Invalid request/main identity.')

    suffix = hashlib.sha256(request_id.encode()).hexdigest()[:12]
    stage = REQUEST_ROOT / suffix
    uid, gid = deploy_identity()
    job = prepare_job(request_id, uid, gid)
    helper: ModuleType | None = None
    scope: object | None = None
    client_file = ''
    snapshot: subprocess.Popen[bytes] | None = None
    preparation_failed = False
    raw_cleanup_proven = True

    try:
        if not SHA40_RE.fullmatch(prod_release) or not HOST_RE.fullmatch(prod_host) or not USER_RE.fullmatch(prod_user):
            raise WorkerError('Invalid immutable PROD SSH metadata.')
        if Path(__file__).resolve().parent != stage.resolve():
            raise WorkerError('Worker outside request-scoped root stage.')
        helper = load_installed_helper()
        sanitizer, policy = helper.load_bundle()
        if not controlled_server_to_server_allowed(policy):
            raise WorkerError('Single policy does not authorize server-to-server raw data.')
        if not os.path.isfile(MARIADB_DUMP) or not os.access(MARIADB_DUMP, os.X_OK):
            raise WorkerError('Fixed MariaDB dump client unavailable.')

        prod_key = stage / 'prod-read.key'
        trust_repo = stage / 'trust-repo'
        trust_home = stage / 'trust-home'
        remote_snapshot = stage / 'scripts/production-readonly-snapshot/remote-stream.sh'
        activation_source = stage / 'remote-apply-worker.sh'
        for path in (prod_key, remote_snapshot, activation_source):
            require_root_regular(path, 0o600 if path == prod_key else 0o700)
        trust_home.mkdir(mode=0o700)
        trust_script = trust_repo / 'scripts/production-ssh-trust/manage-known-host.sh'
        require_root_regular(trust_script, 0o700)
        trust = subprocess.run(
            ['/bin/bash', str(trust_script), 'PROVISION'], cwd=trust_repo,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            env={**CLEAN_ENV, 'HOME': str(trust_home), 'SERVER_HOST': prod_host}, check=False,
        )
        if trust.returncode != 0:
            raise WorkerError('Pinned PROD trust provisioning failed.')
        known_hosts = trust_home / '.ssh/known_hosts'
        require_root_regular(known_hosts, 0o600)

        scope = helper.derive_scope(request_id)
        password = helper.prepare_import_scope(scope)
        client_file = helper.write_restricted_client_file(scope, password)
        password = ''
        with remote_snapshot.open('rb') as remote_stdin:
            snapshot = subprocess.Popen(
                ['/usr/bin/ssh', '-i', str(prod_key), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
                 '-o', 'StrictHostKeyChecking=yes', '-o', f'UserKnownHostsFile={known_hosts}', '-o', 'ConnectTimeout=15',
                 f'{prod_user}@{prod_host}', 'bash -s -- ' + prod_release],
                stdin=remote_stdin, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=CLEAN_ENV,
            )
            if snapshot.stdout is None:
                raise WorkerError('PROD snapshot stream unavailable.')
            import_snapshot_stream(helper, scope, client_file, snapshot.stdout)
            snapshot.stdout.close()
            if snapshot.wait() != 0:
                raise WorkerError('Read-only PROD snapshot route failed.')

        if helper.table_count(scope) <= 0:
            raise WorkerError('Imported staging schema proof failed.')
        execution = sanitizer.sanitize(scope.database, policy)
        evidence = sanitizer.assert_sanitized(scope.database, execution)
        if not isinstance(evidence, dict):
            raise WorkerError('Sanitization evidence contract invalid.')
        require_semantic_coverage({str(k): str(v) for k, v in evidence.items()})

        sanitized_tmp = job / 'sanitized.sql.tmp'
        with sanitized_tmp.open('wb') as output:
            os.chmod(sanitized_tmp, 0o600)
            dump = subprocess.run(
                [MARIADB_DUMP, '--protocol=socket', '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', scope.database],
                stdout=output, stderr=subprocess.DEVNULL, env=CLEAN_ENV, check=False,
            )
        if dump.returncode != 0 or sanitized_tmp.stat().st_size <= 0:
            raise WorkerError('Sanitized SQL export failed.')
    except Exception:
        preparation_failed = True
    finally:
        if snapshot is not None and snapshot.poll() is None:
            snapshot.kill(); snapshot.wait()
        if client_file:
            try:
                os.unlink(client_file)
            except FileNotFoundError:
                pass
        if helper is not None and scope is not None:
            raw_cleanup_proven = cleanup_raw_scope(helper, scope)

    if preparation_failed or not raw_cleanup_proven:
        return terminal_before_activation(job, request_id, expected_main, stage, uid, gid, raw_cleanup_proven,
                                          'NO_PREPROD_RUNTIME_MUTATION_SERVER_TO_SERVER_PREP_FAILED')

    sanitized_tmp, sanitized, activation = job / 'sanitized.sql.tmp', job / 'sanitized.sql', job / 'worker.sh'
    try:
        if sanitized.exists() or activation.exists():
            raise WorkerError('Request-scoped activation state already exists.')
        os.chown(sanitized_tmp, uid, gid); os.chmod(sanitized_tmp, 0o600); os.replace(sanitized_tmp, sanitized)
        shutil.copyfile(stage / 'remote-apply-worker.sh', activation)
        os.chown(activation, uid, gid); os.chmod(activation, 0o700)
        sanitized_sha = sha256_file(sanitized)
        if not re.fullmatch(r'[0-9a-f]{64}', sanitized_sha):
            raise WorkerError('Sanitized SQL digest invalid.')
    except Exception:
        return terminal_before_activation(job, request_id, expected_main, stage, uid, gid, True,
                                          'NO_PREPROD_RUNTIME_MUTATION_SANITIZED_HANDOFF_FAILED')

    # Absolute gate: the request-scoped PROD identity/trust stage must be gone,
    # and its absence must be proven, before the existing activation worker starts.
    if not cleanup_root_stage(stage) or os.path.lexists(stage):
        remove_activation_inputs(job)
        write_result(job, request_id, expected_main, 'HUMAN_RECOVERY_REQUIRED',
                     'PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN', uid, gid)
        return 91

    try:
        os.execv(RUNUSER, [RUNUSER, '-u', DEPLOY_USER, '--', str(activation), request_id, expected_main, sanitized_sha])
    except OSError:
        remove_activation_inputs(job)
        write_result(job, request_id, expected_main, 'ROLLED_BACK',
                     'NO_PREPROD_RUNTIME_MUTATION_ACTIVATION_LAUNCH_FAILED', uid, gid)
        return 83
    return 0


def emergency_result(argv: list[str]) -> None:
    """Prevent unexpected pre-activation exceptions from becoming poll timeouts."""
    if len(argv) < 3 or not REQUEST_RE.fullmatch(argv[1]) or not SHA40_RE.fullmatch(argv[2]):
        return
    request_id, expected_main = argv[1], argv[2]
    stage = REQUEST_ROOT / hashlib.sha256(request_id.encode()).hexdigest()[:12]
    try:
        uid, gid = deploy_identity()
        job = prepare_job(request_id, uid, gid)
    except Exception:
        return
    try:
        helper = load_installed_helper()
        raw_cleanup_proven = cleanup_raw_scope(helper, helper.derive_scope(request_id))
    except Exception:
        raw_cleanup_proven = False
    terminal_before_activation(job, request_id, expected_main, stage, uid, gid, raw_cleanup_proven,
                               'NO_PREPROD_RUNTIME_MUTATION_UNEXPECTED_PREACTIVATION_FAILURE')


if __name__ == '__main__':
    try:
        raise SystemExit(main(sys.argv))
    except SystemExit:
        raise
    except Exception:
        emergency_result(sys.argv)
        raise SystemExit(80)
