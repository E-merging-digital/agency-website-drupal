#!/usr/bin/python3 -I
"""Transient root-only wrapper exposing only the fixed #893 PRECHECK action."""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Callable

HELPER_PATH = Path("/usr/local/sbin/agency-preprod-runtime-db-account")
WORK_DIR = Path(__file__).resolve().parent
SOURCE_HELPER = WORK_DIR / "source" / "agency-preprod-runtime-db-account"
CAPABILITY = WORK_DIR / "source" / "capability.json"
CLEAN_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "LC_ALL": "C"}
FIELDS = (
    "TARGET_DATABASE_PRESENT",
    "ACCOUNT_127_PRESENT",
    "ACCOUNT_LOCALHOST_PRESENT",
    "EXPECTED_DB_GRANT",
    "RUNTIME_ACCOUNT_STATE",
)


class ContractError(RuntimeError):
    """Fail-closed transient PRECHECK route violation."""


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        while True:
            chunk = stream.read(1024 * 1024)
            if not chunk:
                return digest.hexdigest()
            digest.update(chunk)


def path_exists(path: Path) -> bool:
    return os.path.lexists(path)


def require_regular_owned(path: Path, uid: int, gid: int) -> os.stat_result:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise ContractError("Required source is not a regular non-symlink file.")
    if metadata.st_uid != uid or metadata.st_gid != gid:
        raise ContractError("Required source ownership is invalid.")
    return metadata


