#!/usr/bin/python3 -I
"""Temporary bounded #914 server-to-server preparation worker.

This fixed-purpose worker runs as root on PREPROD only. It reuses the installed
root-owned staging helper and its single sanitization policy, streams the fixed
read-only PROD snapshot directly into the derived staging database, exports only
the asserted sanitized database, then hands off to the existing #914 activation
worker as the PREPROD deploy user.
"""

from __future__ import annotations

import hashlib
import importlib.util
import os
import re
import shutil
import stat
import subprocess
import sys
from pathlib import Path
from types import ModuleType
from typing import BinaryIO

HELPER_PATH = Path("/usr/local/sbin/agency-preprod-staging-db")
EXPECTED_HELPER_SHA256 = "a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71"
MARIADB_DUMP = "/usr/bin/mariadb-dump"
RUNUSER = "/usr/sbin/runuser"
PROJECT_ROOT = Path("/var/www/agency-preprod")
SHARED = PROJECT_ROOT / "shared"
REQUEST_ROOT = Path("/run/agency-preprod-refresh")
REQUEST_RE = re.compile(r"^apply-[0-9]+-[A-Za-z0-9._-]{8,40}-r1$")
SHA40_RE = re.compile(r"^[0-9a-f]{40}$")
HOST_RE = re.compile(r"^[A-Za-z0-9._:-]+$")
USER_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9._-]*$")
CLEAN_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "LC_ALL": "C"}


class WorkerError(RuntimeError):
    """Fail-closed temporary server-to-server worker failure."""


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def require_root_regular(path: Path, mode: int) -> None:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise WorkerError("Request-scoped root file type is invalid.")
    if metadata.st_uid != 0 or metadata.st_gid != 0:
        raise WorkerError("Request-scoped root file ownership is invalid.")
    if stat.S_IMODE(metadata.st_mode) != mode:
        raise WorkerError("Request-scoped root file mode is invalid.")


