#!/usr/bin/env python3
"""Consume bounded #895 PRECHECK terminal evidence after cleanup."""

from __future__ import annotations

import argparse
import os
import stat
import sys
from pathlib import Path

FIELDS = (
    "TARGET_DATABASE_PRESENT",
    "ACCOUNT_127_PRESENT",
    "ACCOUNT_LOCALHOST_PRESENT",
    "EXPECTED_DB_GRANT",
    "RUNTIME_ACCOUNT_STATE",
)
ALLOWED = {
    "TARGET_DATABASE_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "ACCOUNT_127_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "ACCOUNT_LOCALHOST_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "EXPECTED_DB_GRANT": {"EXACT", "NOT_EXACT", "UNKNOWN_FAIL_CLOSED"},
    "RUNTIME_ACCOUNT_STATE": {"EXACT", "RECONCILIATION_REQUIRED", "UNSAFE"},
}


class Reject(RuntimeError):
    """Fail-closed terminal evidence rejection."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Reject(message)


def read_request_file(path: Path) -> str:
    metadata = os.lstat(path)
    require(
        stat.S_ISREG(metadata.st_mode) and not stat.S_ISLNK(metadata.st_mode),
        "terminal evidence path is not a regular non-symlink file",
    )
    require(stat.S_IMODE(metadata.st_mode) == 0o600, "terminal evidence mode is invalid")
    return path.read_text(encoding="utf-8")


def parse_status(raw: str) -> int:
    require(raw in {"0\n", "1\n", "0", "1"}, "terminal status is invalid")
    return int(raw.strip())


def parse_evidence(raw: str, rc: int) -> dict[str, str]:
    require(len(raw.encode("utf-8")) <= 2048, "bounded metadata exceeded")
    lines = raw.splitlines()
    require(len(lines) == len(FIELDS), "metadata line count invalid")

    parsed: dict[str, str] = {}
    for expected, line in zip(FIELDS, lines, strict=True):
        require("=" in line, "metadata line invalid")
        key, value = line.split("=", 1)
        require(key == expected and key not in parsed, "metadata key invalid")
        require(value in ALLOWED[key], "metadata value invalid")
        parsed[key] = value

    if rc == 0:
        require(
            all(value != "UNKNOWN_FAIL_CLOSED" for value in parsed.values()),
            "success contains unknown evidence",
        )
    else:
        require(
            all(parsed[key] == "UNKNOWN_FAIL_CLOSED" for key in FIELDS[:-1]),
            "failure is not fail closed",
        )
        require(
            parsed["RUNTIME_ACCOUNT_STATE"] == "UNSAFE",
            "failure classification invalid",
        )
    return parsed


def cleanup_path(path: Path) -> None:
    try:
        metadata = os.lstat(path)
    except FileNotFoundError:
        return

    if stat.S_ISREG(metadata.st_mode) or stat.S_ISLNK(metadata.st_mode):
        os.unlink(path)
        return
    raise Reject("terminal evidence cleanup encountered an unexpected object")


def finalize(
    evidence_path: Path,
    status_path: Path,
    setup_outcome: str,
    execute_outcome: str,
    cleanup_outcome: str,
) -> tuple[int, dict[str, str]]:
    parsed: dict[str, str] | None = None
    rc: int | None = None
    rejection: Exception | None = None
    cleanup_rejection: Exception | None = None

    try:
        require(setup_outcome == "success", "setup did not succeed")
        require(cleanup_outcome == "success", "cleanup did not succeed")
        status_raw = read_request_file(status_path)
        evidence_raw = read_request_file(evidence_path)
        rc = parse_status(status_raw)

        expected_execute = "success" if rc == 0 else "failure"
        require(
            execute_outcome == expected_execute,
            "execute outcome/status mismatch",
        )
        parsed = parse_evidence(evidence_raw, rc)
    except (OSError, UnicodeError, Reject) as exc:
        rejection = exc
    finally:
        for path in (evidence_path, status_path):
            try:
                cleanup_path(path)
            except (OSError, Reject) as exc:
                cleanup_rejection = cleanup_rejection or exc

    if cleanup_rejection is not None:
        raise Reject("terminal evidence cleanup failed") from cleanup_rejection
    if rejection is not None:
        raise Reject("terminal evidence rejected") from rejection
    assert parsed is not None and rc is not None
    return rc, parsed


def emit(parsed: dict[str, str]) -> None:
    for key in FIELDS:
        print(f"{key}={parsed[key]}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--evidence", required=True)
    parser.add_argument("--status", required=True)
    parser.add_argument("--setup-outcome", required=True)
    parser.add_argument("--execute-outcome", required=True)
    parser.add_argument("--cleanup-outcome", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        rc, parsed = finalize(
            Path(args.evidence),
            Path(args.status),
            args.setup_outcome,
            args.execute_outcome,
            args.cleanup_outcome,
        )
    except Reject:
        print("PRECHECK_TERMINAL_EVIDENCE=REJECTED", file=sys.stderr)
        return 1

    emit(parsed)
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