def load_contract(
    capability_path: Path,
    source_helper: Path,
    source_uid: int = 0,
    source_gid: int = 0,
) -> str:
    require_regular_owned(capability_path, source_uid, source_gid)
    require_regular_owned(source_helper, source_uid, source_gid)
    try:
        manifest = json.loads(capability_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractError("Runtime account capability manifest is invalid.") from exc

    try:
        helper = manifest["installation"]["helper"]
        fixed = manifest["fixed_target"]
        observation = manifest["observation"]
        digest = helper["sha256"]
    except (KeyError, TypeError) as exc:
        raise ContractError("Runtime account capability shape is invalid.") from exc

    if manifest.get("issue_number") != 893:
        raise ContractError("Runtime account capability issue lineage is invalid.")
    if manifest.get("status") != "DESIGNED_NOT_INSTALLED_NOT_EXECUTED":
        raise ContractError("Runtime account capability install state is invalid.")
    if fixed != {
        "database": "agency_preprod",
        "user": "agency_preprod",
        "account_host": "127.0.0.1",
        "protocol": "TCP_LOOPBACK",
        "port": 3306,
    }:
        raise ContractError("Runtime account fixed target is invalid.")
    if helper.get("path") != str(HELPER_PATH):
        raise ContractError("Runtime account helper path is invalid.")
    if helper.get("owner") != "root" or helper.get("group") != "root":
        raise ContractError("Runtime account helper ownership contract is invalid.")
    if helper.get("mode") != "0755":
        raise ContractError("Runtime account helper mode contract is invalid.")
    if not isinstance(digest, str) or len(digest) != 64:
        raise ContractError("Runtime account helper digest is invalid.")
    if observation.get("precheck_and_verify") != "METADATA_ONLY":
        raise ContractError("Runtime account observation contract is invalid.")
    if tuple(observation.get("fields", ())) != FIELDS:
        raise ContractError("Runtime account evidence fields are invalid.")
    if sha256_file(source_helper) != digest:
        raise ContractError("Runtime account helper source digest mismatch.")
    return digest


def verify_installed(
    helper_path: Path,
    expected_digest: str,
    owner_uid: int,
    owner_gid: int,
) -> tuple[int, int]:
    metadata = os.lstat(helper_path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise ContractError("Transient helper type is invalid.")
    if metadata.st_uid != owner_uid or metadata.st_gid != owner_gid:
        raise ContractError("Transient helper ownership is invalid.")
    if stat.S_IMODE(metadata.st_mode) != 0o755:
        raise ContractError("Transient helper mode is invalid.")
    if sha256_file(helper_path) != expected_digest:
        raise ContractError("Transient helper digest is invalid.")
    return metadata.st_dev, metadata.st_ino


def atomic_install(
    source_helper: Path,
    helper_path: Path,
    expected_digest: str,
    owner_uid: int = 0,
    owner_gid: int = 0,
) -> tuple[int, int, int]:
    if path_exists(helper_path):
        raise ContractError("Fixed helper path already exists.")

    parent = helper_path.parent
    parent_meta = os.lstat(parent)
    if stat.S_ISLNK(parent_meta.st_mode) or not stat.S_ISDIR(parent_meta.st_mode):
        raise ContractError("Fixed helper parent is invalid.")
    if parent_meta.st_uid != owner_uid or parent_meta.st_gid != owner_gid:
        raise ContractError("Fixed helper parent ownership is invalid.")
    if stat.S_IMODE(parent_meta.st_mode) & 0o022:
        raise ContractError("Fixed helper parent is writable outside its owner.")

    fd, tmp_name = tempfile.mkstemp(
        prefix=".agency-preprod-runtime-db-account.895.",
        dir=str(parent),
    )
    tmp_path = Path(tmp_name)
    try:
        guard_fd = os.dup(fd)
    except BaseException:
        os.close(fd)
        try:
            os.unlink(tmp_path)
        except FileNotFoundError:
            pass
        raise

    try:
        with os.fdopen(fd, "wb") as output, source_helper.open("rb") as source:
            shutil.copyfileobj(source, output, length=1024 * 1024)
            output.flush()
            os.fsync(output.fileno())

        metadata = os.lstat(tmp_path)
        if metadata.st_uid != owner_uid or metadata.st_gid != owner_gid:
            os.chown(tmp_path, owner_uid, owner_gid)
        os.chmod(tmp_path, 0o755)
        if sha256_file(tmp_path) != expected_digest:
            raise ContractError("Transient helper staged digest mismatch.")

        guard_meta = os.fstat(guard_fd)
        try:
            os.link(tmp_path, helper_path)
        except FileExistsError as exc:
            raise ContractError("Fixed helper path appeared during atomic install.") from exc

        installed_identity = verify_installed(
            helper_path,
            expected_digest,
            owner_uid,
            owner_gid,
        )
        if installed_identity != (guard_meta.st_dev, guard_meta.st_ino):
            raise ContractError("Transient helper guard identity mismatch.")
        return installed_identity[0], installed_identity[1], guard_fd
    except BaseException:
        try:
            owned = os.fstat(guard_fd)
            if path_exists(helper_path):
                current = os.lstat(helper_path)
                if (current.st_dev, current.st_ino) == (owned.st_dev, owned.st_ino):
                    os.unlink(helper_path)
        finally:
            os.close(guard_fd)
        raise
    finally:
        try:
            os.unlink(tmp_path)
        except FileNotFoundError:
            pass


def remove_owned_helper(
    helper_path: Path,
    identity: tuple[int, int, int],
) -> None:
    expected_dev, expected_ino, guard_fd = identity
    try:
        guard = os.fstat(guard_fd)
        if (guard.st_dev, guard.st_ino) != (expected_dev, expected_ino):
            raise ContractError("Transient helper ownership guard changed.")
        if not path_exists(helper_path):
            return
        current = os.lstat(helper_path)
        if (current.st_dev, current.st_ino) != (guard.st_dev, guard.st_ino):
            raise ContractError("Transient helper identity changed; refusing unknown cleanup.")
        os.unlink(helper_path)
        if path_exists(helper_path):
            raise ContractError("Transient helper cleanup failed.")
    finally:
        os.close(guard_fd)


def run_fixed_helper(helper_path: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [str(helper_path), "PRECHECK"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
        env=CLEAN_ENV,
    )


def parse_metadata(raw: str, returncode: int) -> dict[str, str]:
    if len(raw.encode("utf-8")) > 2048:
        raise ContractError("PRECHECK metadata exceeds bounded size.")
    lines = raw.splitlines()
    if len(lines) != len(FIELDS):
        raise ContractError("PRECHECK metadata line count is invalid.")

    metadata: dict[str, str] = {}
    for expected_key, line in zip(FIELDS, lines, strict=True):
        if "=" not in line:
            raise ContractError("PRECHECK metadata line is invalid.")
        key, value = line.split("=", 1)
        if key != expected_key or key in metadata:
            raise ContractError("PRECHECK metadata key order is invalid.")
        metadata[key] = value

    yn_unknown = {"YES", "NO", "UNKNOWN_FAIL_CLOSED"}
    if metadata["TARGET_DATABASE_PRESENT"] not in yn_unknown:
        raise ContractError("Target database metadata is invalid.")
    if metadata["ACCOUNT_127_PRESENT"] not in yn_unknown:
        raise ContractError("Canonical account metadata is invalid.")
    if metadata["ACCOUNT_LOCALHOST_PRESENT"] not in yn_unknown:
        raise ContractError("Legacy account metadata is invalid.")
    if metadata["EXPECTED_DB_GRANT"] not in {
        "EXACT",
        "NOT_EXACT",
        "UNKNOWN_FAIL_CLOSED",
    }:
        raise ContractError("Grant metadata is invalid.")
    if metadata["RUNTIME_ACCOUNT_STATE"] not in {
        "EXACT",
        "RECONCILIATION_REQUIRED",
        "UNSAFE",
    }:
        raise ContractError("Runtime account classification is invalid.")

    if returncode == 0:
        if any(value == "UNKNOWN_FAIL_CLOSED" for value in metadata.values()):
            raise ContractError("Successful PRECHECK cannot contain unknown evidence.")
    elif returncode == 1:
        for key in FIELDS[:-1]:
            if metadata[key] != "UNKNOWN_FAIL_CLOSED":
                raise ContractError("Failed PRECHECK evidence is not fail-closed.")
        if metadata["RUNTIME_ACCOUNT_STATE"] != "UNSAFE":
            raise ContractError("Failed PRECHECK classification is not unsafe.")
    else:
        raise ContractError("PRECHECK helper exit class is invalid.")
    return metadata


def install_signal_guard() -> dict[signal.Signals, object]:
    def interrupted(signum: int, _frame: object) -> None:
        raise ContractError(f"Interrupted by signal {signum}.")

    previous: dict[signal.Signals, object] = {}
    for sig in (signal.SIGHUP, signal.SIGINT, signal.SIGTERM):
        previous[sig] = signal.getsignal(sig)
        signal.signal(sig, interrupted)
    return previous


def restore_signal_guard(previous: dict[signal.Signals, object]) -> None:
    for sig, handler in previous.items():
        signal.signal(sig, handler)


def execute_precheck(
    helper_path: Path = HELPER_PATH,
    capability_path: Path = CAPABILITY,
    source_helper: Path = SOURCE_HELPER,
    owner_uid: int = 0,
    owner_gid: int = 0,
    source_uid: int = 0,
    source_gid: int = 0,
    runner: Callable[[Path], subprocess.CompletedProcess[str]] = run_fixed_helper,
    use_signal_guard: bool = True,
) -> tuple[int, dict[str, str]]:
    expected_digest = load_contract(
        capability_path,
        source_helper,
        source_uid,
        source_gid,
    )
    if path_exists(helper_path):
        raise ContractError("Fixed helper path pre-exists; no overwrite is allowed.")

    previous = install_signal_guard() if use_signal_guard else {}
    identity: tuple[int, int, int] | None = None
    try:
        identity = atomic_install(
            source_helper,
            helper_path,
            expected_digest,
            owner_uid,
            owner_gid,
        )
        result = runner(helper_path)
        metadata = parse_metadata(result.stdout, result.returncode)
    finally:
        try:
            if identity is not None:
                remove_owned_helper(helper_path, identity)
        finally:
            if previous:
                restore_signal_guard(previous)

    if path_exists(helper_path):
        raise ContractError("Transient helper remains after PRECHECK.")
    return result.returncode, metadata


def require_self_identity() -> None:
    metadata = os.lstat(Path(__file__))
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise ContractError("Remote PRECHECK wrapper type is invalid.")
    if metadata.st_uid != 0 or metadata.st_gid != 0:
        raise ContractError("Remote PRECHECK wrapper ownership is invalid.")
    if stat.S_IMODE(metadata.st_mode) != 0o700:
        raise ContractError("Remote PRECHECK wrapper mode is invalid.")


def emit_metadata(metadata: dict[str, str]) -> None:
    for key in FIELDS:
        print(f"{key}={metadata[key]}")


def main() -> int:
    if len(sys.argv) != 1:
        return 64
    if os.geteuid() != 0:
        return 77
    try:
        require_self_identity()
        returncode, metadata = execute_precheck()
    except (ContractError, KeyError, OSError, ValueError):
        return 1

    emit_metadata(metadata)
    return returncode


if __name__ == "__main__":
    raise SystemExit(main())