def load_installed_helper() -> ModuleType:
    require_root_regular(HELPER_PATH, 0o755)
    if sha256_file(HELPER_PATH) != EXPECTED_HELPER_SHA256:
        raise WorkerError("Installed bounded staging helper identity mismatch.")
    spec = importlib.util.spec_from_file_location("agency_preprod_staging_db", HELPER_PATH)
    if spec is None or spec.loader is None:
        raise WorkerError("Installed bounded staging helper cannot be loaded.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def controlled_server_to_server_allowed(policy: dict[str, object]) -> bool:
    boundary = policy.get("execution_boundary")
    if not isinstance(boundary, dict):
        return False
    raw = boundary.get("raw_prod_data")
    if not isinstance(raw, dict):
        return False
    paths = raw.get("allowed_paths")
    if not isinstance(paths, list):
        return False
    return any(
        isinstance(entry, dict)
        and entry.get("type") == "CONTROLLED_SERVER_TO_SERVER"
        and entry.get("requirement")
        == "RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE"
        for entry in paths
    )


def import_snapshot_stream(helper: ModuleType, scope: object, client_file: str, source: BinaryIO) -> int:
    process = subprocess.Popen(
        [
            helper.MARIADB_BIN,
            f"--defaults-file={client_file}",
            "--protocol=socket",
            "--local-infile=0",
            f"--database={scope.database}",
        ],
        stdin=subprocess.PIPE,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=helper.CLEAN_ENV,
    )
    if process.stdin is None:
        process.kill()
        process.wait()
        raise WorkerError("Restricted staging import stdin is unavailable.")
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
    returncode = process.wait()
    if failed or total <= 0 or returncode != 0:
        raise WorkerError("Direct bounded staging import failed.")
    return total


def require_semantic_coverage(evidence: dict[str, str]) -> None:
    for key in (
        "user_sanitization",
        "auth_material_invalidation",
        "webform_submissions_purge",
        "sessions_purge",
        "flood_rate_limit_purge",
        "dblog_watchdog_purge",
        "batch_temp_state_purge",
        "queues_purge",
        "cache_purge",
        "runtime_state_reset",
        "credential_state_removal",
    ):
        if evidence.get(key) != "PASS":
            raise WorkerError("Sanitization semantic coverage assertion failed.")


def write_preactivation_result(
    job: Path,
    request_id: str,
    expected_main: str,
    uid: int,
    gid: int,
) -> None:
    result = job / "result.env"
    if result.exists():
        return
    tmp = job / f"result.env.tmp.{os.getpid()}"
    tmp.write_text(
        "schema_version=1\n"
        f"request_id={request_id}\n"
        f"main_sha={expected_main}\n"
        "outcome=ROLLED_BACK\n"
        "detail=NO_PREPROD_RUNTIME_MUTATION_SERVER_TO_SERVER_PREP_FAILED\n",
        encoding="utf-8",
    )
    os.chown(tmp, uid, gid)
    os.chmod(tmp, 0o600)
    os.replace(tmp, result)


def main(argv: list[str]) -> int:
    if os.geteuid() != 0:
        raise WorkerError("Root execution is required.")
    if len(argv) != 6:
        raise WorkerError("Expected request, main, PROD release, host and user only.")
    request_id, expected_main, prod_release, prod_host, prod_user = argv[1:]
    if not REQUEST_RE.fullmatch(request_id):
        raise WorkerError("Invalid request identity.")
    if not SHA40_RE.fullmatch(expected_main) or not SHA40_RE.fullmatch(prod_release):
        raise WorkerError("Invalid immutable identity.")
    if not HOST_RE.fullmatch(prod_host) or not USER_RE.fullmatch(prod_user):
        raise WorkerError("Invalid fixed PROD SSH metadata.")

    suffix = hashlib.sha256(request_id.encode("utf-8")).hexdigest()[:12]
    stage = REQUEST_ROOT / suffix
    if Path(__file__).resolve().parent != stage.resolve():
        raise WorkerError("Worker is outside the request-scoped root stage.")

    deploy_meta = os.stat(SHARED)
    if deploy_meta.st_uid == 0:
        raise WorkerError("PREPROD deploy identity must not be root.")
    deploy_user = subprocess.run(
        ["/usr/bin/id", "-nu", str(deploy_meta.st_uid)],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=True,
        env=CLEAN_ENV,
    ).stdout.strip()
    if not USER_RE.fullmatch(deploy_user):
        raise WorkerError("PREPROD deploy identity is invalid.")

    job = SHARED / "refresh-jobs" / request_id
    if job.exists() and (job.is_symlink() or not job.is_dir()):
        raise WorkerError("Refresh job path is unsafe.")
    job.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chown(job, deploy_meta.st_uid, deploy_meta.st_gid)
    os.chmod(job, 0o700)
    sanitized_tmp = job / "sanitized.sql.tmp"
    sanitized = job / "sanitized.sql"
    activation = job / "worker.sh"
    result = job / "result.env"
    if result.exists() or sanitized.exists() or activation.exists():
        raise WorkerError("Request-scoped activation state already exists.")

    scope = None
    helper = None
    client_file = ""
    snapshot: subprocess.Popen[bytes] | None = None
    try:
        helper = load_installed_helper()
        sanitizer, policy = helper.load_bundle()
        if not controlled_server_to_server_allowed(policy):
            raise WorkerError("Single sanitization policy does not authorize server-to-server raw data.")
        if not os.path.isfile(MARIADB_DUMP) or not os.access(MARIADB_DUMP, os.X_OK):
            raise WorkerError("Fixed MariaDB dump client is unavailable.")

        prod_key = stage / "prod-read.key"
        trust_repo = stage / "trust-repo"
        trust_home = stage / "trust-home"
        remote_snapshot = stage / "scripts/production-readonly-snapshot/remote-stream.sh"
        activation_source = stage / "remote-apply-worker.sh"
        for path in (prod_key, remote_snapshot, activation_source):
            require_root_regular(path, 0o600 if path == prod_key else 0o700)

        trust_home.mkdir(mode=0o700)
        trust_script = trust_repo / "scripts/production-ssh-trust/manage-known-host.sh"
        require_root_regular(trust_script, 0o700)
        trust = subprocess.run(
            ["/bin/bash", str(trust_script), "PROVISION"],
            cwd=trust_repo,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            env={**CLEAN_ENV, "HOME": str(trust_home), "SERVER_HOST": prod_host},
            check=False,
        )
        if trust.returncode != 0:
            raise WorkerError("Pinned PROD trust provisioning failed.")
        known_hosts = trust_home / ".ssh/known_hosts"
        require_root_regular(known_hosts, 0o600)

        scope = helper.derive_scope(request_id)
        password = helper.prepare_import_scope(scope)
        client_file = helper.write_restricted_client_file(scope, password)
        password = ""
        with remote_snapshot.open("rb") as remote_stdin:
            snapshot = subprocess.Popen(
                [
                    "/usr/bin/ssh",
                    "-i",
                    str(prod_key),
                    "-o",
                    "IdentitiesOnly=yes",
                    "-o",
                    "BatchMode=yes",
                    "-o",
                    "StrictHostKeyChecking=yes",
                    "-o",
                    f"UserKnownHostsFile={known_hosts}",
                    "-o",
                    "ConnectTimeout=15",
                    f"{prod_user}@{prod_host}",
                    "bash -s -- " + prod_release,
                ],
                stdin=remote_stdin,
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                env=CLEAN_ENV,
            )
            if snapshot.stdout is None:
                raise WorkerError("PROD snapshot stream is unavailable.")
            import_snapshot_stream(helper, scope, client_file, snapshot.stdout)
            snapshot.stdout.close()
            if snapshot.wait() != 0:
                raise WorkerError("Read-only PROD snapshot route failed.")

        if helper.table_count(scope) <= 0:
            raise WorkerError("Imported staging schema proof failed.")
        execution = sanitizer.sanitize(scope.database, policy)
        evidence = sanitizer.assert_sanitized(scope.database, execution)
        if not isinstance(evidence, dict):
            raise WorkerError("Sanitization evidence contract is invalid.")
        require_semantic_coverage({str(k): str(v) for k, v in evidence.items()})

        with sanitized_tmp.open("wb") as output:
            os.chmod(sanitized_tmp, 0o600)
            dump = subprocess.run(
                [
                    MARIADB_DUMP,
                    "--protocol=socket",
                    "--single-transaction",
                    "--quick",
                    "--skip-lock-tables",
                    "--no-tablespaces",
                    scope.database,
                ],
                stdout=output,
                stderr=subprocess.DEVNULL,
                env=CLEAN_ENV,
                check=False,
            )
        if dump.returncode != 0 or sanitized_tmp.stat().st_size <= 0:
            raise WorkerError("Sanitized SQL export failed.")
    except Exception:
        sanitized_tmp.unlink(missing_ok=True)
        sanitized.unlink(missing_ok=True)
        activation.unlink(missing_ok=True)
        write_preactivation_result(
            job,
            request_id,
            expected_main,
            deploy_meta.st_uid,
            deploy_meta.st_gid,
        )
        shutil.rmtree(stage, ignore_errors=True)
        return 80
    finally:
        if snapshot is not None and snapshot.poll() is None:
            snapshot.kill()
            snapshot.wait()
        if client_file:
            try:
                os.unlink(client_file)
            except FileNotFoundError:
                pass
        if helper is not None and scope is not None:
            try:
                helper.cleanup_scope(scope)
            except Exception:
                sanitized_tmp.unlink(missing_ok=True)
                sanitized.unlink(missing_ok=True)
                activation.unlink(missing_ok=True)
                write_preactivation_result(
                    job,
                    request_id,
                    expected_main,
                    deploy_meta.st_uid,
                    deploy_meta.st_gid,
                )
                shutil.rmtree(stage, ignore_errors=True)
                return 81

    helper.require_absent(scope)
    os.chown(sanitized_tmp, deploy_meta.st_uid, deploy_meta.st_gid)
    os.chmod(sanitized_tmp, 0o600)
    os.replace(sanitized_tmp, sanitized)
    shutil.copyfile(stage / "remote-apply-worker.sh", activation)
    os.chown(activation, deploy_meta.st_uid, deploy_meta.st_gid)
    os.chmod(activation, 0o700)
    sanitized_sha = sha256_file(sanitized)
    if not re.fullmatch(r"[0-9a-f]{64}", sanitized_sha):
        sanitized.unlink(missing_ok=True)
        activation.unlink(missing_ok=True)
        write_preactivation_result(
            job,
            request_id,
            expected_main,
            deploy_meta.st_uid,
            deploy_meta.st_gid,
        )
        shutil.rmtree(stage, ignore_errors=True)
        return 82

    # The PROD identity and all request-scoped trust material are removed before
    # the PREPROD runtime activation worker starts.
    shutil.rmtree(stage)
    try:
        os.execv(
            RUNUSER,
            [
                RUNUSER,
                "-u",
                deploy_user,
                "--",
                str(activation),
                request_id,
                expected_main,
                sanitized_sha,
            ],
        )
    except OSError:
        sanitized.unlink(missing_ok=True)
        activation.unlink(missing_ok=True)
        write_preactivation_result(
            job,
            request_id,
            expected_main,
            deploy_meta.st_uid,
            deploy_meta.st_gid,
        )
        return 83
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv))
    except Exception:
        # Unexpected pre-job failures expose no raw command output.
        raise SystemExit(80)
