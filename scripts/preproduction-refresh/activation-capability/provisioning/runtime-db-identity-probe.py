#!/usr/bin/env python3
from __future__ import annotations

import os
import re
import stat
import subprocess
import sys
from pathlib import Path

CURRENT = Path("/var/www/agency-preprod/current")
DRUSH = CURRENT / "vendor/bin/drush"
SAFE_DB_NAME = re.compile(r"^[A-Za-z0-9_]{1,64}$")

OBSERVED = "OBSERVED"
RELEASE_UNAVAILABLE = "RELEASE_UNAVAILABLE"
RELEASE_ACCESS_FAILED = "RELEASE_ACCESS_FAILED"
DRUSH_MISSING = "DRUSH_MISSING"
DRUSH_ACCESS_FAILED = "DRUSH_ACCESS_FAILED"
DRUSH_NOT_EXECUTABLE = "DRUSH_NOT_EXECUTABLE"
DRUSH_EXEC_FAILED = "DRUSH_EXEC_FAILED"
DRUSH_FAILED = "DRUSH_FAILED"
OUTPUT_EMPTY = "OUTPUT_EMPTY"
OUTPUT_INVALID = "OUTPUT_INVALID"

ZERO = "ZERO"
NONZERO = "NONZERO"
NOT_RUN = "NOT_RUN"


def precondition_state(
    *, current_is_symlink: bool, drush_exists: bool, drush_executable: bool
) -> tuple[str, str, str] | None:
    if not current_is_symlink:
        return RELEASE_UNAVAILABLE, NOT_RUN, "NONE"
    if not drush_exists:
        return DRUSH_MISSING, NOT_RUN, "NONE"
    if not drush_executable:
        return DRUSH_NOT_EXECUTABLE, NOT_RUN, "NONE"
    return None


def inspect_runtime_preconditions() -> tuple[str, str, str] | None:
    try:
        current_stat = CURRENT.lstat()
    except FileNotFoundError:
        return RELEASE_UNAVAILABLE, NOT_RUN, "NONE"
    except OSError:
        return RELEASE_ACCESS_FAILED, NOT_RUN, "NONE"
    if not stat.S_ISLNK(current_stat.st_mode):
        return RELEASE_UNAVAILABLE, NOT_RUN, "NONE"

    try:
        DRUSH.stat()
    except FileNotFoundError:
        return DRUSH_MISSING, NOT_RUN, "NONE"
    except OSError:
        return DRUSH_ACCESS_FAILED, NOT_RUN, "NONE"
    if not os.access(DRUSH, os.X_OK):
        return DRUSH_NOT_EXECUTABLE, NOT_RUN, "NONE"
    return None


def classify_command_result(returncode: int, stdout: bytes) -> tuple[str, str, str]:
    if returncode != 0:
        return DRUSH_FAILED, NONZERO, "NONE"

    # Accept only the one normal terminal line ending produced by a scalar
    # formatter. Never flatten embedded or repeated newlines into an identity.
    if stdout.endswith(b"\r\n"):
        candidate_bytes = stdout[:-2]
    elif stdout.endswith(b"\n"):
        candidate_bytes = stdout[:-1]
    else:
        candidate_bytes = stdout

    if not candidate_bytes:
        return OUTPUT_EMPTY, ZERO, "NONE"
    if b"\n" in candidate_bytes or b"\r" in candidate_bytes or b"\x00" in candidate_bytes:
        return OUTPUT_INVALID, ZERO, "NONE"

    try:
        candidate = candidate_bytes.decode("ascii")
    except UnicodeDecodeError:
        return OUTPUT_INVALID, ZERO, "NONE"

    if not SAFE_DB_NAME.fullmatch(candidate):
        return OUTPUT_INVALID, ZERO, "NONE"
    return OBSERVED, ZERO, candidate


def probe_runtime() -> tuple[str, str, str]:
    precondition = inspect_runtime_preconditions()
    if precondition is not None:
        return precondition

    try:
        completed = subprocess.run(
            [str(DRUSH), "--quiet", "status", "--field=db-name"],
            cwd=str(CURRENT),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            check=False,
        )
    except OSError:
        return DRUSH_EXEC_FAILED, NOT_RUN, "NONE"

    return classify_command_result(completed.returncode, completed.stdout)


def emit(state: str, exit_class: str, db_name: str) -> None:
    print("runtime_db_probe_schema=1")
    print(f"runtime_db_probe_state={state}")
    print(f"runtime_db_probe_exit_class={exit_class}")
    print(f"runtime_db_name={db_name}")


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print("RUNTIME_DB_PROBE_FAIL_CLOSED=arguments forbidden", file=sys.stderr)
        return 64
    if os.geteuid() == 0:
        print("RUNTIME_DB_PROBE_FAIL_CLOSED=root execution forbidden", file=sys.stderr)
        return 65

    state, exit_class, db_name = probe_runtime()
    emit(state, exit_class, db_name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
